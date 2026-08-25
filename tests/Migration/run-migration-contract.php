<?php

declare(strict_types=1);

/** @var array<string, mixed> $veyraMigrationOptions */
$veyraMigrationOptions = [];
$veyraMigrationFailSchemaWrite = false;
$veyraMigrationScheduled = false;
$veyraMigrationScheduleCalls = 0;
$veyraMigrationClearCalls = 0;
$veyraWpDieCalls = [];
$wp_version = '6.8.0';

if (!defined('WC_VERSION')) {
    define('WC_VERSION', '10.0.0');
}
if (!defined('VEYRA_SCHEMA_VERSION')) {
    define('VEYRA_SCHEMA_VERSION', '1.6.0');
}

class wpdb
{
    public string $options = 'wp_options';
    public string $prefix = 'wp_';

    /**
     * @param array<string,list<string>> $columns
     * @param array<string,array<string,array{unique:bool,columns:list<string>}>> $indexes
     * @param array<string,string> $engines
     */
    public function __construct(
        public array $columns = [],
        public array $indexes = [],
        public array $engines = [],
        public bool $indexesReadable = true,
        public bool $enginesReadable = true
    ) {
    }

    public function get_col(string $query, int $column = 0): array|false
    {
        if (!preg_match('/SHOW COLUMNS FROM `?([A-Za-z0-9_]+)`?/', $query, $matches)) {
            return false;
        }
        return $this->columns[$matches[1]] ?? [];
    }

    public function get_results(string $query): array|false
    {
        if (!$this->indexesReadable || !preg_match('/SHOW INDEX FROM `?([A-Za-z0-9_]+)`?/', $query, $matches)) {
            return false;
        }

        $rows = [];
        foreach ($this->indexes[$matches[1]] ?? [] as $name => $index) {
            foreach ($index['columns'] as $position => $column) {
                $rows[] = (object) [
                    'Key_name' => $name,
                    'Non_unique' => $index['unique'] ? 0 : 1,
                    'Seq_in_index' => $position + 1,
                    'Column_name' => $column,
                ];
            }
        }

        return $rows;
    }

    public function get_row(string $query): object|array|false|null
    {
        if (!$this->enginesReadable
            || !preg_match("/SHOW TABLE STATUS WHERE Name = '([A-Za-z0-9_]+)'/", $query, $matches)
        ) {
            return false;
        }
        if (!isset($this->engines[$matches[1]])) {
            return null;
        }

        return (object) ['Engine' => $this->engines[$matches[1]]];
    }

    public function get_var(string $query): mixed
    {
        if (preg_match("/SHOW COLUMNS FROM ([A-Za-z0-9_]+) LIKE '([A-Za-z0-9_]+)'/", $query, $matches)) {
            return in_array($matches[2], $this->columns[$matches[1]] ?? [], true) ? $matches[2] : null;
        }
        if (preg_match("/SHOW INDEX FROM ([A-Za-z0-9_]+) WHERE Key_name = '([A-Za-z0-9_]+)'/", $query, $matches)) {
            return isset($this->indexes[$matches[1]][$matches[2]]) ? $matches[2] : null;
        }
        return null;
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        foreach ($arguments as $argument) {
            $quoted = "'" . str_replace("'", "''", (string) $argument) . "'";
            $query = preg_replace('/%s/', $quoted, $query, 1) ?? $query;
        }
        return $query;
    }

    public function query(string $query): int|false
    {
        global $veyraMigrationOptions;
        if (str_starts_with($query, 'DELETE FROM')) {
            unset($veyraMigrationOptions['veyra_migration_lock']);
            return 1;
        }
        if (preg_match('/^ALTER TABLE ([A-Za-z0-9_]+) ADD UNIQUE KEY ([A-Za-z0-9_]+) \(([A-Za-z0-9_]+)\)$/i', $query, $matches)) {
            $this->indexes[$matches[1]][$matches[2]] = ['unique' => true, 'columns' => [$matches[3]]];
            return 1;
        }
        if (preg_match('/^ALTER TABLE ([A-Za-z0-9_]+) ADD ([A-Za-z0-9_]+)\s+/i', $query, $matches)) {
            $this->columns[$matches[1]] ??= [];
            if (!in_array($matches[2], $this->columns[$matches[1]], true)) {
                $this->columns[$matches[1]][] = $matches[2];
            }
            return 1;
        }
        return 0;
    }
}

