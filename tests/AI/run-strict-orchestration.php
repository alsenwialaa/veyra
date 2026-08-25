<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Orchestration\DecisionPlanExecutor;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\AI\Tool\UniversalToolGovernance;
use Veyra\Conversation\Application\ShortReplyBindingValidator;
use Veyra\Conversation\Domain\ConversationFocus;
use Veyra\Conversation\Domain\PendingQuestion;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        fwrite(STDOUT, "PASS {$message}\n");
        return;
    }
    ++$failed;
    fwrite(STDERR, "FAIL {$message}\n");
};

$now = new DateTimeImmutable('2026-08-24T12:00:00Z');
$question = new PendingQuestion(
    'pq_1',
    'journey_1',
    'variation',
    'msg_question',
    ['type' => 'string', 'enum' => ['black', 'white']],
    ['black', 'white'],
    ['product' => 'product_7'],
    'state_changing',
    $now->modify('-1 minute'),
    $now->modify('+10 minutes'),
    ['runtime' => 'runtime_4'],
    null,
    null,
    3
);
$focus = new ConversationFocus('9', 'journey_1', ['product' => 'product_7'], $question, [], 'msg_question', $now);
$bindingValidator = new ShortReplyBindingValidator();
$validBinding = $bindingValidator->validate($focus, [
    'question_id' => 'pq_1',
    'focus_version' => '9',
    'target_resource_ids' => ['product_7'],
    'value' => 'black',
    'confirmation_id' => null,
], ['runtime' => 'runtime_4'], $now);
$check($validBinding['valid'] && $validBinding['code'] === 'binding_valid', 'server validates an exact AI-proposed Pending Question binding');

$wrongResource = $bindingValidator->validate($focus, [
    'question_id' => 'pq_1',
    'focus_version' => '9',
    'target_resource_ids' => ['product_8'],
    'value' => 'black',
    'confirmation_id' => null,
], ['runtime' => 'runtime_4'], $now);
$check(!$wrongResource['valid'] && $wrongResource['code'] === 'binding_resource_scope_mismatch', 'server rejects a wrong-resource short-reply binding');

$handler = new class implements ToolHandler {
    public int $writes = 0;

    public function definitions(): array
    {
        return [
            new ToolDefinition('test.read', '1.0.0', 'Read fixture.', 'read', [
                'type' => 'object', 'additionalProperties' => false, 'properties' => [],
            ], ['guest'], [], [], true),
            new ToolDefinition('test.write', '1.0.0', 'Write fixture.', 'write', [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['value', 'idempotency_key'],
                'properties' => [
                    'value' => ['type' => 'string'],
                    'idempotency_key' => ['type' => 'string'],
                ],
            ], ['guest'], [], [], true),
        ];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        if ($call->name === 'test.write') {
            ++$this->writes;
        }
        return $call->name === 'test.write'
            ? ToolResult::success($call, ['value' => $call->arguments['value']], $context->correlationId, ['test:1'])
            : ToolResult::success($call, ['value' => 'observed'], $context->correlationId);
    }
};
$context = new ToolContext('guest', 'guest_1', null, 'guest_1', 'conversation_1', [], [], 'en', 'corr_1');
$registry = new ToolRegistry(new ToolInputValidator(), null, UniversalToolGovernance::disabled());
$registry->register($handler);
$catalog = $registry->planningTools($context);
$writeCatalog = array_values(array_filter($catalog, static fn (array $tool): bool => $tool['name'] === 'test.write'))[0] ?? null;
$check(is_array($writeCatalog) && $writeCatalog['classification'] === 'write', 'planning catalog carries the server-owned tool classification');
$check(!isset($writeCatalog['input_schema']['properties']['idempotency_key']), 'planning catalog keeps idempotency server-owned');

