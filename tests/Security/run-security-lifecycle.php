<?php

declare(strict_types=1);

/** @var array<string,mixed> $veyraLifecycleOptions */
$veyraLifecycleOptions = [];
/** @var array<string,bool> $veyraLifecycleSchedules */
$veyraLifecycleSchedules = [];
/** @var array<string,bool> $veyraLifecycleActionSchedules */
$veyraLifecycleActionSchedules = [];
/** @var array<string,bool> $veyraLifecycleRegisteredHooks */
$veyraLifecycleRegisteredHooks = [];
/** @var string|null $veyraLifecycleFailHook */
$veyraLifecycleFailHook = null;
/** @var array<string,bool> $veyraLifecycleDeleteFailures */
$veyraLifecycleDeleteFailures = [];
$wp_version = '6.8.0';

if (!defined('WC_VERSION')) {
    define('WC_VERSION', '10.0.0');
}

class wpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';

    /** @var list<string> */
    public array $queries = [];

    /** @param list<string> $tables */
    public function __construct(
        public array $tables = [],
        public ?string $failQueryContaining = null
    ) {
    }

    public function esc_like(string $value): string
    {
        return addcslashes($value, '_%\\');
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        foreach ($arguments as $argument) {
            $replacement = is_int($argument) || is_float($argument)
                ? (string) $argument
                : "'" . str_replace("'", "''", (string) $argument) . "'";
            $query = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
        }
        return $query;
    }

    public function get_var(string $query): mixed
    {
        $this->last_error = '';
        if (!preg_match("/SHOW TABLES LIKE '([^']+)'/", $query, $matches)) {
            $this->last_error = 'unsupported query';
            return null;
        }
        $candidate = str_replace(['\\_', '\\%', '\\\\'], ['_', '%', '\\'], $matches[1]);
        return in_array($candidate, $this->tables, true) ? $candidate : null;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;
        if ($this->failQueryContaining !== null && str_contains($query, $this->failQueryContaining)) {
            $this->last_error = 'forced lifecycle query failure';
            return false;
        }
        $this->last_error = '';
        return 1;
    }
}

function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    global $veyraLifecycleRegisteredHooks, $veyraLifecycleFailHook;
    unset($callback, $priority, $acceptedArgs);
    $veyraLifecycleRegisteredHooks['action:' . $hook] = true;
    if ($veyraLifecycleFailHook === 'action:' . $hook) {
        throw new RuntimeException('forced hook registration failure');
    }
    return true;
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    global $veyraLifecycleRegisteredHooks, $veyraLifecycleFailHook;
    unset($callback, $priority, $acceptedArgs);
    $veyraLifecycleRegisteredHooks['filter:' . $hook] = true;
    if ($veyraLifecycleFailHook === 'filter:' . $hook) {
        throw new RuntimeException('forced hook registration failure');
    }
    return true;
}

function remove_action(string $hook, callable $callback, int $priority = 10): bool
{
    global $veyraLifecycleRegisteredHooks;
    unset($callback, $priority);
    $key = 'action:' . $hook;
    $present = isset($veyraLifecycleRegisteredHooks[$key]);
    unset($veyraLifecycleRegisteredHooks[$key]);
    return $present;
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    global $veyraLifecycleRegisteredHooks;
    unset($callback, $priority);
    $key = 'filter:' . $hook;
    $present = isset($veyraLifecycleRegisteredHooks[$key]);
    unset($veyraLifecycleRegisteredHooks[$key]);
    return $present;
}

function get_option(string $name, mixed $default = false): mixed
{
    global $veyraLifecycleOptions;
    return array_key_exists($name, $veyraLifecycleOptions) ? $veyraLifecycleOptions[$name] : $default;
}

function update_option(string $name, mixed $value, bool $autoload = false): bool
{
    global $veyraLifecycleOptions;
    unset($autoload);
    $veyraLifecycleOptions[$name] = $value;
    return true;
}

