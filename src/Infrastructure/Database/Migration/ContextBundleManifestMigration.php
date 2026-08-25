<?php
declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

use Veyra\Infrastructure\Database\TableNames;

/** Adds immutable metadata-only Context Bundle selection manifests. */
final class ContextBundleManifestMigration implements Migration
{
    public function version(): string
    {
        return '1.5.0';
    }

    public function up(\wpdb $database): void
    {
        if (!defined('ABSPATH')) {
            throw new \RuntimeException('WordPress is required to run Veyra migrations.');
        }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        if (!function_exists('dbDelta')) {
            throw new \RuntimeException('WordPress dbDelta is unavailable.');
        }
        $statements = $this->statements(
            new TableNames($database->prefix),
            $database->get_charset_collate()
        );
        foreach ($statements as $statement) {
            dbDelta($statement);
        }
        SchemaPostconditionVerifier::verifyCreateStatements($database, $statements);
    }

    /** @return list<string> */
    private function statements(TableNames $tables, string $collation): array
    {
        $manifests = $tables->contextBundleManifests();
        return [
            "CREATE TABLE {$manifests} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id varchar(64) NOT NULL,
                manifest_schema_version varchar(32) NOT NULL,
                bundle_schema_version varchar(32) NOT NULL,
                bundle_version bigint(20) unsigned NOT NULL,
                bundle_hash char(64) NOT NULL,
                metadata_hash char(64) NOT NULL,
                conversation_id char(36) NOT NULL,
                turn_message_id char(36) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                assembled_actor_type varchar(24) NOT NULL,
                actor_scope_id varchar(64) NOT NULL,
                site_scope_id varchar(64) NOT NULL,
                provider_route_id varchar(128) NOT NULL,
                route_manifest_version varchar(128) NOT NULL,
                purpose varchar(160) NOT NULL,
                transmission_authorized tinyint(1) NOT NULL DEFAULT 0,
                transmission_decision_code varchar(128) NOT NULL,
                source_accounting_json longtext NOT NULL,
                selection_manifest_json longtext NOT NULL,
                redactions_json longtext NOT NULL,
                actual_bytes bigint(20) unsigned NOT NULL,
                actual_items bigint(20) unsigned NOT NULL,
                assembled_at datetime NOT NULL,
                bundle_expires_at datetime NOT NULL,
                retention_expires_at datetime NULL,
                legal_hold tinyint(1) NOT NULL DEFAULT 0,
                erased_at datetime NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY bundle_hash (bundle_hash),
                KEY actor_created (actor_key_hash,created_at),
                KEY conversation_created (conversation_id,created_at),
                KEY turn_message_id (turn_message_id),
                KEY retention_due (legal_hold,retention_expires_at),
                KEY route_created (provider_route_id,created_at)
            ) ENGINE=InnoDB {$collation};",
        ];
    }
}
