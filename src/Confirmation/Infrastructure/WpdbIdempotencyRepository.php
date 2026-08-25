<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Infrastructure;

use Veyra\Confirmation\Application\IdempotencyRepository;
use Veyra\Confirmation\Domain\IdempotencyRecord;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\UtcInstant;

final class WpdbIdempotencyRepository implements IdempotencyRepository
{
    private readonly string $table;

    public function __construct(private readonly \wpdb $database, TableNames $tables)
    {
        $this->table = $tables->idempotency();
    }

    public function insert(IdempotencyRecord $record): bool
    {
        $result = $this->database->query(
            $this->database->prepare(
                "INSERT IGNORE INTO {$this->table}
                (public_id,key_digest,actor_type,actor_id,actor_key_hash,action_key,action_key_hash,
                 resource_scope_hash,payload_hash,status,result_code,result_json,retry_safe,correlation_id,
                 version,expires_at,completed_at,created_at,updated_at)
                 VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,NULL,NULL,0,%s,%d,%s,NULL,%s,%s)",
                $record->id,
                $record->keyDigest,
                $record->actor->actorType,
                $record->actor->actorId,
                $record->actor->hash(),
                $record->action,
                hash('sha256', $record->action),
                $record->resourceScopeHash,
                $record->payloadHash->value(),
                $record->status,
                $record->correlationId->value(),
                $record->version,
                $record->expiresAt->toDatabase(),
                $record->createdAt->toDatabase(),
                $record->updatedAt->toDatabase()
            )
        );

        return $result === 1;
    }

    public function find(ActorScope $actor, string $action, string $keyDigest): ?IdempotencyRecord
    {
        $query = $this->database->prepare(
            "SELECT * FROM {$this->table}
             WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             AND action_key_hash = %s AND key_digest = %s LIMIT 1",
            $actor->actorType,
            $actor->actorId,
            $actor->hash(),
            hash('sha256', $action),
            $keyDigest
        );
        $row = $this->database->get_row($query, ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function complete(
        IdempotencyRecord $record,
        string $status,
        string $resultCode,
        array $result,
        bool $retrySafe,
        UtcInstant $completedAt
    ): bool {
        if (!in_array($status, ['succeeded', 'failed', 'uncertain'], true)) {
            throw new \InvalidArgumentException('Idempotency completion status is invalid.');
        }

        $query = $this->database->prepare(
            "UPDATE {$this->table}
             SET status = %s, result_code = %s, result_json = %s, retry_safe = %d,
                 completed_at = %s, updated_at = %s, version = version + 1
             WHERE public_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             AND key_digest = %s AND status = 'in_progress' AND version = %d",
            $status,
            $resultCode,
            CanonicalJson::encode($result),
            $retrySafe ? 1 : 0,
            $completedAt->toDatabase(),
            $completedAt->toDatabase(),
            $record->id,
            $record->actor->actorType,
            $record->actor->actorId,
            $record->actor->hash(),
            $record->keyDigest,
            $record->version
        );

        return $this->database->query($query) === 1;
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): IdempotencyRecord
    {
        $result = isset($row['result_json']) && is_string($row['result_json'])
            ? json_decode($row['result_json'], true)
            : null;

        return new IdempotencyRecord(
            (string) $row['public_id'],
            (string) $row['key_digest'],
            new ActorScope((string) $row['actor_type'], (string) $row['actor_id']),
            (string) $row['action_key'],
            (string) $row['resource_scope_hash'],
            new StateHash((string) $row['payload_hash']),
            (string) $row['status'],
            isset($row['result_code']) && is_string($row['result_code']) ? $row['result_code'] : null,
            is_array($result) ? $result : null,
            (bool) $row['retry_safe'],
            new CorrelationId((string) $row['correlation_id']),
            (int) $row['version'],
            UtcInstant::fromDatabase((string) $row['expires_at']),
            isset($row['completed_at']) && is_string($row['completed_at']) && $row['completed_at'] !== ''
                ? UtcInstant::fromDatabase($row['completed_at'])
                : null,
            UtcInstant::fromDatabase((string) $row['created_at']),
            UtcInstant::fromDatabase((string) $row['updated_at'])
        );
    }
}

