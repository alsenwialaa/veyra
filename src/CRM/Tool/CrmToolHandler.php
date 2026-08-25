<?php

declare(strict_types=1);

namespace Veyra\CRM\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Domain\IdempotencyDecisionStatus;
use Veyra\CRM\Infrastructure\CaseWriteOutcomeUncertain;
use Veyra\CRM\Infrastructure\WpdbCaseRepository;
use Veyra\Shared\Domain\CorrelationId;

final class CrmToolHandler implements ToolHandler
{
    public function __construct(
        private readonly WpdbCaseRepository $cases,
        private readonly IdempotencyService $idempotency,
        private readonly FoundationActorMapper $actors
    ) {
    }

    public function definitions(): array
    {
        $readActors = ['guest', 'customer'];
        $feature = ['service_crm'];
        $key = ['idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 191]];
        return [
            $this->definition('crm.get_case_types', 'Read the merchant-approved bounded CRM case types.', 'read', [], [], $readActors, $feature, true),
            $this->definition('crm.find_open_case', 'Find actor-owned open cases matching exact supplied scope; never pick one from multiple matches.', 'read', [
                'case_type' => ['type' => 'string', 'maxLength' => 64],
                'order_id' => ['type' => 'integer', 'minimum' => 1],
            ], [], $readActors, $feature, true),
            $this->definition('crm.create_draft', 'Create an actor-owned draft CRM case without submitting it.', 'write', array_merge([
                'case_type' => ['type' => 'string', 'maxLength' => 64],
                'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
                'order_id' => ['type' => 'integer', 'minimum' => 1],
            ], $key), ['case_type', 'subject', 'description', 'idempotency_key'], $readActors, $feature, true),
            $this->definition('crm.submit_confirmed_case', 'Submit one exact draft only through the separate confirmation gate.', 'sensitive_write', [
                'case_id' => ['type' => 'string', 'maxLength' => 64],
                'confirmation_token' => ['type' => 'string', 'maxLength' => 192],
                'state_hash' => ['type' => 'string', 'maxLength' => 64],
                'idempotency_key' => $key['idempotency_key'],
            ], ['case_id', 'confirmation_token', 'state_hash', 'idempotency_key'], $readActors, $feature, false),
            $this->definition('crm.list_customer_cases', 'List only current actor-owned CRM cases.', 'read', [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
            ], [], $readActors, $feature, true),
            $this->definition('crm.get_customer_case', 'Read one exact actor-owned CRM case.', 'read', [
                'case_id' => ['type' => 'string', 'maxLength' => 64],
            ], ['case_id'], $readActors, $feature, true),
            $this->definition('crm.update_customer_case', 'Update bounded fields on one exact actor-owned draft case idempotently.', 'write', array_merge([
                'case_id' => ['type' => 'string', 'maxLength' => 64],
                'expected_version' => ['type' => 'integer', 'minimum' => 1],
                'subject' => ['type' => 'string', 'maxLength' => 160],
                'description' => ['type' => 'string', 'maxLength' => 4000],
            ], $key), ['case_id', 'expected_version', 'idempotency_key'], $readActors, $feature, true),
            $this->definition('crm.add_customer_message', 'Add one bounded customer-visible message to an actor-owned draft case.', 'write', array_merge([
                'case_id' => ['type' => 'string', 'maxLength' => 64],
                'expected_version' => ['type' => 'integer', 'minimum' => 1],
                'message' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
            ], $key), ['case_id', 'expected_version', 'message', 'idempotency_key'], $readActors, $feature, true),
            $this->definition('crm.add_evidence', 'Attach one already-scanned actor-owned protected attachment to an exact draft case.', 'write', array_merge([
                'case_id' => ['type' => 'string', 'maxLength' => 64],
                'expected_version' => ['type' => 'integer', 'minimum' => 1],
                'attachment_id' => ['type' => 'string', 'maxLength' => 64],
            ], $key), ['case_id', 'expected_version', 'attachment_id', 'idempotency_key'], $readActors, $feature, true),
        ];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        return match ($call->name) {
            'crm.get_case_types' => ToolResult::success($call, ['case_types' => $this->caseTypes()], $context->correlationId),
            'crm.find_open_case' => $this->findOpen($call, $context),
            'crm.list_customer_cases' => ToolResult::success($call, ['cases' => $this->cases->list($context, (int) ($call->arguments['limit'] ?? 20))], $context->correlationId),
            'crm.get_customer_case' => $this->get($call, $context),
            'crm.create_draft', 'crm.update_customer_case', 'crm.add_customer_message', 'crm.add_evidence' => $this->write($call, $context),
            'crm.submit_confirmed_case' => ToolResult::denied($call, 'sensitive_action_requires_rest_confirmation_gate', $context->correlationId),
            default => ToolResult::failed($call, 'tool_operation_unknown', $context->correlationId, false),
        };
    }

    private function findOpen(ToolCall $call, ToolContext $context): ToolResult
    {
        $cases = $this->cases->list(
            $context,
            20,
            is_string($call->arguments['case_type'] ?? null) ? $call->arguments['case_type'] : null,
            isset($call->arguments['order_id']) ? (int) $call->arguments['order_id'] : null,
            true
        );
        return ToolResult::success($call, [
            'matches' => $cases,
            'count' => count($cases),
            'resolved' => count($cases) === 1,
            'selection_required' => count($cases) > 1,
        ], $context->correlationId);
    }

    private function get(ToolCall $call, ToolContext $context): ToolResult
    {
        $case = $this->cases->get($context, (string) $call->arguments['case_id']);
        return $case === null
            ? ToolResult::failed($call, 'case_not_owned_or_unavailable', $context->correlationId, false)
            : ToolResult::success($call, ['case' => $case], $context->correlationId);
    }

    private function write(ToolCall $call, ToolContext $context): ToolResult
    {
        if ($call->name === 'crm.add_customer_message'
            && trim((string) ($call->arguments['message'] ?? '')) === ''
        ) {
            return ToolResult::failed($call, 'case_message_invalid', $context->correlationId, false);
        }
        $actor = $this->actors->map($context);
        $scope = $call->name === 'crm.create_draft'
            ? 'conversation:' . $context->conversationId
            : 'case:' . (string) ($call->arguments['case_id'] ?? 'unknown');
        try {
            $decision = $this->idempotency->begin(
                $actor,
                $call->name,
                (string) $call->arguments['idempotency_key'],
                $call->arguments,
                $scope,
                new CorrelationId($context->correlationId)
            );
        } catch (\Throwable) {
            return ToolResult::failed($call, 'idempotency_unavailable', $context->correlationId, false);
        }
        if ($decision->status === IdempotencyDecisionStatus::Replay && is_array($decision->record->result)) {
            if ($decision->record->status === 'succeeded') {
                return ToolResult::success($call, $decision->record->result, $context->correlationId);
            }
            return ToolResult::failed(
                $call,
                $decision->record->resultCode ?? 'case_previous_attempt_failed',
                $context->correlationId,
                $decision->record->retrySafe
            );
        }
        if ($decision->status === IdempotencyDecisionStatus::ReconcileRequired
            && $decision->record->status === 'uncertain'
            && is_array($decision->record->result)
        ) {
            return new ToolResult(
                $call->callId,
                $call->name,
                'uncertain',
                $decision->record->resultCode ?? 'case_write_reconciliation_required',
                $decision->record->result,
                [],
                true,
                false,
                $context->correlationId
            );
        }
        if ($decision->status !== IdempotencyDecisionStatus::Claimed) {
            return new ToolResult($call->callId, $call->name, $decision->status === IdempotencyDecisionStatus::Conflict ? 'blocked' : 'uncertain', $decision->code, [], [], true, false, $context->correlationId);
        }

        try {
            $result = match ($call->name) {
                'crm.create_draft' => $this->create($call, $context),
                'crm.update_customer_case' => $this->update($call, $context, 'update'),
                'crm.add_customer_message' => $this->update($call, $context, 'message'),
                'crm.add_evidence' => $this->update($call, $context, 'evidence'),
                default => null,
            };
        } catch (CaseWriteOutcomeUncertain $uncertain) {
            return $this->reconcileKnownWrite($call, $context, $decision->record, $uncertain);
        }
        if (!is_array($result)) {
            try {
                $failed = $this->idempotency->fail($decision->record, 'case_write_conflict', [], true);
            } catch (\Throwable) {
                $failed = false;
            }
            if (!$failed) {
                $known = ['reconciliation_required' => true];
                if (is_string($call->arguments['case_id'] ?? null)) {
                    $known['case_id'] = $call->arguments['case_id'];
                }
                try {
                    $this->idempotency->markUncertain(
                        $decision->record,
                        'case_idempotency_failure_transition_uncertain',
                        $known
                    );
                } catch (\Throwable) {
                    // Public truth remains uncertain even when the recovery
                    // marker cannot be durably written.
                }
                return new ToolResult(
                    $call->callId,
                    $call->name,
                    'uncertain',
                    'case_idempotency_failure_transition_uncertain',
                    $known,
                    [],
                    true,
                    false,
                    $context->correlationId
                );
            }
            return new ToolResult($call->callId, $call->name, 'stale', 'case_write_conflict', [], [], true, true, $context->correlationId);
        }
        try {
            $completed = $this->idempotency->complete($decision->record, 'case_write_completed', $result, true);
        } catch (\Throwable) {
            $completed = false;
        }
        if (!$completed) {
            return new ToolResult($call->callId, $call->name, 'uncertain', 'case_idempotency_completion_uncertain', $result, [], true, false, $context->correlationId);
        }
        return ToolResult::success($call, $result, $context->correlationId, ['case:' . (string) ($result['case']['case_id'] ?? '')]);
    }

    private function reconcileKnownWrite(
        ToolCall $call,
        ToolContext $context,
        \Veyra\Confirmation\Domain\IdempotencyRecord $idempotencyRecord,
        CaseWriteOutcomeUncertain $uncertain
    ): ToolResult {
        try {
            $case = $this->cases->get($context, $uncertain->caseId);
        } catch (\Throwable) {
            $case = null;
        }

        if (is_array($case)
            && hash_equals($uncertain->caseId, (string) ($case['case_id'] ?? ''))
            && (int) ($case['version'] ?? 0) >= $uncertain->knownVersion
        ) {
            $result = [
                'case' => $case,
                'reconciled_after_write' => true,
            ];
            try {
                $completed = $this->idempotency->complete(
                    $idempotencyRecord,
                    'case_write_reconciled',
                    $result,
                    false
                );
            } catch (\Throwable) {
                $completed = false;
            }
            if (!$completed) {
                return new ToolResult(
                    $call->callId,
                    $call->name,
                    'uncertain',
                    'case_idempotency_completion_uncertain',
                    $result,
                    [],
                    true,
                    false,
                    $context->correlationId
                );
            }

            return ToolResult::success(
                $call,
                $result,
                $context->correlationId,
                ['case:' . $uncertain->caseId]
            );
        }

        $known = [
            'case_id' => $uncertain->caseId,
            'known_version' => $uncertain->knownVersion,
            'reconciliation_required' => true,
        ];
        try {
            $this->idempotency->markUncertain(
                $idempotencyRecord,
                'case_write_reconciliation_required',
                $known
            );
        } catch (\Throwable) {
            // The public result remains uncertain and non-retryable even if the
            // reconciliation marker itself also needs operational recovery.
        }

        return new ToolResult(
            $call->callId,
            $call->name,
            'uncertain',
            'case_write_reconciliation_required',
            $known,
            [],
            true,
            false,
            $context->correlationId
        );
    }

    /** @return array<string, mixed>|null */
    private function create(ToolCall $call, ToolContext $context): ?array
    {
        if (!isset($this->caseTypes()[(string) $call->arguments['case_type']])) {
            return null;
        }
        $subject = trim((string) $call->arguments['subject']);
        $description = trim((string) $call->arguments['description']);
        if ($subject === '' || $description === '') {
            return null;
        }
        $orderId = isset($call->arguments['order_id']) ? (int) $call->arguments['order_id'] : null;
        if ($orderId !== null && !$this->ownsOrder($context, $orderId)) {
            return null;
        }
        $case = $this->cases->createDraft($context, (string) $call->arguments['case_type'], $orderId, [
            'subject' => $subject,
            'description' => $description,
            'messages' => [],
            'evidence_attachment_ids' => [],
            'source_message_id' => null,
        ]);
        return $case === null ? null : ['case' => $case];
    }

    /** @return array<string, mixed>|null */
    private function update(ToolCall $call, ToolContext $context, string $mode): ?array
    {
        $caseId = (string) $call->arguments['case_id'];
        $case = $this->cases->get($context, $caseId);
        if ($case === null || $case['submission_status'] !== 'draft' || (int) $case['version'] !== (int) $call->arguments['expected_version']) {
            return null;
        }
        $request = is_array($case['request'] ?? null) ? $case['request'] : [];
        if ($mode === 'update') {
            if (isset($call->arguments['subject'])) {
                $subject = trim((string) $call->arguments['subject']);
                if ($subject === '') {
                    return null;
                }
                $request['subject'] = $subject;
            }
            if (isset($call->arguments['description'])) {
                $description = trim((string) $call->arguments['description']);
                if ($description === '') {
                    return null;
                }
                $request['description'] = $description;
            }
        } elseif ($mode === 'message') {
            $messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];
            if (count($messages) >= 100) {
                return null;
            }
            $message = trim((string) $call->arguments['message']);
            if ($message === '') {
                return null;
            }
            $messages[] = ['sender' => 'customer', 'text' => $message, 'created_at' => gmdate(DATE_ATOM)];
            $request['messages'] = $messages;
        } else {
            $attachmentId = (string) $call->arguments['attachment_id'];
            if (!$this->cases->attachmentUsable($context, $attachmentId)) {
                return null;
            }
            $evidence = is_array($request['evidence_attachment_ids'] ?? null) ? array_values(array_filter($request['evidence_attachment_ids'], 'is_string')) : [];
            if (!in_array($attachmentId, $evidence, true)) {
                $evidence[] = $attachmentId;
            }
            if (count($evidence) > 10) {
                return null;
            }
            $request['evidence_attachment_ids'] = $evidence;
        }
        $updated = $this->cases->updateDraft($context, $caseId, (int) $call->arguments['expected_version'], $request);
        return $updated === null ? null : ['case' => $updated];
    }

