<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

use Veyra\Features\Infrastructure\FeatureDefaultsInstaller;
use Veyra\Identity\Infrastructure\RoleCapabilityInstaller;
use Veyra\Infrastructure\Database\Migration\InitialSchemaMigration;
use Veyra\Infrastructure\Database\Migration\CommerceSchemaMigration;
use Veyra\Infrastructure\Database\Migration\ConversationIntegrityMigration;
use Veyra\Infrastructure\Database\Migration\PendingQuestionBindingMigration;
use Veyra\Infrastructure\Database\Migration\RequirementStateMigration;
use Veyra\Infrastructure\Database\Migration\ContextBundleManifestMigration;
use Veyra\Infrastructure\Database\Migration\ConversationFocusReferencesMigration;
use Veyra\Infrastructure\Database\Migration\Migrator;
use Veyra\Infrastructure\Database\Migration\MigrationRetryPolicy;

final class Activator
{
    public const HEALTH_OPTION = 'veyra_foundation_health';
    public const DELETE_DATA_OPTION = 'veyra_delete_data_on_uninstall';
    public const MIGRATION_RETRY_OPTION = 'veyra_migration_retry_state';
    public const MAX_AUTOMATIC_MIGRATION_ATTEMPTS = 8;

    public static function activate(bool $networkWide = false): void
    {
        if ($networkWide) {
            wp_die(
                esc_html__(
                    'Veyra does not support network-wide activation. Activate it separately on each WooCommerce site after verifying site-level requirements.',
                    'veyra-ai-commerce-agent'
                ),
                esc_html__('Veyra network activation blocked', 'veyra-ai-commerce-agent'),
                ['response' => 400, 'back_link' => true]
            );
            return;
        }
        self::install();
    }

    public static function resumeMigrations(): void
    {
        self::install();
    }

    /**
     * Upgrade discovery runs on ordinary requests, but schema work must not.
     * Schedule one bounded retry and let the existing migration hook perform
     * DDL outside the storefront request path.
     */
    public static function scheduleMigrationResume(?string $requiredSchema = null): bool
    {
        $requiredSchema ??= defined('VEYRA_SCHEMA_VERSION') ? (string) VEYRA_SCHEMA_VERSION : '0.0.0';
        $currentSchema = function_exists('get_option')
            ? (string) get_option(Migrator::SCHEMA_OPTION, '0.0.0')
            : '0.0.0';

        if (hash_equals($requiredSchema, $currentSchema)) {
            return true;
        }
        if (preg_match('/^[0-9]+(?:\.[0-9]+){2}(?:[-+][A-Za-z0-9.-]+)?$/D', $currentSchema) !== 1
            || preg_match('/^[0-9]+(?:\.[0-9]+){2}(?:[-+][A-Za-z0-9.-]+)?$/D', $requiredSchema) !== 1
            || version_compare($currentSchema, $requiredSchema, '>')
        ) {
            self::recordMigrationSchedulingState(
                false,
                true,
                'schema_version_incompatible',
                $currentSchema
            );
            return false;
        }
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            self::recordMigrationSchedulingState(
                false,
                true,
                'migration_retry_schedule_failed',
                $currentSchema
            );
            return false;
        }

        $health = get_option(self::HEALTH_OPTION, []);
        if (is_array($health) && ($health['manual_recovery_required'] ?? false) === true) {
            return false;
        }

        $attempts = 0;
        $retryPolicy = new MigrationRetryPolicy(self::MAX_AUTOMATIC_MIGRATION_ATTEMPTS);
        $persistedRetryState = get_option(self::MIGRATION_RETRY_OPTION, null);
        if ($persistedRetryState !== null) {
            if (!is_array($persistedRetryState) || !$retryPolicy->isValidPersistedState($persistedRetryState)) {
                self::recordMigrationSchedulingState(
                    false,
                    true,
                    'migration_retry_state_unverified',
                    $currentSchema
                );
                return false;
            }
            $attempts = $persistedRetryState['attempts'];
            if (hash_equals($persistedRetryState['schema_version'], $currentSchema)
                && $retryPolicy->exhausted($persistedRetryState)
            ) {
                self::recordMigrationSchedulingState(
                    false,
                    true,
                    'migration_retry_exhausted',
                    $currentSchema
                );
                return false;
            }
        }

