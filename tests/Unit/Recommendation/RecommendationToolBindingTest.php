<?php
declare(strict_types=1);

namespace Veyra\Tests\Unit\Recommendation;

use PHPUnit\Framework\TestCase;
use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Tool\ToolContext;
use Veyra\Conversation\Application\ConversationStore;
use Veyra\Conversation\Domain\ConversationFocus;
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

final class RecommendationToolBindingTest extends TestCase
{
    public function testStaleReferenceFailsBeforeCommerceComputation(): void
    {
        [$handler, $states, $products, $store] = $this->fixture([$this->criterion('category', 'in', ['laptops'], 'soft')]);
        $state = $states->get(self::CONVERSATION_ID, 'guest', self::ACTOR_ID);

        $result = $handler->execute(new ToolCall(
            'call-stale-before',
            'recommendation.rank',
            '1.0.0',
            [
                'product_ids' => [10, 20],
                'expected_requirements_resource_version' => $state['resource_version'] + 1,
                'expected_requirements_state_hash' => $state['state_hash'],
            ]
        ), $this->context());

        self::assertSame('stale', $result->status);
        self::assertSame('requirements_state_stale', $result->code);
        self::assertTrue($result->retrySafe);
        self::assertFalse($result->authoritative);
        self::assertSame(0, $products->retrieveCalls);

        $wrongHash = ($state['state_hash'][0] === 'a' ? 'b' : 'a') . substr($state['state_hash'], 1);
        $hashResult = $handler->execute(new ToolCall(
            'call-stale-before-hash',
            'recommendation.rank',
            '1.0.0',
            [
                'product_ids' => [10, 20],
                'expected_requirements_resource_version' => $state['resource_version'],
                'expected_requirements_state_hash' => $wrongHash,
            ]
        ), $this->context());
        self::assertSame('stale', $hashResult->status);
        self::assertTrue($hashResult->retrySafe);
        self::assertSame(0, $products->retrieveCalls);
        $this->assertActorContext($store);
    }

    public function testStateChangeDuringComputationDiscardsTheResultAsStale(): void
    {
        $initial = $this->criterion('category', 'in', ['laptops'], 'soft');
        $replacement = $this->criterion('preference', 'equals', ['quiet'], 'soft');
        $store = new RecommendationBindingConversationStore(
            self::CONVERSATION_ID,
            'guest',
            self::ACTOR_ID,
            [$initial->toArray()]
        );
        $stateRepository = new InMemoryRequirementStateRepository();
        $states = $this->states($store, $stateRepository, [$initial]);
        $state = $states->get(self::CONVERSATION_ID, 'guest', self::ACTOR_ID);
        $head = $stateRepository->loadOwned(self::CONVERSATION_ID, 'guest', self::ACTOR_ID);
        self::assertNotNull($head);
        $products = new RecommendationBindingProductRepository(
            [$this->candidate(10, ['laptops']), $this->candidate(20, ['tablets'])],
            static function () use ($stateRepository, $head, $replacement): void {
                $stateRepository->seed($head->next(
                    [$head->criteria[0], $replacement],
                    'msg_' . str_repeat('e', 32),
                    '2026-08-24T10:00:02Z'
                ));
            }
        );
        $handler = new RecommendationToolHandler(
            new RecommendationService($products, $this->policy()),
            $states
        );

        $result = $handler->execute(new ToolCall(
            'call-stale-after',
            'recommendation.rank',
            '1.0.0',
            $this->bound([10, 20], $state)
        ), $this->context());

        self::assertSame('stale', $result->status);
        self::assertTrue($result->retrySafe);
        self::assertFalse($result->authoritative);
        self::assertGreaterThan(0, $products->retrieveCalls);
        self::assertNotSame(
            $state['state_hash'],
            $result->data['current_requirements_state_hash'] ?? null,
            'A retry must be bound to the replacement state, not the discarded computation.'
        );
        $this->assertActorContext($store);
    }

