<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Application;

use Veyra\Confirmation\Domain\LockHandle;
use Veyra\Confirmation\Domain\LockRecord;
use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\SecretGenerator;
use Veyra\Shared\Domain\Uuid;

final class LockManager
{
    public function __construct(
        private readonly LockRepository $locks,
        private readonly SecretDigester $digester,
        private readonly Clock $clock
    ) {
    }

    public function acquire(string $resourceKey, CorrelationId $correlationId, int $ttlSeconds = 15): ?LockHandle
    {
        if ($resourceKey === '' || strlen($resourceKey) > 512 || $ttlSeconds < 1 || $ttlSeconds > 120) {
            throw new \InvalidArgumentException('Lock request is outside safe bounds.');
        }

        $ownerToken = SecretGenerator::generate(24);
        $now = $this->clock->now();
        $candidate = new LockRecord(
            Uuid::v4(),
            hash('sha256', $resourceKey),
            $this->digester->digest($ownerToken, 'lock-owner'),
            $correlationId,
            1,
            $now->addSeconds($ttlSeconds),
            $now,
            $now
        );
        $record = $this->locks->acquire($candidate, $now);

        return $record === null ? null : new LockHandle($record, $ownerToken);
    }

    public function release(LockHandle $handle): bool
    {
        if (!hash_equals(
            $handle->record->ownerDigest,
            $this->digester->digest($handle->ownerToken, 'lock-owner')
        )) {
            return false;
        }

        return $this->locks->release($handle->record);
    }

    public function refresh(LockHandle $handle, int $ttlSeconds = 15): ?LockHandle
    {
        if ($ttlSeconds < 1 || $ttlSeconds > 120
            || !hash_equals(
                $handle->record->ownerDigest,
                $this->digester->digest($handle->ownerToken, 'lock-owner')
            )
        ) {
            return null;
        }

        $now = $this->clock->now();
        $record = $this->locks->refresh($handle->record, $now->addSeconds($ttlSeconds), $now);

        return $record === null ? null : new LockHandle($record, $handle->ownerToken);
    }
}

