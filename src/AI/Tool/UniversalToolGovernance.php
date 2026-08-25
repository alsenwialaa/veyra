<?php
declare(strict_types=1);

namespace Veyra\AI\Tool;

/**
 * Catalog-backed policy gate shared by discovery and execution.
 *
 * The catalog never grants authority by itself. It can only narrow a runtime
 * definition and the current server-resolved actor/feature context.
 */
final class UniversalToolGovernance
{
    /** @param array<string, array<string, mixed>> $contracts */
    private function __construct(
        private readonly array $contracts,
        private readonly bool $catalogValid,
        private readonly bool $enabled
    ) {
    }

    public static function disabled(): self
    {
        return new self([], true, false);
    }

    public static function fromCatalog(string $path): self
    {
        if (!is_readable($path)) {
            return new self([], false, true);
        }
        $raw = file_get_contents($path);
        $decoded = is_string($raw) ? json_decode($raw, true, 64) : null;
        if (!is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== '1.0.0'
            || !is_int($decoded['required_tool_count'] ?? null)
            || !is_int($decoded['actual_tool_count'] ?? null)
            || !is_array($decoded['tools'] ?? null)
            || !array_is_list($decoded['tools'])
            || $decoded['required_tool_count'] !== 155
            || $decoded['actual_tool_count'] !== count($decoded['tools'])
        ) {
            return new self([], false, true);
        }

        $contracts = [];
        foreach ($decoded['tools'] as $tool) {
            if (!self::validContract($tool) || isset($contracts[$tool['name']])) {
                return new self([], false, true);
            }
            $contracts[$tool['name']] = $tool;
        }
        if (count($contracts) !== 155) {
            return new self([], false, true);
        }
        return new self($contracts, true, true);
    }

    public function definitionCode(ToolDefinition $definition): string
    {
        if (!$this->enabled) {
            return 'ok';
        }
        if (!$this->catalogValid) {
            return 'tool_catalog_invalid';
        }
        $contract = $this->contracts[$definition->name] ?? null;
        if (!is_array($contract)) {
            return 'tool_contract_missing';
        }
        if (!in_array($contract['design_status'] ?? null, ['tested', 'accepted'], true)) {
            return 'tool_contract_not_certified';
        }
        if (($contract['version'] ?? null) !== $definition->version) {
            return 'tool_contract_version_mismatch';
        }
        if (($contract['classification'] ?? null) !== $definition->classification) {
            return 'tool_contract_classification_mismatch';
        }
        if (($definition->inputSchema['type'] ?? null) !== 'object'
            || ($definition->inputSchema['additionalProperties'] ?? null) !== false
        ) {
            return 'tool_input_contract_not_closed';
        }
        if (!self::closedOutputSchema($definition->outputSchema)) {
            return 'tool_output_contract_not_closed';
        }
        return 'ok';
    }

    public function authorizationCode(ToolDefinition $definition, ToolContext $context): string
    {
        $definitionCode = $this->definitionCode($definition);
        if ($definitionCode !== 'ok' || !$this->enabled) {
            return $definitionCode;
        }
        $contract = $this->contracts[$definition->name];
        $exposure = $contract['model_exposure'];
        if (($exposure['eligible'] ?? false) !== true) {
            return 'tool_not_model_eligible';
        }
        $authorization = $contract['authorization'];
        $actor = $context->actorType === 'reviewer' ? 'payment_reviewer' : $context->actorType;
        if (!in_array($actor, $authorization['allowed_actor_types'], true)) {
            return 'tool_actor_not_allowed';
        }
        if (($authorization['authentication_required'] ?? false) === true
            && ($context->userId === null || $context->actorType === 'guest')
        ) {
            return 'tool_authentication_required';
        }
        foreach (array_unique(array_merge($definition->capabilities, $authorization['required_capabilities'])) as $capability) {
            if (!is_string($capability) || !$context->hasCapability($capability)) {
                return 'tool_capability_required';
            }
        }
        $optional = ($contract['release_unit'] ?? null) === 'optional_module';
        foreach (array_unique(array_merge($definition->features, $contract['feature_keys'])) as $feature) {
            if (!is_string($feature)) {
                return 'tool_feature_unavailable';
            }
            $state = $context->featureStates[$feature] ?? 'Off';
            if (($optional && $state !== 'On')
                || (!$optional && !in_array($state, ['On', 'Degraded'], true))
            ) {
                return 'tool_feature_unavailable';
            }
        }
        return 'ok';
    }

