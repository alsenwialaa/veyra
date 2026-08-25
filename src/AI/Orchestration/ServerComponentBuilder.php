<?php
declare(strict_types=1);

namespace Veyra\AI\Orchestration;

use Veyra\AI\Contract\ToolResult;

final class ServerComponentBuilder
{
    /**
     * @param array<int, mixed>      $intentions
     * @param array<int, ToolResult> $toolResults
     * @return array<int, array<string, mixed>>
     */
    public function build(array $intentions, array $toolResults): array
    {
        $byCall = [];
        foreach ($toolResults as $result) {
            $byCall[$result->callId] = $result;
        }
        $components = [];
        foreach (array_slice($intentions, 0, 20) as $intention) {
            if (!is_array($intention)) {
                continue;
            }
            $type = (string) ($intention['type'] ?? '');
            if (!in_array($type, ['product', 'comparison', 'cart', 'checkout', 'order', 'crm', 'payment_review', 'branch', 'hours', 'notice', 'choices'], true)) {
                continue;
            }
            $callId = (string) ($intention['evidence_call_id'] ?? '');
            if ($type === 'notice' || $type === 'choices') {
                $components[] = [
                    'schema_version' => '1.0.0',
                    'type' => $type,
                    'label' => is_string($intention['label'] ?? null) ? $intention['label'] : '',
                    'choices' => $type === 'choices' && is_array($intention['choices'] ?? null) ? array_slice($intention['choices'], 0, 8) : [],
                    'authoritative' => false,
                ];
                continue;
            }
            $result = $byCall[$callId] ?? null;
            if (!$result instanceof ToolResult || $result->status !== 'succeeded' || !$result->authoritative) {
                continue;
            }
            $payload = $result->data;
            if ($type === 'product' || $type === 'comparison') {
                $payload = $this->exactProductPayload($type, $intention, $result);
                if ($payload === null) {
                    continue;
                }
            }
            $sourceObservedAt = is_array($payload) && is_string($payload['observed_at'] ?? null)
                ? $this->boundedText($payload['observed_at'], 64)
                : '';
            $observedAt = $sourceObservedAt !== '' ? $sourceObservedAt : gmdate(DATE_ATOM);
            $components[] = [
                'schema_version' => '1.0.0',
                'type' => $type,
                'source_tool' => $result->tool,
                'source_call_id' => $result->callId,
                'payload' => $payload,
                'observed_at' => $observedAt,
                'authoritative' => true,
                'historical' => true,
                'actions_require_current_revalidation' => true,
            ];
        }
        return $components;
    }

    /**
     * Derive bounded immutable context references only from already verified,
     * server-built product/comparison components. These records never confer
     * current commerce authorization; a later turn must re-resolve the source.
     *
     * @param array<int, array<string, mixed>> $components
     * @return array<int, array<string, mixed>>
     */
    public function productReferenceSnapshots(array $components): array
    {
        $references = [];
        foreach ($components as $component) {
            if (!is_array($component)
                || !in_array($component['type'] ?? null, ['product', 'comparison'], true)
                || ($component['authoritative'] ?? false) !== true
            ) {
                continue;
            }
            $payload = is_array($component['payload'] ?? null) ? $component['payload'] : [];
            $snapshots = ($component['type'] ?? null) === 'product'
                ? [$payload]
                : (is_array($payload['products'] ?? null) && array_is_list($payload['products']) ? $payload['products'] : []);
            foreach ($snapshots as $raw) {
                if (!is_array($raw) || count($references) >= 3) {
                    continue;
                }
                $identity = $this->snapshotIdentity($raw);
                if ($identity === null) {
                    continue;
                }
                $snapshot = $this->projectCatalogSnapshot($raw, $identity);
                if ($snapshot === null) {
                    continue;
                }
                if (!isset($snapshot['observed_at']) && is_string($component['observed_at'] ?? null)) {
                    $snapshot['observed_at'] = substr($component['observed_at'], 0, 64);
                }
                $key = $identity['product_id'] . ':' . $identity['variation_id'];
                $references[$key] = $snapshot;
            }
        }

        return array_values(array_slice($references, 0, 3));
    }

