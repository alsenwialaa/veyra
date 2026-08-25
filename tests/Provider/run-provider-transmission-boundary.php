<?php
declare(strict_types=1);

define('AUTH_KEY', str_repeat('a', 64));
define('AUTH_SALT', str_repeat('b', 64));
define('SECURE_AUTH_SALT', str_repeat('s', 64));
define('VEYRA_SCHEMA_VERSION', '1.6.0');

/** @var array<string, mixed> $veyraTransmissionOptions */
$veyraTransmissionOptions = [];
$veyraTransmissionHttpCalls = 0;
$veyraTransmissionHttpMode = 'error';
$veyraTransmissionLastHttpBody = null;

function get_option(string $name, mixed $default = false): mixed
{
    global $veyraTransmissionOptions;
    return array_key_exists($name, $veyraTransmissionOptions) ? $veyraTransmissionOptions[$name] : $default;
}

function update_option(string $name, mixed $value, bool $autoload = false): bool
{
    global $veyraTransmissionOptions;
    $veyraTransmissionOptions[$name] = $value;
    return true;
}

function delete_option(string $name): bool
{
    global $veyraTransmissionOptions;
    unset($veyraTransmissionOptions[$name]);
    return true;
}

function wp_remote_post(string $url, array $arguments): array
{
    global $veyraTransmissionHttpCalls, $veyraTransmissionHttpMode, $veyraTransmissionLastHttpBody;
    ++$veyraTransmissionHttpCalls;
    $body = json_decode((string) ($arguments['body'] ?? ''), true);
    $veyraTransmissionLastHttpBody = is_array($body) ? $body : null;
    if (in_array($veyraTransmissionHttpMode, ['readiness_success', 'readiness_extra_call'], true)
        && is_array($body)
    ) {
        $nonce = $body['tools'][0]['parameters']['properties']['nonce']['enum'][0] ?? null;
        $steps = [[
            'type' => 'model_output',
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'schema_version' => '1.0.0',
                    'probe_status' => 'tool_call_requested',
                ], JSON_THROW_ON_ERROR),
            ]],
        ], [
            'type' => 'function_call',
            'id' => 'readiness-call-1',
            'name' => 'diagnostics__probe',
            'arguments' => ['nonce' => $nonce],
        ]];
        if ($veyraTransmissionHttpMode === 'readiness_extra_call') {
            $steps[] = [
                'type' => 'function_call',
                'id' => 'readiness-call-2',
                'name' => 'diagnostics__probe',
                'arguments' => ['nonce' => $nonce],
            ];
        }
        return [
            'response' => ['code' => 200],
            'body' => json_encode(['status' => 'requires_action', 'steps' => $steps], JSON_THROW_ON_ERROR),
        ];
    }
    if ($veyraTransmissionHttpMode === 'shopper_unexpected_call') {
        return [
            'response' => ['code' => 200],
            'body' => json_encode([
                'status' => 'requires_action',
                'steps' => [[
                    'type' => 'model_output',
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode(
                            TransmissionSequencedProvider::decisionPayload(),
                            JSON_THROW_ON_ERROR
                        ),
                    ]],
                ], [
                    'type' => 'function_call',
                    'id' => 'undeclared-shopper-call-1',
                    'name' => 'catalog__get_product',
                    'arguments' => ['product_id' => 42],
                ]],
            ], JSON_THROW_ON_ERROR),
        ];
    }
    return ['response' => ['code' => 500], 'body' => '{}'];
}

function is_wp_error(mixed $value): bool { return false; }
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Contract\ProviderRequest;
use Veyra\AI\Contract\ProviderAdapter;
use Veyra\AI\Contract\ProviderResult;
use Veyra\AI\Provider\CredentialVault;
use Veyra\AI\Provider\GeminiProviderAdapter;
use Veyra\AI\Provider\ProviderPayloadValidator;
use Veyra\AI\Provider\ProviderRequestAttestor;
use Veyra\AI\Provider\ProviderReadinessService;
use Veyra\AI\Provider\ProviderReadinessStateStore;
use Veyra\AI\Provider\ProviderTransmissionGate;
use Veyra\AI\Provider\RouteManifest;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\AI\Orchestration\AgentTurnInput;
use Veyra\AI\Orchestration\AuthoritativeContextProvider;
use Veyra\AI\Orchestration\CommerceAgent;
use Veyra\AI\Orchestration\DecisionPlanExecutor;
use Veyra\AI\Orchestration\PromptPolicyCompiler;
use Veyra\AI\Orchestration\ResponseVerifier;
use Veyra\AI\Orchestration\SemanticResponseVerifier;
use Veyra\AI\Orchestration\ServerComponentBuilder;
use Veyra\Conversation\Application\ContextBundleAssembler;
use Veyra\Conversation\Application\ContextBundleManifestRepository;
use Veyra\Conversation\Application\ConversationStateUpdater;
use Veyra\Conversation\Application\ConversationStore;
use Veyra\Conversation\Application\ShortReplyBindingValidator;
use Veyra\Conversation\Domain\ContextBundlePolicy;
use Veyra\Conversation\Domain\ContextBundle;
use Veyra\Conversation\Domain\ContextBundleAttestor;
use Veyra\Conversation\Domain\ContextBundleManifest;
use Veyra\Conversation\Domain\ConversationFocus;
use Veyra\Conversation\Domain\JourneyState;
use Veyra\Conversation\Domain\PendingQuestion;
use Veyra\Shared\Domain\CanonicalJson;

final class TransmissionContextStore implements ConversationStore
{
    /** @var list<array<string, mixed>> */
    private array $messages = [];
    /** @var list<array<string, mixed>> */
    private array $renders = [];
    public bool $consumed = false;

