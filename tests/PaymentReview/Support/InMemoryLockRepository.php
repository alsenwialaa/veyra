<?php

declare(strict_types=1);

namespace Veyra\Tests\PaymentReview\Support;

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
        return null;
    }
}
