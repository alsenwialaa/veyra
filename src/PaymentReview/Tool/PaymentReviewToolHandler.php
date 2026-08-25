<?php

declare(strict_types=1);

namespace Veyra\PaymentReview\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Confirmation\Domain\IdempotencyDecisionStatus;
use Veyra\PaymentReview\Application\PaymentReviewOutcome;
use Veyra\PaymentReview\Application\PaymentReviewService;
use Veyra\Shared\Domain\CorrelationId;

/**
 * Customer/model operations stop at a draft. Submission and resubmission are
 * sensitive, hidden from the model, and fail closed until a full confirmation,
 * lock, audit and reconciliation path is integrated.
 */
final class PaymentReviewToolHandler implements ToolHandler
{
    public function __construct(
        private readonly PaymentReviewService $reviews,
        private readonly IdempotencyService $idempotency,
        private readonly FoundationActorMapper $actors,
        private readonly LockManager $locks
    ) {
    }

    public function definitions(): array
    {
        $actors = ['customer'];
        $features = ['payment_offline_review'];
        $idempotency = ['type' => 'string', 'minLength' => 8, 'maxLength' => 191];
        $reviewId = ['type' => 'string', 'minLength' => 36, 'maxLength' => 36];
        $proposal = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['field', 'value', 'confidence', 'source_attachment_id'],
            'properties' => [
                'field' => ['type' => 'string', 'enum' => ['amount', 'reference', 'payer_name', 'transferred_at']],
                'value' => ['type' => 'string', 'maxLength' => 191],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'source_attachment_id' => $reviewId,
                'source_path' => ['type' => 'string', 'maxLength' => 191],
            ],
        ];

        return [
            $this->definition('payment_review.create_or_update_draft', 'Create or update only one exact actor-owned payment evidence draft. Customer fields must come from explicit customer input; AI/OCR values belong only in unverified proposed_extractions. This never submits evidence, approves payment or changes an order.', 'write', [
                'order_id' => ['type' => 'integer', 'minimum' => 1],
                'review_id' => $reviewId,
                'expected_version' => ['type' => 'integer', 'minimum' => 0],
                'amount' => ['type' => 'string', 'maxLength' => 32, 'pattern' => '^\\d+(?:\\.\\d{1,8})?$'],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'reference' => ['type' => 'string', 'maxLength' => 191],
                'payer_name' => ['type' => 'string', 'maxLength' => 191],
                'transferred_at' => [
                    'type' => 'string',
                    'maxLength' => 40,
                    'pattern' => '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$',
                ],
                'note' => ['type' => 'string', 'maxLength' => 2000],
                'attachment_ids' => ['type' => 'array', 'maxItems' => 5, 'items' => $reviewId],
                'proposed_extractions' => ['type' => 'array', 'maxItems' => 20, 'items' => $proposal],
                'idempotency_key' => $idempotency,
            ], ['order_id', 'expected_version', 'idempotency_key'], $actors, $features, true),
            $this->definition('payment_review.get_status', 'Read one exact actor-owned review, preserving review, decision, WooCommerce transition and current order state as separate facts.', 'read', [
                'review_id' => $reviewId,
            ], ['review_id'], $actors, $features, true),
            $this->definition('payment_review.submit_confirmed_evidence', 'Submit one exact review only through a complete confirmation and reconciliation gate.', 'sensitive_write', [
                'review_id' => $reviewId,
                'expected_version' => ['type' => 'integer', 'minimum' => 1],
                'state_hash' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                'confirmation_token' => ['type' => 'string', 'minLength' => 32, 'maxLength' => 192],
                'idempotency_key' => $idempotency,
            ], ['review_id', 'expected_version', 'state_hash', 'confirmation_token', 'idempotency_key'], $actors, $features, false),
            $this->definition('payment_review.resubmit_evidence', 'Resubmit eligible evidence only through a new exact confirmation; prior submissions and decisions remain immutable.', 'sensitive_write', [
                'review_id' => $reviewId,
                'expected_version' => ['type' => 'integer', 'minimum' => 1],
                'state_hash' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                'confirmation_token' => ['type' => 'string', 'minLength' => 32, 'maxLength' => 192],
                'idempotency_key' => $idempotency,
            ], ['review_id', 'expected_version', 'state_hash', 'confirmation_token', 'idempotency_key'], $actors, $features, false),
        ];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        if ($context->actorType !== 'customer' || $context->userId === null) {
            return ToolResult::denied($call, 'payment_review_authentication_required', $context->correlationId);
        }
        if (in_array($call->name, ['payment_review.submit_confirmed_evidence', 'payment_review.resubmit_evidence'], true)) {
            return ToolResult::denied($call, 'payment_review_sensitive_action_gate_not_integrated', $context->correlationId);
        }
        if ($call->name === 'payment_review.get_status') {
            return $this->toToolResult($call, $context, $this->reviews->getStatus($context, (string) $call->arguments['review_id']));
        }
        if ($call->name !== 'payment_review.create_or_update_draft') {
            return ToolResult::failed($call, 'tool_operation_unknown', $context->correlationId, false);
        }