        if (wp_next_scheduled('veyra_run_migrations') !== false) {
            return true;
        }

        $delay = min(3600, 60 * (2 ** min(6, max(0, $attempts - 1))));
        $scheduled = wp_schedule_single_event(time() + $delay, 'veyra_run_migrations') === true;
        self::recordMigrationSchedulingState(
            $scheduled,
            !$scheduled,
            $scheduled ? 'schema_migration_required' : 'migration_retry_schedule_failed',
            $currentSchema
        );

        return $scheduled;
    }

    private static function install(): void
    {
        global $wpdb;

        $report = (new RuntimeCompatibility(
            defined('VEYRA_MIN_PHP_VERSION') ? VEYRA_MIN_PHP_VERSION : '8.1.0',
            defined('VEYRA_MIN_WP_VERSION') ? VEYRA_MIN_WP_VERSION : '6.5.0',
            defined('VEYRA_MIN_WC_VERSION') ? VEYRA_MIN_WC_VERSION : '8.5.0'
        ))->evaluate(EnvironmentSnapshot::fromWordPress());

        if (!$report->foundationReady() || !$wpdb instanceof \wpdb) {
            update_option(
                self::HEALTH_OPTION,
                [
                    'state' => 'blocked',
                    'codes' => $report->codes(),
                    'checked_at' => gmdate('Y-m-d H:i:s'),
                ],
                false
            );

            return;
        }

        RoleCapabilityInstaller::install();
        FeatureDefaultsInstaller::install();
        add_option(self::DELETE_DATA_OPTION, false, '', false);

        $result = (new Migrator($wpdb, [
            new InitialSchemaMigration(),
            new CommerceSchemaMigration(),
            new ConversationIntegrityMigration(),
            new PendingQuestionBindingMigration(),
            new RequirementStateMigration(),
            new ContextBundleManifestMigration(),
            new ConversationFocusReferencesMigration(),
        ]))->migrate(20);
        $schemaVersion = (string) get_option(Migrator::SCHEMA_OPTION, '0.0.0');
        $requiredSchema = defined('VEYRA_SCHEMA_VERSION') ? (string) VEYRA_SCHEMA_VERSION : '0.0.0';
        $schemaVerified = $result->succeeded && hash_equals($requiredSchema, $schemaVersion);
        $state = $schemaVerified && $report->commerceReady() ? 'ready' : 'blocked';
        $codes = array_merge(
            $report->codes(),
            !$result->succeeded
                ? [$result->code]
                : ($schemaVerified ? [] : ['schema_version_incompatible'])
        );
        $retryAttempts = 0;
        $automaticRetryScheduled = false;
        $manualRecoveryRequired = false;
        $retentionScheduled = false;

        if ($schemaVerified) {
            delete_option(self::MIGRATION_RETRY_OPTION);
            $retentionScheduled = self::scheduleRetention();
            if (function_exists('wp_schedule_event') && !$retentionScheduled) {
                $codes[] = 'retention_schedule_failed';
                $state = 'blocked';
            }
        } elseif ($result->succeeded) {
            $manualRecoveryRequired = true;
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook('veyra_run_migrations');
            }
        } else {
            $retryPolicy = new MigrationRetryPolicy(self::MAX_AUTOMATIC_MIGRATION_ATTEMPTS);
            $persistedRetryState = get_option(self::MIGRATION_RETRY_OPTION, null);

            if ($persistedRetryState !== null && !$retryPolicy->isValidPersistedState($persistedRetryState)) {
                $manualRecoveryRequired = true;
                $codes[] = 'migration_retry_state_unverified';
                $codes[] = 'migration_retry_exhausted';
            } else {
                try {
                    $retryState = $retryPolicy->nextState(
                        is_array($persistedRetryState) ? $persistedRetryState : null,
                        $schemaVersion,
                        $result->code
                    );
                    $retryAttempts = $retryState['attempts'];
                    $storedRetryState = $retryState + ['recorded_at' => gmdate('Y-m-d H:i:s')];
                    update_option(self::MIGRATION_RETRY_OPTION, $storedRetryState, false);
                } catch (\Throwable) {
                    $manualRecoveryRequired = true;
                    $codes[] = 'migration_retry_state_unverified';
                    $codes[] = 'migration_retry_exhausted';
                    $storedRetryState = null;
                    $retryState = null;
                }

                if (!$manualRecoveryRequired
                    && (!is_array($storedRetryState)
                        || get_option(self::MIGRATION_RETRY_OPTION, null) !== $storedRetryState)
                ) {
                    $manualRecoveryRequired = true;
                    $codes[] = 'migration_retry_state_unverified';
                    $codes[] = 'migration_retry_exhausted';
                } elseif (is_array($retryState) && $retryPolicy->exhausted($retryState)) {
                    $manualRecoveryRequired = true;
                    $codes[] = 'migration_retry_exhausted';
                }
            }

            if ($manualRecoveryRequired) {
                if (function_exists('wp_clear_scheduled_hook')) {
                    wp_clear_scheduled_hook('veyra_run_migrations');
                }
            } elseif (wp_next_scheduled('veyra_run_migrations') !== false) {
                $automaticRetryScheduled = true;
            } else {
                $delay = min(3600, 60 * (2 ** min(6, max(0, $retryAttempts - 1))));
                $automaticRetryScheduled = wp_schedule_single_event(
                    time() + $delay,
                    'veyra_run_migrations'
                ) === true;
                if (!$automaticRetryScheduled) {
                    $manualRecoveryRequired = true;
                    $codes[] = 'migration_retry_schedule_failed';
                }
            }
        }

        update_option(
            self::HEALTH_OPTION,
            [
                'state' => $state,
                'codes' => array_values(array_unique($codes)),
                'schema_version' => $schemaVersion,
                'checked_at' => gmdate('Y-m-d H:i:s'),
                'activation_remote_calls' => 0,
                'migration_retry_attempts' => $retryAttempts,
                'automatic_migration_retry_scheduled' => $automaticRetryScheduled,
                'manual_recovery_required' => $manualRecoveryRequired,
                'retention_scheduled' => $retentionScheduled,
            ],
            false
        );
    }

    private static function scheduleRetention(): bool
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return false;
        }
        if (wp_next_scheduled('veyra_retention') !== false) {
            return true;
        }

        return wp_schedule_event(time() + 300, 'daily', 'veyra_retention') === true;
    }

    private static function recordMigrationSchedulingState(
        bool $scheduled,
        bool $manualRecoveryRequired,
        string $code,
        string $schemaVersion
    ): void {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        $health = get_option(self::HEALTH_OPTION, []);
        $health = is_array($health) ? $health : [];
        $codes = is_array($health['codes'] ?? null)
            ? array_values(array_filter($health['codes'], 'is_string'))
            : [];
        $codes = array_values(array_filter(
            $codes,
            static fn (string $existing): bool => !in_array($existing, [
                'schema_migration_required',
                'migration_retry_schedule_failed',
                'migration_retry_state_unverified',
                'migration_retry_exhausted',
                'schema_version_incompatible',
            ], true)
        ));
        $codes[] = $code;
        $health['state'] = 'blocked';
        $health['codes'] = array_values(array_unique($codes));
        $health['schema_version'] = $schemaVersion;
        $health['automatic_migration_retry_scheduled'] = $scheduled;
        $health['manual_recovery_required'] = $manualRecoveryRequired;
        $health['checked_at'] = gmdate('Y-m-d H:i:s');
        update_option(self::HEALTH_OPTION, $health, false);
    }
}
