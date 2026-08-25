<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

if (!function_exists('wp_timezone')) {
    function wp_timezone(): DateTimeZone
    {
        return new DateTimeZone('Asia/Aden');
    }
}

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Orchestration\DecisionPlanExecutor;
use Veyra\AI\Provider\ProviderPayloadValidator;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\AI\Tool\UniversalToolGovernance;
use Veyra\Context\Tool\ContextToolHandler;
use Veyra\Tests\Support\MemoryConversationStore;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if (!$condition) {
        ++$failed;
        fwrite(STDERR, "FAIL {$message}\n");
        return;
    }
    ++$passed;
    fwrite(STDOUT, "PASS {$message}\n");
};

$validator = new ProviderPayloadValidator();
$decision = [
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
        'plan_id' => 'plan_1',
        'steps' => [[
            'step_id' => 'step_1', 'order' => 1, 'kind' => 'respond',
            'tool_name' => null, 'tool_version' => null, 'proposed_arguments' => null,
            'classification' => 'conversation', 'depends_on' => [],
            'confirmation_requirement' => 'none', 'on_success' => 'Respond.', 'on_failure' => 'stop',
        ]],
        'stop_conditions' => ['The request is answered.'],
        'fallback' => 'truthful_block',
        'budgets' => ['max_provider_calls' => 2, 'max_tool_calls' => 0, 'max_repair_loops' => 0, 'deadline_ms' => 10000],
    ],
];
$check($validator->validateDecisionPayload($decision) !== null, 'strict interpretation/plan envelope accepts an exact valid decision');
$decision['interpretation']['unexpected'] = true;
$check($validator->validateDecisionPayload($decision) === null, 'strict interpretation contract rejects an extra field');

$response = [
    'schema_version' => '1.0.0', 'language' => 'en', 'direction' => 'ltr',
    'reply' => ['text' => 'I need a current authoritative result before making that claim.', 'components' => []],
    'proposed_updates' => ['focus' => null, 'memory' => null, 'summary' => null, 'journey' => null, 'durable_preferences' => []],
    'evidence_requirements' => [], 'claims' => [],
];
$check($validator->validateResponseContractPayload($response) !== null, 'strict response envelope accepts an exact truthful response');
$response['tool_calls'] = [];
$check($validator->validateResponseContractPayload($response) === null, 'response phase rejects plan/tool-call fields');

$call = new ToolCall('call_1', 'test.invalid_read', '1.0.0', []);
$typed = ToolResult::success($call, ['ok' => true], 'corr_1')->toArray();
$check(($typed['schema_version'] ?? null) === '1.0.0' && ($typed['tool_version'] ?? null) === '1.0.0', 'tool results carry schema and tool versions');

$handler = new class implements ToolHandler {
    public function definitions(): array
    {
        return [new ToolDefinition('test.invalid_read', '1.0.0', 'Invalid output fixture.', 'read', [
            'type' => 'object', 'additionalProperties' => false, 'properties' => [],
        ], ['guest'], [], [], true)];
    }
    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        return ToolResult::success($call, [], $context->correlationId, ['cart:changed']);
    }
};
$context = new ToolContext('guest', 'guest_1', null, 'guest_1', 'conversation_1', [], [], 'en_US', 'corr_1');
$registry = new ToolRegistry(new ToolInputValidator());
$registry->register($handler);
$invalidOutput = $registry->execute($call, $context);
$check($invalidOutput->status === 'failed' && $invalidOutput->code === 'tool_output_contract_invalid', 'registry rejects a read result that reports changed resources');

$catalogGovernance = UniversalToolGovernance::fromCatalog(dirname(__DIR__, 2) . '/config/contracts/logical-tool-catalog.json');
$closedOutput = new ReflectionMethod(UniversalToolGovernance::class, 'closedOutputSchema');
$closedOutput->setAccessible(true);
$check($closedOutput->invoke(null, [
    'type' => 'object',
    'additionalProperties' => false,
    'properties' => [
        'items' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['id' => ['type' => 'integer']],
            ],
        ],
    ],
]) === true, 'governance accepts recursively closed output schemas');
$check($closedOutput->invoke(null, [
    'type' => 'object',
    'additionalProperties' => false,
    'properties' => [
        'attributes' => [
            'type' => 'object',
            'additionalProperties' => ['type' => 'array'],
        ],
    ],
]) === false, 'governance rejects nested dynamic provider-visible object keys');
$catalogHandler = new class implements ToolHandler {
    public function definitions(): array
    {
        return [new ToolDefinition('identity.get_current_actor', '1.0.0', 'Identity.', 'read', [
            'type' => 'object', 'additionalProperties' => false, 'properties' => [],
        ], ['guest'], [], [], true)];
    }
    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        return ToolResult::success($call, ['actor' => 'guest'], $context->correlationId);
    }
};
$governed = new ToolRegistry(new ToolInputValidator(), null, $catalogGovernance);
$governed->register($catalogHandler);
$governedContext = new ToolContext('guest', 'guest_1', null, 'guest_1', 'conversation_1', [], ['ai_context_graph' => 'On'], 'en_US', 'corr_1');
$check($governed->modelTools($governedContext) === [], 'uncertified canonical tools are absent from provider discovery');
$blocked = $governed->execute(new ToolCall('call_2', 'identity.get_current_actor', '1.0.0', []), $governedContext);
$check($blocked->status === 'blocked' && $blocked->code === 'tool_contract_not_certified', 'uncertified canonical tools fail closed at execution');

