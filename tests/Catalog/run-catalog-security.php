<?php

declare(strict_types=1);

class WC_Product
{
    public function __construct(protected int $id, protected string $status = 'publish', protected bool $visible = true)
    {
    }

    public function get_id(): int { return $this->id; }
    public function get_status(): string { return $this->status; }
    public function is_visible(): bool { return $this->visible; }
    public function get_type(): string { return 'simple'; }
    public function get_name(): string { return 'Product ' . $this->id; }
    public function get_sku(): string { return ''; }
    public function get_permalink(): string { return 'https://example.test/product/' . $this->id; }
    public function get_image_id(): int { return 0; }
    public function get_price(string $context = 'view'): string { return '10.00'; }
    public function get_regular_price(): string { return '10.00'; }
    public function get_sale_price(): string { return ''; }
    public function is_on_sale(): bool { return false; }
    public function get_stock_status(): string { return 'instock'; }
    public function is_in_stock(): bool { return true; }
    public function get_backorders(): string { return 'no'; }
    public function managing_stock(): bool { return false; }
    public function get_stock_quantity(): ?int { return null; }
    public function is_purchasable(): bool { return true; }
    public function get_min_purchase_quantity(): float { return 1.0; }
    public function get_max_purchase_quantity(): float { return -1.0; }
    public function has_enough_stock(float $quantity): bool { return true; }
    public function get_attributes(): array { return []; }
    public function get_category_ids(): array { return []; }
}

class WC_Product_Variable extends WC_Product
{
    /** @param array<int, int> $children */
    public function __construct(int $id, private array $children, private array $variationAttributes = [])
    {
        parent::__construct($id);
    }

    public function get_type(): string { return 'variable'; }
    public function get_children(): array { return $this->children; }
    public function get_attributes(): array { return $this->variationAttributes; }
}

class VeyraProductWithMissingImage extends WC_Product
{
    public function get_image_id(): int { return 999; }
}

class WC_Product_Variation extends WC_Product
{
    public function __construct(int $id, private int $parentId, string $status = 'publish', private array $variationAttributes = [])
    {
        parent::__construct($id, $status, true);
    }

    public function get_type(): string { return 'variation'; }
    public function get_parent_id(): int { return $this->parentId; }
    public function get_variation_attributes(): array { return $this->variationAttributes; }
}

class WC_Product_Attribute
{
    public function __construct(private string $name, private array $options = []) {}
    public function get_name(): string { return $this->name; }
    public function get_options(): array { return $this->options; }
    public function get_variation(): bool { return true; }
}

/** @var array<int, WC_Product> $veyraCatalogProducts */
$veyraCatalogProducts = [];

function wc_get_product(int $id): ?WC_Product
{
    global $veyraCatalogProducts;
    return $veyraCatalogProducts[$id] ?? null;
}

function wc_get_products(array $parameters): array
{
    return [];
}

function get_woocommerce_currency(): string { return 'USD'; }
function wp_get_attachment_image_url(int $id, string $size): string|false { return false; }
function get_term(int $id, string $taxonomy): mixed { return null; }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value) ?? ''); }
function sanitize_title(string $value): string { return strtolower(trim($value)); }
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Provider\ProviderSafeToolResultProjector;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\Catalog\Tool\CatalogOutputSchemas;
use Veyra\Catalog\Tool\CatalogToolHandler;

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
$context = new ToolContext(
    'guest',
    'guest-session-1',
    null,
    'guest-session-1',
    '11111111-1111-4111-8111-111111111111',
    [],
    ['commerce_product_assistance' => 'On'],
    'en_US',
    '22222222-2222-4222-8222-222222222222'
);

