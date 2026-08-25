<?php
declare(strict_types=1);

namespace Veyra\AI\Orchestration;

use Veyra\AI\Contract\ToolResult;

final class ResponseVerifier
{
    private const MATERIAL_TYPES = [
        'product', 'variation', 'price', 'stock', 'discount', 'shipping', 'tax', 'fee', 'total',
        'payment', 'order', 'mutation_success', 'case', 'payment_review', 'tracking', 'business_status',
    ];

    private const MATERIAL_COMPONENT_TYPES = [
        'product', 'comparison', 'cart', 'checkout', 'order', 'crm', 'payment_review', 'branch', 'hours',
    ];

    private const VALUE_TYPES = ['string', 'integer', 'number', 'boolean', 'null', 'money', 'resource'];

    /**
     * @param array<string, mixed> $payload
     * @param array<int, ToolResult> $toolResults
     * @return array{valid:bool,errors:array<int,string>,evidence:array<int,array<string,mixed>>}
     */
    public function verify(array $payload, array $toolResults): array
    {
        $byCall = [];
        foreach ($toolResults as $result) {
            $byCall[$result->callId] = $result;
        }

        $errors = [];
        $evidence = [];
        $claims = $payload['claims'] ?? [];
        if (!is_array($claims) || !array_is_list($claims)) {
            $claims = [];
            $errors[] = 'claims_invalid';
        }
        if ($claims === [] && $this->requiresClaims($payload, $toolResults)) {
            $errors[] = 'claims_required_for_post_tool_response';
        }

        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                $errors[] = 'claim_invalid:unknown';
                continue;
            }
            $claimId = is_string($claim['claim_id'] ?? null) && $claim['claim_id'] !== ''
                ? $claim['claim_id']
                : 'unknown';
            $type = is_string($claim['type'] ?? null) ? $claim['type'] : '';
            $status = is_string($claim['status'] ?? null) ? $claim['status'] : '';
            $callId = is_string($claim['source_call_id'] ?? null) ? $claim['source_call_id'] : '';

            if ($status === 'verified') {
                $result = $byCall[$callId] ?? null;
                if (!$result instanceof ToolResult || $result->status !== 'succeeded' || !$result->authoritative) {
                    $errors[] = 'claim_without_authoritative_source:' . $claimId;
                    continue;
                }

                $sourcePath = is_string($claim['source_path'] ?? null) ? $claim['source_path'] : '';
                if (!$this->validSourcePath($sourcePath)) {
                    $errors[] = 'claim_source_path_invalid:' . $claimId;
                    continue;
                }
                if ($type === 'mutation_success') {
                    if ($result->changedResources === []) {
                        $errors[] = 'mutation_claim_without_changed_resource:' . $claimId;
                        continue;
                    }
                    if (preg_match('#^/changed_resources/(?:0|[1-9][0-9]*)$#D', $sourcePath) !== 1) {
                        $errors[] = 'mutation_claim_source_not_changed_resource:' . $claimId;
                        continue;
                    }
                }

                $resolved = $this->resolveSourcePath($result, $sourcePath);
                if (!$resolved['found']) {
                    $errors[] = 'claim_source_path_not_found:' . $claimId;
                    continue;
                }

                $asserted = $claim['asserted_value'] ?? null;
                if (!$this->validAssertedValue($asserted, $status)) {
                    $errors[] = 'claim_asserted_value_invalid:' . $claimId;
                    continue;
                }
                /** @var array{kind:string,string_value:?string,integer_value:?int,number_value:int|float|null,boolean_value:?bool,currency:?string,currency_source_path:?string} $asserted */
                if ($type === 'mutation_success' && $asserted['kind'] !== 'resource') {
                    $errors[] = 'mutation_claim_assertion_not_resource:' . $claimId;
                    continue;
                }
                if (!$this->sourceMatchesType($resolved['value'], $asserted['kind'])) {
                    $errors[] = 'claim_type_mismatch:' . $claimId;
                    continue;
                }
                if ($resolved['value'] !== $this->assertedScalar($asserted)) {
                    $errors[] = 'claim_value_mismatch:' . $claimId;
                    continue;
                }

                if ($asserted['kind'] === 'money') {
                    $currencyPath = $asserted['currency_source_path'];
                    if (!is_string($currencyPath) || !$this->validSourcePath($currencyPath, true)) {
                        $errors[] = 'claim_currency_source_path_invalid:' . $claimId;
                        continue;
                    }
                    $currency = $this->resolveSourcePath($result, $currencyPath);
                    if (!$currency['found']) {
                        $errors[] = 'claim_currency_source_path_not_found:' . $claimId;
                        continue;
                    }
                    if (!is_string($currency['value']) || preg_match('/^[A-Z]{3}$/D', $currency['value']) !== 1) {
                        $errors[] = 'claim_currency_type_mismatch:' . $claimId;
                        continue;
                    }
                    if ($currency['value'] !== $asserted['currency']) {
                        $errors[] = 'claim_currency_mismatch:' . $claimId;
                        continue;
                    }
                }

                $evidence[] = [
                    'claim_id' => $claimId,
                    'type' => $type,
                    'source_call_id' => $callId,
                    'source_tool' => $result->tool,
                    'source_path' => $sourcePath,
                    'asserted_value' => $asserted,
                    'correlation_id' => $result->correlationId,
                ];
            }

