<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Shared\Domain\Uuid;

final class IdempotencyRecord
{
    /** @param array<string, mixed>|null $result */
    public function __construct(
        public readonly string $id,
        public readonly string $keyDigest,
        public readonly ActorScope $actor,
        public readonly string $action,
        public readonly string $resourceScopeHash,
        public readonly StateHash $payloadHash,
        public readonly string $status,
        public readonly ?string $resultCode,
        public readonly ?array $result,
        public readonly bool $retrySafe,
        public readonly CorrelationId $correlationId,
        public readonly int $version,
        public readonly UtcInstant $expiresAt,
        public readonly ?UtcInstant $completedAt,
        public readonly UtcInstant $createdAt,
        public readonly UtcInstant $updatedAt
    ) {
        if (!Uuid::isValid($id)
            || preg_match('/^[a-f0-9]{64}$/D', $keyDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $resourceScopeHash) !== 1
            || !in_array($status, ['in_progress', 'succeeded', 'failed', 'uncertain'], true)
            || $version < 1
        ) {
            throw new \InvalidArgumentException('Idempotency record is invalid.');
        }
    }
}

