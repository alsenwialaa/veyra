<?php
declare(strict_types=1);

namespace Veyra\Catalog\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;

final class CatalogToolHandler implements ToolHandler
{
    public function definitions(): array
    {
        $actors = ['guest', 'customer', 'support', 'manager', 'administrator'];
        $feature = ['commerce_product_assistance'];
        return [
            $this->definition('catalog.search_products', 'Search a bounded current WooCommerce catalog candidate set. Never choose the first result implicitly.', [
                'query' => ['type' => 'string', 'maxLength' => 240],
                'category' => ['type' => 'string', 'maxLength' => 120],
                'minimum_price' => ['type' => 'number', 'minimum' => 0],
                'maximum_price' => ['type' => 'number', 'minimum' => 0],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            ], ['query'], $actors, $feature),
            $this->definition('catalog.search_facets', 'Return public categories and attributes represented by the current bounded search candidates.', [
                'query' => ['type' => 'string', 'maxLength' => 240],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
            ], ['query'], $actors, $feature),
            $this->definition('catalog.get_product', 'Read one exact current visible WooCommerce product.', [
                'product_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['product_id'], $actors, $feature),
            $this->definition('catalog.get_variations', 'List current purchasable variation choices for one exact visible variable product.', [
                'product_id' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ], ['product_id'], $actors, $feature),
            $this->definition('catalog.resolve_variation', 'Resolve an exact variation only when supplied attributes identify exactly one current purchasable variation.', [
                'product_id' => ['type' => 'integer', 'minimum' => 1],
                'attributes' => [
                    'type' => 'array',
                    'minItems' => 0,
                    'maxItems' => 100,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'value'],
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                            'value' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                        ],
                    ],
                ],
            ], ['product_id', 'attributes'], $actors, $feature),
            $this->definition('catalog.get_live_offer', 'Read current authoritative product or variation price, sale, stock and purchasability.', [
                'product_id' => ['type' => 'integer', 'minimum' => 1],
                'variation_id' => ['type' => 'integer', 'minimum' => 0],
            ], ['product_id'], $actors, $feature),
            $this->definition('catalog.get_stock', 'Read current WooCommerce stock and backorder state for an exact product or variation.', [
                'product_id' => ['type' => 'integer', 'minimum' => 1],
                'variation_id' => ['type' => 'integer', 'minimum' => 0],
            ], ['product_id'], $actors, $feature),
            $this->definition('catalog.check_purchasability', 'Validate current visibility, purchasability, stock and quantity constraints.', [
                'product_id' => ['type' => 'integer', 'minimum' => 1],
                'variation_id' => ['type' => 'integer', 'minimum' => 0],
                'quantity' => ['type' => 'number', 'minimum' => 0.0001],
            ], ['product_id', 'quantity'], $actors, $feature),
            $this->definition('catalog.get_related_products', 'Read a bounded current related-product candidate set without asserting fit.', [
                'product_id' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
            ], ['product_id'], $actors, $feature),
            $this->definition('catalog.find_alternatives', 'Find bounded current category alternatives to a specific product without selecting one.', [
                'product_id' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
            ], ['product_id'], $actors, $feature),
            $this->definition('catalog.compare_products', 'Read current comparable facts for two to four exact visible products.', [
                'product_ids' => [
                    'type' => 'array', 'minItems' => 2, 'maxItems' => 4,
                    'uniqueItems' => true,
                    'items' => ['type' => 'integer', 'minimum' => 1],
                ],
            ], ['product_ids'], $actors, $feature),
            $this->definition('catalog.resolve_product_reference', 'Reauthorize and refresh an exact product reference; the reference itself is not commerce authorization.', [
                'product_id' => ['type' => 'integer', 'minimum' => 1],
                'variation_id' => ['type' => 'integer', 'minimum' => 0],
            ], ['product_id'], $actors, $feature),
        ];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        if (!function_exists('wc_get_product') || !function_exists('wc_get_products')) {
            return ToolResult::failed($call, 'woocommerce_unavailable', $context->correlationId, false);
        }
        $result = match ($call->name) {
            'catalog.search_products' => $this->search($call->arguments),
            'catalog.search_facets' => $this->facets($call->arguments),
            'catalog.get_product' => $this->getProduct((int) $call->arguments['product_id']),
            'catalog.get_variations' => $this->getVariations((int) $call->arguments['product_id'], (int) ($call->arguments['limit'] ?? 50)),
            'catalog.resolve_variation' => $this->resolveVariation((int) $call->arguments['product_id'], $call->arguments['attributes']),
            'catalog.get_live_offer' => $this->offer((int) $call->arguments['product_id'], (int) ($call->arguments['variation_id'] ?? 0)),
            'catalog.get_stock' => $this->stock((int) $call->arguments['product_id'], (int) ($call->arguments['variation_id'] ?? 0)),
            'catalog.check_purchasability' => $this->purchasability((int) $call->arguments['product_id'], (int) ($call->arguments['variation_id'] ?? 0), (float) $call->arguments['quantity']),
            'catalog.get_related_products' => $this->related((int) $call->arguments['product_id'], (int) ($call->arguments['limit'] ?? 6)),
            'catalog.find_alternatives' => $this->alternatives((int) $call->arguments['product_id'], (int) ($call->arguments['limit'] ?? 6)),
            'catalog.compare_products' => $this->compare($call->arguments['product_ids']),
            'catalog.resolve_product_reference' => $this->reference((int) $call->arguments['product_id'], (int) ($call->arguments['variation_id'] ?? 0)),
            default => ['ok' => false, 'code' => 'tool_operation_unknown'],
        };
        if (($result['ok'] ?? false) !== true) {
            return ToolResult::failed($call, (string) ($result['code'] ?? 'catalog_operation_failed'), $context->correlationId, false);
        }
        unset($result['ok']);
        return ToolResult::success($call, $result, $context->correlationId);
    }

    /** @param array<string, array<string, mixed>> $properties @param array<int, string> $required @param array<int, string> $actors @param array<int, string> $features */
    private function definition(string $name, string $description, array $properties, array $required, array $actors, array $features): ToolDefinition
    {
        return new ToolDefinition($name, '1.0.0', $description, 'read', [
            'type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties,
        ], $actors, [], $features, true, CatalogOutputSchemas::for($name));
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function search(array $args): array
    {
        $query = trim((string) $args['query']);
        if ($query === '') {
            return ['ok' => false, 'code' => 'catalog_query_empty'];
        }
        $requestedLimit = max(1, min(20, (int) ($args['limit'] ?? 12)));
        $scanLimit = min(100, max(20, $requestedLimit * 5));
        $minimumPrice = isset($args['minimum_price']) ? (float) $args['minimum_price'] : null;
        $maximumPrice = isset($args['maximum_price']) ? (float) $args['maximum_price'] : null;
        if ($minimumPrice !== null && $maximumPrice !== null && $minimumPrice > $maximumPrice) {
            return ['ok' => false, 'code' => 'catalog_price_range_invalid'];
        }
        $parameters = [
            'status' => 'publish',
            'visibility' => 'visible',
            // Price arguments are deliberately not passed as undocumented
            // WC_Product_Query variables. A bounded candidate page is read
            // through the public query API and current product prices are
            // filtered deterministically below.
            'limit' => $scanLimit,
            's' => $query,
            'orderby' => ['relevance' => 'DESC', 'date' => 'DESC'],
            'return' => 'objects',
        ];
        if (!empty($args['category'])) {
            $parameters['category'] = [(string) $args['category']];
        }
        $parameters['paginate'] = true;
        $queryResult = wc_get_products($parameters);
        $products = is_object($queryResult) && is_array($queryResult->products ?? null)
            ? $queryResult->products
            : (is_array($queryResult) ? $queryResult : []);
        $total = is_object($queryResult) && is_numeric($queryResult->total ?? null)
            ? (int) $queryResult->total
            : count($products);
        $eligible = [];
        foreach ($products as $product) {
            if ($product instanceof \WC_Product
                && $this->isPublicProduct($product)
                && $this->priceMatches($product, $minimumPrice, $maximumPrice)
            ) {
                $eligible[] = $this->snapshot($product);
            }
        }
        $items = array_slice($eligible, 0, $requestedLimit);
        $sourceExhaustive = $total <= count($products);
        $filteredTotal = $sourceExhaustive ? count($eligible) : null;
        $complete = $sourceExhaustive && count($eligible) <= $requestedLimit;
        return [
            'ok' => true,
            'query' => $query,
            'count' => count($items),
            'total_matching' => $filteredTotal,
            'candidates' => $items,
            // One observed candidate is not an exact resolution when the
            // bounded source page was non-exhaustive.
            'selection_required' => count($items) !== 1 || !$sourceExhaustive,
            'truncated' => !$complete,
            'complete' => $complete,
            'source_candidate_count' => $total,
            'source_exhaustive' => $sourceExhaustive,
            'price_filter_authority' => 'current_woocommerce_product_price',
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function facets(array $args): array
    {
        $search = $this->search($args);
        if (($search['ok'] ?? false) !== true) {
            return $search;
        }
        $categories = [];
        $attributes = [];
        foreach ($search['candidates'] as $candidate) {
            foreach ($candidate['categories'] as $category) {
                $categories[(string) $category['slug']] = (string) $category['name'];
            }
            foreach ($candidate['attributes'] as $attribute) {
                $attributes[(string) $attribute['name']] = array_values(array_unique(array_merge(
                    $attributes[(string) $attribute['name']] ?? [],
                    $attribute['options']
                )));
            }
        }
        ksort($categories, SORT_STRING);
        ksort($attributes, SORT_STRING);
        $categoryList = [];
        foreach ($categories as $slug => $name) {
            $categoryList[] = ['slug' => $slug, 'name' => $name];
        }
        $attributeList = [];
        foreach ($attributes as $name => $options) {
            sort($options, SORT_STRING);
            $attributeList[] = ['name' => $name, 'options' => $options];
        }
        return [
            'ok' => true,
            'categories' => $categoryList,
            'attributes' => $attributeList,
            'candidate_count' => $search['count'],
            'source_candidate_count' => $search['source_candidate_count'],
            'source_exhaustive' => $search['source_exhaustive'],
            'truncated' => $search['truncated'],
            'complete' => $search['complete'],
            'observed_at' => $search['observed_at'],
        ];
    }

    /** @return array<string, mixed> */
    private function getProduct(int $productId): array
    {
        $product = wc_get_product($productId);
        if (!$product instanceof \WC_Product || !$this->isPublicProduct($product)) {
            return ['ok' => false, 'code' => 'product_not_available'];
        }
        return ['ok' => true, 'product' => $this->snapshot($product)];
    }

    /** @return array<string, mixed> */
    private function getVariations(int $productId, int $limit): array
    {
        $product = wc_get_product($productId);
        if (!$product instanceof \WC_Product_Variable || !$this->isPublicProduct($product)) {
            return ['ok' => false, 'code' => 'variable_product_not_available'];
        }
        $limit = max(1, min(100, $limit));
        $items = [];
        $eligibleCount = 0;
        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product($variationId);
            if ($variation instanceof \WC_Product_Variation
                && $this->isPublicProduct($variation)
                && $variation->is_purchasable()
                && $variation->is_in_stock()
                && !in_array('', $variation->get_variation_attributes(), true)
            ) {
                ++$eligibleCount;
                if (count($items) >= $limit) {
                    continue;
                }
                $items[] = $this->snapshot($variation);
            }
        }
        return [
            'ok' => true,
            'product_id' => $productId,
            'variations' => $items,
            'count' => count($items),
            'eligible_count' => $eligibleCount,
            'truncated' => $eligibleCount > count($items),
            'complete' => $eligibleCount <= count($items),
        ];
    }

    /** @param list<array{name:string,value:string}> $requested @return array<string, mixed> */
    private function resolveVariation(int $productId, array $requested): array
    {
        $product = wc_get_product($productId);
        if (!$product instanceof \WC_Product_Variable || !$this->isPublicProduct($product)) {
            return ['ok' => false, 'code' => 'variable_product_not_available'];
        }
        $normalized = [];
        if (!array_is_list($requested) || count($requested) > 100) {
            return ['ok' => false, 'code' => 'variation_attributes_invalid'];
        }
        foreach ($requested as $attribute) {
            if (!is_array($attribute)
                || count($attribute) !== 2
                || !array_key_exists('name', $attribute)
                || !array_key_exists('value', $attribute)
                || !is_string($attribute['name']) || $attribute['name'] === ''
                || !is_string($attribute['value']) || $attribute['value'] === ''
            ) {
                return ['ok' => false, 'code' => 'variation_attributes_invalid'];
            }
            $key = $attribute['name'];
            $value = $attribute['value'];
            $rawKey = str_starts_with($key, 'attribute_') ? substr($key, 10) : $key;
            $attributeKey = 'attribute_' . (function_exists('wc_sanitize_taxonomy_name') ? wc_sanitize_taxonomy_name($rawKey) : sanitize_key($rawKey));
            $normalizedValue = function_exists('sanitize_title') ? sanitize_title($value) : strtolower(trim($value));
            if ($attributeKey === 'attribute_' || $normalizedValue === '' || isset($normalized[$attributeKey])) {
                return ['ok' => false, 'code' => 'variation_attributes_invalid'];
            }
            $normalized[$attributeKey] = $normalizedValue;
        }
        $requiredNames = array_map(static fn ($attribute): string => 'attribute_' . (function_exists('wc_sanitize_taxonomy_name') ? wc_sanitize_taxonomy_name($attribute->get_name()) : sanitize_key($attribute->get_name())), array_filter(
            $product->get_attributes(),
            static fn ($attribute): bool => $attribute instanceof \WC_Product_Attribute && $attribute->get_variation()
        ));
        sort($requiredNames, SORT_STRING);
        $providedNames = array_keys($normalized);
        sort($providedNames, SORT_STRING);
        if (array_diff($requiredNames, $providedNames)) {
            return ['ok' => true, 'resolved' => false, 'code' => 'variation_attributes_missing', 'required_attributes' => $requiredNames];
        }
        if ($providedNames !== $requiredNames) {
            return ['ok' => true, 'resolved' => false, 'code' => 'variation_attributes_unknown', 'required_attributes' => $requiredNames];
        }
        $matches = [];
        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product($variationId);
            if (!$variation instanceof \WC_Product_Variation
                || !$this->isPublicProduct($variation)
                || !$variation->is_purchasable()
                || !$variation->is_in_stock()
            ) {
                continue;
            }
            $actual = $variation->get_variation_attributes();
            if (in_array('', $actual, true)) {
                continue; // "Any" variation is never silently selected.
            }
            $match = true;
            foreach ($requiredNames as $name) {
                if (!isset($normalized[$name], $actual[$name]) || sanitize_title((string) $actual[$name]) !== $normalized[$name]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $matches[] = $variation;
            }
        }
        if (count($matches) !== 1) {
            return ['ok' => true, 'resolved' => false, 'code' => count($matches) === 0 ? 'variation_not_found' : 'variation_ambiguous', 'match_count' => count($matches)];
        }
        return ['ok' => true, 'resolved' => true, 'variation' => $this->snapshot($matches[0])];
    }

    /** @return array<string, mixed> */
    private function offer(int $productId, int $variationId): array
    {
        $product = $this->resolveProductOrVariation($productId, $variationId);
        if (!$product instanceof \WC_Product) {
            return ['ok' => false, 'code' => 'product_not_available'];
        }
        return ['ok' => true, 'offer' => [
            'product_id' => $productId,
            'variation_id' => $product instanceof \WC_Product_Variation ? $product->get_id() : 0,
            'currency' => get_woocommerce_currency(),
            'regular_price' => (string) $product->get_regular_price(),
            'sale_price' => (string) $product->get_sale_price(),
            'current_price' => (string) $product->get_price(),
            'on_sale' => $product->is_on_sale(),
            'stock_status' => $product->get_stock_status(),
            'purchasable' => $product->is_purchasable(),
            'observed_at' => gmdate(DATE_ATOM),
        ]];
    }

    /** @return array<string, mixed> */
    private function stock(int $productId, int $variationId): array
    {
        $product = $this->resolveProductOrVariation($productId, $variationId);
        if (!$product instanceof \WC_Product) {
            return ['ok' => false, 'code' => 'product_not_available'];
        }
        return ['ok' => true, 'stock' => [
            'product_id' => $productId,
            'variation_id' => $product instanceof \WC_Product_Variation ? $product->get_id() : 0,
            'status' => $product->get_stock_status(),
            'quantity' => $product->managing_stock() ? $product->get_stock_quantity() : null,
            'backorders' => $product->get_backorders(),
            'in_stock' => $product->is_in_stock(),
            'observed_at' => gmdate(DATE_ATOM),
        ]];
    }

    /** @return array<string, mixed> */
    private function purchasability(int $productId, int $variationId, float $quantity): array
    {
        $product = $this->resolveProductOrVariation($productId, $variationId);
        if (!$product instanceof \WC_Product) {
            return ['ok' => false, 'code' => 'product_not_available'];
        }
        if ($product instanceof \WC_Product_Variable) {
            return ['ok' => true, 'purchasability' => [
                'allowed' => false,
                'product_id' => $productId,
                'variation_id' => 0,
                'quantity' => $quantity,
                'reason' => 'exact_variation_required',
                'observed_at' => gmdate(DATE_ATOM),
            ]];
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public WooCommerce quantity contract; Veyra must honor extension-adjusted limits.
        $minimum = max(0.0001, (float) apply_filters('woocommerce_quantity_input_min', $product->get_min_purchase_quantity(), $product));
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public WooCommerce quantity contract; Veyra must honor extension-adjusted limits.
        $maximumRaw = (float) apply_filters('woocommerce_quantity_input_max', $product->get_max_purchase_quantity(), $product);
        $maximum = $maximumRaw > 0 ? $maximumRaw : null;
        $enoughStock = method_exists($product, 'has_enough_stock') ? $product->has_enough_stock($quantity) : $product->is_in_stock();
        $valid = $product->is_purchasable()
            && $product->is_in_stock()
            && $enoughStock
            && $quantity >= $minimum
            && ($maximum === null || $quantity <= $maximum);
        return ['ok' => true, 'purchasability' => [
            'allowed' => $valid,
            'product_id' => $productId,
            'variation_id' => $product instanceof \WC_Product_Variation ? $product->get_id() : 0,
            'quantity' => $quantity,
            'minimum_quantity' => $minimum,
            'maximum_quantity' => $maximum,
            'stock_status' => $product->get_stock_status(),
            'reason' => $valid ? null : 'current_product_or_quantity_not_purchasable',
            'observed_at' => gmdate(DATE_ATOM),
        ]];
    }

    /** @return array<string, mixed> */
    private function related(int $productId, int $limit): array
    {
        $product = wc_get_product($productId);
        if (!$product instanceof \WC_Product || !$this->isPublicProduct($product)) {
            return ['ok' => false, 'code' => 'product_not_available'];
        }
        $ids = function_exists('wc_get_related_products') ? wc_get_related_products($productId, max(1, min(12, $limit))) : [];
        return ['ok' => true, 'candidates' => $this->snapshotsFromIds($ids), 'relationship' => 'woocommerce_related_candidate'];
    }

    /** @return array<string, mixed> */
    private function alternatives(int $productId, int $limit): array
    {
        $product = wc_get_product($productId);
        if (!$product instanceof \WC_Product || !$this->isPublicProduct($product)) {
            return ['ok' => false, 'code' => 'product_not_available'];
        }
        $categorySlugs = [];
        foreach ($product->get_category_ids() as $categoryId) {
            $term = get_term($categoryId, 'product_cat');
            if ($term instanceof \WP_Term) {
                $categorySlugs[] = $term->slug;
            }
        }
        if ($categorySlugs === []) {
            return ['ok' => true, 'candidates' => [], 'relationship' => 'same_category_candidate'];
        }
        $boundedLimit = max(1, min(12, $limit));
        $queriedIds = wc_get_products([
            'status' => 'publish', 'visibility' => 'visible', 'category' => $categorySlugs,
            // Fetch one extra row and exclude the source in memory. This avoids
            // the exclusionary post__not_in query while preserving the bound.
            'limit' => $boundedLimit + 1, 'return' => 'ids',
        ]);
        $products = [];
        foreach ($queriedIds as $candidateId) {
            if (!is_int($candidateId) || $candidateId === $productId) {
                continue;
            }
            $products[] = $candidateId;
            if (count($products) === $boundedLimit) {
                break;
            }
        }
        return ['ok' => true, 'candidates' => $this->snapshotsFromIds($products), 'relationship' => 'same_category_candidate'];
    }

    /** @param array<int, mixed> $productIds @return array<string, mixed> */
    private function compare(array $productIds): array
    {
        if (!array_is_list($productIds) || count($productIds) < 2 || count($productIds) > 4) {
            return ['ok' => false, 'code' => 'comparison_scope_invalid'];
        }
        $ids = [];
        foreach ($productIds as $productId) {
            if (!is_int($productId) || $productId < 1 || isset($ids[$productId])) {
                return ['ok' => false, 'code' => 'comparison_scope_invalid'];
            }
            $ids[$productId] = $productId;
        }
        $ids = array_values($ids);
        $products = $this->snapshotsFromIds($ids);
        if (count($products) !== count($ids)) {
            return ['ok' => false, 'code' => 'comparison_product_unavailable'];
        }
        return ['ok' => true, 'products' => $products, 'observed_at' => gmdate(DATE_ATOM)];
    }

    /** @return array<string, mixed> */
    private function reference(int $productId, int $variationId): array
    {
        $product = $this->resolveProductOrVariation($productId, $variationId);
        if (!$product instanceof \WC_Product) {
            return ['ok' => false, 'code' => 'product_reference_unavailable'];
        }
        return ['ok' => true, 'reference' => [
            'snapshot' => $this->snapshot($product),
            'current_state' => $this->snapshot($product),
            'commerce_authorization' => false,
            'observed_at' => gmdate(DATE_ATOM),
        ]];
    }

    private function resolveProductOrVariation(int $productId, int $variationId): ?\WC_Product
    {
        $product = wc_get_product($variationId > 0 ? $variationId : $productId);
        if (!$product instanceof \WC_Product || !$this->isPublicProduct($product)) {
            return null;
        }
        if ($product instanceof \WC_Product_Variation && $product->get_parent_id() !== $productId) {
            return null;
        }
        return $product;
    }

    private function isPublicProduct(\WC_Product $product): bool
    {
        if ($product instanceof \WC_Product_Variation && $product->get_status() !== 'publish') {
            return false;
        }
        $parent = $product instanceof \WC_Product_Variation ? wc_get_product($product->get_parent_id()) : $product;
        return $parent instanceof \WC_Product && $parent->get_status() === 'publish' && $parent->is_visible();
    }

    private function priceMatches(\WC_Product $product, ?float $minimum, ?float $maximum): bool
    {
        if ($minimum === null && $maximum === null) {
            return true;
        }
        $raw = $product->get_price('view');
        if (!is_scalar($raw) || (string) $raw === '' || !is_numeric((string) $raw)) {
            return false;
        }
        $price = (float) $raw;

        return is_finite($price)
            && ($minimum === null || $price >= $minimum)
            && ($maximum === null || $price <= $maximum);
    }

    /** @param array<int, int|string> $ids @return array<int, array<string, mixed>> */
    private function snapshotsFromIds(array $ids): array
    {
        $items = [];
        foreach ($ids as $id) {
            $product = wc_get_product((int) $id);
            if ($product instanceof \WC_Product && $this->isPublicProduct($product)) {
                $items[] = $this->snapshot($product);
            }
        }
        return $items;
    }

    /** @return array<string, mixed> */
    private function snapshot(\WC_Product $product): array
    {
        $variation = $product instanceof \WC_Product_Variation ? $product : null;
        $parent = $variation ? wc_get_product($variation->get_parent_id()) : $product;
        $categories = [];
        if ($parent instanceof \WC_Product) {
            foreach ($parent->get_category_ids() as $categoryId) {
                $term = get_term($categoryId, 'product_cat');
                if ($term instanceof \WP_Term) {
                    $categories[] = ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug];
                }
            }
        }
        $attributes = [];
        foreach ($product->get_attributes() as $key => $attribute) {
            if ($attribute instanceof \WC_Product_Attribute) {
                $attributes[] = ['name' => $attribute->get_name(), 'options' => array_values(array_map('strval', $attribute->get_options())), 'variation' => $attribute->get_variation()];
            } else {
                $attributes[] = ['name' => (string) $key, 'options' => [(string) $attribute], 'variation' => true];
            }
        }
        $imageId = $product->get_image_id() ?: ($parent instanceof \WC_Product ? $parent->get_image_id() : 0);
        $image = $imageId ? wp_get_attachment_image_url($imageId, 'woocommerce_thumbnail') : null;
        return [
            'product_id' => $variation ? $variation->get_parent_id() : $product->get_id(),
            'variation_id' => $variation ? $variation->get_id() : 0,
            'type' => $product->get_type(),
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'permalink' => $parent instanceof \WC_Product ? $parent->get_permalink() : '',
            'image' => is_string($image) ? $image : null,
            'currency' => get_woocommerce_currency(),
            'price' => (string) $product->get_price(),
            'regular_price' => (string) $product->get_regular_price(),
            'sale_price' => (string) $product->get_sale_price(),
            'on_sale' => $product->is_on_sale(),
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $product->managing_stock() ? $product->get_stock_quantity() : null,
            'purchasable' => $product->is_purchasable(),
            'attributes' => $attributes,
            'categories' => $categories,
            'observed_at' => gmdate(DATE_ATOM),
            'historical_snapshot_version' => '1.0.0',
        ];
    }
}
