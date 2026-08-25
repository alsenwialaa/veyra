<?php
declare(strict_types=1);

namespace Veyra\AI\Orchestration;

use Veyra\AI\Contract\AgentTurnResult;
use Veyra\AI\Contract\ProviderAdapter;
use Veyra\AI\Contract\ProviderRequest;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Provider\ProviderPayloadValidator;
use Veyra\AI\Provider\ProviderRequestAttestor;
use Veyra\AI\Provider\ProviderSafeToolResultProjector;
use Veyra\AI\Provider\ProviderToolResultProjectionException;
use Veyra\AI\Provider\ProviderTransmissionGate;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\Conversation\Application\ContextBundleAssembler;
use Veyra\Conversation\Application\ConversationStateUpdater;
use Veyra\Conversation\Application\ConversationStore;
use Veyra\Conversation\Application\ShortReplyBindingValidator;
use Veyra\Conversation\Domain\ContextBundle;
use Veyra\Conversation\Domain\ContextBundleException;
use Veyra\Experience\Contract\ProductReferenceIdentity;
use Veyra\Shared\Domain\CanonicalJson;

final class CommerceAgent
{
    private readonly ProviderRequestAttestor $requestAttestor;
    private readonly ProviderSafeToolResultProjector $toolResultProjector;

    public function __construct(
        private readonly ProviderAdapter $provider,
        private readonly ProviderPayloadValidator $payloadValidator,
        private readonly ToolRegistry $tools,
        private readonly ConversationStore $conversations,
        private readonly ContextBundleAssembler $contextBundles,
        private readonly ConversationStateUpdater $stateUpdater,
        private readonly AuthoritativeContextProvider $authoritativeContext,
        private readonly PromptPolicyCompiler $promptCompiler,
        private readonly ResponseVerifier $verifier,
        private readonly SemanticResponseVerifier $semanticVerifier,
        private readonly ServerComponentBuilder $componentBuilder,
        private readonly ShortReplyBindingValidator $shortReplyBindings,
        private readonly DecisionPlanExecutor $planExecutor,
        private readonly int $maximumProviderCalls = 3,
        private readonly int $maximumToolCalls = 8,
        ?ProviderRequestAttestor $requestAttestor = null,
        ?ProviderSafeToolResultProjector $toolResultProjector = null
    ) {
        $this->requestAttestor = $requestAttestor ?? new ProviderRequestAttestor();
        $this->toolResultProjector = $toolResultProjector ?? new ProviderSafeToolResultProjector();
    }

