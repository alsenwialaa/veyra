<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\Shared\Domain\CanonicalJson;

/**
 * Projects internal ToolResult objects into the only envelope providers see.
 *
 * Data is allowlisted by the registered tool's closed output schema. A
 * registered tool without such a schema may still transmit an empty
 * failure/denial envelope, but non-empty data is blocked. Correlation IDs,
 * dynamic object keys, and all unlisted fields are intentionally absent from
 * the provider projection.
 */
final class ProviderSafeToolResultProjector
{
    public const SCHEMA_VERSION = 'veyra.provider_tool_result.v1';
    private const MAX_RESULTS = 100;
    private const MAX_TOTAL_BYTES = 524288;

    private readonly ToolInputValidator $validator;
    private readonly ProviderProhibitedDataRedactor $redactor;

    public function __construct(
        ?ToolInputValidator $validator = null,
        ?ProviderProhibitedDataRedactor $redactor = null
    ) {
        $this->validator = $validator ?? new ToolInputValidator();
        $this->redactor = $redactor ?? new ProviderProhibitedDataRedactor();
    }

    /**
     * @param list<ToolResult> $results
     * @return list<array<string,mixed>>
     */
    public function projectMany(array $results, ToolRegistry $registry): array
    {
        if (!array_is_list($results) || count($results) > self::MAX_RESULTS) {
            throw new ProviderToolResultProjectionException('provider_tool_result_projection_limit_exceeded');
        }

        $projected = [];
        $seen = [];
        foreach ($results as $result) {
            if (!$result instanceof ToolResult) {
                throw new ProviderToolResultProjectionException('provider_tool_result_projection_type_invalid');
            }
            if (isset($seen[$result->callId])) {
                throw new ProviderToolResultProjectionException('provider_tool_result_projection_duplicate_call');
            }
            $seen[$result->callId] = true;
            $projected[] = $this->project($result, $registry);
        }

        try {
            if (strlen(CanonicalJson::encode($projected)) > self::MAX_TOTAL_BYTES) {
                throw new ProviderToolResultProjectionException('provider_tool_result_projection_limit_exceeded');
            }
        } catch (ProviderToolResultProjectionException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ProviderToolResultProjectionException('provider_tool_result_projection_encoding_failed');
        }

        return $projected;
    }

    /** @return array<string,mixed> */
    public function project(ToolResult $result, ToolRegistry $registry): array
    {
        $this->assertEnvelope($result);
        $emptySchema = [
            'type' => 'object',
            'additionalProperties' => false,
            'maxProperties' => 0,
            'properties' => [],
        ];
        $schema = $emptySchema;
        $data = [];
        $internalPendingQuestion = $result->tool === 'conversation.consume_pending_question';

        if ($internalPendingQuestion) {
            if ($result->toolVersion !== '1.0.0') {
                throw new ProviderToolResultProjectionException('provider_tool_result_projection_not_allowlisted');
            }
        } elseif ($registry->providerProjectionProfile($result->tool, $result->toolVersion) === null) {
            // Even an empty result is not provider data merely because it has a
            // syntactically plausible envelope. Registration, model visibility,
            // and exact version matching are mandatory.
            throw new ProviderToolResultProjectionException('provider_tool_result_projection_not_allowlisted');
        }

        if ($result->data !== []) {
            if ($internalPendingQuestion) {
                [$data, $schema] = $this->projectPendingQuestionConsumption($result->data);
            } else {
                $profile = $registry->providerProjectionProfile($result->tool, $result->toolVersion);
                $candidateSchema = is_array($profile['output_schema'] ?? null)
                    ? $profile['output_schema']
                    : [];
                if ($candidateSchema === [] || !$this->closedSchema($candidateSchema)) {
                    throw new ProviderToolResultProjectionException('provider_tool_result_projection_not_allowlisted');
                }
                if (!$this->validator->validateValue($result->data, $candidateSchema)) {
                    throw new ProviderToolResultProjectionException('provider_tool_result_projection_contract_invalid');
                }
                $schema = $candidateSchema;
                $data = $this->projectNode($result->data, $schema, 0);
                if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                    throw new ProviderToolResultProjectionException('provider_tool_result_projection_contract_invalid');
                }
            }
        }