function delete_option(string $name): bool
{
    global $veyraLifecycleOptions, $veyraLifecycleDeleteFailures;
    if (($veyraLifecycleDeleteFailures[$name] ?? false) === true) {
        return false;
    }
    $present = array_key_exists($name, $veyraLifecycleOptions);
    unset($veyraLifecycleOptions[$name]);
    return $present;
}

function wp_clear_scheduled_hook(string $hook): int|false
{
    global $veyraLifecycleSchedules;
    $present = ($veyraLifecycleSchedules[$hook] ?? false) === true;
    $veyraLifecycleSchedules[$hook] = false;
    return $present ? 1 : 0;
}

function wp_next_scheduled(string $hook): int|false
{
    global $veyraLifecycleSchedules;
    return ($veyraLifecycleSchedules[$hook] ?? false) === true ? time() + 60 : false;
}

function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): int
{
    global $veyraLifecycleActionSchedules;
    unset($args, $group);
    $present = ($veyraLifecycleActionSchedules[$hook] ?? false) === true;
    $veyraLifecycleActionSchedules[$hook] = false;
    return $present ? 1 : 0;
}

function as_has_scheduled_action(string $hook, array $args = [], string $group = ''): bool
{
    global $veyraLifecycleActionSchedules;
    unset($args, $group);
    return ($veyraLifecycleActionSchedules[$hook] ?? false) === true;
}

function wp_salt(string $scheme = 'auth'): string
{
    unset($scheme);
    return 'short';
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\Bootstrap\Activator;
use Veyra\Bootstrap\Deactivator;
use Veyra\Bootstrap\Plugin;
use Veyra\Bootstrap\SecurityLifecycleModule;
use Veyra\Bootstrap\Uninstaller;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\Repository\ActorScopedRepository;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Media\Application\ProtectedAttachmentAccessService;
use Veyra\Media\Application\ProtectedStorage;
use Veyra\Media\Domain\Attachment;
use Veyra\Media\Domain\StoredObject;
use Veyra\Media\Infrastructure\ProtectedStorageFactory;
use Veyra\Media\Presentation\MediaRestController;
use Veyra\Privacy\RetentionService;
use Veyra\Privacy\WordPressPrivacyIntegration;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Media\Support\InMemoryAttachmentRepository;
use Veyra\Tests\Support\FrozenClock;

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

$runtimeTables = static function (): array {
    $tables = new TableNames('wp_');
    return [$tables->confirmations(), $tables->idempotency(), $tables->locks()];
};

$scenario('protected media has no guessed retention default', static function () use ($assert): void {
    $health = ProtectedStorageFactory::health();
    $assert(ProtectedStorageFactory::retentionSeconds() === null, 'Missing retention policy received a default duration.');
    $assert(($health['retention'] ?? null) === 'Blocked', 'Missing retention policy was not health-blocked.');
    $assert(($health['routes'] ?? null) === 'Blocked', 'Protected routes became eligible without retention policy.');
});

$scenario('protected attachment delivery verifies stored bytes before exposure', static function () use ($assert): void {
    $recorded = 'original protected evidence';
    $createdAt = UtcInstant::fromDatabase('2026-08-24 10:00:00');
    $clock = new FrozenClock($createdAt);
    $attachments = new InMemoryAttachmentRepository();
    $attachment = Attachment::quarantined(
        new ActorScope('customer', 'wp-user-42'),
        '11111111-1111-4111-8111-111111111111',
        null,
        'payment_evidence',
        'private_fs',
        '2026/08/' . str_repeat('a', 48) . '.png',
        'image/png',
        strlen($recorded),
        hash('sha256', $recorded),
        $createdAt,
        3600
    )->withScanResult('clean', $createdAt);
    $attachments->insert($attachment);
    $storage = new class implements ProtectedStorage {
        public function store(string $sourcePath, string $mimeType, string $checksumSha256): StoredObject
        {
            throw new LogicException('Storage writes are outside this fixture.');
        }

        public function open(string $key)
        {
            unset($key);
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'tampered protected evidence');
            rewind($stream);
            return $stream;
        }

        public function delete(string $key): bool
        {
            return true;
        }
    };
    $access = new ProtectedAttachmentAccessService($attachments, $storage, new FoundationActorMapper(), $clock);
    $context = new ToolContext(
        'customer',
        'wp-user-42',
        42,
        null,
        '11111111-1111-4111-8111-111111111111',
        [],
        ['ai_multimodal_understanding' => 'On'],
        'en_US',
        '22222222-2222-4222-8222-222222222222'
    );

    try {
        $access->open($context, $attachment->id);
        $assert(false, 'Tampered protected bytes were exposed.');
    } catch (RuntimeException $error) {
        $assert(
            $error->getMessage() === 'attachment_integrity_verification_failed',
            'Tampered bytes did not fail with the stable integrity code.'
        );
    }
});

