<?php

declare(strict_types=1);

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\Confirmation\Application\IdempotencyRepository;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Domain\IdempotencyRecord;
use Veyra\CRM\Infrastructure\WpdbCaseRepository;
use Veyra\CRM\Tool\CrmToolHandler;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Support\FrozenClock;
use Veyra\Tests\Support\InMemoryIdempotencyRepository;

if (!class_exists('WC_Order')) {
    class WC_Order
    {
        public function __construct(
            private int $customerId,
            private string $status = 'processing'
        ) {
        }

        public function get_customer_id(): int { return $this->customerId; }
        public function get_status(): string { return $this->status; }
    }
}

/** @var array<int, WC_Order> $veyraCrmOrders */
$veyraCrmOrders = [];

function wc_get_order(int $orderId): ?WC_Order
{
    global $veyraCrmOrders;
    return $veyraCrmOrders[$orderId] ?? null;
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!class_exists('wpdb')) {
    final class wpdb
    {
        /** @var array<string, array<string, mixed>> */
        public array $rows = [];
        /** @var list<int> */
        public array $failedReadNumbers = [];
        public int $insertCount = 0;
        public int $updateCount = 0;
        public int $readCount = 0;
        /** @var list<mixed> */
        private array $preparedArguments = [];

        public function prepare(string $query, mixed ...$arguments): string
        {
            $this->preparedArguments = $arguments;
            return $query;
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            ++$this->insertCount;
            $this->rows[(string) $data['public_id']] = $data;
            return 1;
        }

        /** @return array<string, mixed>|null */
        public function get_row(string $query, string $output): ?array
        {
            ++$this->readCount;
            if (in_array($this->readCount, $this->failedReadNumbers, true)) {
                return null;
            }
            $caseId = (string) ($this->preparedArguments[0] ?? '');
            $row = $this->rows[$caseId] ?? null;
            if (!is_array($row)
                || ($row['actor_type'] ?? null) !== ($this->preparedArguments[1] ?? null)
                || ($row['actor_id'] ?? null) !== ($this->preparedArguments[2] ?? null)
                || ($row['actor_key_hash'] ?? null) !== ($this->preparedArguments[3] ?? null)
            ) {
                return null;
            }
            return $row;
        }

        public function query(string $query): int
        {
            $caseId = (string) ($this->preparedArguments[2] ?? '');
            $row = $this->rows[$caseId] ?? null;
            $expectedVersion = (int) ($this->preparedArguments[6] ?? 0);
            if (!is_array($row)
                || ($row['actor_type'] ?? null) !== ($this->preparedArguments[3] ?? null)
                || ($row['actor_id'] ?? null) !== ($this->preparedArguments[4] ?? null)
                || ($row['actor_key_hash'] ?? null) !== ($this->preparedArguments[5] ?? null)
                || ($row['submission_status'] ?? null) !== 'draft'
                || (int) ($row['version'] ?? 0) !== $expectedVersion
            ) {
                return 0;
            }
            ++$this->updateCount;
            $row['request_json'] = (string) $this->preparedArguments[0];
            $row['updated_at'] = (string) $this->preparedArguments[1];
            $row['version'] = $expectedVersion + 1;
            $this->rows[$caseId] = $row;
            return 1;
        }

        /** @return list<array<string, mixed>> */
        public function get_results(string $query, string $output): array
        {
            return [];
        }

        public function get_var(string $query): mixed
        {
            return null;
        }

        /** @param array<string, mixed> $request */
        public function seedCase(
            string $caseId,
            \Veyra\AI\Tool\ToolContext $context,
            array $request,
            int $version = 1
        ): void {
            $this->rows[$caseId] = [
                'public_id' => $caseId,
                'actor_type' => $context->actorType,
                'actor_id' => $context->actorId,
                'actor_key_hash' => hash('sha256', $context->actorType . ':' . $context->actorId),
                'conversation_id' => $context->conversationId,
                'order_id' => null,
                'case_type' => 'other',
                'submission_status' => 'draft',
                'decision_status' => null,
                'execution_status' => null,
                'request_json' => json_encode($request, JSON_THROW_ON_ERROR),
                'decision_json' => null,
                'execution_json' => null,
                'assigned_user_id' => null,
                'version' => $version,
                'created_at' => '2026-08-24 10:00:00',
                'updated_at' => '2026-08-24 10:00:00',
            ];
        }
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$scenario = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        ++$passed;
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
};

$context = static fn (): ToolContext => new ToolContext(
    'customer',
    'wp-user-42',
    42,
    null,
    '11111111-1111-4111-8111-111111111111',
    [],
    ['service_crm' => 'On'],
    'en_US',
    '22222222-2222-4222-8222-222222222222'
);

$handler = static function (wpdb $database): CrmToolHandler {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    return new CrmToolHandler(
        new WpdbCaseRepository($database, new TableNames('wp_')),
        new IdempotencyService(
            new InMemoryIdempotencyRepository(),
            new SecretDigester(str_repeat('k', 32)),
            $clock
        ),
        new FoundationActorMapper()
    );
};

$scenario('created CRM draft is reconciled after the first post-write read fails', static function () use ($assert, $context, $handler): void {
    $database = new wpdb();
    $database->failedReadNumbers = [1];
    $tools = $handler($database);
    $arguments = [
        'case_type' => 'other',
        'subject' => 'Delivery question',
        'description' => 'Please review this delivery request.',
        'idempotency_key' => 'crm-create-reconcile-0001',
    ];

    $result = $tools->execute(new ToolCall('call-create-1', 'crm.create_draft', '1.0.0', $arguments), $context());
    $assert($result->status === 'succeeded', 'Known create was not reconciled to success.');
    $assert(($result->data['reconciled_after_write'] ?? false) === true, 'Reconciled create was not identified.');
    $assert((int) ($result->data['case']['version'] ?? 0) === 1, 'Reconciled create did not preserve version 1.');
    $replay = $tools->execute(new ToolCall('call-create-2', 'crm.create_draft', '1.0.0', $arguments), $context());
    $assert($replay->status === 'succeeded', 'Reconciled create did not replay as success.');
    $assert($database->insertCount === 1, 'Reconciled create replayed the database insert.');
});

$scenario('unresolved CRM create remains uncertain and non-retry-safe', static function () use ($assert, $context, $handler): void {
    $database = new wpdb();
    $database->failedReadNumbers = [1, 2];
    $tools = $handler($database);
    $arguments = [
        'case_type' => 'other',
        'subject' => 'Payment question',
        'description' => 'Please review this payment request.',
        'idempotency_key' => 'crm-create-uncertain-0001',
    ];

    $result = $tools->execute(new ToolCall('call-uncertain-1', 'crm.create_draft', '1.0.0', $arguments), $context());
    $assert($result->status === 'uncertain', 'Unresolved known write did not remain uncertain.');
    $assert($result->code === 'case_write_reconciliation_required', 'Unresolved known write used the wrong result code.');
    $assert($result->retrySafe === false, 'Unresolved known write was marked retry-safe.');
    $assert(($result->data['reconciliation_required'] ?? false) === true, 'Reconciliation requirement was omitted.');
    $assert(is_string($result->data['case_id'] ?? null), 'Known case ID was not preserved.');
    $assert(($result->data['known_version'] ?? null) === 1, 'Known create version was not preserved.');
    $replay = $tools->execute(new ToolCall('call-uncertain-2', 'crm.create_draft', '1.0.0', $arguments), $context());
    $assert($replay->status === 'uncertain', 'An unresolved write retry did not require reconciliation.');
    $assert($replay->retrySafe === false, 'An unresolved write retry was marked retry-safe.');
    $assert(($replay->data['case_id'] ?? null) === ($result->data['case_id'] ?? null), 'An unresolved write retry lost the known case ID.');
    $assert(($replay->data['known_version'] ?? null) === 1, 'An unresolved write retry lost the known version.');
    $assert($database->insertCount === 1, 'An unresolved write retry duplicated the insert.');
});

$scenario('updated CRM draft is reconciled by known ID and next version', static function () use ($assert, $context, $handler): void {
    $database = new wpdb();
    $caseId = '33333333-3333-4333-8333-333333333333';
    $database->seedCase($caseId, $context(), [
        'subject' => 'Original subject',
        'description' => 'Original description',
        'messages' => [],
        'evidence_attachment_ids' => [],
    ]);
    $database->failedReadNumbers = [2];
    $tools = $handler($database);
    $result = $tools->execute(new ToolCall('call-update-1', 'crm.update_customer_case', '1.0.0', [
        'case_id' => $caseId,
        'expected_version' => 1,
        'subject' => 'Corrected subject',
        'idempotency_key' => 'crm-update-reconcile-0001',
    ]), $context());

    $assert($result->status === 'succeeded', 'Known update was not reconciled to success.');
    $assert(($result->data['reconciled_after_write'] ?? false) === true, 'Reconciled update was not identified.');
    $assert(($result->data['case']['case_id'] ?? null) === $caseId, 'Reconciled update changed case identity.');
    $assert(($result->data['case']['version'] ?? null) === 2, 'Reconciled update did not preserve the known next version.');
    $assert(($result->data['case']['request']['subject'] ?? null) === 'Corrected subject', 'Reconciled update did not return authoritative current data.');
    $assert($database->updateCount === 1, 'Reconciled update executed more than once.');
});

$scenario('unresolved CRM update preserves the known next version without retrying', static function () use ($assert, $context, $handler): void {
    $database = new wpdb();
    $caseId = '44444444-4444-4444-8444-444444444444';
    $database->seedCase($caseId, $context(), [
        'subject' => 'Original subject',
        'description' => 'Original description',
        'messages' => [],
        'evidence_attachment_ids' => [],
    ]);
    $database->failedReadNumbers = [2, 3];
    $tools = $handler($database);
    $arguments = [
        'case_id' => $caseId,
        'expected_version' => 1,
        'description' => 'Corrected description',
        'idempotency_key' => 'crm-update-uncertain-0001',
    ];

    $result = $tools->execute(new ToolCall('call-update-uncertain-1', 'crm.update_customer_case', '1.0.0', $arguments), $context());
    $assert($result->status === 'uncertain', 'Unresolved update did not remain uncertain.');
    $assert($result->retrySafe === false, 'Unresolved update was marked retry-safe.');
    $assert(($result->data['case_id'] ?? null) === $caseId, 'Unresolved update lost the known case ID.');
    $assert(($result->data['known_version'] ?? null) === 2, 'Unresolved update lost the known next version.');
    $replay = $tools->execute(new ToolCall('call-update-uncertain-2', 'crm.update_customer_case', '1.0.0', $arguments), $context());
    $assert($replay->status === 'uncertain', 'An unresolved update retry did not require reconciliation.');
    $assert(($replay->data['case_id'] ?? null) === $caseId, 'An unresolved update retry lost the known case ID.');
    $assert(($replay->data['known_version'] ?? null) === 2, 'An unresolved update retry lost the known next version.');
    $assert($database->updateCount === 1, 'An unresolved update retry duplicated the database update.');
});

$scenario('CRM messages reject whitespace before claiming or mutating', static function () use ($assert, $context, $handler): void {
    $database = new wpdb();
    $caseId = '55555555-5555-4555-8555-555555555555';
    $database->seedCase($caseId, $context(), [
        'subject' => 'Existing subject',
        'description' => 'Existing description',
        'messages' => [],
        'evidence_attachment_ids' => [],
    ]);
    $result = $handler($database)->execute(new ToolCall('call-empty-message', 'crm.add_customer_message', '1.0.0', [
        'case_id' => $caseId,
        'expected_version' => 1,
        'message' => " \t\n ",
        'idempotency_key' => 'crm-empty-message-0001',
    ]), $context());

    $assert($result->status === 'failed' && $result->code === 'case_message_invalid', 'Whitespace-only CRM text was not rejected explicitly.');
    $assert($database->readCount === 0 && $database->updateCount === 0, 'Invalid CRM text reached persistence.');
});

$scenario('CRM order references exclude internal Woo checkout drafts', static function () use ($assert, $context, $handler): void {
    global $veyraCrmOrders;
    $veyraCrmOrders = [7001 => new WC_Order(42, 'checkout-draft')];
    $database = new wpdb();
    $result = $handler($database)->execute(new ToolCall('call-hidden-order', 'crm.create_draft', '1.0.0', [
        'case_type' => 'order_help',
        'subject' => 'Hidden draft',
        'description' => 'This must not bind to an internal block-checkout order.',
        'order_id' => 7001,
        'idempotency_key' => 'crm-hidden-order-0001',
    ]), $context());

    $assert($result->status !== 'succeeded', 'An internal checkout draft became a customer CRM resource.');
    $assert($database->insertCount === 0, 'A CRM draft persisted an internal checkout-draft reference.');
});

$scenario('CRM never reports a terminal conflict when idempotency failure persistence is uncertain', static function () use ($assert, $context): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $records = new class implements IdempotencyRepository {
        private ?IdempotencyRecord $record = null;
        public function insert(IdempotencyRecord $record): bool
        {
            if ($this->record !== null) {
                return false;
            }
            $this->record = $record;
            return true;
        }
        public function find(ActorScope $actor, string $action, string $keyDigest): ?IdempotencyRecord
        {
            unset($actor, $action, $keyDigest);
            return $this->record;
        }
        public function complete(
            IdempotencyRecord $record,
            string $status,
            string $resultCode,
            array $result,
            bool $retrySafe,
            UtcInstant $completedAt
        ): bool {
            unset($record, $status, $resultCode, $result, $retrySafe, $completedAt);
            return false;
        }
    };
    $handler = new CrmToolHandler(
        new WpdbCaseRepository(new wpdb(), new TableNames('wp_')),
        new IdempotencyService($records, new SecretDigester(str_repeat('k', 32)), $clock),
        new FoundationActorMapper()
    );
    $result = $handler->execute(new ToolCall('call-idem-transition', 'crm.create_draft', '1.0.0', [
        'case_type' => 'not-approved',
        'subject' => 'Invalid type',
        'description' => 'This validation failure cannot be durably finalized.',
        'idempotency_key' => 'crm-idem-transition-0001',
    ]), $context());

    $assert($result->status === 'uncertain', 'An unpersisted idempotency transition was reported as terminal.');
    $assert($result->code === 'case_idempotency_failure_transition_uncertain', 'The CRM uncertainty reason was not explicit.');
    $assert($result->retrySafe === false, 'An uncertain CRM transition was marked retry-safe.');
});

fwrite(STDOUT, sprintf("CRM write-reconciliation scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