$decision = static function (array $steps, int $budget = 4): array {
    return [
        'schema_version' => '1.0.0',
        'interpretation' => [],
        'plan' => [
            'schema_version' => '1.0.0',
            'plan_id' => 'plan_1',
            'steps' => $steps,
            'stop_conditions' => ['Complete safely.'],
            'fallback' => 'truthful_block',
            'budgets' => ['max_provider_calls' => 3, 'max_tool_calls' => $budget, 'max_repair_loops' => 0, 'deadline_ms' => 10000],
        ],
    ];
};
$toolStep = static fn (string $id, string $name, string $classification, array $arguments, array $dependencies = []): array => [
    'step_id' => $id,
    'order' => 1,
    'kind' => 'tool',
    'tool_name' => $name,
    'tool_version' => '1.0.0',
    'proposed_arguments' => $arguments,
    'classification' => $classification,
    'depends_on' => $dependencies,
    'confirmation_requirement' => 'none',
    'on_success' => 'Continue.',
    'on_failure' => 'stop',
];
$respondStep = static fn (string $id, int $order): array => [
    'step_id' => $id,
    'order' => $order,
    'kind' => 'respond',
    'tool_name' => null,
    'tool_version' => null,
    'proposed_arguments' => null,
    'classification' => 'conversation',
    'depends_on' => [],
    'confirmation_requirement' => 'none',
    'on_success' => 'Respond.',
    'on_failure' => 'stop',
];

$executor = new DecisionPlanExecutor($registry);
$readPlan = $decision([$toolStep('step_1', 'test.read', 'read', []), $respondStep('step_2', 2)]);
$readExecution = $executor->execute($readPlan, $context, 'msg_customer', true, 8);
$check(count($readExecution['tool_results']) === 1 && $readExecution['tool_results'][0]->status === 'succeeded', 'strict plan executor runs an authorized read before response composition');

$writePlan = $decision([$toolStep('step_1', 'test.write', 'write', ['value' => 'x'])]);
$blockedWrite = $executor->execute($writePlan, $context, 'msg_customer', false, 8);
$check($blockedWrite['tool_results'][0]->code === 'turn_mutation_binding_required', 'unconsumed Pending Question blocks a planned write');

$wrongClassPlan = $decision([$toolStep('step_1', 'test.write', 'read', ['value' => 'x'])]);
$wrongClass = $executor->execute($wrongClassPlan, $context, 'msg_customer', true, 8);
$check($wrongClass['tool_results'][0]->code === 'plan_tool_classification_mismatch', 'server rejects provider-supplied tool classification drift');

$secondWrite = $toolStep('step_2', 'test.write', 'write', ['value' => 'same'], ['step_1']);
$secondWrite['order'] = 2;
$writesBeforeReplayPlan = $handler->writes;
$replayedWrite = $executor->execute(
    $decision([
        $toolStep('step_1', 'test.write', 'write', ['value' => 'same']),
        $secondWrite,
    ]),
    $context,
    'msg_customer_replay',
    true,
    8
);
$check(
    $handler->writes === $writesBeforeReplayPlan + 1
        && ($replayedWrite['step_outcomes'][0]['semantic_replay'] ?? null) === false
        && ($replayedWrite['step_outcomes'][1]['semantic_replay'] ?? null) === true,
    'semantic mutation replay is server-owned outcome metadata and executes once'
);
$check(
    $replayedWrite['tool_results'][0]->data === ['value' => 'same']
        && $replayedWrite['tool_results'][1]->data === ['value' => 'same']
        && !array_key_exists('turn_semantic_replay', $replayedWrite['tool_results'][1]->data),
    'semantic replay preserves the exact registered ToolResult schema'
);

$afterResponse = $toolStep('step_2', 'test.write', 'write', ['value' => 'x']);
$afterResponse['order'] = 2;
$controlExecution = $executor->execute($decision([$respondStep('step_1', 1), $afterResponse]), $context, 'msg_customer', true, 8);
$check($controlExecution['tool_results'][0]->code === 'plan_control_boundary_reached', 'no tool executes after a response/control boundary');

fwrite(STDOUT, "Strict orchestration scenarios: {$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
