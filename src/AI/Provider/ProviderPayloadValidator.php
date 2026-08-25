<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

use Veyra\AI\Tool\ToolInputValidator;

final class ProviderPayloadValidator
{
    private const CLAIM_VALUE_TYPES = ['string', 'integer', 'number', 'boolean', 'null', 'money', 'resource'];

    private const VERIFICATION_CHECKS = [
        'latest_goal_answered',
        'subgoals_complete_or_pending',
        'commerce_claims_current',
        'policy_claims_approved',
        'hard_requirements_preserved',
        'tool_results_exact',
        'stale_not_current',
        'culture_location_time_format_correct',
        'no_protected_trait_or_manipulation',
        'confirmation_and_disclosure_present',
    ];

    private const SALES_STAGES = [
        'exploring', 'qualifying', 'evaluating', 'configuring', 'cart',
        'checkout', 'post_purchase', 'service', 'handoff', 'unknown',
    ];

    private const PLAN_KINDS = [
        'tool', 'clarify', 'prepare_confirmation', 'respond', 'handoff', 'stop',
    ];

    /**
     * Validate the provider-independent semantic interpretation and ordered
     * plan as two independently versioned contracts. This is deliberately
     * separate from the legacy readiness-probe envelope below.
     *
     * @return array<string, mixed>|null
     */
    public function validateDecisionPayload(mixed $payload): ?array
    {
        if (!$this->exactObject($payload, ['schema_version', 'interpretation', 'plan'])
            || $payload['schema_version'] !== '1.0.0'
            || !$this->withinEncodedLimit($payload, 262144)
            || !$this->validInterpretation($payload['interpretation'])
            || !$this->validPlan($payload['plan'])
        ) {
            return null;
        }

        return $payload;
    }

