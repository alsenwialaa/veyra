<?php

declare(strict_types=1);

namespace Veyra\Tests\Checkout\Support;

use Veyra\Confirmation\Application\LockRepository;
use Veyra\Confirmation\Domain\LockRecord;
use Veyra\Shared\Domain\UtcInstant;

final class InMemoryLockRepository implements LockRepository
{
    /** @var array<string, LockRecord> */
    private array $records = [];

    public function acquire(LockRecord $candidate, UtcInstant $now): ?LockRecord
    {
        $current = $this->records[$candidate->resourceKeyHash] ?? null;
        if ($current instanceof LockRecord && !$current->expiresAt->isAtOrBefore($now)) {
            return null;
        }
        $this->records[$candidate->resourceKeyHash] = $candidate;

        return $candidate;
    }

    public function release(LockRecord $record): bool
    {
        $current = $this->records[$record->resourceKeyHash] ?? null;
        if (!$current instanceof LockRecord
            || !hash_equals($current->id, $record->id)
            || !hash_equals($current->ownerDigest, $record->ownerDigest)
        ) {
            return false;
        }
        unset($this->records[$record->resourceKeyHash]);

        return true;
    }

    public function refresh(LockRecord $record, UtcInstant $newExpiry, UtcInstant $now): ?LockRecord
    {
        $current = $this->records[$record->resourceKeyHash] ?? null;
        if (!$current instanceof LockRecord
            || !hash_equals($current->id, $record->id)
            || !hash_equals($current->ownerDigest, $record->ownerDigest)
            || $current->expiresAt->isAtOrBefore($now)
        ) {
            return null;
        }
        $refreshed = new LockRecord(
            $current->id,
            $current->resourceKeyHash,
            $current->ownerDigest,
            $current->correlationId,
            $current->version + 1,
            $newExpiry,
            $current->createdAt,
            $now
        );
        $this->records[$record->resourceKeyHash] = $refreshed;

        return $refreshed;
    }
}
