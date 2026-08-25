<?php
declare(strict_types=1);

namespace Veyra\Recommendation\Domain;

final class ProductCandidate
{
    /**
     * @param array<int, string> $categories
     * @param array<string, array<int, string>> $attributes
     */
    public function __construct(
        public readonly int $productId,
        public readonly int $parentId,
        public readonly string $name,
        public readonly string $sku,
        public readonly string $productType,
        public readonly bool $visible,
        public readonly bool $purchasable,
        public readonly bool $inStock,
        public readonly bool $backordersAllowed,
        public readonly ?float $unitPrice,
        public readonly string $currency,
        public readonly string $stockStatus,
        public readonly array $categories,
        public readonly array $attributes,
        public readonly string $permalink,
        public readonly int $imageId,
        public readonly string $observedAt,
        public readonly float $minimumQuantity = 1.0,
        public readonly ?float $maximumQuantity = null,
        public readonly ?float $stockQuantity = null,
        public readonly bool $managesStock = false,
        public readonly bool $soldIndividually = false
    ) {
        if ($productId < 1 || $parentId < 0 || $name === '' || $productType === '' || $currency === '') {
            throw new \InvalidArgumentException('Product candidate identity is invalid.');
        }
        if ($unitPrice !== null && (!is_finite($unitPrice) || $unitPrice < 0)) {
            throw new \InvalidArgumentException('Product candidate price is invalid.');
        }
        if (!is_finite($minimumQuantity) || $minimumQuantity <= 0
            || ($maximumQuantity !== null
                && (!is_finite($maximumQuantity) || $maximumQuantity < $minimumQuantity))
            || ($stockQuantity !== null && !is_finite($stockQuantity))
            || !array_is_list($categories) || count($categories) > 100
            || ($attributes !== [] && array_is_list($attributes)) || count($attributes) > 100
        ) {
            throw new \InvalidArgumentException('Product candidate commerce constraints are invalid.');
        }
        foreach ($categories as $category) {
            if (!is_string($category) || $category === '' || strlen($category) > 200) {
                throw new \InvalidArgumentException('Product candidate category is invalid.');
            }
        }
        foreach ($attributes as $name => $values) {
            if (!is_string($name) || $name === '' || strlen($name) > 200
                || !is_array($values) || !array_is_list($values) || count($values) > 100
            ) {
                throw new \InvalidArgumentException('Product candidate attribute is invalid.');
            }
            foreach ($values as $value) {
                if (!is_string($value) || $value === '' || strlen($value) > 200) {
                    throw new \InvalidArgumentException('Product candidate attribute value is invalid.');
                }
            }
        }
        new \DateTimeImmutable($observedAt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'parent_id' => $this->parentId,
            'name' => $this->name,
            'sku' => $this->sku,
            'product_type' => $this->productType,
            'visible' => $this->visible,
            'purchasable' => $this->purchasable,
            'in_stock' => $this->inStock,
            'backorders_allowed' => $this->backordersAllowed,
            'unit_price' => $this->unitPrice,
            'currency' => $this->currency,
            'stock_status' => $this->stockStatus,
            'categories' => $this->categories,
            'attributes' => $this->attributeProjection(),
            'permalink' => $this->permalink,
            'image_id' => $this->imageId,
            'observed_at' => $this->observedAt,
            'minimum_quantity' => $this->minimumQuantity,
            'maximum_quantity' => $this->maximumQuantity,
            'stock_quantity' => $this->stockQuantity,
            'manages_stock' => $this->managesStock,
            'sold_individually' => $this->soldIndividually,
            'evidence' => [
                'source' => 'woocommerce_runtime',
                'product_id' => $this->productId,
                'observed_at' => $this->observedAt,
            ],
            'compatibility_verified' => false,
            'exact_configuration_required' => $this->productType === 'variable',
        ];
    }

    /** @return list<array{name:string,values:list<string>}> */
    private function attributeProjection(): array
    {
        $attributes = $this->attributes;
        ksort($attributes, SORT_STRING);
        $projection = [];
        foreach ($attributes as $name => $values) {
            $values = array_values($values);
            sort($values, SORT_STRING);
            $projection[] = ['name' => $name, 'values' => $values];
        }

        return $projection;
    }
}