    public function handle(AgentTurnInput $turn): AgentTurnResult
    {
        $context = $turn->context;
        if ($this->conversations->getOwnedConversation($context->conversationId, $context->actorType, $context->actorId) === null) {
            return $this->blocked('conversation_not_owned', $context->correlationId, $context->locale);
        }

        [$replySnapshot, $productSnapshots, $referenceWarnings] = $this->authorizeReferences($turn);
        $customerMessageId = $this->conversations->appendVisibleMessage(
            $context->conversationId,
            $context->actorType,
            $context->actorId,
            'customer',
            $turn->text,
            [
                'schema_version' => '1.0.0',
                'language' => $context->locale,
                'direction' => $this->direction($context->locale),
                'reply_snapshot' => $replySnapshot,
                'product_references' => $productSnapshots,
                'attachment_ids' => $turn->attachmentIds,
                'location_supplied' => $turn->location !== null,
                'client_quick_reply_hint' => $this->clientQuickReplyHint($turn->answerBinding),
                'reference_warnings' => $referenceWarnings,
            ],
            [],
            $context->correlationId
        );

        if ($this->maximumProviderCalls < 3) {
            return $this->persistBlocked($turn, 'provider_budget_too_small', $context->correlationId);
        }

        try {
            $runtime = $this->authoritativeContext->runtime($context);
            $commerce = $this->authoritativeContext->commerce($context);
            $focus = $this->conversations->focus(
                $context->conversationId,
                $context->actorType,
                $context->actorId
            );
            $activePendingQuestion = $focus?->pendingQuestion?->isActive(
                new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ) ?? false;
            $bundle = $this->contextBundles->assemble(
                $context,
                $customerMessageId,
                [
                    'message_id' => $customerMessageId,
                    'text' => $turn->text,
                    'reply_snapshot' => $replySnapshot,
                    'product_reference_snapshots' => $productSnapshots,
                    'attachment_ids' => $turn->attachmentIds,
                    'location' => $turn->location,
                    'client_quick_reply_hint' => $this->clientQuickReplyHint($turn->answerBinding),
                    'client_quick_reply_hint_authority' => 'untrusted_hint_only',
                ],
                $runtime,
                $commerce
            );
        } catch (ContextBundleException $error) {
            return $this->persistBlocked($turn, $error->reasonCode, $context->correlationId);
        } catch (\Throwable) {
            return $this->persistBlocked($turn, 'context_bundle_failed', $context->correlationId);
        }

        if (!$bundle->transmissionAuthorized()) {
            return $this->persistFailure(
                $turn,
                'provider_context_transmission_not_authorized',
                $context->correlationId,
                [],
                [],
                $bundle
            );
        }

        $decisionResult = $this->provider->execute($this->requestAttestor->seal(new ProviderRequest(
            'default_text_tool_orchestration',
            $this->promptCompiler->compileDecision($context->locale),
            [[
                'type' => 'text',
                'text' => $this->encode([
                    'instruction' => ProviderTransmissionGate::DECISION_INSTRUCTION,
                    'context_bundle' => $bundle->forProvider(),
                    'authorized_tools' => $this->tools->planningTools($context, true),
                    'server_limits' => [
                        'max_provider_calls' => $this->maximumProviderCalls,
                        'max_tool_calls' => $this->maximumToolCalls,
                    ],
                ]),
            ]],
            [],
            $this->payloadValidator->decisionSchema(),
            25,
            [
                'correlation_id' => $context->correlationId,
                'conversation_id' => $context->conversationId,
                'contract' => 'agent_decision_v1',
                'context_bundle_id' => $bundle->id,
                'context_bundle_version' => $bundle->bundleVersion,
                'context_bundle_hash' => $bundle->hash,
            ],
            null,
            [],
            ProviderRequest::TRAFFIC_SHOPPER,
            ProviderRequest::PURPOSE_SHOPPER,
            $bundle,
            ProviderRequest::PHASE_DECISION
        )));
        if ($decisionResult->status !== 'succeeded' || !is_array($decisionResult->payload)) {
            return $this->persistFailure($turn, $decisionResult->code, $context->correlationId, [], [], $bundle);
        }
        $decision = $this->payloadValidator->validateDecisionPayload($decisionResult->payload);
        if ($decision === null) {
            return $this->persistFailure($turn, 'agent_decision_contract_invalid', $context->correlationId, [], [], $bundle);
        }

        $binding = $this->validateDecisionAnswerBinding($decision, $focus, $runtime, $commerce);
        $consumption = ['consumed' => false, 'code' => 'pending_question_not_active', 'binding_id' => null];
        $mutationsAllowed = !$activePendingQuestion;
        if ($activePendingQuestion && $binding['valid'] && $focus?->pendingQuestion !== null) {
            $semanticBinding = $decision['interpretation']['short_reply_binding'];
            $consumption = $this->conversations->consumePendingQuestion(
                $context->conversationId,
                $context->actorType,
                $context->actorId,
                $focus->pendingQuestion->id,
                $focus->version,
                $focus->pendingQuestion->version,
                $customerMessageId,
                [
                    'proposed_value' => $semanticBinding['proposed_value'],
                    'validated_value' => $binding['value'],
                    'target_resource_ids' => $semanticBinding['target_resource_ids'],
                    'validation_code' => $binding['code'],
                    'decision_id' => (string) $decision['plan']['plan_id'],
                ]
            );
            $mutationsAllowed = $consumption['consumed'];
        } elseif ($activePendingQuestion) {
            $consumption['code'] = $binding['code'];
        }

        $bindingMutationResults = [];
        if ($consumption['consumed'] && $focus?->pendingQuestion !== null) {
            $bindingResult = new ToolResult(
                'call_' . substr(hash('sha256', $context->correlationId . '|pending-question-consumption'), 0, 32),
                'conversation.consume_pending_question',
                'succeeded',
                'pending_question_consumed',
                [
                    'question_id' => $focus->pendingQuestion->id,
                    'binding_id' => $consumption['binding_id'],
                    'customer_message_id' => $customerMessageId,
                    'validated_value' => $binding['value'],
                ],
                ['pending_question:' . $focus->pendingQuestion->id],
                true,
                false,
                $context->correlationId
            );
            $bindingMutationResults[] = $bindingResult;
        }

        $execution = $this->planExecutor->execute(
            $decision,
            $context,
            $customerMessageId,
            $mutationsAllowed,
            $this->maximumToolCalls
        );
        $toolResults = array_merge($bindingMutationResults, $execution['tool_results']);
        $mutationResults = array_merge($bindingMutationResults, $execution['mutation_results']);
        if ($execution['failure_code'] !== null) {
            return $this->persistFailure(
                $turn,
                $execution['failure_code'],
                $context->correlationId,
                $toolResults,
                $mutationResults,
                $bundle
            );
        }

        $bindingOutcome = [
            'active_pending_question' => $activePendingQuestion,
            'valid' => $binding['valid'],
            'validation_code' => $binding['code'],
            'validated_value' => $binding['valid'] ? $binding['value'] : null,
            'consumed' => $consumption['consumed'],
            'consumption_code' => $consumption['code'],
            'binding_id' => $consumption['binding_id'],
            'mutations_allowed' => $mutationsAllowed,
        ];
        try {
            // Provider requests receive only the server-owned, closed projection.
            // Raw ToolResult data and correlation identifiers never cross this
            // boundary. Projection happens before a response request exists so
            // an open or unregistered result fails without another provider call.
            $providerToolResults = $this->toolResultProjector->projectMany($toolResults, $this->tools);
        } catch (ProviderToolResultProjectionException $error) {
            return $this->persistFailure(
                $turn,
                $error->reasonCode,
                $context->correlationId,
                $toolResults,
                $mutationResults,
                $bundle
            );
        }
        $responseResult = $this->provider->execute($this->requestAttestor->seal(new ProviderRequest(
            'default_text_tool_orchestration',
            $this->promptCompiler->compileResponse($context->locale),
            [[
                'type' => 'text',
                'text' => $this->encode([
                    'instruction' => ProviderTransmissionGate::RESPONSE_INSTRUCTION,
                    'context_bundle' => $bundle->forProvider(),
                    'validated_decision' => $decision,
                    'binding_outcome' => $bindingOutcome,
                    'step_outcomes' => $execution['step_outcomes'],
                    'typed_tool_results' => $providerToolResults,
                ]),
            ]],
            [],
            $this->payloadValidator->responseContractSchema(),
            25,
            [
                'correlation_id' => $context->correlationId,
                'conversation_id' => $context->conversationId,
                'contract' => 'agent_response_v1',
                'context_bundle_id' => $bundle->id,
                'context_bundle_version' => $bundle->bundleVersion,
                'context_bundle_hash' => $bundle->hash,
            ],
            null,
            [],
            ProviderRequest::TRAFFIC_SHOPPER,
            ProviderRequest::PURPOSE_SHOPPER,
            $bundle,
            ProviderRequest::PHASE_RESPONSE
        )));
        if ($responseResult->status !== 'succeeded' || !is_array($responseResult->payload)) {
            return $this->persistFailure(
                $turn,
                $responseResult->code,
                $context->correlationId,
                $toolResults,
                $mutationResults,
                $bundle
            );
        }
        $payload = $this->payloadValidator->validateResponseContractPayload($responseResult->payload);
        if ($payload === null) {
            return $this->persistFailure(
                $turn,
                'agent_response_contract_invalid',
                $context->correlationId,
                $toolResults,
                $mutationResults,
                $bundle
            );
        }
        $verified = $this->verifier->verify($payload, $toolResults);
        if (!$verified['valid'] || trim((string) $payload['reply']['text']) === '') {
            return $this->persistFailure(
                $turn,
                'response_verification_failed',
                $context->correlationId,
                $toolResults,
                $mutationResults,
                $bundle
            );
        }

        $semantic = $this->semanticVerifier->verify(
            $payload,
            $providerToolResults,
            $bundle,
            $bindingOutcome,
            $execution['step_outcomes'],
            $context->locale,
            $context->correlationId
        );
        if (!$semantic['valid']) {
            return $this->persistFailure($turn, $semantic['code'], $context->correlationId, $toolResults, $mutationResults, $bundle);
        }

        $components = $this->componentBuilder->build($payload['reply']['components'], $toolResults);
        $derivedProductReferences = $this->componentBuilder->productReferenceSnapshots($components);
        $agentMessageId = $this->conversations->appendVisibleMessage(
            $context->conversationId,
            $context->actorType,
            $context->actorId,
            'ai',
            (string) $payload['reply']['text'],
            [
                'schema_version' => '1.0.0',
                'language' => (string) $payload['language'],
                'direction' => (string) $payload['direction'],
                'components' => $components,
                'product_references' => $derivedProductReferences,
                'provider_route' => 'default_text_tool_orchestration',
                'context_bundle' => $bundle->reference(),
                'decision_contract' => 'agent_decision_v1',
                'response_contract' => 'agent_response_v1',
                'plan_id' => (string) $decision['plan']['plan_id'],
                'answer_binding' => [
                    'validation_code' => $binding['code'],
                    'consumption_code' => $consumption['code'],
                    'binding_id' => $consumption['binding_id'],
                ],
                'semantic_verification' => [
                    'code' => $semantic['code'],
                    'contract' => 'semantic_response_verification_v1',
                ],
            ],
            $verified['evidence'],
            $context->correlationId
        );
        $authorizedResources = $this->extractAuthorizedResources($toolResults);
        $stateUpdate = $this->stateUpdater->applyValidatedProposal(
            $context->conversationId,
            $context->actorType,
            $context->actorId,
            $agentMessageId,
            $customerMessageId,
            is_array($payload['proposed_updates']) ? $payload['proposed_updates'] : [],
            $authorizedResources
        );

        return new AgentTurnResult(
            'succeeded',
            'turn_completed',
            (string) $payload['reply']['text'],
            $components,
            $verified['evidence'],
            $toolResults,
            ['message_id' => $agentMessageId, 'state_update' => $stateUpdate],
            $context->correlationId
        );
    }

