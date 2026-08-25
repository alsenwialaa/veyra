<?php

declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\Conversation\Persistence\WpdbConversationStore;

final class PendingQuestionWpdbFixture
{
    public string $prefix = 'wp_';
    /** @var array<string, mixed> */
    public array $focus = [
        'id' => 4,
        'version' => 9,
        'pending_question_id' => 'pq_1',
    ];
    /** @var array<string, mixed> */
    public array $question = [
        'id' => 7,
        'version' => 3,
        'state' => 'active',
        'expires_at' => '2099-01-01 00:00:00',
        'invalidation_reason' => null,
    ];
    /** @var list<string> */
    public array $transactions = [];
    /** @var array<string, mixed>|null */
    public ?array $bindingWrite = null;

    public function prepare(string $query, mixed ...$arguments): string
    {
        foreach ($arguments as $argument) {
            $replacement = is_int($argument) ? (string) $argument : "'" . str_replace("'", "''", (string) $argument) . "'";
            $query = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
        }
        return $query;
    }

    public function query(string $query): int|false
    {
        if (in_array($query, ['START TRANSACTION', 'COMMIT', 'ROLLBACK'], true)) {
            $this->transactions[] = $query;
            return 1;
        }
        return false;
    }

    public function get_row(string $query, mixed $format = null): ?array
    {
        if (str_contains($query, 'veyra_conversation_focus')) {
            return $this->focus;
        }
        if (str_contains($query, 'veyra_pending_questions')) {
            return $this->question;
        }
        return null;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $where */
    public function update(string $table, array $data, array $where): int|false
    {
        if (str_ends_with($table, 'veyra_pending_questions')) {
            if ($this->question['state'] !== ($where['state'] ?? null)
                || $this->question['version'] !== ($where['version'] ?? null)
            ) {
                return 0;
            }
            $this->question = array_merge($this->question, $data);
            $this->bindingWrite = $data;
            return 1;
        }
        if (str_ends_with($table, 'veyra_conversation_focus')) {
            if ($this->focus['pending_question_id'] !== ($where['pending_question_id'] ?? null)
                || $this->focus['version'] !== ($where['version'] ?? null)
            ) {
                return 0;
            }
            $this->focus = array_merge($this->focus, $data);
            return 1;
        }
        return false;
    }
}

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        fwrite(STDOUT, "PASS {$message}\n");
        return;
    }
    ++$failed;
    fwrite(STDERR, "FAIL {$message}\n");
};

$database = new PendingQuestionWpdbFixture();
$store = new WpdbConversationStore($database);
$binding = [
    'proposed_value' => 'black',
    'validated_value' => 'black',
    'target_resource_ids' => ['product_7'],
    'validation_code' => 'binding_valid',
    'decision_id' => 'plan_1',
];
$first = $store->consumePendingQuestion(
    'conversation_1',
    'guest',
    'guest_1',
    'pq_1',
    '9',
    3,
    'msg_customer_1',
    $binding
);
$check($first['consumed'] && $first['code'] === 'pending_question_consumed', 'exact focus/question versions consume once');
$check($database->question['state'] === 'answered' && $database->focus['pending_question_id'] === null, 'question answer and focus advancement commit together');
$check($database->transactions === ['START TRANSACTION', 'COMMIT'], 'successful consumption uses one transaction');
$written = json_decode((string) ($database->bindingWrite['answer_binding_json'] ?? ''), true);
$check(is_array($written)
    && ($written['schema_version'] ?? null) === 'veyra.validated_answer_binding.v1'
    && ($written['customer_message_id'] ?? null) === 'msg_customer_1', 'durable binding record preserves validated source and contract');

$second = $store->consumePendingQuestion(
    'conversation_1',
    'guest',
    'guest_1',
    'pq_1',
    '9',
    3,
    'msg_customer_2',
    $binding
);
$check(!$second['consumed'] && $second['code'] === 'focus_version_conflict', 'replay cannot consume the already-advanced focus');
$check(array_slice($database->transactions, -2) === ['START TRANSACTION', 'ROLLBACK'], 'failed replay rolls back without a second binding');

$invalid = $store->consumePendingQuestion(
    'conversation_1',
    'guest',
    'guest_1',
    'pq_1',
    '10',
    4,
    'msg_customer_3',
    $binding + ['unexpected' => true]
);
$check(!$invalid['consumed'] && $invalid['code'] === 'binding_record_invalid', 'unbounded or extra binding fields fail before a transaction');

fwrite(STDOUT, "Pending Question consumption scenarios: {$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