$scenario('component-bearing catalog outputs are closed and provider-projectable', static function () use ($assert, $context): void {
    global $veyraCatalogProducts;
    $attribute = new WC_Product_Attribute('pa_size', ['small', 'large']);
    $veyraCatalogProducts = [
        70 => new VeyraProductWithMissingImage(70),
        71 => new WC_Product(71),
        80 => new WC_Product_Variable(80, [81], [$attribute]),
        81 => new WC_Product_Variation(81, 80, 'publish', ['attribute_pa_size' => 'small']),
    ];
    $handler = new CatalogToolHandler();
    $validator = new ToolInputValidator();
    $definitions = [];
    foreach ($handler->definitions() as $definition) {
        $definitions[$definition->name] = $definition;
    }
    $calls = [
        new ToolCall('schema-search', 'catalog.search_products', '1.0.0', ['query' => 'jacket']),
        new ToolCall('schema-facets', 'catalog.search_facets', '1.0.0', ['query' => 'jacket']),
        new ToolCall('schema-product', 'catalog.get_product', '1.0.0', ['product_id' => 70]),
        new ToolCall('schema-variations', 'catalog.get_variations', '1.0.0', ['product_id' => 80, 'limit' => 50]),
        new ToolCall('schema-resolution', 'catalog.resolve_variation', '1.0.0', [
            'product_id' => 80,
            'attributes' => [['name' => 'pa_size', 'value' => 'small']],
        ]),
        new ToolCall('schema-offer', 'catalog.get_live_offer', '1.0.0', ['product_id' => 70]),
        new ToolCall('schema-stock', 'catalog.get_stock', '1.0.0', ['product_id' => 70]),
        new ToolCall('schema-purchasability', 'catalog.check_purchasability', '1.0.0', ['product_id' => 70, 'quantity' => 1.0]),
        new ToolCall('schema-related', 'catalog.get_related_products', '1.0.0', ['product_id' => 70, 'limit' => 6]),
        new ToolCall('schema-alternatives', 'catalog.find_alternatives', '1.0.0', ['product_id' => 70, 'limit' => 6]),
        new ToolCall('schema-comparison', 'catalog.compare_products', '1.0.0', ['product_ids' => [70, 71]]),
        new ToolCall('schema-reference', 'catalog.resolve_product_reference', '1.0.0', ['product_id' => 80, 'variation_id' => 81]),
    ];
    foreach ($calls as $call) {
        $schema = $definitions[$call->name]->outputSchema ?? [];
        $assert($schema !== [], $call->name . ' has no closed output schema.');
        $result = $handler->execute($call, $context);
        $assert($result->status === 'succeeded', $call->name . ' did not produce its successful fixture.');
        $assert($validator->validateValue($result->data, $schema), $call->name . ' result violated its output schema.');
    }

    $productCall = array_values(array_filter($calls, static fn (ToolCall $call): bool => $call->name === 'catalog.get_product'))[0];
    $validProduct = $handler->execute($productCall, $context)->data;
    $validProduct['unexpected'] = true;
    $assert(
        !$validator->validateValue($validProduct, CatalogOutputSchemas::for('catalog.get_product')),
        'Catalog output schema accepted an undeclared field.'
    );

    $registry = new ToolRegistry($validator);
    $registry->register($handler);
    $successful = $registry->execute($productCall, $context);
    $assert($successful->status === 'succeeded', 'The universal result boundary rejected a valid catalog result.');
    $projected = (new ProviderSafeToolResultProjector())->project($successful, $registry);
    $assert(
        ($projected['data']['product']['product_id'] ?? null) === 70
            && array_key_exists('image', $projected['data']['product'])
            && $projected['data']['product']['image'] === null
            && !array_key_exists('correlation_id', $projected),
        'Exact catalog evidence was not safely projected to the response phase.'
    );
    $projector = new ProviderSafeToolResultProjector();
    foreach ($calls as $call) {
        $result = $registry->execute($call, $context);
        $assert($result->status === 'succeeded', $call->name . ' did not pass the registry contract.');
        $assert(($projector->project($result, $registry)['tool'] ?? null) === $call->name, $call->name . ' did not pass provider-safe projection.');
    }

    $failed = $registry->execute(
        new ToolCall('schema-missing', 'catalog.get_product', '1.0.0', ['product_id' => 999]),
        $context
    );
    $failedProjection = (new ProviderSafeToolResultProjector())->project($failed, $registry);
    $assert(
        $failed->status === 'failed' && $failedProjection['data'] === [],
        'A failed catalog result could not use the safe empty provider envelope.'
    );
});

$scenario('direct draft variation cannot inherit public parent visibility', static function () use ($assert, $context): void {
    global $veyraCatalogProducts;
    $veyraCatalogProducts = [
        10 => new WC_Product_Variable(10, [11]),
        11 => new WC_Product_Variation(11, 10, 'draft'),
    ];
    $result = (new CatalogToolHandler())->execute(
        new ToolCall('call-draft', 'catalog.get_product', '1.0.0', ['product_id' => 11]),
        $context
    );
    $assert($result->status === 'failed' && $result->code === 'product_not_available', 'Draft variation commerce data was exposed.');
});