$clockHandler = new ContextToolHandler(new MemoryConversationStore('msg_source', 'source'));
$clockRegistry = new ToolRegistry(new ToolInputValidator(), null, $catalogGovernance);
$clockRegistry->register($clockHandler);
$clockContext = new ToolContext(
    'guest',
    'guest_2',
    null,
    'guest_2',
    'conversation_2',
    [],
    ['ai_time_awareness' => 'On'],
    'en_US',
    'corr_2'
);
$planningNames = array_column($clockRegistry->planningTools($clockContext), 'name');
$check($planningNames === ['context.get_runtime_clock'], 'only the individually certified runtime clock is exposed from the context handler');
$check(
    $clockRegistry->planProfile('context.get_runtime_clock', $clockContext) === ['version' => '1.0.0', 'classification' => 'read'],
    'certified runtime clock has an exact strict-planning profile'
);

$clockCall = new ToolCall('call_clock', 'context.get_runtime_clock', '1.0.0', []);
$beforeClockRead = time();
$clock = $clockRegistry->execute($clockCall, $clockContext);
$afterClockRead = time();
$utc = isset($clock->data['utc']) && is_string($clock->data['utc']) ? new DateTimeImmutable($clock->data['utc']) : null;
$local = isset($clock->data['local']) && is_string($clock->data['local']) ? new DateTimeImmutable($clock->data['local']) : null;
$check(
    $clock->status === 'succeeded'
        && $clock->authoritative
        && ($clock->data['authoritative'] ?? null) === true
        && ($clock->data['timezone'] ?? null) === 'Asia/Aden'
        && is_string($clock->data['local'] ?? null)
        && str_ends_with($clock->data['local'], '+03:00')
        && $utc instanceof DateTimeImmutable
        && $local instanceof DateTimeImmutable
        && $utc->getTimestamp() >= $beforeClockRead
        && $utc->getTimestamp() <= $afterClockRead
        && $utc->getTimestamp() === $local->getTimestamp(),
    'runtime clock returns a current authoritative instant that passes its closed output schema'
);

$clockPlan = [
    'plan' => [
        'plan_id' => 'clock_plan',
        'budgets' => ['max_tool_calls' => 1],
        'steps' => [[
            'step_id' => 'clock_step',
            'kind' => 'tool',
            'tool_name' => 'context.get_runtime_clock',
            'tool_version' => '1.0.0',
            'proposed_arguments' => [],
            'classification' => 'read',
            'depends_on' => [],
            'confirmation_requirement' => 'none',
        ]],
    ],
];
$clockExecution = (new DecisionPlanExecutor($clockRegistry))->execute(
    $clockPlan,
    $clockContext,
    'msg_customer',
    true,
    1
);
$check(
    count($clockExecution['tool_results']) === 1
        && $clockExecution['tool_results'][0]->status === 'succeeded',
    'strict plan execution reaches the certified runtime-clock handler'
);

$invalidInput = $clockRegistry->execute(
    new ToolCall('call_clock_input', 'context.get_runtime_clock', '1.0.0', ['timezone' => 'UTC']),
    $clockContext
);
$check($invalidInput->code === 'tool_input_invalid', 'runtime clock rejects every provider-supplied input field');

$featureOff = new ToolContext(
    'guest', 'guest_2', null, 'guest_2', 'conversation_2', [],
    ['ai_time_awareness' => 'Off'], 'en_US', 'corr_2'
);
$featureBlocked = $clockRegistry->execute($clockCall, $featureOff);
$check(
    $clockRegistry->planningTools($featureOff) === []
        && $featureBlocked->code === 'tool_feature_unavailable',
    'runtime clock is absent and denied when ai_time_awareness is off'
);

$staffContext = new ToolContext(
    'administrator', 'wp_user_1', 1, null, 'conversation_2', [],
    ['ai_time_awareness' => 'On'], 'en_US', 'corr_2'
);
$staffBlocked = $clockRegistry->execute($clockCall, $staffContext);
$check(
    $clockRegistry->planningTools($staffContext) === []
        && $staffBlocked->code === 'tool_actor_not_allowed',
    'runtime clock is limited to the catalog-approved guest and customer actors'
);

$clockDefinition = array_values(array_filter(
    $clockHandler->definitions(),
    static fn (ToolDefinition $definition): bool => $definition->name === 'context.get_runtime_clock'
))[0];
$malformedClockHandler = new class($clockDefinition) implements ToolHandler {
    public function __construct(private readonly ToolDefinition $definition)
    {
    }

    public function definitions(): array
    {
        return [$this->definition];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        return ToolResult::success($call, [
            'utc' => 'not-an-instant',
            'timezone' => 'UTC',
            'local' => 'not-an-instant',
            'authoritative' => true,
        ], $context->correlationId);
    }
};
$malformedRegistry = new ToolRegistry(new ToolInputValidator(), null, $catalogGovernance);
$malformedRegistry->register($malformedClockHandler);
$malformed = $malformedRegistry->execute($clockCall, $clockContext);
$check(
    $malformed->status === 'failed' && $malformed->code === 'tool_output_contract_invalid',
    'registry fails closed when certified runtime-clock data violates its typed output schema'
);

fwrite(STDOUT, "AI/context governance scenarios: {$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
