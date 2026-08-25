<?php
declare(strict_types=1);

namespace Veyra\Requirements\Domain;

final class RequirementCriterion
{
    private const MAXIMUM_VALUE_NODES = 256;
    public const FIELDS = [
        'budget', 'quantity', 'unit', 'use_case', 'recipient', 'compatibility',
        'location', 'timing', 'already_owned', 'product_type', 'category',
        'attribute', 'product_id', 'stock', 'purchasable', 'exclusion', 'preference',
    ];

    public const OPERATORS = [
        'equals', 'not_equals', 'max', 'min', 'in', 'not_in', 'contains',
        'excludes', 'requires', 'before', 'after', 'any_of',
    ];

    public const STRENGTHS = ['hard', 'soft'];
    public const STATUSES = ['active', 'proposed', 'unknown', 'disputed', 'superseded'];

    /**
     * @param scalar|array<array-key, mixed>|null $value
     * @param array<string, int|string> $source
     * @param array<int, string> $supersedes
     */
    private function __construct(
        public readonly string $id,
        public readonly string $field,
        public readonly string $operator,
        public readonly mixed $value,
        public readonly string $strength,
        public readonly string $status,
        public readonly string $verification,
        public readonly array $source,
        public readonly array $supersedes,
        public readonly ?string $supersededBy,
        public readonly int $version,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $statusSourceMessageId
    ) {
    }

    /** @param array<string, int|string> $source */
    public static function proposed(
        string $field,
        string $operator,
        mixed $value,
        string $strength,
        string $status,
        array $source,
        array $supersedes,
        string $now
    ): self {
        self::validateCore($field, $operator, $value, $strength, $status);
        self::validateSource($source);
        if ($status === 'superseded') {
            throw new \InvalidArgumentException('A new requirement cannot start superseded.');
        }
        return new self(
            'req_' . bin2hex(random_bytes(16)),
            $field,
            $operator,
            self::normalizedValue($value),
            $strength,
            $status,
            'shopper_message_exact_excerpt',
            $source,
            self::ids($supersedes),
            null,
            1,
            $now,
            $now,
            null
        );
    }

