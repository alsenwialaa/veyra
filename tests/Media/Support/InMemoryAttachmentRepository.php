<?php

declare(strict_types=1);

namespace Veyra\Tests\Media\Support;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Media\Application\AttachmentRepository;
use Veyra\Media\Domain\Attachment;

final class InMemoryAttachmentRepository implements AttachmentRepository
{
    /** @var array<string, Attachment> */
    private array $items = [];

    public function insert(Attachment $attachment): bool
    {
        if (isset($this->items[$attachment->id])) {
            return false;
        }
        $this->items[$attachment->id] = $attachment;
        return true;
    }

    public function save(Attachment $attachment, int $expectedVersion): bool
    {
        $current = $this->items[$attachment->id] ?? null;
        if (!$current instanceof Attachment || $current->version !== $expectedVersion || $attachment->version !== $expectedVersion + 1) {
            return false;
        }
        $this->items[$attachment->id] = $attachment;
        return true;
    }

    public function find(ActorScope $actor, string $attachmentId): ?Attachment
    {
        $item = $this->items[$attachmentId] ?? null;
        return $item instanceof Attachment && hash_equals($item->actor->hash(), $actor->hash()) ? $item : null;
    }

    public function findMany(ActorScope $actor, array $attachmentIds): array
    {
        $items = [];
        foreach ($attachmentIds as $id) {
            $item = is_string($id) ? $this->find($actor, $id) : null;
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    public function activeUsage(ActorScope $actor): ?array
    {
        $count = 0;
        $bytes = 0;
        foreach ($this->items as $item) {
            if (hash_equals($item->actor->hash(), $actor->hash()) && in_array($item->status, ['active', 'quarantined'], true)) {
                ++$count;
                $bytes += $item->byteSize;
            }
        }
        return ['count' => $count, 'bytes' => $bytes];
    }
}
