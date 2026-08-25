<?php
declare(strict_types=1);

namespace Veyra\Recommendation\Infrastructure;

use Veyra\Recommendation\Contract\ProductCandidateRepository;
use Veyra\Recommendation\Domain\ProductCandidate;
use Veyra\Recommendation\Domain\ProductCandidateSet;

final class WooCommerceProductCandidateRepository implements ProductCandidateRepository
{
    public function retrieve(array $productIds): ProductCandidateSet
    {
        if (!function_exists('wc_get_product')) {
            return new ProductCandidateSet(false, [], $productIds);
        }
        $candidates = [];
        $unavailable = [];
        foreach ($productIds as $productId) {
            $product = wc_get_product($productId);
            if (!$product instanceof \WC_Product || !$this->isPublic($product)) {
                $unavailable[] = $productId;
                continue;
            }
            $candidate = $this->candidate($product);
            if (!$candidate instanceof ProductCandidate) {
                $unavailable[] = $productId;
                continue;
            }
            $candidates[] = $candidate;
        }
        return new ProductCandidateSet(true, $candidates, $unavailable);
    }

    private function isPublic(\WC_Product $product): bool
    {
        $parent = $product instanceof \WC_Product_Variation
            ? wc_get_product($product->get_parent_id())
            : $product;
        return $parent instanceof \WC_Product
            && $parent->get_status() === 'publish'
            && $parent->is_visible()
            && (!$product instanceof \WC_Product_Variation || $product->get_status() === 'publish');
    }

    private function candidate(\WC_Product $product): ?ProductCandidate
    {
        $parent = $product instanceof \WC_Product_Variation
            ? wc_get_product($product->get_parent_id())
            : $product;
        if (!$parent instanceof \WC_Product) {
            return null;
        }
        $price = $product->get_price('view');
        $unitPrice = is_string($price) && $price !== '' && is_numeric($price) ? (float) $price : null;
        $permalink = $product->get_permalink();
        return new ProductCandidate(
            $product->get_id(),
            $product instanceof \WC_Product_Variation ? $product->get_parent_id() : 0,
            $product->get_name(),
            $product->get_sku(),
            $product->get_type(),
            true,
            $product->is_purchasable(),
            $product->is_in_stock(),
            $product->backorders_allowed(),
            $unitPrice,
            function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'UNKNOWN',
            $product->get_stock_status(),
            $this->categories($parent),
            $this->attributes($product),
            is_string($permalink) ? $permalink : '',
            (int) $product->get_image_id(),
            gmdate(DATE_ATOM),
            max(0.000001, (float) $product->get_min_purchase_quantity()),
            (float) $product->get_max_purchase_quantity() > 0
                ? (float) $product->get_max_purchase_quantity()
                : null,
            is_numeric($product->get_stock_quantity())
                ? (float) $product->get_stock_quantity()
                : null,
            $product->managing_stock(),
            $product->is_sold_individually()
        );
    }

    /** @return array<int, string> */
    private function categories(\WC_Product $product): array
    {
        $categories = [];
        if (!function_exists('get_term')) {
            return $categories;
        }
        foreach ($product->get_category_ids() as $categoryId) {
            $term = get_term($categoryId, 'product_cat');
            if ($term instanceof \WP_Term && is_string($term->slug) && $term->slug !== '') {
                $categories[] = $term->slug;
            }
        }
        sort($categories, SORT_STRING);
        return array_values(array_unique($categories));
    }

    /** @return array<string, array<int, string>> */
    private function attributes(\WC_Product $product): array
    {
        $result = [];
        if ($product instanceof \WC_Product_Variation) {
            foreach ($product->get_variation_attributes() as $name => $value) {
                if (is_string($name) && is_string($value) && $value !== '') {
                    $result[$this->attributeName($name)] = [$this->attributeValue($value)];
                }
            }
            ksort($result, SORT_STRING);
            return $result;
        }
        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof \WC_Product_Attribute) {
                continue;
            }
            $name = $this->attributeName($attribute->get_name());
            $values = [];
            if ($attribute->is_taxonomy() && function_exists('wc_get_product_terms')) {
                $terms = wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'slugs']);
                if (is_array($terms)) {
                    foreach ($terms as $term) {
                        if (is_string($term) && $term !== '') {
                            $values[] = $this->attributeValue($term);
                        }
                    }
                }
            } else {
                foreach ($attribute->get_options() as $option) {
                    if (is_scalar($option) && (string) $option !== '') {
                        $values[] = $this->attributeValue((string) $option);
                    }
                }
            }
            sort($values, SORT_STRING);
            $result[$name] = array_values(array_unique($values));
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function attributeName(string $name): string
    {
        $name = str_starts_with($name, 'attribute_') ? substr($name, 10) : $name;
        return function_exists('sanitize_key') ? sanitize_key($name) : strtolower(trim($name));
    }

    private function attributeValue(string $value): string
    {
        return function_exists('sanitize_title') ? sanitize_title($value) : strtolower(trim($value));
    }
}