    public function testSuccessIsBoundToActorOwnedActiveRequirementState(): void
    {
        $source = 'msg_' . str_repeat('d', 32);
        $superseded = $this->criterion('exclusion', 'excludes', ['product_ids' => [10]], 'hard')
            ->withStatus('superseded', 'removed', $source, '2026-08-24T10:00:01Z');
        $active = $this->criterion('category', 'in', ['laptops'], 'soft');
        [$handler, $states, , $store] = $this->fixture([$superseded, $active]);
        $state = $states->get(self::CONVERSATION_ID, 'guest', self::ACTOR_ID);

        $result = $handler->execute(new ToolCall(
            'call-bound-success',
            'recommendation.rank',
            '1.0.0',
            $this->bound([10, 20], $state)
        ), $this->context());

        self::assertSame('succeeded', $result->status);
        self::assertFalse($result->authoritative);
        self::assertSame($state['resource_version'], $result->data['bound_requirements_state']['resource_version']);
        self::assertSame($state['state_hash'], $result->data['bound_requirements_state']['state_hash']);
        self::assertSame(1, $result->data['bound_requirements_state']['active_requirement_count']);
        self::assertSame(10, $result->data['ranked_candidates'][0]['product_id']);
        $this->assertActorContext($store);
    }

    private const CONVERSATION_ID = 'conversation-recommendation-binding';
    private const ACTOR_ID = 'guest-recommendation-binding';

    /**
     * @param array<int, RequirementCriterion> $requirements
     * @return array{RecommendationToolHandler,RequirementStateService,RecommendationBindingProductRepository,RecommendationBindingConversationStore}
     */
    private function fixture(array $requirements): array
    {
        $store = new RecommendationBindingConversationStore(
            self::CONVERSATION_ID,
            'guest',
            self::ACTOR_ID,
            array_map(static fn (RequirementCriterion $criterion): array => $criterion->toArray(), $requirements)
        );
        $states = $this->states($store, null, $requirements);
        $products = new RecommendationBindingProductRepository([
            $this->candidate(10, ['laptops']),
            $this->candidate(20, ['tablets']),
        ]);
        $handler = new RecommendationToolHandler(
            new RecommendationService($products, $this->policy()),
            $states
        );

        return [$handler, $states, $products, $store];
    }

