<?php

declare(strict_types=1);

namespace Veyra\Operations\Configuration;

// Internal persistence exceptions are never rendered; adapters escape at the output boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use Veyra\Infrastructure\Database\TableNames;
use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\Uuid;

/** Immutable draft/published configuration revisions with optimistic parents. */
final class ConfigurationRevisionRepository
{
    private readonly string $table;

    public function __construct(private readonly \wpdb $database, TableNames $tables)
    {
        $this->table = $tables->configurationRevisions();
    }

    /** @return array<string, mixed>|null */
    public function latest(string $product, string $state): ?array
    {
        $this->assertProductAndState($product, $state);
        $row = $this->database->get_row($this->database->prepare(
            "SELECT * FROM {$this->table} WHERE product_key = %s AND lifecycle_state = %s ORDER BY id DESC LIMIT 1",
            $product,
            $state
        ), ARRAY_A);
        return is_array($row) ? $this->map($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function publishedHistory(string $product, int $limit = 10): array
    {
        $this->assertProductAndState($product, 'published');
        $limit = max(1, min(50, $limit));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT * FROM {$this->table} WHERE product_key = %s AND lifecycle_state = 'published' ORDER BY id DESC LIMIT %d",
            $product,
            $limit
        ), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(fn (array $row): array => $this->map($row), $rows));
    }

    /** @param array<string, mixed> $payload @param array<string, mixed>|list<array<string, mixed>> $validation */
    public function append(
        string $product,
        string $state,
        array $payload,
        int $userId,
        ?string $expectedParent,
        array $validation = [],
        ?\DateTimeImmutable $activatedAt = null,
        ?string $parentRevision = null
    ): ?array {
        return $this->appendGuarded(
            $product,
            $state,
            $payload,
            $userId,
            [$state => $expectedParent],
            $validation,
            $activatedAt,
            $parentRevision
        );
    }

    /**
     * Atomically compares one or more product lifecycle heads, appends the
     * requested immutable revision and optionally applies related database
     * state before the transaction commits.
     *
     * A bounded per-product MySQL named lock serializes even an empty head;
     * lifecycle row locks are then acquired in a fixed order. This closes the
     * check-then-insert race in append() and lets publication guard both the
     * draft being promoted and the current published head.
     *
     * @param array<string, mixed> $payload
     * @param array{draft?:?string,published?:?string} $expectedHeads
     * @param array<string, mixed>|list<array<string, mixed>> $validation
     * @param null|callable(array<string, mixed>):void $afterInsert
     * @param null|callable():void $afterRollback
     * @param null|callable(array<string, mixed>):void $afterCommit
     * @return array<string, mixed>|null
     */
    public function appendGuarded(
        string $product,
        string $state,
        array $payload,
        int $userId,
        array $expectedHeads,
        array $validation = [],
        ?\DateTimeImmutable $activatedAt = null,
        ?string $parentRevision = null,
        ?callable $afterInsert = null,
        ?callable $afterRollback = null,
        ?callable $afterCommit = null
    ): ?array {
        $this->assertProductAndState($product, $state);
        if ($userId < 1) {
            throw new \InvalidArgumentException('Configuration author is invalid.');
        }
        $this->assertExpectedHeads($state, $expectedHeads);
        $encoded = CanonicalJson::encode($payload);
        if (strlen($encoded) > 262144) {
            throw new \InvalidArgumentException('Configuration exceeds the published size bound.');
        }
        $validationJson = CanonicalJson::encode($validation);
        $publicId = Uuid::v4();

        $lockName = $this->acquireProductLock($product);
        try {
            return $this->appendWithinTransaction(
                $product,
                $state,
                $encoded,
                $validationJson,
                $publicId,
                $userId,
                $expectedHeads,
                $activatedAt,
                $parentRevision,
                $afterInsert,
                $afterRollback,
                $afterCommit
            );
        } finally {
            $this->releaseProductLock($lockName);
        }
    }

