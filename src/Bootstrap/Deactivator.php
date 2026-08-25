<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

use Veyra\Infrastructure\Database\TableNames;

final class Deactivator
{
    /** @var list<string> */
    private const HOOKS = [
        'veyra_run_migrations',
        'veyra_housekeeping',
        'veyra_retention',
    ];

    public static function deactivate(): void
    {
        $cleanupSucceeded = true;

        foreach (self::HOOKS as $hook) {
            try {
                if (function_exists('wp_clear_scheduled_hook')) {
                    wp_clear_scheduled_hook($hook);
                }
                if (function_exists('as_unschedule_all_actions')) {
                    as_unschedule_all_actions($hook, [], 'veyra');
                }
                if (function_exists('wp_next_scheduled') && wp_next_scheduled($hook) !== false) {
                    $cleanupSucceeded = false;
                }
                if (function_exists('as_has_scheduled_action')
                    && as_has_scheduled_action($hook, [], 'veyra')
                ) {
                    $cleanupSucceeded = false;
                }
            } catch (\Throwable) {
                $cleanupSucceeded = false;
            }
        }

        $cleanupSucceeded = self::transitionRuntimeState() && $cleanupSucceeded;
        self::recordCleanupResult($cleanupSucceeded);
    }

    /**
     * Fence every mutation that was authorized before deactivation. Active
     * confirmations become unusable, unresolved idempotency claims become
     * explicitly uncertain, and only then are plugin-owned runtime locks
     * released. Merchant records are not deleted.
     */
    private static function transitionRuntimeState(): bool
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            return false;
        }

        try {
            $tables = new TableNames($wpdb->prefix);
            $now = gmdate('Y-m-d H:i:s');

            $confirmationTable = $tables->confirmations();
            $exists = self::tableExists($wpdb, $confirmationTable);
            if ($exists === null) {
                return false;
            }
            $confirmationFence = $wpdb->prepare(
                'UPDATE %i
                 SET status = %s, invalidation_reason = %s, updated_at = %s, version = version + 1
                 WHERE status = %s',
                $confirmationTable,
                'invalidated',
                'plugin_deactivated',
                $now,
                'active'
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The complete statement and %i identifier are prepared immediately above; deactivation must synchronously fence active sensitive mutations.
            if ($exists && $wpdb->query($confirmationFence) === false) {
                return false;
            }

            $idempotencyTable = $tables->idempotency();
            $exists = self::tableExists($wpdb, $idempotencyTable);
            if ($exists === null) {
                return false;
            }
            $idempotencyFence = $wpdb->prepare(
                'UPDATE %i
                 SET status = %s, result_code = %s, result_json = NULL, retry_safe = %d,
                     completed_at = %s, updated_at = %s, version = version + 1
                 WHERE status = %s',
                $idempotencyTable,
                'uncertain',
                'plugin_deactivated_during_execution',
                0,
                $now,
                $now,
                'in_progress'
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The complete statement and %i identifier are prepared immediately above; deactivation must persist uncertain outcomes before releasing locks.
            if ($exists && $wpdb->query($idempotencyFence) === false) {
                return false;
            }

            $lockTable = $tables->locks();
            $exists = self::tableExists($wpdb, $lockTable);
            if ($exists === null) {
                return false;
            }
            $releaseLocks = $wpdb->prepare('DELETE FROM %i', $lockTable);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The fixed-registry %i identifier is prepared immediately above; plugin-owned locks may be released only after both mutation fences succeed.
            if ($exists && $wpdb->query($releaseLocks) === false) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** null means the existence check itself failed. */
    private static function tableExists(\wpdb $database, string $table): ?bool
    {
        $pattern = method_exists($database, 'esc_like')
            ? $database->esc_like($table)
            : addcslashes($table, '_%\\');
        $found = $database->get_var($database->prepare('SHOW TABLES LIKE %s', $pattern));
        if (is_string($found)) {
            return hash_equals($table, $found);
        }

        return trim((string) ($database->last_error ?? '')) === '' ? false : null;
    }

    private static function recordCleanupResult(bool $succeeded): void
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        $health = get_option(Activator::HEALTH_OPTION, []);
        $health = is_array($health) ? $health : [];
        $codes = is_array($health['codes'] ?? null)
            ? array_values(array_filter($health['codes'], 'is_string'))
            : [];
        $codes = array_values(array_filter(
            $codes,
            static fn (string $code): bool => $code !== 'deactivation_runtime_cleanup_failed'
        ));
        if (!$succeeded) {
            $codes[] = 'deactivation_runtime_cleanup_failed';
            $health['state'] = 'blocked';
        }

        $health['codes'] = array_values(array_unique($codes));
        $health['deactivation_cleanup_succeeded'] = $succeeded;
        $health['deactivated_at'] = gmdate('Y-m-d H:i:s');
        update_option(Activator::HEALTH_OPTION, $health, false);
    }
}
