<?php
declare(strict_types=1);

namespace Veyra\Recommendation\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\Recommendation\Application\RecommendationService;
use Veyra\Requirements\Application\RequirementStateService;

final class RecommendationToolHandler implements ToolHandler
{
    public function __construct(
        private readonly RecommendationService $recommendations,
        private readonly RequirementStateService $requirementStates
    ) {
    }

    public function definitions(): array
    {
        $actors = ['guest', 'customer'];
        $features = ['commerce_product_assistance'];
        $productIds = [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 40,
            'uniqueItems' => true,
            'items' => ['type' => 'integer', 'minimum' => 1],
        ];
        $requirementStateReference = [
            'expected_requirements_resource_version' => ['type' => 'integer', 'minimum' => 0],
            'expected_requirements_state_hash' => [
                'type' => 'string',
                'minLength' => 64,
                'maxLength' => 64,
                'pattern' => '^[a-f0-9]{64}$',
            ],
        ];
        $boundProducts = array_merge(['product_ids' => $productIds], $requirementStateReference);
        $boundRequired = [
            'product_ids',
            'expected_requirements_resource_version',
            'expected_requirements_state_hash',
        ];
        return [
            $this->definition(
                'recommendation.retrieve_candidates',
                'Hydrate a bounded set of exact product candidate ids from current public WooCommerce state; never run an implicit search or choose the first result.',
                'advisory',
                ['product_ids' => $productIds],
                ['product_ids'],
                $actors,
                $features
            ),
            $this->definition(
                'recommendation.apply_hard_filters',
                'Deterministically enforce current visibility, purchasability, stock and actor-owned active hard requirements against exact product ids; unsupported evidence fails closed.',
                'advisory',
                $boundProducts,
                $boundRequired,
                $actors,
                $features
            ),
            $this->definition(
                'recommendation.rank',
                'Rank exact current candidates using actor-owned active requirement records and an explicitly published merchant weight policy; no caller scores or free-text keyword scoring.',
                'advisory',
                $boundProducts,
                $boundRequired,
                $actors,
                $features
            ),
            $this->definition(
                'recommendation.diversify',
                'Server-rank and diversify exact candidate ids across current category, product type and price band while preserving ties; caller-supplied scores are never accepted.',
                'advisory',
                [
                    'product_ids' => $productIds,
                    'expected_requirements_resource_version' => $requirementStateReference['expected_requirements_resource_version'],
                    'expected_requirements_state_hash' => $requirementStateReference['expected_requirements_state_hash'],
                    'limit' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 8],
                ],
                array_merge($boundRequired, ['limit']),
                $actors,
                $features
            ),
            $this->definition(
                'recommendation.explain',
                'Return evidence-linked structured fit, mismatch, unknown and trade-off facts for exact product ids against actor-owned active requirements; it never invents compatibility or prose.',
                'advisory',
                $boundProducts,
                $boundRequired,
                $actors,
                $features
            ),
            // Tuning is an optional release unit and durable tuning is expressly
            // absent from this implementation. The contract remains typed but
            // cannot be shown to or invoked by the model.
            $this->definition(
                'recommendation.tune',
                'Optional shopper recommendation tuning; unavailable until separately certified and published.',
                'advisory',
                ['adjustments' => ['type' => 'array', 'maxItems' => 12]],
                ['adjustments'],
                $actors,
                ['commerce_product_assistance', 'shopper_recommendation_tuning'],
                false
            ),
        ];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        if ($call->name === 'recommendation.tune') {
            return $this->advisoryResult($call, $context, 'blocked', 'optional_module_not_certified');
        }
        $args = $call->arguments;

        if ($call->name === 'recommendation.retrieve_candidates') {
            $result = $this->recommendations->retrieve(
                is_array($args['product_ids'] ?? null) ? $args['product_ids'] : []
            );
            if (($result['ok'] ?? false) !== true) {
                return $this->advisoryResult(
                    $call,
                    $context,
                    'failed',
                    (string) ($result['code'] ?? 'recommendation_operation_failed')
                );
            }
            unset($result['ok']);
            return ToolResult::advisorySuccess($call, $result, $context->correlationId);
        }

        if (!in_array($call->name, [
            'recommendation.apply_hard_filters',
            'recommendation.rank',
            'recommendation.diversify',
            'recommendation.explain',
        ], true)) {
            return $this->advisoryResult($call, $context, 'failed', 'tool_operation_unknown');
        }

