<?php

declare(strict_types=1);

namespace Veyra\AI\Orchestration;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\AI\Tool\TurnMutationGuard;

/** Executes only the server-authorized tool subset of a validated AI plan. */
final class DecisionPlanExecutor
{
    public function __construct(private readonly ToolRegistry $tools)
    {
    }

    /**
     * @param array<string, mixed> $decision A validated agent_decision_v1 payload.
     * @return array{
     *     tool_results:list<ToolResult>,
     *     mutation_results:list<ToolResult>,
     *     step_outcomes:list<array<string,mixed>>,
     *     failure_code:?string
     * }
     */
    public function execute(
        array $decision,
        ToolContext $context,
        string $customerMessageId,
        bool $mutationsAllowed,
        int $maximumToolCalls
    ): array {
        $steps = $decision['plan']['steps'] ?? [];
        $planId = is_string($decision['plan']['plan_id'] ?? null) ? $decision['plan']['plan_id'] : 'invalid_plan';
        $declaredBudget = is_int($decision['plan']['budgets']['max_tool_calls'] ?? null)
            ? $decision['plan']['budgets']['max_tool_calls']
            : 0;
        $toolBudget = max(0, min($maximumToolCalls, $declaredBudget));
        $results = [];
        $mutationResults = [];
        $outcomes = [];
        $stepStatuses = [];
        $guard = new TurnMutationGuard();
        $toolCalls = 0;
        $executionClosed = false;

        foreach ($steps as $step) {
            $stepId = (string) $step['step_id'];
            $kind = (string) $step['kind'];
            if ($kind !== 'tool') {
                $outcomes[] = [
                    'step_id' => $stepId,
                    'kind' => $kind,
                    'status' => 'control',
                    'code' => 'response_phase_required',
                ];
                $stepStatuses[$stepId] = $kind === 'respond' ? 'succeeded' : 'control';
                // No tool may run after the plan reaches a response, question,
                // confirmation, stop, or handoff boundary.
                $executionClosed = true;
                continue;
            }

            $call = new ToolCall(
                'call_' . substr(hash('sha256', $context->correlationId . '|' . $planId . '|' . $stepId), 0, 32),
                (string) $step['tool_name'],
                (string) $step['tool_version'],
                is_array($step['proposed_arguments']) ? $step['proposed_arguments'] : []
            );
            $blockedCode = null;
            if ($executionClosed) {
                $blockedCode = 'plan_control_boundary_reached';
            }
            foreach ($step['depends_on'] as $dependency) {
                if (($stepStatuses[$dependency] ?? null) !== 'succeeded') {
                    $blockedCode = 'tool_dependency_unsatisfied';
                    break;
                }
            }
            if ($blockedCode === null && ++$toolCalls > $toolBudget) {
                return [
                    'tool_results' => $results,
                    'mutation_results' => array_values($mutationResults),
                    'step_outcomes' => $outcomes,
                    'failure_code' => 'tool_budget_exceeded',
                ];
            }

            $profile = $this->tools->planProfile($call->name, $context);
            if ($blockedCode === null && is_array($profile)) {
                if ($profile['version'] !== $call->version) {
                    $blockedCode = 'plan_tool_version_mismatch';
                } elseif ($profile['classification'] !== $step['classification']) {
                    $blockedCode = 'plan_tool_classification_mismatch';
                } elseif ($step['confirmation_requirement'] === 'preview_and_confirm') {
                    $blockedCode = 'plan_confirmation_not_executable';
                } elseif ($profile['classification'] === 'sensitive_write'
                    && $step['confirmation_requirement'] !== 'already_confirmed_requires_validation'
                ) {
                    $blockedCode = 'plan_sensitive_confirmation_required';
                } elseif (in_array($profile['classification'], ['read', 'advisory'], true)
                    && $step['confirmation_requirement'] !== 'none'
                ) {
                    $blockedCode = 'plan_confirmation_classification_mismatch';
                }
            }

            $fingerprint = null;
            $semanticReplay = false;
            if ($blockedCode !== null) {
                $result = ToolResult::denied($call, $blockedCode, $context->correlationId);
            } else {
                try {
                    $prepared = $guard->prepare(
                        $call,
                        $context,
                        $customerMessageId,
                        $this->tools->mutationProfile($call->name)
                    );
                    $call = $prepared['call'];
                    $fingerprint = $prepared['fingerprint'];
                } catch (\Throwable) {
                    return [
                        'tool_results' => $results,
                        'mutation_results' => array_values($mutationResults),
                        'step_outcomes' => $outcomes,
                        'failure_code' => 'mutation_identity_invalid',
                    ];
                }

                $result = is_string($fingerprint)
                    ? $guard->replay($fingerprint, $call, $context->correlationId)
                    : null;
                $semanticReplay = $result instanceof ToolResult;
                if (!$result instanceof ToolResult) {
                    $result = $this->tools->execute($call, $context, $mutationsAllowed);
                    if (is_string($fingerprint)) {
                        $guard->remember($fingerprint, $result);
                    }
                }
            }

            $results[] = $result;
            $stepStatuses[$stepId] = $result->status;
            $outcomes[] = [
                'step_id' => $stepId,
                'kind' => 'tool',
                'tool' => $call->name,
                'call_id' => $call->callId,
                'status' => $result->status,
                'code' => $result->code,
                'semantic_replay' => $semanticReplay,
            ];
            if (is_array($profile) && in_array($profile['classification'], ['write', 'sensitive_write'], true)) {
                $mutationResults[$call->callId] = $result;
            }
            if ($result->status !== 'succeeded') {
                // Every declared failure behavior requires a response, repair,
                // clarification, or handoff. None authorizes later tool steps.
                $executionClosed = true;
            }
        }

        return [
            'tool_results' => $results,
            'mutation_results' => array_values($mutationResults),
            'step_outcomes' => $outcomes,
            'failure_code' => null,
        ];
    }
}