    public function classification(string $name): ?string
    {
        $value = $this->contracts[$name]['classification'] ?? null;
        return is_string($value) ? $value : null;
    }

    private static function validContract(mixed $tool): bool
    {
        if (!is_array($tool)
            || !is_string($tool['name'] ?? null)
            || preg_match('/^[a-z_]+\.[a-z_]+$/D', $tool['name']) !== 1
            || ($tool['version'] ?? null) !== '1.0.0'
            || !in_array($tool['classification'] ?? null, ['read', 'write', 'sensitive_write', 'advisory'], true)
            || !in_array($tool['release_unit'] ?? null, ['production_core', 'optional_module'], true)
            || !is_array($tool['feature_keys'] ?? null)
            || !is_array($tool['model_exposure'] ?? null)
            || !is_array($tool['authorization'] ?? null)
            || !is_array($tool['input'] ?? null)
            || !is_array($tool['output'] ?? null)
        ) {
            return false;
        }
        $authorization = $tool['authorization'];
        return is_array($authorization['allowed_actor_types'] ?? null)
            && array_is_list($authorization['allowed_actor_types'])
            && is_bool($authorization['authentication_required'] ?? null)
            && is_array($authorization['required_capabilities'] ?? null)
            && ($tool['input']['additional_properties'] ?? null) === false
            && ($tool['input']['schema_version'] ?? null) === '1.0.0'
            && ($tool['output']['schema_version'] ?? null) === '1.0.0';
    }

    /**
     * A typed stale/refresh result may legitimately differ from successful
     * data. Every provider-visible object node must nevertheless have an exact
     * field set; schema-valued additionalProperties remains an open contract.
     *
     * @param array<string, mixed> $schema
     */
    private static function closedOutputSchema(array $schema): bool
    {
        $alternatives = $schema['oneOf'] ?? null;
        if ($alternatives !== null) {
            if (!is_array($alternatives) || !array_is_list($alternatives) || $alternatives === []) {
                return false;
            }
            foreach ($alternatives as $alternative) {
                if (!is_array($alternative)
                    || !self::objectSchema($alternative)
                    || !self::recursivelyClosedSchema($alternative, 0)
                ) {
                    return false;
                }
            }
            return true;
        }

        return self::objectSchema($schema) && self::recursivelyClosedSchema($schema, 0);
    }

    /** @param array<string, mixed> $schema */
    private static function recursivelyClosedSchema(array $schema, int $depth): bool
    {
        if ($depth > 16) {
            return false;
        }
        if (isset($schema['oneOf'])) {
            if (!is_array($schema['oneOf']) || !array_is_list($schema['oneOf']) || $schema['oneOf'] === []) {
                return false;
            }
            foreach ($schema['oneOf'] as $alternative) {
                if (!is_array($alternative) || !self::recursivelyClosedSchema($alternative, $depth + 1)) {
                    return false;
                }
            }
        }

        $types = $schema['type'] ?? null;
        $types = is_array($types) && array_is_list($types) ? $types : [$types];
        if (self::objectSchema($schema)) {
            if (($schema['additionalProperties'] ?? null) !== false) {
                return false;
            }
            foreach (is_array($schema['properties'] ?? null) ? $schema['properties'] : [] as $property) {
                if (!is_array($property) || !self::recursivelyClosedSchema($property, $depth + 1)) {
                    return false;
                }
            }
        }
        if (in_array('array', $types, true)) {
            if (!is_array($schema['items'] ?? null)
                || !self::recursivelyClosedSchema($schema['items'], $depth + 1)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $schema */
    private static function objectSchema(array $schema): bool
    {
        $types = $schema['type'] ?? null;
        $types = is_array($types) && array_is_list($types) ? $types : [$types];

        return in_array('object', $types, true)
            || isset($schema['properties'])
            || array_key_exists('additionalProperties', $schema);
    }
}
