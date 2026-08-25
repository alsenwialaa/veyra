<?php
declare(strict_types=1);

namespace Veyra\AI\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;

final class ToolRegistry
{
    /** @var array<string, ToolDefinition> */
    private array $definitions = [];
    /** @var array<string, ToolHandler> */
    private array $handlers = [];

    private readonly ToolResultValidator $resultValidator;
    private readonly UniversalToolGovernance $governance;

    public function __construct(
        private readonly ToolInputValidator $validator,
        ?ToolResultValidator $resultValidator = null,
        ?UniversalToolGovernance $governance = null
    ) {
        $this->resultValidator = $resultValidator ?? new ToolResultValidator();
        $this->governance = $governance ?? (defined('VEYRA_PLUGIN_DIR')
            ? UniversalToolGovernance::fromCatalog((string) constant('VEYRA_PLUGIN_DIR') . 'config/contracts/logical-tool-catalog.json')
            : UniversalToolGovernance::disabled());
    }

    public function register(ToolHandler $handler): void
    {
        foreach ($handler->definitions() as $definition) {
            if (isset($this->definitions[$definition->name])) {
                throw new \LogicException('Duplicate logical tool registration.');
            }
            $this->definitions[$definition->name] = $definition;
            $this->handlers[$definition->name] = $handler;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function modelTools(ToolContext $context, bool $allowMutations = true): array
    {
        $visible = [];
        foreach ($this->definitions as $definition) {
            if ($this->visibleForPlan($definition, $context, $allowMutations)) {
                $visible[] = $definition->forModel();
            }
        }
        return $visible;
    }

    /**
     * Provider-neutral catalog embedded as untrusted planning data. It adds the
     * server-owned classification which the strict decision contract requires.
     *
     * @return array<int, array<string, mixed>>
     */
    public function planningTools(ToolContext $context, bool $allowMutations = true): array
    {
        $visible = [];
        foreach ($this->definitions as $definition) {
            if (!$this->visibleForPlan($definition, $context, $allowMutations)) {
                continue;
            }
            $tool = $definition->forModel();
            $tool['classification'] = $definition->classification;
            $visible[] = $tool;
        }

        return $visible;
    }

    /** @return array{version:string,classification:string}|null */
    public function planProfile(string $toolName, ToolContext $context): ?array
    {
        $definition = $this->definitions[$toolName] ?? null;
        if (!$definition instanceof ToolDefinition || !$this->visibleForPlan($definition, $context, true)) {
            return null;
        }

        return ['version' => $definition->version, 'classification' => $definition->classification];
    }

    /** @return array{classification:string,accepts_idempotency_key:bool}|null */
    public function mutationProfile(string $toolName): ?array
    {
        $definition = $this->definitions[$toolName] ?? null;
        if (!$definition instanceof ToolDefinition
            || !in_array($definition->classification, ['write', 'sensitive_write'], true)
        ) {
            return null;
        }

        $properties = is_array($definition->inputSchema['properties'] ?? null)
            ? $definition->inputSchema['properties']
            : [];

        return [
            'classification' => $definition->classification,
            'accepts_idempotency_key' => array_key_exists('idempotency_key', $properties),
        ];
    }

    /**
     * Server-owned provider projection policy for one registered tool version.
     * An empty output schema is intentionally returned as-is: it means the
     * tool has no certified data projection and non-empty data must fail closed.
     *
     * @return array{classification:string,output_schema:array<string,mixed>}|null
     */
    public function providerProjectionProfile(string $toolName, string $toolVersion): ?array
    {
        $definition = $this->definitions[$toolName] ?? null;
        if (!$definition instanceof ToolDefinition
            || !$definition->modelVisible
            || $definition->version !== $toolVersion
        ) {
            return null;
        }

        return [
            'classification' => $definition->classification,
            'output_schema' => $definition->outputSchema,
        ];
    }

    public function execute(ToolCall $call, ToolContext $context, bool $allowMutations = true): ToolResult
    {
        $definition = $this->definitions[$call->name] ?? null;
        if (!$definition instanceof ToolDefinition || !isset($this->handlers[$call->name])) {
            return ToolResult::denied($call, 'tool_not_registered', $context->correlationId);
        }
        // This registry is the provider-facing execution boundary. A provider must
        // never be able to invoke a server-only tool merely by naming it directly.
        if (!$definition->modelVisible) {
            return ToolResult::denied($call, 'tool_not_model_visible', $context->correlationId);
        }
        if (!$allowMutations && in_array($definition->classification, ['write', 'sensitive_write'], true)) {
            return ToolResult::denied($call, 'turn_mutation_binding_required', $context->correlationId);
        }
        if ($definition->version !== $call->version) {
            return ToolResult::denied($call, 'tool_version_unsupported', $context->correlationId);
        }
        $governanceCode = $this->governance->authorizationCode($definition, $context);
        if ($governanceCode !== 'ok') {
            return ToolResult::denied($call, $governanceCode, $context->correlationId);
        }
        if (!$this->authorized($definition, $context)) {
            return ToolResult::denied($call, 'tool_not_authorized', $context->correlationId);
        }
        if (!$this->validator->validate($call->arguments, $definition->inputSchema)) {
            return ToolResult::denied($call, 'tool_input_invalid', $context->correlationId);
        }
        try {
            $handler = $this->handlers[$call->name];
            if ($handler instanceof ToolExecutionPreflight) {
                $preflight = $handler->beforeExecute($call, $context);
                if ($preflight instanceof ToolResult) {
                    return $this->validatedResult($preflight, $call, $definition, $context);
                }
            }

            return $this->validatedResult($handler->execute($call, $context), $call, $definition, $context);
        } catch (\Throwable $error) {
            // Raw exception messages can expose store or provider internals.
            // Once a write handler begins, an exception cannot prove that no
            // external or WooCommerce side effect occurred. Fail closed and
            // force authoritative reconciliation before any retry.
            if (in_array($definition->classification, ['write', 'sensitive_write'], true)) {
                return new ToolResult(
                    $call->callId,
                    $call->name,
                    'uncertain',
                    'tool_execution_outcome_uncertain',
                    [],
                    [],
                    true,
                    false,
                    $context->correlationId,
                    $call->version
                );
            }
            return ToolResult::failed($call, 'tool_execution_failed', $context->correlationId, false);
        }
    }

    private function validatedResult(
        ToolResult $result,
        ToolCall $call,
        ToolDefinition $definition,
        ToolContext $context
    ): ToolResult {
        if ($this->resultValidator->validate($result, $call, $definition, $context->correlationId)) {
            return $result;
        }
        if (in_array($definition->classification, ['write', 'sensitive_write'], true)) {
            return new ToolResult(
                $call->callId,
                $call->name,
                'uncertain',
                'tool_output_contract_invalid',
                [],
                [],
                true,
                false,
                $context->correlationId,
                $call->version
            );
        }
        return ToolResult::failed($call, 'tool_output_contract_invalid', $context->correlationId, false);
    }

    private function authorized(ToolDefinition $definition, ToolContext $context): bool
    {
        if (!in_array($context->actorType, $definition->actors, true)) {
            return false;
        }
        foreach ($definition->capabilities as $capability) {
            if (!$context->hasCapability($capability)) {
                return false;
            }
        }
        foreach ($definition->features as $feature) {
            if (!$context->featureIsAvailable($feature)) {
                return false;
            }
        }
        return true;
    }

    private function visibleForPlan(ToolDefinition $definition, ToolContext $context, bool $allowMutations): bool
    {
        return $definition->modelVisible
            && ($allowMutations || !in_array($definition->classification, ['write', 'sensitive_write'], true))
            && $this->authorized($definition, $context)
            && $this->governance->authorizationCode($definition, $context) === 'ok';
    }
}
