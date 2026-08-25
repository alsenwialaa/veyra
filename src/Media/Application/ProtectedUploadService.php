<?php

declare(strict_types=1);

namespace Veyra\Media\Application;

use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Confirmation\Domain\IdempotencyDecisionStatus;
use Veyra\Conversation\Application\ConversationStore;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Media\Domain\Attachment;
use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\Uuid;

/**
 * Synchronous protected-upload boundary for a future authenticated REST adapter.
 * It never creates a public URL and never treats scanner failure as clean.
 */
final class ProtectedUploadService
{
    public function __construct(
        private readonly FileValidator $validator,
        private readonly ImageReencoder $images,
        private readonly MalwareScanner $scanner,
        private readonly ProtectedStorage $storage,
        private readonly AttachmentRepository $attachments,
        private readonly IdempotencyService $idempotency,
        private readonly FoundationActorMapper $actors,
        private readonly Clock $clock,
        private readonly ConversationStore $conversations,
        private readonly LockManager $locks,
        private readonly int $retentionSeconds,
        private readonly int $maximumActiveFilesPerActor = 20,
        private readonly int $maximumActiveBytesPerActor = 83886080
    ) {
        if ($maximumActiveFilesPerActor < 1 || $maximumActiveFilesPerActor > 100
            || $maximumActiveBytesPerActor < 1048576 || $maximumActiveBytesPerActor > 1073741824
            || $retentionSeconds < 3600 || $retentionSeconds > 31536000
        ) {
            throw new \InvalidArgumentException('Protected upload quota or retention is invalid.');
        }
    }

