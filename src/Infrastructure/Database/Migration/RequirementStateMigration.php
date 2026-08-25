<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

use Veyra\Infrastructure\Database\TableNames;

/** Adds the actor-owned, versioned head for complete requirement history. */
final class RequirementStateMigration implements Migration
{
    public function version(): string
    {
        return '1.4.0';
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
        $requirementStates = $tables->requirementStates();

        return [
            "CREATE TABLE {$requirementStates} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                conversation_id char(36) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                state_json longtext NOT NULL,
                state_hash char(64) NOT NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                last_source_message_id char(36) NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY conversation_id (conversation_id),
                KEY actor_version (actor_key_hash,version)
            ) ENGINE=InnoDB {$collation};",
        ];
    }
}