    /**
     * A presentation intention must name the exact identity it is presenting.
     * The server then resolves that tuple only inside a known catalog result
     * shape. No arbitrary nested IDs and no positional candidate selection can
     * become a customer-visible product card.
     *
     * @param array<string, mixed> $intention
     * @return array<string, mixed>|null
     */
    private function exactProductPayload(string $type, array $intention, ToolResult $result): ?array
    {
        $targets = $intention['product_targets'] ?? null;
        if (!is_array($targets) || !array_is_list($targets)) {
            return null;
        }
        $targetCount = count($targets);
        if (($type === 'product' && $targetCount !== 1)
            || ($type === 'comparison' && ($targetCount < 2 || $targetCount > 4))
        ) {
            return null;
        }

        $catalog = [];
        $duplicates = [];
        foreach ($this->catalogSnapshots($result) as $raw) {
            $identity = is_array($raw) ? $this->snapshotIdentity($raw) : null;
            if ($identity === null) {
                continue;
            }
            $key = $identity['product_id'] . ':' . $identity['variation_id'];
            if (isset($catalog[$key])) {
                $duplicates[$key] = true;
                continue;
            }
            $catalog[$key] = $raw;
        }

        $selected = [];
        $seen = [];
        foreach ($targets as $target) {
            $identity = is_array($target) ? $this->snapshotIdentity($target) : null;
            if ($identity === null) {
                return null;
            }
            $key = $identity['product_id'] . ':' . $identity['variation_id'];
            if (isset($seen[$key]) || isset($duplicates[$key]) || !isset($catalog[$key])) {
                return null;
            }
            $projected = $this->projectCatalogSnapshot($catalog[$key], $identity);
            if ($projected === null) {
                return null;
            }
            $seen[$key] = true;
            $selected[] = $projected;
        }

        if ($type === 'product') {
            return $selected[0];
        }

        $payload = [
            'products' => $selected,
            'product_count' => count($selected),
            'historical' => true,
        ];
        if (is_string($result->data['observed_at'] ?? null)) {
            $observedAt = $this->boundedText($result->data['observed_at'], 64);
            if ($observedAt !== '') {
                $payload['observed_at'] = $observedAt;
            }
        }

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    private function catalogSnapshots(ToolResult $result): array
    {
        $data = $result->data;
        $snapshots = match ($result->tool) {
            'catalog.search_products',
            'catalog.get_related_products',
            'catalog.find_alternatives' => $data['candidates'] ?? [],
            'catalog.get_variations' => $data['variations'] ?? [],
            'catalog.compare_products' => $data['products'] ?? [],
            'catalog.get_product' => isset($data['product']) ? [$data['product']] : [],
            'catalog.resolve_variation' => ($data['resolved'] ?? false) === true && isset($data['variation'])
                ? [$data['variation']]
                : [],
            'catalog.resolve_product_reference' => isset($data['reference']['current_state'])
                ? [$data['reference']['current_state']]
                : [],
            default => [],
        };

        return is_array($snapshots) && array_is_list($snapshots) ? $snapshots : [];
    }

    /** @param array<string, mixed> $raw @return array{product_id:int,variation_id:int}|null */
    private function snapshotIdentity(array $raw): ?array
    {
        if (!isset($raw['product_id'], $raw['variation_id'])
            || !is_int($raw['product_id'])
            || !is_int($raw['variation_id'])
            || $raw['product_id'] < 1
            || $raw['variation_id'] < 0
        ) {
            return null;
        }

        return ['product_id' => $raw['product_id'], 'variation_id' => $raw['variation_id']];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array{product_id:int,variation_id:int} $identity
     * @return array<string, mixed>|null
     */
    private function projectCatalogSnapshot(array $raw, array $identity): ?array
    {
        $actual = $this->snapshotIdentity($raw);
        $name = $raw['name'] ?? null;
        if ($actual !== $identity || !is_string($name) || trim($name) === '') {
            return null;
        }
        $boundedName = $this->boundedText($name, 500);
        if ($boundedName === '') {
            return null;
        }

        $snapshot = [
            'product_id' => $identity['product_id'],
            'variation_id' => $identity['variation_id'],
            'name' => $boundedName,
            'image_alt' => $boundedName,
            'historical' => true,
            'commerce_authorization' => false,
        ];

        $productType = is_string($raw['product_type'] ?? null)
            ? $raw['product_type']
            : (is_string($raw['type'] ?? null) ? $raw['type'] : null);
        if ($productType !== null && $productType !== '') {
            $boundedProductType = $this->boundedText($productType, 80);
            if ($boundedProductType !== '') {
                $snapshot['product_type'] = $boundedProductType;
            }
        }
        $snapshot['exact_configuration_required'] = $identity['variation_id'] === 0 && $productType === 'variable';

        $image = is_string($raw['image_url'] ?? null)
            ? $raw['image_url']
            : (is_string($raw['image'] ?? null) ? $raw['image'] : null);
        if ($image !== null && $image !== '') {
            $boundedImage = $this->boundedText($image, 2048);
            if ($boundedImage !== '') {
                $snapshot['image_url'] = $boundedImage;
            }
        }
        if (is_string($raw['image_alt'] ?? null) && $raw['image_alt'] !== '') {
            $boundedAlt = $this->boundedText($raw['image_alt'], 500);
            if ($boundedAlt !== '') {
                $snapshot['image_alt'] = $boundedAlt;
            }
        }

        foreach ([
            'sku', 'permalink', 'price', 'regular_price', 'sale_price', 'currency',
            'on_sale', 'stock_status', 'stock_quantity', 'purchasable',
            'historical_snapshot_version', 'observed_at',
        ] as $key) {
            if (!array_key_exists($key, $raw) || $raw[$key] === null || !is_scalar($raw[$key])) {
                continue;
            }
            if (is_string($raw[$key])) {
                $bounded = $this->boundedText($raw[$key], $key === 'permalink' ? 2048 : 500);
                if ($bounded === '' && $raw[$key] !== '') {
                    continue;
                }
                $snapshot[$key] = $bounded;
            } else {
                $snapshot[$key] = $raw[$key];
            }
        }
        if (isset($snapshot['stock_status'])) {
            $snapshot['stock'] = $snapshot['stock_status'];
        }

        $categories = $this->projectCategories($raw['categories'] ?? null);
        if ($categories !== []) {
            $snapshot['categories'] = $categories;
        }
        $attributes = $this->projectAttributes($raw['attributes'] ?? null);
        if ($attributes !== []) {
            $snapshot['attributes'] = $attributes;
            if ($identity['variation_id'] > 0) {
                $variation = [];
                foreach ($attributes as $attribute) {
                    if (($attribute['variation'] ?? false) !== true || ($attribute['options'] ?? []) === []) {
                        continue;
                    }
                    $variation[] = $this->boundedText($attribute['name'] . ': ' . implode(', ', $attribute['options']), 500);
                }
                if ($variation !== []) {
                    $snapshot['variation'] = $variation;
                }
            }
        }

        return $snapshot;
    }

    /** @return array<int, array<string, mixed>> */
    private function projectCategories(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }
        $categories = [];
        foreach (array_slice($raw, 0, 20) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $category = [];
            if (is_int($item['id'] ?? null) && $item['id'] > 0) {
                $category['id'] = $item['id'];
            }
            foreach (['name', 'slug'] as $key) {
                if (is_string($item[$key] ?? null) && $item[$key] !== '') {
                    $bounded = $this->boundedText($item[$key], 191);
                    if ($bounded !== '') {
                        $category[$key] = $bounded;
                    }
                }
            }
            if ($category !== []) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /** @return array<int, array<string, mixed>> */
    private function projectAttributes(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }
        $attributes = [];
        foreach (array_slice($raw, 0, 20) as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null) || $item['name'] === '') {
                continue;
            }
            $name = $this->boundedText($item['name'], 191);
            if ($name === '') {
                continue;
            }
            $options = [];
            if (is_array($item['options'] ?? null) && array_is_list($item['options'])) {
                foreach (array_slice($item['options'], 0, 20) as $option) {
                    if (is_scalar($option) && (string) $option !== '') {
                        $bounded = $this->boundedText((string) $option, 191);
                        if ($bounded !== '') {
                            $options[] = $bounded;
                        }
                    }
                }
            }
            $attributes[] = [
                'name' => $name,
                'options' => $options,
                'variation' => ($item['variation'] ?? false) === true,
            ];
        }

        return $attributes;
    }

    private function boundedText(string $value, int $maxBytes): string
    {
        if (preg_match('//u', $value) !== 1) {
            return '';
        }
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }
        $bounded = substr($value, 0, $maxBytes);
        while ($bounded !== '' && preg_match('//u', $bounded) !== 1) {
            $bounded = substr($bounded, 0, -1);
        }

        return $bounded;
    }
}
