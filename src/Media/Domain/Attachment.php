<?php

declare(strict_types=1);

namespace Veyra\Media\Domain;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Shared\Domain\Uuid;

/**
 * Metadata for a protected object. The storage key is deliberately excluded
 * from customer/model-facing serialization.
 */
final class Attachment
{
    private const PURPOSES = ['payment_evidence', 'crm_evidence'];
    private const SCAN_STATUSES = ['pending', 'clean', 'malicious', 'unavailable', 'error'];
    private const STATUSES = ['quarantined', 'active', 'rejected', 'deleted'];

    public function __construct(
        public readonly string $id,
        public readonly ActorScope $actor,
        public readonly string $conversationId,
        public readonly ?string $messageId,
        public readonly string $purpose,
        public readonly string $storageDriver,
        public readonly string $storageKey,
        public readonly string $mimeType,
        public readonly int $byteSize,
        public readonly string $checksumSha256,
        public readonly string $scanStatus,
        public readonly string $status,
        public readonly int $version,
        public readonly UtcInstant $expiresAt,
        public readonly ?UtcInstant $deletedAt,
        public readonly UtcInstant $createdAt,
        public readonly UtcInstant $updatedAt
    ) {
        if (!Uuid::isValid($id)
            || !Uuid::isValid($conversationId)
            || ($messageId !== null && !self::validMessageId($messageId))
            || !in_array($purpose, self::PURPOSES, true)
            || preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $storageDriver) !== 1
            || $storageKey === ''
            || strlen($storageKey) > 255
            || preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/D', $mimeType) !== 1
            || $byteSize < 1
            || preg_match('/^[a-f0-9]{64}$/D', $checksumSha256) !== 1
            || !in_array($scanStatus, self::SCAN_STATUSES, true)
            || !in_array($status, self::STATUSES, true)
            || $version < 1
        ) {
            throw new \InvalidArgumentException('Protected attachment state is invalid.');
        }

        if (($status === 'active') !== ($scanStatus === 'clean')) {
            throw new \InvalidArgumentException('Only clean attachments may be active.');
        }
        if ($status === 'deleted' && $deletedAt === null) {
            throw new \InvalidArgumentException('Deleted attachments require a deletion instant.');
        }
    }

    public static function quarantined(
        ActorScope $actor,
        string $conversationId,
        ?string $messageId,
        string $purpose,
        string $storageDriver,
        string $storageKey,
        string $mimeType,
        int $byteSize,
        string $checksumSha256,
        UtcInstant $now,
        int $retentionSeconds
    ): self {
        if ($retentionSeconds < 3600 || $retentionSeconds > 31536000) {
            throw new \InvalidArgumentException('Attachment retention is outside the safe bound.');
        }

        return new self(
            Uuid::v4(),
            $actor,
            $conversationId,
            $messageId,
            $purpose,
            $storageDriver,
            $storageKey,
            $mimeType,
            $byteSize,
            $checksumSha256,
            'pending',
            'quarantined',
            1,
            $now->addSeconds($retentionSeconds),
            null,
            $now,
            $now
        );
    }

    public function withScanResult(string $scanStatus, UtcInstant $now): self
    {
        $status = $scanStatus === 'clean'
            ? 'active'
            : ($scanStatus === 'malicious' ? 'rejected' : 'quarantined');

        return new self(
            $this->id,
            $this->actor,
            $this->conversationId,
            $this->messageId,
            $this->purpose,
            $this->storageDriver,
            $this->storageKey,
            $this->mimeType,
            $this->byteSize,
            $this->checksumSha256,
            $scanStatus,
            $status,
            $this->version + 1,
            $this->expiresAt,
            null,
            $this->createdAt,
            $now
        );
    }

    public function isUsable(UtcInstant $now): bool
    {
        return $this->status === 'active'
            && $this->scanStatus === 'clean'
            && $this->deletedAt === null
            && $now->isBefore($this->expiresAt);
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
            'message_id' => $this->messageId,
            'purpose' => $this->purpose,
            'storage_driver' => $this->storageDriver,
            'storage_key' => $this->storageKey,
            'mime_type' => $this->mimeType,
            'byte_size' => $this->byteSize,
            'checksum_sha256' => $this->checksumSha256,
            'scan_status' => $this->scanStatus,
            'status' => $this->status,
            'version' => $this->version,
            'expires_at' => $this->expiresAt->toDatabase(),
            'deleted_at' => $this->deletedAt?->toDatabase(),
            'created_at' => $this->createdAt->toDatabase(),
            'updated_at' => $this->updatedAt->toDatabase(),
        ];
    }

    /** @return array<string, mixed> */
    public function safeMetadata(UtcInstant $now): array
    {
        return [
            'attachment_id' => $this->id,
            'purpose' => $this->purpose,
            'mime_type' => $this->mimeType,
            'byte_size' => $this->byteSize,
            'checksum_sha256' => $this->checksumSha256,
            'scan_status' => $this->scanStatus,
            'status' => $this->status,
            'usable_as_evidence' => $this->isUsable($now),
            'version' => $this->version,
            'expires_at' => $this->expiresAt->toIso8601(),
            'created_at' => $this->createdAt->toIso8601(),
            'updated_at' => $this->updatedAt->toIso8601(),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['public_id'],
            new ActorScope((string) $row['actor_type'], (string) $row['actor_id']),
            (string) $row['conversation_id'],
            is_string($row['message_id'] ?? null) && $row['message_id'] !== '' ? $row['message_id'] : null,
            (string) $row['purpose'],
            (string) $row['storage_driver'],
            (string) $row['storage_key'],
            (string) $row['mime_type'],
            (int) $row['byte_size'],
            (string) $row['checksum_sha256'],
            (string) $row['scan_status'],
            (string) $row['status'],
            (int) $row['version'],
            UtcInstant::fromDatabase((string) $row['expires_at']),
            is_string($row['deleted_at'] ?? null) && $row['deleted_at'] !== ''
                ? UtcInstant::fromDatabase($row['deleted_at'])
                : null,
            UtcInstant::fromDatabase((string) $row['created_at']),
            UtcInstant::fromDatabase((string) $row['updated_at'])
        );
    }

    private static function validMessageId(string $messageId): bool
    {
        return Uuid::isValid($messageId)
            || preg_match('/^msg_[a-f0-9]{32}$/D', $messageId) === 1;
    }
}
