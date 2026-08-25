<?php
declare(strict_types=1);

namespace Veyra\Knowledge\Application;

use Veyra\Knowledge\Contract\PublishedKnowledgeRepository;
use Veyra\Knowledge\Domain\KnowledgeSource;
use Veyra\Knowledge\Domain\PublishedKnowledgeIndex;
use Veyra\Shared\Domain\Clock;

final class KnowledgeService
{
    public function __construct(
        private readonly PublishedKnowledgeRepository $repository,
        private readonly Clock $clock
    ) {
    }

    /**
     * @param array<int, string> $types
     * @return array<string, mixed>
     */
    public function search(
        string $query,
        string $actorType,
        string $locale,
        ?string $market,
        ?string $branch,
        array $types,
        int $limit
    ): array {
        $index = $this->repository->published();
        if (!$index instanceof PublishedKnowledgeIndex) {
            return $this->unavailable();
        }
        $query = trim($query);
        if ($query === '') {
            return ['ok' => false, 'code' => 'knowledge_query_empty'];
        }
        $allowedTypes = [
            'policy', 'product_guide', 'shipping_policy', 'payment_policy',
            'return_policy', 'faq',
        ];
        if ($types !== [] && array_diff($types, $allowedTypes)) {
            return ['ok' => false, 'code' => 'knowledge_source_type_invalid'];
        }
        $terms = $this->terms($query);
        $matches = [];
        foreach ($index->sources() as $source) {
            if (!$this->eligible($source, $actorType, $locale, $market, $branch, true)) {
                continue;
            }
            if ($types !== [] && !in_array($source->type, $types, true)) {
                continue;
            }
            $score = $this->score($source, $query, $terms);
            if ($score <= 0) {
                continue;
            }
            $matches[] = ['score' => $score, 'source' => $source];
        }
        usort($matches, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            return $score !== 0 ? $score : strcmp($left['source']->id, $right['source']->id);
        });
        $totalMatching = count($matches);
        $results = [];
        foreach (array_slice($matches, 0, max(1, min(12, $limit))) as $match) {
            /** @var KnowledgeSource $source */
            $source = $match['source'];
            $results[] = array_merge($source->metadata($this->clock->now()), [
                'retrieval_score' => $match['score'],
                'snippet' => $this->snippet($source->content, $query, 600),
                'citations' => $source->citations,
            ]);
        }
        return [
            'ok' => true,
            'available' => true,
            'query' => $query,
            'count' => count($results),
            'total_matching' => $totalMatching,
            'results' => $results,
            'selection_required' => $totalMatching > 1,
            'selection_performed' => false,
            'truncated' => count($results) < $totalMatching,
            'complete' => count($results) === $totalMatching,
            'retrieval' => 'bounded_published_index',
            'publication' => $index->publicationMetadata(),
        ];
    }

    /** @return array<string, mixed> */
    public function readSource(
        string $sourceId,
        string $actorType,
        string $locale,
        ?string $market,
        ?string $branch,
        int $offset,
        int $maximumCharacters
    ): array {
        $index = $this->repository->published();
        if (!$index instanceof PublishedKnowledgeIndex) {
            return $this->unavailable();
        }
        $source = $index->source($sourceId);
        if (!$source instanceof KnowledgeSource
            || !$this->eligible($source, $actorType, $locale, $market, $branch, true)) {
            return ['ok' => false, 'code' => 'knowledge_source_unavailable'];
        }
        $offset = max(0, min(120000, $offset));
        $maximumCharacters = max(200, min(6000, $maximumCharacters));
        $contentLength = $this->length($source->content);
        if ($offset >= $contentLength) {
            return ['ok' => false, 'code' => 'knowledge_chunk_offset_invalid'];
        }
        $chunk = $this->slice($source->content, $offset, $maximumCharacters);
        return [
            'ok' => true,
            'available' => true,
            'source' => array_merge($source->metadata($this->clock->now()), [
                'content' => $chunk,
                'content_offset' => $offset,
                'content_length' => $this->length($chunk),
                'source_content_length' => $contentLength,
                'truncated' => $offset + $this->length($chunk) < $contentLength,
                'citations' => $source->citations,
            ]),
            'publication' => $index->publicationMetadata(),
        ];
    }

    /** @return array<string, mixed> */
    public function policy(
        string $type,
        ?string $policyKey,
        ?int $productId,
        string $actorType,
        string $locale,
        ?string $market,
        ?string $branch
    ): array {
        $index = $this->repository->published();
        if (!$index instanceof PublishedKnowledgeIndex) {
            return $this->unavailable();
        }
        $matches = [];
        foreach ($index->sources() as $source) {
            if ($source->type !== $type
                || !$this->eligible($source, $actorType, $locale, $market, $branch, true)) {
                continue;
            }
            if ($policyKey !== null && $source->policyKey !== $policyKey) {
                continue;
            }
            if ($productId !== null && !in_array($productId, $source->productIds, true)) {
                continue;
            }
            $matches[] = $source;
        }
        usort($matches, static function (KnowledgeSource $left, KnowledgeSource $right): int {
            $authority = $right->authorityRank() <=> $left->authorityRank();
            return $authority !== 0 ? $authority : strcmp($left->id, $right->id);
        });
        if ($matches === []) {
            return ['ok' => false, 'code' => 'knowledge_source_unavailable'];
        }
        $totalMatching = count($matches);
        $sources = [];
        foreach (array_slice($matches, 0, 5) as $source) {
            $sources[] = array_merge($source->metadata($this->clock->now()), [
                'content' => $this->slice($source->content, 0, 5000),
                'truncated' => $this->length($source->content) > 5000,
                'citations' => $source->citations,
            ]);
        }
        return [
            'ok' => true,
            'available' => true,
            'sources' => $sources,
            'count' => count($sources),
            'total_matching' => $totalMatching,
            'selection_required' => $totalMatching !== 1,
            'selection_performed' => false,
            'conflict_check_required' => $totalMatching > 1,
            'truncated' => count($sources) < $totalMatching,
            'complete' => count($sources) === $totalMatching,
            'publication' => $index->publicationMetadata(),
        ];
    }

    /** @param array<int, string> $sourceIds @return array<string, mixed> */
    public function freshness(
        array $sourceIds,
        string $actorType,
        string $locale,
        ?string $market,
        ?string $branch
    ): array {
        $index = $this->repository->published();
        if (!$index instanceof PublishedKnowledgeIndex) {
            return $this->unavailable();
        }
        $ids = $this->sourceIds($sourceIds, 20);
        if ($ids === null) {
            return ['ok' => false, 'code' => 'knowledge_source_ids_invalid'];
        }
        $results = [];
        foreach ($ids as $id) {
            $source = $index->source($id);
            if (!$source instanceof KnowledgeSource
                || !$source->accessibleTo($actorType)
                || !$source->matchesContext($locale, $market, $branch)) {
                $results[] = ['source_id' => $id, 'available' => false, 'fresh' => false, 'reason' => 'source_unavailable'];
                continue;
            }
            $results[] = array_merge($source->metadata($this->clock->now()), ['available' => true]);
        }
        return [
            'ok' => true,
            'available' => true,
            'results' => $results,
            'all_fresh' => !in_array(false, array_map(static fn (array $item): bool => ($item['fresh'] ?? false) === true, $results), true),
            'publication' => $index->publicationMetadata(),
        ];
    }

    /** @param array<int, string> $sourceIds @return array<string, mixed> */
    public function resolveConflict(
        array $sourceIds,
        string $actorType,
        string $locale,
        ?string $market,
        ?string $branch
    ): array {
        $index = $this->repository->published();
        if (!$index instanceof PublishedKnowledgeIndex) {
            return $this->unavailable();
        }
        $ids = $this->sourceIds($sourceIds, 10);
        if ($ids === null || count($ids) < 2) {
            return ['ok' => false, 'code' => 'knowledge_conflict_scope_invalid'];
        }
        $sources = [];
        $missing = [];
        foreach ($ids as $id) {
            $source = $index->source($id);
            if (!$source instanceof KnowledgeSource
                || !$source->accessibleTo($actorType)
                || !$source->matchesContext($locale, $market, $branch)) {
                $missing[] = $id;
                continue;
            }
            $sources[] = $source;
        }
        if ($missing !== []) {
            return ['ok' => false, 'code' => 'knowledge_conflict_source_unavailable', 'missing_source_ids' => $missing];
        }
        usort($sources, function (KnowledgeSource $left, KnowledgeSource $right): int {
            $fresh = ((int) $right->isFresh($this->clock->now())) <=> ((int) $left->isFresh($this->clock->now()));
            if ($fresh !== 0) {
                return $fresh;
            }
            $authority = $right->authorityRank() <=> $left->authorityRank();
            return $authority !== 0 ? $authority : strcmp($left->id, $right->id);
        });
        $fresh = array_values(array_filter($sources, fn (KnowledgeSource $source): bool => $source->isFresh($this->clock->now())));
        $selected = null;
        $basis = 'merchant_reconciliation_required';
        if (count($fresh) === 1) {
            $selected = $fresh[0]->id;
            $basis = 'only_fresh_source';
        } elseif (count($fresh) > 1 && $fresh[0]->authorityRank() > $fresh[1]->authorityRank()) {
            $selected = $fresh[0]->id;
            $basis = 'unique_higher_published_authority';
        }
        return [
            'ok' => true,
            'available' => true,
            'resolution_status' => $selected === null ? 'unresolved' : 'resolved_by_published_precedence',
            'selected_source_id' => $selected,
            'basis' => $basis,
            'precedence' => array_map(
                fn (KnowledgeSource $source): array => $source->metadata($this->clock->now()),
                $sources
            ),
            'content_interpretation_performed' => false,
            'publication' => $index->publicationMetadata(),
        ];
    }

    /** @param array<int, string> $sourceIds @return array<string, mixed> */
    public function citations(
        array $sourceIds,
        string $actorType,
        string $locale,
        ?string $market,
        ?string $branch
    ): array {
        $index = $this->repository->published();
        if (!$index instanceof PublishedKnowledgeIndex) {
            return $this->unavailable();
        }
        $ids = $this->sourceIds($sourceIds, 20);
        if ($ids === null) {
            return ['ok' => false, 'code' => 'knowledge_source_ids_invalid'];
        }
        $items = [];
        $missing = [];
        foreach ($ids as $id) {
            $source = $index->source($id);
            if (!$source instanceof KnowledgeSource
                || !$source->accessibleTo($actorType)
                || !$source->matchesContext($locale, $market, $branch)) {
                $missing[] = $id;
                continue;
            }
            $items[] = [
                'source_id' => $source->id,
                'source_version' => $source->version,
                'fresh' => $source->isFresh($this->clock->now()),
                'freshness_reason' => $source->freshnessReason($this->clock->now()),
                'citations' => $source->citations,
            ];
        }
        return [
            'ok' => true,
            'available' => true,
            'sources' => $items,
            'missing_source_ids' => $missing,
            'complete' => $missing === [],
            'publication' => $index->publicationMetadata(),
        ];
    }

    private function eligible(
        KnowledgeSource $source,
        string $actorType,
        string $locale,
        ?string $market,
        ?string $branch,
        bool $freshOnly
    ): bool {
        return $source->accessibleTo($actorType)
            && $source->matchesContext($locale, $market, $branch)
            && (!$freshOnly || $source->isFresh($this->clock->now()));
    }

    /** @param array<int, string> $terms */
    private function score(KnowledgeSource $source, string $query, array $terms): int
    {
        $query = $this->lower($query);
        $title = $this->lower($source->title);
        $content = $this->lower($source->content);
        $keywords = array_map(fn (string $keyword): string => $this->lower($keyword), $source->keywords);
        $score = 0;
        if (str_contains($title, $query)) {
            $score += 30;
        }
        if (str_contains($content, $query)) {
            $score += 10;
        }
        foreach ($terms as $term) {
            if (str_contains($title, $term)) {
                $score += 8;
            }
            if (in_array($term, $keywords, true)) {
                $score += 6;
            }
            if (str_contains($content, $term)) {
                $score += 2;
            }
        }
        return $score;
    }

    /** @return array<int, string> */
    private function terms(string $query): array
    {
        $normalized = $this->lower($query);
        $normalized = str_replace([
            '.', ',', ';', ':', '!', '?', '/', '\\', '(', ')', '[', ']',
            '{', '}', '"', "'", '،', '؛', '؟', "\n", "\r", "\t",
        ], ' ', $normalized);
        $items = [];
        foreach (explode(' ', $normalized) as $term) {
            $term = trim($term);
            if ($this->length($term) >= 2) {
                $items[] = $term;
            }
        }
        return array_slice(array_values(array_unique($items)), 0, 24);
    }

    private function snippet(string $content, string $query, int $maximum): string
    {
        $position = $this->position($this->lower($content), $this->lower($query));
        $offset = $position === false ? 0 : max(0, $position - intdiv($maximum, 3));
        $chunk = $this->slice($content, $offset, $maximum);
        return ($offset > 0 ? '…' : '') . $chunk . ($offset + $this->length($chunk) < $this->length($content) ? '…' : '');
    }

    /** @param array<int, mixed> $sourceIds @return array<int, string>|null */
    private function sourceIds(array $sourceIds, int $maximum): ?array
    {
        if ($sourceIds === [] || count($sourceIds) > $maximum) {
            return null;
        }
        $result = [];
        foreach ($sourceIds as $id) {
            if (!is_string($id) || $id === '' || strlen($id) > 128) {
                return null;
            }
            $result[] = $id;
        }
        return count(array_unique($result)) === count($result) ? $result : null;
    }

    /** @return array<string, mixed> */
    private function unavailable(): array
    {
        return [
            'ok' => false,
            'code' => 'knowledge_index_unavailable',
            'available' => false,
            'reason' => 'no_valid_published_governed_index',
        ];
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function slice(string $value, int $offset, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, $offset, $length, 'UTF-8')
            : substr($value, $offset, $length);
    }

    private function position(string $haystack, string $needle): int|false
    {
        return function_exists('mb_strpos')
            ? mb_strpos($haystack, $needle, 0, 'UTF-8')
            : strpos($haystack, $needle);
    }
}
