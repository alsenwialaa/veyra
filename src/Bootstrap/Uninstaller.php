<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

use Veyra\Features\Infrastructure\WordPressFeatureConfigurationStore;
use Veyra\Identity\Infrastructure\RoleCapabilityInstaller;
use Veyra\Infrastructure\Database\Migration\Migrator;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Knowledge\Infrastructure\WordPressPublishedKnowledgeRepository;
use Veyra\Media\Infrastructure\ProtectedObjectEraser;
use Veyra\Privacy\RetentionService;
use Veyra\Recommendation\Infrastructure\WordPressPublishedRecommendationPolicyRepository;

final class Uninstaller
{
    /** @var list<string> */
    private const OPTIONS = [
        Activator::HEALTH_OPTION,
        Activator::DELETE_DATA_OPTION,
        Activator::MIGRATION_RETRY_OPTION,
        Migrator::SCHEMA_OPTION,
        Migrator::LOCK_OPTION,
        Migrator::FAILURE_OPTION,
        WordPressFeatureConfigurationStore::OPTION,
        WordPressFeatureConfigurationStore::CERTIFICATION_OPTION,
        'veyra_configuration_version',
        'veyra_provider_routes',
        'veyra_release_manifest',
        'veyra_provider_credentials_v1',
        'veyra_admin_product_configuration_v1',
        'veyra_provider_readiness_v1',
        'veyra_agent_published_v1',
        'veyra_knowledge_published_v1',
        'veyra_experience_published_v1',
        'veyra_commerce_published_v1',
        'veyra_payment_review_gateway_ids',
        WordPressPublishedKnowledgeRepository::OPTION,
        WordPressPublishedRecommendationPolicyRepository::OPTION,
        RetentionService::HEALTH_OPTION,
    ];

    public static function uninstall(): void
    {
        global $wpdb;

        Deactivator::deactivate();

        // Retention is fail-safe: customer/plugin data is preserved unless an
        // authorized merchant explicitly enabled deletion before uninstall.
        if (!self::deletionEnabled(get_option(Activator::DELETE_DATA_OPTION, false))) {
            // Explicit retention is already a complete data disposition. It is
            // now safe to remove plugin-only authorization from WordPress roles.
            RoleCapabilityInstaller::removeFromAllRoles();
            return;
        }
        if (!$wpdb instanceof \wpdb) {
            return;
        }

        $tables = new TableNames($wpdb->prefix);

        // Protected bytes must be deleted while the attachment table still
        // provides their driver/key inventory. A storage adapter acknowledges
        // an idempotent deletion through the filter below. Any unknown driver,
        // malformed row, query error, or failed deletion preserves metadata and
        // stops uninstall data deletion instead of orphaning private files.
        if (!self::deleteProtectedObjects($wpdb, $tables->attachments())) {
            return;
        }

        foreach ($tables->all() as $table) {
            // Table names originate only from the validated WordPress prefix and
            // the fixed Veyra table registry; no request value reaches this SQL.
            if ($wpdb->query("DROP TABLE IF EXISTS {$table}") === false
                || self::tableExists($wpdb, $table) !== false
            ) {
                return;
            }
        }

        if (!self::deleteOptions()) {
            return;
        }

        // Do not remove recovery authorization until protected objects,
        // plugin tables, and the fixed option allowlist have been disposed of.
        // On any earlier failure the capabilities remain available so an
        // operator can investigate or retry instead of being locked out of
        // the retained Veyra records.
        RoleCapabilityInstaller::removeFromAllRoles();
    }

    public static function deletionEnabled(mixed $value): bool
    {
        // WordPress returns database-backed non-string scalar options as their
        // string equivalents; a saved boolean true is normally read as "1".
        return in_array($value, [true, 1, '1'], true);
    }

    private static function deleteProtectedObjects(\wpdb $database, string $attachmentTable): bool
    {
        $pattern = method_exists($database, 'esc_like')
            ? $database->esc_like($attachmentTable)
            : addcslashes($attachmentTable, '_%\\');
        $found = $database->get_var($database->prepare('SHOW TABLES LIKE %s', $pattern));
        if (!is_string($found)) {
            return !isset($database->last_error) || trim((string) $database->last_error) === '';
        }
        if (!hash_equals($attachmentTable, $found)) {
            return false;
        }

        $cursor = 0;
        do {
            $rows = $database->get_results($database->prepare(
                "SELECT id, storage_driver, storage_key FROM {$attachmentTable} WHERE id > %d ORDER BY id ASC LIMIT %d",
                $cursor,
                100
            ), ARRAY_A);
            if (!is_array($rows)) {
                return false;
            }

            foreach ($rows as $row) {
                $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
                $driver = is_array($row) && is_string($row['storage_driver'] ?? null)
                    ? $row['storage_driver']
                    : '';
                $key = is_array($row) && is_string($row['storage_key'] ?? null)
                    ? $row['storage_key']
                    : '';
                if ($id <= $cursor
                    || preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $driver) !== 1
                    || $key === ''
                    || strlen($key) > 255
                ) {
                    return false;
                }

                /**
                 * Storage adapters return true only after the exact object is
                 * absent. Missing objects must also return true so retries are
                 * idempotent. The raw key is never sent to a client or log.
                 *
                 * @param bool   $deleted
                 * @param string $driver
                 * @param string $key
                 */
                $deleted = apply_filters(
                    'veyra_protected_storage_delete_on_uninstall',
                    null,
                    $driver,
                    $key
                );
                if ($deleted === null) {
                    $deleted = (new ProtectedObjectEraser())->delete($driver, $key);
                }
                if ($deleted !== true) {
                    return false;
                }
                $cursor = $id;
            }
        } while (count($rows) === 100);

        return true;
    }

    /** A DDL acknowledgement is not the uninstall postcondition. */
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

    /** Every fixed option must read back as absent before capabilities go away. */
    private static function deleteOptions(): bool
    {
        foreach (self::OPTIONS as $option) {
            delete_option($option);
            $missing = new \stdClass();
            if (get_option($option, $missing) !== $missing) {
                return false;
            }
        }

        return true;
    }
}