    /** @return array<string, mixed>|null */
    public function validateResponseContractPayload(mixed $payload): ?array
    {
        $required = [
            'schema_version', 'language', 'direction', 'reply', 'proposed_updates',
            'evidence_requirements', 'claims',
        ];
        if (!$this->exactObject($payload, $required)
            || $payload['schema_version'] !== '1.0.0'
            || !$this->withinEncodedLimit($payload, 262144)
            || !$this->boundedString($payload['language'], 2, 35)
            || !in_array($payload['direction'], ['ltr', 'rtl', 'mixed'], true)
            || !$this->validReply($payload['reply'])
            || !$this->validProposedUpdates($payload['proposed_updates'])
            || !$this->stringList($payload['evidence_requirements'], 32, 240)
            || !is_array($payload['claims'])
            || !array_is_list($payload['claims'])
            || count($payload['claims']) > 64
        ) {
            return null;
        }

        $claimIds = [];
        foreach ($payload['claims'] as $claim) {
            if (!$this->validClaim($claim) || isset($claimIds[$claim['claim_id']])) {
                return null;
            }
            $claimIds[$claim['claim_id']] = true;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function decisionSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema_version', 'interpretation', 'plan'],
            'properties' => [
                'schema_version' => ['type' => 'string', 'enum' => ['1.0.0']],
                'interpretation' => $this->interpretationSchema(),
                'plan' => $this->planSchema(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function responseContractSchema(): array
    {
        $legacy = $this->responseSchema();

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'schema_version', 'language', 'direction', 'reply', 'proposed_updates',
                'evidence_requirements', 'claims',
            ],
            'properties' => [
                'schema_version' => ['type' => 'string', 'enum' => ['1.0.0']],
                'language' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 35],
                'direction' => ['type' => 'string', 'enum' => ['ltr', 'rtl', 'mixed']],
                'reply' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['text', 'components'],
                    'properties' => [
                        'text' => ['type' => 'string', 'maxLength' => 12000],
                        'components' => [
                            'type' => 'array',
                            'maxItems' => 20,
                            'items' => $this->componentIntentionSchema(),
                        ],
                    ],
                ],
                'proposed_updates' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['focus', 'memory', 'summary', 'journey', 'durable_preferences'],
                    'properties' => [
                        'focus' => ['type' => ['object', 'null']],
                        'memory' => ['type' => ['object', 'null']],
                        'summary' => ['type' => ['object', 'null']],
                        'journey' => ['type' => ['object', 'null']],
                        'durable_preferences' => [
                            'type' => 'array',
                            'maxItems' => 20,
                            'items' => ['type' => 'object'],
                        ],
                    ],
                ],
                'evidence_requirements' => [
                    'type' => 'array',
                    'maxItems' => 32,
                    'items' => ['type' => 'string', 'maxLength' => 240],
                ],
                'claims' => $legacy['properties']['claims'],
            ],
        ];
    }

    /**
     * Closed output shape used only by the explicit capability probe. Native
     * tool calling remains the success criterion; this schema prevents the
     * probe from carrying a shopper/agent response contract on the side.
     *
     * @return array<string, mixed>
     */
    public function readinessSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema_version', 'probe_status'],
            'properties' => [
                'schema_version' => ['type' => 'string', 'enum' => ['1.0.0']],
                'probe_status' => ['type' => 'string', 'enum' => ['tool_call_requested']],
            ],
        ];
    }

    /** @return array{schema_version:string,probe_status:string}|null */
    public function validateReadinessPayload(mixed $payload): ?array
    {
        if (!is_array($payload) || array_is_list($payload)) {
            return null;
        }
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['probe_status', 'schema_version']
            || ($payload['schema_version'] ?? null) !== '1.0.0'
            || ($payload['probe_status'] ?? null) !== 'tool_call_requested'
        ) {
            return null;
        }
        return [
            'schema_version' => '1.0.0',
            'probe_status' => 'tool_call_requested',
        ];
    }

    /** @return array<string, mixed>|null */
    public function validateTurnPayload(mixed $payload): ?array
    {
        $required = [
            'schema_version', 'turn_type', 'language', 'direction', 'reply', 'tool_calls',
            'proposed_updates', 'evidence_requirements', 'claims',
        ];
        if (!is_array($payload) || array_diff(array_keys($payload), $required)) {
            return null;
        }
        foreach ($required as $field) {
            if (!array_key_exists($field, $payload)) {
                return null;
            }
        }

        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            return null;
        }
        if (!in_array($payload['turn_type'] ?? null, ['plan', 'response'], true)) {
            return null;
        }
        if (!is_string($payload['language'] ?? null) || !in_array($payload['direction'] ?? null, ['ltr', 'rtl'], true)) {
            return null;
        }
        if (!is_array($payload['reply'] ?? null) || !is_string($payload['reply']['text'] ?? null)) {
            return null;
        }
        if (strlen($payload['reply']['text']) > 12000 || !is_array($payload['reply']['components'] ?? null)) {
            return null;
        }
        if (!is_array($payload['tool_calls'] ?? null) || count($payload['tool_calls']) > 8) {
            return null;
        }
        foreach ($payload['tool_calls'] as $call) {
            if (!is_array($call) || array_diff(array_keys($call), ['call_id', 'name', 'version', 'arguments'])) {
                return null;
            }
            if (!is_string($call['call_id'] ?? null) || !is_string($call['name'] ?? null) || ($call['version'] ?? null) !== '1.0.0' || !is_array($call['arguments'] ?? null)) {
                return null;
            }
        }
        if (!is_array($payload['proposed_updates'] ?? null)
            || !is_array($payload['evidence_requirements'] ?? null)
            || !is_array($payload['claims'] ?? null)
            || !array_is_list($payload['claims'])
            || count($payload['claims']) > 64
        ) {
            return null;
        }
        $claimIds = [];
        foreach ($payload['claims'] as $claim) {
            if (!$this->validClaim($claim) || isset($claimIds[$claim['claim_id']])) {
                return null;
            }
            $claimIds[$claim['claim_id']] = true;
        }
        return $payload;
    }

    /** @return array<string, mixed> */
    public function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema_version', 'turn_type', 'language', 'direction', 'reply', 'tool_calls', 'proposed_updates', 'evidence_requirements', 'claims'],
            'properties' => [
                'schema_version' => ['type' => 'string', 'enum' => ['1.0.0']],
                'turn_type' => ['type' => 'string', 'enum' => ['plan', 'response']],
                'language' => ['type' => 'string', 'maxLength' => 24],
                'direction' => ['type' => 'string', 'enum' => ['ltr', 'rtl']],
                'reply' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['text', 'components'],
                    'properties' => [
                        'text' => ['type' => 'string', 'maxLength' => 12000],
                        'components' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'object']],
                    ],
                ],
                'tool_calls' => [
                    'type' => 'array',
                    'maxItems' => 8,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['call_id', 'name', 'version', 'arguments'],
                        'properties' => [
                            'call_id' => ['type' => 'string', 'maxLength' => 80],
                            'name' => ['type' => 'string', 'maxLength' => 100],
                            'version' => ['type' => 'string', 'enum' => ['1.0.0']],
                            'arguments' => ['type' => 'object'],
                        ],
                    ],
                ],
                'proposed_updates' => ['type' => 'object'],
                'evidence_requirements' => ['type' => 'array', 'maxItems' => 32, 'items' => ['type' => 'string']],
                'claims' => [
                    'type' => 'array',
                    'maxItems' => 64,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['claim_id', 'type', 'status', 'source_call_id', 'source_path', 'asserted_value'],
                        'properties' => [
                            'claim_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]*$'],
                            'type' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80, 'pattern' => '^[a-z][a-z0-9_]*$'],
                            'status' => ['type' => 'string', 'enum' => ['proposed', 'verified', 'historical', 'assumption', 'unknown']],
                            'source_call_id' => ['type' => ['string', 'null'], 'minLength' => 1, 'maxLength' => 80],
                            'source_path' => [
                                'type' => ['string', 'null'],
                                'minLength' => 5,
                                'maxLength' => 512,
                                'pattern' => '^/(?:data|changed_resources)(?:/(?:[^~/]|~[01])*)*$',
                            ],
                            'asserted_value' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => [
                                    'kind', 'string_value', 'integer_value', 'number_value', 'boolean_value',
                                    'currency', 'currency_source_path',
                                ],
                                'properties' => [
                                    'kind' => ['type' => 'string', 'enum' => self::CLAIM_VALUE_TYPES],
                                    'string_value' => ['type' => ['string', 'null'], 'maxLength' => 12000],
                                    'integer_value' => ['type' => ['integer', 'null']],
                                    'number_value' => ['type' => ['number', 'null']],
                                    'boolean_value' => ['type' => ['boolean', 'null']],
                                    'currency' => ['type' => ['string', 'null'], 'pattern' => '^[A-Z]{3}$'],
                                    'currency_source_path' => [
                                        'type' => ['string', 'null'],
                                        'minLength' => 5,
                                        'maxLength' => 512,
                                        'pattern' => '^/data(?:/(?:[^~/]|~[01])*)*$',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function validateSemanticVerificationPayload(mixed $payload): ?array
    {
        $required = ['schema_version', 'verdict', 'checks', 'reason_codes', 'unsupported_spans'];
        if (!is_array($payload) || array_diff(array_keys($payload), $required) !== [] || array_diff($required, array_keys($payload)) !== []) {
            return null;
        }
        if (($payload['schema_version'] ?? null) !== '1.0.0'
            || !in_array($payload['verdict'] ?? null, ['supported', 'unsupported', 'uncertain'], true)
            || !is_array($payload['checks'] ?? null)
            || !array_is_list($payload['checks'])
            || count($payload['checks']) !== count(self::VERIFICATION_CHECKS)
            || !is_array($payload['reason_codes'] ?? null)
            || !array_is_list($payload['reason_codes'])
            || count($payload['reason_codes']) > 16
            || !is_array($payload['unsupported_spans'] ?? null)
            || !array_is_list($payload['unsupported_spans'])
            || count($payload['unsupported_spans']) > 12
        ) {
            return null;
        }

        $statuses = [];
        foreach ($payload['checks'] as $check) {
            if (!is_array($check)
                || array_diff(array_keys($check), ['check', 'status']) !== []
                || array_diff(['check', 'status'], array_keys($check)) !== []
                || !in_array($check['check'] ?? null, self::VERIFICATION_CHECKS, true)
                || !in_array($check['status'] ?? null, ['pass', 'fail', 'uncertain'], true)
                || isset($statuses[$check['check']])
            ) {
                return null;
            }
            $statuses[$check['check']] = $check['status'];
        }
        if (array_diff(self::VERIFICATION_CHECKS, array_keys($statuses)) !== []) {
            return null;
        }
        foreach ($payload['reason_codes'] as $reason) {
            if (!is_string($reason) || preg_match('/^[a-z][a-z0-9_]{1,79}$/D', $reason) !== 1) {
                return null;
            }
        }
        foreach ($payload['unsupported_spans'] as $span) {
            if (!is_string($span) || trim($span) === '' || strlen($span) > 240) {
                return null;
            }
        }

        $failed = in_array('fail', $statuses, true);
        $uncertain = in_array('uncertain', $statuses, true);
        if (($payload['verdict'] === 'supported' && ($failed || $uncertain || $payload['unsupported_spans'] !== []))
            || ($payload['verdict'] === 'unsupported' && !$failed)
            || ($payload['verdict'] === 'uncertain' && !$uncertain)
        ) {
            return null;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function semanticVerificationSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema_version', 'verdict', 'checks', 'reason_codes', 'unsupported_spans'],
            'properties' => [
                'schema_version' => ['type' => 'string', 'enum' => ['1.0.0']],
                'verdict' => ['type' => 'string', 'enum' => ['supported', 'unsupported', 'uncertain']],
                'checks' => [
                    'type' => 'array',
                    'minItems' => count(self::VERIFICATION_CHECKS),
                    'maxItems' => count(self::VERIFICATION_CHECKS),
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['check', 'status'],
                        'properties' => [
                            'check' => ['type' => 'string', 'enum' => self::VERIFICATION_CHECKS],
                            'status' => ['type' => 'string', 'enum' => ['pass', 'fail', 'uncertain']],
                        ],
                    ],
                ],
                'reason_codes' => [
                    'type' => 'array',
                    'maxItems' => 16,
                    'items' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,79}$'],
                ],
                'unsupported_spans' => [
                    'type' => 'array',
                    'maxItems' => 12,
                    'items' => ['type' => 'string', 'maxLength' => 240],
                ],
            ],
        ];
    }

    private function validClaim(mixed $claim): bool
    {
        $required = ['claim_id', 'type', 'status', 'source_call_id', 'source_path', 'asserted_value'];
        if (!is_array($claim)
            || array_diff(array_keys($claim), $required) !== []
            || array_diff($required, array_keys($claim)) !== []
            || !is_string($claim['claim_id'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/D', $claim['claim_id']) !== 1
            || !is_string($claim['type'] ?? null)
            || preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $claim['type']) !== 1
            || !in_array($claim['status'] ?? null, ['proposed', 'verified', 'historical', 'assumption', 'unknown'], true)
        ) {
            return false;
        }

        $callId = $claim['source_call_id'];
        $sourcePath = $claim['source_path'];
        if (($callId !== null && (!is_string($callId) || $callId === '' || strlen($callId) > 80))
            || ($sourcePath !== null && (!is_string($sourcePath) || !$this->validSourcePath($sourcePath)))
            || (($callId === null) !== ($sourcePath === null))
            || ($claim['status'] === 'verified' && ($callId === null || $sourcePath === null))
            || ($claim['status'] === 'unknown' && ($callId !== null || $sourcePath !== null))
            || !$this->validAssertedValue($claim['asserted_value'], $claim['status'], $callId !== null)
        ) {
            return false;
        }

        if ($claim['status'] === 'unknown') {
            return $claim['asserted_value']['kind'] === 'null';
        }
        if ($claim['type'] === 'mutation_success' && $claim['status'] === 'verified') {
            return $claim['asserted_value']['kind'] === 'resource'
                && preg_match('#^/changed_resources/(?:0|[1-9][0-9]*)$#D', $sourcePath) === 1;
        }

        return true;
    }

    private function validAssertedValue(mixed $asserted, string $status, bool $hasSource): bool
    {
        $required = [
            'kind', 'string_value', 'integer_value', 'number_value', 'boolean_value',
            'currency', 'currency_source_path',
        ];
        if (!is_array($asserted)
            || array_diff(array_keys($asserted), $required) !== []
            || array_diff($required, array_keys($asserted)) !== []
            || !is_string($asserted['kind'] ?? null)
            || !in_array($asserted['kind'], self::CLAIM_VALUE_TYPES, true)
        ) {
            return false;
        }

        $valueType = $asserted['kind'];
        if ($valueType === 'money') {
            $currencyPath = $asserted['currency_source_path'];
            return is_string($asserted['string_value'])
                && strlen($asserted['string_value']) <= 64
                && preg_match('/^-?\d+(?:\.\d+)?$/D', $asserted['string_value']) === 1
                && $asserted['integer_value'] === null
                && $asserted['number_value'] === null
                && $asserted['boolean_value'] === null
                && is_string($asserted['currency'])
                && preg_match('/^[A-Z]{3}$/D', $asserted['currency']) === 1
                && ($currencyPath === null || (is_string($currencyPath) && $this->validSourcePath($currencyPath, true)))
                && ($hasSource || $currencyPath === null)
                && ($status !== 'verified' || $currencyPath !== null);
        }
        if ($asserted['currency'] !== null || $asserted['currency_source_path'] !== null) {
            return false;
        }

        return match ($valueType) {
            'string' => is_string($asserted['string_value'])
                && strlen($asserted['string_value']) <= 12000
                && $asserted['integer_value'] === null
                && $asserted['number_value'] === null
                && $asserted['boolean_value'] === null,
            'integer' => $asserted['string_value'] === null
                && is_int($asserted['integer_value'])
                && $asserted['number_value'] === null
                && $asserted['boolean_value'] === null,
            'number' => $asserted['string_value'] === null
                && $asserted['integer_value'] === null
                && (is_int($asserted['number_value']) || is_float($asserted['number_value']))
                && is_finite((float) $asserted['number_value'])
                && $asserted['boolean_value'] === null,
            'boolean' => $asserted['string_value'] === null
                && $asserted['integer_value'] === null
                && $asserted['number_value'] === null
                && is_bool($asserted['boolean_value']),
            'null' => $asserted['string_value'] === null
                && $asserted['integer_value'] === null
                && $asserted['number_value'] === null
                && $asserted['boolean_value'] === null,
            'resource' => is_string($asserted['string_value'])
                && $asserted['string_value'] !== ''
                && strlen($asserted['string_value']) <= 512
                && $asserted['integer_value'] === null
                && $asserted['number_value'] === null
                && $asserted['boolean_value'] === null,
            default => false,
        };
    }

    private function validSourcePath(string $path, bool $dataOnly = false): bool
    {
        if ($path === '' || strlen($path) > 512 || substr_count($path, '/') > 32) {
            return false;
        }
        if (preg_match($dataOnly ? '#^/data(?:/|$)#D' : '#^/(?:data|changed_resources)(?:/|$)#D', $path) !== 1) {
            return false;
        }
        foreach (explode('/', substr($path, 1)) as $token) {
            if (preg_match('/~(?:[^01]|$)/', $token) === 1) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $keys */
    private function exactObject(mixed $value, array $keys): bool
    {
        return is_array($value)
            && ($value === [] || !array_is_list($value))
            && array_diff(array_keys($value), $keys) === []
            && array_diff($keys, array_keys($value)) === [];
    }

    private function boundedString(mixed $value, int $minimum, int $maximum): bool
    {
        return is_string($value) && strlen($value) >= $minimum && strlen($value) <= $maximum;
    }

    /** @return bool */
    private function stringList(mixed $value, int $maximumItems, int $maximumLength): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximumItems) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || strlen($item) > $maximumLength) {
                return false;
            }
        }
        return true;
    }

    private function withinEncodedLimit(mixed $value, int $maximumBytes): bool
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) && strlen($encoded) <= $maximumBytes;
    }

    private function validInterpretation(mixed $value): bool
    {
        return is_array($value) && (new ToolInputValidator())->validate($value, $this->interpretationSchema());
    }

    private function validPlan(mixed $value): bool
    {
        if (!is_array($value) || !(new ToolInputValidator())->validate($value, $this->planSchema())) {
            return false;
        }
        $stepIds = [];
        $orders = [];
        foreach ($value['steps'] as $step) {
            $stepId = $step['step_id'];
            $order = $step['order'];
            if (isset($stepIds[$stepId]) || isset($orders[$order])) {
                return false;
            }
            foreach ($step['depends_on'] as $dependency) {
                if (!isset($stepIds[$dependency])) {
                    return false;
                }
            }
            $isTool = $step['kind'] === 'tool';
            if ($isTool !== ($step['tool_name'] !== null && $step['tool_version'] !== null && $step['proposed_arguments'] !== null)) {
                return false;
            }
            $stepIds[$stepId] = true;
            $orders[$order] = true;
        }
        $expected = 1;
        $actual = array_keys($orders);
        sort($actual, SORT_NUMERIC);
        foreach ($actual as $order) {
            if ($order !== $expected++) {
                return false;
            }
        }
        return true;
    }

    private function validReply(mixed $value): bool
    {
        if (!is_array($value) || !(new ToolInputValidator())->validate($value, [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['text', 'components'],
            'properties' => [
                'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000],
                'components' => [
                    'type' => 'array', 'maxItems' => 20,
                    'items' => $this->componentIntentionSchema(),
                ],
            ],
        ])) {
            return false;
        }

        foreach ($value['components'] as $component) {
            $type = $component['type'];
            $targets = $component['product_targets'];
            $targetCount = count($targets);
            if (($type === 'product' && $targetCount !== 1)
                || ($type === 'comparison' && ($targetCount < 2 || $targetCount > 4))
                || (!in_array($type, ['product', 'comparison'], true) && $targetCount !== 0)
            ) {
                return false;
            }
            $identities = [];
            foreach ($targets as $target) {
                $identity = (string) $target['product_id'] . ':' . (string) $target['variation_id'];
                if (isset($identities[$identity])) {
                    return false;
                }
                $identities[$identity] = true;
            }
        }

        return true;
    }

    private function validProposedUpdates(mixed $value): bool
    {
        return is_array($value) && (new ToolInputValidator())->validate($value, [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['focus', 'memory', 'summary', 'journey', 'durable_preferences'],
            'properties' => [
                'focus' => ['type' => ['object', 'null'], 'maxProperties' => 10],
                'memory' => ['type' => ['object', 'null'], 'maxProperties' => 20],
                'summary' => ['type' => ['object', 'null'], 'maxProperties' => 20],
                'journey' => ['type' => ['object', 'null'], 'maxProperties' => 20],
                'durable_preferences' => [
                    'type' => 'array', 'maxItems' => 20,
                    'items' => ['type' => 'object', 'maxProperties' => 20],
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function interpretationSchema(): array
    {
        $opaque = ['type' => 'string', 'minLength' => 1, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]*$'];
        $goal = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['goal_type', 'description', 'confidence'],
            'properties' => [
                'goal_type' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                'description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
        ];
        $proposal = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['key', 'value', 'value_type', 'source_message_id', 'confidence', 'state'],
            'properties' => [
                'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                'value' => [],
                'value_type' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                'source_message_id' => $opaque,
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'state' => ['const' => 'proposed'],
            ],
        ];

        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => [
                'schema_version', 'language', 'direction', 'primary_goal', 'secondary_goals',
                'sales_stage', 'requirements', 'corrections', 'references', 'focus_proposal',
                'short_reply_binding', 'missing_information', 'ambiguities', 'risk', 'field_confidence',
            ],
            'properties' => [
                'schema_version' => ['const' => '1.0.0'],
                'language' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 35],
                'dialect' => ['type' => ['string', 'null'], 'maxLength' => 80],
                'direction' => ['enum' => ['ltr', 'rtl', 'mixed']],
                'primary_goal' => $goal,
                'secondary_goals' => ['type' => 'array', 'maxItems' => 10, 'items' => $goal],
                'sales_stage' => ['enum' => self::SALES_STAGES],
                'requirements' => ['type' => 'array', 'maxItems' => 100, 'items' => $proposal],
                'corrections' => ['type' => 'array', 'maxItems' => 50, 'items' => $proposal],
                'references' => [
                    'type' => 'array', 'maxItems' => 30,
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['surface_text', 'candidate_resource_ids', 'confidence', 'ambiguity'],
                        'properties' => [
                            'surface_text' => ['type' => 'string', 'maxLength' => 240],
                            'candidate_resource_ids' => ['type' => 'array', 'maxItems' => 10, 'items' => $opaque],
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            'ambiguity' => ['enum' => ['none', 'material', 'non_material']],
                        ],
                    ],
                ],
                'focus_proposal' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['foreground_journey_id', 'focused_resource_ids', 'pending_question_id', 'reason'],
                    'properties' => [
                        'foreground_journey_id' => ['oneOf' => [$opaque, ['type' => 'null']]],
                        'focused_resource_ids' => ['type' => 'array', 'maxItems' => 20, 'items' => $opaque],
                        'pending_question_id' => ['oneOf' => [$opaque, ['type' => 'null']]],
                        'reason' => ['type' => 'string', 'maxLength' => 400],
                    ],
                ],
                'short_reply_binding' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['state', 'target_question_id', 'target_resource_ids', 'proposed_value', 'confidence', 'requires_server_validation'],
                    'properties' => [
                        'state' => ['enum' => ['not_applicable', 'proposed', 'ambiguous', 'unresolved']],
                        'target_question_id' => ['oneOf' => [$opaque, ['type' => 'null']]],
                        'target_resource_ids' => ['type' => 'array', 'maxItems' => 20, 'items' => $opaque],
                        'proposed_value' => [],
                        'confidence' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1],
                        'requires_server_validation' => ['const' => true],
                    ],
                ],
                'missing_information' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'maxLength' => 160]],
                'ambiguities' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'maxLength' => 400]],
                'risk' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['level', 'confirmation_class', 'reasons'],
                    'properties' => [
                        'level' => ['enum' => ['none', 'low', 'medium', 'high', 'critical']],
                        'confirmation_class' => ['enum' => ['none', 'ordinary_write', 'sensitive_write']],
                        'reasons' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'maxLength' => 240]],
                    ],
                ],
                'field_confidence' => ['type' => 'object', 'maxProperties' => 200, 'additionalProperties' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function planSchema(): array
    {
        $opaque = ['type' => 'string', 'minLength' => 1, 'maxLength' => 191, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]*$'];
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['schema_version', 'plan_id', 'steps', 'stop_conditions', 'fallback', 'budgets'],
            'properties' => [
                'schema_version' => ['const' => '1.0.0'],
                'plan_id' => $opaque,
                'steps' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 30,
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['step_id', 'order', 'kind', 'tool_name', 'tool_version', 'proposed_arguments', 'classification', 'depends_on', 'confirmation_requirement', 'on_success', 'on_failure'],
                        'properties' => [
                            'step_id' => $opaque,
                            'order' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30],
                            'kind' => ['enum' => self::PLAN_KINDS],
                            'tool_name' => ['type' => ['string', 'null'], 'pattern' => '^[a-z_]+\\.[a-z_]+$'],
                            'tool_version' => ['type' => ['string', 'null'], 'pattern' => '^[1-9][0-9]*\\.[0-9]+\\.[0-9]+$'],
                            'proposed_arguments' => ['type' => ['object', 'null'], 'maxProperties' => 100],
                            'classification' => ['enum' => ['read', 'write', 'sensitive_write', 'advisory', 'conversation']],
                            'depends_on' => ['type' => 'array', 'maxItems' => 20, 'uniqueItems' => true, 'items' => $opaque],
                            'confirmation_requirement' => ['enum' => ['none', 'preview_and_confirm', 'already_confirmed_requires_validation']],
                            'on_success' => ['type' => 'string', 'maxLength' => 240],
                            'on_failure' => ['enum' => ['stop', 'clarify', 'safe_fallback', 'handoff', 'repair_within_budget']],
                        ],
                    ],
                ],
                'stop_conditions' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 30, 'items' => ['type' => 'string', 'maxLength' => 240]],
                'fallback' => ['enum' => ['clarify', 'truthful_block', 'declared_degraded_mode', 'human_handoff']],
                'budgets' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['max_provider_calls', 'max_tool_calls', 'max_repair_loops', 'deadline_ms'],
                    'properties' => [
                        'max_provider_calls' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8],
                        'max_tool_calls' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 32],
                        'max_repair_loops' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3],
                        'deadline_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 120000],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function componentIntentionSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['type', 'evidence_call_id', 'label', 'choices', 'product_targets'],
            'properties' => [
                'type' => ['enum' => ['product', 'comparison', 'cart', 'checkout', 'order', 'crm', 'payment_review', 'branch', 'hours', 'notice', 'choices']],
                'evidence_call_id' => ['type' => ['string', 'null'], 'maxLength' => 80],
                'label' => ['type' => 'string', 'maxLength' => 240],
                'product_targets' => [
                    'type' => 'array', 'maxItems' => 4, 'uniqueItems' => true,
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['product_id', 'variation_id'],
                        'properties' => [
                            'product_id' => ['type' => 'integer', 'minimum' => 1],
                            'variation_id' => ['type' => 'integer', 'minimum' => 0],
                        ],
                    ],
                ],
                'choices' => [
                    'type' => 'array', 'maxItems' => 8,
                    'items' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'required' => ['choice_id', 'label', 'semantic_value', 'side_effect'],
                        'properties' => [
                            'choice_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
                            'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                            'semantic_value' => [],
                            'side_effect' => ['const' => false],
                        ],
                    ],
                ],
            ],
        ];
    }

}
