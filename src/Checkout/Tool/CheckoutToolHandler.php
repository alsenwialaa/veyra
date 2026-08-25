<?php

declare(strict_types=1);

namespace Veyra\Checkout\Tool;

use Veyra\Audit\Application\AuditWriter;
use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolExecutionPreflight;
use Veyra\AI\Tool\ToolHandler;
use Veyra\Checkout\Application\CheckoutAuthority;
use Veyra\Checkout\Application\CheckoutInputSanitizer;
use Veyra\Checkout\Application\CheckoutSessionService;
use Veyra\Checkout\Application\CheckoutStateConflict;
use Veyra\Checkout\Domain\CheckoutState;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Confirmation\Domain\IdempotencyDecisionStatus;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\StateHash;

/**
 * Conversational checkout tools stop at a fresh order preview. Sensitive order
 * placement and gateway authorization are intentionally absent from this class.
 */
final class CheckoutToolHandler implements ToolHandler, ToolExecutionPreflight
{
    public function __construct(
        private readonly CheckoutSessionService $sessions,
        private readonly CheckoutAuthority $authority,
        private readonly IdempotencyService $idempotency,
        private readonly FoundationActorMapper $actors,
        private readonly CheckoutInputSanitizer $input,
        private readonly LockManager $locks,
        private readonly ?AuditWriter $audit = null
    ) {
    }

    public function definitions(): array
    {
        $actors = ['customer'];
        $features = ['commerce_chat_checkout'];
        $write = [
            'expected_version' => ['type' => 'integer', 'minimum' => 1],
            'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 191],
        ];

        return [
            $this->definition('checkout.get_journey_state', 'Read the actor-owned persistent checkout state and current cart freshness.', 'read', [], [], $actors, $features),
            $this->definition('checkout.classify_cart_fulfillment', 'Classify the current authoritative cart before asking fulfillment-dependent questions.', 'read', [], [], $actors, $features),
            $this->definition('checkout.get_fulfillment_modes', 'Read currently eligible high-level fulfillment modes; shipping rates remain separate.', 'read', [], [], $actors, $features),
            $this->definition('checkout.select_fulfillment_mode', 'Select one exact currently eligible fulfillment mode and invalidate dependent state.', 'write', array_merge([
                'fulfillment_mode' => ['type' => 'string', 'maxLength' => 32],
            ], $write), ['fulfillment_mode', 'expected_version', 'idempotency_key'], $actors, $features),
            $this->definition('checkout.get_shipping_contact', 'Read the actor-owned shipping contact and address separately from billing.', 'read', [], [], $actors, $features),
            $this->definition('checkout.update_shipping_contact', 'Update only explicit shipping contact/address fields and invalidate dependent commercial state.', 'write', array_merge([
                'contact' => $this->contactSchema(),
                'address' => $this->addressSchema(),
            ], $write), ['expected_version', 'idempotency_key'], $actors, $features),
            $this->definition('checkout.get_billing_contact', 'Read the actor-owned billing contact and address separately from shipping.', 'read', [], [], $actors, $features),
            $this->definition('checkout.update_billing_contact', 'Update only explicit billing contact/address fields and invalidate payment/totals.', 'write', array_merge([
                'contact' => $this->contactSchema(),
                'address' => $this->addressSchema(),
            ], $write), ['expected_version', 'idempotency_key'], $actors, $features),
            $this->definition('checkout.get_required_fields', 'Read current WooCommerce checkout fields and identify only missing required values.', 'read', [], [], $actors, $features),
            $this->definition('checkout.get_shipping_packages', 'Calculate current WooCommerce shipping packages, destinations and exact available rate IDs.', 'read', [], [], $actors, $features),
            $this->definition('checkout.get_shipping_methods', 'Read current WooCommerce rate options per exact package without inventing costs or estimates.', 'read', [], [], $actors, $features),
            $this->definition('checkout.select_shipping_method', 'Select one exact current rate for every current package and invalidate payment/totals.', 'write', array_merge([
                'selections' => [
                    'type' => 'array',
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['package_id', 'rate_id'],
                        'properties' => [
                            'package_id' => ['type' => 'string', 'maxLength' => 64],
                            'rate_id' => ['type' => 'string', 'maxLength' => 191],
                        ],
                    ],
                ],
            ], $write), ['selections', 'expected_version', 'idempotency_key'], $actors, $features),
            $this->definition('checkout.get_payment_methods', 'Read only currently eligible WooCommerce payment methods after required eligibility context exists.', 'read', [], [], $actors, $features),
            $this->definition('checkout.select_payment_method', 'Select one exact currently eligible WooCommerce payment method without authorizing or charging it.', 'write', array_merge([
                'payment_method_id' => ['type' => 'string', 'maxLength' => 191],
            ], $write), ['payment_method_id', 'expected_version', 'idempotency_key'], $actors, $features),
            $this->definition('checkout.calculate', 'Refresh WooCommerce shipping, tax, fees, discounts, payment eligibility and totals without placing an order.', 'read', [], [], $actors, $features),
            $this->definition('checkout.get_preview', 'Build one fresh exact order preview; never create a confirmation, order, charge or gateway handoff.', 'read', [], [], $actors, $features),
            $this->definition('checkout.invalidate_dependent_state', 'Explicitly invalidate state after a known material dependency changes.', 'write', array_merge([
                'reason' => ['type' => 'string', 'enum' => ['cart_changed', 'fulfillment_changed', 'contact_changed', 'shipping_changed', 'payment_changed']],
            ], $write), ['reason', 'expected_version', 'idempotency_key'], $actors, $features),
        ];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        if ($context->actorType !== 'customer' || $context->userId === null) {
            return ToolResult::denied($call, 'checkout_authentication_required', $context->correlationId);
        }
        if (!$this->authority->available()) {
            return ToolResult::failed($call, 'checkout_runtime_unavailable', $context->correlationId, false);
        }
        if (!$this->authority->actorMatches($context->userId)) {
            return ToolResult::denied($call, 'checkout_actor_binding_invalid', $context->correlationId);
        }

