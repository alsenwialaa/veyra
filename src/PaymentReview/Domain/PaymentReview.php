<?php

declare(strict_types=1);

namespace Veyra\PaymentReview\Domain;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Shared\Domain\Uuid;

final class PaymentReview
{
    private const SUBMISSION_STATUSES = [
        'draft', 'submitted', 'assigned', 'under_review', 'waiting_for_customer',
        'approved', 'rejected', 'superseded', 'expired', 'closed',
    ];
    private const DECISION_STATUSES = ['pending', 'approved', 'rejected'];
    private const TRANSITION_STATUSES = ['not_requested', 'transition_pending', 'transition_succeeded', 'transition_failed'];

    /**
     * @param array<string, mixed>      $evidence
     * @param array<string, mixed>|null $decision
     * @param array<string, mixed>|null $transition
     */
    public function __construct(
        public readonly string $id,
        public readonly ActorScope $actor,
        public readonly string $conversationId,
        public readonly int $orderId,
        public readonly ?string $caseId,
        public readonly ?string $primaryAttachmentId,
        public readonly string $submissionStatus,
        public readonly ?string $decisionStatus,
        public readonly ?string $transitionStatus,
        public readonly array $evidence,
        public readonly ?array $decision,
        public readonly ?array $transition,
        public readonly int $version,
        public readonly UtcInstant $createdAt,
        public readonly UtcInstant $updatedAt
    ) {
        if (!Uuid::isValid($id)
            || !Uuid::isValid($conversationId)
            || ($caseId !== null && !Uuid::isValid($caseId))
            || ($primaryAttachmentId !== null && !Uuid::isValid($primaryAttachmentId))
            || $orderId < 1
            || !in_array($submissionStatus, self::SUBMISSION_STATUSES, true)
            || ($decisionStatus !== null && !in_array($decisionStatus, self::DECISION_STATUSES, true))
            || ($transitionStatus !== null && !in_array($transitionStatus, self::TRANSITION_STATUSES, true))
            || $version < 1
        ) {
            throw new \InvalidArgumentException('Payment review state is invalid.');
        }
        if ($submissionStatus === 'draft' && ($decisionStatus !== null || $transitionStatus !== null)) {
            throw new \InvalidArgumentException('Draft review cannot contain a decision or transition.');
        }
    }

    /** @param array<string, mixed> $evidence */
    public static function draft(
        ActorScope $actor,
        string $conversationId,
        int $orderId,
        array $evidence,
        UtcInstant $now
    ): self {
        return new self(
            Uuid::v4(),
            $actor,
            $conversationId,
            $orderId,
            null,
            self::primaryAttachment($evidence),
            'draft',
            null,
            null,
            $evidence,
            null,
            null,
            1,
            $now,
            $now
        );
    }

    /** @param array<string, mixed> $evidence */
    public function reviseDraft(array $evidence, int $expectedVersion, UtcInstant $now): self
    {
        if ($this->submissionStatus !== 'draft' || $this->version !== $expectedVersion) {
            throw new \DomainException('payment_review_version_conflict');
        }

        return new self(
            $this->id,
            $this->actor,
            $this->conversationId,
            $this->orderId,
            $this->caseId,
            self::primaryAttachment($evidence),
            'draft',
            null,
            null,
            $evidence,
            null,
            null,
            $this->version + 1,
            $this->createdAt,
            $now
        );
    }

    /** @return array<string, mixed> */
    public function persistenceValues(): array
    {
        return [
            'public_id' => $this->id,
            'actor_type' => $this->actor->actorType,
            'actor_id' => $this->actor->actorId,
            'actor_key_hash' => $this->actor->hash(),
            'conversation_id' => $this->conversationId,
            'order_id' => $this->orderId,
            'case_id' => $this->caseId,
            'evidence_attachment_id' => $this->primaryAttachmentId,
            'submission_status' => $this->submissionStatus,
            'decision_status' => $this->decisionStatus,
            'transition_status' => $this->transitionStatus,
            'evidence_json' => CanonicalJson::encode($this->evidence),
            'decision_json' => $this->decision !== null ? CanonicalJson::encode($this->decision) : null,
            'transition_json' => $this->transition !== null ? CanonicalJson::encode($this->transition) : null,
            'version' => $this->version,
            'created_at' => $this->createdAt->toDatabase(),
            'updated_at' => $this->updatedAt->toDatabase(),
        ];
    }