    public function __construct(private readonly string $conversationId, private readonly string $actorId, private readonly string $turnText = 'Check the current cart.', private readonly ?ConversationFocus $focusState = null) {}
    public function createConversation(string $actorType, string $actorId, ?int $userId, ?string $guestSessionId): string { throw new LogicException('Not used.'); }
    public function currentOwnedConversation(string $actorType, string $actorId): ?array { return $actorType === 'guest' && $actorId === $this->actorId ? [] : null; }
    public function getOwnedConversation(string $conversationId, string $actorType, string $actorId): ?array { return $conversationId === $this->conversationId && $actorType === 'guest' && $actorId === $this->actorId ? ['public_id' => $conversationId] : null; }
    public function recentVisibleMessages(string $conversationId, string $actorType, string $actorId, int $limit): array { return $this->getOwnedConversation($conversationId, $actorType, $actorId) === null ? [] : array_slice($this->messages, -$limit); }
    public function visibleMessage(string $conversationId, string $actorType, string $actorId, string $messageId): ?array { if ($this->getOwnedConversation($conversationId, $actorType, $actorId) === null) { return null; } if ($messageId === 'msg-provider-current-0001' && $this->messages === []) { return ['message_id' => $messageId, 'sender_type' => 'customer', 'content' => ['text' => $this->turnText]]; } foreach ($this->messages as $message) { if (($message['message_id'] ?? null) === $messageId) { return $message; } } return null; }
    public function journeys(string $conversationId, string $actorType, string $actorId): array
    {
        if ($this->getOwnedConversation($conversationId, $actorType, $actorId) === null
            || $this->focusState?->foregroundJourneyId === null
        ) {
            return [];
        }
        return [new JourneyState(
            $this->focusState->foregroundJourneyId,
            $conversationId,
            $actorId,
            'product_discovery',
            'journey-version-1',
            'active',
            'answer-choice',
            'answer-choice',
            [],
            $this->focusState->pendingQuestion === null ? [] : [$this->focusState->pendingQuestion->id],
            $this->focusState->focusedResources,
            ['runtime_version' => 'runtime-provider-1'],
            'checkpoint-provider-1'
        )];
    }
    public function focus(string $conversationId, string $actorType, string $actorId): ?ConversationFocus { return $this->getOwnedConversation($conversationId, $actorType, $actorId) === null ? null : $this->focusState; }
    public function memory(string $conversationId, string $actorType, string $actorId): array { return []; }
    public function summary(string $conversationId, string $actorType, string $actorId): array { return []; }
    public function appendVisibleMessage(string $conversationId, string $actorType, string $actorId, string $senderType, string $text, array $renderPayload, array $evidence, string $correlationId): string { if ($this->getOwnedConversation($conversationId, $actorType, $actorId) === null) { throw new RuntimeException('foreign actor'); } $id = $senderType === 'customer' ? 'msg-provider-current-0001' : 'msg-provider-agent-0001'; $this->messages[] = ['message_id' => $id, 'sender_type' => $senderType, 'content' => ['text' => $text, 'evidence' => $evidence], 'render' => $renderPayload, 'language' => $renderPayload['language'] ?? 'en_US', 'direction' => $renderPayload['direction'] ?? 'ltr', 'reply_to_message_id' => null, 'product_references' => [], 'status' => 'delivered_to_server', 'rendering_schema_version' => '1.0.0', 'correlation_id' => $correlationId, 'created_at' => '2026-08-24T18:00:00+00:00']; $this->renders[] = $renderPayload; return $id; }
    public function saveFocus(string $conversationId, string $actorType, string $actorId, ConversationFocus $focus, string $expectedVersion): bool { return false; }
    public function consumePendingQuestion(string $conversationId, string $actorType, string $actorId, string $questionId, string $expectedFocusVersion, int $expectedQuestionVersion, string $customerMessageId, array $validatedBinding): array { if ($this->focusState?->pendingQuestion?->id !== $questionId || $this->consumed) { return ['consumed' => false, 'code' => 'pending_question_conflict', 'binding_id' => null]; } $this->consumed = true; return ['consumed' => true, 'code' => 'pending_question_consumed', 'binding_id' => 'binding-consumed-0001']; }
    public function saveMemory(string $conversationId, string $actorType, string $actorId, array $memory, string $sourceMessageId): bool { return false; }
    /** @return list<array<string, mixed>> */
    public function renders(): array { return $this->renders; }
}

final class TransmissionManifestRepository implements ContextBundleManifestRepository
{
    /** @var array<string, ContextBundleManifest> */
    private array $saved = [];

    public function save(ContextBundleManifest $manifest): bool
    {
        $this->saved[$manifest->bundleId] = $manifest;
        return true;
    }

    public function findOwned(string $bundleId, string $actorType, string $actorId): ?ContextBundleManifest
    {
        $manifest = $this->saved[$bundleId] ?? null;
        return $manifest instanceof ContextBundleManifest
            && $manifest->actorType === $actorType
            && $manifest->actorId === $actorId
                ? $manifest
                : null;
    }
}

final class TransmissionCountingProvider implements ProviderAdapter
{
    public int $calls = 0;
    public function providerKey(): string { return 'test'; }
    public function execute(ProviderRequest $request): ProviderResult { ++$this->calls; return ProviderResult::failure('must_not_run', false); }
}

final class TransmissionSequencedProvider implements ProviderAdapter
{
    /** @var list<ProviderRequest> */
    public array $requests = [];
    public ?string $lastFailure = null;