        return match ($call->name) {
            'checkout.get_journey_state' => $this->authorityLockedRead($call, $context, fn (): ToolResult => $this->journeyState($call, $context)),
            'checkout.classify_cart_fulfillment' => $this->authorityLockedRead($call, $context, fn (): ToolResult => $this->authorityRead($call, $context, fn (): array => $this->authority->classifyFulfillment())),
            'checkout.get_fulfillment_modes' => $this->authorityLockedRead($call, $context, fn (): ToolResult => $this->stateRead($call, $context, fn (CheckoutState $state): array => $this->authority->fulfillmentModes($state))),
            'checkout.get_shipping_contact' => $this->authorityLockedRead($call, $context, fn (): ToolResult => $this->stateRead($call, $context, static fn (CheckoutState $state): array => [
                'ok' => true,
                'contact' => is_array($state->contacts['shipping'] ?? null) ? $state->contacts['shipping'] : [],
                'address' => $state->shippingAddress,
                'source' => 'actor_owned_checkout_state',
            ])),
            'checkout.get_billing_contact' => $this->authorityLockedRead($call, $context, fn (): ToolResult => $this->stateRead($call, $context, static fn (CheckoutState $state): array => [
                'ok' => true,
                'contact' => is_array($state->contacts['billing'] ?? null) ? $state->contacts['billing'] : [],
                'address' => $state->billingAddress,
                'source' => 'actor_owned_checkout_state',
            ])),
            'checkout.get_required_fields' => $this->authorityLockedRead($call, $context, fn (): ToolResult => $this->stateRead($call, $context, fn (CheckoutState $state): array => $this->authority->requiredFields($state))),
            'checkout.get_shipping_packages' => $this->authorityLockedRead($call, $context, fn (): ToolResult => $this->stateRead($call, $context, fn (CheckoutState $state): array => $this->authority->shippingPackages($state))),
            'checkout.get_shipping_methods' => $this->authorityLockedRead($call, $context, fn (): ToolResult => $this->stateRead($call, $context, function (CheckoutState $state): array {
                $packages = $this->authority->shippingPackages($state);
                if (($packages['ok'] ?? false) !== true) {
                    return $packages;
                }
                $methods = [];
                foreach ($packages['packages'] as $package) {
                    $methods[] = ['package_id' => $package['package_id'], 'rates' => $package['rates']];
                }

                return ['ok' => true, 'methods_by_package' => $methods, 'package_fingerprint' => $packages['package_fingerprint'], 'observed_at' => $packages['observed_at']];
            })),
            'checkout.get_payment_methods' => $this->authorityLockedRead($call, $context, fn (): ToolResult => $this->stateRead($call, $context, fn (CheckoutState $state): array => $this->authority->paymentMethods($state))),
            'checkout.calculate' => $this->authorityLockedRead($call, $context, fn (): ToolResult => $this->stateRead($call, $context, fn (CheckoutState $state): array => $this->authority->calculate($state))),
            'checkout.get_preview' => $this->authorityLockedRead($call, $context, fn (): ToolResult => $this->preview($call, $context)),
            'checkout.select_fulfillment_mode', 'checkout.update_shipping_contact', 'checkout.update_billing_contact',
            'checkout.select_shipping_method', 'checkout.select_payment_method', 'checkout.invalidate_dependent_state'
                => $this->idempotentMutation($call, $context),
            default => ToolResult::failed($call, 'tool_operation_unknown', $context->correlationId, false),
        };
    }

    public function beforeExecute(ToolCall $call, ToolContext $context): ?ToolResult
    {
        if ($context->actorType !== 'customer' || $context->userId === null) {
            return ToolResult::denied($call, 'checkout_authentication_required', $context->correlationId);
        }
        if (!$this->authority->available()) {
            return ToolResult::failed($call, 'checkout_runtime_unavailable', $context->correlationId, false);
        }
        if (!$this->authority->actorMatches($context->userId)) {
            return ToolResult::denied($call, 'checkout_actor_binding_invalid', $context->correlationId);
        }
        try {
            $authorityLock = $this->locks->acquire(
                $this->woocommerceAuthorityLockKey($context),
                new CorrelationId($context->correlationId),
                30
            );
        } catch (\Throwable) {
            $authorityLock = null;
        }
        if ($authorityLock === null) {
            return new ToolResult(
                $call->callId,
                $call->name,
                'blocked',
                'checkout_authority_lock_unavailable',
                [],
                [],
                true,
                true,
                $context->correlationId
            );
        }
        try {
        $current = $this->sessions->current($this->scope($context), $context->conversationId);
        if ($current !== null) {
            $unsupported = $this->unsupportedRequiredFields($current);
            if ($unsupported === null && $call->name !== 'checkout.get_required_fields') {
                return ToolResult::failed($call, 'checkout_required_fields_preflight_unavailable', $context->correlationId, false);
            }
            if ($unsupported !== [] && $call->name !== 'checkout.get_required_fields') {
                return $this->unsupportedCheckoutResult($call, $context, $unsupported);
            }
        }
        if ($call->name !== 'checkout.get_journey_state') {
            return null;
        }
        if ($current !== null) {
            return null;
        }

        try {
            $opened = $this->openForContextWithoutLock($context);
        } catch (\Throwable) {
            return ToolResult::failed($call, 'checkout_entry_preflight_failed', $context->correlationId, true);
        }
        $unsupported = $this->unsupportedRequiredFields($opened);
        if ($unsupported === null) {
            return ToolResult::failed($call, 'checkout_required_fields_preflight_unavailable', $context->correlationId, false);
        }
        if ($unsupported !== []) {
            return $this->unsupportedCheckoutResult($call, $context, $unsupported);
        }
        if ($this->audit !== null) {
            try {
                $this->audit->writeRequired(
                    $this->actors->map($context),
                    'checkout.entry_preflight',
                    'checkout_session',
                    $opened->id,
                    $opened->version === 1 ? 'checkout_entry_opened' : 'checkout_entry_reopened',
                    new CorrelationId($context->correlationId),
                    ['conversation_id' => $context->conversationId, 'version' => $opened->version]
                );
            } catch (\Throwable) {
                return new ToolResult(
                    $call->callId,
                    $call->name,
                    'uncertain',
                    'checkout_entry_audit_uncertain',
                    ['checkout_session_id' => $opened->id, 'checkout_version' => $opened->version],
                    [],
                    true,
                    false,
                    $context->correlationId
                );
            }
        }

        return null;
        } finally {
            try {
                $this->locks->release($authorityLock);
            } catch (\Throwable) {
                // The bounded lease expires without changing read truth.
            }
        }
    }

    /** @return list<string>|null */
    private function unsupportedRequiredFields(CheckoutState $state): ?array
    {
        try {
            $fields = $this->authority->requiredFields($state);
        } catch (\Throwable) {
            return null;
        }
        if (($fields['ok'] ?? false) !== true) {
            return null;
        }
        if (is_array($fields['unsupported_required_fields'] ?? null)) {
            return array_values(array_filter(
                $fields['unsupported_required_fields'],
                static fn (mixed $field): bool => is_string($field) && $field !== ''
            ));
        }

        $supportedSuffixes = [
            'first_name', 'last_name', 'company', 'email', 'phone',
            'address_1', 'address_2', 'city', 'state', 'postcode', 'country',
        ];
        $unsupported = [];
        foreach (is_array($fields['fields'] ?? null) ? $fields['fields'] : [] as $field) {
            if (!is_array($field) || ($field['required'] ?? false) !== true || !is_string($field['field_id'] ?? null)) {
                continue;
            }
            $id = $field['field_id'];
            $capturable = false;
            foreach (['billing_', 'shipping_'] as $prefix) {
                if (str_starts_with($id, $prefix)
                    && in_array(substr($id, strlen($prefix)), $supportedSuffixes, true)
                ) {
                    $capturable = true;
                    break;
                }
            }
            if (!$capturable) {
                $unsupported[] = $id;
            }
        }

        return array_values(array_unique($unsupported));
    }

    /** @param list<string> $unsupported */
    private function unsupportedCheckoutResult(ToolCall $call, ToolContext $context, array $unsupported): ToolResult
    {
        return new ToolResult(
            $call->callId,
            $call->name,
            'blocked',
            'checkout_extension_fields_require_standard_handoff',
            [
                'unsupported_required_fields' => $unsupported,
                'chat_checkout_supported' => false,
                'standard_checkout_handoff_required' => true,
            ],
            [],
            true,
            false,
            $context->correlationId
        );
    }

    /**
     * Runtime integration point for entry/preflight. It is intentionally not a
     * model-visible write; the caller has already resolved the actor/conversation.
     */
    public function openForContext(ToolContext $context, ?string $journeyId = null): CheckoutState
    {
        try {
            $lock = $this->locks->acquire(
                $this->woocommerceAuthorityLockKey($context),
                new CorrelationId($context->correlationId),
                30
            );
        } catch (\Throwable) {
            $lock = null;
        }
        if ($lock === null) {
            throw new \RuntimeException('The actor-owned Woo authority is busy.');
        }
        try {
            return $this->openForContextWithoutLock($context, $journeyId);
        } finally {
            try {
                $this->locks->release($lock);
            } catch (\Throwable) {
                // The bounded lease expires without changing the opened state.
            }
        }
    }

    private function openForContextWithoutLock(ToolContext $context, ?string $journeyId = null): CheckoutState
    {
        $actor = $this->actors->map($context);
        $cart = $this->authority->cart();
        if (($cart['ok'] ?? false) !== true || ($cart['empty'] ?? true) === true || !is_string($cart['hash'] ?? null) || $cart['hash'] === '') {
            throw new \RuntimeException('A non-empty authoritative cart is required to open checkout.');
        }

        return $this->sessions->open(ActorScope::fromActor($actor), $context->conversationId, $cart['hash'], $journeyId);
    }

    /** @param array<string, array<string, mixed>> $properties @param list<string> $required @param list<string> $actors @param list<string> $features */
    private function definition(string $name, string $description, string $classification, array $properties, array $required, array $actors, array $features): ToolDefinition
    {
        return new ToolDefinition($name, '1.0.0', $description, $classification, [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ], $actors, [], $features, true);
    }

    /** @return array<string, mixed> */
    private function contactSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'first_name' => ['type' => 'string', 'maxLength' => 255],
                'last_name' => ['type' => 'string', 'maxLength' => 255],
                'company' => ['type' => 'string', 'maxLength' => 255],
                'email' => ['type' => 'string', 'maxLength' => 255],
                'phone' => ['type' => 'string', 'maxLength' => 255],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function addressSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'address_1' => ['type' => 'string', 'maxLength' => 255],
                'address_2' => ['type' => 'string', 'maxLength' => 255],
                'city' => ['type' => 'string', 'maxLength' => 255],
                'state' => ['type' => 'string', 'maxLength' => 255],
                'postcode' => ['type' => 'string', 'maxLength' => 255],
                'country' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 2],
            ],
        ];
    }

    private function journeyState(ToolCall $call, ToolContext $context): ToolResult
    {
        $scope = $this->scope($context);
        $state = $this->sessions->current($scope, $context->conversationId);
        if ($state === null) {
            return ToolResult::failed($call, 'checkout_not_open_or_expired', $context->correlationId, true);
        }
        $cart = $this->authority->cart();
        if (($cart['ok'] ?? false) !== true) {
            return ToolResult::failed($call, (string) ($cart['code'] ?? 'checkout_cart_unavailable'), $context->correlationId, false);
        }
        $data = ['checkout' => $state->jsonSerialize(), 'cart' => $cart];
        if (!$state->hasCurrentCart((string) $cart['hash'])) {
            return new ToolResult($call->callId, $call->name, 'stale', 'checkout_cart_stale', $data, [], true, true, $context->correlationId);
        }

        return ToolResult::success($call, $data, $context->correlationId);
    }

    /** @param callable(): array<string, mixed> $read */
    private function authorityRead(ToolCall $call, ToolContext $context, callable $read): ToolResult
    {
        $data = $read();
        if (($data['ok'] ?? false) !== true) {
            return ToolResult::failed($call, (string) ($data['code'] ?? 'checkout_authority_read_failed'), $context->correlationId, true);
        }
        unset($data['ok']);

        return ToolResult::success($call, $data, $context->correlationId);
    }

    /** @param callable(CheckoutState): array<string, mixed> $read */
    private function stateRead(ToolCall $call, ToolContext $context, callable $read): ToolResult
    {
        $state = $this->sessions->current($this->scope($context), $context->conversationId);
        if ($state === null) {
            return ToolResult::failed($call, 'checkout_not_open_or_expired', $context->correlationId, true);
        }
        $cart = $this->authority->cart();
        if (($cart['ok'] ?? false) !== true) {
            return ToolResult::failed($call, (string) ($cart['code'] ?? 'checkout_cart_unavailable'), $context->correlationId, false);
        }
        if (!$state->hasCurrentCart((string) ($cart['hash'] ?? ''))) {
            return new ToolResult(
                $call->callId,
                $call->name,
                'stale',
                'checkout_cart_stale',
                ['checkout' => $state->jsonSerialize(), 'current_cart' => $cart],
                [],
                true,
                true,
                $context->correlationId
            );
        }
        $data = $read($state);
        if (($data['ok'] ?? false) !== true) {
            $status = in_array((string) ($data['code'] ?? ''), ['shipping_packages_stale', 'shipping_rate_not_currently_available'], true) ? 'stale' : 'failed';

            return new ToolResult(
                $call->callId,
                $call->name,
                $status,
                (string) ($data['code'] ?? 'checkout_authority_read_failed'),
                $data,
                [],
                true,
                true,
                $context->correlationId
            );
        }
        unset($data['ok']);

        return ToolResult::success($call, array_merge($data, ['checkout_version' => $state->version, 'checkout_state_hash' => $state->stateHash()->value()]), $context->correlationId);
    }

    private function preview(ToolCall $call, ToolContext $context): ToolResult
    {
        $state = $this->sessions->current($this->scope($context), $context->conversationId);
        if ($state === null) {
            return ToolResult::failed($call, 'checkout_not_open_or_expired', $context->correlationId, true);
        }
        $calculation = $this->authority->calculate($state);
        if (($calculation['ok'] ?? false) !== true) {
            $code = (string) ($calculation['code'] ?? 'checkout_calculation_failed');
            $status = str_contains($code, 'stale') || str_contains($code, 'changed') ? 'stale' : 'blocked';

            return new ToolResult($call->callId, $call->name, $status, $code, $calculation, [], true, true, $context->correlationId);
        }
        if (($calculation['complete'] ?? false) !== true) {
            return new ToolResult(
                $call->callId,
                $call->name,
                'blocked',
                'checkout_required_fields_missing',
                ['missing_required_fields' => $calculation['missing_required_fields'] ?? []],
                [],
                true,
                true,
                $context->correlationId
            );
        }

        $shippingContact = is_array($state->contacts['shipping'] ?? null) ? $state->contacts['shipping'] : [];
        $billingContact = is_array($state->contacts['billing'] ?? null) ? $state->contacts['billing'] : [];
        $materialPreview = [
            'products' => $calculation['cart']['lines'],
            'coupons' => $calculation['cart']['coupons'],
            'fulfillment_mode' => $state->fulfillmentMode,
            'shipping_contact' => $shippingContact,
            'shipping_address' => $state->shippingAddress,
            'billing_contact' => $billingContact,
            'billing_address' => $state->billingAddress,
            'shipping' => $calculation['shipping'],
            'totals' => $calculation['totals'],
            'currency' => $calculation['currency'],
            'payment_method' => $calculation['payment_method'],
        ];
        $preview = $materialPreview + [
            'calculated_at' => $calculation['calculated_at'],
            'woocommerce_authoritative' => true,
        ];
        $previewHash = StateHash::fromPayload([
            'checkout_state_hash' => $state->stateHash()->value(),
            'cart_hash' => $calculation['cart']['hash'],
            'preview' => $materialPreview,
        ])->value();

        return ToolResult::success($call, [
            'preview' => $preview,
            'preview_state_hash' => $previewHash,
            'preview_complete' => true,
            'ready_for_confirmation' => false,
            'execution_supported' => false,
            'execution_gate' => 'blocked',
            'blocking_reasons' => ['order_placement_tool_not_published'],
            'confirmation_required_for_placement' => true,
            'confirmation_created' => false,
            'order_placed' => false,
            'payment_authorization_started' => false,
            'sensitive_order_placement_tool_exposed' => false,
        ], $context->correlationId);
    }

    private function idempotentMutation(ToolCall $call, ToolContext $context): ToolResult
    {
        $scope = $this->scope($context);
        $state = $this->sessions->current($scope, $context->conversationId);
        if ($state === null) {
            return ToolResult::failed($call, 'checkout_not_open_or_expired', $context->correlationId, true);
        }
        $key = (string) ($call->arguments['idempotency_key'] ?? '');
        $payload = $call->arguments;
        unset($payload['idempotency_key']);
        try {
            $decision = $this->idempotency->begin(
                $this->actors->map($context),
                $call->name,
                $key,
                $payload,
                'checkout:' . $state->id,
                new CorrelationId($context->correlationId)
            );
        } catch (\Throwable) {
            return ToolResult::failed($call, 'idempotency_unavailable', $context->correlationId, false);
        }
        if ($decision->status === IdempotencyDecisionStatus::Replay) {
            return $this->replay(
                $call,
                $context,
                $decision->record->status,
                $decision->record->resultCode,
                $decision->record->result ?? [],
                $decision->record->retrySafe
            );
        }
        if ($decision->status === IdempotencyDecisionStatus::Conflict) {
            return ToolResult::failed($call, 'idempotency_payload_conflict', $context->correlationId, false);
        }
        if ($decision->status === IdempotencyDecisionStatus::InProgress) {
            return new ToolResult($call->callId, $call->name, 'uncertain', 'idempotency_in_progress', [], [], true, false, $context->correlationId);
        }
        if ($decision->status === IdempotencyDecisionStatus::ReconcileRequired) {
            try {
                $current = $this->sessions->current($scope, $context->conversationId);
            } catch (\Throwable) {
                $current = null;
            }

            return new ToolResult(
                $call->callId,
                $call->name,
                'uncertain',
                'checkout_reconciliation_required',
                ['checkout' => $current?->jsonSerialize()],
                [],
                true,
                false,
                $context->correlationId
            );
        }

        try {
            // Woo selections live in one actor session, not in an individual
            // conversation checkout record. Keep this actor-wide so two open
            // checkout conversations or cart writes cannot mutate the shared
            // authority state concurrently; the CAS below remains
            // checkout-record-specific.
            $lock = $this->locks->acquire(
                $this->woocommerceAuthorityLockKey($context),
                new CorrelationId($context->correlationId),
                60
            );
        } catch (\Throwable) {
            $lock = null;
        }
        if ($lock === null) {
            $stored = ['result_status' => 'blocked', 'data' => []];
            try {
                $transitioned = $this->idempotency->fail(
                    $decision->record,
                    'checkout_write_lock_unavailable',
                    $stored,
                    true
                );
            } catch (\Throwable) {
                $transitioned = false;
            }
            if (!$transitioned) {
                return new ToolResult(
                    $call->callId,
                    $call->name,
                    'uncertain',
                    'checkout_idempotency_transition_uncertain',
                    [],
                    [],
                    true,
                    false,
                    $context->correlationId
                );
            }

            return new ToolResult(
                $call->callId,
                $call->name,
                'blocked',
                'checkout_write_lock_unavailable',
                [],
                [],
                true,
                true,
                $context->correlationId
            );
        }

        try {
            // Re-read only after acquiring the lease. The initial state was
            // sufficient to bind idempotency and the lock, not to authorize a
            // mutation after another request may have advanced the version.
            $lockedState = $this->sessions->current($scope, $context->conversationId);
            $operation = $lockedState === null
                ? [
                    'ok' => false,
                    'code' => 'checkout_not_open_or_expired',
                    'result_status' => 'stale',
                    'data' => [],
                ]
                : $this->performMutation($call, $context, $lockedState);
            if (($operation['ok'] ?? false) !== true) {
                $code = (string) ($operation['code'] ?? 'checkout_mutation_failed');
                $status = (string) ($operation['result_status'] ?? 'failed');
                $data = is_array($operation['data'] ?? null) ? $operation['data'] : [];
                $stored = ['result_status' => $status, 'data' => $data];
                if ($status === 'uncertain') {
                    if (!$this->idempotency->markUncertain($decision->record, $code, $stored)) {
                        $code = 'checkout_idempotency_transition_uncertain';
                    }

                    return new ToolResult($call->callId, $call->name, 'uncertain', $code, $data, [], true, false, $context->correlationId);
                }
                if (!$this->idempotency->fail($decision->record, $code, $stored, true)) {
                    return new ToolResult(
                        $call->callId,
                        $call->name,
                        'uncertain',
                        'checkout_idempotency_transition_uncertain',
                        $data,
                        [],
                        true,
                        false,
                        $context->correlationId
                    );
                }

                return new ToolResult($call->callId, $call->name, $status, $code, $data, [], true, true, $context->correlationId);
            }
            $data = $operation['data'];
            if (!$this->idempotency->complete($decision->record, (string) $operation['code'], ['result_status' => 'succeeded', 'data' => $data], false)) {
                return new ToolResult($call->callId, $call->name, 'uncertain', 'idempotency_completion_uncertain', $data, [], true, false, $context->correlationId);
            }

            return ToolResult::success($call, $data, $context->correlationId, ['checkout:' . ($lockedState?->id ?? $state->id)]);
        } catch (\Throwable) {
            try {
                $current = $this->sessions->current($scope, $context->conversationId);
            } catch (\Throwable) {
                $current = null;
            }
            $known = ['checkout' => $current?->jsonSerialize()];
            try {
                $this->idempotency->markUncertain($decision->record, 'checkout_mutation_uncertain', $known);
            } catch (\Throwable) {
                // The public result remains uncertain even when persistence of
                // that status also needs operator reconciliation.
            }

            return new ToolResult($call->callId, $call->name, 'uncertain', 'checkout_mutation_uncertain', $known, [], true, false, $context->correlationId);
        } finally {
            try {
                $this->locks->release($lock);
            } catch (\Throwable) {
                // The bounded lease expires; releasing it cannot change the
                // already established commerce/checkout outcome.
            }
        }
    }

    /** @return array{ok: bool, code: string, data?: array<string, mixed>, result_status?: string} */
    private function performMutation(ToolCall $call, ToolContext $context, CheckoutState $state): array
    {
        $expected = (int) ($call->arguments['expected_version'] ?? 0);
        if ($expected < 1 || $expected !== $state->version) {
            return ['ok' => false, 'code' => 'checkout_version_stale', 'result_status' => 'stale', 'data' => ['checkout' => $state->jsonSerialize()]];
        }
        $cart = $this->authority->cart();
        if (($cart['ok'] ?? false) !== true) {
            return ['ok' => false, 'code' => (string) ($cart['code'] ?? 'checkout_cart_unavailable'), 'data' => []];
        }
        if ($call->name === 'checkout.invalidate_dependent_state'
            && ($call->arguments['reason'] ?? null) === 'cart_changed'
            && (($cart['empty'] ?? false) === true || (int) ($cart['item_count'] ?? 0) === 0)
        ) {
            return [
                'ok' => false,
                'code' => 'checkout_cart_empty_or_unavailable',
                'result_status' => 'blocked',
                'data' => ['current_cart' => $cart],
            ];
        }
        if ($call->name !== 'checkout.invalidate_dependent_state' && !$state->hasCurrentCart((string) ($cart['hash'] ?? ''))) {
            return ['ok' => false, 'code' => 'checkout_cart_stale', 'result_status' => 'stale', 'data' => ['checkout' => $state->jsonSerialize(), 'current_cart' => $cart]];
        }

        $selectionWrite = in_array($call->name, [
            'checkout.select_shipping_method',
            'checkout.select_payment_method',
        ], true);
        $selectionSnapshot = null;
        if ($selectionWrite) {
            try {
                $selectionSnapshot = $this->authority->selectionSnapshot();
            } catch (\Throwable) {
                return [
                    'ok' => false,
                    'code' => 'checkout_authority_snapshot_unavailable',
                    'result_status' => 'blocked',
                    'data' => ['checkout' => $state->jsonSerialize()],
                ];
            }
        }

        try {
            $changes = match ($call->name) {
                'checkout.select_fulfillment_mode' => $this->fulfillmentMutation($state, (string) ($call->arguments['fulfillment_mode'] ?? '')),
                'checkout.update_shipping_contact' => $this->contactMutation($state, 'shipping', $call->arguments),
                'checkout.update_billing_contact' => $this->contactMutation($state, 'billing', $call->arguments),
                'checkout.select_shipping_method' => $this->shippingMutation($state, $call->arguments['selections'] ?? null),
                'checkout.select_payment_method' => $this->paymentMutation($state, (string) ($call->arguments['payment_method_id'] ?? '')),
                'checkout.invalidate_dependent_state' => $this->invalidationMutation($state, (string) ($call->arguments['reason'] ?? ''), (string) ($cart['hash'] ?? '')),
                default => throw new \InvalidArgumentException('Unknown checkout mutation.'),
            };
        } catch (\InvalidArgumentException $error) {
            return ['ok' => false, 'code' => $this->publicValidationCode($error), 'data' => []];
        } catch (\Throwable) {
            if ($selectionSnapshot !== null) {
                return $this->compensatedSelectionFailure(
                    $selectionSnapshot,
                    'checkout_authority_mutation_failed',
                    'failed',
                    ['checkout' => $state->jsonSerialize()]
                );
            }

            throw new \RuntimeException('Checkout mutation failed before persistence.');
        }
        $authoritySessionTouched = ($changes['authority_session_touched'] ?? false) === true;
        if (($changes['ok'] ?? true) === false) {
            $failure = [
                'ok' => false,
                'code' => (string) ($changes['code'] ?? 'checkout_mutation_invalid'),
                'result_status' => str_contains((string) ($changes['code'] ?? ''), 'stale') ? 'stale' : 'failed',
                'data' => is_array($changes['data'] ?? null) ? $changes['data'] : [],
            ];
            if ($authoritySessionTouched && $selectionSnapshot !== null) {
                return $this->compensatedSelectionFailure(
                    $selectionSnapshot,
                    $failure['code'],
                    $failure['result_status'],
                    $failure['data']
                );
            }

            return $failure;
        }
        $authorityData = is_array($changes['authority_data'] ?? null) ? $changes['authority_data'] : [];
        unset($changes['ok'], $changes['code'], $changes['data'], $changes['authority_data'], $changes['authority_session_touched']);
        try {
            $next = $this->sessions->mutate($this->scope($context), $context->conversationId, $expected, static fn (): array => $changes);
        } catch (CheckoutStateConflict) {
            $current = $this->sessions->current($this->scope($context), $context->conversationId);

            if ($authoritySessionTouched && $selectionSnapshot !== null) {
                return $this->compensatedSelectionFailure(
                    $selectionSnapshot,
                    'checkout_version_stale',
                    'stale',
                    ['checkout' => $current?->jsonSerialize()]
                );
            }

            return ['ok' => false, 'code' => 'checkout_version_stale', 'result_status' => 'stale', 'data' => ['checkout' => $current?->jsonSerialize()]];
        } catch (\Throwable) {
            $current = $this->sessions->current($this->scope($context), $context->conversationId);
            if ($authoritySessionTouched && $selectionSnapshot !== null) {
                // A repository exception is not equivalent to a proven CAS
                // loss. Even a verified authority rollback therefore retains
                // an uncertain result until the persisted state is reconciled.
                return $this->compensatedSelectionFailure(
                    $selectionSnapshot,
                    'checkout_state_persistence_uncertain',
                    'uncertain',
                    ['checkout' => $current?->jsonSerialize()]
                );
            }

            return [
                'ok' => false,
                'code' => 'checkout_state_persistence_uncertain',
                'result_status' => 'uncertain',
                'data' => ['checkout' => $current?->jsonSerialize()],
            ];
        }

        return [
            'ok' => true,
            'code' => 'checkout_state_updated',
            'data' => ['checkout' => $next->jsonSerialize(), 'authoritative_validation' => $authorityData],
        ];
    }

    /**
     * @param array{shipping_methods: list<string>|null, payment_method_id: string|null} $snapshot
     * @param array<string, mixed> $data
     * @return array{ok: false, code: string, result_status: string, data: array<string, mixed>}
     */
    private function compensatedSelectionFailure(
        array $snapshot,
        string $causeCode,
        string $resultStatus,
        array $data
    ): array {
        try {
            $restored = $this->authority->restoreSelectionSnapshot($snapshot);
        } catch (\Throwable) {
            $restored = false;
        }
        $data['authority_reconciliation'] = [
            'selection_snapshot_hash' => StateHash::fromPayload($snapshot)->value(),
            'compensation_attempted' => true,
            'compensation_verified' => $restored,
            'reconciliation_required' => !$restored,
            'cause_code' => $causeCode,
        ];
        if (!$restored) {
            return [
                'ok' => false,
                'code' => 'checkout_session_reconciliation_required',
                'result_status' => 'uncertain',
                'data' => $data,
            ];
        }

        return [
            'ok' => false,
            'code' => $causeCode,
            'result_status' => $resultStatus,
            'data' => $data,
        ];
    }

    /** @return array<string, mixed> */
    private function fulfillmentMutation(CheckoutState $state, string $mode): array
    {
        $modes = $this->authority->fulfillmentModes($state);
        if (($modes['ok'] ?? false) !== true) {
            return ['ok' => false, 'code' => (string) ($modes['code'] ?? 'fulfillment_modes_unavailable'), 'data' => $modes];
        }
        $matches = array_values(array_filter($modes['modes'], static fn (array $candidate): bool => hash_equals((string) $candidate['id'], $mode)));
        if (count($matches) !== 1) {
            return ['ok' => false, 'code' => 'fulfillment_mode_not_currently_eligible', 'data' => ['eligible_modes' => $modes['modes']]];
        }

        return [
            'fulfillment_mode' => $mode,
            'package_selection' => [],
            'payment_method_id' => null,
            'totals' => [],
            'status' => 'active',
            'authority_data' => ['selected_mode' => $matches[0], 'classification' => $modes['classification']],
        ];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function contactMutation(CheckoutState $state, string $kind, array $arguments): array
    {
        $rawContact = $arguments['contact'] ?? [];
        $rawAddress = $arguments['address'] ?? [];
        if (!is_array($rawContact) || !is_array($rawAddress) || ($rawContact === [] && $rawAddress === [])) {
            throw new \InvalidArgumentException('A contact or address object is required.');
        }
        $contact = $rawContact !== [] ? $this->input->contact($rawContact) : [];
        $address = $rawAddress !== [] ? $this->input->address($rawAddress) : [];
        $contacts = $state->contacts;
        $currentContact = is_array($contacts[$kind] ?? null) ? $contacts[$kind] : [];
        $contacts[$kind] = array_merge($currentContact, $contact);
        $mergedAddress = array_merge(
            $kind === 'shipping' ? $state->shippingAddress : $state->billingAddress,
            $address
        );
        if ($address !== []) {
            // Validate partial region edits against the already stored country,
            // rather than treating the delta as an isolated address.
            $mergedAddress = $this->input->address($mergedAddress);
        }
        $changes = [
            'contacts' => $contacts,
            $kind === 'shipping' ? 'shipping_address' : 'billing_address' => $mergedAddress,
            'payment_method_id' => null,
            'totals' => [],
            'status' => 'active',
            'authority_data' => ['changed_contact_fields' => array_keys($contact), 'changed_address_fields' => array_keys($address)],
        ];
        if ($kind === 'shipping') {
            $changes['package_selection'] = [];
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    private function shippingMutation(CheckoutState $state, mixed $rawSelections): array
    {
        if (!is_array($rawSelections) || !array_is_list($rawSelections) || count($rawSelections) > 12) {
            throw new \InvalidArgumentException('Shipping selections must be a bounded list.');
        }
        $selections = [];
        foreach ($rawSelections as $selection) {
            if (!is_array($selection)
                || array_diff(array_keys($selection), ['package_id', 'rate_id']) !== []
                || !is_string($selection['package_id'] ?? null)
                || !is_string($selection['rate_id'] ?? null)
                || strlen($selection['package_id']) > 64
                || strlen($selection['rate_id']) > 191
            ) {
                throw new \InvalidArgumentException('Shipping selection contains invalid package/rate IDs.');
            }
            $selections[] = ['package_id' => $selection['package_id'], 'rate_id' => $selection['rate_id']];
        }
        $selected = $this->authority->selectShippingRates($state, $selections);
        if (($selected['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'code' => (string) ($selected['code'] ?? 'shipping_selection_failed'),
                'data' => $selected,
                'authority_session_touched' => true,
            ];
        }

        return [
            'package_selection' => [
                'selections' => $selections,
                'package_fingerprint' => $selected['package_fingerprint'],
                'selected_at' => $selected['selected_at'] ?? gmdate(DATE_ATOM),
            ],
            'payment_method_id' => null,
            'totals' => [],
            'status' => 'active',
            'authority_data' => $selected,
            'authority_session_touched' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function paymentMutation(CheckoutState $state, string $paymentMethodId): array
    {
        if ($paymentMethodId === '' || strlen($paymentMethodId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $paymentMethodId) === 1) {
            throw new \InvalidArgumentException('Payment method ID is invalid.');
        }
        $selection = $this->authority->selectPaymentMethod($state, $paymentMethodId);
        if (($selection['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'code' => (string) ($selection['code'] ?? 'payment_method_selection_failed'),
                'data' => $selection,
                'authority_session_touched' => true,
            ];
        }

        return [
            'payment_method_id' => $paymentMethodId,
            'totals' => [],
            'status' => 'active',
            'authority_data' => $selection,
            'authority_session_touched' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function invalidationMutation(CheckoutState $state, string $reason, string $currentCartHash): array
    {
        if (!in_array($reason, ['cart_changed', 'fulfillment_changed', 'contact_changed', 'shipping_changed', 'payment_changed'], true)) {
            throw new \InvalidArgumentException('Checkout invalidation reason is invalid.');
        }
        $changes = ['totals' => [], 'status' => 'active', 'authority_data' => ['reason' => $reason]];
        if ($reason === 'cart_changed') {
            if ($currentCartHash === '') {
                return ['ok' => false, 'code' => 'checkout_cart_empty_or_unavailable', 'data' => []];
            }
            $changes['cart_hash'] = $currentCartHash;
            $changes['fulfillment_mode'] = null;
            $changes['package_selection'] = [];
            $changes['payment_method_id'] = null;
        } elseif (in_array($reason, ['fulfillment_changed', 'contact_changed'], true)) {
            $changes['package_selection'] = [];
            $changes['payment_method_id'] = null;
        } elseif ($reason === 'shipping_changed') {
            $changes['payment_method_id'] = null;
        }

        return $changes;
    }

    /** @param array<string, mixed> $stored */
    private function replay(
        ToolCall $call,
        ToolContext $context,
        string $recordStatus,
        ?string $code,
        array $stored,
        bool $recordRetrySafe
    ): ToolResult
    {
        $data = is_array($stored['data'] ?? null) ? $stored['data'] : $stored;
        $data['idempotent_replay'] = true;
        $status = is_string($stored['result_status'] ?? null) ? $stored['result_status'] : ($recordStatus === 'succeeded' ? 'succeeded' : 'failed');
        if (!in_array($status, ['succeeded', 'failed', 'partial', 'blocked', 'stale', 'uncertain'], true)) {
            $status = 'failed';
        }

        return new ToolResult(
            $call->callId,
            $call->name,
            $status,
            $code ?? ($status === 'succeeded' ? 'checkout_state_updated' : 'checkout_mutation_failed'),
            $data,
            $status === 'succeeded' ? ['checkout:' . $context->conversationId] : [],
            true,
            $recordRetrySafe,
            $context->correlationId
        );
    }

    private function scope(ToolContext $context): ActorScope
    {
        return ActorScope::fromActor($this->actors->map($context));
    }

    private function woocommerceAuthorityLockKey(ToolContext $context): string
    {
        return 'woocommerce-commerce-authority:'
            . hash('sha256', $context->actorType . ':' . $context->actorId);
    }

    /** @param callable(): ToolResult $read */
    private function authorityLockedRead(ToolCall $call, ToolContext $context, callable $read): ToolResult
    {
        try {
            $lock = $this->locks->acquire(
                $this->woocommerceAuthorityLockKey($context),
                new CorrelationId($context->correlationId),
                30
            );
        } catch (\Throwable) {
            $lock = null;
        }
        if ($lock === null) {
            return new ToolResult(
                $call->callId,
                $call->name,
                'blocked',
                'checkout_authority_lock_unavailable',
                [],
                [],
                true,
                true,
                $context->correlationId
            );
        }
        try {
            return $read();
        } catch (\Throwable) {
            return ToolResult::failed($call, 'checkout_authority_read_failed', $context->correlationId, false);
        } finally {
            try {
                $this->locks->release($lock);
            } catch (\Throwable) {
                // The bounded lease expires without altering the read result.
            }
        }
    }

    private function publicValidationCode(\InvalidArgumentException $error): string
    {
        $message = strtolower($error->getMessage());
        if (str_contains($message, 'email')) {
            return 'checkout_email_invalid';
        }
        if (str_contains($message, 'country')) {
            return 'checkout_country_invalid';
        }
        if (str_contains($message, 'state')) {
            return 'checkout_state_region_invalid';
        }
        if (str_contains($message, 'shipping')) {
            return 'checkout_shipping_selection_invalid';
        }
        if (str_contains($message, 'payment')) {
            return 'checkout_payment_method_invalid';
        }

        return 'checkout_input_invalid';
    }
}
