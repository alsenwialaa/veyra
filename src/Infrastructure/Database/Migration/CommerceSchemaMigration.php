<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

use Veyra\Infrastructure\Database\TableNames;

/**
 * Persistent records that cannot safely live in WooCommerce session state.
 * WooCommerce remains authoritative for products, carts, orders and payments.
 */
final class CommerceSchemaMigration implements Migration
{
    public function version(): string
    {
        return '1.1.0';
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
        $checkout = $tables->checkoutSessions();
        $cases = $tables->cases();
        $reviews = $tables->paymentReviews();
        $attachments = $tables->attachments();
        $revisions = $tables->configurationRevisions();
        $rateLimits = $tables->rateLimits();

        return [
            "CREATE TABLE {$checkout} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                conversation_id char(36) NOT NULL,
                journey_id char(36) NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                cart_hash char(64) NOT NULL,
                fulfillment_mode varchar(32) NULL,
                contact_json longtext NULL,
                billing_address_json longtext NULL,
                shipping_address_json longtext NULL,
                package_selection_json longtext NULL,
                payment_method_id varchar(191) NULL,
                totals_json longtext NULL,
                state_hash char(64) NOT NULL,
                status varchar(24) NOT NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                expires_at datetime NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY conversation_id (conversation_id),
                KEY actor_status_updated (actor_key_hash,status,updated_at),
                KEY journey_id (journey_id),
                KEY expires_status (expires_at,status)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$cases} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                conversation_id char(36) NOT NULL,
                order_id bigint(20) unsigned NULL,
                case_type varchar(64) NOT NULL,
                submission_status varchar(32) NOT NULL,
                decision_status varchar(32) NULL,
                execution_status varchar(32) NULL,
                request_json longtext NOT NULL,
                decision_json longtext NULL,
                execution_json longtext NULL,
                assigned_user_id bigint(20) unsigned NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY actor_updated (actor_key_hash,updated_at),
                KEY order_id (order_id),
                KEY workflow_status (submission_status,decision_status,execution_status),
                KEY assigned_user_id (assigned_user_id)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$reviews} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                conversation_id char(36) NOT NULL,
                order_id bigint(20) unsigned NOT NULL,
                case_id char(36) NULL,
                evidence_attachment_id char(36) NULL,
                submission_status varchar(32) NOT NULL,
                decision_status varchar(32) NULL,
                transition_status varchar(32) NULL,
                evidence_json longtext NOT NULL,
                decision_json longtext NULL,
                transition_json longtext NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY actor_updated (actor_key_hash,updated_at),
                KEY order_id (order_id),
                KEY workflow_status (submission_status,decision_status,transition_status),
                KEY case_id (case_id)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$attachments} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                actor_type varchar(24) NOT NULL,
                actor_id varchar(191) NOT NULL,
                actor_key_hash char(64) NOT NULL,
                conversation_id char(36) NOT NULL,
                message_id char(36) NULL,
                purpose varchar(64) NOT NULL,
                storage_driver varchar(32) NOT NULL,
                storage_key varchar(255) NOT NULL,
                mime_type varchar(127) NOT NULL,
                byte_size bigint(20) unsigned NOT NULL,
                checksum_sha256 char(64) NOT NULL,
                scan_status varchar(32) NOT NULL,
                status varchar(24) NOT NULL,
                version bigint(20) unsigned NOT NULL DEFAULT 1,
                expires_at datetime NOT NULL,
                deleted_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                UNIQUE KEY storage_key (storage_key(191)),
                KEY actor_status_updated (actor_key_hash,status,updated_at),
                KEY conversation_id (conversation_id),
                KEY expires_status (expires_at,status)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$revisions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                public_id char(36) NOT NULL,
                product_key varchar(64) NOT NULL,
                lifecycle_state varchar(24) NOT NULL,
                parent_public_id char(36) NULL,
                payload_json longtext NOT NULL,
                payload_hash char(64) NOT NULL,
                validation_json longtext NULL,
                created_by bigint(20) unsigned NOT NULL,
                activated_at datetime NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY public_id (public_id),
                KEY product_state_created (product_key,lifecycle_state,created_at),
                KEY payload_hash (payload_hash),
                KEY parent_public_id (parent_public_id)
            ) ENGINE=InnoDB {$collation};",
            "CREATE TABLE {$rateLimits} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                bucket_hash char(64) NOT NULL,
                window_key bigint(20) unsigned NOT NULL,
                counter bigint(20) unsigned NOT NULL DEFAULT 0,
                expires_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY bucket_window (bucket_hash,window_key),
                KEY expires_at (expires_at)
            ) ENGINE=InnoDB {$collation};",
        ];
    }
}