        $expectedVersion = $args['expected_requirements_resource_version'] ?? null;
        $expectedHash = $args['expected_requirements_state_hash'] ?? null;
        if (!is_int($expectedVersion) || $expectedVersion < 0
            || !is_string($expectedHash)
            || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1
        ) {
            return $this->advisoryResult(
                $call,
                $context,
                'failed',
                'recommendation_requirements_reference_invalid'
            );
        }

        $state = $this->requirementStates->get(
            $context->conversationId,
            $context->actorType,
            $context->actorId
        );
        if (($state['ok'] ?? false) !== true) {
            $code = (string) ($state['code'] ?? 'requirements_state_unavailable');
            return $this->advisoryResult(
                $call,
                $context,
                $code === 'conversation_not_owned' ? 'blocked' : 'failed',
                $code
            );
        }

        $currentVersion = $state['resource_version'] ?? null;
        $currentHash = $state['state_hash'] ?? null;
        $activeRequirements = $state['active_requirements'] ?? null;
        if (!is_int($currentVersion) || $currentVersion < 0
            || !is_string($currentHash)
            || preg_match('/^[a-f0-9]{64}$/D', $currentHash) !== 1
            || !is_array($activeRequirements)
            || !array_is_list($activeRequirements)
        ) {
            return $this->advisoryResult($call, $context, 'failed', 'requirements_state_invalid');
        }
        if ($expectedVersion !== $currentVersion || !hash_equals($currentHash, $expectedHash)) {
            return $this->staleRequirementsResult($call, $context, $state);
        }

        $productIds = is_array($args['product_ids'] ?? null) ? $args['product_ids'] : [];
        $result = match ($call->name) {
            'recommendation.apply_hard_filters' => $this->recommendations->hardFilters($productIds, $activeRequirements),
            'recommendation.rank' => $this->recommendations->rank($productIds, $activeRequirements),
            'recommendation.diversify' => $this->recommendations->rankAndDiversify(
                $productIds,
                $activeRequirements,
                (int) ($args['limit'] ?? 4)
            ),
            'recommendation.explain' => $this->recommendations->explain($productIds, $activeRequirements),
        };

        if (!$this->requirementStates->isCurrent(
            $context->conversationId,
            $context->actorType,
            $context->actorId,
            $currentVersion,
            $currentHash
        )) {
            $latest = $this->requirementStates->get(
                $context->conversationId,
                $context->actorType,
                $context->actorId
            );
            return $this->staleRequirementsResult($call, $context, $latest);
        }

        if (($result['ok'] ?? false) !== true) {
            return $this->advisoryResult(
                $call,
                $context,
                'failed',
                (string) ($result['code'] ?? 'recommendation_operation_failed')
            );
        }
        unset($result['ok']);
        $result['bound_requirements_state'] = [
            'scope' => 'current_conversation_only',
            'resource_version' => $currentVersion,
            'state_hash' => $currentHash,
            'active_requirement_count' => count($activeRequirements),
        ];
        return ToolResult::advisorySuccess($call, $result, $context->correlationId);
    }

    /** @param array<string, mixed> $state */
    private function staleRequirementsResult(ToolCall $call, ToolContext $context, array $state): ToolResult
    {
        $data = [
            'requirements_refresh_required' => true,
            'scope' => 'current_conversation_only',
        ];
        if (is_int($state['resource_version'] ?? null)) {
            $data['current_requirements_resource_version'] = $state['resource_version'];
        }
        if (is_string($state['state_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $state['state_hash']) === 1
        ) {
            $data['current_requirements_state_hash'] = $state['state_hash'];
        }

        return $this->advisoryResult(
            $call,
            $context,
            'stale',
            'requirements_state_stale',
            $data,
            true
        );
    }

    /** @param array<string, mixed> $data */
    private function advisoryResult(
        ToolCall $call,
        ToolContext $context,
        string $status,
        string $code,
        array $data = [],
        bool $retrySafe = false
    ): ToolResult {
        return new ToolResult(
            $call->callId,
            $call->name,
            $status,
            $code,
            $data,
            [],
            false,
            $retrySafe,
            $context->correlationId,
            $call->version
        );
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param array<int, string> $required
     * @param array<int, string> $actors
     * @param array<int, string> $features
     */
    private function definition(
        string $name,
        string $description,
        string $classification,
        array $properties,
        array $required,
        array $actors,
        array $features,
        bool $modelVisible = true
    ): ToolDefinition {
        return new ToolDefinition($name, '1.0.0', $description, $classification, [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ], $actors, [], $features, $modelVisible, RecommendationOutputSchemas::for($name));
    }
}