    public function accept(
        ToolContext $context,
        string $temporaryPath,
        string $claimedMimeType,
        string $purpose,
        string $idempotencyKey,
        ?string $messageId = null
    ): UploadOutcome {
        if (!in_array($context->actorType, ['guest', 'customer'], true)) {
            return new UploadOutcome('blocked', 'upload_actor_not_allowed', null, false);
        }
        if (!in_array($purpose, ['payment_evidence', 'crm_evidence'], true)) {
            return new UploadOutcome('blocked', 'upload_purpose_not_allowed', null, false);
        }
        if ($purpose === 'payment_evidence' && $context->actorType !== 'customer') {
            return new UploadOutcome('blocked', 'upload_authenticated_customer_required', null, false);
        }
        if ($messageId !== null
            && !Uuid::isValid($messageId)
            && preg_match('/^msg_[a-f0-9]{32}$/D', $messageId) !== 1
        ) {
            return new UploadOutcome('blocked', 'upload_message_reference_invalid', null, false);
        }
        if (($purpose === 'payment_evidence' && !$context->featureIsAvailable('payment_offline_review'))
            || ($purpose === 'crm_evidence' && !$context->featureIsAvailable('service_crm'))
        ) {
            return new UploadOutcome('blocked', 'upload_feature_unavailable', null, false);
        }
        if ($this->conversations->getOwnedConversation($context->conversationId, $context->actorType, $context->actorId) === null) {
            return new UploadOutcome('blocked', 'upload_conversation_not_owned_or_unavailable', null, false);
        }
        if ($messageId !== null
            && $this->conversations->visibleMessage(
                $context->conversationId,
                $context->actorType,
                $context->actorId,
                $messageId
            ) === null
        ) {
            return new UploadOutcome('blocked', 'upload_message_not_owned_or_unavailable', null, false);
        }

        try {
            $validated = $this->validator->validate($temporaryPath, $claimedMimeType);
        } catch (\Throwable $error) {
            return new UploadOutcome('blocked', $this->safeValidationCode($error), null, true);
        }

        $actor = $this->actors->map($context);
        $scope = ActorScope::fromActor($actor);
        try {
            $decision = $this->idempotency->begin(
                $actor,
                'media.protected_upload',
                $idempotencyKey,
                [
                    'purpose' => $purpose,
                    'conversation_id' => $context->conversationId,
                    'message_id' => $messageId,
                    'mime_type' => $validated->mimeType,
                    'byte_size' => $validated->byteSize,
                    'checksum_sha256' => $validated->checksumSha256,
                ],
                'conversation:' . $context->conversationId,
                new CorrelationId($context->correlationId)
            );
        } catch (\Throwable) {
            return new UploadOutcome('failed', 'upload_idempotency_unavailable', null, false);
        }

        if ($decision->status === IdempotencyDecisionStatus::Replay) {
            $record = $decision->record;
            $attachmentId = is_string($record->result['attachment_id'] ?? null) ? $record->result['attachment_id'] : '';
            $attachment = $attachmentId !== '' ? $this->attachments->find($scope, $attachmentId) : null;
            if ($record->status === 'succeeded' && $attachment !== null) {
                return new UploadOutcome('succeeded', 'upload_idempotent_replay', $attachment, false);
            }

            return new UploadOutcome(
                $record->status === 'uncertain' ? 'uncertain' : 'failed',
                is_string($record->resultCode) ? $record->resultCode : 'upload_prior_attempt_failed',
                $attachment,
                $record->retrySafe
            );
        }
        if ($decision->status !== IdempotencyDecisionStatus::Claimed) {
            return new UploadOutcome(
                $decision->status === IdempotencyDecisionStatus::Conflict ? 'blocked' : 'uncertain',
                $decision->code,
                null,
                false
            );
        }

        try {
            $lock = $this->locks->acquire(
                'media-upload-quota:' . $scope->hash(),
                new CorrelationId($context->correlationId),
                120
            );
        } catch (\Throwable) {
            $lock = null;
        }
        if ($lock === null) {
            $this->idempotency->fail($decision->record, 'upload_quota_lock_unavailable', [], true);
            return new UploadOutcome('blocked', 'upload_quota_lock_unavailable', null, true);
        }

        $usage = $this->attachments->activeUsage($scope);
        if ($usage === null) {
            $this->releaseLock($lock);
            $this->idempotency->fail($decision->record, 'upload_quota_state_unavailable', [], true);
            return new UploadOutcome('blocked', 'upload_quota_state_unavailable', null, true);
        }
        if ($usage['count'] >= $this->maximumActiveFilesPerActor
            || $usage['bytes'] + $validated->byteSize > $this->maximumActiveBytesPerActor
        ) {
            $this->releaseLock($lock);
            $this->idempotency->fail($decision->record, 'upload_quota_exceeded', [], true);
            return new UploadOutcome('blocked', 'upload_quota_exceeded', null, true);
        }

        $ownedTemporaryPath = null;
        $stored = null;
        $attachment = null;
        try {
            if (str_starts_with($validated->mimeType, 'image/')) {
                $ownedTemporaryPath = $this->images->reencode($validated->path, $validated->mimeType);
                $validated = $this->validator->validate($ownedTemporaryPath, $validated->mimeType);
            }
            $stored = $this->storage->store($validated->path, $validated->mimeType, $validated->checksumSha256);
            $now = $this->clock->now();
            $attachment = Attachment::quarantined(
                $scope,
                $context->conversationId,
                $messageId,
                $purpose,
                $stored->driver,
                $stored->key,
                $validated->mimeType,
                $stored->byteSize,
                $stored->checksumSha256,
                $now,
                $this->retentionSeconds
            );
            if (!$this->attachments->insert($attachment)) {
                $this->storage->delete($stored->key);
                $this->idempotency->markUncertain($decision->record, 'upload_record_persistence_uncertain', []);
                return new UploadOutcome('uncertain', 'upload_record_persistence_uncertain', null, false);
            }

            try {
                $verdict = $this->scanner->scan($validated->path, $validated->mimeType, $validated->checksumSha256);
            } catch (\Throwable) {
                $verdict = new \Veyra\Media\Domain\MalwareScanVerdict('error', 'scanner_exception');
            }
            $scanned = $attachment->withScanResult($verdict->status, $this->clock->now());
            if (!$this->attachments->save($scanned, $attachment->version)) {
                $this->idempotency->markUncertain($decision->record, 'upload_scan_persistence_uncertain', [
                    'attachment_id' => $attachment->id,
                    'stored' => true,
                    'scan_verdict' => $verdict->status,
                ]);
                return new UploadOutcome('uncertain', 'upload_scan_persistence_uncertain', $attachment, false);
            }
            if ($verdict->status === 'malicious') {
                $this->storage->delete($stored->key);
                $result = ['attachment_id' => $scanned->id, 'scan_status' => 'malicious'];
                $this->idempotency->fail($decision->record, 'upload_malware_rejected', $result, false);
                return new UploadOutcome('blocked', 'upload_malware_rejected', $scanned, false);
            }
            if ($verdict->status !== 'clean') {
                $result = ['attachment_id' => $scanned->id, 'scan_status' => $verdict->status];
                $this->idempotency->fail($decision->record, 'upload_scan_not_clean', $result, false);
                return new UploadOutcome('blocked', 'upload_scan_not_clean', $scanned, false);
            }

            $result = ['attachment_id' => $scanned->id, 'scan_status' => 'clean'];
            if (!$this->idempotency->complete($decision->record, 'upload_completed', $result, false)) {
                return new UploadOutcome('uncertain', 'upload_idempotency_completion_uncertain', $scanned, false);
            }

            return new UploadOutcome('succeeded', 'upload_completed', $scanned, false);
        } catch (\Throwable $error) {
            if ($attachment instanceof Attachment) {
                try {
                    $this->idempotency->markUncertain($decision->record, 'upload_processing_uncertain', [
                        'attachment_id' => $attachment->id,
                        'stored' => true,
                    ]);
                } catch (\Throwable) {
                    // Keep the externally reported result uncertain even when
                    // reconciliation metadata cannot be updated immediately.
                }
                return new UploadOutcome('uncertain', 'upload_processing_uncertain', $attachment, false);
            }
            if ($stored instanceof \Veyra\Media\Domain\StoredObject) {
                $this->storage->delete($stored->key);
            }
            $this->idempotency->fail($decision->record, 'upload_processing_failed', [], true);
            return new UploadOutcome('failed', 'upload_processing_failed', null, true);
        } finally {
            $this->releaseLock($lock);
            if (is_string($ownedTemporaryPath) && is_file($ownedTemporaryPath)) {
                // Delete the exact service-owned path; wp_delete_file filters may redirect it.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                @unlink($ownedTemporaryPath);
            }
        }
    }

    private function safeValidationCode(\Throwable $error): string
    {
        $code = $error->getMessage();
        return preg_match('/^upload_[a-z0-9_]{3,88}$/D', $code) === 1 ? $code : 'upload_validation_failed';
    }

    private function releaseLock(\Veyra\Confirmation\Domain\LockHandle $lock): void
    {
        try {
            $this->locks->release($lock);
        } catch (\Throwable) {
            // The lease is bounded; a release failure must not rewrite a known
            // upload outcome. Operations should still alert on lock-table health.
        }
    }
}
