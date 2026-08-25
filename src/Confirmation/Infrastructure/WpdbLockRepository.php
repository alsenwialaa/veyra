<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Infrastructure;

use Veyra\Confirmation\Application\LockRepository;
use Veyra\Confirmation\Domain\LockRecord;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\UtcInstant;

final class WpdbLockRepository implements LockRepository
{
    private readonly string $table;

    public function __construct(private readonly \wpdb $database, TableNames $tables)
    {
        $this->table = $tables->locks();
    }

    public function acquire(LockRecord $candidate, UtcInstant $now): ?LockRecord
    {
        $inserted = $this->database->query(
            $this->database->prepare(
                "INSERT IGNORE INTO {$this->table}
                 (public_id,resource_key_hash,owner_digest,correlation_id,version,expires_at,created_at,updated_at)
                 VALUES (%s,%s,%s,%s,%d,%s,%s,%s)",
                $candidate->id,
                $candidate->resourceKeyHash,
                $candidate->ownerDigest,
                $candidate->correlationId->value(),
                $candidate->version,
                $candidate->expiresAt->toDatabase(),
                $candidate->createdAt->toDatabase(),
                $candidate->updatedAt->toDatabase()
            )
        );

        if ($inserted !== 1) {
            $taken = $this->database->query(
                $this->database->prepare(
                    "UPDATE {$this->table}
                     SET public_id = %s, owner_digest = %s, correlation_id = %s,
                         expires_at = %s, updated_at = %s, version = version + 1
                     WHERE resource_key_hash = %s AND expires_at <= %s",
                    $candidate->id,
                    $candidate->ownerDigest,
                    $candidate->correlationId->value(),
                    $candidate->expiresAt->toDatabase(),
                    $candidate->updatedAt->toDatabase(),
                    $candidate->resourceKeyHash,
                    $now->toDatabase()
                )
            );

            if ($taken !== 1) {
                return null;
            }
        }

        return $this->findOwned($candidate->resourceKeyHash, $candidate->ownerDigest);
    }

    public function release(LockRecord $record): bool
    {
        $query = $this->database->prepare(
            "DELETE FROM {$this->table}
             WHERE public_id = %s AND resource_key_hash = %s AND owner_digest = %s AND version = %d",
            $record->id,
            $record->resourceKeyHash,
            $record->ownerDigest,
            $record->version
        );

        return $this->database->query($query) === 1;
    }

    public function refresh(LockRecord $record, UtcInstant $newExpiry, UtcInstant $now): ?LockRecord
    {
        $query = $this->database->prepare(
            "UPDATE {$this->table}
             SET expires_at = %s, updated_at = %s, version = version + 1
             WHERE public_id = %s AND resource_key_hash = %s AND owner_digest = %s
             AND version = %d AND expires_at > %s",
            $newExpiry->toDatabase(),
            $now->toDatabase(),
            $record->id,
            $record->resourceKeyHash,
            $record->ownerDigest,
            $record->version,
            $now->toDatabase()
        );

        if ($this->database->query($query) !== 1) {
            return null;
        }

        return $this->findOwned($record->resourceKeyHash, $record->ownerDigest);
    }

    private function findOwned(string $resourceKeyHash, string $ownerDigest): ?LockRecord
    {
        $query = $this->database->prepare(
            "SELECT * FROM {$this->table} WHERE resource_key_hash = %s AND owner_digest = %s LIMIT 1",
            $resourceKeyHash,
            $ownerDigest
        );
        $row = $this->database->get_row($query, ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        return new LockRecord(
            (string) $row['public_id'],
            (string) $row['resource_key_hash'],
            (string) $row['owner_digest'],
            new CorrelationId((string) $row['correlation_id']),
            (int) $row['version'],
            UtcInstant::fromDatabase((string) $row['expires_at']),
            UtcInstant::fromDatabase((string) $row['created_at']),
            UtcInstant::fromDatabase((string) $row['updated_at'])
        );
    }
}