    /**
     * @param array<string, mixed> $decision
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $commerce
     * @return array{valid:bool,code:string,value:mixed}
     */
    private function validateDecisionAnswerBinding(array $decision, ?\Veyra\Conversation\Domain\ConversationFocus $focus, array $runtime, array $commerce): array
    {
        if ($focus === null || $focus->pendingQuestion === null) {
            return ['valid' => false, 'code' => 'pending_question_unavailable', 'value' => null];
        }
        $binding = $decision['interpretation']['short_reply_binding'] ?? null;
        if (!is_array($binding) || ($binding['state'] ?? null) !== 'proposed') {
            return ['valid' => false, 'code' => 'binding_not_proposed', 'value' => null];
        }
        $versions = [];
        foreach ($focus->pendingQuestion->dependencyVersions as $key => $expected) {
            $current = match ((string) $key) {
                'runtime', 'runtime_version' => $runtime['version'] ?? null,
                'cart', 'cart_hash', 'commerce', 'commerce_version' => $commerce['version'] ?? null,
                default => null,
            };
            if ($current !== null) {
                $versions[(string) $key] = is_int($current) ? $current : (string) $current;
            }
        }
        return $this->shortReplyBindings->validate(
            $focus,
            [
                'question_id' => $binding['target_question_id'] ?? null,
                'focus_version' => $focus->version,
                'target_resource_ids' => $binding['target_resource_ids'] ?? null,
                'value' => $binding['proposed_value'] ?? null,
                // Confirmation-sensitive bindings require independent server
                // confirmation lookup and therefore cannot be promoted here.
                'confirmation_id' => null,
            ],
            $versions,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    /** @param array<string, mixed>|null $binding @return array<string, string>|null */
    private function clientQuickReplyHint(?array $binding): ?array
    {
        if ($binding === null
            || array_diff(array_keys($binding), ['schema_version', 'choice_id', 'pending_question_id']) !== []
            || array_diff(['schema_version', 'choice_id', 'pending_question_id'], array_keys($binding)) !== []
            || ($binding['schema_version'] ?? null) !== 'veyra.answer_binding.v1'
            || !is_string($binding['choice_id'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $binding['choice_id']) !== 1
            || !is_string($binding['pending_question_id'] ?? null)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $binding['pending_question_id']) !== 1
        ) {
            return null;
        }

        return [
            'schema_version' => 'veyra.answer_binding.v1',
            'choice_id' => $binding['choice_id'],
            'pending_question_id' => $binding['pending_question_id'],
        ];
    }

    /** @return array{0:?array,1:array<int,array<string,mixed>>,2:array<int,string>} */
    private function authorizeReferences(AgentTurnInput $turn): array
    {
        $context = $turn->context;
        $warnings = [];
        $reply = null;
        if ($turn->replyToMessageId !== null) {
            $reply = $this->conversations->visibleMessage($context->conversationId, $context->actorType, $context->actorId, $turn->replyToMessageId);
            if ($reply === null) {
                $warnings[] = 'reply_reference_rejected';
            }
        }
        $products = [];
        foreach ($turn->productReferences as $rawBinding) {
            $binding = ProductReferenceIdentity::commandBinding($rawBinding);
            if ($binding === null) {
                $warnings[] = 'product_reference_rejected';
                continue;
            }
            $sourceMessageId = (string) $binding['source_message_id'];
            $source = $this->conversations->visibleMessage($context->conversationId, $context->actorType, $context->actorId, $sourceMessageId);
            $sourceReferences = is_array($source['product_references'] ?? null)
                && array_is_list($source['product_references'])
                    ? $source['product_references']
                    : [];
            $matched = $source === null
                ? null
                : ProductReferenceIdentity::match($sourceReferences, $sourceMessageId, $binding);
            if ($matched === null || !is_array($matched['snapshot'] ?? null)) {
                $warnings[] = 'product_reference_rejected';
                continue;
            }
            $products[] = [
                'schema_version' => ProductReferenceIdentity::BINDING_SCHEMA_VERSION,
                'reference_id' => $binding['reference_id'],
                'source_message_id' => $sourceMessageId,
                'product_id' => $binding['product_id'],
                'variation_id' => $binding['variation_id'],
                'historical_references' => [$matched['snapshot']],
            ];
        }
        return [$reply, $products, $warnings];
    }

    /** @param array<int, ToolResult> $results @return array<string, array<string, true>> */
    private function extractAuthorizedResources(array $results): array
    {
        $resources = [];
        foreach ($results as $result) {
            if ($result->status !== 'succeeded' || !$result->authoritative) {
                continue;
            }
            $fields = $this->authorizedResourceFields($result->tool);
            if ($fields !== []) {
                $this->collectAuthorizedIds($result->data, $fields, $resources);
            }
        }
        foreach ($resources as &$ids) {
            ksort($ids, SORT_STRING);
        }
        unset($ids);
        ksort($resources, SORT_STRING);
        return $resources;
    }

    /**
     * @param array<string|int, mixed>       $data
     * @param array<string, string>          $fields
     * @param array<string, array<string, true>> $resources
     */
    private function collectAuthorizedIds(array $data, array $fields, array &$resources): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key)
                && isset($fields[$key])
                && (is_int($value) || is_string($value))
                && (string) $value !== ''
            ) {
                $resources[$fields[$key]][(string) $value] = true;
            }
            if (is_array($value)) {
                $this->collectAuthorizedIds($value, $fields, $resources);
            }
        }
    }

    /** @return array<string, string> Exact server-owned result fields by tool domain. */
    private function authorizedResourceFields(string $tool): array
    {
        $domain = strstr($tool, '.', true);

        return match ($domain) {
            'catalog', 'recommendation' => [
                'product_id' => 'product',
                'variation_id' => 'variation',
            ],
            'cart' => [
                'product_id' => 'product',
                'variation_id' => 'variation',
                'cart_item_key' => 'cart_line',
            ],
            'orders' => [
                'order_id' => 'order',
                'order_item_id' => 'order_item',
                'product_id' => 'product',
                'variation_id' => 'variation',
            ],
            'checkout' => [
                'checkout_id' => 'checkout',
                'product_id' => 'product',
                'variation_id' => 'variation',
            ],
            'crm' => [
                'case_id' => 'case',
                'order_id' => 'order',
                'attachment_id' => 'attachment',
            ],
            'payment_review' => [
                'review_id' => 'payment_review',
                'case_id' => 'case',
                'order_id' => 'order',
                'attachment_id' => 'attachment',
            ],
            'media' => ['attachment_id' => 'attachment'],
            'requirements' => ['criterion_id' => 'requirement'],
            default => [],
        };
    }

    private function persistBlocked(AgentTurnInput $turn, string $code, string $correlationId): AgentTurnResult
    {
        return $this->persistFailure($turn, $code, $correlationId, [], []);
    }

    /** @param array<int, ToolResult> $toolResults @param array<int, ToolResult> $mutationResults */
    private function persistFailure(
        AgentTurnInput $turn,
        string $failureCode,
        string $correlationId,
        array $toolResults,
        array $mutationResults,
        ?ContextBundle $contextBundle = null
    ): AgentTurnResult {
        $outcome = MutationFailureOutcome::classify($mutationResults);
        $status = $outcome === 'none' ? 'blocked' : $outcome;
        $code = match ($outcome) {
            'partial' => 'turn_partial_after_mutation',
            'uncertain' => 'turn_mutation_outcome_uncertain',
            default => $failureCode,
        };
        $message = $this->failureMessage($turn->context->locale, $outcome);
        $safeOutcomes = array_map(static fn (ToolResult $result): array => [
            'tool' => $result->tool,
            'status' => $result->status,
            'code' => $result->code,
            'changed_resources' => $result->changedResources,
        ], $mutationResults);
        $render = [
            'schema_version' => '1.0.0',
            'language' => $turn->context->locale,
            'direction' => $this->direction($turn->context->locale),
            'components' => [],
            'error_code' => $failureCode,
            'operation_outcome' => $outcome,
            'mutation_results' => $safeOutcomes,
        ];
        if ($contextBundle instanceof ContextBundle) {
            $render['context_bundle'] = $contextBundle->reference();
        }
        $messageId = $this->conversations->appendVisibleMessage(
            $turn->context->conversationId,
            $turn->context->actorType,
            $turn->context->actorId,
            'ai',
            $message,
            $render,
            [],
            $correlationId
        );

        return new AgentTurnResult(
            $status,
            $code,
            $message,
            [],
            [],
            $toolResults,
            ['message_id' => $messageId, 'state_update' => null],
            $correlationId
        );
    }

    private function blocked(string $code, string $correlationId, string $locale): AgentTurnResult
    {
        return new AgentTurnResult('blocked', $code, $this->safeFailureMessage($locale), [], [], [], null, $correlationId);
    }

    private function safeFailureMessage(string $locale): string
    {
        return str_starts_with(strtolower($locale), 'ar')
            ? 'تعذّر تشغيل المساعدة الذكية بأمان الآن. لم أنفّذ أي إجراء. حاول لاحقًا أو استخدم مسار المتجر العادي.'
            : 'Intelligent assistance is temporarily unavailable. No action was executed. Please retry later or use the store’s standard flow.';
    }

    private function failureMessage(string $locale, string $outcome): string
    {
        $arabic = str_starts_with(strtolower($locale), 'ar');
        return match ($outcome) {
            'partial' => $arabic
                ? 'تعذّر إكمال الرد بعد اكتمال عملية واحدة أو أكثر في المتجر. قد تكون الحالة تغيّرت. حدّث المحادثة وحالة السلة أو الطلب الحالية قبل أي محاولة أخرى.'
                : 'The response could not be completed after one or more store operations finished. The current state may have changed. Refresh the conversation and current cart or order state before trying anything else.',
            'uncertain' => $arabic
                ? 'تعذّر التحقق مما إذا كان التغيير المطلوب قد اكتمل. حدّث حالة المتجر الموثوقة قبل إعادة المحاولة.'
                : 'The requested change could not be verified. Refresh the authoritative store state before retrying.',
            default => $this->safeFailureMessage($locale),
        };
    }

    private function direction(string $locale): string
    {
        return str_starts_with(strtolower($locale), 'ar') ? 'rtl' : 'ltr';
    }

    /** @param mixed $value */
    private function encode(mixed $value): string
    {
        try {
            return CanonicalJson::encode($value);
        } catch (\Throwable) {
            throw new \RuntimeException('JSON encoding failed.');
        }
    }
}