function add_option(string $name, mixed $value, string $deprecated = '', bool $autoload = false): bool
{
    global $veyraMigrationOptions;
    if (array_key_exists($name, $veyraMigrationOptions)) {
        return false;
    }
    $veyraMigrationOptions[$name] = $value;
    return true;
}

function get_option(string $name, mixed $default = false): mixed
{
    global $veyraMigrationOptions;
    return array_key_exists($name, $veyraMigrationOptions) ? $veyraMigrationOptions[$name] : $default;
}

function update_option(string $name, mixed $value, bool $autoload = false): bool
{
    global $veyraMigrationOptions, $veyraMigrationFailSchemaWrite;
    if ($name === 'veyra_schema_version' && $veyraMigrationFailSchemaWrite) {
        return false;
    }
    $veyraMigrationOptions[$name] = $value;
    return true;
}

function delete_option(string $name): bool
{
    global $veyraMigrationOptions;
    if (!array_key_exists($name, $veyraMigrationOptions)) {
        return false;
    }
    unset($veyraMigrationOptions[$name]);
    return true;
}

function maybe_serialize(mixed $value): string
{
    return is_scalar($value) ? (string) $value : serialize($value);
}

function wp_cache_delete(string $key, string $group = ''): bool { return true; }

function get_role(string $role): mixed { return null; }

function wp_next_scheduled(string $hook): int|false
{
    global $veyraMigrationScheduled;
    return $veyraMigrationScheduled ? time() + 60 : false;
}

function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
{
    global $veyraMigrationScheduled, $veyraMigrationScheduleCalls;
    $veyraMigrationScheduled = true;
    ++$veyraMigrationScheduleCalls;
    return true;
}

function wp_clear_scheduled_hook(string $hook): int|false
{
    global $veyraMigrationScheduled, $veyraMigrationClearCalls;
    $veyraMigrationScheduled = false;
    ++$veyraMigrationClearCalls;
    return 1;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return $text;
}

function wp_die(mixed $message = '', mixed $title = '', mixed $args = []): never
{
    global $veyraWpDieCalls;
    $veyraWpDieCalls[] = ['message' => $message, 'title' => $title, 'args' => $args];
    throw new VeyraNetworkActivationBlocked((string) $message);
}

