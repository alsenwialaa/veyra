<?php
declare(strict_types=1);

namespace Veyra\Tests\Unit\Recommendation;

use PHPUnit\Framework\TestCase;
use Veyra\Recommendation\Application\RecommendationService;
use Veyra\Recommendation\Contract\ProductCandidateRepository;
use Veyra\Recommendation\Contract\PublishedRecommendationPolicyRepository;
use Veyra\Recommendation\Domain\ProductCandidate;
use Veyra\Recommendation\Domain\ProductCandidateSet;
use Veyra\Recommendation\Domain\RecommendationPolicy;
use Veyra\Requirements\Domain\RequirementCriterion;

final class RecommendationServiceTest extends TestCase
{
    public function testCompatibilityHardRequirementFailsClosedWithoutEvidence(): void
    {
        $candidate = $this->candidate(10, ['laptops']);
        $service = new RecommendationService($this->products([$candidate]), $this->policy());
        $criterion = $this->criterion('compatibility', 'requires', ['key' => 'model-x'], 'hard');

        $result = $service->hardFilters([10], [$criterion->toArray()]);

        self::assertTrue($result['ok']);
        self::assertSame([], $result['eligible_candidates']);
        self::assertSame('compatibility_evidence_required', $result['unresolved_hard_requirements'][0]['code']);
        self::assertFalse($result['compatibility_assumed']);
    }

    public function testRankRequiresExactIdsAndStructuredEvidence(): void
    {
        $service = new RecommendationService(
            $this->products([$this->candidate(10, ['laptops']), $this->candidate(20, ['tablets'])]),
            $this->policy()
        );
        $criterion = $this->criterion('category', 'in', ['laptops'], 'soft');

        $result = $service->rank([10, 20], [$criterion->toArray()]);

        self::assertTrue($result['ok']);
        self::assertSame(10, $result['ranked_candidates'][0]['product_id']);
        self::assertFalse($result['semantic_free_text_scoring_used']);
    }

    public function testServerRankedDiversificationPreservesHardExclusions(): void
    {
        $service = new RecommendationService(
            $this->products([
                $this->candidate(10, ['laptops']),
                $this->candidate(20, ['tablets']),
                $this->candidate(30, ['accessories']),
            ]),
            $this->policy()
        );
        $exclusion = $this->criterion(
            'exclusion',
            'excludes',
            ['product_ids' => [10], 'categories' => []],
            'hard'
        );

        $result = $service->rankAndDiversify([10, 20, 30], [$exclusion->toArray()], 2);

        self::assertTrue($result['ok']);
        self::assertFalse($result['scores_supplied_by_caller']);
        self::assertNotContains(
            10,
            array_column($result['diversified_candidates'], 'product_id'),
            'A server-rejected hard exclusion must not re-enter during diversification.'
        );
        self::assertContains(10, array_column($result['rejected_candidates'], 'product_id'));
    }

    private function candidate(int $id, array $categories): ProductCandidate
    {
        return new ProductCandidate(
            $id, 0, 'Product ' . $id, 'SKU-' . $id, 'simple', true, true, true,
            false, 25.0, 'USD', 'instock', $categories, [], 'https://store.test/p/' . $id,
            0, '2026-08-24T10:00:00Z'
        );
    }

    /** @param array<int, ProductCandidate> $candidates */
    private function products(array $candidates): ProductCandidateRepository
    {
        return new class($candidates) implements ProductCandidateRepository {
            public function __construct(private readonly array $candidates) {}
            public function retrieve(array $productIds): ProductCandidateSet
            {
                $found = array_values(array_filter(
                    $this->candidates,
                    static fn (ProductCandidate $candidate): bool => in_array($candidate->productId, $productIds, true)
                ));
                $foundIds = array_map(static fn (ProductCandidate $candidate): int => $candidate->productId, $found);
                return new ProductCandidateSet(true, $found, array_values(array_diff($productIds, $foundIds)));
            }
        };
    }

    private function policy(): PublishedRecommendationPolicyRepository
    {
        $policy = RecommendationPolicy::fromPublishedPayload([
            'schema_version' => '1.0.0',
            'status' => 'published',
            'publication_id' => 'ranking-1',
            'version' => '1',
            'store_id' => 1,
            'published_at' => '2026-08-20T00:00:00Z',
            'weights' => ['availability' => 1.0, 'category' => 5.0, 'compatibility' => 5.0],
        ], 1);
        return new class($policy) implements PublishedRecommendationPolicyRepository {
            public function __construct(private readonly RecommendationPolicy $policy) {}
            public function published(): ?RecommendationPolicy { return $this->policy; }
        };
    }

    private function criterion(string $field, string $operator, mixed $value, string $strength): RequirementCriterion
    {
        return RequirementCriterion::proposed(
            $field,
            $operator,
            $value,
            $strength,
            'active',
            [
                'message_id' => 'msg-1',
                'excerpt_sha256' => hash('sha256', 'shopper text'),
                'excerpt_offset_bytes' => 0,
                'excerpt_length_bytes' => 12,
                'source_kind' => 'customer_visible_message',
            ],
            [],
            '2026-08-24T10:00:00Z'
        );
    }
}
