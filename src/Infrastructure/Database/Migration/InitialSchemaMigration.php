<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

use Veyra\Infrastructure\Database\TableNames;

final class InitialSchemaMigration implements Migration
{
    public function version(): string
    {
        return '1.0.0';
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

        $tables = new TableNames($database->prefix);
        $collation = $database->get_charset_collate();

        $statements = $this->statements($tables, $collation);
        foreach ($statements as $statement) {
            dbDelta($statement);
        }
        SchemaPostconditionVerifier::verifyCreateStatements($database, $statements);
    }

    /** @return list<string> */
    private function statements(TableNames $tables, string $collation): array
    {
        $guestSessions = $tables->guestSessions();
        $conversations = $tables->conversations();
        $messages = $tables->messages();
        $journeys = $tables->journeys();
        $conversationFocus = $tables->conversationFocus();
        $pendingQuestions = $tables->pendingQuestions();
        $confirmations = $tables->confirmations();
        $idempotency = $tables->idempotency();
        $locks = $tables->locks();
        $audit = $tables->audit();

        return [
            "CREATE TABLE {$guestSessions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                token_digest char(64) NOT NULL,
                csrf_digest char(64) NOT NULL,
                actor_key varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                user_id bigint(20) unsigned NULL,
                status varchar(24) NOT NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                expires_at datetime NOT NULL,
                last_seen_at datetime NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY token_digest (token_digest),
                KEY actor_key_hash (actor_key_hash),
                KEY user_status (user_id,status),
                KEY expires_status (expires_at,status)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$conversations} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                user_id bigint(20) unsigned NULL,
                guest_session_id char(36) NULL,
                status varchar(24) NOT NULL,
                foreground_journey_id char(36) NULL,
                focus_json longtext NULL,
                memory_json longtext NULL,
                summary_json longtext NULL,
                configuration_version varchar(64) NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY actor_updated (actor_key_hash,updated_at),
                KEY user_updated (user_id,updated_at),
                KEY guest_updated (guest_session_id,updated_at),
                KEY status_updated (status,updated_at)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$messages} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                conversation_id char(36) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                sender_type varchar(24) NOT NULL,
                content_json longtext NOT NULL,
                render_json longtext NULL,
                language varchar(16) NULL,
                direction varchar(8) NULL,
                reply_to_message_id char(36) NULL,
                product_reference_json longtext NULL,
                status varchar(24) NOT NULL,
                rendering_schema_version varchar(32) NOT NULL,
                correlation_id char(36) NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY conversation_created (conversation_id,created_at),
                KEY actor_created (actor_key_hash,created_at),
                KEY correlation_id (correlation_id),
                KEY reply_to_message_id (reply_to_message_id)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$journeys} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                conversation_id char(36) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                journey_type varchar(64) NOT NULL,
                status varchar(24) NOT NULL,
                current_step varchar(96) NOT NULL,
                state_json longtext NOT NULL,
                dependencies_json longtext NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                last_checkpoint_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY conversation_status (conversation_id,status),
                KEY actor_status_updated (actor_key_hash,status,updated_at),
                KEY journey_type_status (journey_type,status)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$conversationFocus} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                conversation_id char(36) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                foreground_journey_id char(36) NULL,
                pending_question_id char(36) NULL,
                focused_resources_json longtext NULL,
                unresolved_references_json longtext NULL,
                expected_answer_schema_json longtext NULL,
                sensitivity varchar(32) NOT NULL,
                source_message_id char(36) NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                expires_at datetime NULL,
                invalidation_reason varchar(96) NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY conversation_id (conversation_id),
                KEY actor_updated (actor_key_hash,updated_at),
                KEY pending_question_id (pending_question_id)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$pendingQuestions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                conversation_id char(36) NOT NULL,
                journey_id char(36) NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                visible_message_id char(36) NOT NULL,
                question_type varchar(64) NOT NULL,
                expected_schema_json longtext NOT NULL,
                allowed_choices_json longtext NULL,
                resource_scope_json longtext NULL,
                sensitivity varchar(32) NOT NULL,
                state varchar(24) NOT NULL,
                dependency_hash char(64) NULL,
                dependency_versions_json longtext NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                expires_at datetime NULL,
                invalidation_reason varchar(96) NULL,
                answered_binding_id varchar(191) NULL,
                answered_message_id char(36) NULL,
                answer_binding_json longtext NULL,
                answered_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY conversation_state (conversation_id,state),
                KEY actor_state_updated (actor_key_hash,state,updated_at),
                KEY journey_state (journey_id,state),
                KEY visible_message_id (visible_message_id),
                UNIQUE KEY answered_binding_id (answered_binding_id)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$confirmations} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                token_digest char(64) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                session_id char(36) NULL,
                conversation_id char(36) NULL,
                journey_id char(36) NULL,
                action_key varchar(191) NOT NULL,
                resource_scope_json longtext NOT NULL,
                material_payload_json longtext NOT NULL,
                state_hash char(64) NOT NULL,
                summary_message_id char(36) NOT NULL,
                summary_version bigint(20) unsigned NOT NULL,
                acknowledgements_json longtext NULL,
                idempotency_scope varchar(191) NOT NULL,
                correlation_id char(36) NOT NULL,
                status varchar(24) NOT NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                expires_at datetime NOT NULL,
                consumed_at datetime NULL,
                invalidation_reason varchar(96) NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY token_digest (token_digest),
                KEY actor_status_expires (actor_key_hash,status,expires_at),
                KEY conversation_status (conversation_id,status),
                KEY journey_status (journey_id,status),
                KEY correlation_id (correlation_id)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$idempotency} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                key_digest char(64) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                action_key varchar(191) NOT NULL,
                action_key_hash char(64) NOT NULL,
                resource_scope_hash char(64) NOT NULL,
                payload_hash char(64) NOT NULL,
                status varchar(24) NOT NULL,
                result_code varchar(96) NULL,
                result_json longtext NULL,
                retry_safe tinyint(1) NOT NULL DEFAULT 0,
                correlation_id char(36) NOT NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                expires_at datetime NOT NULL,
                completed_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY idempotency_scope (actor_key_hash,action_key_hash,key_digest),
                KEY status_expires (status,expires_at),
                KEY correlation_id (correlation_id),
                KEY resource_scope_hash (resource_scope_hash)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$locks} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                resource_key_hash char(64) NOT NULL,
                owner_digest char(64) NOT NULL,
                correlation_id char(36) NOT NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                expires_at datetime NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY resource_key_hash (resource_key_hash),
                KEY expires_at (expires_at),
                KEY correlation_id (correlation_id)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$audit} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                action_key varchar(191) NOT NULL,
                target_type varchar(64) NOT NULL,
                target_id varchar(191) NULL,
                result_code varchar(96) NOT NULL,
                correlation_id char(36) NOT NULL,
                metadata_json longtext NULL,
                occurred_at datetime NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY actor_occurred (actor_key_hash,occurred_at),
                KEY target_occurred (target_type,target_id(96),occurred_at),
                KEY action_occurred (action_key(96),occurred_at),
                KEY correlation_id (correlation_id)
            ) ENGINE=InnoDB {$collation};",
        ];
    }
}