final class VeyraNetworkActivationBlocked extends RuntimeException
{
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\Infrastructure\Database\Migration\Migration;
use Veyra\Infrastructure\Database\Migration\InitialSchemaMigration;
use Veyra\Infrastructure\Database\Migration\CommerceSchemaMigration;
use Veyra\Infrastructure\Database\Migration\MigrationRetryPolicy;
use Veyra\Infrastructure\Database\Migration\Migrator;
use Veyra\Infrastructure\Database\Migration\PendingQuestionBindingMigration;
use Veyra\Infrastructure\Database\Migration\RequirementStateMigration;
use Veyra\Infrastructure\Database\Migration\ContextBundleManifestMigration;
use Veyra\Infrastructure\Database\Migration\ConversationFocusReferencesMigration;
use Veyra\Infrastructure\Database\Migration\SchemaPostconditionVerifier;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Bootstrap\Activator;

$passed = 0;
$failed = 0;
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
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$scenario('CREATE migration verifies columns, index uniqueness/order, and InnoDB', static function () use ($assert): void {
    $statement = "CREATE TABLE wp_veyra_test (\n id bigint NOT NULL,\n public_id char(36) NOT NULL,\n status varchar(24) NOT NULL,\n PRIMARY KEY  (id),\n UNIQUE KEY public_id (public_id),\n KEY public_status (public_id,status(12))\n) ENGINE=InnoDB;";
    $columns = ['wp_veyra_test' => ['id', 'public_id', 'status']];
    $indexes = ['wp_veyra_test' => [
        'PRIMARY' => ['unique' => true, 'columns' => ['id']],
        'public_id' => ['unique' => true, 'columns' => ['public_id']],
        'public_status' => ['unique' => false, 'columns' => ['public_id', 'status']],
    ]];
    $engines = ['wp_veyra_test' => 'InnoDB'];
    SchemaPostconditionVerifier::verifyCreateStatements(new wpdb([
        'wp_veyra_test' => ['id', 'public_id', 'status'],
    ], $indexes, $engines), [$statement]);

    $thrown = false;
    try {
        SchemaPostconditionVerifier::verifyCreateStatements(new wpdb(
            ['wp_veyra_test' => ['id', 'public_id']],
            $indexes,
            $engines
        ), [$statement]);
    } catch (RuntimeException) {
        $thrown = true;
    }
    $assert($thrown, 'Missing migration column allowed schema completion.');

    $wrongUniqueness = $indexes;
    $wrongUniqueness['wp_veyra_test']['public_id']['unique'] = false;
    $thrown = false;
    try {
        SchemaPostconditionVerifier::verifyCreateStatements(
            new wpdb($columns, $wrongUniqueness, $engines),
            [$statement]
        );
    } catch (RuntimeException) {
        $thrown = true;
    }
    $assert($thrown, 'Non-unique index satisfied a required unique index.');

    $wrongOrder = $indexes;
    $wrongOrder['wp_veyra_test']['public_status']['columns'] = ['status', 'public_id'];
    $thrown = false;
    try {
        SchemaPostconditionVerifier::verifyCreateStatements(new wpdb($columns, $wrongOrder, $engines), [$statement]);
    } catch (RuntimeException) {
        $thrown = true;
    }
    $assert($thrown, 'Wrong composite-index column order satisfied the postcondition.');

    $missingIndex = $indexes;
    unset($missingIndex['wp_veyra_test']['public_status']);
    $thrown = false;
    try {
        SchemaPostconditionVerifier::verifyCreateStatements(new wpdb($columns, $missingIndex, $engines), [$statement]);
    } catch (RuntimeException) {
        $thrown = true;
    }
    $assert($thrown, 'Missing declared index satisfied the postcondition.');

    $thrown = false;
    try {
        SchemaPostconditionVerifier::verifyCreateStatements(
            new wpdb($columns, $indexes, ['wp_veyra_test' => 'MyISAM']),
            [$statement]
        );
    } catch (RuntimeException) {
        $thrown = true;
    }
    $assert($thrown, 'Wrong table engine satisfied the InnoDB postcondition.');
});

$scenario('CREATE migration fails closed when index or engine state is unreadable', static function () use ($assert): void {
    $statement = "CREATE TABLE wp_veyra_test (\n id bigint NOT NULL,\n PRIMARY KEY  (id)\n) ENGINE=InnoDB;";
    $columns = ['wp_veyra_test' => ['id']];
    $indexes = ['wp_veyra_test' => [
        'PRIMARY' => ['unique' => true, 'columns' => ['id']],
    ]];
    $engines = ['wp_veyra_test' => 'InnoDB'];

    $indexThrown = false;
    try {
        SchemaPostconditionVerifier::verifyCreateStatements(
            new wpdb($columns, $indexes, $engines, false, true),
            [$statement]
        );
    } catch (RuntimeException) {
        $indexThrown = true;
    }
    $assert($indexThrown, 'Unreadable index state allowed schema completion.');

    $engineThrown = false;
    try {
        SchemaPostconditionVerifier::verifyCreateStatements(
            new wpdb($columns, $indexes, $engines, true, false),
            [$statement]
        );
    } catch (RuntimeException) {
        $engineThrown = true;
    }
    $assert($engineThrown, 'Unreadable engine state allowed schema completion.');
});

$scenario('all checked-in CREATE statements are postcondition-verifiable', static function (): void {
    $statements = [];
    foreach ([
        new InitialSchemaMigration(),
        new CommerceSchemaMigration(),
        new RequirementStateMigration(),
        new ContextBundleManifestMigration(),
    ] as $migration) {
        $method = new ReflectionMethod($migration, 'statements');
        $method->setAccessible(true);
        $statements = array_merge($statements, $method->invoke($migration, new TableNames('wp_'), 'DEFAULT CHARACTER SET utf8mb4'));
    }
    $columns = [];
    $indexes = [];
    $engines = [];
    foreach ($statements as $statement) {
        if (!preg_match('/^\s*CREATE\s+TABLE\s+([^\s(]+)\s*\((.*)\)\s*ENGINE\s*=\s*([A-Za-z0-9_]+)/is', $statement, $matches)) {
            throw new RuntimeException('Fixture could not identify checked-in CREATE statement.');
        }
        $table = trim($matches[1], '` ');
        $columns[$table] = [];
        $indexes[$table] = [];
        $engines[$table] = $matches[3];
        foreach (preg_split('/,\s*(?:\r?\n|$)/', trim($matches[2])) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $name = null;
            $unique = false;
            $columnList = null;
            if (preg_match('/^PRIMARY\s+KEY\s*\((.+)\)$/i', $line, $index)) {
                $name = 'PRIMARY';
                $unique = true;
                $columnList = $index[1];
            } elseif (preg_match('/^UNIQUE\s+KEY\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$/i', $line, $index)) {
                $name = $index[1];
                $unique = true;
                $columnList = $index[2];
            } elseif (preg_match('/^KEY\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$/i', $line, $index)) {
                $name = $index[1];
                $columnList = $index[2];
            }
            if ($name !== null && is_string($columnList)) {
                $indexColumns = [];
                foreach (explode(',', $columnList) as $indexColumn) {
                    if (!preg_match('/^\s*`?([A-Za-z0-9_]+)`?/', $indexColumn, $columnMatch)) {
                        throw new RuntimeException('Fixture could not parse checked-in index column.');
                    }
                    $indexColumns[] = $columnMatch[1];
                }
                $indexes[$table][$name] = ['unique' => $unique, 'columns' => $indexColumns];
                continue;
            }
            if (preg_match('/^`?([A-Za-z0-9_]+)`?\s+/', $line, $column)) {
                $columns[$table][] = $column[1];
            }
        }
    }
    SchemaPostconditionVerifier::verifyCreateStatements(new wpdb($columns, $indexes, $engines), $statements);
});

$scenario('requirement-state schema has one atomic actor-owned versioned head', static function () use ($assert): void {
    $migration = new RequirementStateMigration();
    $method = new ReflectionMethod($migration, 'statements');
    $method->setAccessible(true);
    $statements = $method->invoke(
        $migration,
        new TableNames('wp_'),
        'DEFAULT CHARACTER SET utf8mb4'
    );
    $assert($migration->version() === '1.4.0', 'Requirement-state migration version drifted.');
    $assert(is_array($statements) && count($statements) === 1, 'Requirement-state migration did not define exactly one head table.');
    $statement = (string) ($statements[0] ?? '');
    foreach ([
        'public_id char(36) NOT NULL',
        'conversation_id char(36) NOT NULL',
        'actor_type varchar(24) NOT NULL',
        'actor_id varchar(191) NOT NULL',
        'actor_key_hash char(64) NOT NULL',
        'state_json longtext NOT NULL',
        'state_hash char(64) NOT NULL',
        'version bigint(20) unsigned NOT NULL',
        'last_source_message_id char(36) NULL',
        'UNIQUE KEY conversation_id (conversation_id)',
        'KEY actor_version (actor_key_hash,version)',
        'ENGINE=InnoDB',
    ] as $requiredSql) {
        $assert(str_contains($statement, $requiredSql), 'Requirement-state schema omitted: ' . $requiredSql);
    }
    $tables = new TableNames('wp_');
    $assert($tables->requirementStates() === 'wp_veyra_requirement_states', 'Requirement-state table name drifted.');
    $assert(in_array($tables->requirementStates(), $tables->all(), true), 'Uninstall table inventory omitted requirement state.');
});

$scenario('Context Bundle manifest schema is metadata-only, actor-owned, and lifecycle-aware', static function () use ($assert): void {
    $migration = new ContextBundleManifestMigration();
    $method = new ReflectionMethod($migration, 'statements');
    $method->setAccessible(true);
    $statements = $method->invoke(
        $migration,
        new TableNames('wp_'),
        'DEFAULT CHARACTER SET utf8mb4'
    );
    $assert($migration->version() === '1.5.0', 'Context Bundle manifest migration version drifted.');
    $assert(is_array($statements) && count($statements) === 1, 'Context Bundle migration did not define exactly one manifest table.');
    $statement = (string) ($statements[0] ?? '');
    foreach ([
        'public_id varchar(64) NOT NULL',
        'bundle_hash char(64) NOT NULL',
        'metadata_hash char(64) NOT NULL',
        'actor_type varchar(24) NOT NULL',
        'actor_id varchar(191) NOT NULL',
        'actor_key_hash char(64) NOT NULL',
        'source_accounting_json longtext NOT NULL',
        'selection_manifest_json longtext NOT NULL',
        'redactions_json longtext NOT NULL',
        'bundle_expires_at datetime NOT NULL',
        'retention_expires_at datetime NULL',
        'legal_hold tinyint(1) NOT NULL DEFAULT 0',
        'erased_at datetime NULL',
        'UNIQUE KEY public_id (public_id)',
        'UNIQUE KEY bundle_hash (bundle_hash)',
        'KEY actor_created (actor_key_hash,created_at)',
        'KEY retention_due (legal_hold,retention_expires_at)',
        'ENGINE=InnoDB',
    ] as $requiredSql) {
        $assert(str_contains($statement, $requiredSql), 'Context Bundle manifest schema omitted: ' . $requiredSql);
    }
    foreach (['selected_data', 'provider_body', 'provider_payload', 'message_text', 'attestation'] as $prohibitedColumn) {
        $assert(!str_contains($statement, $prohibitedColumn), 'Manifest schema persisted prohibited content column: ' . $prohibitedColumn);
    }
    $tables = new TableNames('wp_');
    $assert($tables->contextBundleManifests() === 'wp_veyra_context_bundle_manifests', 'Context Bundle table name drifted.');
    $assert(in_array($tables->contextBundleManifests(), $tables->all(), true), 'Uninstall table inventory omitted Context Bundle manifests.');
});

$scenario('Conversation Focus unresolved references migrate idempotently with a verified postcondition', static function () use ($assert): void {
    $database = new wpdb([
        'wp_veyra_conversation_focus' => ['id', 'focused_resources_json'],
    ]);
    $migration = new ConversationFocusReferencesMigration();
    $migration->up($database);
    $migration->up($database);
    $assert($migration->version() === '1.6.0', 'Conversation Focus reference migration version drifted.');
    $assert(
        in_array(
            'unresolved_references_json',
            $database->columns['wp_veyra_conversation_focus'] ?? [],
            true
        ),
        'Conversation Focus reference migration omitted its durable JSON column.'
    );

    $initial = new InitialSchemaMigration();
    $method = new ReflectionMethod($initial, 'statements');
    $method->setAccessible(true);
    $statements = $method->invoke(
        $initial,
        new TableNames('wp_'),
        'DEFAULT CHARACTER SET utf8mb4'
    );
    $focusStatement = '';
    foreach ($statements as $statement) {
        if (str_contains($statement, 'wp_veyra_conversation_focus')) {
            $focusStatement = $statement;
            break;
        }
    }
    $assert(
        str_contains($focusStatement, 'unresolved_references_json longtext NULL'),
        'Clean-install Conversation Focus schema omitted unresolved references.'
    );
});

$scenario('Pending Question binding migration is complete and idempotent', static function () use ($assert): void {
    $database = new wpdb([
        'wp_veyra_pending_questions' => ['id', 'invalidation_reason', 'answered_at'],
    ]);
    $migration = new PendingQuestionBindingMigration();
    $migration->up($database);
    $migration->up($database);
    foreach (['answered_binding_id', 'answered_message_id', 'answer_binding_json'] as $column) {
        $assert(
            in_array($column, $database->columns['wp_veyra_pending_questions'] ?? [], true),
            'Pending Question binding migration omitted ' . $column . '.'
        );
    }
    $assert(
        ($database->indexes['wp_veyra_pending_questions']['answered_binding_id'] ?? null) === [
            'unique' => true,
            'columns' => ['answered_binding_id'],
        ],
        'Pending Question binding migration omitted its unique index.'
    );
    $assert($migration->version() === '1.3.0', 'Pending Question binding migration version drifted.');
});

$scenario('migration batch reports incomplete until every pending migration is applied', static function () use ($assert): void {
    global $veyraMigrationOptions, $veyraMigrationFailSchemaWrite;
    $veyraMigrationOptions = [];
    $veyraMigrationFailSchemaWrite = false;
    $migration = static fn (string $version): Migration => new class($version) implements Migration {
        public function __construct(private readonly string $migrationVersion) { }
        public function version(): string { return $this->migrationVersion; }
        public function up(wpdb $database): void { }
    };

    $migrator = new Migrator(new wpdb(), [$migration('1.0.0'), $migration('1.1.0')]);
    $first = $migrator->migrate(1);
    $assert(!$first->succeeded, 'Partial migration batch was reported as successful.');
    $assert($first->code === 'migration_incomplete', 'Partial migration batch did not return migration_incomplete.');
    $assert($first->appliedVersions === ['1.0.0'], 'Partial migration batch reported the wrong applied versions.');
    $assert(get_option(Migrator::SCHEMA_OPTION, '') === '1.0.0', 'Partial batch did not preserve verified progress.');

    $second = $migrator->migrate(1);
    $assert($second->succeeded && $second->code === 'migration_ok', 'Final migration batch did not complete.');
    $assert($second->appliedVersions === ['1.1.0'], 'Final migration batch reported the wrong applied version.');
});

$scenario('automatic migration attempts exhaust at eight and reset only after progress', static function () use ($assert): void {
    $assert(Activator::MAX_AUTOMATIC_MIGRATION_ATTEMPTS === 8, 'Activator retry cap changed unexpectedly.');
    $policy = new MigrationRetryPolicy(Activator::MAX_AUTOMATIC_MIGRATION_ATTEMPTS);
    $state = null;
    for ($attempt = 1; $attempt <= Activator::MAX_AUTOMATIC_MIGRATION_ATTEMPTS; ++$attempt) {
        $state = $policy->nextState($state, '1.0.0', 'migration_failed');
        $assert($state['attempts'] === $attempt, 'Migration retry attempt counter was not monotonic.');
    }
    $assert($policy->exhausted($state), 'Eighth consecutive migration attempt did not exhaust automatic retries.');

    $saturated = $policy->nextState($state, '1.0.0', 'migration_locked');
    $assert($saturated['attempts'] === 8 && $policy->exhausted($saturated), 'Exhausted retry state escaped the cap.');
    $progressed = $policy->nextState($state, '1.1.0', 'migration_incomplete');
    $assert($progressed['attempts'] === 1, 'Verified schema progress did not start a fresh bounded retry budget.');
    $assert(!$policy->isValidPersistedState(['attempts' => 0]), 'Malformed retry state was accepted.');
});

$scenario('network-wide activation is explicitly blocked before site installation', static function () use ($assert): void {
    global $veyraMigrationOptions, $veyraWpDieCalls;
    $veyraMigrationOptions = [];
    $veyraWpDieCalls = [];
    $blocked = false;
    try {
        Activator::activate(true);
    } catch (VeyraNetworkActivationBlocked) {
        $blocked = true;
    }
    $assert($blocked, 'Network-wide activation did not stop activation.');
    $assert(count($veyraWpDieCalls) === 1, 'Network-wide activation did not emit one explicit blocking response.');
    $assert(($veyraWpDieCalls[0]['args']['response'] ?? null) === 400, 'Network-wide activation used the wrong response status.');
    $assert($veyraMigrationOptions === [], 'Network-wide activation mutated site installation state before blocking.');
});

$scenario('upgrade discovery schedules migration without running DDL in the request', static function () use ($assert): void {
    global $veyraMigrationOptions, $veyraMigrationScheduled, $veyraMigrationScheduleCalls;
    $veyraMigrationOptions = [Migrator::SCHEMA_OPTION => '1.5.0'];
    $veyraMigrationScheduled = false;
    $veyraMigrationScheduleCalls = 0;

    $scheduled = Activator::scheduleMigrationResume('1.6.0');

    $health = get_option(Activator::HEALTH_OPTION, []);
    $assert($scheduled, 'Older schema did not schedule one bounded migration event.');
    $assert($veyraMigrationScheduleCalls === 1, 'Upgrade discovery scheduled the wrong number of events.');
    $assert(get_option(Migrator::SCHEMA_OPTION, '') === '1.5.0', 'Upgrade discovery executed migration work synchronously.');
    $assert(in_array('schema_migration_required', $health['codes'] ?? [], true), 'Scheduled upgrade omitted its health code.');
    $assert(($health['manual_recovery_required'] ?? true) === false, 'Scheduled upgrade incorrectly required manual recovery.');
});

$scenario('newer stored schema is health-blocked instead of reported ready', static function () use ($assert): void {
    global $wpdb, $veyraMigrationOptions, $veyraMigrationScheduled, $veyraMigrationScheduleCalls;
    $wpdb = new wpdb();
    $veyraMigrationOptions = [Migrator::SCHEMA_OPTION => '9.0.0'];
    $veyraMigrationScheduled = false;
    $veyraMigrationScheduleCalls = 0;

    Activator::resumeMigrations();

    $health = get_option(Activator::HEALTH_OPTION, []);
    $assert(($health['state'] ?? null) === 'blocked', 'A downgrade over a newer schema was reported ready.');
    $assert(in_array('schema_version_incompatible', $health['codes'] ?? [], true), 'Newer schema omitted its incompatibility code.');
    $assert(($health['manual_recovery_required'] ?? false) === true, 'Newer schema did not require manual recovery.');
    $assert(($health['retention_scheduled'] ?? true) === false, 'Incompatible code scheduled retention against an unknown schema.');
    $assert($veyraMigrationScheduleCalls === 0, 'Incompatible schema scheduled an automatic downgrade.');
});

$scenario('Activator stops scheduling after the eighth stalled migration attempt', static function () use ($assert): void {
    global $wpdb, $veyraMigrationOptions, $veyraMigrationFailSchemaWrite;
    global $veyraMigrationScheduled, $veyraMigrationScheduleCalls, $veyraMigrationClearCalls;
    $wpdb = new wpdb();
    $veyraMigrationOptions = [
        Migrator::LOCK_OPTION => ['token' => 'active-owner', 'acquired_at' => time()],
    ];
    $veyraMigrationFailSchemaWrite = false;
    $veyraMigrationScheduled = false;
    $veyraMigrationScheduleCalls = 0;
    $veyraMigrationClearCalls = 0;

    for ($attempt = 1; $attempt <= Activator::MAX_AUTOMATIC_MIGRATION_ATTEMPTS; ++$attempt) {
        Activator::resumeMigrations();
    }

    $health = get_option(Activator::HEALTH_OPTION, []);
    $retry = get_option(Activator::MIGRATION_RETRY_OPTION, []);
    $assert(($retry['attempts'] ?? null) === 8, 'Activator did not persist the exhausted retry count.');
    $assert(in_array('migration_locked', $health['codes'] ?? [], true), 'Activator health omitted the migration result.');
    $assert(in_array('migration_retry_exhausted', $health['codes'] ?? [], true), 'Activator health omitted retry exhaustion.');
    $assert(($health['manual_recovery_required'] ?? false) === true, 'Activator did not require manual recovery.');
    $assert(($health['automatic_migration_retry_scheduled'] ?? true) === false, 'Activator reported another automatic retry.');
    $assert($veyraMigrationScheduled === false, 'Activator left an automatic migration event scheduled after exhaustion.');
    $assert($veyraMigrationScheduleCalls === 1, 'Activator scheduled duplicate retry events while one was pending.');
    $assert($veyraMigrationClearCalls === 1, 'Activator did not clear the pending retry event at exhaustion.');
});

$scenario('schema version cannot advance without verified option persistence', static function () use ($assert): void {
    global $veyraMigrationOptions, $veyraMigrationFailSchemaWrite;
    $veyraMigrationOptions = [];
    $veyraMigrationFailSchemaWrite = true;
    $migration = new class implements Migration {
        public function version(): string { return '9.9.9'; }
        public function up(wpdb $database): void { }
    };
    $result = (new Migrator(new wpdb(), [$migration]))->migrate();
    $assert(!$result->succeeded && $result->failedVersion === '9.9.9', 'Unpersisted schema version was reported as migrated.');
    $assert(get_option(Migrator::SCHEMA_OPTION, '0.0.0') === '0.0.0', 'Schema version advanced despite failed persistence.');
    $assert((get_option(Migrator::FAILURE_OPTION, [])['attempts'] ?? null) === 1, 'First migration failure attempt was not recorded.');
    $second = (new Migrator(new wpdb(), [$migration]))->migrate();
    $secondAttempts = get_option(Migrator::FAILURE_OPTION, [])['attempts'] ?? null;
    $assert(
        !$second->succeeded && $secondAttempts === 2,
        'Migration retry attempt did not advance: ' . json_encode([
            'code' => $second->code,
            'attempts' => $secondAttempts,
            'lock' => get_option(Migrator::LOCK_OPTION, null),
        ], JSON_THROW_ON_ERROR)
    );
    $veyraMigrationFailSchemaWrite = false;
});

fwrite(STDOUT, sprintf("Migration contract scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
