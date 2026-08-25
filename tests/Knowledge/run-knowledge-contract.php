<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Provider\ProviderSafeToolResultProjector;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\Knowledge\Application\KnowledgeService;
use Veyra\Knowledge\Contract\PublishedKnowledgeRepository;
use Veyra\Knowledge\Domain\PublishedKnowledgeIndex;
use Veyra\Knowledge\Tool\KnowledgeToolHandler;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Support\FrozenClock;

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

$source = static function (
    string $id,
    string $type,
    string $content,
    string $authority = 'authoritative_policy',
    ?string $policyKey = null,
    array $productIds = []
): array {
    return [
        'id' => $id,
        'type' => $type,
        'version' => '1',
        'title' => $content,
        'content' => $content,
        'language' => 'en',
        'owner' => 'merchant',
        'authority' => $authority,
        'scope' => 'public',
        'status' => 'approved',
        'effective_from' => '2026-01-01T00:00:00Z',
        'expires_at' => '2027-01-01T00:00:00Z',
        'approved_at' => '2026-01-01T00:00:00Z',
        'citations' => [[
            'citation_id' => $id . '-citation',
            'label' => $content,
            'url' => 'https://example.test/knowledge/' . $id,
        ]],
        'data_classification' => 'public',
        'injection_treatment' => 'content_only',
        'policy_key' => $policyKey,
        'product_ids' => $productIds,
        'keywords' => [$type, 'shipping'],
    ];
};

$index = PublishedKnowledgeIndex::fromPublishedPayload([
    'schema_version' => '1.0.0',
    'status' => 'published',
    'publication_id' => 'publication-0001',
    'version' => '7',
    'store_id' => 1,
    'published_at' => '2026-08-20T00:00:00Z',
    'sources' => [
        $source('policy-general-0001', 'policy', 'General policy evidence.', 'authoritative_policy', 'general'),
        $source('guide-product-0001', 'product_guide', 'Product 41 guide evidence.', 'authoritative_product_guide', null, [41]),
        $source('shipping-policy-0001', 'shipping_policy', 'Shipping is available in the selected market.'),
        $source('payment-policy-0001', 'payment_policy', 'Payment policy evidence.'),
        $source('return-policy-0001', 'return_policy', 'Return policy evidence.', 'merchant_approved'),
        $source('shipping-policy-0002', 'shipping_policy', 'Secondary shipping evidence.', 'merchant_approved'),
        $source('shipping-policy-0003', 'shipping_policy', 'Third shipping evidence.', 'merchant_approved'),
        $source('shipping-policy-0004', 'shipping_policy', 'Fourth shipping evidence.', 'merchant_approved'),
        $source('shipping-policy-0005', 'shipping_policy', 'Fifth shipping evidence.', 'merchant_approved'),
        $source('shipping-policy-0006', 'shipping_policy', 'Sixth shipping evidence.', 'merchant_approved'),
    ],
], 1);
$repository = new class($index) implements PublishedKnowledgeRepository {
    public function __construct(private readonly PublishedKnowledgeIndex $index)
    {
    }

    public function published(): ?PublishedKnowledgeIndex
    {
        return $this->index;
    }
};
$handler = new KnowledgeToolHandler(new KnowledgeService(
    $repository,
    new FrozenClock(UtcInstant::fromDatabase('2026-08-25 10:00:00'))
));
$validator = new ToolInputValidator();
$registry = new ToolRegistry($validator);
$registry->register($handler);
$context = new ToolContext(
    'guest',
    'guest-session-knowledge',
    null,
    'guest-session-knowledge',
    'conversation-knowledge-0001',
    [],
    ['ai_merchant_knowledge' => 'On'],
    'en_US',
    '11111111-1111-4111-8111-111111111111'
);

