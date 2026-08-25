<?php
declare(strict_types=1);

namespace Veyra\Tests\Unit\AI;

use PHPUnit\Framework\TestCase;
use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Orchestration\ResponseVerifier;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\Recommendation\Application\RecommendationService;
use Veyra\Recommendation\Contract\ProductCandidateRepository;
use Veyra\Recommendation\Contract\PublishedRecommendationPolicyRepository;
use Veyra\Recommendation\Domain\ProductCandidate;
use Veyra\Recommendation\Domain\ProductCandidateSet;
use Veyra\Recommendation\Domain\RecommendationPolicy;
use Veyra\Recommendation\Tool\RecommendationToolHandler;
use Veyra\Requirements\Application\RequirementStateService;
use Veyra\Requirements\Domain\RequirementCriterion;
use Veyra\Requirements\Domain\RequirementState;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Support\FrozenClock;
use Veyra\Tests\Support\InMemoryRequirementStateRepository;
use Veyra\Tests\Support\MemoryConversationStore;

final class AdvisoryRecommendationEvidenceTest extends TestCase
{
    /** @var array{expected_requirements_resource_version:int,expected_requirements_state_hash:string} */
    private array $binding = [];

    public function testEveryAdvisoryRecommendationOperationIsNonAuthoritative(): void
    {
        $handler = $this->handler();
        $context = $this->context();
        $calls = [
            'recommendation.apply_hard_filters' => $this->bound(['product_ids' => [10, 20]]),
            'recommendation.rank' => $this->bound(['product_ids' => [10, 20]]),
            'recommendation.diversify' => $this->bound(['product_ids' => [10, 20], 'limit' => 2]),
            'recommendation.explain' => $this->bound(['product_ids' => [10, 20]]),
        ];

        foreach ($calls as $name => $arguments) {
            $result = $handler->execute(new ToolCall('call-' . str_replace('.', '-', $name), $name, '1.0.0', $arguments), $context);

            self::assertSame('succeeded', $result->status, $name);
            self::assertFalse($result->authoritative, $name);
            self::assertSame([], $result->changedResources, $name);
        }

        $retrieval = $handler->execute(new ToolCall(
            'call-retrieve',
            'recommendation.retrieve_candidates',
            '1.0.0',
            ['product_ids' => [10, 20]]
        ), $context);
        self::assertFalse($retrieval->authoritative, 'The canonical catalog classifies candidate retrieval as advisory.');
    }

    public function testAdvisoryRecommendationCannotBackVerifiedEvidence(): void
    {
        $handler = $this->handler();
        $result = $handler->execute(new ToolCall(
            'call-diversify',
            'recommendation.diversify',
            '1.0.0',
            $this->bound(['product_ids' => [10, 20], 'limit' => 2])
        ), $this->context());
        $payload = [
            'reply' => ['text' => 'Product 10 is the verified best choice.', 'components' => []],
            'claims' => [[
                'claim_id' => 'claim-advisory-rank',
                'type' => 'product',
                'status' => 'verified',
                'source_call_id' => $result->callId,
                'source_path' => '/data/diversified_candidates/0/product_id',
                'asserted_value' => [
                    'kind' => 'integer',
                    'string_value' => null,
                    'integer_value' => 10,
                    'number_value' => null,
                    'boolean_value' => null,
                    'currency' => null,
                    'currency_source_path' => null,
                ],
            ]],
        ];

        $verified = (new ResponseVerifier())->verify($payload, [$result]);

        self::assertFalse($verified['valid']);
        self::assertContains('claim_without_authoritative_source:claim-advisory-rank', $verified['errors']);
        self::assertSame([], $verified['evidence']);
    }