        $actor = $this->actors->map($context);
        $scope = isset($call->arguments['review_id'])
            ? 'review:' . (string) $call->arguments['review_id']
            : 'order:' . (string) $call->arguments['order_id'];
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
            return ToolResult::failed($call, 'payment_review_idempotency_unavailable', $context->correlationId, false);
        }

        if ($decision->status === IdempotencyDecisionStatus::Replay) {
            $record = $decision->record;
            if ($record->status === 'succeeded' && is_string($record->result['review_id'] ?? null)) {
                $replayed = $this->reviews->getStatus($context, $record->result['review_id']);
                if ($replayed->status === 'succeeded') {
                    $data = $replayed->data;
                    $data['idempotent_replay'] = true;
                    return new ToolResult($call->callId, $call->name, 'succeeded', 'payment_review_idempotent_replay', $data, [], true, false, $context->correlationId);
                }
                return new ToolResult($call->callId, $call->name, 'uncertain', 'payment_review_replay_reconciliation_required', [], [], true, false, $context->correlationId);
            }

            $storedStatus = is_string($record->result['operation_status'] ?? null) ? $record->result['operation_status'] : 'failed';
            if (!in_array($storedStatus, ['failed', 'blocked', 'stale', 'uncertain'], true)) {
                $storedStatus = 'failed';
            }
            return new ToolResult(
                $call->callId,
                $call->name,
                $storedStatus,
                is_string($record->resultCode) ? $record->resultCode : 'payment_review_prior_attempt_failed',
                is_array($record->result) ? $record->result : [],
                [],
                true,
                $record->retrySafe,
                $context->correlationId
            );
        }
        if ($decision->status !== IdempotencyDecisionStatus::Claimed) {
            return new ToolResult(
                $call->callId,
                $call->name,
                $decision->status === IdempotencyDecisionStatus::Conflict ? 'blocked' : 'uncertain',
                $decision->code,
                [],
                [],
                true,
                false,
                $context->correlationId
            );
        }

        try {
            $lock = $this->locks->acquire(
                'payment-review-draft:' . hash('sha256', $context->actorType . ':' . $context->actorId) . ':' . (string) $call->arguments['order_id'],
                new CorrelationId($context->correlationId),
                30
            );
        } catch (\Throwable) {
            $lock = null;
        }
        if ($lock === null) {
            $stored = ['operation_status' => 'blocked'];
            try {
                $transitioned = $this->idempotency->fail(
                    $decision->record,
                    'payment_review_write_lock_unavailable',
                    $stored,
                    true
                );
            } catch (\Throwable) {
                $transitioned = false;
            }
            if (!$transitioned) {
                return new ToolResult($call->callId, $call->name, 'uncertain', 'payment_review_idempotency_transition_uncertain', $stored, [], true, false, $context->correlationId);
            }
            return new ToolResult($call->callId, $call->name, 'blocked', 'payment_review_write_lock_unavailable', $stored, [], true, true, $context->correlationId);
        }

        try {
            $outcome = $this->reviews->createOrUpdateDraft($context, $call->arguments);
        } catch (\Throwable) {
            try {
                $this->idempotency->markUncertain($decision->record, 'payment_review_write_uncertain', [
                    'operation_status' => 'uncertain',
                ]);
            } catch (\Throwable) {
                // The customer result remains uncertain even if the evidence
                // record used for reconciliation is temporarily unavailable.
            }
            return new ToolResult($call->callId, $call->name, 'uncertain', 'payment_review_write_uncertain', [], [], true, false, $context->correlationId);
        } finally {
            try {
                $this->locks->release($lock);
            } catch (\Throwable) {
                // The bounded lease expires. Do not rewrite a known DB/CAS result.
            }
        }
        $stored = array_merge($outcome->data, [
            'operation_status' => $outcome->status,
            'review_id' => $outcome->review?->id,
            'review_version' => $outcome->review?->version,
        ]);
        if ($outcome->status === 'succeeded') {
            try {
                $completed = $this->idempotency->complete($decision->record, $outcome->code, $stored, false);
            } catch (\Throwable) {
                $completed = false;
            }
            if (!$completed) {
                return new ToolResult($call->callId, $call->name, 'uncertain', 'payment_review_idempotency_completion_uncertain', $stored, [], true, false, $context->correlationId);
            }
        } elseif ($outcome->status === 'uncertain') {
            try {
                $transitioned = $this->idempotency->markUncertain($decision->record, $outcome->code, $stored);
            } catch (\Throwable) {
                $transitioned = false;
            }
            if (!$transitioned) {
                return new ToolResult($call->callId, $call->name, 'uncertain', 'payment_review_idempotency_transition_uncertain', $stored, [], true, false, $context->correlationId);
            }
        } else {
            try {
                $transitioned = $this->idempotency->fail(
                    $decision->record,
                    $outcome->code,
                    $stored,
                    $outcome->retrySafe
                );
            } catch (\Throwable) {
                $transitioned = false;
            }
            if (!$transitioned) {
                return new ToolResult($call->callId, $call->name, 'uncertain', 'payment_review_idempotency_transition_uncertain', $stored, [], true, false, $context->correlationId);
            }
        }

        return $this->toToolResult($call, $context, $outcome);
    }

    private function toToolResult(ToolCall $call, ToolContext $context, PaymentReviewOutcome $outcome): ToolResult
    {
        $data = $outcome->data;
        if ($outcome->review !== null && !isset($data['review'])) {
            $data['review'] = $outcome->review->customerView();
        }
        $changed = $outcome->status === 'succeeded' && str_contains($outcome->code, 'draft_') && $outcome->review !== null
            ? ['payment_review:' . $outcome->review->id]
            : [];
        return new ToolResult(
            $call->callId,
            $call->name,
            $outcome->status,
            $outcome->code,
            $data,
            $changed,
            true,
            $outcome->retrySafe,
            $context->correlationId
        );
    }

    /** @param array<string, array<string, mixed>> $properties @param list<string> $required @param list<string> $actors @param list<string> $features */
    private function definition(
        string $name,
        string $description,
        string $classification,
        array $properties,
        array $required,
        array $actors,
        array $features,
        bool $modelVisible
    ): ToolDefinition {
        return new ToolDefinition($name, '1.0.0', $description, $classification, [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ], $actors, [], $features, $modelVisible);
    }
}
