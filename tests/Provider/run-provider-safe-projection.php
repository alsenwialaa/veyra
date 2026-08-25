<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Provider\GeminiInteractionResponse;
use Veyra\AI\Provider\ProviderProhibitedDataRedactor;
use Veyra\AI\Provider\ProviderSafeToolResultProjector;
use Veyra\AI\Provider\ProviderToolResultProjectionException;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\AI\Tool\UniversalToolGovernance;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$handler = new class implements ToolHandler {
    public function definitions(): array
    {
        return [
            new ToolDefinition(
                'test.closed_result',
                '1.0.0',
                'Closed provider projection fixture.',
                'read',
                ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                ['guest'],
                [],
                [],
                true,
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['summary', 'empty_object'],
                    'properties' => [
                        'summary' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                        'empty_object' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'maxProperties' => 0,
                            'properties' => [],
                        ],
                    ],
                ]
            ),
            new ToolDefinition(
                'test.open_result',
                '1.0.0',
                'Intentionally uncertified open result fixture.',
                'read',
                ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                ['guest'],
                [],
                [],
                true,
                ['type' => 'object']
            ),
            new ToolDefinition(
                'test.dynamic_map_result',
                '1.0.0',
                'Intentionally uncertified nested dynamic-map fixture.',
                'read',
                ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                ['guest'],
                [],
                [],
                true,
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['attributes'],
                    'properties' => [
                        'attributes' => [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'string', 'maxLength' => 100],
                        ],
                    ],
                ]
            ),
        ];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        return ToolResult::failed($call, 'fixture_not_executable', $context->correlationId, false);
    }
};

$registry = new ToolRegistry(
    new ToolInputValidator(),
    null,
    UniversalToolGovernance::disabled()
);
$registry->register($handler);
$projector = new ProviderSafeToolResultProjector();

$safe = new ToolResult(
    'call-safe',
    'test.closed_result',
    'succeeded',
    'ok',
    ['summary' => 'Two items are available.', 'empty_object' => []],
    [],
    true,
    false,
    'private-correlation'
);
$first = $projector->project($safe, $registry);
$second = $projector->project($safe, $registry);
$check($first === $second, 'Provider ToolResult projection was not deterministic.');
$check(!array_key_exists('correlation_id', $first), 'Internal correlation ID crossed the provider projection.');
$check(($first['data']['empty_object'] ?? null) === [], 'A schema-typed empty object was not projected.');
$check($projector->validateProjectedList([$first]), 'A valid closed projection failed last-boundary validation.');

$secretText = 'password=shopper-secret OTP: 123456 card 4111 1111 1111 1111';
$secretResult = new ToolResult(
    'call-secret',
    'test.closed_result',
    'succeeded',
    'ok',
    ['summary' => $secretText, 'empty_object' => []],
    [],
    true,
    false,
    'private-correlation'
);
$redacted = $projector->project($secretResult, $registry);
$encodedRedacted = json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$check(is_string($encodedRedacted) && !str_contains($encodedRedacted, 'shopper-secret'), 'ToolResult authentication secret was not removed.');
$check(is_string($encodedRedacted) && !str_contains($encodedRedacted, '4111 1111 1111 1111'), 'ToolResult payment credential was not removed.');
$check(($redacted['redactions'] ?? null) === ['authentication_secret', 'one_time_code', 'payment_credential'], 'ToolResult redaction classes were not stable and sorted.');
$check($projector->validateProjectedList([$redacted]), 'A deterministically redacted projection failed last-boundary validation.');

$open = new ToolResult('call-open', 'test.open_result', 'succeeded', 'ok', ['value' => 'unsafe'], [], true, false, 'corr');
$dynamicMap = new ToolResult(
    'call-dynamic-map',
    'test.dynamic_map_result',
    'succeeded',
    'ok',
    ['attributes' => ['color' => 'blue']],
    [],
    true,
    false,
    'corr'
);
$unregistered = new ToolResult('call-missing', 'test.missing_result', 'failed', 'not_registered', [], [], true, false, 'corr');
foreach ([
    'open schema' => $open,
    'nested dynamic-map schema' => $dynamicMap,
    'unregistered tool' => $unregistered,
] as $label => $result) {
    $providerRequestsConstructed = 0;
    try {
        // This is the CommerceAgent ordering invariant: projection completes
        // before the response ProviderRequest constructor is reached.
        $projector->projectMany([$result], $registry);
        ++$providerRequestsConstructed;
    } catch (ProviderToolResultProjectionException $error) {
        $check($error->reasonCode === 'provider_tool_result_projection_not_allowlisted', ucfirst($label) . ' returned the wrong fail-closed code.');
    }
    $check($providerRequestsConstructed === 0, ucfirst($label) . ' reached provider request construction.');
}