$scenario('actor-scoped persistence writes nullable fields as SQL NULL', static function () use ($assert): void {
    $database = new wpdb();
    $repository = new class($database) extends ActorScopedRepository {
        public function __construct(wpdb $database)
        {
            parent::__construct($database, 'wp_veyra_attachments');
        }

        /** @param array<string,mixed> $changes */
        public function update(ActorScope $actor, array $changes): bool
        {
            return $this->updateScopedVersioned(
                $actor,
                '11111111-1111-4111-8111-111111111111',
                1,
                $changes
            );
        }
    };

    $assert(
        $repository->update(new ActorScope('customer', 'wp-user-42'), [
            'status' => 'active',
            'deleted_at' => null,
        ]),
        'Nullable actor-scoped update did not persist.'
    );
    $query = (string) ($database->queries[0] ?? '');
    $assert(str_contains($query, 'deleted_at = NULL'), 'Nullable field was not represented as SQL NULL.');
    $assert(!str_contains($query, "deleted_at = ''"), 'Nullable field was serialized as an empty string.');
});

$scenario('deactivation fences mutations before releasing runtime locks', static function () use ($assert, $runtimeTables): void {
    global $wpdb, $veyraLifecycleOptions, $veyraLifecycleSchedules, $veyraLifecycleActionSchedules;
    $wpdb = new wpdb($runtimeTables());
    $veyraLifecycleOptions = [Activator::HEALTH_OPTION => ['state' => 'ready', 'codes' => []]];
    $veyraLifecycleSchedules = [
        'veyra_run_migrations' => true,
        'veyra_housekeeping' => true,
        'veyra_retention' => true,
    ];
    $veyraLifecycleActionSchedules = $veyraLifecycleSchedules;

    Deactivator::deactivate();

    $queries = implode("\n", $wpdb->queries);
    $confirmation = strpos($queries, 'plugin_deactivated');
    $idempotency = strpos($queries, 'plugin_deactivated_during_execution');
    $locks = strpos($queries, 'DELETE FROM wp_veyra_locks');
    $assert(is_int($confirmation), 'Active confirmations were not invalidated.');
    $assert(is_int($idempotency), 'In-progress idempotency was not made uncertain.');
    $assert(is_int($locks), 'Plugin-owned runtime locks were not released.');
    $assert($confirmation < $idempotency && $idempotency < $locks, 'Deactivation fencing order was unsafe.');
    $assert(!in_array(true, $veyraLifecycleSchedules, true), 'A plugin-owned scheduled hook survived deactivation.');
    $assert(!in_array(true, $veyraLifecycleActionSchedules, true), 'A plugin-owned Action Scheduler task survived deactivation.');
    $health = $veyraLifecycleOptions[Activator::HEALTH_OPTION] ?? [];
    $assert(($health['deactivation_cleanup_succeeded'] ?? null) === true, 'Successful cleanup was not recorded.');
});

