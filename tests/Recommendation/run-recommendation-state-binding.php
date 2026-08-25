<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Provider\ProviderSafeToolResultProjector;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\AI\Tool\UniversalToolGovernance;
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

final class VeyraRecommendationRunnerProducts implements ProductCandidateRepository
{
    public int $retrieveCalls = 0;
    private ?Closure $afterNextRetrieve = null;

    /** @param array<int, ProductCandidate> $candidates */
    public function __construct(private readonly array $candidates)
    {
    }

    public function afterNextRetrieve(Closure $callback): void
    {
        $this->afterNextRetrieve = $callback;
    }

    public function retrieve(array $productIds): ProductCandidateSet
    {
        ++$this->retrieveCalls;
        $found = array_values(array_filter(
            $this->candidates,
            static fn (ProductCandidate $candidate): bool => in_array($candidate->productId, $productIds, true)
        ));
        $foundIds = array_map(static fn (ProductCandidate $candidate): int => $candidate->productId, $found);
        $callback = $this->afterNextRetrieve;
        $this->afterNextRetrieve = null;
        if ($callback instanceof Closure) {
            $callback();
        }

        return new ProductCandidateSet(true, $found, array_values(array_diff($productIds, $foundIds)));
    }
}

$passed = 0;
$failed = 0;
$scenario = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        ++$passed;
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$conversationId = '11111111-1111-4111-8111-111111111111';
$actorId = 'guest-recommendation-runner';
$sourceMessageId = 'msg_' . str_repeat('a', 32);
$context = new ToolContext(
    'guest',
    $actorId,
    null,
    $actorId,
    $conversationId,
    [],
    ['commerce_product_assistance' => 'On'],
    'en_US',
    '22222222-2222-4222-8222-222222222222'
);

$criterion = static function (string $field, string $operator, mixed $value, string $strength) use ($sourceMessageId): RequirementCriterion {
    return RequirementCriterion::proposed(
        $field,
        $operator,
        $value,
        $strength,
        'active',
        [
            'message_id' => $sourceMessageId,
            'excerpt_sha256' => hash('sha256', 'shopper requirement'),
            'excerpt_offset_bytes' => 0,
            'excerpt_length_bytes' => 19,
            'source_kind' => 'customer_visible_message',
        ],
        [],
        '2026-08-24T10:00:00Z'
    );
};

$candidate = static function (int $id, array $categories): ProductCandidate {
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
};

