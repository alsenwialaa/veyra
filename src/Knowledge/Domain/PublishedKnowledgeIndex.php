<?php
declare(strict_types=1);

namespace Veyra\Knowledge\Domain;

final class PublishedKnowledgeIndex
{
    /** @param array<int, KnowledgeSource> $sources */
    private function __construct(
        public readonly string $publicationId,
        public readonly string $version,
        public readonly int $storeId,
        public readonly string $publishedAt,
        private readonly array $sources
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPublishedPayload(array $payload, int $expectedStoreId): self
    {
        foreach (['schema_version', 'status', 'publication_id', 'version', 'store_id', 'published_at', 'sources'] as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new \InvalidArgumentException('Published knowledge index is incomplete.');
            }
        }
        if ($payload['schema_version'] !== '1.0.0' || $payload['status'] !== 'published') {
            throw new \InvalidArgumentException('Knowledge index is not a supported publication.');
        }
        if (!is_int($payload['store_id']) || $payload['store_id'] !== $expectedStoreId) {
            throw new \InvalidArgumentException('Knowledge index belongs to another store.');
        }
        if (!is_string($payload['publication_id']) || $payload['publication_id'] === ''
            || !is_string($payload['version']) || $payload['version'] === ''
            || !is_string($payload['published_at']) || $payload['published_at'] === '') {
            throw new \InvalidArgumentException('Knowledge publication identity is invalid.');
        }
        try {
            new \DateTimeImmutable($payload['published_at']);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Knowledge publication date is invalid.');
        }
        if (!is_array($payload['sources']) || !array_is_list($payload['sources']) || count($payload['sources']) > 10000) {
            throw new \InvalidArgumentException('Knowledge publication sources are invalid.');
        }
        $sources = [];
        foreach ($payload['sources'] as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Knowledge publication source is invalid.');
            }
            $source = KnowledgeSource::fromPublishedRow($row);
            if (isset($sources[$source->id])) {
                throw new \InvalidArgumentException('Knowledge source ids must be unique.');
            }
            $sources[$source->id] = $source;
        }
        return new self(
            $payload['publication_id'],
            $payload['version'],
            $payload['store_id'],
            (new \DateTimeImmutable($payload['published_at']))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            array_values($sources)
        );
    }

    /** @return array<int, KnowledgeSource> */
    public function sources(): array
    {
        return $this->sources;
    }

    public function source(string $sourceId): ?KnowledgeSource
    {
        foreach ($this->sources as $source) {
            if ($source->id === $sourceId) {
                return $source;
            }
        }
        return null;
    }

    /** @return array<string, string|int> */
    public function publicationMetadata(): array
    {
        return [
            'publication_id' => $this->publicationId,
            'publication_version' => $this->version,
            'store_id' => $this->storeId,
            'published_at' => $this->publishedAt,
        ];
    }
}
