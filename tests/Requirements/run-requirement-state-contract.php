<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Provider\ProviderProhibitedDataRedactor;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\AI\Tool\ToolResultValidator;
use Veyra\AI\Tool\UniversalToolGovernance;
use Veyra\Conversation\Application\ConversationStore;
use Veyra\Conversation\Application\ContextBundleAssembler;
use Veyra\Conversation\Domain\ContextBundlePolicy;
use Veyra\Conversation\Domain\ContextBundleAttestor;
use Veyra\Conversation\Domain\ConversationFocus;
use Veyra\Requirements\Application\RequirementStateService;
use Veyra\Requirements\Domain\RequirementCriterion;
use Veyra\Requirements\Domain\RequirementState;
use Veyra\Requirements\Tool\RequirementsToolHandler;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Support\FrozenClock;
use Veyra\Tests\Support\InMemoryRequirementStateRepository;

/** Exact actor-owned conversation fixture; it never falls back across scope. */
final class RequirementConversationFixture implements ConversationStore
{
    /** @var array<string, array<string, mixed>> */
    private array $conversations = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $messages = [];

    /** @var array<string, array<string, mixed>> */
    private array $memories = [];

    /**
     * @param array<string, string> $customerMessages
     * @param array<string, mixed>  $memory
     */
    public function add(
        string $conversationId,
        string $actorType,
        string $actorId,
        array $customerMessages = [],
        array $memory = []
    ): void {
        $key = $this->key($conversationId, $actorType, $actorId);
        $this->conversations[$key] = [
            'conversation_id' => $conversationId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
        ];
        $this->memories[$key] = $memory;
        foreach ($customerMessages as $messageId => $text) {
            $this->messages[$key][$messageId] = [
                'message_id' => $messageId,
                'sender_type' => 'customer',
                'content' => ['text' => $text],
            ];
        }
    }

    /** @return array<string, mixed> */
    public function memorySnapshot(string $conversationId, string $actorType, string $actorId): array
    {
        return $this->memories[$this->key($conversationId, $actorType, $actorId)] ?? [];
    }

    public function createConversation(string $actorType, string $actorId, ?int $userId, ?string $guestSessionId): string
    {
        throw new LogicException('Not used by requirement-state contract scenarios.');
    }

    public function currentOwnedConversation(string $actorType, string $actorId): ?array
    {
        foreach ($this->conversations as $conversation) {
            if (($conversation['actor_type'] ?? null) === $actorType
                && ($conversation['actor_id'] ?? null) === $actorId
            ) {
                return $conversation;
            }
        }

        return null;
    }

    public function getOwnedConversation(string $conversationId, string $actorType, string $actorId): ?array
    {
        return $this->conversations[$this->key($conversationId, $actorType, $actorId)] ?? null;
    }

    public function recentVisibleMessages(string $conversationId, string $actorType, string $actorId, int $limit): array
    {
        $messages = array_values($this->messages[$this->key($conversationId, $actorType, $actorId)] ?? []);

        return array_slice($messages, -max(0, $limit));
    }

    public function visibleMessage(
        string $conversationId,
        string $actorType,
        string $actorId,
        string $messageId
    ): ?array {
        return $this->messages[$this->key($conversationId, $actorType, $actorId)][$messageId] ?? null;
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
        return $this->memorySnapshot($conversationId, $actorType, $actorId);
    }

    public function summary(string $conversationId, string $actorType, string $actorId): array
    {
        return [];
    }

    public function appendVisibleMessage(
        string $conversationId,
        string $actorType,
        string $actorId,
        string $senderType,
        string $text,
        array $renderPayload,
        array $evidence,
        string $correlationId
    ): string {
        throw new LogicException('Not used by requirement-state contract scenarios.');
    }

    public function saveFocus(
        string $conversationId,
        string $actorType,
        string $actorId,
        ConversationFocus $focus,
        string $expectedVersion
    ): bool {
        return false;
    }

    public function consumePendingQuestion(
        string $conversationId,
        string $actorType,
        string $actorId,
        string $questionId,
        string $expectedFocusVersion,
        int $expectedQuestionVersion,
        string $customerMessageId,
        array $validatedBinding
    ): array {
        return ['consumed' => false, 'code' => 'pending_question_unavailable', 'binding_id' => null];
    }