    /** @param array<string, mixed>|null $currentOrder @return array<string, mixed> */
    public function customerView(?array $currentOrder = null): array
    {
        $decisionReason = is_array($this->decision)
            && is_string($this->decision['customer_visible_reason'] ?? null)
            ? $this->decision['customer_visible_reason']
            : null;
        $requestedInformation = is_array($this->decision)
            && is_array($this->decision['requested_information'] ?? null)
            ? array_values(array_filter($this->decision['requested_information'], 'is_string'))
            : [];

        return [
            'review_id' => $this->id,
            'review_number' => 'VPR-' . strtoupper(substr(str_replace('-', '', $this->id), 0, 10)),
            'order_id' => $this->orderId,
            'submission_status' => $this->submissionStatus,
            'decision_status' => $this->decisionStatus,
            'transition_status' => $this->transitionStatus,
            'customer_visible_decision_reason' => $decisionReason,
            'requested_information' => $requestedInformation,
            'evidence' => $this->safeEvidence(),
            'current_order' => $currentOrder,
            'version' => $this->version,
            'created_at' => $this->createdAt->toIso8601(),
            'updated_at' => $this->updatedAt->toIso8601(),
        ];
    }

    /** @return array<string, mixed> */
    private function safeEvidence(): array
    {
        return [
            'customer_fields' => is_array($this->evidence['customer_fields'] ?? null) ? $this->evidence['customer_fields'] : [],
            'attachment_ids' => is_array($this->evidence['attachment_ids'] ?? null)
                ? array_values(array_filter($this->evidence['attachment_ids'], 'is_string'))
                : [],
            'proposed_extractions' => is_array($this->evidence['proposed_extractions'] ?? null)
                ? $this->evidence['proposed_extractions']
                : [],
            'order_snapshot' => is_array($this->evidence['order_snapshot'] ?? null) ? $this->evidence['order_snapshot'] : [],
            'draft_revision_count' => is_array($this->evidence['draft_history'] ?? null)
                ? count($this->evidence['draft_history'])
                : 0,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $evidence = json_decode((string) $row['evidence_json'], true, 64);
        $decision = isset($row['decision_json']) ? json_decode((string) $row['decision_json'], true, 64) : null;
        $transition = isset($row['transition_json']) ? json_decode((string) $row['transition_json'], true, 64) : null;

        return new self(
            (string) $row['public_id'],
            new ActorScope((string) $row['actor_type'], (string) $row['actor_id']),
            (string) $row['conversation_id'],
            (int) $row['order_id'],
            is_string($row['case_id'] ?? null) && $row['case_id'] !== '' ? $row['case_id'] : null,
            is_string($row['evidence_attachment_id'] ?? null) && $row['evidence_attachment_id'] !== '' ? $row['evidence_attachment_id'] : null,
            (string) $row['submission_status'],
            is_string($row['decision_status'] ?? null) && $row['decision_status'] !== '' ? $row['decision_status'] : null,
            is_string($row['transition_status'] ?? null) && $row['transition_status'] !== '' ? $row['transition_status'] : null,
            is_array($evidence) ? $evidence : [],
            is_array($decision) ? $decision : null,
            is_array($transition) ? $transition : null,
            (int) $row['version'],
            UtcInstant::fromDatabase((string) $row['created_at']),
            UtcInstant::fromDatabase((string) $row['updated_at'])
        );
    }

    /** @param array<string, mixed> $evidence */
    private static function primaryAttachment(array $evidence): ?string
    {
        $attachments = is_array($evidence['attachment_ids'] ?? null) ? $evidence['attachment_ids'] : [];
        return is_string($attachments[0] ?? null) ? $attachments[0] : null;
    }
}
