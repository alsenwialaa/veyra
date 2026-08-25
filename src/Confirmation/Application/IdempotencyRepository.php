<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Application;

use Veyra\Confirmation\Domain\IdempotencyRecord;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\UtcInstant;

interface IdempotencyRepository
{
    public function insert(IdempotencyRecord $record): bool;

    public function find(ActorScope $actor, string $action, string $keyDigest): ?IdempotencyRecord;

    /** @param array<string, mixed> $result */
    public function complete(
        IdempotencyRecord $record,
        string $status,
        string $resultCode,
        array $result,
        bool $retrySafe,
        UtcInstant $completedAt
    ): bool;
}