    public function __construct(private readonly ProviderTransmissionGate $gate) {}
    public function providerKey(): string { return 'test'; }
    public function execute(ProviderRequest $request): ProviderResult
    {
        $decision = $this->gate->decision($request);
        if (!$decision['allowed']) {
            $this->lastFailure = $decision['reason_code'];
            return ProviderResult::failure($decision['reason_code'], false, 'test.manifest.1');
        }
        $this->requests[] = $request;
        $payload = match ($request->phase) {
            ProviderRequest::PHASE_DECISION => self::decisionPayload(),
            ProviderRequest::PHASE_RESPONSE => self::responsePayload(),
            ProviderRequest::PHASE_SEMANTIC_VERIFICATION => self::verificationPayload(),
            default => null,
        };
        return is_array($payload)
            ? ProviderResult::success($payload, [], 'test.manifest.1')
            : ProviderResult::failure('unexpected_phase', false, 'test.manifest.1');
    }

    /** @return array<string, mixed> */
    public static function decisionPayload(): array
    {
        return [
            'schema_version' => '1.0.0',
            'interpretation' => [
                'schema_version' => '1.0.0',
                'language' => 'en',
                'direction' => 'ltr',
                'primary_goal' => ['goal_type' => 'answer', 'description' => 'Answer the current request.', 'confidence' => 1.0],
                'secondary_goals' => [],
                'sales_stage' => 'exploring',
                'requirements' => [],
                'corrections' => [],
                'references' => [],
                'focus_proposal' => ['foreground_journey_id' => null, 'focused_resource_ids' => [], 'pending_question_id' => null, 'reason' => 'No focus change.'],
                'short_reply_binding' => ['state' => 'not_applicable', 'target_question_id' => null, 'target_resource_ids' => [], 'proposed_value' => null, 'confidence' => null, 'requires_server_validation' => true],
                'missing_information' => [],
                'ambiguities' => [],
                'risk' => ['level' => 'none', 'confirmation_class' => 'none', 'reasons' => []],
                'field_confidence' => [],
            ],
            'plan' => [
                'schema_version' => '1.0.0',
                'plan_id' => 'plan_integration_1',
                'steps' => [[
                    'step_id' => 'step_respond', 'order' => 1, 'kind' => 'respond',
                    'tool_name' => null, 'tool_version' => null, 'proposed_arguments' => null,
                    'classification' => 'conversation', 'depends_on' => [],
                    'confirmation_requirement' => 'none', 'on_success' => 'Respond.', 'on_failure' => 'stop',
                ]],
                'stop_conditions' => ['The request is answered.'],
                'fallback' => 'truthful_block',
                'budgets' => ['max_provider_calls' => 3, 'max_tool_calls' => 0, 'max_repair_loops' => 0, 'deadline_ms' => 10000],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function responsePayload(): array
    {
        return [
            'schema_version' => '1.0.0',
            'language' => 'en',
            'direction' => 'ltr',
            'reply' => ['text' => 'I can help with the current request.', 'components' => []],
            'proposed_updates' => ['focus' => null, 'memory' => null, 'summary' => null, 'journey' => null, 'durable_preferences' => []],
            'evidence_requirements' => [],
            'claims' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function verificationPayload(): array
    {
        $checks = array_map(static fn (string $check): array => ['check' => $check, 'status' => 'pass'], [
            'latest_goal_answered', 'subgoals_complete_or_pending', 'commerce_claims_current',
            'policy_claims_approved', 'hard_requirements_preserved', 'tool_results_exact',
            'stale_not_current', 'culture_location_time_format_correct',
            'no_protected_trait_or_manipulation', 'confirmation_and_disclosure_present',
        ]);
        return [
            'schema_version' => '1.0.0',
            'verdict' => 'supported',
            'checks' => $checks,
            'reason_codes' => ['all_material_claims_supported'],
            'unsupported_spans' => [],
        ];
    }
}

final class TransmissionBindingFailureProvider implements ProviderAdapter
{
    public int $calls = 0;
    public function providerKey(): string { return 'test'; }
    public function execute(ProviderRequest $request): ProviderResult
    {
        ++$this->calls;
        if ($this->calls > 1) {
            return ProviderResult::failure('provider_response_failed_after_binding', false, 'test.manifest.1');
        }
        $decision = TransmissionSequencedProvider::decisionPayload();
        $decision['interpretation']['focus_proposal'] = [
            'foreground_journey_id' => null,
            'focused_resource_ids' => ['product-binding-0001'],
            'pending_question_id' => 'pending-question-0001',
            'reason' => 'Bind the exact active question.',
        ];
        $decision['interpretation']['short_reply_binding'] = [
            'state' => 'proposed',
            'target_question_id' => 'pending-question-0001',
            'target_resource_ids' => ['product-binding-0001'],
            'proposed_value' => 'yes',
            'confidence' => 1.0,
            'requires_server_validation' => true,
        ];
        return ProviderResult::success($decision, [], 'test.manifest.1');
    }
}

final class TransmissionAuthority implements AuthoritativeContextProvider
{
    public function runtime(ToolContext $context): array { return ['version' => 'runtime-provider-1', 'utc' => '2026-08-24T18:00:00+00:00', 'local' => '2026-08-24T21:00:00+03:00', 'timezone' => 'Asia/Aden', 'locale' => 'en_US', 'feature_states' => ['ai_context_graph' => 'On']]; }
    public function commerce(ToolContext $context): array { return ['version' => 'woo-unavailable', 'freshness' => 'unknown', 'cart' => ['available' => false]]; }
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

$manifestData = [
    'manifest_version' => 'test.manifest.1',
    'routes' => [
        'default_text_tool_orchestration' => [
            'provider' => 'google_gemini',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/interactions',
            'model_id' => 'gemini-test',
            'status' => 'Ready',
            'store_requests' => false,
            'shopper_transmission_enabled' => true,
            'privacy_policy_published' => true,
            'evaluation_passed' => true,
            'context_manifest_persistence_certified' => true,
            'prohibited_data_filter_certified' => true,
            'provider_result_projection_certified' => true,
            'woocommerce_actor_binding_certified' => true,
            'context_snapshot_consistency_certified' => true,
            'readiness_max_age_seconds' => 86400,
            'release_certified' => true,
            'timeout_seconds' => 25,
            'max_provider_calls' => 3,
            'max_tool_calls' => 8,
            'max_context_bytes' => 65536,
            'max_context_items' => 256,
            'context_bundle_ttl_seconds' => 300,
            'max_request_bytes' => 524288,
            'max_response_bytes' => 524288,
            'context_bundle_schema_version' => '1.1.0',
            'shopper_purpose' => 'shopper_commerce_assistance',
            'allowed_data_classes' => ['internal', 'personal', 'commerce_confidential'],
            'required_capabilities' => [
                'structured_output' => true,
                'function_calling' => true,
                'modalities' => ['text'],
            ],
        ],
    ],
];
$manifestPath = tempnam(sys_get_temp_dir(), 'veyra-manifest-');
if (!is_string($manifestPath)) {
    throw new RuntimeException('Could not create route-manifest fixture.');
}
file_put_contents($manifestPath, "<?php\nreturn " . var_export($manifestData, true) . ";\n");
$manifest = new RouteManifest($manifestPath);
$states = new ProviderReadinessStateStore();
$bundleAttestor = new ContextBundleAttestor(str_repeat('b', 32));
$requestAttestor = new ProviderRequestAttestor(str_repeat('r', 32));
$payloadValidator = new ProviderPayloadValidator();
$veyraTransmissionOptions[ProviderReadinessStateStore::OPTION] = [
    'state' => 'Ready',
    'route_id' => 'default_text_tool_orchestration',
    'route_version' => 'test.manifest.1',
    'checked_at' => gmdate(DATE_ATOM),
    'capabilities' => ['structured_output' => true, 'function_calling' => true, 'text' => true],
    'safe_error_code' => null,
    'release_certified' => true,
];
$veyraTransmissionOptions['veyra_schema_version'] = '1.6.0';
$gate = new ProviderTransmissionGate($manifest, $states, $bundleAttestor, $requestAttestor, $payloadValidator);

$conversationId = 'conversation-provider-0001';
$actorId = 'guest-provider-owner-0001';
$turnMessageId = 'msg-provider-current-0001';
$toolContext = new ToolContext('guest', $actorId, null, 'guest-provider-session-0001', $conversationId, [], ['ai_context_graph' => 'On'], 'en_US', 'provider-correlation-0001');
$policy = new ContextBundlePolicy(
    'default_text_tool_orchestration',
    'test.manifest.1',
    'shopper_commerce_assistance',
    true,
    'runtime_ready',
    ['internal', 'personal', 'commerce_confidential'],
    65536,
    256,
    300
);
$bundle = (new ContextBundleAssembler(
    new TransmissionContextStore($conversationId, $actorId),
    $policy,
    null,
    null,
    $bundleAttestor,
    new TransmissionManifestRepository()
))->assemble(
    $toolContext,
    $turnMessageId,
    ['message_id' => $turnMessageId, 'text' => 'Check the current cart.'],
    [
        'version' => 'runtime-provider-1',
        'utc' => '2026-08-24T18:00:00+00:00',
        'local' => '2026-08-24T21:00:00+03:00',
        'timezone' => 'Asia/Aden',
        'locale' => 'en_US',
        'feature_states' => ['ai_context_graph' => 'On'],
    ],
    [
        'version' => 'cart-provider-1',
        'freshness' => 'current',
        'cart' => [
            'available' => true,
            'hash' => 'cart-provider-1',
            'item_count' => 1,
            'lines' => [[
                'line_id' => 'cart-line-provider-0001',
                'product_id' => 42,
                'variation_id' => 0,
                'name' => 'Integral float quantity',
                'quantity' => 1.0,
            ]],
            'currency' => 'USD',
            'total' => '10.00',
        ],
    ]
);

$request = static function (string $phase = ProviderRequest::PHASE_DECISION, ?array $projection = null) use ($bundle, $requestAttestor, $payloadValidator): ProviderRequest {
    $projection ??= $bundle->forProvider();
    [$contract, $timeout, $schema, $payload] = match ($phase) {
        ProviderRequest::PHASE_RESPONSE => [
            'agent_response_v1', 25, $payloadValidator->responseContractSchema(), [
                'instruction' => ProviderTransmissionGate::RESPONSE_INSTRUCTION,
                'context_bundle' => $projection,
                'validated_decision' => ['schema_version' => '1.0.0'],
                'binding_outcome' => [],
                'step_outcomes' => [],
                'typed_tool_results' => [],
            ],
        ],
        ProviderRequest::PHASE_SEMANTIC_VERIFICATION => [
            'semantic_response_verification_v1', 20, $payloadValidator->semanticVerificationSchema(), [
                'candidate_response' => [],
                'typed_tool_results' => [],
                'binding_outcome' => [],
                'step_outcomes' => [],
                'bounded_context_bundle' => $projection,
            ],
        ],
        default => [
            'agent_decision_v1', 25, $payloadValidator->decisionSchema(), [
                'instruction' => ProviderTransmissionGate::DECISION_INSTRUCTION,
                'context_bundle' => $projection,
                'authorized_tools' => [],
                'server_limits' => ['max_provider_calls' => 3, 'max_tool_calls' => 8],
            ],
        ],
    };
    return $requestAttestor->seal(new ProviderRequest(
        'default_text_tool_orchestration',
        'system',
        [['type' => 'text', 'text' => CanonicalJson::encode($payload)]],
        [],
        $schema,
        $timeout,
        [
            'correlation_id' => 'provider-correlation-0001',
            'conversation_id' => $bundle->conversationId,
            'contract' => $contract,
            'context_bundle_id' => $bundle->id,
            'context_bundle_version' => $bundle->bundleVersion,
            'context_bundle_hash' => $bundle->hash,
        ],
        null,
        [],
        ProviderRequest::TRAFFIC_SHOPPER,
        ProviderRequest::PURPOSE_SHOPPER,
        $bundle,
        $phase
    ));
};

$scenario('decision, response, and semantic phases bind one exact bundle digest', static function () use ($assert, $gate, $request, $bundle): void {
    $hashes = [];
    foreach ([ProviderRequest::PHASE_DECISION, ProviderRequest::PHASE_RESPONSE, ProviderRequest::PHASE_SEMANTIC_VERIFICATION] as $phase) {
        $candidate = $request($phase);
        $decision = $gate->decision($candidate);
        $assert($decision['allowed'], 'Valid shopper phase was denied: ' . $decision['reason_code']);
        $hashes[] = $candidate->contextBundle?->hash;
    }
    $assert(count(array_unique($hashes)) === 1 && $hashes[0] === $bundle->hash, 'Provider phases were not bound to one digest.');
    $assert(str_contains($request()->input[0]['text'], '"quantity":1.0'), 'Integral Woo quantity lost its float wire type.');
});

$scenario('forged, unpersisted, and open shopper envelopes are rejected', static function () use ($assert, $gate, $request, $bundle, $bundleAttestor, $requestAttestor): void {
    $valid = $request();
    $unpersisted = ContextBundle::issue(
        $bundle->forProvider(),
        $bundle->actorType,
        $bundle->actorId,
        $bundleAttestor,
        false
    );
    $unpersistedRequest = $requestAttestor->seal(new ProviderRequest(
        $valid->routeId,
        $valid->systemInstruction,
        $valid->input,
        $valid->tools,
        $valid->responseSchema,
        $valid->timeoutSeconds,
        $valid->metadata,
        null,
        [],
        $valid->trafficClass,
        $valid->purpose,
        $unpersisted,
        $valid->phase
    ));
    $decision = $gate->decision($unpersistedRequest);
    $assert(
        !$decision['allowed'] && $decision['reason_code'] === 'provider_context_manifest_persistence_required',
        'A validly attested but unpersisted Context Bundle reached provider transmission.'
    );

    $forged = ContextBundle::issue(
        $bundle->forProvider(),
        $bundle->actorType,
        $bundle->actorId,
        new ContextBundleAttestor(str_repeat('x', 32))
    );
    $forgedRequest = $requestAttestor->seal(new ProviderRequest(
        $valid->routeId,
        $valid->systemInstruction,
        $valid->input,
        $valid->tools,
        $valid->responseSchema,
        $valid->timeoutSeconds,
        $valid->metadata,
        null,
        [],
        $valid->trafficClass,
        $valid->purpose,
        $forged,
        $valid->phase
    ));
    $decision = $gate->decision($forgedRequest);
    $assert(!$decision['allowed'] && $decision['reason_code'] === 'provider_context_bundle_attestation_invalid', 'A bundle issued outside the actor-owned assembler boundary was accepted.');

    $decoded = json_decode($valid->input[0]['text'], true, 128, JSON_THROW_ON_ERROR);
    $decoded['unmanifested_shopper_data'] = 'must-not-pass';
    $open = $requestAttestor->seal(new ProviderRequest(
        $valid->routeId,
        $valid->systemInstruction,
        [['type' => 'text', 'text' => CanonicalJson::encode($decoded)]],
        $valid->tools,
        $valid->responseSchema,
        $valid->timeoutSeconds,
        $valid->metadata,
        null,
        [],
        $valid->trafficClass,
        $valid->purpose,
        $bundle,
        $valid->phase
    ));
    $decision = $gate->decision($open);
    $assert(!$decision['allowed'] && $decision['reason_code'] === 'provider_shopper_envelope_invalid', 'An extra shopper payload field bypassed the Context Bundle.');

    $alternate = $requestAttestor->seal(new ProviderRequest(
        'future_fallback_route',
        $valid->systemInstruction,
        $valid->input,
        $valid->tools,
        $valid->responseSchema,
        $valid->timeoutSeconds,
        $valid->metadata,
        null,
        [],
        $valid->trafficClass,
        $valid->purpose,
        $bundle,
        $valid->phase
    ));
    $decision = $gate->decision($alternate);
    $assert(!$decision['allowed'] && $decision['reason_code'] === 'provider_route_not_certified', 'An unpublished fallback route inherited the default route certification.');
});

$scenario('response and semantic envelopes reject raw open tool-result objects', static function () use ($assert, $gate, $request, $requestAttestor): void {
    foreach ([ProviderRequest::PHASE_RESPONSE, ProviderRequest::PHASE_SEMANTIC_VERIFICATION] as $phase) {
        $valid = $request($phase);
        $decoded = json_decode($valid->input[0]['text'], true, 128, JSON_THROW_ON_ERROR);
        $decoded['typed_tool_results'] = [[
            'call_id' => 'tool-call-open-0001',
            'tool' => 'cart.get',
            'status' => 'succeeded',
            'data' => ['raw' => 'unprojected'],
            'correlation_id' => 'internal-correlation-must-not-pass',
        ]];
        $open = $requestAttestor->seal(new ProviderRequest(
            $valid->routeId,
            $valid->systemInstruction,
            [['type' => 'text', 'text' => CanonicalJson::encode($decoded)]],
            $valid->tools,
            $valid->responseSchema,
            $valid->timeoutSeconds,
            $valid->metadata,
            null,
            [],
            $valid->trafficClass,
            $valid->purpose,
            $valid->contextBundle,
            $valid->phase
        ));
        $decision = $gate->decision($open);
        $assert(
            !$decision['allowed'] && $decision['reason_code'] === 'provider_shopper_envelope_invalid',
            'Open ToolResult envelope was accepted in phase ' . $phase . '.'
        );
    }
});

$scenario('final provider-specific body is exact and bounded', static function () use ($assert, $gate, $request): void {
    $valid = $request();
    $body = [
        'model' => 'gemini-test',
        'system_instruction' => $valid->systemInstruction,
        'input' => [['type' => 'user_input', 'content' => $valid->input]],
        'store' => false,
        'response_format' => [
            'type' => 'text',
            'mime_type' => 'application/json',
            'schema' => $valid->responseSchema,
        ],
    ];
    $assert($gate->outboundDecision($valid, $body)['allowed'], 'Exact finalized Gemini body was denied.');
    $body['unbound'] = ['customer' => 'secret'];
    $decision = $gate->outboundDecision($valid, $body);
    $assert(!$decision['allowed'] && $decision['reason_code'] === 'provider_outbound_body_mismatch', 'Final body accepted unbound data.');
});

$scenario('prohibited free text is denied before credential or network access', static function () use ($assert, $gate, $request, $requestAttestor, $manifest, $payloadValidator): void {
    global $veyraTransmissionHttpCalls;
    $valid = $request();
    $unsafe = $requestAttestor->seal(new ProviderRequest(
        $valid->routeId,
        'password=never-transmit-this',
        $valid->input,
        $valid->tools,
        $valid->responseSchema,
        $valid->timeoutSeconds,
        $valid->metadata,
        null,
        [],
        $valid->trafficClass,
        $valid->purpose,
        $valid->contextBundle,
        $valid->phase
    ));
    $decision = $gate->decision($unsafe);
    $assert(!$decision['allowed'] && $decision['reason_code'] === 'provider_outbound_prohibited_data_detected', 'Prohibited free text passed the provider-independent boundary.');

    $veyraTransmissionHttpCalls = 0;
    $adapter = new GeminiProviderAdapter($manifest, new CredentialVault(), $payloadValidator, $gate);
    $result = $adapter->execute($unsafe);
    $assert($result->status === 'failed' && $result->code === 'provider_outbound_prohibited_data_detected', 'Adapter did not preserve prohibited-data denial.');
    $assert($veyraTransmissionHttpCalls === 0, 'Prohibited free text reached wp_remote_post.');
});

$scenario('tampered embedded bundle is denied before adapter network activity', static function () use ($assert, $gate, $request, $manifest): void {
    global $veyraTransmissionHttpCalls;
    $tampered = $request()->contextBundle?->forProvider() ?? [];
    $tampered['selected_data']['current_input']['text'] = 'tampered';
    $invalid = $request(ProviderRequest::PHASE_DECISION, $tampered);
    $decision = $gate->decision($invalid);
    $assert(!$decision['allowed'] && $decision['reason_code'] === 'provider_context_bundle_binding_mismatch', 'Tampered bundle was not rejected.');
    $veyraTransmissionHttpCalls = 0;
    $adapter = new GeminiProviderAdapter($manifest, new CredentialVault(), new ProviderPayloadValidator(), $gate);
    $result = $adapter->execute($invalid);
    $assert($result->status === 'failed' && $result->code === 'provider_context_bundle_binding_mismatch', 'Adapter did not preserve transmission denial.');
    $assert($veyraTransmissionHttpCalls === 0, 'Denied bundle reached wp_remote_post.');
});

$scenario('real CommerceAgent success binds one attested bundle through all three phases', static function () use ($assert, $conversationId, $actorId, $policy, $gate, $bundleAttestor, $requestAttestor, $payloadValidator): void {
    $store = new TransmissionContextStore($conversationId, $actorId);
    $provider = new TransmissionSequencedProvider($gate);
    $tools = new ToolRegistry(new ToolInputValidator());
    $agent = new CommerceAgent(
        $provider,
        $payloadValidator,
        $tools,
        $store,
        new ContextBundleAssembler($store, $policy, null, null, $bundleAttestor, new TransmissionManifestRepository()),
        new ConversationStateUpdater($store),
        new TransmissionAuthority(),
        new PromptPolicyCompiler(),
        new ResponseVerifier(),
        new SemanticResponseVerifier($provider, $payloadValidator, $requestAttestor),
        new ServerComponentBuilder(),
        new ShortReplyBindingValidator(),
        new DecisionPlanExecutor($tools),
        3,
        8,
        $requestAttestor
    );
    $turnContext = new ToolContext('guest', $actorId, null, 'guest-provider-session-0001', $conversationId, [], ['ai_context_graph' => 'On'], 'en_US', 'provider-integration-correlation-0001');
    $result = $agent->handle(new AgentTurnInput($turnContext, 'Check the current cart.', null, [], [], null));
    $assert($result->status === 'succeeded' && $result->code === 'turn_completed', 'Real CommerceAgent path did not complete: ' . $result->status . '/' . $result->code . '/' . ($provider->lastFailure ?? 'none') . '.');
    $assert(array_map(static fn (ProviderRequest $captured): string => $captured->phase, $provider->requests) === [
        ProviderRequest::PHASE_DECISION,
        ProviderRequest::PHASE_RESPONSE,
        ProviderRequest::PHASE_SEMANTIC_VERIFICATION,
    ], 'Real CommerceAgent did not execute the exact three phases.');
    $hashes = [];
    $objects = [];
    $wire = [];
    foreach ($provider->requests as $captured) {
        $hashes[] = $captured->contextBundle?->hash;
        $objects[] = $captured->contextBundle === null ? 0 : spl_object_id($captured->contextBundle);
        $decoded = json_decode($captured->input[0]['text'], true, 128, JSON_THROW_ON_ERROR);
        $wire[] = $decoded['context_bundle'] ?? $decoded['bounded_context_bundle'] ?? null;
    }
    $assert(count(array_unique($hashes)) === 1 && count(array_unique($objects)) === 1, 'Real phases did not reuse one exact bundle object and digest.');
    $assert($wire[0] === $wire[1] && $wire[1] === $wire[2], 'Real phases changed the serialized bundle projection.');
});

$scenario('post-consumption provider failure is reported as a completed state mutation', static function () use ($assert, $conversationId, $actorId, $policy, $bundleAttestor, $requestAttestor, $payloadValidator): void {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $question = new PendingQuestion(
        'pending-question-0001',
        'journey-binding-0001',
        'answer-choice',
        'question-message-0001',
        ['type' => 'string', 'enum' => ['yes', 'no']],
        ['yes', 'no'],
        ['product' => 'product-binding-0001'],
        'informational',
        $now->modify('-1 minute'),
        $now->modify('+10 minutes'),
        ['runtime_version' => 'runtime-provider-1'],
        null,
        null,
        1
    );
    $focus = new ConversationFocus(
        '1',
        'journey-binding-0001',
        ['product' => 'product-binding-0001'],
        $question,
        [],
        'question-message-0001',
        $now
    );
    $store = new TransmissionContextStore($conversationId, $actorId, 'yes', $focus);
    $provider = new TransmissionBindingFailureProvider();
    $tools = new ToolRegistry(new ToolInputValidator());
    $agent = new CommerceAgent(
        $provider,
        $payloadValidator,
        $tools,
        $store,
        new ContextBundleAssembler($store, $policy, null, null, $bundleAttestor, new TransmissionManifestRepository()),
        new ConversationStateUpdater($store),
        new TransmissionAuthority(),
        new PromptPolicyCompiler(),
        new ResponseVerifier(),
        new SemanticResponseVerifier($provider, $payloadValidator, $requestAttestor),
        new ServerComponentBuilder(),
        new ShortReplyBindingValidator(),
        new DecisionPlanExecutor($tools),
        3,
        8,
        $requestAttestor
    );
    $turnContext = new ToolContext('guest', $actorId, null, 'guest-provider-session-0001', $conversationId, [], ['ai_context_graph' => 'On'], 'en_US', 'provider-binding-correlation-0001');
    $result = $agent->handle(new AgentTurnInput($turnContext, 'yes', null, [], [], null));
    $assert($store->consumed, 'Pending Question was not consumed in the fixture; turn code: ' . $result->code);
    $assert($result->status === 'partial' && $result->code === 'turn_partial_after_mutation', 'Post-consumption failure was mislabeled as no action.');
    $assert(!str_contains($result->visibleText, 'No action was executed'), 'Shopper was told no action occurred after Pending Question consumption.');
});

$scenario('runtime release-state revocation is rechecked at send time', static function () use ($assert, $gate, $request): void {
    global $veyraTransmissionOptions;
    $ready = $veyraTransmissionOptions[ProviderReadinessStateStore::OPTION];
    $veyraTransmissionOptions[ProviderReadinessStateStore::OPTION]['state'] = 'Blocked';
    $decision = $gate->decision($request());
    $assert(!$decision['allowed'] && $decision['reason_code'] === 'provider_capability_readiness_required', 'Revoked readiness was not rechecked.');
    $veyraTransmissionOptions[ProviderReadinessStateStore::OPTION] = $ready;
});

$scenario('stale readiness, missing capabilities, and unknown schema fail closed', static function () use ($assert, $gate, $request): void {
    global $veyraTransmissionOptions;
    $ready = $veyraTransmissionOptions[ProviderReadinessStateStore::OPTION];
    $veyraTransmissionOptions[ProviderReadinessStateStore::OPTION]['checked_at'] = '2000-01-01T00:00:00+00:00';
    $decision = $gate->decision($request());
    $assert(!$decision['allowed'] && $decision['reason_code'] === 'provider_readiness_stale', 'Expired readiness evidence authorized shopper traffic.');

    $veyraTransmissionOptions[ProviderReadinessStateStore::OPTION] = $ready;
    unset($veyraTransmissionOptions[ProviderReadinessStateStore::OPTION]['capabilities']['text']);
    $decision = $gate->decision($request());
    $assert(!$decision['allowed'] && $decision['reason_code'] === 'provider_required_capability_missing', 'Missing required text capability authorized shopper traffic.');

    $veyraTransmissionOptions[ProviderReadinessStateStore::OPTION] = $ready;
    $veyraTransmissionOptions['veyra_schema_version'] = '1.7.0';
    $decision = $gate->decision($request());
    $assert(!$decision['allowed'] && $decision['reason_code'] === 'schema_migration_required', 'Unknown newer schema authorized older runtime code.');
    $veyraTransmissionOptions['veyra_schema_version'] = '1.6.0';
});

$scenario('readiness traffic cannot carry shopper context', static function () use ($assert, $gate, $bundle, $requestAttestor, $payloadValidator): void {
    $leaking = $requestAttestor->seal(new ProviderRequest(
        'default_text_tool_orchestration',
        'probe',
        [['type' => 'text', 'text' => json_encode(['actor_scope' => $bundle->forProvider()['actor_scope']], JSON_THROW_ON_ERROR)]],
        [],
        ['type' => 'object'],
        20,
        ['purpose' => ProviderRequest::PURPOSE_READINESS],
        null,
        [],
        ProviderRequest::TRAFFIC_READINESS,
        ProviderRequest::PURPOSE_READINESS,
        null,
        ProviderRequest::PHASE_READINESS
    ));
    $decision = $gate->decision($leaking);
    $assert(!$decision['allowed'] && $decision['reason_code'] === 'provider_readiness_payload_not_isolated', 'Readiness probe accepted shopper context.');

    $clean = $requestAttestor->seal(new ProviderRequest(
        'default_text_tool_orchestration',
        \Veyra\AI\Provider\ProviderReadinessService::READINESS_SYSTEM_INSTRUCTION,
        [['type' => 'text', 'text' => 'Capability probe nonce: abcdef0123456789']],
        [[
            'name' => 'diagnostics.probe',
            'version' => '1.0.0',
            'description' => \Veyra\AI\Provider\ProviderReadinessService::READINESS_TOOL_DESCRIPTION,
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['nonce'],
                'properties' => ['nonce' => ['type' => 'string', 'enum' => ['abcdef0123456789']]],
            ],
        ]],
        $payloadValidator->readinessSchema(),
        20,
        ['purpose' => ProviderRequest::PURPOSE_READINESS],
        null,
        [],
        ProviderRequest::TRAFFIC_READINESS,
        ProviderRequest::PURPOSE_READINESS,
        null,
        ProviderRequest::PHASE_READINESS
    ));
    $assert($gate->decision($clean)['allowed'], 'Isolated readiness probe was incorrectly denied.');
});

$scenario('orchestration denial makes zero provider calls and persists correlation only', static function () use ($assert, $conversationId, $actorId): void {
    $store = new TransmissionContextStore($conversationId, $actorId);
    $provider = new TransmissionCountingProvider();
    $validator = new ProviderPayloadValidator();
    $tools = new ToolRegistry(new ToolInputValidator());
    $deniedPolicy = new ContextBundlePolicy(
        'default_text_tool_orchestration',
        'test.manifest.1',
        'shopper_commerce_assistance',
        false,
        'provider_privacy_policy_required',
        ['internal', 'personal', 'commerce_confidential'],
        65536,
        256,
        300
    );
    $agent = new CommerceAgent(
        $provider,
        $validator,
        $tools,
        $store,
        new ContextBundleAssembler($store, $deniedPolicy),
        new ConversationStateUpdater($store),
        new TransmissionAuthority(),
        new PromptPolicyCompiler(),
        new ResponseVerifier(),
        new SemanticResponseVerifier($provider, $validator),
        new ServerComponentBuilder(),
        new ShortReplyBindingValidator(),
        new DecisionPlanExecutor($tools)
    );
    $turnContext = new ToolContext('guest', $actorId, null, 'guest-provider-session-0001', $conversationId, [], ['ai_context_graph' => 'On'], 'en_US', 'provider-correlation-denied-0001');
    $result = $agent->handle(new AgentTurnInput($turnContext, 'Do not transmit this turn.', null, [], [], null));
    $assert($result->status === 'blocked' && $result->code === 'provider_context_transmission_not_authorized', 'Denied orchestration returned the wrong safe outcome.');
    $assert($provider->calls === 0, 'CommerceAgent called its provider after a denied bundle decision.');
    $renders = $store->renders();
    $failure = $renders[count($renders) - 1] ?? null;
    $reference = is_array($failure) ? ($failure['context_bundle'] ?? null) : null;
    $assert(is_array($reference) && ($reference['bundle_hash'] ?? null) !== null, 'Post-assembly failure omitted safe Context Bundle correlation.');
    $assert(!array_key_exists('selected_data', $reference) && !array_key_exists('source_manifest', $reference), 'Failure rendering persisted Context Bundle content.');
});

$scenario('real readiness service preserves provider failures and rejects undeclared shopper calls', static function () use ($assert, $manifest, $gate, $payloadValidator, $states, $requestAttestor, $request): void {
    global $veyraTransmissionOptions, $veyraTransmissionHttpCalls, $veyraTransmissionHttpMode, $veyraTransmissionLastHttpBody;
    $savedReadiness = $veyraTransmissionOptions[ProviderReadinessStateStore::OPTION];
    $vault = new CredentialVault();
    $vault->storeGeminiCredential('readiness-test-key');
    $adapter = new GeminiProviderAdapter($manifest, $vault, $payloadValidator, $gate);
    $service = new ProviderReadinessService($adapter, $payloadValidator, $manifest, $states, $requestAttestor);

    $veyraTransmissionHttpMode = 'readiness_success';
    $veyraTransmissionHttpCalls = 0;
    $ready = $service->runExplicitTest();
    $assert(($ready['state'] ?? null) === 'Ready' && $veyraTransmissionHttpCalls === 1, 'Exact readiness interaction did not produce Ready.');
    $body = $veyraTransmissionLastHttpBody;
    $assert(is_array($body) && array_keys($body) === ['model', 'system_instruction', 'input', 'store', 'response_format', 'tools'], 'Readiness adapter emitted an open or reordered final body.');
    $assert(!str_contains(CanonicalJson::encode($body), 'context_bundle'), 'Readiness final body carried shopper context.');

    $veyraTransmissionHttpMode = 'readiness_extra_call';
    $blocked = $service->runExplicitTest();
    $assert(($blocked['state'] ?? null) === 'Blocked'
        && ($blocked['safe_error_code'] ?? null) === 'provider_contract_error', 'Readiness accepted or obscured an extra provider tool call.');

    $veyraTransmissionHttpMode = 'error';
    $unavailable = $service->runExplicitTest();
    $assert(($unavailable['state'] ?? null) === 'Blocked'
        && ($unavailable['safe_error_code'] ?? null) === 'provider_unavailable'
        && ($unavailable['capabilities']['credentials'] ?? true) === false, 'Readiness obscured a provider transport failure or claimed credentials were proven.');

    $veyraTransmissionOptions[ProviderReadinessStateStore::OPTION] = $savedReadiness;
    $veyraTransmissionHttpMode = 'shopper_unexpected_call';
    $unexpected = $adapter->execute($request());
    $assert($unexpected->status === 'failed'
        && $unexpected->code === 'provider_unexpected_tool_call', 'Undeclared native shopper tool call escaped the Gemini boundary.');

    $veyraTransmissionHttpMode = 'error';
    $veyraTransmissionOptions[ProviderReadinessStateStore::OPTION] = $savedReadiness;
});

@unlink($manifestPath);
fwrite(STDOUT, sprintf("Provider transmission-boundary scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