    public function saveMemory(
        string $conversationId,
        string $actorType,
        string $actorId,
        array $memory,
        string $sourceMessageId
    ): bool {
        return false;
    }

    private function key(string $conversationId, string $actorType, string $actorId): string
    {
        return hash('sha256', $actorType . "\0" . $actorId . "\0" . $conversationId);
    }
}

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        fwrite(STDOUT, "PASS: {$label}\n");
        return;
    }
    ++$failed;
    fwrite(STDERR, "FAIL: {$label}\n");
};

$conversationId = '11111111-1111-4111-8111-111111111111';
$actorType = 'guest';
$actorId = 'guest_requirement_owner';
$sourceMessageOne = 'msg_' . str_repeat('a', 32);
$sourceMessageTwo = 'msg_' . str_repeat('b', 32);
$syntheticSecret = str_repeat('x', 12);
$messageOne = 'I need a blue jacket under 100 dollars. password=' . $syntheticSecret;
$messageTwo = 'Correction: I need a black jacket instead.';
$clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
$conversations = new RequirementConversationFixture();
$conversations->add(
    $conversationId,
    $actorType,
    $actorId,
    [$sourceMessageOne => $messageOne, $sourceMessageTwo => $messageTwo]
);
$states = new InMemoryRequirementStateRepository();
$service = new RequirementStateService($conversations, $states, $clock);
$handler = new RequirementsToolHandler($service);
$inputValidator = new ToolInputValidator();
$resultValidator = new ToolResultValidator($inputValidator);
$registry = new ToolRegistry($inputValidator, $resultValidator, UniversalToolGovernance::disabled());
$registry->register($handler);

$contextFor = static fn (string $conversation, string $owner): ToolContext => new ToolContext(
    'guest',
    $owner,
    null,
    $owner,
    $conversation,
    [],
    ['commerce_product_assistance' => 'On', 'ai_conversation_memory' => 'On'],
    'en_US',
    'corr_requirements_contract'
);
$context = $contextFor($conversationId, $actorId);

/** @var array<string, ToolDefinition> $definitions */
$definitions = [];
foreach ($handler->definitions() as $definition) {
    $definitions[$definition->name] = $definition;
}
$getDefinition = $definitions['requirements.get'];
$updateDefinition = $definitions['requirements.propose_update'];

$check(
    ($getDefinition->inputSchema['additionalProperties'] ?? null) === false
        && ($getDefinition->outputSchema['additionalProperties'] ?? null) === false,
    'requirements.get has closed input and successful-output schemas'
);
$check(
    ($updateDefinition->inputSchema['additionalProperties'] ?? null) === false
        && ($updateDefinition->outputSchema['additionalProperties'] ?? null) === false
        && in_array('expected_resource_version', $updateDefinition->inputSchema['required'] ?? [], true)
        && in_array('expected_state_hash', $updateDefinition->inputSchema['required'] ?? [], true)
        && !isset($updateDefinition->inputSchema['properties']['expected_version']),
    'propose_update exposes separate integer resource version and state hash contracts'
);
$visibleNames = array_column($registry->modelTools($context), 'name');
$check(
    in_array('requirements.get', $visibleNames, true)
        && !in_array('requirements.propose_update', $visibleNames, true),
    'get remains model-visible while proposals remain server-only'
);

$emptyCall = new ToolCall('requirements_get_empty', 'requirements.get', '1.0.0', []);
$empty = $registry->execute($emptyCall, $context);
$check(
    $empty->status === 'succeeded'
        && $empty->data['resource_version'] === 0
        && $empty->data['state_hash'] === hash('sha256', '[]')
        && $empty->data['version'] === $empty->data['state_hash']
        && $empty->data['requirements'] === []
        && $empty->data['active_requirements'] === [],
    'empty actor-owned state is deterministic and separates version from hash'
);
$check(
    $inputValidator->validate($empty->data, $getDefinition->outputSchema),
    'empty requirements.get payload satisfies its successful-output schema'
);
$unexpectedGet = $empty->data;
$unexpectedGet['unexpected'] = true;
$check(
    !$inputValidator->validate($unexpectedGet, $getDefinition->outputSchema),
    'requirements.get output schema rejects additional top-level fields'
);
$badGet = $registry->execute(
    new ToolCall('requirements_get_extra', 'requirements.get', '1.0.0', ['unexpected' => true]),
    $context
);
$check($badGet->code === 'tool_input_invalid', 'requirements.get rejects additional input fields');

