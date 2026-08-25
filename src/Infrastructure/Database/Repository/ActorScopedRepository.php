<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Repository;

abstract class ActorScopedRepository
{
    public function __construct(
        protected readonly \wpdb $database,
        protected readonly string $table
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new \InvalidArgumentException('Repository table name is invalid.');
        }
    }

    /** @return array<string, mixed>|null */
    protected function findScopedRow(ActorScope $actor, string $publicId): ?array
    {
        $query = $this->database->prepare(
            "SELECT * FROM {$this->table} WHERE public_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s LIMIT 1",
            $publicId,
            $actor->actorType,
            $actor->actorId,
            $actor->hash()
        );
        $row = $this->database->get_row($query, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    protected function updateScopedVersioned(
        ActorScope $actor,
        string $publicId,
        int $expectedVersion,
        array $changes
    ): bool {
        if ($expectedVersion < 1 || $changes === []) {
            return false;
        }

        $set = [];
        $values = [];

        foreach ($changes as $column => $value) {
            if (!is_string($column) || preg_match('/^[a-z][a-z0-9_]*$/D', $column) !== 1 || $column === 'version') {
                throw new \InvalidArgumentException('Unsafe repository update column.');
            }

            if ($value === null) {
                // wpdb::prepare formats null through %s as an empty string.
                // Preserve the schema's nullable semantics explicitly instead
                // of writing invalid empty DATETIME/ID values in strict SQL.
                $set[] = "{$column} = NULL";
                continue;
            }

            $set[] = "{$column} = %s";
            $values[] = $value;
        }

        $set[] = 'version = version + 1';
        array_push(
            $values,
            $publicId,
            $actor->actorType,
            $actor->actorId,
            $actor->hash(),
            $expectedVersion
        );
        $sql = "UPDATE {$this->table} SET " . implode(', ', $set)
            . ' WHERE public_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s AND version = %d';
        $prepared = $this->database->prepare($sql, ...$values);

        return $this->database->query($prepared) === 1;
    }
}
