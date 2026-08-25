<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

use Veyra\Infrastructure\Database\TableNames;

/** Persists the bounded unresolved-reference set carried by Conversation Focus. */
final class ConversationFocusReferencesMigration implements Migration
{
    public function version(): string
    {
        return '1.6.0';
    }

    public function up(\wpdb $database): void
    {
        $table = (new TableNames($database->prefix))->conversationFocus();
        $column = 'unresolved_references_json';
        if (!$this->columnExists($database, $table, $column)
            && $database->query(
                "ALTER TABLE {$table} ADD {$column} longtext NULL AFTER focused_resources_json"
            ) === false
        ) {
            throw new \RuntimeException('Conversation Focus unresolved-reference migration failed.');
        }

        if (!$this->columnExists($database, $table, $column)) {
            throw new \RuntimeException('Conversation Focus unresolved-reference postcondition failed.');
        }
    }

    private function columnExists(\wpdb $database, string $table, string $column): bool
    {
        $result = $database->get_var($database->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column
        ));

        return is_string($result) && $result !== '';
    }
}