            if (in_array($type, self::MATERIAL_TYPES, true) && $status === 'unknown' && $callId !== '') {
                $errors[] = 'unknown_claim_must_not_cite_success:' . $claimId;
            }
        }

        $components = $payload['reply']['components'] ?? [];
        if (!is_array($components) || !array_is_list($components)) {
            $errors[] = 'components_invalid';
            $components = [];
        }
        foreach ($components as $index => $component) {
            if (!is_array($component) || !is_string($component['type'] ?? null)) {
                $errors[] = 'component_invalid:' . $index;
                continue;
            }
            if (in_array($component['type'], self::MATERIAL_COMPONENT_TYPES, true)) {
                $callId = is_string($component['evidence_call_id'] ?? null) ? $component['evidence_call_id'] : '';
                $result = $byCall[$callId] ?? null;
                if (!$result instanceof ToolResult || $result->status !== 'succeeded' || !$result->authoritative) {
                    $errors[] = 'component_without_authoritative_source:' . $index;
                }
            }
        }

        return ['valid' => $errors === [], 'errors' => array_values(array_unique($errors)), 'evidence' => $evidence];
    }

    /** @param array<string, mixed> $payload @param array<int, ToolResult> $toolResults */
    private function requiresClaims(array $payload, array $toolResults): bool
    {
        $hasObservedSuccessOrChange = false;
        foreach ($toolResults as $result) {
            if ($result->status === 'succeeded' || $result->changedResources !== []) {
                $hasObservedSuccessOrChange = true;
                break;
            }
        }
        if (!$hasObservedSuccessOrChange) {
            return false;
        }

        $text = $payload['reply']['text'] ?? '';
        if (is_string($text) && trim($text) !== '') {
            return true;
        }
        $components = $payload['reply']['components'] ?? [];
        if (!is_array($components)) {
            return false;
        }
        foreach ($components as $component) {
            if (is_array($component) && in_array($component['type'] ?? null, self::MATERIAL_COMPONENT_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    private function validSourcePath(string $path, bool $dataOnly = false): bool
    {
        if ($path === '' || strlen($path) > 512 || substr_count($path, '/') > 32) {
            return false;
        }
        if ($dataOnly ? !str_starts_with($path, '/data') : preg_match('#^/(?:data|changed_resources)(?:/|$)#D', $path) !== 1) {
            return false;
        }
        if ($dataOnly && preg_match('#^/data(?:/|$)#D', $path) !== 1) {
            return false;
        }
        foreach (explode('/', substr($path, 1)) as $token) {
            if (preg_match('/~(?:[^01]|$)/', $token) === 1) {
                return false;
            }
        }

        return true;
    }

    /** @return array{found:bool,value:mixed} */
    private function resolveSourcePath(ToolResult $result, string $path): array
    {
        $current = ['data' => $result->data, 'changed_resources' => $result->changedResources];
        foreach (explode('/', substr($path, 1)) as $encodedToken) {
            $token = str_replace(['~1', '~0'], ['/', '~'], $encodedToken);
            if (!is_array($current)) {
                return ['found' => false, 'value' => null];
            }
            if (array_is_list($current)) {
                if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $token) !== 1) {
                    return ['found' => false, 'value' => null];
                }
                $index = filter_var($token, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($index === false || !array_key_exists($index, $current)) {
                    return ['found' => false, 'value' => null];
                }
                $current = $current[$index];
                continue;
            }
            if (!array_key_exists($token, $current)) {
                return ['found' => false, 'value' => null];
            }
            $current = $current[$token];
        }

        return ['found' => true, 'value' => $current];
    }

    private function validAssertedValue(mixed $asserted, string $status): bool
    {
        $required = [
            'kind', 'string_value', 'integer_value', 'number_value', 'boolean_value',
            'currency', 'currency_source_path',
        ];
        if (!is_array($asserted)
            || array_diff(array_keys($asserted), $required) !== []
            || array_diff($required, array_keys($asserted)) !== []
            || !is_string($asserted['kind'] ?? null)
            || !in_array($asserted['kind'], self::VALUE_TYPES, true)
        ) {
            return false;
        }

        $valueType = $asserted['kind'];
        if ($valueType === 'money') {
            return is_string($asserted['string_value'])
                && strlen($asserted['string_value']) <= 64
                && preg_match('/^-?\d+(?:\.\d+)?$/D', $asserted['string_value']) === 1
                && $asserted['integer_value'] === null
                && $asserted['number_value'] === null
                && $asserted['boolean_value'] === null
                && is_string($asserted['currency'])
                && preg_match('/^[A-Z]{3}$/D', $asserted['currency']) === 1
                && ($status !== 'verified' || is_string($asserted['currency_source_path']));
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

    private function sourceMatchesType(mixed $source, string $valueType): bool
    {
        return match ($valueType) {
            'string' => is_string($source),
            'integer' => is_int($source),
            'number' => (is_int($source) || is_float($source)) && is_finite((float) $source),
            'boolean' => is_bool($source),
            'null' => $source === null,
            'money' => is_string($source) && strlen($source) <= 64 && preg_match('/^-?\d+(?:\.\d+)?$/D', $source) === 1,
            'resource' => is_string($source) && $source !== '' && strlen($source) <= 512,
            default => false,
        };
    }

    /** @param array{kind:string,string_value:?string,integer_value:?int,number_value:int|float|null,boolean_value:?bool,currency:?string,currency_source_path:?string} $asserted */
    private function assertedScalar(array $asserted): mixed
    {
        return match ($asserted['kind']) {
            'string', 'money', 'resource' => $asserted['string_value'],
            'integer' => $asserted['integer_value'],
            'number' => $asserted['number_value'],
            'boolean' => $asserted['boolean_value'],
            'null' => null,
            default => null,
        };
    }
}
