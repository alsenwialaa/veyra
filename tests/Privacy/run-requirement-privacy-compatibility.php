<?php

declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        unset($domain);
        return $text;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0): string|false
    {
        return json_encode($value, $flags);
    }
}
if (!class_exists('WP_Error')) {
    final class WP_Error
    {
        public function __construct(private readonly string $code, private readonly string $message)
        {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        unset($capability);
        return false;
    }
}

final class wpdb
{
    public string $prefix = 'wp_';

    /** @var list<array<string,mixed>> */
    public array $legacyRows = [];

    /** @var array{sql:string,args:list<mixed>}|null */
    public ?array $lastPrepared = null;

    public mixed $scalarResult = 0;

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->lastPrepared = ['sql' => $query, 'args' => array_values($arguments)];
        return 'prepared-legacy-export';
    }

    /** @return list<array<string,mixed>> */
    public function get_results(string $query, mixed $output = null): array
    {
        unset($output);
        return $query === 'prepared-legacy-export' ? $this->legacyRows : [];
    }

    public function get_var(string $query): mixed
    {
        unset($query);
        return $this->scalarResult;
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Identity\Application\ActorResolver;
use Veyra\Identity\Domain\Actor;
use Veyra\Privacy\WordPressPrivacyIntegration;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        fwrite(STDOUT, "PASS {$label}\n");
        return;
    }
    ++$failed;
    fwrite(STDERR, "FAIL {$label}\n");
};

$database = new wpdb();
$database->legacyRows = [[
    'public_id' => '11111111-1111-4111-8111-111111111111',
    'memory_json' => json_encode([
        'requirements' => [[
            'id' => 'req_' . str_repeat('a', 32),
            'field' => 'category',
            'value' => 'jackets',
        ]],
        'internal_note' => 'must-not-be-exported-through-legacy-projection',
    ], JSON_THROW_ON_ERROR),
], [
    'public_id' => '22222222-2222-4222-8222-222222222222',
    'memory_json' => json_encode(['unrelated' => true], JSON_THROW_ON_ERROR),
]];

$integration = (new ReflectionClass(WordPressPrivacyIntegration::class))->newInstanceWithoutConstructor();
foreach (['database' => $database, 'tables' => new TableNames('wp_')] as $property => $value) {
    $reflection = new ReflectionProperty(WordPressPrivacyIntegration::class, $property);
    $reflection->setAccessible(true);
    $reflection->setValue($integration, $value);
}
$method = new ReflectionMethod(WordPressPrivacyIntegration::class, 'legacyRequirementExport');
$method->setAccessible(true);
$result = $method->invoke($integration, new ActorScope('customer', 'wp-user-7'), 0);

$sql = is_array($database->lastPrepared) ? $database->lastPrepared['sql'] : '';
$arguments = is_array($database->lastPrepared) ? $database->lastPrepared['args'] : [];
$check(
    str_contains($sql, 'LEFT JOIN wp_veyra_requirement_states AS requirement_state')
        && str_contains($sql, 'requirement_state.id IS NULL')
        && str_contains($sql, 'conversation.actor_type = %s')
        && str_contains($sql, 'conversation.actor_id = %s')
        && str_contains($sql, 'conversation.actor_key_hash = %s')
        && $arguments[0] === 'customer'
        && $arguments[1] === 'wp-user-7'
        && $arguments[2] === hash('sha256', 'customer:wp-user-7'),
    'legacy export query is actor-scoped and excludes already imported heads'
);

$items = is_array($result) && is_array($result['data'] ?? null) ? $result['data'] : [];
$encodedItem = $items[0]['data'][2]['value'] ?? '';
$decodedItem = is_string($encodedItem) ? json_decode($encodedItem, true) : null;
$check(
    count($items) === 1
        && ($result['done'] ?? null) === true
        && is_array($decodedItem)
        && ($decodedItem[0]['field'] ?? null) === 'category'
        && !str_contains((string) $encodedItem, 'must-not-be-exported-through-legacy-projection'),
    'legacy export projects only the requirements key and never the competing memory blob'
);

$source = file_get_contents(dirname(__DIR__, 2) . '/src/Privacy/WordPressPrivacyIntegration.php');
$check(
    is_string($source)
        && str_contains($source, '$this->tables->requirementStates()')
        && str_contains($source, "'requirement-states'")
        && str_contains($source, 'legacyRequirementExport'),
    'current and legacy requirement data both have explicit privacy-export paths'
);

$unauthorized = (new ReflectionClass(WordPressPrivacyIntegration::class))->newInstanceWithoutConstructor();
$resolver = new class implements ActorResolver {
    public function resolve(bool $allowGuest = true): ?Actor
    {
        unset($allowGuest);
        return null;
    }

    public function resolveOrCreateGuest(): Actor
    {
        throw new RuntimeException('Guest creation is outside the privacy callback.');
    }
};
foreach ([
    'database' => $database,
    'tables' => new TableNames('wp_'),
    'actors' => $resolver,
] as $property => $value) {
    $reflection = new ReflectionProperty(WordPressPrivacyIntegration::class, $property);
    $reflection->setAccessible(true);
    $reflection->setValue($unauthorized, $value);
}
$exportDenied = $unauthorized->exportPersonalData('customer@example.test');
$erasureDenied = $unauthorized->erasePersonalData('customer@example.test');
$check(
    $exportDenied instanceof WP_Error
        && $exportDenied->get_error_code() === 'veyra_privacy_export_not_authorized'
        && $erasureDenied instanceof WP_Error
        && $erasureDenied->get_error_code() === 'veyra_privacy_erasure_not_authorized',
    'unauthorized privacy callbacks return typed errors and cannot complete requests'
);

$database->scalarResult = null;
$retainedCount = new ReflectionMethod(WordPressPrivacyIntegration::class, 'retainedCount');
$retainedCount->setAccessible(true);
$check(
    $retainedCount->invoke($integration, new ActorScope('customer', 'wp-user-7')) === null,
    'retention verification query failures remain distinguishable from retained rows'
);

fwrite(STDOUT, "Requirement privacy compatibility scenarios: {$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