    /**
     * @param array{draft?:?string,published?:?string} $expectedHeads
     * @param null|callable(array<string, mixed>):void $afterInsert
     * @param null|callable():void $afterRollback
     * @param null|callable(array<string, mixed>):void $afterCommit
     * @return array<string, mixed>|null
     */
    private function appendWithinTransaction(
        string $product,
        string $state,
        string $encoded,
        string $validationJson,
        string $publicId,
        int $userId,
        array $expectedHeads,
        ?\DateTimeImmutable $activatedAt,
        ?string $parentRevision,
        ?callable $afterInsert,
        ?callable $afterRollback,
        ?callable $afterCommit
    ): ?array {
        if ($this->database->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Configuration transaction could not be started.');
        }
        $transactionActive = true;

        try {
            foreach (['draft', 'published'] as $guardedState) {
                if (!array_key_exists($guardedState, $expectedHeads)) {
                    continue;
                }
                $actualHead = $this->lockedHead($product, $guardedState);
                if ($actualHead !== $expectedHeads[$guardedState]) {
                    $this->rollback();
                    $transactionActive = false;
                    return null;
                }
            }

            $inserted = $this->database->insert($this->table, [
                'public_id' => $publicId,
                'product_key' => $product,
                'lifecycle_state' => $state,
                'parent_public_id' => $parentRevision ?? $expectedHeads[$state],
                'payload_json' => $encoded,
                'payload_hash' => hash('sha256', $encoded),
                'validation_json' => $validationJson,
                'created_by' => $userId,
                'activated_at' => $activatedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if ($inserted !== 1) {
                $this->rollback();
                $transactionActive = false;
                return null;
            }

            $created = $this->findExact($product, $state, $publicId);
            if ($created === null) {
                throw new \RuntimeException('Inserted configuration revision could not be reconciled by its public identifier.');
            }
            if ($afterInsert !== null) {
                $afterInsert($created);
            }
            if ($this->database->query('COMMIT') === false) {
                throw new \RuntimeException('Configuration transaction could not be committed.');
            }
            $transactionActive = false;
            if ($afterCommit !== null) {
                $afterCommit($created);
            }
            return $created;
        } catch (\Throwable $error) {
            if ($transactionActive) {
                $rollbackError = null;
                try {
                    $this->rollback();
                } catch (\Throwable $failure) {
                    $rollbackError = $failure;
                }
                if ($afterRollback !== null) {
                    $afterRollback();
                }
                if ($rollbackError !== null) {
                    throw new \RuntimeException(
                        'Configuration transaction failed and rollback could not be verified.',
                        0,
                        $error
                    );
                }
            }
            throw $error;
        }
    }

    private function acquireProductLock(string $product): string
    {
        $name = 'veyra_config_' . substr(hash('sha256', $this->table . ':' . $product), 0, 40);
        $acquired = $this->database->get_var($this->database->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $name,
            5
        ));
        if ((string) $acquired !== '1') {
            throw new \RuntimeException('Configuration serialization lock is busy or unavailable.');
        }
        return $name;
    }

    private function releaseProductLock(string $name): void
    {
        $released = $this->database->get_var($this->database->prepare(
            'SELECT RELEASE_LOCK(%s)',
            $name
        ));
        if ((string) $released !== '1') {
            throw new \RuntimeException('Configuration serialization lock release could not be verified.');
        }
    }

    private function lockedHead(string $product, string $state): ?string
    {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT public_id FROM {$this->table} WHERE product_key = %s AND lifecycle_state = %s ORDER BY id DESC LIMIT 1 FOR UPDATE",
            $product,
            $state
        ), ARRAY_A);
        if ($row === null) {
            if (is_string($this->database->last_error ?? null) && $this->database->last_error !== '') {
                throw new \RuntimeException('Configuration head could not be locked.');
            }
            return null;
        }
        if (!is_array($row) || !is_string($row['public_id'] ?? null) || $row['public_id'] === '') {
            throw new \RuntimeException('Locked configuration head was malformed.');
        }
        return $row['public_id'];
    }

    /** @return array<string, mixed>|null */
    private function findExact(string $product, string $state, string $publicId): ?array
    {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT * FROM {$this->table} WHERE public_id = %s AND product_key = %s AND lifecycle_state = %s LIMIT 1",
            $publicId,
            $product,
            $state
        ), ARRAY_A);
        return is_array($row) ? $this->map($row) : null;
    }

    private function rollback(): void
    {
        if ($this->database->query('ROLLBACK') === false) {
            throw new \RuntimeException('Configuration transaction could not be rolled back.');
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function map(array $row): array
    {
        $payload = json_decode((string) $row['payload_json'], true, 64);
        $validation = json_decode((string) ($row['validation_json'] ?? '[]'), true, 64);
        return [
            'version' => (string) $row['public_id'],
            'product' => (string) $row['product_key'],
            'state' => (string) $row['lifecycle_state'],
            'parent_version' => is_string($row['parent_public_id'] ?? null) && $row['parent_public_id'] !== '' ? $row['parent_public_id'] : null,
            'configuration' => is_array($payload) ? $payload : [],
            'payload_hash' => (string) $row['payload_hash'],
            'validation' => is_array($validation) ? $validation : [],
            'created_by' => (int) $row['created_by'],
            'activated_at' => isset($row['activated_at']) && is_string($row['activated_at']) && $row['activated_at'] !== '' ? $row['activated_at'] . 'Z' : null,
            'created_at' => (string) $row['created_at'] . 'Z',
        ];
    }

    private function assertProductAndState(string $product, string $state): void
    {
        if (!in_array($product, ['agent', 'knowledge', 'experience', 'commerce'], true)
            || !in_array($state, ['draft', 'published'], true)
        ) {
            throw new \InvalidArgumentException('Unknown configuration product or lifecycle state.');
        }
    }

    /** @param array{draft?:?string,published?:?string} $expectedHeads */
    private function assertExpectedHeads(string $appendState, array $expectedHeads): void
    {
        if (!array_key_exists($appendState, $expectedHeads)) {
            throw new \InvalidArgumentException('The appended lifecycle head must be guarded.');
        }
        foreach ($expectedHeads as $state => $version) {
            if (!in_array($state, ['draft', 'published'], true)
                || ($version !== null && (!is_string($version) || $version === ''))
            ) {
                throw new \InvalidArgumentException('Configuration head guard is invalid.');
            }
        }
    }
}
