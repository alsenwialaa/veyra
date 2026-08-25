<?php
declare(strict_types=1);

namespace Veyra\Cart\Tool;

use Veyra\Audit\Application\AuditWriter;
use Veyra\Cart\Domain\MutationPlanOutcome;
use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Confirmation\Application\SensitiveActionGate;
use Veyra\Confirmation\Domain\IdempotencyDecision;
use Veyra\Confirmation\Domain\IdempotencyDecisionStatus;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\StateHash;

final class CartToolHandler implements ToolHandler
{
    private readonly ToolInputValidator $planInputs;

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly FoundationActorMapper $actors,
        ?ToolInputValidator $planInputs = null,
        private readonly ?SensitiveActionGate $sensitiveActions = null,
        private readonly ?LockManager $locks = null,
        private readonly ?AuditWriter $audit = null
    ) {
        $this->planInputs = $planInputs ?? new ToolInputValidator();
    }

    public function definitions(): array
    {
        // A WooCommerce cart is request/session global. Veyra does not yet have
        // a persisted, rotated, expiry-aware guest-session <-> Woo-session
        // binding, so exposing this handler to a guest would risk attributing
        // the browser cart to the wrong Veyra principal. Keep guest commerce
        // unavailable until that lifecycle exists and is compatibility-tested.
        $actors = ['customer'];
        $features = ['commerce_cart'];
        $idempotency = ['idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 191]];
        $plan = $this->mutationPlanSchema();
        $definitions = [
            $this->definition('cart.get', 'Read the current authoritative WooCommerce cart and totals.', 'read', [], [], $actors, $features, true),
            $this->definition('cart.resolve_line', 'Resolve exactly one current cart line; ambiguous matches remain unresolved.', 'read', [
                'line_id' => ['type' => 'string', 'maxLength' => 64],
                'product_id' => ['type' => 'integer', 'minimum' => 0],
                'variation_id' => ['type' => 'integer', 'minimum' => 0],
            ], [], $actors, $features, true),
            $this->definition('cart.preview_mutation_plan', 'Validate a compound cart plan without writing and declare explicit partial semantics.', 'read', [
                'operations' => $plan,
            ], ['operations'], $actors, $features, true),
            $this->definition('cart.execute_mutation_plan', 'Execute a validated idempotent cart plan with explicit per-operation partial results and one final recalculation.', 'write', array_merge([
                'operations' => $plan,
            ], $idempotency), ['operations', 'idempotency_key'], $actors, $features, true),
            $this->definition('cart.add_item', 'Add one exact current product and variation to WooCommerce cart idempotently.', 'write', array_merge($this->itemProperties(), $idempotency), ['product_id', 'quantity', 'idempotency_key'], $actors, $features, true),
            $this->definition('cart.update_quantity', 'Update one exact current cart line quantity idempotently.', 'write', array_merge([
                'line_id' => ['type' => 'string', 'maxLength' => 64],
                'quantity' => ['type' => 'number', 'minimum' => 0],
            ], $idempotency), ['line_id', 'quantity', 'idempotency_key'], $actors, $features, true),
            $this->definition('cart.remove_item', 'Remove one exact current cart line idempotently.', 'write', array_merge([
                'line_id' => ['type' => 'string', 'maxLength' => 64],
            ], $idempotency), ['line_id', 'idempotency_key'], $actors, $features, true),
            $this->definition('cart.replace_item', 'Replace one exact line with one exact product using validation and compensation.', 'write', array_merge([
                'line_id' => ['type' => 'string', 'maxLength' => 64],
            ], $this->itemProperties(), $idempotency), ['line_id', 'product_id', 'quantity', 'idempotency_key'], $actors, $features, true),
            $this->definition('cart.apply_coupon', 'Apply one exact coupon through WooCommerce idempotently.', 'write', array_merge([
                'coupon_code' => ['type' => 'string', 'maxLength' => 100],
            ], $idempotency), ['coupon_code', 'idempotency_key'], $actors, $features, true),
            $this->definition('cart.remove_coupon', 'Remove one exact applied coupon through WooCommerce idempotently.', 'write', array_merge([
                'coupon_code' => ['type' => 'string', 'maxLength' => 100],
            ], $idempotency), ['coupon_code', 'idempotency_key'], $actors, $features, true),
            $this->definition('cart.clear_preview', 'Create a non-mutating exact cart-clear preview and state hash. Clearing still requires a separate confirmation.', 'read', [], [], $actors, $features, true),
            $this->definition('cart.recalculate', 'Recalculate the current WooCommerce cart and return authoritative totals.', 'read', [], [], $actors, $features, true),
        ];
        if ($this->sensitiveActions !== null && $this->locks !== null && $this->audit !== null) {
            $definitions[] = $this->definition(
                'cart.clear_confirmed',
                'Consume one exact fresh cart-clear confirmation and clear the authoritative WooCommerce cart exactly once.',
                'sensitive_write',
                [
                    'confirmation_token' => ['type' => 'string', 'minLength' => 32, 'maxLength' => 192],
                    'preview_state_hash' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                    'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 191],
                ],
                ['confirmation_token', 'preview_state_hash', 'idempotency_key'],
                $actors,
                $features,
                false
            );
        }

        return $definitions;
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        if (in_array($call->name, ['cart.preview_mutation_plan', 'cart.execute_mutation_plan'], true)) {
            $operations = $this->validatePlan($call->arguments['operations'] ?? null);
            if ($operations === null) {
                return ToolResult::failed($call, 'cart_plan_invalid_or_ambiguous', $context->correlationId, false);
            }

            // Normalize once at the handler boundary. Crucially, this happens
            // before the Woo cart check and before an idempotency claim, so an
            // invalid later operation cannot follow an earlier side effect.
            $arguments = $call->arguments;
            $arguments['operations'] = $operations;
            $call = new ToolCall($call->callId, $call->name, $call->version, $arguments);
        }

        $actorBinding = $this->authenticatedWooActorBinding($context);
        if ($actorBinding !== 'ok') {
            return $actorBinding === 'cart_unavailable'
                ? ToolResult::failed($call, $actorBinding, $context->correlationId, false)
                : ToolResult::denied($call, $actorBinding, $context->correlationId);
        }
        if (!function_exists('WC') || !WC() || !WC()->cart) {
            return ToolResult::failed($call, 'cart_unavailable', $context->correlationId, false);
        }
        return match ($call->name) {
            'cart.get', 'cart.recalculate' => $this->readCart($call, $context, $call->name === 'cart.recalculate'),
            'cart.resolve_line' => $this->resolveLineTool($call, $context),
            'cart.preview_mutation_plan' => $this->previewPlan($call, $context),
            'cart.clear_preview' => $this->clearPreview($call, $context),
            'cart.clear_confirmed' => $this->clearConfirmed($call, $context),
            'cart.add_item', 'cart.update_quantity', 'cart.remove_item', 'cart.replace_item',
            'cart.apply_coupon', 'cart.remove_coupon', 'cart.execute_mutation_plan' => $this->idempotentWrite($call, $context),
            default => ToolResult::failed($call, 'tool_operation_unknown', $context->correlationId, false),
        };
    }

    /** @param array<string, array<string, mixed>> $properties @param array<int, string> $required @param array<int, string> $actors @param array<int, string> $features */
    private function definition(string $name, string $description, string $classification, array $properties, array $required, array $actors, array $features, bool $visible): ToolDefinition
    {
        return new ToolDefinition($name, '1.0.0', $description, $classification, [
            'type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties,
        ], $actors, [], $features, $visible);
    }

    /**
     * Prove that the process-global Woo cart and session belong to the exact
     * authenticated Veyra customer before any read, idempotency claim, lock or
     * write. Context assembly has an equivalent check, but handlers must defend
     * their own authority boundary when called from any adapter.
     */
    private function authenticatedWooActorBinding(ToolContext $context): string
    {
        if ($context->actorType !== 'customer' || $context->userId === null || $context->userId < 1) {
            return 'cart_authenticated_customer_required';
        }
        if (!function_exists('get_current_user_id')
            || (int) get_current_user_id() !== $context->userId
            || (function_exists('is_user_logged_in') && !is_user_logged_in())
        ) {
            return 'cart_actor_binding_unavailable';
        }
        if (!function_exists('WC')) {
            return 'cart_unavailable';
        }
        $woo = WC();
        if (!is_object($woo)
            || !is_object($woo->cart ?? null)
            || !is_object($woo->session ?? null)
            || !is_object($woo->customer ?? null)
        ) {
            return 'cart_unavailable';
        }
        if (!method_exists($woo->customer, 'get_id') || (int) $woo->customer->get_id() !== $context->userId) {
            return 'cart_actor_binding_mismatch';
        }
        if (!method_exists($woo->session, 'get_customer_id')) {
            return 'cart_actor_binding_unavailable';
        }
        $sessionCustomerId = (string) $woo->session->get_customer_id();
        if ($sessionCustomerId === '' || !hash_equals((string) $context->userId, $sessionCustomerId)) {
            return 'cart_actor_binding_mismatch';
        }

        return 'ok';
    }

    /** @return array<string, array<string, mixed>> */
    private function itemProperties(): array
    {
        return [
            'product_id' => ['type' => 'integer', 'minimum' => 1],
            'variation_id' => ['type' => 'integer', 'minimum' => 0],
            'quantity' => ['type' => 'number', 'minimum' => 0.0001],
            'variation_attributes' => ['type' => 'object'],
        ];
    }

    /** @return array<string, mixed> */
    private function mutationPlanSchema(): array
    {
        // Keep the provider-facing contract in the supported object/enum
        // subset, while bounding every possible nested field. Conditional
        // required fields are enforced by validatePlan() against the exact
        // per-operation schemas before any handler side effect.
        return [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 12,
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['operation', 'arguments'],
                'properties' => [
                    'operation' => [
                        'type' => 'string',
                        'enum' => array_keys($this->mutationOperationSchemas()),
                    ],
                    'arguments' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'line_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                            'product_id' => ['type' => 'integer', 'minimum' => 1],
                            'variation_id' => ['type' => 'integer', 'minimum' => 0],
                            'quantity' => ['type' => 'number', 'minimum' => 0],
                            'variation_attributes' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'string', 'maxLength' => 200],
                            ],
                            'coupon_code' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function mutationOperationSchemas(): array
    {
        $line = ['type' => 'string', 'minLength' => 1, 'maxLength' => 64];
        $item = [
            'product_id' => ['type' => 'integer', 'minimum' => 1],
            'variation_id' => ['type' => 'integer', 'minimum' => 0],
            'quantity' => ['type' => 'number', 'minimum' => 0.0001],
            'variation_attributes' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string', 'maxLength' => 200],
            ],
        ];
        $coupon = ['type' => 'string', 'minLength' => 1, 'maxLength' => 100];

        return [
            'add_item' => $this->strictObject($item, ['product_id', 'quantity']),
            'update_quantity' => $this->strictObject([
                'line_id' => $line,
                'quantity' => ['type' => 'number', 'minimum' => 0],
            ], ['line_id', 'quantity']),
            'remove_item' => $this->strictObject(['line_id' => $line], ['line_id']),
            'replace_item' => $this->strictObject(['line_id' => $line] + $item, ['line_id', 'product_id', 'quantity']),
            'apply_coupon' => $this->strictObject(['coupon_code' => $coupon], ['coupon_code']),
            'remove_coupon' => $this->strictObject(['coupon_code' => $coupon], ['coupon_code']),
        ];
    }

    /** @param array<string, array<string, mixed>> $properties @param array<int, string> $required @return array<string, mixed> */
    private function strictObject(array $properties, array $required): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ];
    }

    private function readCart(ToolCall $call, ToolContext $context, bool $recalculate): ToolResult
    {
        if ($recalculate) {
            WC()->cart->calculate_totals();
        }
        return ToolResult::success($call, ['cart' => $this->snapshot()], $context->correlationId);
    }

    private function resolveLineTool(ToolCall $call, ToolContext $context): ToolResult
    {
        $resolution = $this->resolveLine(
            isset($call->arguments['line_id']) ? (string) $call->arguments['line_id'] : null,
            (int) ($call->arguments['product_id'] ?? 0),
            (int) ($call->arguments['variation_id'] ?? 0)
        );
        return ToolResult::success($call, $resolution, $context->correlationId);
    }

    private function previewPlan(ToolCall $call, ToolContext $context): ToolResult
    {
        $operations = $this->validatePlan($call->arguments['operations']);
        if ($operations === null) {
            return ToolResult::failed($call, 'cart_plan_invalid_or_ambiguous', $context->correlationId, false);
        }
        $preview = ['operations' => $operations, 'semantics' => 'explicit_partial', 'cart_before' => $this->snapshot()];
        return ToolResult::success($call, ['preview' => $preview, 'state_hash' => StateHash::fromPayload($preview)->value()], $context->correlationId);
    }

    private function clearPreview(ToolCall $call, ToolContext $context): ToolResult
    {
        $cart = $this->snapshot();
        $material = $this->clearMaterial($cart);
        $stateHash = StateHash::fromPayload($material)->value();
        $hasContents = $material['line_count'] > 0 || $material['coupons'] !== [];

        return ToolResult::success($call, [
            'preview' => $material,
            'state_hash' => $stateHash,
            'confirmation_action' => 'cart.clear_confirmed',
            'confirmation_scope' => $this->cartScope($context),
            'confirmation_required' => $hasContents,
            'clear_required' => $hasContents,
            'summary_complete' => true,
            'order_or_payment_effect' => 'none',
        ], $context->correlationId);
    }

    private function clearConfirmed(ToolCall $call, ToolContext $context): ToolResult
    {
        if ($this->sensitiveActions === null || $this->locks === null || $this->audit === null) {
            return ToolResult::denied($call, 'cart_clear_confirmation_runtime_unavailable', $context->correlationId);
        }
        try {
            $expectedState = new StateHash((string) ($call->arguments['preview_state_hash'] ?? ''));
            $correlation = new CorrelationId($context->correlationId);
            $actor = $this->actors->map($context);
        } catch (\Throwable) {
            return ToolResult::failed($call, 'cart_clear_state_hash_invalid', $context->correlationId, false);
        }
        $scope = $this->cartScope($context);
        $idempotencyPayload = ['preview_state_hash' => $expectedState->value()];
        try {
            $prior = $this->idempotency->inspect(
                $actor,
                'cart.clear_confirmed',
                (string) ($call->arguments['idempotency_key'] ?? ''),
                $idempotencyPayload,
                $scope
            );
        } catch (\Throwable) {
            return ToolResult::failed($call, 'idempotency_unavailable', $context->correlationId, false);
        }
        if ($prior !== null) {
            return $this->clearGateResult(
                $call,
                $context,
                $prior->status->value,
                $prior->code,
                $prior->record
            );
        }
        try {
            $lock = $this->locks->acquire($this->woocommerceAuthorityLockKey($context), $correlation, 30);
        } catch (\Throwable) {
            $lock = null;
        }
        if ($lock === null) {
            return new ToolResult($call->callId, $call->name, 'blocked', 'cart_clear_lock_unavailable', [], [], true, true, $context->correlationId);
        }

        try {
            // Re-inspect under the cart-authority lock. This closes the race
            // where another request completes the exact operation after the
            // fast-path inspection but before this request observes the now
            // empty cart. A genuinely fresh key still has to validate the
            // authoritative state below before confirmation can be consumed.
            try {
                $prior = $this->idempotency->inspect(
                    $actor,
                    'cart.clear_confirmed',
                    (string) ($call->arguments['idempotency_key'] ?? ''),
                    $idempotencyPayload,
                    $scope
                );
            } catch (\Throwable) {
                return ToolResult::failed($call, 'idempotency_unavailable', $context->correlationId, false);
            }
            if ($prior !== null) {
                return $this->clearGateResult(
                    $call,
                    $context,
                    $prior->status->value,
                    $prior->code,
                    $prior->record
                );
            }

            $lockedBefore = $this->snapshot();
            $material = $this->clearMaterial($lockedBefore);
            $lockedState = StateHash::fromPayload($material);
            if (!$lockedState->equals($expectedState)) {
                return new ToolResult(
                    $call->callId,
                    $call->name,
                    'stale',
                    'cart_clear_preview_stale',
                    ['cart' => $lockedBefore, 'current_state_hash' => $lockedState->value()],
                    [],
                    true,
                    true,
                    $context->correlationId
                );
            }
            if (($material['line_count'] ?? 0) === 0 && ($material['coupons'] ?? []) === []) {
                return new ToolResult(
                    $call->callId,
                    $call->name,
                    'blocked',
                    'cart_already_empty',
                    ['cart' => $lockedBefore],
                    [],
                    true,
                    true,
                    $context->correlationId
                );
            }

            $gate = $this->sensitiveActions->begin(
                $actor,
                (string) ($call->arguments['confirmation_token'] ?? ''),
                $lockedState,
                'cart.clear_confirmed',
                (string) ($call->arguments['idempotency_key'] ?? ''),
                $idempotencyPayload,
                $scope,
                $correlation
            );
            if ($gate->status !== 'ready' || $gate->lease === null) {
                return $this->clearGateResult($call, $context, $gate->status, $gate->code, $gate->idempotency);
            }

            try {
                WC()->cart->empty_cart(true);
                WC()->cart->calculate_totals();
                if (WC()->session) {
                    WC()->session->set('cart', WC()->cart->get_cart_for_session());
                }
                $after = $this->snapshot();
                if (($after['item_count'] ?? -1) !== 0
                    || ($after['lines'] ?? null) !== []
                    || ($after['coupons'] ?? null) !== []
                ) {
                    $known = ['cart_before' => $lockedBefore, 'cart' => $after, 'reconciliation_required' => true];
                    $this->idempotency->markUncertain($gate->lease->idempotency, 'cart_clear_postcondition_unverified', $known);

                    return new ToolResult($call->callId, $call->name, 'uncertain', 'cart_clear_postcondition_unverified', $known, [], true, false, $context->correlationId);
                }
                $auditReference = $this->audit->writeRequired(
                    $actor,
                    'cart.clear_confirmed',
                    'cart',
                    hash('sha256', $scope),
                    'cart_clear_verified',
                    $correlation,
                    [
                        'confirmation_id' => $gate->lease->confirmation->id->value(),
                        'before_state_hash' => $lockedState->value(),
                        'after_cart_hash' => (string) ($after['hash'] ?? ''),
                        'line_count' => (int) ($material['line_count'] ?? 0),
                    ]
                );
                $data = [
                    'operation' => [
                        'result_status' => 'succeeded',
                        'code' => 'cart_cleared',
                        'cleared_line_count' => (int) ($material['line_count'] ?? 0),
                        'cleared_coupon_count' => count($material['coupons'] ?? []),
                    ],
                    'cart' => $after,
                    'final_recalculation_performed' => true,
                    'dependent_state_invalidated' => $this->checkoutInvalidation($context, 'cart_cleared'),
                    'audit_reference' => $auditReference,
                ];
                if (!$this->idempotency->complete($gate->lease->idempotency, 'cart_cleared', $data, false)) {
                    return new ToolResult($call->callId, $call->name, 'uncertain', 'cart_clear_idempotency_completion_uncertain', $data, [], true, false, $context->correlationId);
                }

                return new ToolResult(
                    $call->callId,
                    $call->name,
                    'succeeded',
                    'cart_cleared',
                    $data,
                    ['cart:' . (string) ($after['hash'] ?? 'empty'), 'checkout:' . $context->conversationId . ':stale'],
                    true,
                    false,
                    $context->correlationId
                );
            } catch (\Throwable) {
                $known = ['cart' => $this->safeSnapshot(), 'reconciliation_required' => true];
                try {
                    $this->idempotency->markUncertain($gate->lease->idempotency, 'cart_clear_outcome_uncertain', $known);
                } catch (\Throwable) {
                    $known['idempotency_reconciliation_required'] = true;
                }

                return new ToolResult($call->callId, $call->name, 'uncertain', 'cart_clear_outcome_uncertain', $known, [], true, false, $context->correlationId);
            }
        } finally {
            try {
                $this->locks->release($lock);
            } catch (\Throwable) {
                // The bounded lease expires. Release failure cannot alter the
                // already verified cart result.
            }
        }
    }

    private function idempotentWrite(ToolCall $call, ToolContext $context): ToolResult
    {
        $key = (string) $call->arguments['idempotency_key'];
        $payload = $call->arguments;
        unset($payload['idempotency_key']);
        $scope = 'cart:conversation:' . $context->conversationId;
        try {
            $actor = $this->actors->map($context);
            $correlation = new CorrelationId($context->correlationId);
            $prior = $this->idempotency->inspect(
                $actor,
                $call->name,
                $key,
                $payload,
                $scope
            );
        } catch (\Throwable) {
            return ToolResult::failed($call, 'idempotency_unavailable', $context->correlationId, false);
        }
        if ($prior !== null) {
            $result = $this->writeDecisionResult($call, $context, $prior);
            if ($result !== null) {
                return $result;
            }
        }
        if ($this->locks === null) {
            return new ToolResult(
                $call->callId,
                $call->name,
                'blocked',
                'cart_write_lock_runtime_unavailable',
                [],
                [],
                true,
                false,
                $context->correlationId
            );
        }
        try {
            $lock = $this->locks->acquire($this->woocommerceAuthorityLockKey($context), $correlation, 60);
        } catch (\Throwable) {
            $lock = null;
        }
        if ($lock === null) {
            // No idempotency claim has been created, so the exact request can
            // safely retry after the actor-wide Woo cart lease is released.
            return new ToolResult(
                $call->callId,
                $call->name,
                'blocked',
                'cart_write_lock_unavailable',
                [],
                [],
                true,
                true,
                $context->correlationId
            );
        }

        try {
            // Another request may have completed this exact key while this one
            // waited for the actor-wide cart lease. Re-inspect before claiming.
            try {
                $prior = $this->idempotency->inspect($actor, $call->name, $key, $payload, $scope);
                if ($prior !== null) {
                    $result = $this->writeDecisionResult($call, $context, $prior);
                    if ($result !== null) {
                        return $result;
                    }
                }
                $decision = $this->idempotency->begin(
                    $actor,
                    $call->name,
                    $key,
                    $payload,
                    // Keep retries in one stable actor/conversation scope. A
                    // cart hash changes after success and cannot identify replay.
                    $scope,
                    $correlation
                );
            } catch (\Throwable) {
                return ToolResult::failed($call, 'idempotency_unavailable', $context->correlationId, false);
            }
            $decisionResult = $this->writeDecisionResult($call, $context, $decision);
            if ($decisionResult !== null) {
                return $decisionResult;
            }

            $operation = $this->performWrite($call);
            if (($operation['ok'] ?? false) !== true) {
                $result = [
                    'code' => (string) ($operation['code'] ?? 'cart_mutation_failed'),
                    'operation' => $operation,
                    'cart' => $this->snapshot(),
                ];
                if (!$this->idempotency->fail($decision->record, $result['code'], $result, true)) {
                    return new ToolResult(
                        $call->callId,
                        $call->name,
                        'uncertain',
                        'cart_idempotency_transition_uncertain',
                        $result,
                        [],
                        true,
                        false,
                        $context->correlationId
                    );
                }
                return new ToolResult($call->callId, $call->name, 'failed', $result['code'], $result, [], true, true, $context->correlationId);
            }
            WC()->cart->calculate_totals();
            if (WC()->session) {
                WC()->session->set('cart', WC()->cart->get_cart_for_session());
            }
            $data = [
                'operation' => $operation,
                'cart' => $this->snapshot(),
                'final_recalculation_performed' => true,
            ];
            if (!$this->mutationPostconditionsSatisfied($call->name, $operation, $data['cart'])) {
                $data['reconciliation_required'] = true;
                $this->idempotency->markUncertain($decision->record, 'cart_postcondition_unverified', $data);
                return new ToolResult(
                    $call->callId,
                    $call->name,
                    'uncertain',
                    'cart_postcondition_unverified',
                    $data,
                    [],
                    true,
                    false,
                    $context->correlationId
                );
            }
            $domainStatus = (string) ($operation['result_status'] ?? 'succeeded');
            $resultCode = (string) ($operation['code'] ?? 'cart_mutation_succeeded');
            $changed = (($operation['changed'] ?? true) === true)
                && ($domainStatus === 'succeeded'
                    || ($domainStatus === 'partial' && (int) ($operation['success_count'] ?? 0) > 0));
            if ($changed) {
                $data['dependent_state_invalidated'] = $this->checkoutInvalidation($context, 'cart_changed');
            }
            // Persist the exact final response projection so an idempotent
            // replay carries the same recalculation and invalidation facts.
            if (!$this->idempotency->complete($decision->record, $resultCode, $data, false)) {
                return new ToolResult($call->callId, $call->name, 'uncertain', 'idempotency_completion_uncertain', $data, [], true, false, $context->correlationId);
            }
            return new ToolResult(
                $call->callId,
                $call->name,
                in_array($domainStatus, ['succeeded', 'partial'], true) ? $domainStatus : 'failed',
                $resultCode,
                $data,
                $changed ? ['cart:' . $data['cart']['hash'], 'checkout:' . $context->conversationId . ':stale'] : [],
                true,
                false,
                $context->correlationId
            );
        } catch (\Throwable) {
            $known = ['cart' => $this->safeSnapshot(), 'reconciliation_required' => true];
            try {
                $this->idempotency->markUncertain($decision->record, 'cart_mutation_uncertain', $known);
            } catch (\Throwable) {
                $known['idempotency_reconciliation_required'] = true;
            }
            return new ToolResult($call->callId, $call->name, 'uncertain', 'cart_mutation_uncertain', $known, [], true, false, $context->correlationId);
        } finally {
            try {
                $this->locks->release($lock);
            } catch (\Throwable) {
                // The bounded lease expires. Release failure cannot change an
                // already verified or explicitly uncertain cart outcome.
            }
        }
    }

    private function writeDecisionResult(
        ToolCall $call,
        ToolContext $context,
        IdempotencyDecision $decision
    ): ?ToolResult {
        if ($decision->status === IdempotencyDecisionStatus::Claimed) {
            return null;
        }
        if ($decision->status === IdempotencyDecisionStatus::Replay) {
            $data = $decision->record->result ?? [];
            if ($decision->record->status !== 'succeeded') {
                return new ToolResult(
                    $call->callId,
                    $call->name,
                    'failed',
                    $decision->record->resultCode ?? 'cart_previous_attempt_failed',
                    array_merge($data, ['idempotent_replay' => true]),
                    [],
                    true,
                    $decision->record->retrySafe,
                    $context->correlationId
                );
            }
            $domainStatus = (string) ($data['operation']['result_status'] ?? 'succeeded');
            $changed = (($data['operation']['changed'] ?? true) === true)
                && ($domainStatus === 'succeeded'
                    || ($domainStatus === 'partial' && (int) ($data['operation']['success_count'] ?? 0) > 0));
            $changedResources = $changed
                ? ['cart:' . (string) ($data['cart']['hash'] ?? 'unknown'), 'checkout:' . $context->conversationId . ':stale']
                : [];

            return new ToolResult(
                $call->callId,
                $call->name,
                in_array($domainStatus, ['succeeded', 'partial'], true) ? $domainStatus : 'failed',
                $decision->record->resultCode ?? 'cart_mutation_succeeded',
                array_merge($data, ['idempotent_replay' => true]),
                $changedResources,
                true,
                false,
                $context->correlationId
            );
        }
        if ($decision->status === IdempotencyDecisionStatus::Conflict) {
            return ToolResult::failed($call, 'idempotency_payload_conflict', $context->correlationId, false);
        }
        if ($decision->status === IdempotencyDecisionStatus::InProgress) {
            return new ToolResult($call->callId, $call->name, 'uncertain', 'idempotency_in_progress', [], [], true, false, $context->correlationId);
        }

        return new ToolResult(
            $call->callId,
            $call->name,
            'uncertain',
            'cart_reconciliation_required',
            ['cart' => $this->safeSnapshot()],
            [],
            true,
            false,
            $context->correlationId
        );
    }

    /** @return array<string, mixed> */
    private function performWrite(ToolCall $call): array
    {
        return match ($call->name) {
            'cart.add_item' => $this->add($call->arguments),
            'cart.update_quantity' => $this->update((string) $call->arguments['line_id'], (float) $call->arguments['quantity']),
            'cart.remove_item' => $this->remove((string) $call->arguments['line_id']),
            'cart.replace_item' => $this->replace($call->arguments),
            'cart.apply_coupon' => $this->applyCoupon((string) $call->arguments['coupon_code']),
            'cart.remove_coupon' => $this->removeCoupon((string) $call->arguments['coupon_code']),
            'cart.execute_mutation_plan' => $this->executePlan($call->arguments['operations']),
            default => ['ok' => false, 'code' => 'cart_operation_unknown'],
        };
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function add(array $args): array
    {
        $resolved = $this->resolvePurchasableItem((int) $args['product_id'], (int) ($args['variation_id'] ?? 0), (float) $args['quantity'], is_array($args['variation_attributes'] ?? null) ? $args['variation_attributes'] : []);
        if (($resolved['ok'] ?? false) !== true) {
            return $resolved;
        }
        $before = WC()->cart->get_cart();
        $lineId = WC()->cart->add_to_cart($resolved['product_id'], $resolved['quantity'], $resolved['variation_id'], $resolved['variation_attributes']);
        $expectedQuantity = is_string($lineId) && isset($before[$lineId])
            ? (float) ($before[$lineId]['quantity'] ?? 0) + $resolved['quantity']
            : $resolved['quantity'];
        return is_string($lineId) && $lineId !== ''
            ? [
                'ok' => true,
                'code' => 'cart_item_added',
                'line_id' => $lineId,
                'product_id' => $resolved['product_id'],
                'variation_id' => $resolved['variation_id'],
                'quantity' => $resolved['quantity'],
                'expected_quantity' => $expectedQuantity,
            ]
            : ['ok' => false, 'code' => 'cart_item_add_failed'];
    }

    /** @return array<string, mixed> */
    private function update(string $lineId, float $quantity): array
    {
        $cart = WC()->cart->get_cart();
        if (!isset($cart[$lineId])) {
            return ['ok' => false, 'code' => 'cart_line_not_found'];
        }
        $quantity = function_exists('wc_stock_amount') ? (float) wc_stock_amount($quantity) : $quantity;
        if ($quantity > 0) {
            $product = $cart[$lineId]['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                return ['ok' => false, 'code' => 'product_not_available'];
            }
            $quantityFailure = $this->quantityFailure($product, $quantity);
            if ($quantityFailure !== null) {
                return ['ok' => false, 'code' => $quantityFailure];
            }
        }
        $changed = WC()->cart->set_quantity($lineId, $quantity, false);
        return $changed !== false ? ['ok' => true, 'code' => $quantity <= 0 ? 'cart_item_removed' : 'cart_quantity_updated', 'line_id' => $lineId, 'quantity' => $quantity] : ['ok' => false, 'code' => 'cart_quantity_update_failed'];
    }

    /** @return array<string, mixed> */
    private function remove(string $lineId): array
    {
        if (!isset(WC()->cart->get_cart()[$lineId])) {
            return ['ok' => false, 'code' => 'cart_line_not_found'];
        }
        return WC()->cart->remove_cart_item($lineId)
            ? ['ok' => true, 'code' => 'cart_item_removed', 'line_id' => $lineId]
            : ['ok' => false, 'code' => 'cart_item_remove_failed'];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function replace(array $args): array
    {
        $lineId = (string) $args['line_id'];
        $before = WC()->cart->get_cart();
        if (!isset($before[$lineId])) {
            return ['ok' => false, 'code' => 'cart_line_not_found'];
        }

        $resolved = $this->resolvePurchasableItem(
            (int) $args['product_id'],
            (int) ($args['variation_id'] ?? 0),
            (float) $args['quantity'],
            is_array($args['variation_attributes'] ?? null) ? $args['variation_attributes'] : []
        );
        if (($resolved['ok'] ?? false) !== true) {
            return $resolved;
        }

        $current = $before[$lineId];
        $sameConfiguration = (int) ($current['product_id'] ?? 0) === $resolved['product_id']
            && (int) ($current['variation_id'] ?? 0) === $resolved['variation_id']
            && $this->normalizedAttributes(is_array($current['variation'] ?? null) ? $current['variation'] : [])
                === $this->normalizedAttributes($resolved['variation_attributes']);
        if ($sameConfiguration) {
            $changed = WC()->cart->set_quantity($lineId, $resolved['quantity'], false);
            return $changed !== false
                ? [
                    'ok' => true,
                    'code' => 'cart_item_replaced_in_place',
                    'removed_line_id' => $lineId,
                    'added_line_id' => $lineId,
                    'product_id' => $resolved['product_id'],
                    'variation_id' => $resolved['variation_id'],
                    'quantity' => $resolved['quantity'],
                    'expected_quantity' => $resolved['quantity'],
                ]
                : ['ok' => false, 'code' => 'cart_replace_quantity_update_failed'];
        }

        $addedLineId = WC()->cart->add_to_cart(
            $resolved['product_id'],
            $resolved['quantity'],
            $resolved['variation_id'],
            $resolved['variation_attributes']
        );
        if (!is_string($addedLineId) || $addedLineId === '') {
            return ['ok' => false, 'code' => 'cart_item_add_failed'];
        }

        $targetExisted = isset($before[$addedLineId]);
        $targetQuantityBefore = $targetExisted ? (float) ($before[$addedLineId]['quantity'] ?? 0) : 0.0;
        if (!WC()->cart->remove_cart_item($lineId)) {
            $compensated = $targetExisted
                ? WC()->cart->set_quantity($addedLineId, $targetQuantityBefore, false) !== false
                : WC()->cart->remove_cart_item($addedLineId);
            if (!$compensated) {
                // The caller catches this and records an uncertain idempotency
                // outcome; claiming compensation here would be fabricated.
                throw new \RuntimeException('Cart replacement compensation could not be verified.');
            }
            return ['ok' => false, 'code' => 'cart_replace_compensated'];
        }
        return [
            'ok' => true,
            'code' => 'cart_item_replaced',
            'removed_line_id' => $lineId,
            'added_line_id' => $addedLineId,
            'product_id' => $resolved['product_id'],
            'variation_id' => $resolved['variation_id'],
            'quantity' => $resolved['quantity'],
            'expected_quantity' => $targetQuantityBefore + $resolved['quantity'],
        ];
    }

    /** @param array<string, mixed> $attributes @return array<string, string> */
    private function normalizedAttributes(array $attributes): array
    {
        $normalized = [];
        foreach ($attributes as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            $normalized[$key] = sanitize_title((string) $value);
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function applyCoupon(string $code): array
    {
        $code = function_exists('wc_format_coupon_code') ? wc_format_coupon_code($code) : trim($code);
        if ($code === '') {
            return ['ok' => false, 'code' => 'coupon_code_invalid'];
        }
        if (WC()->cart->has_discount($code)) {
            return ['ok' => true, 'code' => 'coupon_already_applied', 'coupon_code' => $code, 'changed' => false];
        }
        return WC()->cart->apply_coupon($code)
            ? ['ok' => true, 'code' => 'coupon_applied', 'coupon_code' => $code, 'changed' => true]
            : ['ok' => false, 'code' => 'coupon_not_applied'];
    }

    /** @return array<string, mixed> */
    private function removeCoupon(string $code): array
    {
        $code = function_exists('wc_format_coupon_code') ? wc_format_coupon_code($code) : trim($code);
        if (!WC()->cart->has_discount($code)) {
            return ['ok' => false, 'code' => 'coupon_not_applied'];
        }
        return WC()->cart->remove_coupon($code)
            ? ['ok' => true, 'code' => 'coupon_removed', 'coupon_code' => $code]
            : ['ok' => false, 'code' => 'coupon_remove_failed'];
    }

    /** @param array<int, mixed> $rawOperations @return array<string, mixed> */
    private function executePlan(array $rawOperations): array
    {
        $operations = $this->validatePlan($rawOperations);
        if ($operations === null) {
            return ['ok' => false, 'code' => 'cart_plan_invalid_or_ambiguous'];
        }
        $results = [];
        foreach ($operations as $operation) {
            $synthetic = new ToolCall('plan_' . count($results), 'cart.' . $operation['operation'], '1.0.0', $operation['arguments']);
            $result = $this->performWrite($synthetic);
            $results[] = ['operation' => $operation['operation'], 'result' => $result];
        }
        return MutationPlanOutcome::fromResults($results);
    }

    /** @return array<int, array<string, mixed>>|null */
    private function validatePlan(mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > 12) {
            return null;
        }
        $schemas = $this->mutationOperationSchemas();
        $result = [];
        $targets = [];
        foreach ($raw as $operation) {
            if (!is_array($operation) || array_is_list($operation)
                || array_diff(array_keys($operation), ['operation', 'arguments']) !== []
                || !is_string($operation['operation'] ?? null)
                || !isset($schemas[$operation['operation']])
                || !is_array($operation['arguments'] ?? null)
                || !$this->planInputs->validate($operation['arguments'], $schemas[$operation['operation']])
            ) {
                return null;
            }
            foreach ($this->planConflictKeys((string) $operation['operation'], $operation['arguments']) as $targetKey) {
                if (isset($targets[$targetKey])) {
                    return null;
                }
                $targets[$targetKey] = true;
            }
            $result[] = ['operation' => $operation['operation'], 'arguments' => $operation['arguments']];
        }
        return $result;
    }

    /** @param array<string, mixed> $arguments @return list<string> */
    private function planConflictKeys(string $operation, array $arguments): array
    {
        return match ($operation) {
            'update_quantity', 'remove_item' => ['line:' . (string) $arguments['line_id']],
            'replace_item' => [
                'line:' . (string) $arguments['line_id'],
                'item:' . (int) $arguments['product_id'] . ':' . (int) ($arguments['variation_id'] ?? 0),
            ],
            'add_item' => [
                'item:' . (int) $arguments['product_id'] . ':' . (int) ($arguments['variation_id'] ?? 0),
            ],
            'apply_coupon', 'remove_coupon' => [
                'coupon:' . strtolower(trim((string) $arguments['coupon_code'])),
            ],
            default => ['unknown:' . $operation],
        };
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $cart */
    private function mutationPostconditionsSatisfied(string $tool, array $operation, array $cart): bool
    {
        if (($operation['ok'] ?? false) !== true || !is_array($cart['lines'] ?? null) || !is_array($cart['coupons'] ?? null)) {
            return false;
        }
        if ($tool === 'cart.execute_mutation_plan') {
            $results = $operation['results'] ?? null;
            if (!is_array($results) || !array_is_list($results) || $results === []) {
                return false;
            }
            $verified = 0;
            foreach ($results as $result) {
                if (!is_array($result) || !is_array($result['result'] ?? null) || !is_string($result['operation'] ?? null)) {
                    return false;
                }
                if (($result['result']['ok'] ?? false) !== true) {
                    continue;
                }
                ++$verified;
                if (!$this->mutationPostconditionsSatisfied('cart.' . $result['operation'], $result['result'], $cart)) {
                    return false;
                }
            }
            return $verified > 0 && $verified === (int) ($operation['success_count'] ?? -1);
        }

        if ($tool === 'cart.apply_coupon' || $tool === 'cart.remove_coupon') {
            $coupon = strtolower((string) ($operation['coupon_code'] ?? ''));
            $coupons = array_map(static fn (mixed $value): string => strtolower((string) $value), $cart['coupons']);
            return $coupon !== '' && ($tool === 'cart.apply_coupon'
                ? in_array($coupon, $coupons, true)
                : !in_array($coupon, $coupons, true));
        }

        if ($tool === 'cart.remove_item') {
            return $this->cartLine($cart, (string) ($operation['line_id'] ?? '')) === null;
        }

        if ($tool === 'cart.update_quantity') {
            $lineId = (string) ($operation['line_id'] ?? '');
            $quantity = (float) ($operation['quantity'] ?? -1);
            $line = $this->cartLine($cart, $lineId);
            return $quantity <= 0
                ? $line === null
                : is_array($line) && $this->quantityEquals((float) ($line['quantity'] ?? -1), $quantity);
        }

        if ($tool === 'cart.add_item') {
            $line = $this->cartLine($cart, (string) ($operation['line_id'] ?? ''));
            return is_array($line)
                && (int) ($line['product_id'] ?? 0) === (int) ($operation['product_id'] ?? -1)
                && (int) ($line['variation_id'] ?? 0) === (int) ($operation['variation_id'] ?? -1)
                && $this->quantityEquals(
                    (float) ($line['quantity'] ?? -1),
                    (float) ($operation['expected_quantity'] ?? -2)
                );
        }

        if ($tool === 'cart.replace_item') {
            $removedLineId = (string) ($operation['removed_line_id'] ?? '');
            $addedLineId = (string) ($operation['added_line_id'] ?? '');
            $added = $this->cartLine($cart, $addedLineId);
            return $removedLineId !== ''
                && $addedLineId !== ''
                && ($removedLineId === $addedLineId || $this->cartLine($cart, $removedLineId) === null)
                && is_array($added)
                && (int) ($added['product_id'] ?? 0) === (int) ($operation['product_id'] ?? -1)
                && (int) ($added['variation_id'] ?? 0) === (int) ($operation['variation_id'] ?? -1)
                && $this->quantityEquals(
                    (float) ($added['quantity'] ?? -1),
                    (float) ($operation['expected_quantity'] ?? -2)
                );
        }

        return false;
    }

    /** @param array<string, mixed> $cart @return array<string, mixed>|null */
    private function cartLine(array $cart, string $lineId): ?array
    {
        if ($lineId === '') {
            return null;
        }
        foreach ($cart['lines'] as $line) {
            if (is_array($line) && ($line['line_id'] ?? null) === $lineId) {
                return $line;
            }
        }
        return null;
    }

    private function quantityEquals(float $actual, float $expected): bool
    {
        return is_finite($actual) && is_finite($expected) && abs($actual - $expected) <= 0.000001;
    }

    private function clearGateResult(
        ToolCall $call,
        ToolContext $context,
        string $status,
        string $code,
        ?\Veyra\Confirmation\Domain\IdempotencyRecord $record
    ): ToolResult {
        if ($status === IdempotencyDecisionStatus::Replay->value && $record !== null) {
            $data = is_array($record->result) ? $record->result : [];
            $data['idempotent_replay'] = true;
            if ($record->status === 'succeeded') {
                return new ToolResult(
                    $call->callId,
                    $call->name,
                    'succeeded',
                    $record->resultCode ?? 'cart_cleared',
                    $data,
                    ['cart:' . (string) ($data['cart']['hash'] ?? 'empty'), 'checkout:' . $context->conversationId . ':stale'],
                    true,
                    false,
                    $context->correlationId
                );
            }

            return new ToolResult(
                $call->callId,
                $call->name,
                $record->status === 'uncertain' ? 'uncertain' : 'failed',
                $record->resultCode ?? 'cart_clear_previous_attempt_failed',
                $data,
                [],
                true,
                $record->retrySafe,
                $context->correlationId
            );
        }
        if ($status === IdempotencyDecisionStatus::Conflict->value) {
            return ToolResult::failed($call, 'idempotency_payload_conflict', $context->correlationId, false);
        }
        if (in_array($status, [IdempotencyDecisionStatus::InProgress->value, IdempotencyDecisionStatus::ReconcileRequired->value], true)) {
            return new ToolResult($call->callId, $call->name, 'uncertain', $code, [], [], true, false, $context->correlationId);
        }
        if ($status === 'uncertain') {
            return new ToolResult($call->callId, $call->name, 'uncertain', $code, [], [], true, false, $context->correlationId);
        }

        return ToolResult::denied($call, $code, $context->correlationId);
    }

    /** @param array<string, mixed> $cart @return array<string, mixed> */
    private function clearMaterial(array $cart): array
    {
        $lines = [];
        foreach (is_array($cart['lines'] ?? null) ? $cart['lines'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lines[] = [
                'line_id' => (string) ($line['line_id'] ?? ''),
                'product_id' => (int) ($line['product_id'] ?? 0),
                'variation_id' => (int) ($line['variation_id'] ?? 0),
                'name' => (string) ($line['name'] ?? ''),
                'quantity' => (float) ($line['quantity'] ?? 0),
                'variation_attributes' => $this->normalizedAttributes(is_array($line['variation_attributes'] ?? null) ? $line['variation_attributes'] : []),
                'line_total' => (string) ($line['line_total'] ?? 0),
                'line_tax' => (string) ($line['line_tax'] ?? 0),
            ];
        }
        usort($lines, static fn (array $left, array $right): int => strcmp($left['line_id'], $right['line_id']));
        $coupons = array_values(array_map('strval', is_array($cart['coupons'] ?? null) ? $cart['coupons'] : []));
        sort($coupons, SORT_STRING);

        return [
            'action' => 'cart.clear_confirmed',
            'line_count' => count($lines),
            'lines' => $lines,
            'coupons' => $coupons,
            'cart_hash' => (string) ($cart['hash'] ?? ''),
            'currency' => (string) ($cart['currency'] ?? ''),
            'subtotal' => (string) ($cart['subtotal'] ?? 0),
            'discount_total' => (string) ($cart['discount_total'] ?? 0),
            'shipping_total' => (string) ($cart['shipping_total'] ?? 0),
            'shipping_tax' => (string) ($cart['shipping_tax'] ?? 0),
            'fee_total' => (string) ($cart['fee_total'] ?? 0),
            'total_tax' => (string) ($cart['total_tax'] ?? 0),
            'total' => (string) ($cart['total'] ?? 0),
        ];
    }

    private function cartScope(ToolContext $context): string
    {
        return 'cart:actor:' . hash('sha256', $context->actorType . ':' . $context->actorId);
    }

    private function woocommerceAuthorityLockKey(ToolContext $context): string
    {
        return 'woocommerce-commerce-authority:'
            . hash('sha256', $context->actorType . ':' . $context->actorId);
    }

    /** @return array<string, mixed> */
    private function checkoutInvalidation(ToolContext $context, string $reason): array
    {
        return [
            'checkout_state' => 'stale',
            'conversation_id' => $context->conversationId,
            'reason' => $reason,
            'invalidated_dependencies' => [
                'fulfillment', 'shipping_packages', 'shipping_methods', 'tax',
                'fees', 'payment_eligibility', 'totals', 'confirmation',
            ],
            'resume_from' => 'checkout_entry_preflight',
        ];
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function resolvePurchasableItem(int $productId, int $variationId, float $quantity, array $attributes): array
    {
        $parent = wc_get_product($productId);
        if (!$parent instanceof \WC_Product || $parent->get_status() !== 'publish' || !$parent->is_visible()) {
            return ['ok' => false, 'code' => 'product_not_available'];
        }
        $product = $variationId > 0 ? wc_get_product($variationId) : $parent;
        if (!$product instanceof \WC_Product || ($product instanceof \WC_Product_Variation && $product->get_parent_id() !== $productId)) {
            return ['ok' => false, 'code' => 'variation_not_available'];
        }
        if ($parent instanceof \WC_Product_Variable && !($product instanceof \WC_Product_Variation)) {
            return ['ok' => false, 'code' => 'exact_variation_required'];
        }
        $variationAttributes = [];
        if ($product instanceof \WC_Product_Variation) {
            if ($product->get_status() !== 'publish' || !$product->is_visible()) {
                return ['ok' => false, 'code' => 'variation_not_available'];
            }
            $variationAttributes = $product->get_variation_attributes();
            if (in_array('', $variationAttributes, true)) {
                return ['ok' => false, 'code' => 'variation_any_not_allowed'];
            }
            $suppliedAttributes = $this->normalizedAttributes($attributes);
            if ($suppliedAttributes !== [] && $suppliedAttributes !== $this->normalizedAttributes($variationAttributes)) {
                return ['ok' => false, 'code' => 'variation_attribute_mismatch'];
            }
        } elseif ($attributes !== []) {
            return ['ok' => false, 'code' => 'variation_attributes_not_applicable'];
        }
        $quantity = function_exists('wc_stock_amount') ? (float) wc_stock_amount($quantity) : $quantity;
        if ($quantity <= 0 || !$product->is_purchasable() || !$product->is_in_stock()) {
            return ['ok' => false, 'code' => 'product_not_purchasable'];
        }
        $quantityFailure = $this->quantityFailure($product, $quantity);
        if ($quantityFailure !== null) {
            return ['ok' => false, 'code' => $quantityFailure];
        }
        return ['ok' => true, 'product_id' => $productId, 'variation_id' => $variationId, 'quantity' => $quantity, 'variation_attributes' => $variationAttributes];
    }

    private function quantityFailure(\WC_Product $product, float $quantity): ?string
    {
        if (!is_finite($quantity) || $quantity <= 0) {
            return 'quantity_invalid';
        }
        $minimum = (float) $product->get_min_purchase_quantity();
        $maximum = (float) $product->get_max_purchase_quantity();
        if ($quantity < $minimum || ($maximum > 0 && $quantity > $maximum)) {
            return 'quantity_outside_purchase_limits';
        }
        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            return 'product_not_purchasable';
        }

        return $product->has_enough_stock($quantity) ? null : 'insufficient_stock';
    }

    /** @return array<string, mixed> */
    private function resolveLine(?string $lineId, int $productId, int $variationId): array
    {
        $cart = WC()->cart->get_cart();
        if ($lineId !== null && $lineId !== '') {
            return isset($cart[$lineId]) ? ['resolved' => true, 'line' => $this->lineSnapshot($lineId, $cart[$lineId])] : ['resolved' => false, 'code' => 'cart_line_not_found'];
        }
        $matches = [];
        foreach ($cart as $key => $item) {
            if ((int) ($item['product_id'] ?? 0) === $productId && ($variationId === 0 || (int) ($item['variation_id'] ?? 0) === $variationId)) {
                $matches[] = $this->lineSnapshot((string) $key, $item);
            }
        }
        return count($matches) === 1
            ? ['resolved' => true, 'line' => $matches[0]]
            : ['resolved' => false, 'code' => count($matches) === 0 ? 'cart_line_not_found' : 'cart_line_ambiguous', 'match_count' => count($matches), 'choices' => $matches];
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $cart = WC()->cart;
        $lines = [];
        foreach ($cart->get_cart() as $key => $item) {
            $lines[] = $this->lineSnapshot((string) $key, $item);
        }
        $totals = $cart->get_totals();
        return [
            'hash' => (string) $cart->get_cart_hash(),
            'currency' => get_woocommerce_currency(),
            'item_count' => (int) $cart->get_cart_contents_count(),
            'lines' => $lines,
            'coupons' => array_values(array_map('strval', array_keys($cart->get_coupons()))),
            'subtotal' => (string) ($totals['subtotal'] ?? 0),
            'discount_total' => (string) ($totals['discount_total'] ?? 0),
            'shipping_total' => (string) ($totals['shipping_total'] ?? 0),
            'shipping_tax' => (string) ($totals['shipping_tax'] ?? 0),
            'fee_total' => (string) ($totals['fee_total'] ?? 0),
            'total_tax' => (string) ($totals['total_tax'] ?? 0),
            'total' => (string) ($totals['total'] ?? 0),
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function safeSnapshot(): array
    {
        try {
            return $this->snapshot();
        } catch (\Throwable) {
            return [
                'available' => false,
                'freshness' => 'unknown',
                'observed_at' => gmdate(DATE_ATOM),
            ];
        }
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function lineSnapshot(string $key, array $item): array
    {
        $product = $item['data'] ?? null;
        return [
            'line_id' => $key,
            'product_id' => (int) ($item['product_id'] ?? 0),
            'variation_id' => (int) ($item['variation_id'] ?? 0),
            'name' => $product instanceof \WC_Product ? $product->get_name() : '',
            'quantity' => (float) ($item['quantity'] ?? 0),
            'variation_attributes' => is_array($item['variation'] ?? null) ? $item['variation'] : [],
            'unit_price' => $product instanceof \WC_Product ? (string) $product->get_price() : null,
            'line_subtotal' => (string) ($item['line_subtotal'] ?? 0),
            'line_total' => (string) ($item['line_total'] ?? 0),
            'line_tax' => (string) ($item['line_tax'] ?? 0),
        ];
    }
}
