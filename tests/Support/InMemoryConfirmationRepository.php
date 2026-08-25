<?php

declare(strict_types=1);

namespace Veyra\Tests\Support;

use Veyra\Confirmation\Application\ConfirmationRepository;
use Veyra\Confirmation\Domain\ConfirmationRecord;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Shared\Domain\CorrelationId;

final class InMemoryConfirmationRepository implements ConfirmationRepository
{
    /** @var array<string, ConfirmationRecord> */
    private array $records = [];

    public function insert(ConfirmationRecord $record): bool
    {
        $key = $this->key($record->actor, $record->tokenDigest);

        if (isset($this->records[$key])) {
            return false;
        }

        $this->records[$key] = $record;

        return true;
    }

    public function findByTokenDigest(ActorScope $actor, string $tokenDigest): ?ConfirmationRecord
    {
        return $this->records[$this->key($actor, $tokenDigest)] ?? null;
    }

    public function findActiveForJourney(ActorScope $actor, ?string $journeyId, UtcInstant $now, int $limit = 2): array
    {
        $found = [];

        foreach ($this->records as $record) {
            if ($record->actor->hash() === $actor->hash()
                && $record->journeyId === $journeyId
                && $record->activeAt($now)
            ) {
                $found[] = $record;
            }
        }

        return array_slice($found, 0, $limit);
    }

    public function consume(
        ConfirmationRecord $record,
        StateHash $currentState,
        UtcInstant $consumedAt,
        CorrelationId $consumptionCorrelationId
    ): bool
    {
        $key = $this->key($record->actor, $record->tokenDigest);
        $current = $this->records[$key] ?? null;

        if ($current === null
            || !$current->activeAt($consumedAt)
            || $current->version !== $record->version
            || !$current->stateHash->equals($currentState)
        ) {
            return false;
        }

        $this->records[$key] = $current->asConsumed($consumedAt, $consumptionCorrelationId);

        return true;
    }

    public function invalidate(ConfirmationRecord $record, string $reason, UtcInstant $invalidatedAt): bool
    {
        return false;
    }

    public function first(): ?ConfirmationRecord
    {
        return $this->records === [] ? null : reset($this->records);
    }

    private function key(ActorScope $actor, string $tokenDigest): string
    {
        return $actor->hash() . ':' . $tokenDigest;
    }
}
