<?php
declare(strict_types=1);

namespace Veyra\Catalog\Tool;

/** Closed provider/result contracts for exact catalog presentation evidence. */
final class CatalogOutputSchemas
{
    /** @return array<string, mixed> */
    public static function for(string $toolName): array
    {
        return match ($toolName) {
            'catalog.search_products' => self::search(),
            'catalog.search_facets' => self::facets(),
            'catalog.get_product' => self::object(['product'], ['product' => self::snapshot()]),
            'catalog.get_variations' => self::variations(),
            'catalog.resolve_variation' => self::variationResolution(),
            'catalog.get_live_offer' => self::offer(),
            'catalog.get_stock' => self::stock(),
            'catalog.check_purchasability' => self::purchasability(),
            'catalog.get_related_products' => self::candidateRelationship('woocommerce_related_candidate'),
            'catalog.find_alternatives' => self::candidateRelationship('same_category_candidate'),
            'catalog.compare_products' => self::comparison(),
            'catalog.resolve_product_reference' => self::reference(),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function facets(): array
    {
        return self::object([
            'categories', 'attributes', 'candidate_count', 'source_candidate_count',
            'source_exhaustive', 'truncated', 'complete', 'observed_at',
        ], [
            'categories' => self::list(self::object(['slug', 'name'], [
                'slug' => self::string(500, 1),
                'name' => self::string(500, 1),
            ]), 200),
            'attributes' => self::list(self::object(['name', 'options'], [
                'name' => self::string(500, 1),
                'options' => self::list(self::string(500), 500),
            ]), 200),
            'candidate_count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
            'source_candidate_count' => ['type' => 'integer', 'minimum' => 0],
            'source_exhaustive' => self::boolean(),
            'truncated' => self::boolean(),
            'complete' => self::boolean(),
            'observed_at' => self::timestamp(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function search(): array
    {
        return self::object([
            'query', 'count', 'total_matching', 'candidates', 'selection_required',
            'truncated', 'complete', 'source_candidate_count', 'source_exhaustive',
            'price_filter_authority', 'observed_at',
        ], [
            'query' => self::string(240, 1),
            'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
            'total_matching' => ['type' => ['integer', 'null'], 'minimum' => 0],
            'candidates' => self::list(self::snapshot(), 20),
            'selection_required' => self::boolean(),
            'truncated' => self::boolean(),
            'complete' => self::boolean(),
            'source_candidate_count' => ['type' => 'integer', 'minimum' => 0],
            'source_exhaustive' => self::boolean(),
            'price_filter_authority' => ['const' => 'current_woocommerce_product_price'],
            'observed_at' => self::timestamp(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function variations(): array
    {
        return self::object([
            'product_id', 'variations', 'count', 'eligible_count', 'truncated', 'complete',
        ], [
            'product_id' => self::positiveInteger(),
            'variations' => self::list(self::snapshot(), 100),
            'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            'eligible_count' => ['type' => 'integer', 'minimum' => 0],
            'truncated' => self::boolean(),
            'complete' => self::boolean(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function variationResolution(): array
    {
        $requiredAttributes = self::object(['resolved', 'code', 'required_attributes'], [
            'resolved' => ['const' => false],
            'code' => ['enum' => ['variation_attributes_missing', 'variation_attributes_unknown']],
            'required_attributes' => self::list(self::string(191, 1), 100),
        ]);
        $matchCount = self::object(['resolved', 'code', 'match_count'], [
            'resolved' => ['const' => false],
            'code' => ['enum' => ['variation_not_found', 'variation_ambiguous']],
            'match_count' => ['type' => 'integer', 'minimum' => 0],
        ]);
        $resolved = self::object(['resolved', 'variation'], [
            'resolved' => ['const' => true],
            'variation' => self::snapshot(),
        ]);

        return ['oneOf' => [$requiredAttributes, $matchCount, $resolved]];
    }

    /** @return array<string, mixed> */
    private static function offer(): array
    {
        return self::object(['offer'], [
            'offer' => self::object([
                'product_id', 'variation_id', 'currency', 'regular_price',
                'sale_price', 'current_price', 'on_sale', 'stock_status',
                'purchasable', 'observed_at',
            ], [
                'product_id' => self::positiveInteger(),
                'variation_id' => ['type' => 'integer', 'minimum' => 0],
                'currency' => self::string(12, 1),
                'regular_price' => self::string(128),
                'sale_price' => self::string(128),
                'current_price' => self::string(128),
                'on_sale' => self::boolean(),
                'stock_status' => self::string(80, 1),
                'purchasable' => self::boolean(),
                'observed_at' => self::timestamp(),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private static function stock(): array
    {
        return self::object(['stock'], [
            'stock' => self::object([
                'product_id', 'variation_id', 'status', 'quantity', 'backorders',
                'in_stock', 'observed_at',
            ], [
                'product_id' => self::positiveInteger(),
                'variation_id' => ['type' => 'integer', 'minimum' => 0],
                'status' => self::string(80, 1),
                'quantity' => ['type' => ['number', 'null']],
                'backorders' => ['enum' => ['no', 'notify', 'yes']],
                'in_stock' => self::boolean(),
                'observed_at' => self::timestamp(),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private static function purchasability(): array
    {
        $variable = self::object([
            'allowed', 'product_id', 'variation_id', 'quantity', 'reason', 'observed_at',
        ], [
            'allowed' => ['const' => false],
            'product_id' => self::positiveInteger(),
            'variation_id' => ['const' => 0],
            'quantity' => ['type' => 'number', 'minimum' => 0.0001],
            'reason' => ['const' => 'exact_variation_required'],
            'observed_at' => self::timestamp(),
        ]);
        $exact = self::object([
            'allowed', 'product_id', 'variation_id', 'quantity', 'minimum_quantity',
            'maximum_quantity', 'stock_status', 'reason', 'observed_at',
        ], [
            'allowed' => self::boolean(),
            'product_id' => self::positiveInteger(),
            'variation_id' => ['type' => 'integer', 'minimum' => 0],
            'quantity' => ['type' => 'number', 'minimum' => 0.0001],
            'minimum_quantity' => ['type' => 'number', 'minimum' => 0.0001],
            'maximum_quantity' => ['type' => ['number', 'null']],
            'stock_status' => self::string(80, 1),
            'reason' => ['enum' => [null, 'current_product_or_quantity_not_purchasable']],
            'observed_at' => self::timestamp(),
        ]);

        return self::object(['purchasability'], [
            'purchasability' => ['oneOf' => [$variable, $exact]],
        ]);
    }

    /** @return array<string, mixed> */
    private static function candidateRelationship(string $relationship): array
    {
        return self::object(['candidates', 'relationship'], [
            'candidates' => self::list(self::snapshot(), 12),
            'relationship' => ['const' => $relationship],
        ]);
    }

    /** @return array<string, mixed> */
    private static function comparison(): array
    {
        return self::object(['products', 'observed_at'], [
            'products' => [
                'type' => 'array',
                'minItems' => 2,
                'maxItems' => 4,
                'items' => self::snapshot(),
            ],
            'observed_at' => self::timestamp(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function reference(): array
    {
        return self::object(['reference'], [
            'reference' => self::object([
                'snapshot', 'current_state', 'commerce_authorization', 'observed_at',
            ], [
                'snapshot' => self::snapshot(),
                'current_state' => self::snapshot(),
                'commerce_authorization' => ['const' => false],
                'observed_at' => self::timestamp(),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private static function snapshot(): array
    {
        return self::object([
            'product_id', 'variation_id', 'type', 'name', 'sku', 'permalink',
            'image', 'currency', 'price', 'regular_price', 'sale_price', 'on_sale',
            'stock_status', 'stock_quantity', 'purchasable', 'attributes',
            'categories', 'observed_at', 'historical_snapshot_version',
        ], [
            'product_id' => self::positiveInteger(),
            'variation_id' => ['type' => 'integer', 'minimum' => 0],
            'type' => self::string(80, 1),
            'name' => self::string(2000, 1),
            'sku' => self::string(500),
            'permalink' => self::string(4096),
            'image' => ['type' => ['string', 'null'], 'maxLength' => 4096],
            'currency' => self::string(12, 1),
            'price' => self::string(128),
            'regular_price' => self::string(128),
            'sale_price' => self::string(128),
            'on_sale' => self::boolean(),
            'stock_status' => self::string(80, 1),
            'stock_quantity' => ['type' => ['number', 'null']],
            'purchasable' => self::boolean(),
            'attributes' => self::list(self::attribute(), 200),
            'categories' => self::list(self::category(), 200),
            'observed_at' => self::timestamp(),
            'historical_snapshot_version' => ['const' => '1.0.0'],
        ]);
    }

    /** @return array<string, mixed> */
    private static function attribute(): array
    {
        return self::object(['name', 'options', 'variation'], [
            'name' => self::string(500, 1),
            'options' => self::list(self::string(500), 500),
            'variation' => self::boolean(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function category(): array
    {
        return self::object(['id', 'name', 'slug'], [
            'id' => self::positiveInteger(),
            'name' => self::string(500, 1),
            'slug' => self::string(500, 1),
        ]);
    }

    /** @param list<string> $required @param array<string, mixed> $properties @return array<string, mixed> */
    private static function object(array $required, array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ];
    }

    /** @param array<string, mixed> $items @return array<string, mixed> */
    private static function list(array $items, int $maximum): array
    {
        return ['type' => 'array', 'maxItems' => $maximum, 'items' => $items];
    }

    /** @return array<string, mixed> */
    private static function positiveInteger(): array
    {
        return ['type' => 'integer', 'minimum' => 1];
    }

    /** @return array<string, mixed> */
    private static function boolean(): array
    {
        return ['type' => 'boolean'];
    }

    /** @return array<string, mixed> */
    private static function string(int $maximum, int $minimum = 0): array
    {
        return ['type' => 'string', 'minLength' => $minimum, 'maxLength' => $maximum];
    }

    /** @return array<string, mixed> */
    private static function timestamp(): array
    {
        return ['type' => 'string', 'minLength' => 20, 'maxLength' => 40];
    }
}
