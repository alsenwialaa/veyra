<?php
declare(strict_types=1);

define('AUTH_SALT', str_repeat('c', 64));

$veyraContextBundleBlogId = 1;
function get_current_blog_id(): int
{
    global $veyraContextBundleBlogId;
    return $veyraContextBundleBlogId;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Tool\ToolContext;
use Veyra\Conversation\Application\ContextBundleAssembler;
use Veyra\Conversation\Application\ContextBundleManifestRepository;
use Veyra\Conversation\Application\ConversationStore;
use Veyra\Conversation\Contract\ContextBundleContract;
use Veyra\Conversation\Domain\ContextBundleException;
use Veyra\Conversation\Domain\ContextBundleManifest;
use Veyra\Conversation\Domain\ContextBundlePolicy;
use Veyra\Conversation\Domain\ConversationFocus;
use Veyra\Conversation\Domain\JourneyState;
use Veyra\Experience\Contract\ProductReferenceIdentity;
use Veyra\Shared\Domain\CanonicalJson;

final class ContextBundleTestStore implements ConversationStore
{
    /** @param list<array<string, mixed>> $messages */
    public function __construct(
        private readonly string $conversationId,
        private readonly string $actorType,
        private readonly string $actorId,
        private array $messages = [],
        private array $memoryState = [],
        private array $summaryState = [],
        private readonly string $turnText = '',
        private readonly array $turnRender = [],
        private readonly array $turnProductReferences = [],
        private readonly ?ConversationFocus $focusState = null,
        private readonly array $journeyStates = []
    ) {
    }

    public function createConversation(string $actorType, string $actorId, ?int $userId, ?string $guestSessionId): string { throw new LogicException('Not used.'); }
    public function currentOwnedConversation(string $actorType, string $actorId): ?array { return $actorType === $this->actorType && $actorId === $this->actorId ? ['public_id' => $this->conversationId] : null; }
    public function getOwnedConversation(string $conversationId, string $actorType, string $actorId): ?array { return $conversationId === $this->conversationId && $actorType === $this->actorType && $actorId === $this->actorId ? ['public_id' => $conversationId] : null; }
    public function recentVisibleMessages(string $conversationId, string $actorType, string $actorId, int $limit): array { return $this->getOwnedConversation($conversationId, $actorType, $actorId) === null ? [] : array_slice($this->messages, -$limit); }
    public function visibleMessage(string $conversationId, string $actorType, string $actorId, string $messageId): ?array { if ($this->getOwnedConversation($conversationId, $actorType, $actorId) === null) { return null; } if ($messageId === 'msg-current-context-0001') { return ['message_id' => $messageId, 'sender_type' => 'customer', 'content' => ['text' => $this->turnText], 'render' => $this->turnRender, 'product_references' => $this->turnProductReferences]; } foreach ($this->recentVisibleMessages($conversationId, $actorType, $actorId, 50) as $message) { if (($message['message_id'] ?? null) === $messageId) { return $message; } } return null; }
    public function journeys(string $conversationId, string $actorType, string $actorId): array { return $this->getOwnedConversation($conversationId, $actorType, $actorId) === null ? [] : $this->journeyStates; }
    public function focus(string $conversationId, string $actorType, string $actorId): ?ConversationFocus { return $this->getOwnedConversation($conversationId, $actorType, $actorId) === null ? null : $this->focusState; }
    public function memory(string $conversationId, string $actorType, string $actorId): array { return $this->getOwnedConversation($conversationId, $actorType, $actorId) === null ? [] : $this->memoryState; }
    public function summary(string $conversationId, string $actorType, string $actorId): array { return $this->getOwnedConversation($conversationId, $actorType, $actorId) === null ? [] : $this->summaryState; }
    public function appendVisibleMessage(string $conversationId, string $actorType, string $actorId, string $senderType, string $text, array $renderPayload, array $evidence, string $correlationId): string { throw new LogicException('Not used.'); }
    public function saveFocus(string $conversationId, string $actorType, string $actorId, ConversationFocus $focus, string $expectedVersion): bool { return false; }
    public function consumePendingQuestion(string $conversationId, string $actorType, string $actorId, string $questionId, string $expectedFocusVersion, int $expectedQuestionVersion, string $customerMessageId, array $validatedBinding): array { return ['consumed' => false, 'code' => 'not_used', 'binding_id' => null]; }
    public function saveMemory(string $conversationId, string $actorType, string $actorId, array $memory, string $sourceMessageId): bool { $this->memoryState = $memory; return true; }
}

final class ContextBundleTestManifestRepository implements ContextBundleManifestRepository
{
    public ?ContextBundleManifest $saved = null;

    public function __construct(private readonly bool $accept = true)
    {
    }

    public function save(ContextBundleManifest $manifest): bool
    {
        $this->saved = $manifest;
        return $this->accept;
    }

    public function findOwned(string $bundleId, string $actorType, string $actorId): ?ContextBundleManifest
    {
        return $this->saved !== null
            && $this->saved->bundleId === $bundleId
            && $this->saved->actorType === $actorType
            && $this->saved->actorId === $actorId
                ? $this->saved
                : null;
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

$conversationId = 'conversation-context-0001';
$actorType = 'guest';
$actorId = 'guest-owner-context-0001';
$turnMessageId = 'msg-current-context-0001';
$context = new ToolContext(
    $actorType,
    $actorId,
    null,
    'guest-session-context-0001',
    $conversationId,
    [],
    ['ai_context_graph' => 'On'],
    'en_US',
    'correlation-context-0001'
);
$policy = static fn (int $bytes = 65536, bool $authorized = true): ContextBundlePolicy => new ContextBundlePolicy(
    'default_text_tool_orchestration',
    'test.manifest.1',
    'shopper_commerce_assistance',
    $authorized,
    $authorized ? 'runtime_ready' : 'provider_privacy_policy_required',
    ['internal', 'personal', 'commerce_confidential'],
    $bytes,
    256,
    300
);
$runtime = [
    'version' => 'runtime-test-1',
    'utc' => '2026-08-24T18:00:00+00:00',
    'local' => '2026-08-24T21:00:00+03:00',
    'timezone' => 'Asia/Aden',
    'locale' => 'en_US',
    'feature_states' => ['ai_context_graph' => 'On'],
];
$commerce = [
    'version' => 'cart-test-1',
    'freshness' => 'current',
    'cart' => [
        'available' => true,
        'hash' => 'cart-test-1',
        'item_count' => 1,
        'lines' => [[
            'line_id' => 'cart-line-0001',
            'product_id' => 42,
            'variation_id' => 0,
            'name' => 'Bounded product',
            'quantity' => 1.0,
        ]],
        'currency' => 'USD',
        'total' => '24.00',
    ],
];
$message = static fn (int $index, string $text): array => [
    'message_id' => 'msg-history-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
    'sender_type' => $index % 2 === 0 ? 'ai' : 'customer',
    'content' => ['text' => $text, 'evidence' => [['secret' => 'must-not-leak']]],
    'render' => ['private_renderer' => 'must-not-leak'],
    'language' => 'en_US',
    'direction' => 'ltr',
    'reply_to_message_id' => null,
    'product_references' => [['product_id' => 999]],
    'status' => 'delivered_to_server',
    'rendering_schema_version' => '1.0.0',
    'correlation_id' => 'private-correlation-' . $index,
    'created_at' => '2026-08-24T17:' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . ':00+00:00',
];

$scenario('durable manifest is metadata-only, complete, immutable, and required when configured', static function () use ($assert, $conversationId, $actorType, $actorId, $turnMessageId, $context, $policy, $runtime, $commerce): void {
    $rawText = 'Persist this selection, not this shopper sentence.';
    $store = new ContextBundleTestStore(
        $conversationId,
        $actorType,
        $actorId,
        [],
        ['unvalidated' => 'raw-memory-must-not-persist'],
        ['summary_text' => 'raw-summary-must-not-persist'],
        $rawText,
        ['attachment_ids' => ['attachment-private-manifest-0001'], 'location_supplied' => true]
    );
    $repository = new ContextBundleTestManifestRepository();
    $bundle = (new ContextBundleAssembler($store, $policy(), null, null, null, $repository))->assemble(
        $context,
        $turnMessageId,
        ['message_id' => $turnMessageId, 'text' => $rawText],
        $runtime,
        $commerce
    );
    $manifest = $repository->saved;
    $assert($bundle->manifestPersisted() && $manifest instanceof ContextBundleManifest, 'Verified manifest save was not bound to the issued bundle.');
    $stored = $manifest->storageProjection();
    $encoded = CanonicalJson::encode($stored);
    foreach ([$rawText, 'raw-memory-must-not-persist', 'raw-summary-must-not-persist', 'selected_data', 'provider_payload', 'attestation'] as $forbidden) {
        $assert(!str_contains($encoded, $forbidden), 'Manifest retained prohibited content: ' . $forbidden);
    }
    $sourceRows = $stored['source_accounting'];
    $included = count(array_filter($sourceRows, static fn (array $source): bool => $source['disposition'] === 'included'));
    $excluded = count(array_filter($sourceRows, static fn (array $source): bool => $source['disposition'] === 'excluded'));
    $assert(
        $included === $stored['selection_manifest']['included_count']
            && $excluded === $stored['selection_manifest']['excluded_count']
            && $included === $stored['actual_items'],
        'Identity-level source ledger did not equal aggregate selection accounting.'
    );
    $assert(
        count(array_unique(array_column($sourceRows, 'accounting_id'))) === count($sourceRows),
        'Manifest accepted duplicate accounting identities.'
    );

    $rejects = static function (array $candidate): bool {
        try {
            ContextBundleManifest::fromStorageProjection($candidate);
        } catch (ContextBundleException) {
            return true;
        }
        return false;
    };
    $tampered = $stored;
    $tampered['assembled_at'] = 'tomorrow';
    $assert($rejects($tampered), 'Natural-language manifest timestamp was accepted.');
    $tampered = $stored;
    $tampered['source_accounting'][0]['observed_at'] = '2026-08-24T18:00:00Z';
    $assert($rejects($tampered), 'Non-canonical source timestamp was accepted.');
    $tampered = $stored;
    $tampered['source_accounting'][0]['accounting_id'] = str_repeat('a', 64);
    $assert($rejects($tampered), 'Non-derived accounting ID was accepted.');
    $tampered = $stored;
    ++$tampered['selection_manifest']['included_count'];
    $assert($rejects($tampered), 'Aggregate/detail count mismatch was accepted.');
    $tampered = $stored;
    foreach ($tampered['selection_manifest']['sections'] as $index => $section) {
        if (($section['section'] ?? null) === 'durable_preferences') {
            $tampered['selection_manifest']['sections'][$index]['section'] = 'unknown_zero_section';
            break;
        }
    }
    $assert($rejects($tampered), 'Unknown zero-count section replaced a canonical manifest section.');
    $tampered = $stored;
    array_pop($tampered['source_accounting']);
    $assert($rejects($tampered), 'Selection identity absent from the complete ledger was accepted.');

    $failedRepository = new ContextBundleTestManifestRepository(false);
    $code = null;
    try {
        (new ContextBundleAssembler($store, $policy(), null, null, null, $failedRepository))->assemble(
            $context,
            $turnMessageId,
            ['message_id' => $turnMessageId, 'text' => $rawText],
            $runtime,
            $commerce
        );
    } catch (ContextBundleException $error) {
        $code = $error->reasonCode;
    }
    $assert($code === 'context_bundle_manifest_persistence_failed', 'Unverified manifest save did not fail assembly closed.');
});

$scenario('canonical projection is closed, bounded, hash-stable, and pseudonymous', static function () use ($assert, $conversationId, $actorType, $actorId, $turnMessageId, $context, $policy, $runtime, $commerce, $message): void {
    $store = new ContextBundleTestStore(
        $conversationId,
        $actorType,
        $actorId,
        [$message(1, 'Earlier shopper message'), $message(2, 'Earlier agent message')],
        ['requirements' => [['legacy' => true]], 'unvalidated' => 'must-not-leak'],
        ['summary_text' => 'must-not-leak'],
        'Please compare the current cart.',
        ['attachment_ids' => ['attachment-private-0001'], 'location_supplied' => true]
    );
    $bundle = (new ContextBundleAssembler($store, $policy()))->assemble(
        $context,
        $turnMessageId,
        [
            'message_id' => $turnMessageId,
            'text' => 'Please compare the current cart.',
            'reply_snapshot' => null,
            'product_reference_snapshots' => [],
            'attachment_ids' => ['attachment-private-0001'],
            'location' => ['latitude' => 10],
            'client_quick_reply_hint' => null,
        ],
        $runtime,
        $commerce
    );
    $projection = $bundle->forProvider();
    $encoded = CanonicalJson::encode($projection);
    $assert($projection['schema_version'] === '1.1.0' && $projection['bundle_version'] === 1, 'Published bundle version is wrong.');
    $assert($projection['limits']['actual_bytes'] === strlen($encoded), 'Whole-bundle byte count is not exact.');
    $assert($projection['limits']['actual_bytes'] <= $projection['limits']['max_bytes'], 'Bundle exceeded its route bound.');
    $assert($projection['actor_scope']['actor_id'] !== $actorId && !str_contains($encoded, 'guest-session-context-0001'), 'Raw actor identity leaked.');
    foreach (['must-not-leak', 'private-correlation-', 'private_renderer', 'attachment-private-0001'] as $forbidden) {
        $assert(!str_contains($encoded, $forbidden), 'Omitted raw state leaked: ' . $forbidden);
    }
    $assert($projection['selected_data']['conversation_memory'] === [] && $projection['selected_data']['validated_summary'] === null, 'Unvalidated continuity state was transmitted.');
    $assert($bundle->hash === hash('sha256', $encoded), 'Bundle digest is not bound to the exact canonical provider projection.');
    $assert($bundle->forProvider() === $projection, 'Validated provider projection was not stable across reads.');
    $reference = $bundle->reference();
    $assert(!array_key_exists('selected_data', $reference) && !array_key_exists('source_manifest', $reference), 'Message correlation duplicated Context Bundle content.');

    $tampered = $projection;
    $tampered['unknown'] = true;
    $rejected = false;
    try {
        (new ContextBundleContract())->assertValid(
            $tampered,
            $context,
            $turnMessageId,
            $policy(),
            $projection['actor_scope']['actor_id'],
            $projection['actor_scope']['site_id']
        );
    } catch (ContextBundleException $error) {
        $rejected = $error->reasonCode === 'context_bundle_unknown_or_missing_field';
    }
    $assert($rejected, 'Unknown top-level field was accepted.');

    foreach (['selected_data', 'source_manifest', 'privacy', 'limits'] as $nested) {
        $tampered = $projection;
        if ($nested === 'selected_data') {
            $tampered['selected_data']['current_input']['unknown'] = true;
        } elseif ($nested === 'source_manifest') {
            $tampered['source_manifest'][0]['unknown'] = true;
        } else {
            $tampered[$nested]['unknown'] = true;
        }
        $rejected = false;
        try {
            (new ContextBundleContract())->assertValid(
                $tampered,
                $context,
                $turnMessageId,
                $policy(),
                $projection['actor_scope']['actor_id'],
                $projection['actor_scope']['site_id']
            );
        } catch (ContextBundleException $error) {
            $rejected = $error->reasonCode === 'context_bundle_unknown_or_missing_field';
        }
        $assert($rejected, 'Unknown nested field was accepted in ' . $nested . '.');
    }
});

$scenario('contract rejects provenance, modality, and timestamp parity drift', static function () use ($assert, $conversationId, $actorType, $actorId, $turnMessageId, $context, $policy, $runtime, $commerce): void {
    $store = new ContextBundleTestStore($conversationId, $actorType, $actorId, [], [], [], 'Validate every invariant.');
    $projection = (new ContextBundleAssembler($store, $policy()))->assemble(
        $context,
        $turnMessageId,
        ['message_id' => $turnMessageId, 'text' => 'Validate every invariant.'],
        $runtime,
        $commerce
    )->forProvider();
    $contract = new ContextBundleContract();
    $rejects = static function (array $candidate, string $expected) use ($contract, $context, $turnMessageId, $policy, $projection): bool {
        try {
            $contract->assertValid(
                $candidate,
                $context,
                $turnMessageId,
                $policy(),
                $projection['actor_scope']['actor_id'],
                $projection['actor_scope']['site_id']
            );
        } catch (ContextBundleException $error) {
            return $error->reasonCode === $expected;
        }
        return false;
    };

    $tampered = $projection;
    $tampered['source_manifest'][0]['version'] = 'different-source-version';
    $assert($rejects($tampered, 'context_bundle_sources_invalid'), 'Source-version equality drift was accepted.');

    $tampered = $projection;
    $tampered['modalities'][0]['source_id'] = 'different-message-0001';
    $assert($rejects($tampered, 'context_bundle_modalities_invalid'), 'Text modality was not bound to the exact turn message.');

    $tampered = $projection;
    $tampered['assembled_at'] = 'tomorrow';
    $assert($rejects($tampered, 'context_bundle_timestamp_invalid'), 'Relative timestamp syntax was accepted.');

    $tampered = $projection;
    $future = new DateTimeImmutable('+1 day', new DateTimeZone('UTC'));
    $tampered['assembled_at'] = $future->format(DATE_ATOM);
    $tampered['expires_at'] = $future->modify('+300 seconds')->format(DATE_ATOM);
    $assert($rejects($tampered, 'context_bundle_expiry_invalid'), 'Future-dated bundle was accepted.');
});

$scenario('foreign actor and conversation cannot assemble a bundle', static function () use ($assert, $conversationId, $actorType, $actorId, $turnMessageId, $policy, $runtime, $commerce): void {
    $store = new ContextBundleTestStore($conversationId, $actorType, $actorId, [], [], [], 'foreign');
    $foreign = new ToolContext('guest', 'guest-foreign-context-0001', null, 'guest-session-foreign-0001', $conversationId, [], ['ai_context_graph' => 'On'], 'en_US', 'correlation-foreign-0001');
    $code = null;
    try {
        (new ContextBundleAssembler($store, $policy()))->assemble(
            $foreign,
            $turnMessageId,
            ['message_id' => $turnMessageId, 'text' => 'foreign'],
            $runtime,
            $commerce
        );
    } catch (ContextBundleException $error) {
        $code = $error->reasonCode;
    }
    $assert($code === 'context_bundle_conversation_not_owned', 'Foreign actor assembly did not fail closed.');
});

$scenario('turn payload must match the exact owned persisted customer message', static function () use ($assert, $conversationId, $actorType, $actorId, $turnMessageId, $context, $policy, $runtime, $commerce): void {
    $store = new ContextBundleTestStore($conversationId, $actorType, $actorId, [], [], [], 'Persisted exact text');
    $code = null;
    try {
        (new ContextBundleAssembler($store, $policy()))->assemble(
            $context,
            $turnMessageId,
            ['message_id' => $turnMessageId, 'text' => 'Different transient text'],
            $runtime,
            $commerce
        );
    } catch (ContextBundleException $error) {
        $code = $error->reasonCode;
    }
    $assert($code === 'context_bundle_turn_message_not_owned', 'Transient input was not bound to the exact persisted customer message.');
});

$scenario('explicit quote and product references are reloaded from actor-owned history', static function () use ($assert, $conversationId, $actorType, $actorId, $turnMessageId, $context, $policy, $runtime, $commerce, $message): void {
    $owned = $message(1, 'Exact actor-owned historical text');
    $owned['product_references'] = [[
        'product_id' => 42,
        'variation_id' => 7,
        'name' => 'Bound small variation',
    ], [
        'product_id' => 42,
        'variation_id' => 8,
        'name' => 'Bound large variation',
    ]];
    $presented = ProductReferenceIdentity::presentReferences($owned['product_references'], $owned['message_id']);
    $storedBindings = [];
    foreach ($presented as $reference) {
        $storedBindings[] = [
            'schema_version' => ProductReferenceIdentity::BINDING_SCHEMA_VERSION,
            'reference_id' => $reference['reference_id'],
            'source_message_id' => $owned['message_id'],
            'product_id' => $reference['snapshot']['product_id'],
            'variation_id' => $reference['snapshot']['variation_id'],
            'historical_references' => [$reference['snapshot']],
        ];
    }
    $other = $message(2, 'Other actor-owned reference');
    $store = new ContextBundleTestStore(
        $conversationId,
        $actorType,
        $actorId,
        [$owned, $other],
        [],
        [],
        'Use that item.',
        ['reply_snapshot' => ['message_id' => $owned['message_id']]],
        $storedBindings
    );
    $projection = (new ContextBundleAssembler($store, $policy()))->assemble(
        $context,
        $turnMessageId,
        [
            'message_id' => $turnMessageId,
            'text' => 'Use that item.',
            'reply_snapshot' => ['message_id' => $other['message_id'], 'sender_type' => 'ai', 'content' => ['text' => 'forged']],
            'product_reference_snapshots' => [['source_message_id' => $other['message_id'], 'historical_references' => [['product_id' => 123456]]]],
        ],
        $runtime,
        $commerce
    )->forProvider();
    $current = $projection['selected_data']['current_input'];
    $assert($current['reply_quote']['text'] === 'Exact actor-owned historical text', 'Caller-supplied quote body was trusted.');
    $assert(
        array_column($current['product_reference_bindings'], 'variation_id') === [7, 8]
            && array_column($current['product_reference_bindings'], 'reference_id') === array_column($presented, 'reference_id'),
        'Two exact references from one actor-owned message were not retained independently.'
    );
    $assert(!str_contains(CanonicalJson::encode($projection), '123456'), 'Caller-supplied historical product body leaked.');
    $referenceIds = array_column($presented, 'reference_id');
    $modalitySources = array_values(array_filter(
        $projection['source_manifest'],
        static fn (array $source): bool => $source['section'] === 'modalities'
            && in_array($source['source']['source_id'] ?? null, $referenceIds, true)
    ));
    $assert(
        count($modalitySources) === 2
            && array_column(array_column($modalitySources, 'source'), 'source_id') === $referenceIds
            && array_unique(array_column(array_column($modalitySources, 'source'), 'source_message_id')) === [$owned['message_id']],
        'Each exact reference was not versioned against its actor-owned source message.'
    );
});

$scenario('site scope is distinct across multisite blog identities', static function () use ($assert, $conversationId, $actorType, $actorId, $turnMessageId, $context, $policy, $runtime, $commerce): void {
    global $veyraContextBundleBlogId;
    $store = new ContextBundleTestStore($conversationId, $actorType, $actorId, [], [], [], 'Scope this site.');
    $veyraContextBundleBlogId = 1;
    $first = (new ContextBundleAssembler($store, $policy()))->assemble($context, $turnMessageId, ['message_id' => $turnMessageId, 'text' => 'Scope this site.'], $runtime, $commerce)->forProvider();
    $veyraContextBundleBlogId = 2;
    $second = (new ContextBundleAssembler($store, $policy()))->assemble($context, $turnMessageId, ['message_id' => $turnMessageId, 'text' => 'Scope this site.'], $runtime, $commerce)->forProvider();
    $veyraContextBundleBlogId = 1;
    $assert($first['actor_scope']['site_id'] !== $second['actor_scope']['site_id'], 'Two blog identities shared one provider site scope.');
});

$scenario('active journey graph must exactly match Conversation Focus', static function () use ($assert, $conversationId, $actorType, $actorId, $turnMessageId, $context, $policy, $runtime, $commerce): void {
    $journey = static fn (string $id): JourneyState => new JourneyState(
        $id,
        $conversationId,
        $actorId,
        'assistance',
        '1',
        'active',
        'current-step',
        'current-step',
        [],
        [],
        [],
        [],
        null
    );
    $code = null;
    try {
        $store = new ContextBundleTestStore(
            $conversationId, $actorType, $actorId, [], [], [], 'Check journey state.', [], [], null,
            [$journey('journey-active-0001')]
        );
        (new ContextBundleAssembler($store, $policy()))->assemble($context, $turnMessageId, ['message_id' => $turnMessageId, 'text' => 'Check journey state.'], $runtime, $commerce);
    } catch (ContextBundleException $error) {
        $code = $error->reasonCode;
    }
    $assert($code === 'context_bundle_journey_graph_inconsistent', 'Active journey without focus was silently omitted.');

    $focus = new ConversationFocus('1', 'journey-active-0001', [], null, [], 'focus-source-message-0001', new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $code = null;
    try {
        $store = new ContextBundleTestStore(
            $conversationId, $actorType, $actorId, [], [], [], 'Check two active journeys.', [], [], $focus,
            [$journey('journey-active-0001'), $journey('journey-active-0002')]
        );
        (new ContextBundleAssembler($store, $policy()))->assemble($context, $turnMessageId, ['message_id' => $turnMessageId, 'text' => 'Check two active journeys.'], $runtime, $commerce);
    } catch (ContextBundleException $error) {
        $code = $error->reasonCode;
    }
    $assert($code === 'context_bundle_journey_graph_inconsistent', 'Multiple active journeys were presented as one coherent foreground.');
});

$scenario('optional history is reduced oldest-first with explicit selection reasons', static function () use ($assert, $conversationId, $actorType, $actorId, $turnMessageId, $context, $policy, $runtime, $commerce, $message): void {
    $messages = [];
    for ($index = 1; $index <= 12; ++$index) {
        $messages[] = $message($index, str_repeat(chr(64 + $index), 900));
    }
    $store = new ContextBundleTestStore($conversationId, $actorType, $actorId, $messages, [], [], 'Bound this context.');
    $projection = (new ContextBundleAssembler($store, $policy(12000)))->assemble(
        $context,
        $turnMessageId,
        ['message_id' => $turnMessageId, 'text' => 'Bound this context.'],
        $runtime,
        $commerce
    )->forProvider();
    $included = $projection['recent_visible_message_refs'];
    $assert(count($included) < 12 && $included !== [], 'History was not reduced to the whole-bundle byte bound.');
    $assert($included[0] !== 'msg-history-0001', 'Oldest message was not removed first.');
    $section = null;
    foreach ($projection['selection_manifest']['sections'] as $candidate) {
        if ($candidate['section'] === 'recent_visible_messages') {
            $section = $candidate;
            break;
        }
    }
    $assert(is_array($section) && $section['truncated'] === true && in_array('oldest_message_removed_for_route_bound', $section['exclusion_reasons'], true), 'Deterministic truncation was not recorded.');
    $assert($projection['limits']['actual_bytes'] === strlen(CanonicalJson::encode($projection)), 'Reduced bundle byte count drifted.');
});

$scenario('mandatory context overflow fails closed instead of trimming shopper input', static function () use ($assert, $conversationId, $actorType, $actorId, $turnMessageId, $context, $policy, $runtime, $commerce): void {
    $store = new ContextBundleTestStore($conversationId, $actorType, $actorId, [], [], [], str_repeat('x', 12000));
    $code = null;
    try {
        (new ContextBundleAssembler($store, $policy(4096)))->assemble(
            $context,
            $turnMessageId,
            ['message_id' => $turnMessageId, 'text' => str_repeat('x', 12000)],
            $runtime,
            $commerce
        );
    } catch (ContextBundleException $error) {
        $code = $error->reasonCode;
    }
    $assert($code === 'context_bundle_limit_exceeded', 'Mandatory overflow did not return the distinct safe code.');
});

$scenario('denied route decision is represented and never promoted to authorized', static function () use ($assert, $conversationId, $actorType, $actorId, $turnMessageId, $context, $policy, $runtime, $commerce): void {
    $store = new ContextBundleTestStore($conversationId, $actorType, $actorId, [], [], [], 'Do not transmit.');
    $bundle = (new ContextBundleAssembler($store, $policy(65536, false)))->assemble(
        $context,
        $turnMessageId,
        ['message_id' => $turnMessageId, 'text' => 'Do not transmit.'],
        $runtime,
        $commerce
    );
    $assert(!$bundle->transmissionAuthorized(), 'Denied policy became transmittable.');
    $assert($bundle->reference()['transmission_decision_code'] === 'provider_privacy_policy_required', 'Denied decision code was not retained.');
});

fwrite(STDOUT, sprintf("Context Bundle contract scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
