<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

use Veyra\Shared\Domain\SecretGenerator;

final class Migrator
{
    public const SCHEMA_OPTION = 'veyra_schema_version';
    public const LOCK_OPTION = 'veyra_migration_lock';
    public const FAILURE_OPTION = 'veyra_migration_failure';

    /** @var list<Migration> */
    private array $migrations;

    /** @param iterable<Migration> $migrations */
    public function __construct(
        private readonly \wpdb $database,
        iterable $migrations
    ) {
        $this->migrations = is_array($migrations) ? array_values($migrations) : iterator_to_array($migrations, false);
        usort(
            $this->migrations,
            static fn (Migration $left, Migration $right): int => version_compare($left->version(), $right->version())
        );
    }

    public function migrate(int $maximumMigrations = 1): MigrationResult
    {
        if ($maximumMigrations < 1 || $maximumMigrations > 20) {
            throw new \InvalidArgumentException('Migration batch size is outside the safe bound.');
        }

        $lockToken = $this->acquireLock();

        if ($lockToken === null) {
            return new MigrationResult(false, 'migration_locked', []);
        }

        $applied = [];
        $current = (string) get_option(self::SCHEMA_OPTION, '0.0.0');
        $unappliedRemain = false;

        try {
            foreach ($this->migrations as $migration) {
                if (version_compare($migration->version(), $current, '<=')) {
                    continue;
                }

                if (count($applied) >= $maximumMigrations) {
                    $unappliedRemain = true;
                    break;
                }

                try {
                    $migration->up($this->database);
                    update_option(self::SCHEMA_OPTION, $migration->version(), false);
                    if ((string) get_option(self::SCHEMA_OPTION, '') !== $migration->version()) {
                        throw new \RuntimeException('Migration schema-version persistence could not be verified.');
                    }
                    delete_option(self::FAILURE_OPTION);
                    $current = $migration->version();
                    $applied[] = $current;
                } catch (\Throwable $error) {
                    $previousFailure = get_option(self::FAILURE_OPTION, []);
                    $attempts = is_array($previousFailure) && is_numeric($previousFailure['attempts'] ?? null)
                        ? min(1000, (int) $previousFailure['attempts'] + 1)
                        : 1;
                    update_option(
                        self::FAILURE_OPTION,
                        [
                            'code' => 'migration_failed',
                            'version' => $migration->version(),
                            'error_class' => get_class($error),
                            'attempts' => $attempts,
                            'recorded_at' => gmdate('Y-m-d H:i:s'),
                        ],
                        false
                    );

                    return new MigrationResult(false, 'migration_failed', $applied, $migration->version());
                }
            }

            if ($unappliedRemain) {
                return new MigrationResult(false, 'migration_incomplete', $applied);
            }

            return new MigrationResult(true, 'migration_ok', $applied);
        } finally {
            $this->releaseLock($lockToken);
        }
    }

    private function acquireLock(): ?string
    {
        $token = SecretGenerator::generate(24);
        $payload = ['token' => $token, 'acquired_at' => time()];

        if (add_option(self::LOCK_OPTION, $payload, '', false)) {
            return $token;
        }

        $existing = get_option(self::LOCK_OPTION, []);

        if (!is_array($existing) || !isset($existing['acquired_at']) || (int) $existing['acquired_at'] > time() - 300) {
            return null;
        }

        $expected = maybe_serialize($existing);
        $replacement = maybe_serialize($payload);
        $updated = $this->database->query($this->database->prepare(
            "UPDATE {$this->database->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $replacement,
            self::LOCK_OPTION,
            $expected
        ));
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::LOCK_OPTION, 'options');
        }
        $current = get_option(self::LOCK_OPTION, []);

        return $updated === 1
            && is_array($current)
            && isset($current['token'])
            && hash_equals($token, (string) $current['token'])
                ? $token
                : null;
    }

    private function releaseLock(string $token): void
    {
        $existing = get_option(self::LOCK_OPTION, []);

        if (is_array($existing) && isset($existing['token']) && hash_equals((string) $existing['token'], $token)) {
            $this->database->query($this->database->prepare(
                "DELETE FROM {$this->database->options} WHERE option_name = %s AND option_value = %s",
                self::LOCK_OPTION,
                maybe_serialize($existing)
            ));
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete(self::LOCK_OPTION, 'options');
            }
        }
    }
}
