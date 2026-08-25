<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

use Veyra\Infrastructure\Database\TableNames;

/** Adds recoverable dependency versions required for safe short-reply binding. */
final class ConversationIntegrityMigration implements Migration
{
    public function version(): string
    {
        return '1.2.0';
    }

    public function up(\wpdb $database): void
    {
        $table = (new TableNames($database->prefix))->pendingQuestions();
        $column = $database->get_var($database->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            'dependency_versions_json'
        ));
        if (is_string($column) && $column !== '') {
            return;
        }

        $result = $database->query(
            "ALTER TABLE {$table} ADD dependency_versions_json longtext NULL AFTER dependency_hash"
        );
        if ($result === false) {
            throw new \RuntimeException('Pending-question dependency-version migration failed.');
        }
    }
}
