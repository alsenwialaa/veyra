<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Application;

use Veyra\Confirmation\Domain\ConfirmationRecord;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\UtcInstant;

interface ConfirmationRepository
{
    public function insert(ConfirmationRecord $record): bool;

    public function findByTokenDigest(ActorScope $actor, string $tokenDigest): ?ConfirmationRecord;

    /** @return list<ConfirmationRecord> */
    public function findActiveForJourney(ActorScope $actor, ?string $journeyId, UtcInstant $now, int $limit = 2): array;

    public function consume(
        ConfirmationRecord $record,
        StateHash $currentState,
        UtcInstant $consumedAt,
        CorrelationId $consumptionCorrelationId
    ): bool;

    public function invalidate(ConfirmationRecord $record, string $reason, UtcInstant $invalidatedAt): bool;
}