$scenario('deactivation query failure is blocked and does not release locks', static function () use ($assert, $runtimeTables): void {
    global $wpdb, $veyraLifecycleOptions, $veyraLifecycleSchedules;
    $wpdb = new wpdb($runtimeTables(), 'wp_veyra_idempotency');
    $veyraLifecycleOptions = [Activator::HEALTH_OPTION => ['state' => 'ready', 'codes' => []]];
    $veyraLifecycleSchedules = [];

    Deactivator::deactivate();

    $queries = implode("\n", $wpdb->queries);
    $health = $veyraLifecycleOptions[Activator::HEALTH_OPTION] ?? [];
    $assert(!str_contains($queries, 'DELETE FROM wp_veyra_locks'), 'Locks were released after an incomplete mutation fence.');
    $assert(($health['state'] ?? null) === 'blocked', 'Cleanup failure did not block lifecycle health.');
    $assert(
        in_array('deactivation_runtime_cleanup_failed', $health['codes'] ?? [], true),
        'Cleanup failure omitted its stable health code.'
    );
});

$scenario('bootstrap composition exceptions are contained and health-blocked', static function () use ($assert): void {
    global $wpdb, $veyraLifecycleOptions;
    $wpdb = new wpdb();
    $veyraLifecycleOptions = [
        'veyra_schema_version' => '0.0.0',
        Activator::HEALTH_OPTION => ['state' => 'ready', 'codes' => []],
    ];

    $plugin = Plugin::register('/project/veyra-ai-commerce-agent.php');
    $plugin->boot();

    $health = $veyraLifecycleOptions[Activator::HEALTH_OPTION] ?? [];
    $assert($plugin->container() === null, 'A failed composition exposed a partial container.');
    $assert(($health['state'] ?? null) === 'blocked', 'Composition failure did not block lifecycle health.');
    $assert(in_array('runtime_boot_failed', $health['codes'] ?? [], true), 'Composition failure leaked without a stable health code.');
});

$scenario('security lifecycle registration rolls back every partial hook', static function () use ($assert): void {
    global $veyraLifecycleRegisteredHooks, $veyraLifecycleFailHook;
    $veyraLifecycleRegisteredHooks = [];
    $veyraLifecycleFailHook = 'filter:rest_pre_serve_request';

    $privacy = (new ReflectionClass(WordPressPrivacyIntegration::class))->newInstanceWithoutConstructor();
    $retention = (new ReflectionClass(RetentionService::class))->newInstanceWithoutConstructor();
    $media = (new ReflectionClass(MediaRestController::class))->newInstanceWithoutConstructor();
    $register = new ReflectionMethod(SecurityLifecycleModule::class, 'registerHooks');
    $register->setAccessible(true);

    try {
        $register->invoke(null, $privacy, $retention, $media);
        $assert(false, 'A failed lifecycle registration was reported as successful.');
    } catch (ReflectionException $error) {
        throw $error;
    } catch (Throwable $error) {
        $assert(
            str_contains($error->getMessage(), 'forced hook registration failure'),
            'The original registration failure was not propagated to Plugin.'
        );
    } finally {
        $veyraLifecycleFailHook = null;
    }

    $assert($veyraLifecycleRegisteredHooks === [], 'Lifecycle registration left a partial WordPress hook mounted.');
});

$scenario('uninstall verifies fixed option deletion before capability removal', static function () use ($assert): void {
    global $veyraLifecycleOptions, $veyraLifecycleDeleteFailures;
    $veyraLifecycleOptions = [Activator::HEALTH_OPTION => ['state' => 'blocked']];
    $veyraLifecycleDeleteFailures = [Activator::HEALTH_OPTION => true];
    $deleteOptions = new ReflectionMethod(Uninstaller::class, 'deleteOptions');
    $deleteOptions->setAccessible(true);

    $assert($deleteOptions->invoke(null) === false, 'A retained uninstall option passed its absence postcondition.');
    $assert(isset($veyraLifecycleOptions[Activator::HEALTH_OPTION]), 'The failure fixture unexpectedly deleted its option.');

    $veyraLifecycleDeleteFailures = [];
    $assert($deleteOptions->invoke(null) === true, 'Verified option deletion did not complete.');
    $assert($veyraLifecycleOptions === [], 'A fixed uninstall option remained after successful deletion.');
});

fwrite(STDOUT, "Security lifecycle scenarios: {$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