$wrongActor = $registry->execute(
    new ToolCall('requirements_get_wrong_actor', 'requirements.get', '1.0.0', []),
    $contextFor($conversationId, 'guest_other_actor')
);
$check(
    $wrongActor->status === 'failed' && $wrongActor->code === 'conversation_not_owned',
    'conversation ownership uses the exact actor tuple and never conversation id alone'
);

$providerProposal = $registry->execute(
    new ToolCall('requirements_provider_write', 'requirements.propose_update', '1.0.0', []),
    $context
);
$check(
    $providerProposal->status === 'blocked' && $providerProposal->code === 'tool_not_model_visible',
    'provider-facing registry cannot invoke the server-only proposal boundary'
);

$executeServer = static function (array $arguments, string $callId) use (
    $handler,
    $context,
    $updateDefinition,
    $inputValidator,
    $resultValidator,
    $check
): ToolResult {
    $call = new ToolCall($callId, 'requirements.propose_update', '1.0.0', $arguments);
    if (!$inputValidator->validate($arguments, $updateDefinition->inputSchema)) {
        $check(false, "{$callId} has a valid server-owned input envelope");
        return ToolResult::denied($call, 'tool_input_invalid', $context->correlationId);
    }
    $result = $handler->execute($call, $context);
    $check(
        $resultValidator->validate($result, $call, $updateDefinition, $context->correlationId),
        "{$callId} satisfies its result envelope and successful-output schema"
    );

    return $result;
};

$upsertArguments = [
    'expected_resource_version' => $empty->data['resource_version'],
    'expected_state_hash' => $empty->data['state_hash'],
    'source_message_id' => $sourceMessageOne,
    'changes' => [[
        'operation' => 'upsert',
        'field' => 'attribute',
        'operator' => 'equals',
        'value' => ['name' => 'color', 'value' => 'blue', 'password' => $syntheticSecret],
        'strength' => 'hard',
        'status' => 'active',
        'source_excerpt' => 'blue jacket',
    ]],
];
$check(
    !$inputValidator->validate(
        ['expected_version' => $empty->data['state_hash']] + $upsertArguments,
        $updateDefinition->inputSchema
    ),
    'legacy expected_version input is rejected by the closed proposal schema'
);
$firstUpdate = $executeServer($upsertArguments, 'requirements_update_blue');
$firstRecord = $firstUpdate->data['requirements'][0] ?? null;
$check(
    $firstUpdate->status === 'succeeded'
        && $firstUpdate->data['resource_version'] === 1
        && $firstUpdate->data['version'] === $firstUpdate->data['state_hash']
        && is_array($firstRecord)
        && ($firstRecord['status'] ?? null) === 'active',
    'first valid proposal creates active requirement state version one'
);
$expectedOffset = strpos($messageOne, 'blue jacket');
$check(
    is_array($firstRecord)
        && ($firstRecord['source']['message_id'] ?? null) === $sourceMessageOne
        && ($firstRecord['source']['excerpt_sha256'] ?? null) === hash('sha256', 'blue jacket')
        && ($firstRecord['source']['excerpt_offset_bytes'] ?? null) === $expectedOffset
        && ($firstRecord['source']['excerpt_length_bytes'] ?? null) === strlen('blue jacket')
        && ($firstRecord['source']['source_kind'] ?? null) === 'customer_visible_message',
    'stored requirement carries exact deterministic shopper-message provenance'
);