$scenario('all implemented knowledge results satisfy exact provider contracts', static function () use (
    $assert,
    $context,
    $handler,
    $registry,
    $validator
): void {
    $definitions = [];
    foreach ($handler->definitions() as $definition) {
        $definitions[$definition->name] = $definition;
    }
    $calls = [
        new ToolCall('knowledge-search-0001', 'knowledge.search', '1.0.0', [
            'query' => 'shipping',
            'source_types' => ['shipping_policy'],
            'limit' => 8,
        ]),
        new ToolCall('knowledge-read-0001', 'knowledge.read_source', '1.0.0', [
            'source_id' => 'shipping-policy-0001',
            'offset' => 0,
            'max_characters' => 4000,
        ]),
        new ToolCall('knowledge-policy-0001', 'knowledge.get_policy', '1.0.0', ['policy_key' => 'general']),
        new ToolCall('knowledge-guide-0001', 'knowledge.get_product_guide', '1.0.0', ['product_id' => 41]),
        new ToolCall('knowledge-shipping-0001', 'knowledge.get_shipping_policy', '1.0.0', []),
        new ToolCall('knowledge-payment-0001', 'knowledge.get_payment_policy', '1.0.0', []),
        new ToolCall('knowledge-return-0001', 'knowledge.get_return_policy', '1.0.0', []),
        new ToolCall('knowledge-freshness-0001', 'knowledge.check_freshness', '1.0.0', [
            'source_ids' => ['shipping-policy-0001', 'missing-source-0001'],
        ]),
        new ToolCall('knowledge-conflict-0001', 'knowledge.resolve_conflict', '1.0.0', [
            'source_ids' => ['shipping-policy-0001', 'return-policy-0001'],
        ]),
        new ToolCall('knowledge-citations-0001', 'knowledge.get_citations', '1.0.0', [
            'source_ids' => ['shipping-policy-0001', 'missing-source-0001'],
        ]),
    ];
    $projector = new ProviderSafeToolResultProjector();
    foreach ($calls as $call) {
        $definition = $definitions[$call->name] ?? null;
        $assert($definition !== null && $definition->outputSchema !== [], $call->name . ' has no closed output contract.');
        $result = $registry->execute($call, $context);
        $assert($result->status === 'succeeded', $call->name . ' failed with ' . $result->code . '.');
        $assert(
            $validator->validateValue($result->data, $definition->outputSchema),
            $call->name . ' violated its closed output contract.'
        );
        $projected = $projector->project($result, $registry);
        $assert(($projected['tool'] ?? null) === $call->name, $call->name . ' was not provider-projectable.');
    }
    $conflict = $registry->execute($calls[8], $context);
    $assert(!$conflict->authoritative, 'Conflict resolution incorrectly granted semantic authority.');
});

$scenario('knowledge list inputs reject malformed and duplicate identifiers', static function () use (
    $assert,
    $context,
    $registry
): void {
    $invalid = [
        new ToolCall('knowledge-invalid-type', 'knowledge.search', '1.0.0', [
            'query' => 'shipping',
            'source_types' => ['shipping_policy', 4],
        ]),
        new ToolCall('knowledge-duplicate-id', 'knowledge.check_freshness', '1.0.0', [
            'source_ids' => ['shipping-policy-0001', 'shipping-policy-0001'],
        ]),
        new ToolCall('knowledge-conflict-one', 'knowledge.resolve_conflict', '1.0.0', [
            'source_ids' => ['shipping-policy-0001'],
        ]),
        new ToolCall('knowledge-invalid-id', 'knowledge.get_citations', '1.0.0', [
            'source_ids' => ['unsafe source id'],
        ]),
    ];
    foreach ($invalid as $call) {
        $result = $registry->execute($call, $context);
        $assert(
            $result->status === 'blocked' && $result->code === 'tool_input_invalid',
            $call->callId . ' did not fail closed at the typed input boundary.'
        );
    }
});

$scenario('knowledge evidence remains untrusted and selection-explicit', static function () use (
    $assert,
    $context,
    $registry
): void {
    $result = $registry->execute(new ToolCall(
        'knowledge-evidence-0001',
        'knowledge.search',
        '1.0.0',
        ['query' => 'shipping', 'limit' => 1]
    ), $context);
    $item = $result->data['results'][0] ?? [];
    $assert(($item['content_role'] ?? null) === 'untrusted_evidence', 'Knowledge content was not marked untrusted.');
    $assert(($item['embedded_instructions_authorized'] ?? null) === false, 'Embedded source instructions gained authority.');
    $assert(array_key_exists('selection_required', $result->data), 'Knowledge selection state was implicit.');
    $assert(
        ($result->data['count'] ?? null) === 1
            && ($result->data['total_matching'] ?? 0) > 1
            && ($result->data['selection_required'] ?? false) === true
            && ($result->data['selection_performed'] ?? true) === false
            && ($result->data['truncated'] ?? false) === true
            && ($result->data['complete'] ?? true) === false,
        'A bounded search hid additional matches or implied a first-result selection.'
    );
    $policy = $registry->execute(new ToolCall(
        'knowledge-policy-truncation-0001',
        'knowledge.get_shipping_policy',
        '1.0.0',
        []
    ), $context);
    $assert(
        ($policy->data['count'] ?? null) === 5
            && ($policy->data['total_matching'] ?? 0) === 6
            && ($policy->data['selection_required'] ?? false) === true
            && ($policy->data['selection_performed'] ?? true) === false
            && ($policy->data['truncated'] ?? false) === true
            && ($policy->data['complete'] ?? true) === false,
        'Bounded policy evidence hid source completeness or implied authority.'
    );
    $empty = $registry->execute(new ToolCall(
        'knowledge-no-match-0001',
        'knowledge.search',
        '1.0.0',
        ['query' => 'no-such-merchant-evidence-token']
    ), $context);
    $assert(
        ($empty->data['count'] ?? null) === 0
            && ($empty->data['total_matching'] ?? null) === 0
            && ($empty->data['selection_required'] ?? true) === false
            && ($empty->data['selection_performed'] ?? true) === false
            && ($empty->data['complete'] ?? false) === true,
        'An empty evidence set incorrectly requested a selection or hid completeness.'
    );
});

fwrite(STDOUT, sprintf("Knowledge contract scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