    private function states(
        RecommendationBindingConversationStore $store,
        ?InMemoryRequirementStateRepository $repository = null,
        array $requirements = []
    ): RequirementStateService
    {
        $repository ??= new InMemoryRequirementStateRepository();
        if ($requirements !== []) {
            $sourceMessageId = $requirements[array_key_last($requirements)]->source['message_id'];
            $repository->seed(RequirementState::empty(
                self::CONVERSATION_ID,
                'guest',
                self::ACTOR_ID,
                '2026-08-24T10:00:00Z'
            )->next($requirements, $sourceMessageId, '2026-08-24T10:00:00Z'));
        }
        return new RequirementStateService(
            $store,
            $repository,
            new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'))
        );
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function bound(array $productIds, array $state): array
    {
        return [
            'product_ids' => $productIds,
            'expected_requirements_resource_version' => $state['resource_version'],
            'expected_requirements_state_hash' => $state['state_hash'],
        ];
    }

    private function context(): ToolContext
    {
        return new ToolContext(
            'guest',
            self::ACTOR_ID,
            null,
            self::ACTOR_ID,
            self::CONVERSATION_ID,
            [],
            ['commerce_product_assistance' => 'On'],
            'en_US',
            'correlation-recommendation-binding'
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

    private function policy(): PublishedRecommendationPolicyRepository
    {
        $policy = RecommendationPolicy::fromPublishedPayload([
            'schema_version' => '1.0.0',
            'status' => 'published',
            'publication_id' => 'ranking-binding-test',
            'version' => '1',
            'store_id' => 1,
            'published_at' => '2026-08-24T00:00:00Z',
            'weights' => ['availability' => 1.0, 'category' => 5.0],
        ], 1);

        return new class($policy) implements PublishedRecommendationPolicyRepository {
            public function __construct(private readonly RecommendationPolicy $policy)
            {
            }

            public function published(): ?RecommendationPolicy
            {
                return $this->policy;
            }
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
                'message_id' => 'msg_' . str_repeat('c', 32),
                'excerpt_sha256' => hash('sha256', 'shopper requirement'),
                'excerpt_offset_bytes' => 0,
                'excerpt_length_bytes' => 19,
                'source_kind' => 'customer_visible_message',
            ],
            [],
            '2026-08-24T10:00:00Z'
        );
    }

    private function assertActorContext(RecommendationBindingConversationStore $store): void
    {
        self::assertNotSame([], $store->observedContexts);
        foreach ($store->observedContexts as $observed) {
            self::assertSame(
                [self::CONVERSATION_ID, 'guest', self::ACTOR_ID],
                [$observed['conversation_id'], $observed['actor_type'], $observed['actor_id']],
                $observed['method'] . ' must use the current server-owned actor context.'
            );
        }
    }
}

final class RecommendationBindingProductRepository implements ProductCandidateRepository
{
    public int $retrieveCalls = 0;

    /** @param array<int, ProductCandidate> $candidates */
    public function __construct(
        private readonly array $candidates,
        private ?\Closure $afterFirstRetrieve = null
    ) {
    }

    public function retrieve(array $productIds): ProductCandidateSet
    {
        ++$this->retrieveCalls;
        $found = array_values(array_filter(
            $this->candidates,
            static fn (ProductCandidate $candidate): bool => in_array($candidate->productId, $productIds, true)
        ));
        $foundIds = array_map(static fn (ProductCandidate $candidate): int => $candidate->productId, $found);
        $callback = $this->afterFirstRetrieve;
        $this->afterFirstRetrieve = null;
        if ($callback instanceof \Closure) {
            $callback();
        }

        return new ProductCandidateSet(true, $found, array_values(array_diff($productIds, $foundIds)));
    }
}

final class RecommendationBindingConversationStore implements ConversationStore
{
    /** @var array<string, mixed> */
    private array $memory;
    /** @var array<int, array{method:string,conversation_id:string,actor_type:string,actor_id:string}> */
    public array $observedContexts = [];

    /** @param array<int, array<string, mixed>> $requirements */
    public function __construct(
        private readonly string $conversationId,
        private readonly string $actorType,
        private readonly string $actorId,
        array $requirements
    ) {
        $this->memory = ['requirements' => $requirements];
    }

    /** @param array<int, array<string, mixed>> $requirements */
    public function replaceRequirements(array $requirements): void
    {
        $this->memory = ['requirements' => $requirements];
    }

    public function createConversation(string $actorType, string $actorId, ?int $userId, ?string $guestSessionId): string
    {
        throw new \LogicException('Not used.');
    }

    public function currentOwnedConversation(string $actorType, string $actorId): ?array
    {
        return null;
    }

    public function getOwnedConversation(string $conversationId, string $actorType, string $actorId): ?array
    {
        $this->observe('getOwnedConversation', $conversationId, $actorType, $actorId);
        return $this->owned($conversationId, $actorType, $actorId)
            ? ['conversation_id' => $conversationId]
            : null;
    }

    public function recentVisibleMessages(string $conversationId, string $actorType, string $actorId, int $limit): array
    {
        return [];
    }

    public function visibleMessage(string $conversationId, string $actorType, string $actorId, string $messageId): ?array
    {
        return null;
    }

    public function journeys(string $conversationId, string $actorType, string $actorId): array
    {
        return [];
    }

    public function focus(string $conversationId, string $actorType, string $actorId): ?ConversationFocus
    {
        return null;
    }

    public function memory(string $conversationId, string $actorType, string $actorId): array
    {
        $this->observe('memory', $conversationId, $actorType, $actorId);
        return $this->owned($conversationId, $actorType, $actorId) ? $this->memory : [];
    }

    public function summary(string $conversationId, string $actorType, string $actorId): array
    {
        return [];
    }

    public function appendVisibleMessage(string $conversationId, string $actorType, string $actorId, string $senderType, string $text, array $renderPayload, array $evidence, string $correlationId): string
    {
        throw new \LogicException('Not used.');
    }

    public function saveFocus(string $conversationId, string $actorType, string $actorId, ConversationFocus $focus, string $expectedVersion): bool
    {
        return false;
    }

    public function consumePendingQuestion(string $conversationId, string $actorType, string $actorId, string $questionId, string $expectedFocusVersion, int $expectedQuestionVersion, string $customerMessageId, array $validatedBinding): array
    {
        return ['consumed' => false, 'code' => 'pending_question_unavailable', 'binding_id' => null];
    }

    public function saveMemory(string $conversationId, string $actorType, string $actorId, array $memory, string $sourceMessageId): bool
    {
        return false;
    }

    private function owned(string $conversationId, string $actorType, string $actorId): bool
    {
        return hash_equals($this->conversationId, $conversationId)
            && hash_equals($this->actorType, $actorType)
            && hash_equals($this->actorId, $actorId);
    }

    private function observe(string $method, string $conversationId, string $actorType, string $actorId): void
    {
        $this->observedContexts[] = [
            'method' => $method,
            'conversation_id' => $conversationId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ];
    }
}