$redactionPolicy = new ContextBundlePolicy(
    'default_text_tool_orchestration',
    'requirements.redaction.test.1',
    'shopper_commerce_assistance',
    true,
    'runtime_ready',
    ['internal', 'personal', 'commerce_confidential'],
    65536,
    256,
    300
);
$redactionAttestor = new ContextBundleAttestor();
$redactedBundle = (new ContextBundleAssembler(
    $conversations,
    $redactionPolicy,
    $service,
    null,
    $redactionAttestor,
    null,
    new ProviderProhibitedDataRedactor()
))->assemble(
    $context,
    $sourceMessageOne,
    ['message_id' => $sourceMessageOne, 'text' => $messageOne],
    [
        'version' => 'runtime-redaction-1',
        'utc' => '2026-08-24T10:00:00+00:00',
        'local' => '2026-08-24T10:00:00+00:00',
        'timezone' => 'UTC',
        'locale' => 'en_US',
        'feature_states' => ['ai_conversation_memory' => 'On'],
    ],
    ['version' => 'commerce-redaction-1', 'freshness' => 'unknown', 'cart' => ['available' => false]]
);
$redactedProjection = $redactedBundle->forProvider();
$redactedEncoding = json_encode($redactedProjection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$redactedRequirement = $redactedProjection['selected_data']['requirement_state']['active_requirements'][0] ?? null;
$check(
    is_array($firstRecord)
        && (($firstRecord['value']['password'] ?? null) === $syntheticSecret)
        && is_array($redactedRequirement)
        && (($redactedRequirement['value']['password'] ?? null) === '[REDACTED:authentication_secret]')
        && !str_contains($redactedEncoding, $syntheticSecret)
        && in_array('provider_redaction:authentication_secret', $redactedProjection['privacy']['redactions_applied'] ?? [], true)
        && $redactionAttestor->verify($redactedBundle),
    'raw requirement state stays unchanged while the exact attested provider bundle redacts prohibited values'
);

$active = $registry->execute(
    new ToolCall('requirements_get_active', 'requirements.get', '1.0.0', []),
    $context
);
$check(
    $active->status === 'succeeded'
        && count($active->data['requirements']) === 1
        && count($active->data['active_requirements']) === 1
        && $active->data['active_requirements'][0] === $active->data['requirements'][0],
    'requirements.get returns complete history and its active projection'
);
$nestedExtra = $active->data;
$nestedExtra['active_requirements'][0]['unexpected'] = true;
$check(
    !$inputValidator->validate($nestedExtra, $getDefinition->outputSchema),
    'active requirement items reject additional fields'
);
$sourceExtra = $active->data;
$sourceExtra['requirements'][0]['source']['unexpected'] = true;
$check(
    !$inputValidator->validate($sourceExtra, $getDefinition->outputSchema),
    'requirement provenance objects reject additional fields'
);
$inactiveProjection = $active->data;
$inactiveProjection['active_requirements'][0]['status'] = 'superseded';
$check(
    !$inputValidator->validate($inactiveProjection, $getDefinition->outputSchema),
    'active projection schema rejects non-active criteria'
);

$clock->advance(1);
$firstRequirementId = (string) $firstRecord['id'];
$correctionArguments = [
    'expected_resource_version' => $firstUpdate->data['resource_version'],
    'expected_state_hash' => $firstUpdate->data['state_hash'],
    'source_message_id' => $sourceMessageTwo,
    'changes' => [[
        'operation' => 'correct',
        'target_requirement_id' => $firstRequirementId,
        'field' => 'attribute',
        'operator' => 'equals',
        'value' => ['name' => 'color', 'value' => 'black'],
        'strength' => 'hard',
        'status' => 'active',
        'source_excerpt' => 'black jacket',
    ]],
];
$correction = $executeServer($correctionArguments, 'requirements_correct_black');
$correctedRecords = $correction->data['requirements'] ?? [];
$oldRecord = $correctedRecords[0] ?? [];
$newRecord = $correctedRecords[1] ?? [];
$check(
    $correction->status === 'succeeded'
        && $correction->data['resource_version'] === 2
        && count($correctedRecords) === 2
        && ($oldRecord['status'] ?? null) === 'superseded'
        && ($oldRecord['superseded_by'] ?? null) === ($newRecord['id'] ?? null)
        && ($oldRecord['status_source_message_id'] ?? null) === $sourceMessageTwo
        && ($newRecord['status'] ?? null) === 'active'
        && in_array($firstRequirementId, $newRecord['supersedes'] ?? [], true),
    'correction preserves history and deterministically links supersession in both directions'
);
$check(
    ($newRecord['source']['message_id'] ?? null) === $sourceMessageTwo
        && ($newRecord['source']['excerpt_sha256'] ?? null) === hash('sha256', 'black jacket')
        && ($newRecord['source']['excerpt_offset_bytes'] ?? null) === strpos($messageTwo, 'black jacket'),
    'corrected requirement is grounded in its exact correction message'
);
$unexpectedUpdateField = $correction->data;
$unexpectedUpdateField['active_requirements'] = [];
$check(
    !$inputValidator->validate($unexpectedUpdateField, $updateDefinition->outputSchema),
    'proposal output schema rejects fields the service does not emit'
);

$writesBeforeStaleAttempts = $states->successfulCompareAndSwaps();
$staleResource = $executeServer(
    $correctionArguments,
    'requirements_stale_resource_version'
);
$staleHashArguments = $correctionArguments;
$staleHashArguments['expected_resource_version'] = $correction->data['resource_version'];
$staleHashArguments['expected_state_hash'] = $firstUpdate->data['state_hash'];
$staleHash = $executeServer($staleHashArguments, 'requirements_stale_state_hash');
$check(
    $staleResource->status === 'failed'
        && $staleResource->code === 'requirements_version_conflict'
        && $staleHash->status === 'failed'
        && $staleHash->code === 'requirements_version_conflict'
        && $states->successfulCompareAndSwaps() === $writesBeforeStaleAttempts,
    'stale resource versions and stale hashes both deny without attempting a write'
);
$check(
    $service->isCurrent(
        $conversationId,
        $actorType,
        $actorId,
        $correction->data['resource_version'],
        $correction->data['state_hash']
    )
        && !$service->isCurrent(
            $conversationId,
            $actorType,
            $actorId,
            $firstUpdate->data['resource_version'],
            $firstUpdate->data['state_hash']
        ),
    'isCurrent requires both the exact actor-owned resource version and state hash'
);

$invalidConversation = '22222222-2222-4222-8222-222222222222';
$conversations->add($invalidConversation, $actorType, $actorId);
$states->corrupt($invalidConversation, $actorType, $actorId);
$invalidStored = $registry->execute(
    new ToolCall('requirements_get_corrupt', 'requirements.get', '1.0.0', []),
    $contextFor($invalidConversation, $actorId)
);
$check(
    $invalidStored->status === 'failed' && $invalidStored->code === 'requirements_state_invalid',
    'invalid stored aggregate fails closed without exposing repository details'
);

$legacyConversation = '33333333-3333-4333-8333-333333333333';
$legacyMemory = ['requirements' => [$firstRecord], 'unrelated' => ['preserve' => true]];
$conversations->add(
    $legacyConversation,
    $actorType,
    $actorId,
    [$sourceMessageOne => $messageOne],
    $legacyMemory
);
$legacyBefore = $conversations->memorySnapshot($legacyConversation, $actorType, $actorId);
$legacyImport = $registry->execute(
    new ToolCall('requirements_get_legacy', 'requirements.get', '1.0.0', []),
    $contextFor($legacyConversation, $actorId)
);
$legacyHead = $states->loadOwned($legacyConversation, $actorType, $actorId);
$check(
    $legacyImport->status === 'succeeded'
        && $legacyImport->data['resource_version'] === 1
        && count($legacyImport->data['requirements']) === 1
        && ($legacyImport->data['requirements'][0]['status'] ?? null) === 'proposed'
        && $legacyImport->data['active_requirements'] === []
        && $legacyHead instanceof RequirementState
        && $legacyHead->criteriaArray() === $legacyImport->data['requirements']
        && $conversations->memorySnapshot($legacyConversation, $actorType, $actorId) === $legacyBefore,
    'bounded valid actor-owned legacy history CAS-imports as quarantined v1 without rewriting memory'
);

$bundlePolicy = new ContextBundlePolicy(
    'default_text_tool_orchestration',
    'requirements.test.1',
    'shopper_commerce_assistance',
    true,
    'runtime_ready',
    ['internal', 'personal', 'commerce_confidential'],
    65536,
    256,
    300
);
$providerBundle = (new ContextBundleAssembler($conversations, $bundlePolicy, $service))->assemble(
    $contextFor($legacyConversation, $actorId),
    $sourceMessageOne,
    ['message_id' => $sourceMessageOne, 'text' => $messageOne],
    [
        'version' => 'runtime-1',
        'utc' => '2026-08-24T10:00:00+00:00',
        'local' => '2026-08-24T10:00:00+00:00',
        'timezone' => 'UTC',
        'locale' => 'en_US',
        'feature_states' => ['ai_conversation_memory' => 'On'],
    ],
    ['version' => 'commerce-1', 'freshness' => 'unknown', 'cart' => ['available' => false]]
)->forProvider();
$bundleRequirementState = $providerBundle['selected_data']['requirement_state'] ?? null;
$bundleMemory = $providerBundle['selected_data']['conversation_memory'] ?? null;
$requirementSources = array_values(array_filter(
    is_array($providerBundle['source_manifest'] ?? null) ? $providerBundle['source_manifest'] : [],
    static fn (mixed $source): bool => is_array($source) && ($source['section'] ?? null) === 'requirement_state'
));
$check(
    is_array($bundleRequirementState)
        && ($bundleRequirementState['resource_version'] ?? null) === $legacyImport->data['resource_version']
        && ($bundleRequirementState['state_hash'] ?? null) === $legacyImport->data['state_hash']
        && ($bundleRequirementState['active_requirements'] ?? null) === []
        && ($bundleRequirementState['durable_preference_memory_used'] ?? null) === false
        && $bundleMemory === []
        && count($requirementSources) === 1
        && ($requirementSources[0]['version'] ?? null) === $legacyImport->data['resource_version']
        && ($requirementSources[0]['actor_scope_validated'] ?? null) === true,
    'provider context carries one exact actor-owned requirement head and excludes the unvalidated legacy memory blob'
);

$foreignLegacyConversation = '88888888-8888-4888-8888-888888888888';
$conversations->add(
    $foreignLegacyConversation,
    $actorType,
    $actorId,
    [],
    ['requirements' => [$firstRecord]]
);
$foreignLegacy = $registry->execute(
    new ToolCall('requirements_get_foreign_legacy', 'requirements.get', '1.0.0', []),
    $contextFor($foreignLegacyConversation, $actorId)
);
$tamperedLegacyConversation = '99999999-9999-4999-8999-999999999999';
$tamperedRecord = $firstRecord;
$tamperedRecord['source']['excerpt_offset_bytes'] = 0;
$conversations->add(
    $tamperedLegacyConversation,
    $actorType,
    $actorId,
    [$sourceMessageOne => $messageOne],
    ['requirements' => [$tamperedRecord]]
);
$tamperedLegacy = $registry->execute(
    new ToolCall('requirements_get_tampered_legacy', 'requirements.get', '1.0.0', []),
    $contextFor($tamperedLegacyConversation, $actorId)
);
$check(
    $foreignLegacy->code === 'requirements_state_invalid'
        && $tamperedLegacy->code === 'requirements_state_invalid'
        && $states->loadOwned($foreignLegacyConversation, $actorType, $actorId) === null
        && $states->loadOwned($tamperedLegacyConversation, $actorType, $actorId) === null,
    'legacy import rejects cross-conversation and tampered exact-excerpt provenance'
);

$legacyRaceConversation = '44444444-4444-4444-8444-444444444444';
$conversations->add(
    $legacyRaceConversation,
    $actorType,
    $actorId,
    [$sourceMessageOne => $messageOne],
    ['requirements' => [$firstRecord]]
);
$raceNow = $clock->now()->toIso8601();
$raceWinnerCriterion = RequirementCriterion::proposed(
    'category',
    'equals',
    'coats',
    'soft',
    'active',
    [
        'message_id' => $sourceMessageTwo,
        'excerpt_sha256' => hash('sha256', 'coats'),
        'excerpt_offset_bytes' => 0,
        'excerpt_length_bytes' => strlen('coats'),
        'source_kind' => 'customer_visible_message',
    ],
    [],
    $raceNow
);
$raceWinner = RequirementState::empty($legacyRaceConversation, $actorType, $actorId, $raceNow)
    ->next([$raceWinnerCriterion], $sourceMessageTwo, $raceNow);
$states->loseNextEmptyInsertTo($raceWinner);
$legacyRace = $registry->execute(
    new ToolCall('requirements_get_legacy_race', 'requirements.get', '1.0.0', []),
    $contextFor($legacyRaceConversation, $actorId)
);
$check(
    $legacyRace->status === 'succeeded'
        && $legacyRace->data['resource_version'] === 1
        && $legacyRace->data['state_hash'] === $raceWinner->stateHash
        && $legacyRace->data['requirements'] === $raceWinner->criteriaArray(),
    'legacy import losing the unique insert race reloads the exact actor-owned winner'
);

$malformedLegacyConversation = '55555555-5555-4555-8555-555555555555';
$conversations->add(
    $malformedLegacyConversation,
    $actorType,
    $actorId,
    [],
    ['requirements' => [['invalid' => true]]]
);
$malformedLegacy = $registry->execute(
    new ToolCall('requirements_get_bad_legacy', 'requirements.get', '1.0.0', []),
    $contextFor($malformedLegacyConversation, $actorId)
);
$oversizedLegacyConversation = '66666666-6666-4666-8666-666666666666';
$conversations->add(
    $oversizedLegacyConversation,
    $actorType,
    $actorId,
    [],
    ['requirements' => array_fill(0, 65, $firstRecord)]
);
$oversizedLegacy = $registry->execute(
    new ToolCall('requirements_get_large_legacy', 'requirements.get', '1.0.0', []),
    $contextFor($oversizedLegacyConversation, $actorId)
);
$check(
    $malformedLegacy->code === 'requirements_state_invalid'
        && $oversizedLegacy->code === 'requirements_state_invalid'
        && $states->loadOwned($malformedLegacyConversation, $actorType, $actorId) === null
        && $states->loadOwned($oversizedLegacyConversation, $actorType, $actorId) === null,
    'malformed and oversized legacy histories fail closed without creating a head'
);

$raceRepository = new InMemoryRequirementStateRepository();
$twoWriterConversation = '77777777-7777-4777-8777-777777777777';
$expectedHead = RequirementState::empty($twoWriterConversation, $actorType, $actorId, $raceNow);
$raceSource = [
    'message_id' => $sourceMessageOne,
    'excerpt_sha256' => hash('sha256', 'blue'),
    'excerpt_offset_bytes' => 0,
    'excerpt_length_bytes' => 4,
    'source_kind' => 'customer_visible_message',
];
$candidateOne = $expectedHead->next([
    RequirementCriterion::proposed('category', 'equals', 'jackets', 'hard', 'active', $raceSource, [], $raceNow),
], $sourceMessageOne, $raceNow);
$candidateTwo = $expectedHead->next([
    RequirementCriterion::proposed('category', 'equals', 'coats', 'hard', 'active', $raceSource, [], $raceNow),
], $sourceMessageOne, $raceNow);
$writerResults = [
    $raceRepository->compareAndSwap($expectedHead, $candidateOne),
    $raceRepository->compareAndSwap($expectedHead, $candidateTwo),
];
$winner = $raceRepository->loadOwned($twoWriterConversation, $actorType, $actorId);
$check(
    count(array_filter($writerResults, static fn (bool $result): bool => $result)) === 1
        && $writerResults === [true, false]
        && $raceRepository->successfulCompareAndSwaps() === 1
        && $winner instanceof RequirementState
        && $winner->stateHash === $candidateOne->stateHash,
    'two writers with the same expected head produce exactly one CAS winner'
);

$historyDropRejected = false;
try {
    $candidateOne->next([], $sourceMessageOne, $raceNow);
} catch (InvalidArgumentException) {
    $historyDropRejected = true;
}
$check(
    $historyDropRejected,
    'a successor head cannot drop or rewrite the complete requirement history'
);

fwrite(STDOUT, "Requirement-state contract scenarios: {$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