$policyRepository = static function (): PublishedRecommendationPolicyRepository {
    $policy = RecommendationPolicy::fromPublishedPayload([
        'schema_version' => '1.0.0',
        'status' => 'published',
        'publication_id' => 'recommendation-binding-runner',
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
};

/**
 * @param array<int, RequirementCriterion> $requirements
 * @return array<string, mixed>
 */
$fixture = static function (array $requirements) use (
    $assert,
    $conversationId,
    $actorId,
    $sourceMessageId,
    $candidate,
    $policyRepository
): array {
    $store = new MemoryConversationStore($sourceMessageId, 'shopper requirement');
    $written = $store->saveMemory(
        $conversationId,
        'guest',
        $actorId,
        ['requirements' => array_map(static fn (RequirementCriterion $item): array => $item->toArray(), $requirements)],
        $sourceMessageId
    );
    $assert($written, 'Requirement fixture could not be persisted.');
    $stateRepository = new InMemoryRequirementStateRepository();
    // Recommendation tests exercise a validated current head. Legacy memory
    // imports are intentionally quarantined as proposed until a deterministic
    // semantic promotion path exists, so do not use that compatibility path as
    // a shortcut for establishing active requirements here.
    $stateRepository->seed(RequirementState::empty(
        $conversationId,
        'guest',
        $actorId,
        '2026-08-24T10:00:00Z'
    )->next($requirements, $sourceMessageId, '2026-08-24T10:00:00Z'));
    $states = new RequirementStateService(
        $store,
        $stateRepository,
        new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'))
    );
    $state = $states->get($conversationId, 'guest', $actorId);
    $assert(
        ($state['ok'] ?? false) === true
            && is_int($state['resource_version'] ?? null)
            && is_string($state['state_hash'] ?? null),
        'Actor-owned requirement state could not be loaded.'
    );
    $products = new VeyraRecommendationRunnerProducts([
        $candidate(10, ['laptops']),
        $candidate(20, ['tablets']),
        $candidate(30, ['accessories']),
    ]);
    $handler = new RecommendationToolHandler(
        new RecommendationService($products, $policyRepository()),
        $states
    );

    return [
        'handler' => $handler,
        'states' => $states,
        'state_repository' => $stateRepository,
        'state' => $state,
        'products' => $products,
    ];
};

$bound = static function (array $arguments, array $state): array {
    return array_merge($arguments, [
        'expected_requirements_resource_version' => $state['resource_version'],
        'expected_requirements_state_hash' => $state['state_hash'],
    ]);
};

$scenario('caller-supplied requirements and scores are rejected at the tool boundary', static function () use (
    $assert,
    $fixture,
    $criterion,
    $bound,
    $context
): void {
    $active = $criterion('category', 'in', ['laptops'], 'soft');
    $fixtureData = $fixture([$active]);
    /** @var RecommendationToolHandler $handler */
    $handler = $fixtureData['handler'];
    $registry = new ToolRegistry(new ToolInputValidator());
    $registry->register($handler);

    $forgedRequirements = $registry->execute(new ToolCall(
        'call-forged-requirements',
        'recommendation.rank',
        '1.0.0',
        $bound([
            'product_ids' => [10, 20],
            'requirements' => [$criterion('exclusion', 'excludes', ['product_ids' => [20]], 'hard')->toArray()],
        ], $fixtureData['state'])
    ), $context);
    $forgedScores = $registry->execute(new ToolCall(
        'call-forged-scores',
        'recommendation.diversify',
        '1.0.0',
        $bound([
            'product_ids' => [10, 20],
            'ranked_candidates' => [
                ['product_id' => 20, 'score' => 100.0],
                ['product_id' => 10, 'score' => 0.0],
            ],
            'limit' => 2,
        ], $fixtureData['state'])
    ), $context);

    $assert($forgedRequirements->status === 'blocked' && $forgedRequirements->code === 'tool_input_invalid', 'Forged requirements reached recommendation execution.');
    $assert($forgedScores->status === 'blocked' && $forgedScores->code === 'tool_input_invalid', 'Forged ranking scores reached recommendation execution.');
});

$scenario('stale requirement references fail before commerce computation', static function () use (
    $assert,
    $fixture,
    $criterion,
    $context
): void {
    $fixtureData = $fixture([$criterion('category', 'in', ['laptops'], 'soft')]);
    /** @var RecommendationToolHandler $handler */
    $handler = $fixtureData['handler'];
    /** @var VeyraRecommendationRunnerProducts $products */
    $products = $fixtureData['products'];
    $state = $fixtureData['state'];
    $wrongHash = ($state['state_hash'][0] === 'a' ? 'b' : 'a') . substr($state['state_hash'], 1);

    $wrongVersion = $handler->execute(new ToolCall(
        'call-stale-version',
        'recommendation.rank',
        '1.0.0',
        [
            'product_ids' => [10, 20],
            'expected_requirements_resource_version' => $state['resource_version'] + 1,
            'expected_requirements_state_hash' => $state['state_hash'],
        ]
    ), $context);
    $wrongStateHash = $handler->execute(new ToolCall(
        'call-stale-hash',
        'recommendation.rank',
        '1.0.0',
        [
            'product_ids' => [10, 20],
            'expected_requirements_resource_version' => $state['resource_version'],
            'expected_requirements_state_hash' => $wrongHash,
        ]
    ), $context);

    $assert($wrongVersion->status === 'stale' && $wrongVersion->retrySafe && !$wrongVersion->authoritative, 'Version-stale work did not fail retry-safe.');
    $assert($wrongStateHash->status === 'stale' && $wrongStateHash->retrySafe, 'Hash-stale work was accepted.');
    $assert($products->retrieveCalls === 0, 'Commerce computation ran before exact state-reference validation.');
});

$scenario('all implemented recommendation results satisfy closed output contracts', static function () use (
    $assert,
    $fixture,
    $criterion,
    $bound,
    $context
): void {
    $fixtureData = $fixture([$criterion('category', 'in', ['laptops'], 'soft')]);
    /** @var RecommendationToolHandler $handler */
    $handler = $fixtureData['handler'];
    $validator = new ToolInputValidator();
    $registry = new ToolRegistry($validator);
    $registry->register($handler);
    $calls = [
        new ToolCall('schema-retrieve', 'recommendation.retrieve_candidates', '1.0.0', ['product_ids' => [10, 20]]),
        new ToolCall('schema-filter', 'recommendation.apply_hard_filters', '1.0.0', $bound(['product_ids' => [10, 20]], $fixtureData['state'])),
        new ToolCall('schema-rank', 'recommendation.rank', '1.0.0', $bound(['product_ids' => [10, 20]], $fixtureData['state'])),
        new ToolCall('schema-diversify', 'recommendation.diversify', '1.0.0', $bound(['product_ids' => [10, 20], 'limit' => 2], $fixtureData['state'])),
        new ToolCall('schema-explain', 'recommendation.explain', '1.0.0', $bound(['product_ids' => [10, 20]], $fixtureData['state'])),
    ];
    $results = [];
    $projector = new ProviderSafeToolResultProjector();
    foreach ($calls as $call) {
        $results[$call->name] = $registry->execute($call, $context);
        $assert($results[$call->name]->status === 'succeeded', $call->name . ' failed its closed successful-output contract.');
        $projected = $projector->project($results[$call->name], $registry);
        $assert(($projected['tool'] ?? null) === $call->name, $call->name . ' could not cross the provider-safe projection boundary.');
    }

    $definitions = [];
    foreach ($handler->definitions() as $definition) {
        $definitions[$definition->name] = $definition;
        if ($definition->name !== 'recommendation.tune') {
            $assert($definition->outputSchema !== [], $definition->name . ' has no successful-output contract.');
        }
    }
    $closedOutput = new ReflectionMethod(UniversalToolGovernance::class, 'closedOutputSchema');
    $closedOutput->setAccessible(true);
    $assert(
        $closedOutput->invoke(null, $definitions['recommendation.rank']->outputSchema) === true
            && $closedOutput->invoke(null, ['oneOf' => [[
                'type' => 'object',
                'additionalProperties' => true,
            ]]]) === false,
        'Catalog governance did not distinguish a closed typed union from an open output branch.'
    );
    $tampered = $results['recommendation.rank']->data;
    $tampered['caller_injected_claim'] = true;
    $assert(
        !$validator->validate($tampered, $definitions['recommendation.rank']->outputSchema),
        'Recommendation rank output contract accepted an undeclared top-level field.'
    );

    $stale = $registry->execute(new ToolCall(
        'schema-stale',
        'recommendation.rank',
        '1.0.0',
        [
            'product_ids' => [10, 20],
            'expected_requirements_resource_version' => $fixtureData['state']['resource_version'] + 1,
            'expected_requirements_state_hash' => $fixtureData['state']['state_hash'],
        ]
    ), $context);
    $assert($stale->status === 'stale' && $stale->code === 'requirements_state_stale', 'Closed rank output contract rejected its typed stale-refresh shape.');
});

$scenario('requirement changes during computation discard advisory output', static function () use (
    $assert,
    $fixture,
    $criterion,
    $bound,
    $context,
    $conversationId,
    $actorId,
    $sourceMessageId
): void {
    $initial = $criterion('category', 'in', ['laptops'], 'soft');
    $replacement = $criterion('preference', 'equals', ['quiet'], 'soft');
    $fixtureData = $fixture([$initial]);
    /** @var InMemoryRequirementStateRepository $stateRepository */
    $stateRepository = $fixtureData['state_repository'];
    $head = $stateRepository->loadOwned($conversationId, 'guest', $actorId);
    $assert($head !== null, 'Current requirement head was unavailable.');
    /** @var VeyraRecommendationRunnerProducts $products */
    $products = $fixtureData['products'];
    $products->afterNextRetrieve(static function () use ($stateRepository, $head, $replacement, $sourceMessageId): void {
        $stateRepository->seed($head->next([$head->criteria[0], $replacement], $sourceMessageId, '2026-08-24T10:00:02Z'));
    });
    /** @var RecommendationToolHandler $handler */
    $handler = $fixtureData['handler'];

    $result = $handler->execute(new ToolCall(
        'call-stale-after',
        'recommendation.rank',
        '1.0.0',
        $bound(['product_ids' => [10, 20]], $fixtureData['state'])
    ), $context);

    $assert($result->status === 'stale' && $result->retrySafe && !$result->authoritative, 'State drift did not discard computed output.');
    $assert(!isset($result->data['ranked_candidates']), 'Stale computed recommendation data escaped.');
    $assert(($result->data['current_requirements_state_hash'] ?? '') !== $fixtureData['state']['state_hash'], 'Retry metadata remained bound to the replaced state.');
});

$scenario('hard exclusions survive server ranking and diversification', static function () use (
    $assert,
    $fixture,
    $criterion,
    $bound,
    $context
): void {
    $exclusion = $criterion('exclusion', 'excludes', ['product_ids' => [10], 'categories' => []], 'hard');
    $fixtureData = $fixture([$exclusion]);
    /** @var RecommendationToolHandler $handler */
    $handler = $fixtureData['handler'];
    $filtered = $handler->execute(new ToolCall(
        'call-hard-filter',
        'recommendation.apply_hard_filters',
        '1.0.0',
        $bound(['product_ids' => [10, 20, 30]], $fixtureData['state'])
    ), $context);
    $diversified = $handler->execute(new ToolCall(
        'call-diversify',
        'recommendation.diversify',
        '1.0.0',
        $bound(['product_ids' => [10, 20, 30], 'limit' => 2], $fixtureData['state'])
    ), $context);

    $assert($filtered->status === 'succeeded' && !in_array(10, array_column($filtered->data['eligible_candidates'], 'product_id'), true), 'Hard-excluded candidate remained eligible.');
    $assert($diversified->status === 'succeeded' && !in_array(10, array_column($diversified->data['diversified_candidates'], 'product_id'), true), 'Hard-excluded candidate re-entered diversification.');
    $assert(in_array(10, array_column($diversified->data['rejected_candidates'], 'product_id'), true), 'Hard exclusion evidence was dropped.');
    $assert(($diversified->data['scores_supplied_by_caller'] ?? true) === false, 'Diversification did not attest server-owned scoring.');
    $assert($fixtureData['products']->retrieveCalls === 2, 'Diversification re-read WooCommerce instead of retaining its exact ranked candidate snapshot.');
});

$scenario('hard quantity requirements use current Woo limits and stock instead of shape-only approval', static function () use (
    $assert,
    $criterion,
    $policyRepository
): void {
    $candidateWithStock = static function (int $id, ?float $stock): ProductCandidate {
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
            ['laptops'],
            [],
            'https://store.test/product/' . $id,
            0,
            '2026-08-24T10:00:00Z',
            1.0,
            5.0,
            $stock,
            true,
            false
        );
    };
    $products = new VeyraRecommendationRunnerProducts([
        $candidateWithStock(60, 3.0),
        $candidateWithStock(61, 5.0),
        $candidateWithStock(62, null),
    ]);
    $service = new RecommendationService($products, $policyRepository());
    $quantity = $criterion('quantity', 'equals', 4, 'hard')->toArray();
    $filtered = $service->hardFilters([60, 61, 62], [$quantity]);

    $assert(
        array_column($filtered['eligible_candidates'] ?? [], 'product_id') === [61],
        'Quantity hard filter admitted insufficient or unverified stock.'
    );
    $codes = [];
    foreach ($filtered['rejected_candidates'] ?? [] as $rejected) {
        $codes[(int) ($rejected['product_id'] ?? 0)] = array_column($rejected['reasons'] ?? [], 'code');
    }
    $assert(
        in_array('quantity_stock_insufficient', $codes[60] ?? [], true)
            && in_array('quantity_stock_not_verified', $codes[62] ?? [], true),
        'Quantity evidence did not distinguish mismatch from unknown stock.'
    );
});

$scenario('unknown soft evidence is unscored and remains explicitly not verified', static function () use (
    $assert,
    $fixture,
    $criterion,
    $bound,
    $context
): void {
    $unknown = $criterion('preference', 'equals', 'quiet', 'soft');
    $fixtureData = $fixture([$unknown]);
    /** @var RecommendationToolHandler $handler */
    $handler = $fixtureData['handler'];

    $ranked = $handler->execute(new ToolCall(
        'call-unknown-soft-rank',
        'recommendation.rank',
        '1.0.0',
        $bound(['product_ids' => [10]], $fixtureData['state'])
    ), $context);
    $explained = $handler->execute(new ToolCall(
        'call-unknown-soft-explain',
        'recommendation.explain',
        '1.0.0',
        $bound(['product_ids' => [10]], $fixtureData['state'])
    ), $context);

    $assert(($ranked->data['ranked_candidates'][0]['score'] ?? null) === 100.0, 'Unknown soft evidence silently reduced the fit score.');
    $assert(($ranked->data['unscored_soft_requirements'] ?? []) === [$unknown->id], 'Unknown soft requirement was not disclosed as unscored.');
    $assert(($explained->data['explanations'][0]['classification'] ?? null) === 'not_verified', 'Unknown soft evidence was presented as an exact or mismatched fit.');
});

$scenario('variable parents require configuration while exact variations remain eligible', static function () use (
    $assert,
    $criterion,
    $policyRepository
): void {
    $product = static function (int $id, int $parentId, string $type): ProductCandidate {
        return new ProductCandidate(
            $id,
            $parentId,
            'Product ' . $id,
            'SKU-' . $id,
            $type,
            true,
            true,
            true,
            false,
            25.0,
            'USD',
            'instock',
            ['laptops'],
            [],
            'https://store.test/product/' . $id,
            0,
            '2026-08-24T10:00:00Z'
        );
    };
    $products = new VeyraRecommendationRunnerProducts([
        $product(40, 0, 'variable'),
        $product(41, 40, 'variation'),
    ]);
    $service = new RecommendationService($products, $policyRepository());
    $requirements = [$criterion('category', 'in', ['laptops'], 'soft')->toArray()];

    $parent = $service->retrieve([40]);
    $variation = $service->retrieve([41]);
    $filtered = $service->hardFilters([40, 41], $requirements);

    $assert(($parent['exact_configuration_required'] ?? false) === true && ($parent['exact_commerce_action_ready'] ?? true) === false, 'Variable parent was treated as an exact commerce target.');
    $assert(($variation['exact_configuration_required'] ?? true) === false && ($variation['exact_commerce_action_ready'] ?? false) === true, 'Exact variation was incorrectly treated as requiring another selection.');
    $assert(array_column($filtered['eligible_candidates'] ?? [], 'product_id') === [41], 'Hard-filter boundary did not distinguish a variable parent from its exact variation.');
});

$scenario('every recommendation operation remains advisory and state-bound', static function () use (
    $assert,
    $fixture,
    $criterion,
    $bound,
    $context
): void {
    $fixtureData = $fixture([$criterion('category', 'in', ['laptops'], 'soft')]);
    /** @var RecommendationToolHandler $handler */
    $handler = $fixtureData['handler'];
    foreach ($handler->definitions() as $definition) {
        $assert($definition->classification === 'advisory', $definition->name . ' is not advisory.');
    }
    $calls = [
        new ToolCall('call-retrieve', 'recommendation.retrieve_candidates', '1.0.0', ['product_ids' => [10, 20]]),
        new ToolCall('call-filter', 'recommendation.apply_hard_filters', '1.0.0', $bound(['product_ids' => [10, 20]], $fixtureData['state'])),
        new ToolCall('call-rank', 'recommendation.rank', '1.0.0', $bound(['product_ids' => [10, 20]], $fixtureData['state'])),
        new ToolCall('call-diverse', 'recommendation.diversify', '1.0.0', $bound(['product_ids' => [10, 20], 'limit' => 2], $fixtureData['state'])),
        new ToolCall('call-explain', 'recommendation.explain', '1.0.0', $bound(['product_ids' => [10, 20]], $fixtureData['state'])),
        new ToolCall('call-tune', 'recommendation.tune', '1.0.0', ['adjustments' => []]),
    ];
    foreach ($calls as $call) {
        $result = $handler->execute($call, $context);
        $assert(!$result->authoritative && $result->changedResources === [], $call->name . ' produced authoritative or mutating output.');
        if ($call->name !== 'recommendation.retrieve_candidates' && $call->name !== 'recommendation.tune') {
            $assert(
                ($result->data['bound_requirements_state']['resource_version'] ?? null) === $fixtureData['state']['resource_version']
                    && ($result->data['bound_requirements_state']['state_hash'] ?? null) === $fixtureData['state']['state_hash'],
                $call->name . ' omitted its exact requirement-state binding.'
            );
        }
    }
});

fwrite(STDOUT, sprintf("Recommendation state-binding scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
