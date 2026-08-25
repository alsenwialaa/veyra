<?php
declare(strict_types=1);

namespace Veyra\Requirements\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\Requirements\Application\RequirementStateService;
use Veyra\Requirements\Domain\RequirementCriterion;

final class RequirementsToolHandler implements ToolHandler
{
    public function __construct(private readonly RequirementStateService $requirements)
    {
    }

    public function definitions(): array
    {
        $actors = ['guest', 'customer'];
        $features = ['commerce_product_assistance', 'ai_conversation_memory'];
        return [
            new ToolDefinition(
                'requirements.get',
                '1.0.0',
                'Read validated requirement history for the current actor-owned conversation only; durable preference memory is excluded.',
                'read',
                ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                $actors,
                [],
                $features,
                true,
                $this->getOutputSchema()
            ),
            new ToolDefinition(
                'requirements.propose_update',
                '1.0.0',
                'Server-only requirement proposal boundary; unavailable to the model until semantic binding and promotion are implemented.',
                'write',
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['expected_resource_version', 'expected_state_hash', 'source_message_id', 'changes'],
                    'properties' => [
                        'expected_resource_version' => ['type' => 'integer', 'minimum' => 0],
                        'expected_state_hash' => [
                            'type' => 'string',
                            'pattern' => '^[a-f0-9]{64}$',
                        ],
                        'source_message_id' => [
                            'type' => 'string',
                            'minLength' => 1,
                            'maxLength' => 36,
                            'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]*$',
                        ],
                        'changes' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'maxItems' => 16,
                            'items' => $this->changeSchema(),
                        ],
                    ],
                ],
                $actors,
                [],
                $features,
                false,
                $this->updateOutputSchema()
            ),
        ];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        $result = match ($call->name) {
            'requirements.get' => $this->requirements->get(
                $context->conversationId,
                $context->actorType,
                $context->actorId
            ),
            'requirements.propose_update' => $this->requirements->proposeUpdate(
                $context->conversationId,
                $context->actorType,
                $context->actorId,
                (int) $call->arguments['expected_resource_version'],
                (string) $call->arguments['expected_state_hash'],
                (string) $call->arguments['source_message_id'],
                is_array($call->arguments['changes']) ? $call->arguments['changes'] : []
            ),
            default => ['ok' => false, 'code' => 'tool_operation_unknown'],
        };
        if (($result['ok'] ?? false) !== true) {
            return ToolResult::failed($call, (string) ($result['code'] ?? 'requirements_operation_failed'), $context->correlationId, false);
        }
        unset($result['ok']);
        $changed = $call->name === 'requirements.propose_update'
            ? ['conversation:' . $context->conversationId . ':requirements']
            : [];
        return ToolResult::success($call, $result, $context->correlationId, $changed);
    }

    /** @return array<string, mixed> */
    private function getOutputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'scope', 'conversation_id', 'resource_version', 'state_hash', 'version',
                'requirements', 'active_requirements', 'durable_preference_memory_used',
            ],
            'properties' => array_merge(
                $this->stateProperties(),
                [
                    'conversation_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 36],
                    'durable_preference_memory_used' => ['const' => false],
                ]
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function updateOutputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'scope', 'conversation_id', 'resource_version', 'state_hash', 'version',
                'changed_requirement_ids', 'requirements', 'durable_preference_memory_written',
            ],
            'properties' => array_merge(
                $this->stateProperties(false),
                [
                    'conversation_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 36],
                    'changed_requirement_ids' => [
                        'type' => 'array',
                        'maxItems' => 64,
                        'uniqueItems' => true,
                        'items' => $this->requirementIdSchema(),
                    ],
                    'durable_preference_memory_written' => ['const' => false],
                ]
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function stateProperties(bool $includeActiveRequirements = true): array
    {
        $properties = [
            'scope' => ['const' => 'current_conversation_only'],
            'resource_version' => ['type' => 'integer', 'minimum' => 0],
            'state_hash' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            // Compatibility alias. It must equal state_hash; the service and
            // deterministic harness enforce that invariant.
            'version' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
            'requirements' => [
                'type' => 'array',
                'maxItems' => 64,
                'items' => $this->criterionSchema(),
            ],
        ];

        if ($includeActiveRequirements) {
            $properties['active_requirements'] = [
                'type' => 'array',
                'maxItems' => 64,
                'items' => $this->criterionSchema('active'),
            ];
        }

        return $properties;
    }

    /** @return array<string, mixed> */
    private function criterionSchema(?string $requiredStatus = null): array
    {
        $status = $requiredStatus === null
            ? ['type' => 'string', 'enum' => RequirementCriterion::STATUSES]
            : ['const' => $requiredStatus];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'id', 'field', 'operator', 'value', 'strength', 'status', 'verification',
                'source', 'supersedes', 'superseded_by', 'version', 'created_at',
                'updated_at', 'status_source_message_id',
            ],
            'properties' => [
                'id' => $this->requirementIdSchema(),
                'field' => ['type' => 'string', 'enum' => RequirementCriterion::FIELDS],
                'operator' => ['type' => 'string', 'enum' => RequirementCriterion::OPERATORS],
                'value' => $this->requirementValueSchema(),
                'strength' => ['type' => 'string', 'enum' => RequirementCriterion::STRENGTHS],
                'status' => $status,
                'verification' => ['const' => 'shopper_message_exact_excerpt'],
                'source' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        'message_id', 'excerpt_sha256', 'excerpt_offset_bytes',
                        'excerpt_length_bytes', 'source_kind',
                    ],
                    'properties' => [
                        'message_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 36],
                        'excerpt_sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                        'excerpt_offset_bytes' => ['type' => 'integer', 'minimum' => 0],
                        'excerpt_length_bytes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000],
                        'source_kind' => ['const' => 'customer_visible_message'],
                    ],
                ],
                'supersedes' => [
                    'type' => 'array',
                    'maxItems' => 64,
                    'uniqueItems' => true,
                    'items' => $this->requirementIdSchema(),
                ],
                'superseded_by' => [
                    'type' => ['string', 'null'],
                    'maxLength' => 36,
                    'pattern' => '^(?:req_[a-f0-9]{32}|removed)$',
                ],
                'version' => ['type' => 'integer', 'minimum' => 1],
                'created_at' => $this->timestampSchema(),
                'updated_at' => $this->timestampSchema(),
                'status_source_message_id' => ['type' => ['string', 'null'], 'minLength' => 1, 'maxLength' => 36],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function changeSchema(): array
    {
        $value = $this->requirementValueSchema();
        $sourceExcerpt = ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000];
        $criterion = [
            'source_excerpt' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
            'field' => ['type' => 'string', 'enum' => RequirementCriterion::FIELDS],
            'operator' => ['type' => 'string', 'enum' => RequirementCriterion::OPERATORS],
            'value' => $value,
            'strength' => ['type' => 'string', 'enum' => RequirementCriterion::STRENGTHS],
            'status' => ['type' => 'string', 'enum' => ['active', 'proposed', 'unknown', 'disputed']],
        ];

        return [
            'oneOf' => [
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['operation', 'field', 'operator', 'value', 'strength', 'status', 'source_excerpt'],
                    'properties' => array_merge(['operation' => ['const' => 'upsert']], $criterion),
                ],
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        'operation', 'field', 'operator', 'value', 'strength', 'status',
                        'source_excerpt', 'target_requirement_id',
                    ],
                    'properties' => array_merge($criterion, [
                        'operation' => ['const' => 'correct'],
                        'status' => ['const' => 'active'],
                        'target_requirement_id' => $this->requirementIdSchema(),
                    ]),
                ],
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['operation', 'source_excerpt', 'target_requirement_id'],
                    'properties' => [
                        'operation' => ['const' => 'dispute'],
                        'source_excerpt' => $sourceExcerpt,
                        'target_requirement_id' => $this->requirementIdSchema(),
                    ],
                ],
                [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['operation', 'source_excerpt', 'target_requirement_id'],
                    'properties' => [
                        'operation' => ['const' => 'remove'],
                        'source_excerpt' => $sourceExcerpt,
                        'target_requirement_id' => $this->requirementIdSchema(),
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function requirementValueSchema(int $depth = 0): array
    {
        $types = ['string', 'number', 'boolean', 'null'];
        $schema = ['type' => $types, 'maxLength' => 500];
        if ($depth >= 5) {
            return $schema;
        }

        $schema['type'] = array_merge($types, ['array', 'object']);
        $schema['maxItems'] = 30;
        $schema['maxProperties'] = 30;
        $schema['items'] = $this->requirementValueSchema($depth + 1);
        $schema['additionalProperties'] = $this->requirementValueSchema($depth + 1);

        return $schema;
    }

    /** @return array<string, mixed> */
    private function requirementIdSchema(): array
    {
        return ['type' => 'string', 'pattern' => '^req_[a-f0-9]{32}$'];
    }

    /** @return array<string, mixed> */
    private function timestampSchema(): array
    {
        return [
            'type' => 'string',
            'pattern' => '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|\\+00:00)$',
        ];
    }
}