$pending = new ToolResult(
    'call-pending',
    'conversation.consume_pending_question',
    'succeeded',
    'pending_question_consumed',
    [
        'question_id' => 'question-1',
        'binding_id' => 'binding-1',
        'customer_message_id' => 'message-1',
        'validated_value' => 'password=pending-secret',
    ],
    ['pending_question:question-1'],
    true,
    false,
    'private-correlation'
);
$pendingProjection = $projector->project($pending, $registry);
$pendingEncoded = json_encode($pendingProjection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$check(!array_key_exists('validated_value', $pendingProjection['data'] ?? []), 'Pending-question validated value crossed the narrow provider projection.');
$check(is_string($pendingEncoded) && !str_contains($pendingEncoded, 'pending-secret'), 'Pending-question secret crossed the provider projection.');
$check($projector->validateProjectedList([$pendingProjection]), 'Internal pending-question projection failed boundary validation.');

$tampered = $first;
$tampered['correlation_id'] = 'leak';
$check(!$projector->validateProjectedList([$tampered]), 'Open provider envelope field was accepted.');
$tampered = $first;
$tampered['data']['summary'] = 'api_key=sk-1234567890abcdefghijklmnop';
$check(!$projector->validateProjectedList([$tampered]), 'Unredacted prohibited ToolResult data was accepted at the final boundary.');

$redactor = new ProviderProhibitedDataRedactor();
$requirement = [
    'requirement_state' => [
        'criteria' => [[
            'criterion_id' => 'criterion-1',
            'value' => 'My password=never-send-this',
        ]],
    ],
];
$requirementSafe = $redactor->redact($requirement);
$requirementEncoded = json_encode($requirementSafe['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$check(is_string($requirementEncoded) && !str_contains($requirementEncoded, 'never-send-this'), 'Requirement-state free text bypassed prohibited-data redaction.');
$check(!$redactor->isAlreadySafe($requirement), 'Unsafe requirement-state data was classified as provider-safe.');
$check($redactor->isAlreadySafe($requirementSafe['value']), 'Redacted requirement-state data was not idempotently provider-safe.');

$freeTextSurfaces = [
    'current_input' => ['text' => 'Use password=current-turn-secret.'],
    'reply_quote' => ['text' => 'Earlier card: 4111 1111 1111 1111'],
    'recent_visible_messages' => [[
        'text' => 'Bearer abcdefghijklmnopqrstuvwxyz123456',
    ]],
    'allowed_personal_contact' => 'Email shopper@example.test or call +1 202 555 0142.',
];
$surfaceSafe = $redactor->redact($freeTextSurfaces);
$surfaceEncoded = json_encode($surfaceSafe['value'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$check(is_string($surfaceEncoded) && !str_contains($surfaceEncoded, 'current-turn-secret'), 'Current-turn free text retained an authentication secret.');
$check(is_string($surfaceEncoded) && !str_contains($surfaceEncoded, '4111 1111 1111 1111'), 'Reply-quote text retained a payment credential.');
$check(is_string($surfaceEncoded) && !str_contains($surfaceEncoded, 'abcdefghijklmnopqrstuvwxyz123456'), 'Recent-message text retained a bearer credential.');
$check(is_string($surfaceEncoded) && str_contains($surfaceEncoded, 'shopper@example.test') && str_contains($surfaceEncoded, '+1 202 555 0142'), 'Authorized ordinary contact data was over-redacted.');

$validSteps = new GeminiInteractionResponse([
    'object' => 'interaction',
    'status' => 'requires_action',
    'steps' => [
        [
            'type' => 'thought',
            'signature' => 'opaque-signature',
            'summary' => [['type' => 'text', 'text' => 'Checking the declared function.']],
        ],
        [
            'type' => 'function_call',
            'id' => 'call-1',
            'name' => 'catalog__search',
            'arguments' => ['query' => 'blue jacket'],
        ],
    ],
    'usage' => ['total_input_tokens' => 12, 'total_output_tokens' => 7],
]);
$check($validSteps->valid() && count($validSteps->nativeToolCalls()) === 1, 'Current raw REST steps fixture was rejected.');
$check($validSteps->usage('input_tokens') === 12, 'Current raw REST usage was not decoded.');

foreach ([
    'legacy outputs' => ['status' => 'completed', 'outputs' => [['text' => '{}']]],
    'SDK output_text' => ['status' => 'completed', 'output_text' => '{}', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{}']]]]],
    'unknown step' => ['status' => 'completed', 'steps' => [['type' => 'future_unknown', 'value' => 'ignored?']]],
    'open model step' => ['status' => 'completed', 'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{}']], 'unknown' => true]]],
    'duplicate call id' => ['status' => 'requires_action', 'steps' => [
        ['type' => 'function_call', 'id' => 'same', 'name' => 'catalog__search', 'arguments' => []],
        ['type' => 'function_call', 'id' => 'same', 'name' => 'catalog__search', 'arguments' => []],
    ]],
] as $label => $fixture) {
    $decoded = new GeminiInteractionResponse($fixture);
    $check(!$decoded->valid() && $decoded->modelSteps() === [], ucfirst($label) . ' was accepted by the raw REST decoder.');
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Provider-safe projection and strict Gemini fixtures passed.\n");
