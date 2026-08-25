<?php

declare(strict_types=1);

namespace Veyra\Tests\Support;

use Veyra\Confirmation\Application\IdempotencyRepository;
use Veyra\Confirmation\Domain\IdempotencyRecord;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\UtcInstant;

final class InMemoryIdempotencyRepository implements IdempotencyRepository
{
    /** @var array<string, IdempotencyRecord> */
    private array $records = [];

    public function insert(IdempotencyRecord $record): bool
    {
        $key = $this->key($record->actor, $record->action, $record->keyDigest);

        if (isset($this->records[$key])) {
            return false;
        }

        $this->records[$key] = $record;

        return true;
    }

    public function find(ActorScope $actor, string $action, string $keyDigest): ?IdempotencyRecord
    {
        return $this->records[$this->key($actor, $action, $keyDigest)] ?? null;
    }

    public function complete(
        IdempotencyRecord $record,
        string $status,
        string $resultCode,
        array $result,
        bool $retrySafe,
        UtcInstant $completedAt
    ): bool {
        $key = $this->key($record->actor, $record->action, $record->keyDigest);
        $current = $this->records[$key] ?? null;

        if ($current === null || $current->status !== 'in_progress' || $current->version !== $record->version) {
            return false;
        }

        $this->records[$key] = new IdempotencyRecord(
            $current->id,
            $current->keyDigest,
            $current->actor,
            $current->action,
            $current->resourceScopeHash,
            $current->payloadHash,
            $status,
            $resultCode,
            $result,
            $retrySafe,
            $current->correlationId,
            $current->version + 1,
            $current->expiresAt,
            $completedAt,
            $current->createdAt,
            $completedAt
        );

        return true;
    }

    public function count(): int
    {
        return count($this->records);
    }

    private function key(ActorScope $actor, string $action, string $keyDigest): string
    {
        return $actor->hash() . ':' . hash('sha256', $action) . ':' . $keyDigest;
    }
}
