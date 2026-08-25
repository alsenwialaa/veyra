<?php
declare(strict_types=1);

namespace Veyra\Experience\Contract;

/**
 * One deterministic identity contract for displayed product references and
 * the bounded client binding that may carry them into a later turn.
 *
 * A binding is context only. It proves which immutable, actor-owned display
 * snapshot the shopper selected; every commerce operation must still resolve
 * and authorize the product from current WooCommerce state.
 */
final class ProductReferenceIdentity
{
    public const REFERENCE_SCHEMA_VERSION = 'veyra.product_reference.v1';
    public const BINDING_SCHEMA_VERSION = 'veyra.product_reference_binding.v1';

    /** @var list<string> */
    private const BINDING_KEYS = [
        'schema_version',
        'reference_id',
        'source_message_id',
        'product_id',
        'variation_id',
    ];

    /**
     * Rebuilds the exact public references exposed by CustomerMessagePresenter.
     *
     * @param list<mixed> $raw
     * @return list<array<string, mixed>>
     */
    public static function presentReferences(array $raw, string $fallbackSourceMessageId): array
    {
        if (!self::opaqueId($fallbackSourceMessageId)) {
            return [];
        }

        $result = [];
        $seen = [];
        foreach (array_slice($raw, 0, 100) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            // A customer message persists only a server-verified binding and
            // its one matched historical snapshot. Preserve that original
            // identity when the message itself is rendered from history.
            $stored = self::storedBinding($item);
            if ($stored !== null) {
                $snapshot = $item['historical_references'][0];
                self::appendPresented($result, $seen, $stored, $snapshot);
                if (count($result) >= 3) {
                    break;
                }
                continue;
            }

            $source = self::opaqueId($item['source_message_id'] ?? null)
                ? (string) $item['source_message_id']
                : $fallbackSourceMessageId;
            $snapshots = is_array($item['historical_references'] ?? null)
                && array_is_list($item['historical_references'])
                    ? $item['historical_references']
                    : [$item];
            foreach (array_slice($snapshots, 0, 100) as $snapshot) {
                if (!is_array($snapshot)) {
                    continue;
                }
                $identity = self::snapshotIdentity($snapshot);
                $referenceId = $identity === null ? null : self::referenceId($source, $snapshot, (int) $index);
                if ($identity === null || $referenceId === null) {
                    continue;
                }
                self::appendPresented($result, $seen, [
                    'schema_version' => self::BINDING_SCHEMA_VERSION,
                    'reference_id' => $referenceId,
                    'source_message_id' => $source,
                    'product_id' => $identity['product_id'],
                    'variation_id' => $identity['variation_id'],
                ], $snapshot);
                if (count($result) >= 3) {
                    break 2;
                }
            }
        }

        return $result;
    }

    /** @param mixed $value @return array<string, int|string>|null */
    public static function commandBinding(mixed $value): ?array
    {
        if (!is_array($value)
            || array_diff(array_keys($value), self::BINDING_KEYS) !== []
            || array_diff(self::BINDING_KEYS, array_keys($value)) !== []
            || ($value['schema_version'] ?? null) !== self::BINDING_SCHEMA_VERSION
            || !self::opaqueId($value['reference_id'] ?? null)
            || !self::opaqueId($value['source_message_id'] ?? null)
            || !is_int($value['product_id'] ?? null)
            || $value['product_id'] < 1
            || !is_int($value['variation_id'] ?? null)
            || $value['variation_id'] < 0
        ) {
            return null;
        }

        return [
            'schema_version' => self::BINDING_SCHEMA_VERSION,
            'reference_id' => $value['reference_id'],
            'source_message_id' => $value['source_message_id'],
            'product_id' => $value['product_id'],
            'variation_id' => $value['variation_id'],
        ];
    }

    /** @param array<string, mixed> $value @return array<string, int|string>|null */
    public static function storedBinding(array $value): ?array
    {
        $allowed = array_merge(self::BINDING_KEYS, ['historical_references']);
        if (array_diff(array_keys($value), $allowed) !== []
            || array_diff($allowed, array_keys($value)) !== []
            || !is_array($value['historical_references'] ?? null)
            || !array_is_list($value['historical_references'])
            || count($value['historical_references']) !== 1
            || !is_array($value['historical_references'][0] ?? null)
        ) {
            return null;
        }
        $binding = self::commandBinding(array_intersect_key($value, array_flip(self::BINDING_KEYS)));
        $identity = self::snapshotIdentity($value['historical_references'][0]);
        if ($binding === null || $identity === null
            || $binding['product_id'] !== $identity['product_id']
            || $binding['variation_id'] !== $identity['variation_id']
        ) {
            return null;
        }

        return $binding;
    }

    /**
     * Returns the one exact public reference selected by a client binding.
     * A token/tuple mismatch or ambiguous match fails closed.
     *
     * @param list<mixed> $sourceReferences
     * @param array<string, mixed> $binding
     * @return array<string, mixed>|null
     */
    public static function match(array $sourceReferences, string $sourceMessageId, array $binding): ?array
    {
        $binding = self::commandBinding($binding);
        if ($binding === null || !hash_equals($sourceMessageId, (string) $binding['source_message_id'])) {
            return null;
        }

        $matched = null;
        foreach (self::presentReferences($sourceReferences, $sourceMessageId) as $reference) {
            $identity = self::snapshotIdentity(is_array($reference['snapshot'] ?? null) ? $reference['snapshot'] : []);
            if ($identity === null
                || !hash_equals((string) $reference['reference_id'], (string) $binding['reference_id'])
                || !hash_equals((string) $reference['source_message_id'], (string) $binding['source_message_id'])
                || $identity['product_id'] !== $binding['product_id']
                || $identity['variation_id'] !== $binding['variation_id']
            ) {
                continue;
            }
            if ($matched !== null) {
                return null;
            }
            $matched = $reference;
        }

        return $matched;
    }

    /** @param array<string, mixed> $snapshot @return array{product_id:int,variation_id:int}|null */
    public static function snapshotIdentity(array $snapshot): ?array
    {
        if (!is_int($snapshot['product_id'] ?? null) || $snapshot['product_id'] < 1
            || !is_int($snapshot['variation_id'] ?? null) || $snapshot['variation_id'] < 0
        ) {
            return null;
        }

        return [
            'product_id' => $snapshot['product_id'],
            'variation_id' => $snapshot['variation_id'],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private static function referenceId(string $sourceMessageId, array $snapshot, int $index): ?string
    {
        try {
            $encoded = json_encode(
                [$sourceMessageId, $snapshot, $index],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (\Throwable) {
            return null;
        }

        return 'ref:' . substr(hash('sha256', $encoded), 0, 32);
    }

    /**
     * @param list<array<string, mixed>> $result
     * @param array<string, true> $seen
     * @param array<string, int|string> $binding
     * @param array<string, mixed> $snapshot
     */
    private static function appendPresented(array &$result, array &$seen, array $binding, array $snapshot): void
    {
        $referenceId = (string) $binding['reference_id'];
        if (isset($seen[$referenceId]) || count($result) >= 3) {
            return;
        }
        $seen[$referenceId] = true;
        $result[] = [
            'schema_version' => self::REFERENCE_SCHEMA_VERSION,
            'reference_id' => $referenceId,
            'source_message_id' => (string) $binding['source_message_id'],
            'snapshot' => $snapshot,
            'context_only' => true,
            'commerce_authorization' => false,
        ];
    }

    private static function opaqueId(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) === 1;
    }
}