$scenario('variation limit is applied after public eligibility filtering', static function () use ($assert, $context): void {
    global $veyraCatalogProducts;
    $veyraCatalogProducts = [
        20 => new WC_Product_Variable(20, [21, 22, 23]),
        21 => new WC_Product_Variation(21, 20, 'private'),
        22 => new WC_Product_Variation(22, 20, 'publish'),
        23 => new WC_Product_Variation(23, 20, 'publish'),
    ];
    $result = (new CatalogToolHandler())->execute(
        new ToolCall('call-list', 'catalog.get_variations', '1.0.0', ['product_id' => 20, 'limit' => 1]),
        $context
    );
    $assert($result->status === 'succeeded', 'Eligible variation query failed.');
    $assert(($result->data['count'] ?? 0) === 1 && ($result->data['eligible_count'] ?? 0) === 2, 'Eligibility was counted after applying the limit.');
    $assert(($result->data['variations'][0]['variation_id'] ?? 0) === 22, 'A later eligible variation was hidden by an earlier private child.');
    $assert(($result->data['truncated'] ?? false) === true && ($result->data['complete'] ?? true) === false, 'Non-exhaustive variation output was not explicit.');
});

$scenario('variation resolution requires an exact complete attribute set and excludes Any', static function () use ($assert, $context): void {
    global $veyraCatalogProducts;
    $attribute = new WC_Product_Attribute('pa_size', ['small', 'large']);
    $veyraCatalogProducts = [
        30 => new WC_Product_Variable(30, [31, 32], [$attribute]),
        31 => new WC_Product_Variation(31, 30, 'publish', ['attribute_pa_size' => '']),
        32 => new WC_Product_Variation(32, 30, 'publish', ['attribute_pa_size' => 'small']),
    ];
    $handler = new CatalogToolHandler();
    $missing = $handler->execute(new ToolCall('call-missing', 'catalog.resolve_variation', '1.0.0', [
        'product_id' => 30,
        'attributes' => [],
    ]), $context);
    $assert(($missing->data['resolved'] ?? true) === false && $missing->data['code'] === 'variation_attributes_missing', 'Missing variation attributes were silently resolved.');
    $extra = $handler->execute(new ToolCall('call-extra', 'catalog.resolve_variation', '1.0.0', [
        'product_id' => 30,
        'attributes' => [
            ['name' => 'pa_size', 'value' => 'small'],
            ['name' => 'color', 'value' => 'red'],
        ],
    ]), $context);
    $assert(($extra->data['resolved'] ?? true) === false && $extra->data['code'] === 'variation_attributes_unknown', 'Unknown variation attributes were ignored.');
    $notFound = $handler->execute(new ToolCall('call-not-found', 'catalog.resolve_variation', '1.0.0', [
        'product_id' => 30,
        'attributes' => [['name' => 'pa_size', 'value' => 'large']],
    ]), $context);
    $assert(($notFound->data['resolved'] ?? true) === false && $notFound->data['code'] === 'variation_not_found', 'An absent exact variation did not remain unresolved.');
    $exact = $handler->execute(new ToolCall('call-exact', 'catalog.resolve_variation', '1.0.0', [
        'product_id' => 30,
        'attributes' => [['name' => 'pa_size', 'value' => 'small']],
    ]), $context);
    $assert(($exact->data['resolved'] ?? false) === true && ($exact->data['variation']['variation_id'] ?? 0) === 32, 'Exact variation was not deterministically resolved.');
    $schema = CatalogOutputSchemas::for('catalog.resolve_variation');
    $validator = new ToolInputValidator();
    $assert($validator->validateValue($missing->data, $schema), 'Missing-attribute resolution result violated its closed output schema.');
    $assert($validator->validateValue($extra->data, $schema), 'Unknown-attribute resolution result violated its closed output schema.');
    $assert($validator->validateValue($notFound->data, $schema), 'No-match resolution result violated its closed output schema.');
    $assert($validator->validateValue($exact->data, $schema), 'Exact resolution result violated its closed output schema.');
});

fwrite(STDOUT, sprintf("Catalog security scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