        try {
            $redacted = $this->redactor->redact($data);
            $safeData = $redacted['value'];
            if (!is_array($safeData) || ($safeData !== [] && array_is_list($safeData))) {
                throw new ProviderToolResultProjectionException('provider_tool_result_projection_contract_invalid');
            }
            if (!$this->validator->validateValue($safeData, $schema)) {
                throw new ProviderToolResultProjectionException('provider_tool_result_projection_redaction_contract_invalid');
            }
            if (!$this->redactor->isAlreadySafe([
                'call_id' => $result->callId,
                'tool' => $result->tool,
                'tool_version' => $result->toolVersion,
                'status' => $result->status,
                'code' => $result->code,
                'changed_resources' => $result->changedResources,
            ])) {
                throw new ProviderToolResultProjectionException('provider_tool_result_projection_metadata_prohibited');
            }
            $schemaHash = hash('sha256', CanonicalJson::encode($schema));
        } catch (ProviderToolResultProjectionException $error) {
            throw $error;
        } catch (ProviderDataPolicyException $error) {
            throw new ProviderToolResultProjectionException($error->reasonCode);
        } catch (\Throwable) {
            throw new ProviderToolResultProjectionException('provider_tool_result_projection_encoding_failed');
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'call_id' => $result->callId,
            'tool' => $result->tool,
            'tool_version' => $result->toolVersion,
            'status' => $result->status,
            'code' => $result->code,
            'data' => $safeData,
            'data_schema_hash' => $schemaHash,
            'changed_resources' => array_values($result->changedResources),
            'authoritative' => $result->authoritative,
            'retry_safe' => $result->retrySafe,
            'redactions' => $redacted['redactions'],
        ];
    }

    /** Validate the closed wire envelope at the last outbound boundary. */
    public function validateProjectedList(mixed $results): bool
    {
        if (!is_array($results) || !array_is_list($results) || count($results) > self::MAX_RESULTS) {
            return false;
        }
        $seen = [];
        foreach ($results as $result) {
            if (!is_array($result) || array_is_list($result)
                || !$this->exactKeys($result, [
                    'schema_version', 'call_id', 'tool', 'tool_version', 'status', 'code',
                    'data', 'data_schema_hash', 'changed_resources', 'authoritative',
                    'retry_safe', 'redactions',
                ])
                || ($result['schema_version'] ?? null) !== self::SCHEMA_VERSION
                || !$this->opaque($result['call_id'] ?? null, 191)
                || !$this->toolName($result['tool'] ?? null)
                || !$this->version($result['tool_version'] ?? null)
                || !in_array($result['status'] ?? null, ['succeeded', 'failed', 'partial', 'blocked', 'stale', 'uncertain'], true)
                || !is_string($result['code'] ?? null)
                || preg_match('/^[a-z][a-z0-9_]{0,119}$/D', $result['code']) !== 1
                || !is_array($result['data'] ?? null)
                || (($result['data'] ?? []) !== [] && array_is_list($result['data']))
                || !is_string($result['data_schema_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $result['data_schema_hash']) !== 1
                || !is_bool($result['authoritative'] ?? null)
                || !is_bool($result['retry_safe'] ?? null)
                || !$this->stringList($result['changed_resources'] ?? null, 100, 512, false)
                || !$this->stringList($result['redactions'] ?? null, 16, 80, true)
                || isset($seen[$result['call_id']])
            ) {
                return false;
            }
            $seen[$result['call_id']] = true;
            try {
                if (!$this->redactor->isAlreadySafe($result)) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }
        try {
            return strlen(CanonicalJson::encode($results)) <= self::MAX_TOTAL_BYTES;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $data @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function projectPendingQuestionConsumption(array $data): array
    {
        if (!$this->exactKeys($data, ['question_id', 'binding_id', 'customer_message_id', 'validated_value'])
            || !$this->opaque($data['question_id'] ?? null, 191)
            || !$this->opaque($data['binding_id'] ?? null, 191)
            || !$this->opaque($data['customer_message_id'] ?? null, 191)
        ) {
            throw new ProviderToolResultProjectionException('provider_tool_result_projection_contract_invalid');
        }
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['question_id', 'binding_id', 'customer_message_id'],
            'properties' => [
                'question_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'binding_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                'customer_message_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
            ],
        ];

        return [[
            'question_id' => $data['question_id'],
            'binding_id' => $data['binding_id'],
            'customer_message_id' => $data['customer_message_id'],
        ], $schema];
    }

    private function assertEnvelope(ToolResult $result): void
    {
        if ($result->schemaVersion !== '1.0.0'
            || !$this->opaque($result->callId, 191)
            || !$this->toolName($result->tool)
            || !$this->version($result->toolVersion)
            || preg_match('/^[a-z][a-z0-9_]{0,119}$/D', $result->code) !== 1
            || !$this->stringList($result->changedResources, 100, 512, false)
        ) {
            throw new ProviderToolResultProjectionException('provider_tool_result_projection_envelope_invalid');
        }
    }

    private function closedSchema(array $schema, int $depth = 0): bool
    {
        if ($depth > 16) {
            return false;
        }
        if (isset($schema['oneOf'])) {
            if (!is_array($schema['oneOf']) || !array_is_list($schema['oneOf']) || $schema['oneOf'] === []) {
                return false;
            }
            foreach ($schema['oneOf'] as $candidate) {
                if (!is_array($candidate) || !$this->closedSchema($candidate, $depth + 1)) {
                    return false;
                }
            }
        }
        $types = $schema['type'] ?? null;
        $types = is_array($types) && array_is_list($types) ? $types : [$types];
        $object = in_array('object', $types, true)
            || isset($schema['properties']) || array_key_exists('additionalProperties', $schema);
        if ($object) {
            $additional = $schema['additionalProperties'] ?? null;
            // A schema-valued additionalProperties contract validates values
            // but still lets arbitrary provider-visible field names through.
            // Provider projection requires an exact, recursively allowlisted
            // field set, so every object node must be closed with literal
            // additionalProperties=false.
            if ($additional !== false) {
                return false;
            }
            foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $property) {
                if (!is_array($property) || !$this->closedSchema($property, $depth + 1)) {
                    return false;
                }
            }
        }
        if (in_array('array', $types, true)) {
            if (!is_array($schema['items'] ?? null) || !$this->closedSchema($schema['items'], $depth + 1)) {
                return false;
            }
        }
        return true;
    }

    private function projectNode(mixed $value, array $schema, int $depth): mixed
    {
        if ($depth > 16) {
            throw new ProviderToolResultProjectionException('provider_tool_result_projection_limit_exceeded');
        }
        if (isset($schema['oneOf'])) {
            $matches = [];
            foreach ($schema['oneOf'] as $candidate) {
                if (is_array($candidate) && $this->validator->validateValue($value, $candidate)) {
                    $matches[] = $candidate;
                }
            }
            if (count($matches) !== 1) {
                throw new ProviderToolResultProjectionException('provider_tool_result_projection_contract_invalid');
            }
            return $this->projectNode($value, $matches[0], $depth + 1);
        }
        if (!is_array($value)) {
            return $value;
        }
        $types = $schema['type'] ?? null;
        $types = is_array($types) && array_is_list($types) ? $types : [$types];
        $schemaObject = in_array('object', $types, true)
            || isset($schema['properties']) || array_key_exists('additionalProperties', $schema);
        $schemaArray = in_array('array', $types, true);

        // PHP uses [] for both an empty JSON object and an empty JSON array.
        // Resolve that representation from the certified schema, not from
        // array_is_list(), so closed empty object fields remain projectable.
        if (array_is_list($value) && ($value !== [] || ($schemaArray && !$schemaObject))) {
            $items = $schema['items'] ?? null;
            if (!is_array($items)) {
                throw new ProviderToolResultProjectionException('provider_tool_result_projection_schema_open');
            }
            return array_map(fn (mixed $item): mixed => $this->projectNode($item, $items, $depth + 1), $value);
        }
        if (!$schemaObject) {
            throw new ProviderToolResultProjectionException('provider_tool_result_projection_contract_invalid');
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $additional = $schema['additionalProperties'] ?? null;
        $projected = [];
        foreach ($value as $key => $item) {
            $child = $properties[$key] ?? $additional;
            if (!is_string($key) || !is_array($child)) {
                throw new ProviderToolResultProjectionException('provider_tool_result_projection_schema_open');
            }
            $projected[$key] = $this->projectNode($item, $child, $depth + 1);
        }
        return $projected;
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        return $actual === $keys;
    }

    private function opaque(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }

    private function toolName(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 160
            && preg_match('/^[a-z][a-z0-9_]{0,63}\.[a-z][a-z0-9_]{0,95}$/D', $value) === 1;
    }

    private function version(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 32
            && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?$/D', $value) === 1;
    }

    private function stringList(mixed $value, int $maximumItems, int $maximumBytes, bool $identifierOnly): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximumItems) {
            return false;
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item) || $item === '' || strlen($item) > $maximumBytes
                || preg_match('//u', $item) !== 1 || isset($seen[$item])
                || ($identifierOnly && preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $item) !== 1)
            ) {
                return false;
            }
            $seen[$item] = true;
        }
        return true;
    }
}
