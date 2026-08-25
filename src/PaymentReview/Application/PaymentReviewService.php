<?php

declare(strict_types=1);

namespace Veyra\PaymentReview\Application;

use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Media\Application\AttachmentRepository;
use Veyra\Media\Domain\Attachment;
use Veyra\PaymentReview\Domain\PaymentReview;
use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\Clock;

final class PaymentReviewService
{
    public function __construct(
        private readonly PaymentReviewRepository $reviews,
        private readonly AttachmentRepository $attachments,
        private readonly PaymentReviewAuthority $authority,
        private readonly FoundationActorMapper $actors,
        private readonly Clock $clock
    ) {
    }

    /** @param array<string, mixed> $input */
    public function createOrUpdateDraft(ToolContext $context, array $input): PaymentReviewOutcome
    {
        if ($context->actorType !== 'customer' || $context->userId === null) {
            return new PaymentReviewOutcome('blocked', 'payment_review_authentication_required', null);
        }
        $orderId = (int) ($input['order_id'] ?? 0);
        $order = $this->authority->resolveOwnedEligibleOrder($context, $orderId);
        if (!$order->snapshot instanceof \Veyra\PaymentReview\Domain\PaymentOrderSnapshot) {
            return new PaymentReviewOutcome('blocked', $order->code, null);
        }
        $scope = ActorScope::fromActor($this->actors->map($context));
        $reviewId = is_string($input['review_id'] ?? null) && $input['review_id'] !== '' ? $input['review_id'] : null;
        $expectedVersion = (int) ($input['expected_version'] ?? -1);
        $review = null;

        if ($reviewId === null) {
            if ($expectedVersion !== 0) {
                return new PaymentReviewOutcome('blocked', 'payment_review_create_version_must_be_zero', null);
            }
            $existing = $this->reviews->listForOrder($scope, $orderId, 50);
            if ($existing !== []) {
                $matches = array_map(static fn (PaymentReview $item): array => [
                    'review_id' => $item->id,
                    'submission_status' => $item->submissionStatus,
                    'version' => $item->version,
                ], $existing);
                return new PaymentReviewOutcome(
                    'blocked',
                    count($existing) === 1
                        ? 'payment_review_existing_record_requires_exact_id'
                        : 'payment_review_multiple_records_require_selection',
                    null,
                    ['matches' => $matches, 'selection_required' => true]
                );
            }
        } else {
            if ($expectedVersion < 1) {
                return new PaymentReviewOutcome('blocked', 'payment_review_expected_version_required', null);
            }
            $review = $this->reviews->find($scope, $reviewId);
            if ($review === null || $review->orderId !== $orderId) {
                return new PaymentReviewOutcome('blocked', 'payment_review_not_owned_or_unavailable', null);
            }
            if (!hash_equals($review->conversationId, $context->conversationId)) {
                return new PaymentReviewOutcome('blocked', 'payment_review_draft_reauthorization_required', $review);
            }
            if ($review->submissionStatus !== 'draft') {
                return new PaymentReviewOutcome('blocked', 'payment_review_submission_or_resubmission_requires_confirmation', $review);
            }
            if ($review->version !== $expectedVersion) {
                return new PaymentReviewOutcome('stale', 'payment_review_version_conflict', $review, [], true);
            }
        }

        $evidence = $this->buildEvidence($scope, $context->conversationId, $input, $order->snapshot, $review);
        if ($evidence instanceof PaymentReviewOutcome) {
            return $evidence;
        }

        if ($review === null) {
            $created = PaymentReview::draft($scope, $context->conversationId, $orderId, $evidence, $this->clock->now());
            if (!$this->reviews->insert($created)) {
                return new PaymentReviewOutcome('uncertain', 'payment_review_create_persistence_uncertain', null);
            }
            return new PaymentReviewOutcome('succeeded', 'payment_review_draft_created', $created);
        }

        try {
            $updated = $review->reviseDraft($evidence, $expectedVersion, $this->clock->now());
        } catch (\DomainException) {
            return new PaymentReviewOutcome('stale', 'payment_review_version_conflict', $review, [], true);
        }
        if (!$this->reviews->save($updated, $expectedVersion)) {
            $current = $this->reviews->find($scope, $review->id);
            return new PaymentReviewOutcome(
                $current !== null ? 'stale' : 'uncertain',
                $current !== null ? 'payment_review_version_conflict' : 'payment_review_update_persistence_uncertain',
                $current,
                [],
                $current !== null
            );
        }

        return new PaymentReviewOutcome('succeeded', 'payment_review_draft_updated', $updated);
    }