    /** @param array<string, mixed> $row */
    public static function fromStored(array $row): self
    {
        $allowedKeys = [
            'id', 'field', 'operator', 'value', 'strength', 'status',
            'verification', 'source', 'supersedes', 'superseded_by', 'version',
            'created_at', 'updated_at', 'status_source_message_id',
        ];
        if (array_diff(array_keys($row), $allowedKeys) !== []) {
            throw new \InvalidArgumentException('Stored requirement contains unsupported fields.');
        }
        foreach ([
            'id', 'field', 'operator', 'value', 'strength', 'status',
            'verification', 'source', 'supersedes', 'version', 'created_at', 'updated_at',
        ] as $key) {
            if (!array_key_exists($key, $row)) {
                throw new \InvalidArgumentException('Stored requirement is incomplete.');
            }
        }
        if (!is_string($row['id']) || preg_match('/^req_[a-f0-9]{32}$/', $row['id']) !== 1) {
            throw new \InvalidArgumentException('Stored requirement id is invalid.');
        }
        self::validateCore($row['field'], $row['operator'], $row['value'], $row['strength'], $row['status']);
        if ($row['verification'] !== 'shopper_message_exact_excerpt'
            || !is_array($row['source']) || !is_array($row['supersedes'])
            || !is_int($row['version']) || $row['version'] < 1
            || !is_string($row['created_at']) || !is_string($row['updated_at'])) {
            throw new \InvalidArgumentException('Stored requirement metadata is invalid.');
        }
        self::validateSource($row['source']);
        $createdAt = self::date($row['created_at']);
        $updatedAt = self::date($row['updated_at']);
        if (strcmp($updatedAt, $createdAt) < 0) {
            throw new \InvalidArgumentException('Stored requirement timestamps are invalid.');
        }
        $supersededBy = $row['superseded_by'] ?? null;
        if ($supersededBy !== null && (!is_string($supersededBy) || ($supersededBy !== 'removed' && preg_match('/^req_[a-f0-9]{32}$/', $supersededBy) !== 1))) {
            throw new \InvalidArgumentException('Stored supersession reference is invalid.');
        }
        $statusSource = $row['status_source_message_id'] ?? null;
        if ($statusSource !== null && (!is_string($statusSource)
            || strlen($statusSource) > 36
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $statusSource) !== 1
        )) {
            throw new \InvalidArgumentException('Stored status provenance is invalid.');
        }
        return new self(
            $row['id'],
            $row['field'],
            $row['operator'],
            self::normalizedValue($row['value']),
            $row['strength'],
            $row['status'],
            $row['verification'],
            $row['source'],
            self::ids($row['supersedes']),
            $supersededBy,
            $row['version'],
            $createdAt,
            $updatedAt,
            $statusSource
        );
    }

    public function withStatus(string $status, ?string $supersededBy, string $sourceMessageId, string $now): self
    {
        if (!in_array($status, ['disputed', 'superseded'], true)) {
            throw new \InvalidArgumentException('Requirement status transition is invalid.');
        }
        if ($this->status === 'superseded') {
            throw new \InvalidArgumentException('A superseded requirement is immutable.');
        }
        return new self(
            $this->id,
            $this->field,
            $this->operator,
            $this->value,
            $this->strength,
            $status,
            $this->verification,
            $this->source,
            $this->supersedes,
            $supersededBy,
            $this->version + 1,
            $this->createdAt,
            self::date($now),
            $sourceMessageId
        );
    }

    /**
     * Upgrade-only safety downgrade. Exact excerpt provenance can be
     * revalidated deterministically, but an old stored field/operator/value is
     * not thereby proven semantically entailed. Preserve the record and source
     * while keeping it out of the active recommendation projection.
     */
    public function quarantineLegacyActive(string $now): self
    {
        if ($this->status !== 'active') {
            return $this;
        }

        return new self(
            $this->id,
            $this->field,
            $this->operator,
            $this->value,
            $this->strength,
            'proposed',
            $this->verification,
            $this->source,
            $this->supersedes,
            $this->supersededBy,
            $this->version + 1,
            $this->createdAt,
            self::date($now),
            $this->statusSourceMessageId
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'field' => $this->field,
            'operator' => $this->operator,
            'value' => $this->value,
            'strength' => $this->strength,
            'status' => $this->status,
            'verification' => $this->verification,
            'source' => $this->source,
            'supersedes' => $this->supersedes,
            'superseded_by' => $this->supersededBy,
            'version' => $this->version,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'status_source_message_id' => $this->statusSourceMessageId,
        ];
    }

    private static function validateCore(mixed $field, mixed $operator, mixed $value, mixed $strength, mixed $status): void
    {
        if (!is_string($field) || !in_array($field, self::FIELDS, true)
            || !is_string($operator) || !in_array($operator, self::OPERATORS, true)
            || !is_string($strength) || !in_array($strength, self::STRENGTHS, true)
            || !is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Requirement criterion contract is invalid.');
        }
        $nodes = 0;
        self::validateValue($value, 0, $nodes);
    }

    private static function validateValue(mixed $value, int $depth, int &$nodes): void
    {
        ++$nodes;
        if ($nodes > self::MAXIMUM_VALUE_NODES
            || $depth > 5 || is_object($value) || is_resource($value)
        ) {
            throw new \InvalidArgumentException('Requirement value is unsafe.');
        }
        if (is_string($value)) {
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($length > 500) {
                throw new \InvalidArgumentException('Requirement value is too long.');
            }
            return;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return;
        }
        if (!is_array($value) || count($value) > 30) {
            throw new \InvalidArgumentException('Requirement value is invalid.');
        }
        foreach ($value as $key => $item) {
            if (!is_int($key) && (!is_string($key) || strlen($key) > 64)) {
                throw new \InvalidArgumentException('Requirement value key is invalid.');
            }
            self::validateValue($item, $depth + 1, $nodes);
        }
    }

    /** @param array<string, mixed> $source */
    private static function validateSource(array $source): void
    {
        $keys = ['message_id', 'excerpt_sha256', 'excerpt_offset_bytes', 'excerpt_length_bytes', 'source_kind'];
        if (array_diff(array_keys($source), $keys) !== [] || array_diff($keys, array_keys($source)) !== []) {
            throw new \InvalidArgumentException('Requirement source provenance has unsupported fields.');
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                throw new \InvalidArgumentException('Requirement source provenance is incomplete.');
            }
        }
        if (!is_string($source['message_id'])
            || strlen($source['message_id']) > 36
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $source['message_id']) !== 1
            || !is_string($source['excerpt_sha256']) || preg_match('/^[a-f0-9]{64}$/', $source['excerpt_sha256']) !== 1
            || !is_int($source['excerpt_offset_bytes']) || $source['excerpt_offset_bytes'] < 0
            || !is_int($source['excerpt_length_bytes']) || $source['excerpt_length_bytes'] < 1 || $source['excerpt_length_bytes'] > 2000
            || $source['source_kind'] !== 'customer_visible_message') {
            throw new \InvalidArgumentException('Requirement source provenance is invalid.');
        }
    }

    /** @param array<int, mixed> $ids @return array<int, string> */
    private static function ids(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/^req_[a-f0-9]{32}$/', $id) !== 1) {
                throw new \InvalidArgumentException('Requirement reference is invalid.');
            }
            $result[] = $id;
        }
        return array_values(array_unique($result));
    }

    private static function date(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Requirement timestamp is invalid.');
        }
    }

    private static function normalizedValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'normalizedValue'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalizedValue($item);
        }
        return $value;
    }
}
