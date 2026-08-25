<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

use Veyra\Infrastructure\Database\TableNames;

/** Adds the durable record required for one-time, atomic short-reply consumption. */
final class PendingQuestionBindingMigration implements Migration
{
    public function version(): string
    {
        return '1.3.0';
    }

    public function up(\wpdb $database): void
    {
        $table = (new TableNames($database->prefix))->pendingQuestions();
        $columns = [
            'answered_binding_id' => "ALTER TABLE {$table} ADD answered_binding_id varchar(191) NULL AFTER invalidation_reason",
            'answered_message_id' => "ALTER TABLE {$table} ADD answered_message_id char(36) NULL AFTER answered_binding_id",
            'answer_binding_json' => "ALTER TABLE {$table} ADD answer_binding_json longtext NULL AFTER answered_message_id",
        ];

        foreach ($columns as $column => $statement) {
            if (!$this->columnExists($database, $table, $column) && $database->query($statement) === false) {
                throw new \RuntimeException('Pending-question binding column migration failed: ' . $column);
            }
            if (!$this->columnExists($database, $table, $column)) {
                throw new \RuntimeException('Pending-question binding column postcondition failed: ' . $column);
            }
        }

        $index = $database->get_var($database->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            'answered_binding_id'
        ));
        if ((!is_string($index) || $index === '')
            && $database->query("ALTER TABLE {$table} ADD UNIQUE KEY answered_binding_id (answered_binding_id)") === false
        ) {
            throw new \RuntimeException('Pending-question binding index migration failed.');
        }
        $index = $database->get_var($database->prepare(
            "SHOW INDEX FROM {$table} WHERE Key_name = %s",
            'answered_binding_id'
        ));
        if (!is_string($index) || $index === '') {
            throw new \RuntimeException('Pending-question binding index postcondition failed.');
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