    public function getStatus(ToolContext $context, string $reviewId): PaymentReviewOutcome
    {
        $scope = ActorScope::fromActor($this->actors->map($context));
        $review = $this->reviews->find($scope, $reviewId);
        if ($review === null) {
            return new PaymentReviewOutcome('blocked', 'payment_review_not_owned_or_unavailable', null);
        }
        $order = $this->authority->currentOwnedOrder($context, $review->orderId);
        $current = $order->snapshot?->jsonSerialize();
        return new PaymentReviewOutcome('succeeded', 'payment_review_status_read', $review, [
            'review' => $review->customerView($current),
            'current_order_authority_code' => $order->code,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|PaymentReviewOutcome
     */
    private function buildEvidence(
        ActorScope $scope,
        string $conversationId,
        array $input,
        \Veyra\PaymentReview\Domain\PaymentOrderSnapshot $order,
        ?PaymentReview $existing
    ): array|PaymentReviewOutcome {
        $prior = $existing?->evidence ?? [];
        $fields = is_array($prior['customer_fields'] ?? null) ? $prior['customer_fields'] : [];
        $limits = [
            'amount' => 32,
            'currency' => 3,
            'reference' => 191,
            'payer_name' => 191,
            'transferred_at' => 40,
            'note' => 2000,
        ];
        foreach ($limits as $field => $limit) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = trim((string) $input[$field]);
            if ($value === '') {
                unset($fields[$field]);
                continue;
            }
            if (strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                return new PaymentReviewOutcome('blocked', 'payment_review_evidence_field_invalid', $existing);
            }
            $fields[$field] = $value;
        }
        if (isset($fields['amount']) && preg_match('/^\d+(?:\.\d{1,8})?$/D', (string) $fields['amount']) !== 1) {
            return new PaymentReviewOutcome('blocked', 'payment_review_amount_invalid', $existing);
        }
        if (isset($fields['amount']) && ltrim(str_replace('.', '', (string) $fields['amount']), '0') === '') {
            return new PaymentReviewOutcome('blocked', 'payment_review_amount_invalid', $existing);
        }
        if (isset($fields['currency'])) {
            $currency = strtoupper((string) $fields['currency']);
            if (!hash_equals($order->currency, $currency)) {
                return new PaymentReviewOutcome('blocked', 'payment_review_currency_mismatch', $existing);
            }
            $fields['currency'] = $currency;
        } elseif (isset($fields['amount'])) {
            $fields['currency'] = $order->currency;
        }
        if (isset($fields['transferred_at'])) {
            $transferredAt = (string) $fields['transferred_at'];
            if (preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',
                $transferredAt
            ) !== 1) {
                return new PaymentReviewOutcome('blocked', 'payment_review_transfer_time_invalid', $existing);
            }
            try {
                $date = new \DateTimeImmutable($transferredAt);
                $errors = \DateTimeImmutable::getLastErrors();
                if (is_array($errors)
                    && ((int) ($errors['warning_count'] ?? 0) > 0 || (int) ($errors['error_count'] ?? 0) > 0)
                ) {
                    return new PaymentReviewOutcome('blocked', 'payment_review_transfer_time_invalid', $existing);
                }
                $fields['transferred_at'] = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            } catch (\Throwable) {
                return new PaymentReviewOutcome('blocked', 'payment_review_transfer_time_invalid', $existing);
            }
        }

        $attachmentIds = array_key_exists('attachment_ids', $input)
            ? array_values(array_filter((array) $input['attachment_ids'], 'is_string'))
            : (is_array($prior['attachment_ids'] ?? null) ? array_values(array_filter($prior['attachment_ids'], 'is_string')) : []);
        if (count($attachmentIds) > 5 || count($attachmentIds) !== count(array_unique($attachmentIds))) {
            return new PaymentReviewOutcome('blocked', 'payment_review_attachment_selection_invalid', $existing);
        }
        $attachments = $this->attachments->findMany($scope, $attachmentIds);
        if (count($attachments) !== count($attachmentIds)) {
            return new PaymentReviewOutcome('blocked', 'payment_review_attachment_not_owned_or_unavailable', $existing);
        }
        $checksums = [];
        $integrity = [];
        $now = $this->clock->now();
        foreach ($attachments as $attachment) {
            if (!$attachment->isUsable($now)
                || $attachment->purpose !== 'payment_evidence'
                || !hash_equals($conversationId, $attachment->conversationId)
            ) {
                return new PaymentReviewOutcome('blocked', 'payment_review_attachment_not_clean_or_wrong_purpose', $existing);
            }
            if (isset($checksums[$attachment->checksumSha256])) {
                return new PaymentReviewOutcome('blocked', 'payment_review_duplicate_attachment', $existing);
            }
            $checksums[$attachment->checksumSha256] = true;
            $integrity[] = [
                'attachment_id' => $attachment->id,
                'checksum_sha256' => $attachment->checksumSha256,
                'mime_type' => $attachment->mimeType,
                'byte_size' => $attachment->byteSize,
                'scan_status' => 'clean',
            ];
        }

        $otherReviews = $this->reviews->listForOrder($scope, $order->orderId, 50);
        foreach ($otherReviews as $other) {
            if ($existing !== null && $other->id === $existing->id) {
                continue;
            }
            $otherIntegrity = is_array($other->evidence['attachment_integrity'] ?? null) ? $other->evidence['attachment_integrity'] : [];
            foreach ($otherIntegrity as $entry) {
                $checksum = is_array($entry) && is_string($entry['checksum_sha256'] ?? null) ? $entry['checksum_sha256'] : null;
                if ($checksum !== null && isset($checksums[$checksum])) {
                    return new PaymentReviewOutcome('blocked', 'payment_review_duplicate_evidence_requires_review', $existing);
                }
            }
        }

        $proposalsInput = array_key_exists('proposed_extractions', $input)
            ? (array) $input['proposed_extractions']
            : (is_array($prior['proposed_extractions'] ?? null) ? $prior['proposed_extractions'] : []);
        if (count($proposalsInput) > 20) {
            return new PaymentReviewOutcome('blocked', 'payment_review_extraction_limit_exceeded', $existing);
        }
        $proposals = [];
        foreach ($proposalsInput as $proposal) {
            if (!is_array($proposal)
                || !in_array($proposal['field'] ?? null, ['amount', 'reference', 'payer_name', 'transferred_at'], true)
                || !is_string($proposal['value'] ?? null)
                || strlen($proposal['value']) > 191
                || (!is_int($proposal['confidence'] ?? null) && !is_float($proposal['confidence'] ?? null))
                || $proposal['confidence'] < 0
                || $proposal['confidence'] > 1
                || !is_string($proposal['source_attachment_id'] ?? null)
                || !in_array($proposal['source_attachment_id'], $attachmentIds, true)
            ) {
                return new PaymentReviewOutcome('blocked', 'payment_review_extraction_invalid', $existing);
            }
            $proposals[] = [
                'field' => $proposal['field'],
                'value' => trim($proposal['value']),
                'confidence' => (float) $proposal['confidence'],
                'source_attachment_id' => $proposal['source_attachment_id'],
                'source_path' => is_string($proposal['source_path'] ?? null) ? substr($proposal['source_path'], 0, 191) : '',
                'source_type' => 'ai_or_ocr_proposal',
                'verification_state' => 'unverified',
            ];
        }

        $materialFields = array_diff_key($fields, ['currency' => true]);
        if ($materialFields === [] && $attachmentIds === []) {
            return new PaymentReviewOutcome('blocked', 'payment_review_material_evidence_required', $existing);
        }
        $history = is_array($prior['draft_history'] ?? null) ? $prior['draft_history'] : [];
        if ($existing !== null) {
            if (count($history) >= 50) {
                return new PaymentReviewOutcome('blocked', 'payment_review_draft_revision_limit_reached', $existing);
            }
            $history[] = [
                'version' => $existing->version,
                'evidence_hash' => hash('sha256', CanonicalJson::encode([
                    'customer_fields' => $prior['customer_fields'] ?? [],
                    'attachment_integrity' => $prior['attachment_integrity'] ?? [],
                    'proposed_extractions' => $prior['proposed_extractions'] ?? [],
                    'order_snapshot' => $prior['order_snapshot'] ?? [],
                ])),
                'revised_at' => $this->clock->now()->toIso8601(),
            ];
        }

        return [
            'customer_fields' => $fields,
            'attachment_ids' => $attachmentIds,
            'attachment_integrity' => $integrity,
            'proposed_extractions' => $proposals,
            'order_snapshot' => $order->jsonSerialize(),
            'draft_history' => $history,
            'submission_history' => is_array($prior['submission_history'] ?? null) ? $prior['submission_history'] : [],
        ];
    }
}
