<?php

declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/** @var array<string, mixed> $veyraConfigurationOptions */
$veyraConfigurationOptions = [];

function get_option(string $name, mixed $default = false): mixed
{
    global $veyraConfigurationOptions;
    return array_key_exists($name, $veyraConfigurationOptions) ? $veyraConfigurationOptions[$name] : $default;
}

function update_option(string $name, mixed $value, bool $autoload = false): bool
{
    global $veyraConfigurationOptions;
    $changed = !array_key_exists($name, $veyraConfigurationOptions) || $veyraConfigurationOptions[$name] !== $value;
    $veyraConfigurationOptions[$name] = $value;
    return $changed;
}

if (!class_exists('wpdb')) {
    final class wpdb
    {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public string $last_error = '';
        /** @var list<array<string, mixed>> */
        public array $rows = [];
        /** @var list<string> */
        public array $events = [];
        public int $insertCount = 0;
        public bool $namedLockAvailable = true;
        public ?string $lastLockName = null;
        public bool $failAuthoritativeOptionRead = false;
        /** @var array<string, string> */
        public array $engines = [
            'wp_veyra_configuration_revisions' => 'InnoDB',
            'wp_options' => 'InnoDB',
        ];
        /** @var list<mixed> */
        private array $preparedArguments = [];
        /** @var list<array<string, mixed>>|null */
        private ?array $snapshot = null;
        /** @var array<string, mixed>|null */
        private ?array $optionSnapshot = null;
        private bool $namedLockHeld = false;
        private int $nextId = 1;

        public function prepare(string $query, mixed ...$arguments): string
        {
            $this->preparedArguments = $arguments;
            return $query;
        }

        /** @return array<string, mixed>|null */
        public function get_row(string $query, string $output): ?array
        {
            if (str_contains($query, 'SHOW TABLE STATUS')) {
                $table = (string) ($this->preparedArguments[0] ?? '');
                return isset($this->engines[$table]) ? ['Engine' => $this->engines[$table]] : null;
            }
            if (str_contains($query, 'FOR UPDATE')) {
                $product = (string) ($this->preparedArguments[0] ?? '');
                $state = (string) ($this->preparedArguments[1] ?? '');
                $this->events[] = 'lock:' . $state;
                return $this->latestMatching($product, $state, true);
            }
            if (str_contains($query, 'WHERE public_id = %s')) {
                $publicId = (string) ($this->preparedArguments[0] ?? '');
                $product = (string) ($this->preparedArguments[1] ?? '');
                $state = (string) ($this->preparedArguments[2] ?? '');
                $this->events[] = 'exact';
                foreach ($this->rows as $row) {
                    if (($row['public_id'] ?? null) === $publicId
                        && ($row['product_key'] ?? null) === $product
                        && ($row['lifecycle_state'] ?? null) === $state
                    ) {
                        return $row;
                    }
                }
                return null;
            }
            $product = (string) ($this->preparedArguments[0] ?? '');
            $state = (string) ($this->preparedArguments[1] ?? '');
            return $this->latestMatching($product, $state, false);
        }

        /** @return list<array<string, mixed>> */
        public function get_results(string $query, string $output): array
        {
            return [];
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data): int
        {
            ++$this->insertCount;
            $data['id'] = $this->nextId++;
            $this->rows[] = $data;
            $this->events[] = 'insert';
            return 1;
        }

        public function query(string $query): int|false
        {
            if ($query === 'START TRANSACTION') {
                global $veyraConfigurationOptions;
                $this->snapshot = $this->rows;
                $this->optionSnapshot = $veyraConfigurationOptions;
                $this->events[] = 'START';
                return 1;
            }
            if ($query === 'COMMIT') {
                $this->snapshot = null;
                $this->optionSnapshot = null;
                $this->events[] = 'COMMIT';
                return 1;
            }
            if ($query === 'ROLLBACK') {
                global $veyraConfigurationOptions;
                if ($this->snapshot !== null) {
                    $this->rows = $this->snapshot;
                }
                if ($this->optionSnapshot !== null) {
                    $veyraConfigurationOptions = $this->optionSnapshot;
                }
                $this->snapshot = null;
                $this->optionSnapshot = null;
                $this->events[] = 'ROLLBACK';
                return 1;
            }
            if (str_contains($query, 'INSERT INTO wp_options')) {
                global $veyraConfigurationOptions;
                $option = (string) ($this->preparedArguments[0] ?? '');
                $serialized = (string) ($this->preparedArguments[1] ?? '');
                $value = @unserialize($serialized, ['allowed_classes' => false]);
                if ($option === '' || !is_array($value)) {
                    return false;
                }
                $veyraConfigurationOptions[$option] = $value;
                $this->events[] = 'option-write';
                return 1;
            }
            return 0;
        }

        public function get_var(string $query): mixed
        {
            if (str_contains($query, 'GET_LOCK')) {
                $this->lastLockName = (string) ($this->preparedArguments[0] ?? '');
                $this->events[] = 'named-lock';
                if (!$this->namedLockAvailable || $this->namedLockHeld) {
                    return 0;
                }
                $this->namedLockHeld = true;
                return 1;
            }
            if (str_contains($query, 'RELEASE_LOCK')) {
                $this->events[] = 'named-unlock';
                if (!$this->namedLockHeld || ($this->preparedArguments[0] ?? null) !== $this->lastLockName) {
                    return 0;
                }
                $this->namedLockHeld = false;
                return 1;
            }
            if (str_contains($query, 'SELECT option_value')) {
                global $veyraConfigurationOptions;
                if ($this->failAuthoritativeOptionRead) {
                    return serialize(['stale' => true]);
                }
                $option = (string) ($this->preparedArguments[0] ?? '');
                return array_key_exists($option, $veyraConfigurationOptions)
                    ? serialize($veyraConfigurationOptions[$option])
                    : null;
            }
            return null;
        }

        public function seed(string $publicId, string $product, string $state): void
        {
            $payload = ['seed' => $publicId];
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->rows[] = [
                'id' => $this->nextId++,
                'public_id' => $publicId,
                'product_key' => $product,
                'lifecycle_state' => $state,
                'parent_public_id' => null,
                'payload_json' => $encoded,
                'payload_hash' => hash('sha256', $encoded),
                'validation_json' => '[]',
                'created_by' => 1,
                'activated_at' => null,
                'created_at' => '2026-08-24 10:00:00',
            ];
        }

        /** @return array<string, mixed>|null */
        private function latestMatching(string $product, string $state, bool $headOnly): ?array
        {
            $matching = array_values(array_filter(
                $this->rows,
                static fn (array $row): bool => ($row['product_key'] ?? null) === $product
                    && ($row['lifecycle_state'] ?? null) === $state
            ));
            if ($matching === []) {
                return null;
            }
            usort($matching, static fn (array $left, array $right): int => (int) $right['id'] <=> (int) $left['id']);
            return $headOnly ? ['public_id' => $matching[0]['public_id']] : $matching[0];
        }
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\Experience\Contract\ExperienceConfigurationValidator;
use Veyra\Features\Domain\FeatureRegistry;
use Veyra\Features\Domain\FeatureState;
use Veyra\Features\Infrastructure\WordPressFeatureConfigurationStore;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Operations\Configuration\AdminProductService;
use Veyra\Operations\Configuration\ConfigurationRevisionRepository;
use Veyra\Operations\Configuration\ProductConfigurationValidator;

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
$repository = static fn (wpdb $database): ConfigurationRevisionRepository => new ConfigurationRevisionRepository(
    $database,
    new TableNames('wp_')
);

$scenario('append locks the head and reconciles the exact inserted revision before commit', static function () use ($assert, $repository): void {
    $database = new wpdb();
    $created = $repository($database)->append('agent', 'draft', ['name' => 'one'], 7, null);
    $assert(is_array($created), 'Initial revision was not created.');
    $assert($database->events === ['named-lock', 'START', 'lock:draft', 'insert', 'exact', 'COMMIT', 'named-unlock'], 'Append did not use the required serialization/lock/insert/reconcile/commit sequence.');
    $assert(is_string($database->lastLockName) && strlen($database->lastLockName) <= 64, 'Named serialization lock was not safely bounded.');
    $assert(($created['version'] ?? null) === ($database->rows[0]['public_id'] ?? null), 'Append returned a revision other than the exact inserted row.');
});

$scenario('an empty head cannot append while another connection owns its product lock', static function () use ($assert, $repository): void {
    $database = new wpdb();
    $database->namedLockAvailable = false;
    $thrown = false;
    try {
        $repository($database)->append('experience', 'draft', ['schema_version' => 'veyra.experience.v1'], 7, null);
    } catch (RuntimeException $error) {
        $thrown = str_contains($error->getMessage(), 'serialization lock');
    }
    $assert($thrown, 'Contended empty-head append did not fail closed.');
    $assert($database->events === ['named-lock'], 'Contended empty-head append entered a transaction.');
    $assert($database->rows === [] && $database->insertCount === 0, 'Contended empty-head append wrote a revision.');
});

$scenario('a stale parent loses the serialized compare-and-append race', static function () use ($assert, $repository): void {
    $database = new wpdb();
    $parent = '11111111-1111-4111-8111-111111111111';
    $database->seed($parent, 'agent', 'draft');
    $first = $repository($database)->append('agent', 'draft', ['name' => 'first'], 7, $parent);
    $second = $repository($database)->append('agent', 'draft', ['name' => 'second'], 8, $parent);
    $assert(is_array($first), 'The current parent was rejected.');
    $assert($second === null, 'A stale parent appended a competing revision.');
    $assert($database->insertCount === 1, 'Both writers inserted against the same parent.');
    $assert(array_slice($database->events, -4) === ['START', 'lock:draft', 'ROLLBACK', 'named-unlock'], 'The stale writer did not leave through rollback and lock release.');
});

$scenario('publication guards draft and published heads in deterministic order', static function () use ($assert, $repository): void {
    $database = new wpdb();
    $draft = '22222222-2222-4222-8222-222222222222';
    $published = '33333333-3333-4333-8333-333333333333';
    $database->seed($draft, 'commerce', 'draft');
    $database->seed($published, 'commerce', 'published');
    $applied = false;
    $created = $repository($database)->appendGuarded(
        'commerce',
        'published',
        ['features' => []],
        9,
        ['published' => $published, 'draft' => $draft],
        [],
        null,
        $draft,
        static function (array $_created) use (&$applied, $database): void {
            $database->events[] = 'apply';
            $applied = true;
        },
        null,
        static function (array $_created) use ($database): void {
            $database->events[] = 'cache';
        }
    );
    $assert(is_array($created) && $applied, 'Guarded publication did not apply inside the transaction.');
    $assert($database->events === ['named-lock', 'START', 'lock:draft', 'lock:published', 'insert', 'exact', 'apply', 'COMMIT', 'cache', 'named-unlock'], 'Lifecycle locks, application or cache publication occurred in an unsafe order.');
});

$scenario('application failure rolls back the revision and runs the recovery hook', static function () use ($assert, $repository): void {
    $database = new wpdb();
    $parent = '44444444-4444-4444-8444-444444444444';
    $database->seed($parent, 'knowledge', 'published');
    $recovered = false;
    $thrown = false;
    try {
        $repository($database)->appendGuarded(
            'knowledge',
            'published',
            ['store_name' => 'Example'],
            10,
            ['published' => $parent],
            [],
            null,
            null,
            static function (): void {
                throw new RuntimeException('simulated option failure');
            },
            static function () use (&$recovered): void {
                $recovered = true;
            }
        );
    } catch (RuntimeException $error) {
        $thrown = $error->getMessage() === 'simulated option failure';
    }
    $assert($thrown, 'Application failure was hidden or changed.');
    $assert($recovered, 'Rollback recovery callback was not executed.');
    $assert(count($database->rows) === 1 && ($database->rows[0]['public_id'] ?? null) === $parent, 'Failed application left a published revision behind.');
    $assert(in_array('ROLLBACK', $database->events, true), 'Failed application did not roll back.');
    $assert(end($database->events) === 'named-unlock', 'Failed application did not release its serialization lock.');
});

$scenario('authoritative option verification failure rolls back option and revision together', static function () use ($assert, $repository): void {
    global $veyraConfigurationOptions;
    $veyraConfigurationOptions = ['veyra_agent_published_v1' => ['public_name' => 'Previous']];
    $database = new wpdb();
    $parent = '55555555-5555-4555-8555-555555555555';
    $database->seed($parent, 'agent', 'published');
    $reflection = new ReflectionClass(AdminProductService::class);
    /** @var AdminProductService $service */
    $service = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('database')->setValue($service, $database);
    $writeOption = $reflection->getMethod('writeAndVerifyPublishedOption');
    $database->failAuthoritativeOptionRead = true;
    $thrown = false;
    try {
        $repository($database)->appendGuarded(
            'agent',
            'published',
            ['public_name' => 'Changed'],
            10,
            ['published' => $parent],
            [],
            null,
            null,
            static function () use ($writeOption, $service): void {
                $writeOption->invoke($service, 'veyra_agent_published_v1', ['public_name' => 'Changed']);
            }
        );
    } catch (RuntimeException $error) {
        $thrown = true;
    }
    $assert($thrown, 'Failed authoritative option verification did not abort publication.');
    $assert(count($database->rows) === 1 && ($database->rows[0]['public_id'] ?? null) === $parent, 'Failed option verification retained the new revision.');
    $assert(($veyraConfigurationOptions['veyra_agent_published_v1']['public_name'] ?? null) === 'Previous', 'Failed option verification retained the new option value.');
});

$scenario('commerce publication requires a complete feature map', static function () use ($assert): void {
    $registry = FeatureRegistry::canonical();
    $reflection = new ReflectionClass(AdminProductService::class);
    /** @var AdminProductService $service */
    $service = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('features')->setValue($service, $registry);
    $method = $reflection->getMethod('commerceCompletenessIssues');
    $partial = $method->invoke($service, 'commerce', [
        'features' => ['commerce_cart' => ['configured_state' => 'On']],
    ]);
    $assert(is_array($partial) && ($partial[0]['code'] ?? null) === 'feature_map_incomplete', 'Partial feature map was not blocked.');

    $features = [];
    foreach ($registry->all() as $definition) {
        $features[$definition->key->value()] = ['configured_state' => $definition->defaultOn ? 'On' : 'Off'];
    }
    $complete = $method->invoke($service, 'commerce', ['features' => $features]);
    $assert($complete === [], 'Complete feature map was rejected.');
});

$scenario('new commerce drafts snapshot every registered effective setting', static function () use ($assert): void {
    global $veyraConfigurationOptions;
    $registry = FeatureRegistry::canonical();
    $veyraConfigurationOptions = [
        WordPressFeatureConfigurationStore::OPTION => ['commerce_cart' => FeatureState::On->value],
    ];
    $reflection = new ReflectionClass(AdminProductService::class);
    /** @var AdminProductService $service */
    $service = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('features')->setValue($service, $registry);
    $reflection->getProperty('validator')->setValue(
        $service,
        new ProductConfigurationValidator($registry, new ExperienceConfigurationValidator())
    );
    $configuration = $reflection->getMethod('initialConfiguration')->invoke($service, 'commerce');
    $assert(is_array($configuration), 'Initial commerce configuration was not created.');
    $assert(count($configuration['features'] ?? []) === count($registry->all()), 'Initial commerce draft omitted registered features.');
    $assert(($configuration['features']['commerce_cart']['configured_state'] ?? null) === 'On', 'Initial draft did not preserve the configured feature state.');
});

$scenario('publication fails closed unless revisions and options are transactional', static function () use ($assert): void {
    $database = new wpdb();
    $reflection = new ReflectionClass(AdminProductService::class);
    /** @var AdminProductService $service */
    $service = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('database')->setValue($service, $database);
    $reflection->getProperty('tables')->setValue($service, new TableNames('wp_'));
    $method = $reflection->getMethod('transactionalPublicationStorageReady');
    $assert($method->invoke($service) === true, 'InnoDB-backed revision and option tables were rejected.');
    $database->engines['wp_options'] = 'MyISAM';
    $assert($method->invoke($service) === false, 'Non-transactional option storage was accepted.');
});

$scenario('published option verification bypasses object-cache state', static function () use ($assert): void {
    global $veyraConfigurationOptions;
    $veyraConfigurationOptions = [];
    $database = new wpdb();
    $reflection = new ReflectionClass(AdminProductService::class);
    /** @var AdminProductService $service */
    $service = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('database')->setValue($service, $database);
    $method = $reflection->getMethod('writeAndVerifyPublishedOption');
    $method->invoke($service, 'veyra_agent_published_v1', ['public_name' => 'Veyra']);
    $assert(($veyraConfigurationOptions['veyra_agent_published_v1']['public_name'] ?? null) === 'Veyra', 'Authoritative option write was not accepted.');

    $database->failAuthoritativeOptionRead = true;
    $thrown = false;
    try {
        $method->invoke($service, 'veyra_agent_published_v1', ['public_name' => 'Changed']);
    } catch (ReflectionException|RuntimeException $error) {
        $thrown = true;
    }
    $assert($thrown, 'A stale authoritative option row was accepted from cache-equivalent state.');
});

$scenario('agent state exposes the independent model-management permission', static function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__, 2) . '/src/Operations/Configuration/AdminProductService.php');
    $assert(is_string($source), 'Admin product service source could not be read.');
    $assert(
        str_contains($source, "'manage_models' => \$product === 'agent' && current_user_can('manage_veyra_models')"),
        'Agent provider controls are not keyed to manage_veyra_models.'
    );
});

fwrite(STDOUT, sprintf("Configuration revision safety scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