    /** @return array<string, array<string, string>> */
    private function caseTypes(): array
    {
        $defaults = [
            'order_help' => ['label' => 'Order help', 'confirmation_summary' => 'Submit an order-help case'],
            'product_help' => ['label' => 'Product help', 'confirmation_summary' => 'Submit a product-help case'],
            'payment_help' => ['label' => 'Payment help', 'confirmation_summary' => 'Submit a payment-help case'],
            'other' => ['label' => 'Other support', 'confirmation_summary' => 'Submit a support case'],
        ];
        $filtered = function_exists('apply_filters') ? apply_filters('veyra_case_types', $defaults) : $defaults;
        if (!is_array($filtered)) {
            return $defaults;
        }
        $result = $defaults;
        foreach ($defaults as $key => $fallback) {
            $candidate = $filtered[$key] ?? null;
            if (!is_array($candidate)) {
                continue;
            }
            $label = $candidate['label'] ?? null;
            $summary = $candidate['confirmation_summary'] ?? null;
            if (!is_string($label) || !is_string($summary) || trim($label) === '' || trim($summary) === '') {
                continue;
            }
            $cleanLabel = function_exists('sanitize_text_field') ? sanitize_text_field($label) : trim(strip_tags($label));
            $cleanSummary = function_exists('sanitize_text_field') ? sanitize_text_field($summary) : trim(strip_tags($summary));
            if ($cleanLabel !== '' && $cleanSummary !== '' && strlen($cleanLabel) <= 120 && strlen($cleanSummary) <= 200) {
                $result[$key] = ['label' => $cleanLabel, 'confirmation_summary' => $cleanSummary];
            }
        }
        return $result;
    }

    private function ownsOrder(ToolContext $context, int $orderId): bool
    {
        if ($context->actorType !== 'customer' || $context->userId === null || !function_exists('wc_get_order')) {
            return false;
        }
        $order = wc_get_order($orderId);
        return $order instanceof \WC_Order
            && (int) $order->get_customer_id() === $context->userId
            && (!method_exists($order, 'get_status')
                || !in_array($order->get_status(), ['checkout-draft', 'auto-draft', 'trash'], true));
    }

    /** @param array<string, array<string, mixed>> $properties @param list<string> $required @param list<string> $actors @param list<string> $features */
    private function definition(string $name, string $description, string $classification, array $properties, array $required, array $actors, array $features, bool $visible): ToolDefinition
    {
        return new ToolDefinition($name, '1.0.0', $description, $classification, [
            'type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties,
        ], $actors, [], $features, $visible);
    }
}
