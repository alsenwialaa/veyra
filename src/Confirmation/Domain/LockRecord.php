<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Shared\Domain\Uuid;

final class LockRecord
{
    public function __construct(
        public readonly string $id,
        public readonly string $resourceKeyHash,
        public readonly string $ownerDigest,
        public readonly CorrelationId $correlationId,
        public readonly int $version,
        public readonly UtcInstant $expiresAt,
        public readonly UtcInstant $createdAt,
        public readonly UtcInstant $updatedAt
    ) {
        if (!Uuid::isValid($id)
            || preg_match('/^[a-f0-9]{64}$/D', $resourceKeyHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $ownerDigest) !== 1
            || $version < 1
        ) {
            throw new \InvalidArgumentException('Lock record is invalid.');
        }
    }
}