    public function testToolSchemasRejectCallerRequirementsAndScores(): void
    {
        $handler = $this->handler();
        $definitions = [];
        foreach ($handler->definitions() as $definition) {
            $definitions[$definition->name] = $definition;
        }
        $validator = new ToolInputValidator();

        self::assertFalse($validator->validate(
            $this->bound(['product_ids' => [10, 20], 'requirements' => [$this->requirement()->toArray()]]),
            $definitions['recommendation.rank']->inputSchema
        ));
        self::assertFalse($validator->validate(
            $this->bound([
                'product_ids' => [10, 20],
                'ranked_candidates' => [
                    ['product_id' => 20, 'score' => 100.0],
                    ['product_id' => 10, 'score' => 0.0],
                ],
                'limit' => 2,
            ]),
            $definitions['recommendation.diversify']->inputSchema
        ));
        self::assertArrayNotHasKey('requirements', $definitions['recommendation.rank']->inputSchema['properties']);
        self::assertArrayNotHasKey('ranked_candidates', $definitions['recommendation.diversify']->inputSchema['properties']);

        $registry = new ToolRegistry($validator);
        $registry->register($handler);
        $forgedRequirements = $registry->execute(new ToolCall(
            'call-forged-requirements',
            'recommendation.rank',
            '1.0.0',
            $this->bound(['product_ids' => [10, 20], 'requirements' => [$this->requirement()->toArray()]])
        ), $this->context());
        $forgedScores = $registry->execute(new ToolCall(
            'call-forged-scores',
            'recommendation.diversify',
            '1.0.0',
            $this->bound([
                'product_ids' => [10, 20],
                'ranked_candidates' => [
                    ['product_id' => 20, 'score' => 100.0],
                    ['product_id' => 10, 'score' => 0.0],
                ],
                'limit' => 2,
            ])
        ), $this->context());
        self::assertSame('blocked', $forgedRequirements->status);
        self::assertSame('tool_input_invalid', $forgedRequirements->code);
        self::assertSame('blocked', $forgedScores->status);
        self::assertSame('tool_input_invalid', $forgedScores->code);
    }

    private function handler(): RecommendationToolHandler
    {
        $candidates = [
            $this->candidate(10, ['laptops']),
            $this->candidate(20, ['tablets']),
        ];
        $products = new class($candidates) implements ProductCandidateRepository {
            /** @param array<int, ProductCandidate> $candidates */
            public function __construct(private readonly array $candidates)
            {
            }

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
        $policy = RecommendationPolicy::fromPublishedPayload([
            'schema_version' => '1.0.0',
            'status' => 'published',
            'publication_id' => 'ranking-advisory-test',
            'version' => '1',
            'store_id' => 1,
            'published_at' => '2026-08-24T00:00:00Z',
            'weights' => ['availability' => 1.0, 'category' => 5.0],
        ], 1);
        $policies = new class($policy) implements PublishedRecommendationPolicyRepository {
            public function __construct(private readonly RecommendationPolicy $policy)
            {
            }

            public function published(): ?RecommendationPolicy
            {
                return $this->policy;
            }
        };

        $sourceMessageId = 'msg_' . str_repeat('a', 32);
        $store = new MemoryConversationStore($sourceMessageId, 'I prefer laptops');
        $repository = new InMemoryRequirementStateRepository();
        $criterion = $this->requirement($sourceMessageId);
        $repository->seed(RequirementState::empty(
            'conversation-advisory-test',
            'guest',
            'guest-session-advisory-test',
            '2026-08-24T10:00:00Z'
        )->next([$criterion], $sourceMessageId, '2026-08-24T10:00:00Z'));
        $states = new RequirementStateService(
            $store,
            $repository,
            new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'))
        );
        $state = $states->get('conversation-advisory-test', 'guest', 'guest-session-advisory-test');
        self::assertTrue($state['ok']);
        self::assertIsInt($state['resource_version']);
        self::assertIsString($state['state_hash']);
        self::assertCount(1, $state['active_requirements']);
        $this->binding = [
            'expected_requirements_resource_version' => $state['resource_version'],
            'expected_requirements_state_hash' => $state['state_hash'],
        ];

        return new RecommendationToolHandler(new RecommendationService($products, $policies), $states);
    }

    private function context(): ToolContext
    {
        return new ToolContext(
            'guest',
            'guest-session-advisory-test',
            null,
            'guest-session-advisory-test',
            'conversation-advisory-test',
            [],
            ['commerce_product_assistance' => 'On'],
            'en_US',
            'correlation-advisory-test'
        );
    }

    /** @param array<int, string> $categories */
    private function candidate(int $id, array $categories): ProductCandidate
    {
        return new ProductCandidate(
            $id,
            0,
            'Product ' . $id,
            'SKU-' . $id,
            'simple',
            true,
            true,
            true,
            false,
            25.0,
            'USD',
            'instock',
            $categories,
            [],
            'https://store.test/product/' . $id,
            0,
            '2026-08-24T10:00:00Z'
        );
    }

    private function requirement(?string $sourceMessageId = null): RequirementCriterion
    {
        $sourceMessageId ??= 'msg_' . str_repeat('a', 32);
        return RequirementCriterion::proposed(
            'category',
            'in',
            ['laptops'],
            'soft',
            'active',
            [
                'message_id' => $sourceMessageId,
                'excerpt_sha256' => hash('sha256', 'I prefer laptops'),
                'excerpt_offset_bytes' => 0,
                'excerpt_length_bytes' => 16,
                'source_kind' => 'customer_visible_message',
            ],
            [],
            '2026-08-24T10:00:00Z'
        );
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function bound(array $arguments): array
    {
        return array_merge($arguments, $this->binding);
    }
}
