<?php
declare(strict_types=1);

namespace Veyra\Recommendation\Domain;

final class RecommendationPolicy
{
    private const WEIGHTS = [
        'availability', 'budget', 'quantity', 'product_type', 'category',
        'attribute', 'product_id', 'stock', 'purchasable', 'exclusion',
        'already_owned', 'unit', 'use_case', 'recipient', 'compatibility',
        'location', 'timing', 'preference',
    ];

    /** @param array<string, float> $weights */
    private function __construct(
        public readonly string $publicationId,
        public readonly string $version,
        public readonly int $storeId,
        public readonly string $publishedAt,
        private readonly array $weights
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPublishedPayload(array $payload, int $expectedStoreId): self
    {
        foreach (['schema_version', 'status', 'publication_id', 'version', 'store_id', 'published_at', 'weights'] as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new \InvalidArgumentException('Published recommendation policy is incomplete.');
            }
        }
        if ($payload['schema_version'] !== '1.0.0' || $payload['status'] !== 'published'
            || !is_int($payload['store_id']) || $payload['store_id'] !== $expectedStoreId
            || !is_string($payload['publication_id']) || $payload['publication_id'] === ''
            || !is_string($payload['version']) || $payload['version'] === ''
            || !is_string($payload['published_at']) || $payload['published_at'] === ''
            || !is_array($payload['weights']) || array_is_list($payload['weights'])) {
            throw new \InvalidArgumentException('Published recommendation policy is invalid.');
        }
        new \DateTimeImmutable($payload['published_at']);
        if (array_diff(array_keys($payload['weights']), self::WEIGHTS)) {
            throw new \InvalidArgumentException('Recommendation policy contains an unapproved weight.');
        }
        $weights = [];
        foreach (self::WEIGHTS as $key) {
            $value = $payload['weights'][$key] ?? 1.0;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > 10) {
                throw new \InvalidArgumentException('Recommendation policy weight is invalid.');
            }
            $weights[$key] = (float) $value;
        }
        return new self(
            $payload['publication_id'],
            $payload['version'],
            $payload['store_id'],
            (new \DateTimeImmutable($payload['published_at']))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            $weights
        );
    }

    public function weight(string $field): float
    {
        return $this->weights[$field] ?? 0.0;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return [
            'publication_id' => $this->publicationId,
            'version' => $this->version,
            'store_id' => $this->storeId,
            'published_at' => $this->publishedAt,
            'weights' => $this->weights,
        ];
    }
}
