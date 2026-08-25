<?php

declare(strict_types=1);

namespace Veyra\Media\Infrastructure;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\Repository\ActorScopedRepository;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Media\Application\AttachmentRepository;
use Veyra\Media\Domain\Attachment;

final class WpdbAttachmentRepository extends ActorScopedRepository implements AttachmentRepository
{
    public function __construct(\wpdb $database, TableNames $tables)
    {
        parent::__construct($database, $tables->attachments());
    }

    public function insert(Attachment $attachment): bool
    {
        return $this->database->insert($this->table, $attachment->persistenceValues(), [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
        ]) === 1;
    }

    public function save(Attachment $attachment, int $expectedVersion): bool
    {
        if ($attachment->version !== $expectedVersion + 1) {
            throw new \InvalidArgumentException('Attachment version transition is invalid.');
        }

        return $this->updateScopedVersioned($attachment->actor, $attachment->id, $expectedVersion, [
            'scan_status' => $attachment->scanStatus,
            'status' => $attachment->status,
            'deleted_at' => $attachment->deletedAt?->toDatabase(),
            'updated_at' => $attachment->updatedAt->toDatabase(),
        ]);
    }

    public function find(ActorScope $actor, string $attachmentId): ?Attachment
    {
        $row = $this->findScopedRow($actor, $attachmentId);
        return $row !== null ? Attachment::fromRow($row) : null;
    }

    public function findMany(ActorScope $actor, array $attachmentIds): array
    {
        $ids = array_values(array_unique(array_filter($attachmentIds, static fn (mixed $id): bool => is_string($id) && $id !== '')));
        if ($ids === [] || count($ids) > 20) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $query = $this->database->prepare(
            "SELECT * FROM {$this->table} WHERE public_id IN ({$placeholders}) AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s",
            ...array_merge($ids, [$actor->actorType, $actor->actorId, $actor->hash()])
        );
        $rows = $this->database->get_results($query, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $byId = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $attachment = Attachment::fromRow($row);
                $byId[$attachment->id] = $attachment;
            }
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function activeUsage(ActorScope $actor): ?array
    {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT COUNT(*) AS item_count, COALESCE(SUM(byte_size), 0) AS byte_count FROM {$this->table}
             WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             AND status IN ('active','quarantined') AND deleted_at IS NULL",
            $actor->actorType,
            $actor->actorId,
            $actor->hash()
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        return ['count' => (int) ($row['item_count'] ?? 0), 'bytes' => (int) ($row['byte_count'] ?? 0)];
    }
}
