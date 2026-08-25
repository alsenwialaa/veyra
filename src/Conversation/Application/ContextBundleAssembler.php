<?php
declare(strict_types=1);

namespace Veyra\Conversation\Application;

use Veyra\AI\Tool\ToolContext;
use Veyra\Conversation\Application\ContextBundleManifestRepository;
use Veyra\Conversation\Contract\ContextBundleContract;
use Veyra\Conversation\Domain\ContextBundle;
use Veyra\Conversation\Domain\ContextBundleAttestor;
use Veyra\Conversation\Domain\ContextBundleException;
use Veyra\Conversation\Domain\ContextBundleManifest;
use Veyra\Conversation\Domain\ContextBundlePolicy;
use Veyra\Conversation\Domain\ContextBundleSource;
use Veyra\Conversation\Domain\ConversationFocus;
use Veyra\Conversation\Domain\JourneyState;
use Veyra\Conversation\Domain\PendingQuestion;
use Veyra\Experience\Contract\ProductReferenceIdentity;
use Veyra\Requirements\Application\RequirementStateService;
use Veyra\Privacy\ProviderOutboundSanitizer;
use Veyra\Shared\Domain\CanonicalJson;

final class ContextBundleAssembler
{
    private readonly ContextBundleContract $contract;
    private readonly ContextBundleAttestor $attestor;

    public function __construct(
        private readonly ConversationStore $store,
        private readonly ContextBundlePolicy $policy,
        private readonly ?RequirementStateService $requirements = null,
        ?ContextBundleContract $contract = null,
        ?ContextBundleAttestor $attestor = null,
        private readonly ?ContextBundleManifestRepository $manifests = null,
        private readonly ?ProviderOutboundSanitizer $sanitizer = null
    ) {
        $this->contract = $contract ?? new ContextBundleContract();
        $this->attestor = $attestor ?? new ContextBundleAttestor();
    }

    /**
     * @param array<string, mixed> $currentInput
     * @param array<string, mixed> $runtimeContext
     * @param array<string, mixed> $authoritativeState
     */
    public function assemble(
        ToolContext $context,
        string $turnMessageId,
        array $currentInput,
        array $runtimeContext,
        array $authoritativeState
    ): ContextBundle {
        if ($this->store->getOwnedConversation(
            $context->conversationId,
            $context->actorType,
            $context->actorId
        ) === null) {
            throw new ContextBundleException('context_bundle_conversation_not_owned');
        }
        if (($currentInput['message_id'] ?? null) !== $turnMessageId) {
            throw new ContextBundleException('context_bundle_turn_binding_invalid');
        }
        $ownedTurn = $this->store->visibleMessage(
            $context->conversationId,
            $context->actorType,
            $context->actorId,
            $turnMessageId
        );
        if (!is_array($ownedTurn) || ($ownedTurn['sender_type'] ?? null) !== 'customer'
            || !is_array($ownedTurn['content'] ?? null)
            || !is_string($ownedTurn['content']['text'] ?? null)
            || ($ownedTurn['content']['text'] ?? null) !== ($currentInput['text'] ?? null)
        ) {
            throw new ContextBundleException('context_bundle_turn_message_not_owned');
        }
        $persistedRender = is_array($ownedTurn['render'] ?? null) ? $ownedTurn['render'] : [];
        $persistedInput = [
            'message_id' => $turnMessageId,
            'text' => (string) $ownedTurn['content']['text'],
            'reply_snapshot' => $persistedRender['reply_snapshot'] ?? null,
            'product_reference_snapshots' => is_array($ownedTurn['product_references'] ?? null)
                ? $ownedTurn['product_references']
                : [],
            'attachment_ids' => is_array($persistedRender['attachment_ids'] ?? null)
                ? $persistedRender['attachment_ids']
                : [],
            'location' => ($persistedRender['location_supplied'] ?? false) === true ? ['omitted' => true] : null,
            'client_quick_reply_hint' => $persistedRender['client_quick_reply_hint'] ?? null,
        ];

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $bundleId = 'ctx_' . bin2hex(random_bytes(16));
        $actorScopeId = $this->pseudonym('actor|' . $context->actorType . '|' . $context->actorId . '|' . $context->conversationId . '|' . $bundleId);
        $siteIdentity = function_exists('get_current_blog_id') ? (string) get_current_blog_id() : 'single-site';
        $siteScopeId = $this->pseudonym('site|' . $siteIdentity);

        $focus = $this->store->focus(
            $context->conversationId,
            $context->actorType,
            $context->actorId
        );
        $journeys = $this->store->journeys(
            $context->conversationId,
            $context->actorType,
            $context->actorId
        );
        $rawMessages = $this->store->recentVisibleMessages(
            $context->conversationId,
            $context->actorType,
            $context->actorId,
            26
        );
        $rawMemory = $this->store->memory(
            $context->conversationId,
            $context->actorType,
            $context->actorId
        );
        $rawSummary = $this->store->summary(
            $context->conversationId,
            $context->actorType,
            $context->actorId
        );

        $redactions = ['raw_actor_identifier_pseudonymized'];
        [$input, $inputSources, $inputExcluded] = $this->projectCurrentInput($context, $persistedInput, $turnMessageId, $redactions, $now);
        $focusProjection = $this->projectFocus($focus, $now);
        [$foregroundJourney, $pausedJourneys, $pausedJourneyExcluded] = $this->projectJourneys($journeys, $focus?->foregroundJourneyId);
        [$messages, $messageExcluded] = $this->projectMessages($rawMessages, $turnMessageId);
        $requirementState = $this->requirements($context);
        $runtime = $this->projectRuntime($runtimeContext);
        [$authority, $authorityExcluded] = $this->projectAuthority($authoritativeState, $now);

        [$input, $focusProjection, $foregroundJourney, $messages, $requirementState, $authority]
            = $this->sanitizeProviderContent(
                $input,
                $focusProjection,
                $foregroundJourney,
                $messages,
                $requirementState,
                $authority,
                $redactions
            );

        $excludedSources = array_merge($inputExcluded, $pausedJourneyExcluded, $messageExcluded, $authorityExcluded);

        if ($rawMemory !== []) {
            $redactions[] = 'unvalidated_conversation_memory_omitted';
            $excludedSources[] = $this->excludedSource(
                'validated_state',
                'conversation_memory:' . $context->conversationId,
                $this->payloadVersion($rawMemory),
                null,
                'conversation_memory',
                'personal',
                'unvalidated',
                'unknown',
                $now,
                'validated_memory_contract_unavailable'
            );
        }
        if ($rawSummary !== []) {
            $redactions[] = 'unvalidated_summary_omitted';
            $excludedSources[] = $this->excludedSource(
                'validated_state',
                'validated_summary:' . $context->conversationId,
                $this->payloadVersion($rawSummary),
                null,
                'validated_summary',
                'personal',
                'unvalidated',
                'unknown',
                $now,
                'validated_summary_contract_unavailable'
            );
        }
        $redactions[] = 'message_render_evidence_and_correlation_omitted';

        $modalities = [];
        if ($input['text'] !== '') {
            $modalities[] = $this->modality('text', $turnMessageId);
        }
        if ($input['reply_quote'] !== null) {
            $modalities[] = $this->modality('reply_quote', (string) $input['reply_quote']['message_id']);
        }
        foreach ($input['product_reference_bindings'] as $reference) {
            $modalities[] = $this->modality('product_reference', (string) $reference['reference_id']);
        }
        $unsupportedModalities = count($inputExcluded);
        if ($unsupportedModalities > 0) {
            $redactions[] = 'unvalidated_attachment_or_location_omitted';
        }

        $selection = $this->initialSelection(
            $focusProjection,
            $foregroundJourney,
            $pausedJourneys,
            $pausedJourneyExcluded,
            $messages,
            $messageExcluded,
            $rawMemory,
            $requirementState,
            $rawSummary,
            $authority,
            $authorityExcluded,
            $modalities,
            $inputExcluded
        );

        while (true) {
            $bundle = $this->projection(
                $bundleId,
                $actorScopeId,
                $siteScopeId,
                $context,
                $turnMessageId,
                $now,
                $input,
                $focusProjection,
                $foregroundJourney,
                $pausedJourneys,
                $messages,
                $requirementState,
                $runtime,
                $authority,
                $modalities,
                $inputSources,
                $selection,
                array_values(array_unique($redactions))
            );
            $items = (int) $bundle['limits']['actual_items'];
            $bytes = (int) $bundle['limits']['actual_bytes'];
            if ($items <= $this->policy->maximumItems && $bytes <= $this->policy->maximumBytes) {
                break;
            }

            if ($messages !== []) {
                $removed = array_shift($messages);
                if (is_array($removed)) {
                    $excludedSources[] = $this->excludedProjectedMessage(
                        $removed,
                        'oldest_message_removed_for_route_bound'
                    );
                }
                $this->excludeOne($selection, 'recent_visible_messages', 'oldest_message_removed_for_route_bound');
                continue;
            }
            if (($authority['cart']['lines'] ?? []) !== []) {
                $removed = array_pop($authority['cart']['lines']);
                if (is_array($removed)) {
                    $excludedSources[] = $this->excludedCartLine(
                        $removed,
                        (string) $authority['version'],
                        $now,
                        'cart_line_removed_for_route_bound'
                    );
                }
                $authority['cart']['lines_truncated'] = true;
                $this->excludeOne($selection, 'authoritative_state', 'cart_line_removed_for_route_bound');
                continue;
            }
            if ($pausedJourneys !== []) {
                $removed = array_pop($pausedJourneys);
                if (is_array($removed)) {
                    $excludedSources[] = $this->excludedSource(
                        'validated_state',
                        (string) $removed['id'],
                        (string) $removed['version'],
                        null,
                        'paused_journeys',
                        'personal',
                        'validated',
                        'fresh',
                        $now,
                        'paused_journey_removed_for_route_bound'
                    );
                }
                $this->excludeOne($selection, 'paused_journeys', 'paused_journey_removed_for_route_bound');
                continue;
            }
            throw new ContextBundleException('context_bundle_limit_exceeded');
        }

        $this->contract->assertValid(
            $bundle,
            $context,
            $turnMessageId,
            $this->policy,
            $actorScopeId,
            $siteScopeId
        );
        $bundleHash = hash('sha256', CanonicalJson::encode($bundle));
        $sourceAccounting = $this->sourceAccounting($bundle, $excludedSources, $now);
        $manifest = ContextBundleManifest::fromBundle(
            $bundle,
            $context->actorType,
            $context->actorId,
            $bundleHash,
            $sourceAccounting
        );
        $persisted = false;
        if ($this->manifests !== null) {
            try {
                $persisted = $this->manifests->save($manifest);
            } catch (\Throwable) {
                $persisted = false;
            }
            if (!$persisted) {
                throw new ContextBundleException('context_bundle_manifest_persistence_failed');
            }
        }
        return ContextBundle::issue(
            $bundle,
            $context->actorType,
            $context->actorId,
            $this->attestor,
            $persisted
        );
    }

    /** @param array<string, mixed> $currentInput @param list<string> $redactions @return array{0:array<string,mixed>,1:list<array<string,mixed>>,2:list<ContextBundleSource>} */
    private function projectCurrentInput(
        ToolContext $context,
        array $currentInput,
        string $turnMessageId,
        array &$redactions,
        \DateTimeImmutable $now
    ): array {
        $text = is_string($currentInput['text'] ?? null) ? $currentInput['text'] : '';
        if (trim($text) === '') {
            throw new ContextBundleException('context_bundle_supported_input_required');
        }
        if (strlen($text) > 12000 || preg_match('//u', $text) !== 1) {
            throw new ContextBundleException('context_bundle_current_input_invalid');
        }
        $inputSources = [];
        $excludedSources = [];
        $requestedQuote = $currentInput['reply_snapshot'] ?? null;
        $ownedQuote = is_array($requestedQuote) && $this->opaque($requestedQuote['message_id'] ?? null)
            ? $this->store->visibleMessage(
                $context->conversationId,
                $context->actorType,
                $context->actorId,
                (string) $requestedQuote['message_id']
            )
            : null;
        $quote = $this->projectQuote($ownedQuote, $now);
        if (($currentInput['reply_snapshot'] ?? null) !== null && $quote === null) {
            $redactions[] = 'unvalidated_reply_snapshot_omitted';
            $requestedId = is_array($requestedQuote) ? ($requestedQuote['message_id'] ?? null) : null;
            $excludedSources[] = $this->excludedSource(
                'historical_snapshot',
                $this->safeSourceId('reply_snapshot', $requestedId, $requestedQuote),
                $this->payloadVersion($requestedQuote),
                $this->opaque($requestedId) ? (string) $requestedId : null,
                'modalities',
                'personal',
                'unvalidated',
                'unknown',
                $now,
                'unvalidated_reply_snapshot_omitted'
            );
        }
        if ($quote !== null) {
            $inputSources[] = [
                'source_id' => $quote['message_id'],
                'source_version' => hash('sha256', CanonicalJson::encode($quote)),
                'observed_at' => $quote['created_at'],
            ];
        }
        $productBindings = [];
        $requestedProductReferences = is_array($currentInput['product_reference_snapshots'] ?? null)
            ? $currentInput['product_reference_snapshots']
            : [];
        if (count($requestedProductReferences) > 100) {
            throw new ContextBundleException('context_bundle_input_reference_limit_exceeded');
        }
        $seenProductReferences = [];
        foreach ($requestedProductReferences as $reference) {
            $binding = is_array($reference) ? ProductReferenceIdentity::storedBinding($reference) : null;
            $sourceId = $binding['source_message_id'] ?? null;
            $referenceId = $binding['reference_id'] ?? null;
            $identity = $this->safeSourceId('product_reference', $referenceId ?? $sourceId, $reference);
            if (isset($seenProductReferences[$identity])) {
                continue;
            }
            $seenProductReferences[$identity] = true;
            $owned = $binding !== null && $this->opaque($sourceId)
                ? $this->store->visibleMessage(
                    $context->conversationId,
                    $context->actorType,
                    $context->actorId,
                    (string) $sourceId
                )
                : null;
            $ownedReferences = is_array($owned) && is_array($owned['product_references'] ?? null)
                && array_is_list($owned['product_references'])
                    ? $owned['product_references']
                    : [];
            $matched = $binding === null || !is_string($sourceId)
                ? null
                : ProductReferenceIdentity::match($ownedReferences, $sourceId, $binding);
            if ($matched !== null && count($productBindings) < 3) {
                $productBindings[] = $binding;
                $observed = $this->normalizedInstant($owned['created_at'] ?? null) ?? $now->format(DATE_ATOM);
                $inputSources[] = [
                    'source_id' => (string) $referenceId,
                    'source_message_id' => (string) $sourceId,
                    'source_version' => hash('sha256', CanonicalJson::encode([$binding, $observed])),
                    'observed_at' => $observed,
                ];
            } else {
                $excludedSources[] = $this->excludedSource(
                    'historical_snapshot',
                    $identity,
                    $this->payloadVersion($reference),
                    $this->opaque($sourceId) ? (string) $sourceId : null,
                    'modalities',
                    'personal',
                    'unvalidated',
                    'unknown',
                    $now,
                    count($productBindings) >= 3
                        ? 'product_reference_limit_exceeded'
                        : 'product_reference_binding_mismatch'
                );
            }
        }
        if (($currentInput['product_reference_snapshots'] ?? []) !== []) {
            $redactions[] = 'historical_product_snapshot_body_omitted';
        }
        $hint = $this->projectQuickReplyHint($currentInput['client_quick_reply_hint'] ?? null);
        if (($currentInput['client_quick_reply_hint'] ?? null) !== null && $hint === null) {
            $excludedSources[] = $this->excludedSource(
                'shopper_statement',
                $this->safeSourceId('quick_reply_hint', null, $currentInput['client_quick_reply_hint']),
                $this->payloadVersion($currentInput['client_quick_reply_hint']),
                $turnMessageId,
                'modalities',
                'personal',
                'unvalidated',
                'unknown',
                $now,
                'unvalidated_quick_reply_hint_omitted'
            );
        }

        $projected = [
            'message_id' => $turnMessageId,
            'text' => $text,
            'reply_quote' => $quote,
            'product_reference_bindings' => $productBindings,
            'quick_reply_hint' => $hint,
        ];
        $uniqueSources = [];
        foreach ($inputSources as $source) {
            $uniqueSources[(string) $source['source_id']] = $source;
        }
        $attachmentIds = is_array($currentInput['attachment_ids'] ?? null) ? $currentInput['attachment_ids'] : [];
        if (count($attachmentIds) > 100) {
            throw new ContextBundleException('context_bundle_input_reference_limit_exceeded');
        }
        $seenAttachments = [];
        foreach ($attachmentIds as $attachmentId) {
            $identity = $this->safeSourceId('attachment', $attachmentId, $attachmentId);
            if (isset($seenAttachments[$identity])) {
                continue;
            }
            $seenAttachments[$identity] = true;
            $excludedSources[] = $this->excludedSource(
                'protected_file',
                $identity,
                'unprojected',
                null,
                'modalities',
                'protected_file',
                'unvalidated',
                'unknown',
                $now,
                'unvalidated_attachment_omitted'
            );
        }
        if (($currentInput['location'] ?? null) !== null) {
            $excludedSources[] = $this->excludedSource(
                'shopper_statement',
                'location:' . $turnMessageId,
                $turnMessageId,
                $turnMessageId,
                'modalities',
                'sensitive_personal',
                'unvalidated',
                'unknown',
                $now,
                'unvalidated_location_omitted'
            );
        }
        return [$projected, array_values($uniqueSources), $excludedSources];
    }

    /** @return array<string, mixed>|null */
    private function projectQuote(mixed $value, \DateTimeImmutable $now): ?array
    {
        if (!is_array($value) || !$this->opaque($value['message_id'] ?? null)
            || !in_array($value['sender_type'] ?? null, ['customer', 'ai', 'staff', 'system'], true)
        ) {
            return null;
        }
        $text = is_array($value['content'] ?? null) && is_string($value['content']['text'] ?? null)
            ? $value['content']['text']
            : '';
        if (strlen($text) > 4000 || preg_match('//u', $text) !== 1) {
            return null;
        }
        $createdAt = $this->normalizedInstant($value['created_at'] ?? null) ?? $now->format(DATE_ATOM);
        return [
            'message_id' => (string) $value['message_id'],
            'sender_type' => (string) $value['sender_type'],
            'text' => $text,
            'created_at' => $createdAt,
        ];
    }

    /** @return array<string, string>|null */
    private function projectQuickReplyHint(mixed $value): ?array
    {
        if (!is_array($value)
            || array_diff(array_keys($value), ['schema_version', 'choice_id', 'pending_question_id']) !== []
            || array_diff(['schema_version', 'choice_id', 'pending_question_id'], array_keys($value)) !== []
            || ($value['schema_version'] ?? null) !== 'veyra.answer_binding.v1'
            || !$this->identifier($value['choice_id'] ?? null, 128)
            || !$this->opaque($value['pending_question_id'] ?? null)
        ) {
            return null;
        }
        return [
            'schema_version' => 'veyra.answer_binding.v1',
            'choice_id' => (string) $value['choice_id'],
            'pending_question_id' => (string) $value['pending_question_id'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function projectFocus(?ConversationFocus $focus, \DateTimeImmutable $now): ?array
    {
        if ($focus === null) {
            return null;
        }
        return [
            'version' => $focus->version,
            'foreground_journey_id' => $focus->foregroundJourneyId,
            'focused_resources' => $this->resourceMap($focus->focusedResources),
            'pending_question' => $focus->pendingQuestion !== null && $focus->pendingQuestion->isActive($now)
                ? $this->projectPendingQuestion($focus->pendingQuestion)
                : null,
            'unresolved_references' => $focus->unresolvedReferences,
            'source_message_id' => $focus->sourceMessageId,
            'updated_at' => $focus->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function projectPendingQuestion(PendingQuestion $question): array
    {
        return [
            'id' => $question->id,
            'journey_id' => $question->journeyId,
            'step_id' => $question->stepId,
            'message_id' => $question->messageId,
            'answer_schema' => $question->answerSchema,
            'allowed_choice_ids' => array_values(array_unique($question->allowedChoiceIds)),
            'focused_resources' => $this->resourceMap($question->focusedResources),
            'sensitivity' => $question->sensitivity,
            'created_at' => $question->createdAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'expires_at' => $question->expiresAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'dependency_versions' => $this->scalarMap($question->dependencyVersions, 6),
            'version' => $question->version,
        ];
    }

    /** @param list<JourneyState> $journeys @return array{0:?array,1:list<array{id:string,version:string}>,2:list<ContextBundleSource>} */
    private function projectJourneys(array $journeys, ?string $foregroundId): array
    {
        $foreground = null;
        $paused = [];
        $activeIds = [];
        $excluded = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($journeys as $journey) {
            if (!$journey instanceof JourneyState) {
                $excluded[] = $this->excludedSource(
                    'validated_state',
                    $this->safeSourceId('journey', null, $journey),
                    $this->payloadVersion($journey),
                    null,
                    'paused_journeys',
                    'personal',
                    'unvalidated',
                    'unknown',
                    $now,
                    'invalid_journey_state_omitted'
                );
                continue;
            }
            if ($journey->status === 'active') {
                $activeIds[] = $journey->id;
                if ($foregroundId !== null && $journey->id === $foregroundId) {
                    $foreground = $this->projectJourney($journey);
                }
                continue;
            }
            if ($journey->status === 'paused' && $this->opaque($journey->id)) {
                $paused[] = ['id' => $journey->id, 'version' => $journey->version];
                continue;
            }
            $excluded[] = $this->excludedSource(
                'validated_state',
                $this->safeSourceId('journey', $journey->id, $journey->id),
                $journey->version,
                null,
                'paused_journeys',
                'personal',
                'validated',
                'unknown',
                $now,
                'non_contextual_journey_omitted'
            );
        }
        if (($foregroundId === null && $activeIds !== [])
            || ($foregroundId !== null && ($activeIds !== [$foregroundId] || $foreground === null))
        ) {
            throw new ContextBundleException('context_bundle_journey_graph_inconsistent');
        }
        foreach (array_slice($paused, 20) as $omitted) {
            $excluded[] = $this->excludedSource(
                'validated_state',
                (string) $omitted['id'],
                (string) $omitted['version'],
                null,
                'paused_journeys',
                'personal',
                'validated',
                'fresh',
                $now,
                'paused_journey_window_exceeded'
            );
        }
        return [$foreground, array_slice($paused, 0, 20), $excluded];
    }

    /** @return array<string, mixed> */
    private function projectJourney(JourneyState $journey): array
    {
        return [
            'journey_id' => $journey->id,
            'type' => $journey->type,
            'version' => $journey->version,
            'status' => $journey->status,
            'current_step' => $journey->currentStep,
            'resume_step' => $journey->resumeStep,
            'open_question_ids' => array_values(array_unique(array_slice(array_filter(
                $journey->openQuestionIds,
                fn (mixed $id): bool => $this->opaque($id)
            ), 0, 30))),
            'related_resources' => $this->resourceMap($journey->relatedResources),
            'dependency_versions' => $this->scalarMap($journey->dependencyVersions, 30),
            'last_verified_checkpoint' => $journey->lastVerifiedCheckpoint,
        ];
    }

    /** @param array<int, array<string, mixed>> $raw @return array{0:list<array<string,mixed>>,1:list<ContextBundleSource>} */
    private function projectMessages(array $raw, string $turnMessageId): array
    {
        $messages = [];
        $excluded = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($raw as $message) {
            if (($message['message_id'] ?? null) === $turnMessageId) {
                continue;
            }
            $text = is_array($message['content'] ?? null) && is_string($message['content']['text'] ?? null)
                ? $message['content']['text']
                : null;
            $createdAt = $this->normalizedInstant($message['created_at'] ?? null);
            if (!$this->opaque($message['message_id'] ?? null)
                || !in_array($message['sender_type'] ?? null, ['customer', 'ai', 'staff', 'system'], true)
                || !is_string($text) || strlen($text) > 12000 || preg_match('//u', $text) !== 1
                || !is_string($message['language'] ?? null) || $message['language'] === '' || strlen($message['language']) > 35
                || !in_array($message['direction'] ?? null, ['ltr', 'rtl'], true)
                || (($message['reply_to_message_id'] ?? null) !== null && !$this->opaque($message['reply_to_message_id']))
                || $createdAt === null
            ) {
                $sourceId = is_array($message) ? ($message['message_id'] ?? null) : null;
                $excluded[] = $this->excludedSource(
                    'historical_snapshot',
                    $this->safeSourceId('visible_message', $sourceId, $message),
                    $this->payloadVersion($message),
                    $this->opaque($sourceId) ? (string) $sourceId : null,
                    'recent_visible_messages',
                    'personal',
                    'unvalidated',
                    'unknown',
                    $createdAt !== null ? new \DateTimeImmutable($createdAt) : $now,
                    'malformed_message_omitted'
                );
                continue;
            }
            $messages[] = [
                'message_id' => (string) $message['message_id'],
                'sender_type' => (string) $message['sender_type'],
                'text' => $text,
                'language' => (string) $message['language'],
                'direction' => (string) $message['direction'],
                'reply_to_message_id' => $message['reply_to_message_id'] !== null
                    ? (string) $message['reply_to_message_id']
                    : null,
                'created_at' => $createdAt,
            ];
        }
        foreach (array_slice($messages, 0, max(0, count($messages) - 24)) as $omitted) {
            $excluded[] = $this->excludedProjectedMessage($omitted, 'message_window_exceeded');
        }
        return [array_slice($messages, -24), $excluded];
    }

    /** @return array<string, mixed>|null */
    private function requirements(ToolContext $context): ?array
    {
        if (!$this->requirements instanceof RequirementStateService) {
            return null;
        }
        try {
            $resolved = $this->requirements->get(
                $context->conversationId,
                $context->actorType,
                $context->actorId
            );
        } catch (\Throwable) {
            throw new ContextBundleException('context_bundle_requirement_state_unavailable');
        }
        if (($resolved['ok'] ?? false) !== true
            || !is_int($resolved['resource_version'] ?? null)
            || !is_string($resolved['state_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $resolved['state_hash']) !== 1
            || !is_array($resolved['active_requirements'] ?? null)
            || !array_is_list($resolved['active_requirements'])
        ) {
            throw new ContextBundleException('context_bundle_requirement_state_unavailable');
        }
        return [
            'scope' => 'current_conversation_only',
            'resource_version' => $resolved['resource_version'],
            'state_hash' => $resolved['state_hash'],
            'active_requirements' => $resolved['active_requirements'],
            'durable_preference_memory_used' => false,
        ];
    }

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    private function projectRuntime(array $raw): array
    {
        return [
            'version' => is_int($raw['version'] ?? null) ? (string) $raw['version'] : ($raw['version'] ?? null),
            'utc' => $raw['utc'] ?? null,
            'local' => $raw['local'] ?? null,
            'timezone' => $raw['timezone'] ?? null,
            'locale' => $raw['locale'] ?? null,
            'feature_states' => $this->stringMap($raw['feature_states'] ?? [], 64),
        ];
    }

    /** @param array<string, mixed> $raw @return array{0:array<string,mixed>,1:list<ContextBundleSource>} */
    private function projectAuthority(array $raw, \DateTimeImmutable $now): array
    {
        $cart = is_array($raw['cart'] ?? null) ? $raw['cart'] : [];
        $available = ($cart['available'] ?? false) === true;
        $rawLines = $available && is_array($cart['lines'] ?? null) && array_is_list($cart['lines'])
            ? $cart['lines']
            : [];
        $lines = [];
        $excluded = [];
        $seenLineIds = [];
        $authorityVersion = is_int($raw['version'] ?? null)
            ? (string) $raw['version']
            : (is_string($raw['version'] ?? null) && $raw['version'] !== ''
                ? $raw['version']
                : 'woo_unavailable');
        if ($available && array_key_exists('lines', $cart)
            && (!is_array($cart['lines']) || !array_is_list($cart['lines']))
        ) {
            $excluded[] = $this->excludedSource(
                'woocommerce',
                $this->safeSourceId('cart_lines', null, $cart['lines']),
                $authorityVersion,
                null,
                'authoritative_state',
                'commerce_confidential',
                'unvalidated',
                'unknown',
                $now,
                'invalid_cart_line_collection_omitted'
            );
        }
        foreach ($rawLines as $line) {
            $lineId = is_array($line) ? ($line['line_id'] ?? null) : null;
            $invalid = !is_array($line)
                || !is_string($line['line_id'] ?? null) || $line['line_id'] === '' || strlen($line['line_id']) > 191
                || !is_int($line['product_id'] ?? null) || $line['product_id'] < 1
                || !is_int($line['variation_id'] ?? null) || $line['variation_id'] < 0
                || !is_string($line['name'] ?? null) || $line['name'] === '' || strlen($line['name']) > 500
                || (!is_int($line['quantity'] ?? null) && !is_float($line['quantity'] ?? null))
                || $line['quantity'] <= 0;
            if ($invalid || (is_string($lineId) && isset($seenLineIds[$lineId]))) {
                $excluded[] = $this->excludedCartLine(
                    $line,
                    $authorityVersion,
                    $now,
                    is_string($lineId) && isset($seenLineIds[$lineId])
                        ? 'duplicate_cart_line_omitted'
                        : 'invalid_cart_line_omitted'
                );
                continue;
            }
            $seenLineIds[(string) $lineId] = true;
            $lines[] = [
                'line_id' => $line['line_id'],
                'product_id' => $line['product_id'],
                'variation_id' => $line['variation_id'],
                'name' => $line['name'],
                'quantity' => $line['quantity'],
            ];
        }
        foreach (array_slice($lines, 50) as $omitted) {
            $excluded[] = $this->excludedCartLine(
                $omitted,
                $authorityVersion,
                $now,
                'cart_line_window_exceeded'
            );
        }
        $lines = array_slice($lines, 0, 50);
        $currency = is_string($cart['currency'] ?? null)
            && preg_match('/^[A-Z]{3}$/D', $cart['currency']) === 1
                ? $cart['currency']
                : null;
        $total = is_int($cart['total'] ?? null) || is_float($cart['total'] ?? null) || is_string($cart['total'] ?? null)
            ? (string) $cart['total']
            : null;
        if ($total !== null && ($total === '' || strlen($total) > 80)) {
            $total = null;
        }
        return [[
            'version' => $authorityVersion,
            'freshness' => in_array($raw['freshness'] ?? null, ['current', 'stale', 'unknown'], true)
                ? $raw['freshness']
                : 'unknown',
            'cart' => [
                'available' => $available,
                'hash' => $available && is_string($cart['hash'] ?? null) && $cart['hash'] !== '' && strlen($cart['hash']) <= 128
                    ? $cart['hash']
                    : null,
                'item_count' => $available && is_int($cart['item_count'] ?? null) && $cart['item_count'] >= 0
                    ? $cart['item_count']
                    : 0,
                'lines' => $lines,
                'currency' => $available ? $currency : null,
                'total' => $available ? $total : null,
                'lines_truncated' => $excluded !== [],
            ],
        ], $excluded];
    }

    /**
     * @param array<string, mixed>|null $focus
     * @param array<string, mixed>|null $foreground
     * @param list<string> $paused
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed> $memory
     * @param array<string, mixed>|null $requirements
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $authority
     * @param list<array<string, mixed>> $modalities
     * @return array<string, array<string, mixed>>
     */
    private function initialSelection(
        ?array $focus,
        ?array $foreground,
        array $paused,
        array $pausedExcluded,
        array $messages,
        array $messageExcluded,
        array $memory,
        ?array $requirements,
        array $summary,
        array $authority,
        array $authorityExcluded,
        array $modalities,
        array $inputExcluded
    ): array {
        $sections = [];
        $sections['current_input'] = $this->section('current_input', 1, 1, 0, ['current_turn_required'], []);
        $sections['focus'] = $this->section('focus', $focus === null ? 0 : 1, $focus === null ? 0 : 1, 0, $focus === null ? [] : ['current_validated_focus'], []);
        $sections['foreground_journey'] = $this->section('foreground_journey', $foreground === null ? 0 : 1, $foreground === null ? 0 : 1, 0, $foreground === null ? [] : ['focus_selected_foreground_journey'], []);
        $sections['paused_journeys'] = $this->section(
            'paused_journeys',
            count($paused) + count($pausedExcluded),
            count($paused),
            count($pausedExcluded),
            $paused === [] ? [] : ['actor_owned_paused_journey_ids'],
            $this->exclusionReasons($pausedExcluded)
        );
        $sections['recent_visible_messages'] = $this->section('recent_visible_messages', count($messages) + count($messageExcluded), count($messages), count($messageExcluded), $messages === [] ? [] : ['recent_actor_owned_visible_messages'], $this->exclusionReasons($messageExcluded));
        $sections['conversation_memory'] = $this->section('conversation_memory', $memory === [] ? 0 : 1, 0, $memory === [] ? 0 : 1, [], $memory === [] ? [] : ['validated_memory_contract_unavailable']);
        $requirementCount = $requirements === null ? 0 : 1 + count($requirements['active_requirements']);
        $requirementReasons = $requirements === null
            ? []
            : array_values(array_filter([
                'exact_actor_owned_requirement_head',
                $requirements['active_requirements'] === [] ? null : 'active_requirement_selected',
            ], 'is_string'));
        $sections['requirement_state'] = $this->section('requirement_state', $requirementCount, $requirementCount, 0, $requirementReasons, []);
        $sections['validated_summary'] = $this->section('validated_summary', $summary === [] ? 0 : 1, 0, $summary === [] ? 0 : 1, [], $summary === [] ? [] : ['validated_summary_contract_unavailable']);
        $sections['runtime_context'] = $this->section('runtime_context', 1, 1, 0, ['server_runtime_snapshot'], []);
        $authorityIncluded = 1 + count($authority['cart']['lines']);
        $authorityReasons = ['single_woocommerce_cart_snapshot'];
        if (($authority['cart']['lines'] ?? []) !== []) {
            $authorityReasons[] = 'current_cart_line_selected';
        }
        $sections['authoritative_state'] = $this->section('authoritative_state', $authorityIncluded + count($authorityExcluded), $authorityIncluded, count($authorityExcluded), $authorityReasons, $this->exclusionReasons($authorityExcluded));
        $sections['durable_preferences'] = $this->section('durable_preferences', 0, 0, 0, [], []);
        $sections['knowledge_evidence'] = $this->section('knowledge_evidence', 0, 0, 0, [], []);
        $sections['modalities'] = $this->section('modalities', count($modalities) + count($inputExcluded), count($modalities), count($inputExcluded), $modalities === [] ? [] : ['explicit_supported_turn_modalities'], $this->exclusionReasons($inputExcluded));
        return $sections;
    }

    /** @param list<string> $selected @param list<string> $excluded @return array<string, mixed> */
    private function section(string $name, int $available, int $included, int $excludedCount, array $selected, array $excluded): array
    {
        return [
            'section' => $name,
            'available_count' => $available,
            'included_count' => $included,
            'excluded_count' => $excludedCount,
            'truncated' => $excludedCount > 0,
            'selection_reasons' => $selected,
            'exclusion_reasons' => $excluded,
        ];
    }

    /** @param array<string, array<string, mixed>> $selection */
    private function excludeOne(array &$selection, string $section, string $reason): void
    {
        if (($selection[$section]['included_count'] ?? 0) < 1) {
            throw new ContextBundleException('context_bundle_limit_exceeded');
        }
        --$selection[$section]['included_count'];
        ++$selection[$section]['excluded_count'];
        $selection[$section]['truncated'] = true;
        if (!in_array($reason, $selection[$section]['exclusion_reasons'], true)) {
            $selection[$section]['exclusion_reasons'][] = $reason;
        }
    }

    /** @return array<string, mixed> */
    private function projection(
        string $bundleId,
        string $actorScopeId,
        string $siteScopeId,
        ToolContext $context,
        string $turnMessageId,
        \DateTimeImmutable $now,
        array $input,
        ?array $focus,
        ?array $foregroundJourney,
        array $pausedJourneys,
        array $messages,
        ?array $requirementState,
        array $runtime,
        array $authority,
        array $modalities,
        array $inputSources,
        array $selection,
        array $redactions
    ): array {
        $selectionManifest = [
            'included_count' => array_sum(array_column(array_values($selection), 'included_count')),
            'excluded_count' => array_sum(array_column(array_values($selection), 'excluded_count')),
            'truncated' => array_sum(array_column(array_values($selection), 'excluded_count')) > 0,
            'sections' => array_values($selection),
        ];
        $bundle = [
            'schema_version' => ContextBundleContract::SCHEMA_VERSION,
            'bundle_id' => $bundleId,
            'bundle_version' => ContextBundleContract::BUNDLE_VERSION,
            'conversation_id' => $context->conversationId,
            'turn_message_id' => $turnMessageId,
            'actor_scope' => [
                'site_id' => $siteScopeId,
                'actor_type' => $context->actorType === 'reviewer' ? 'payment_reviewer' : $context->actorType,
                'actor_id' => $actorScopeId,
            ],
            'purpose' => $this->policy->purpose,
            'focus' => $focus,
            'foreground_journey' => $foregroundJourney,
            'paused_journey_ids' => array_column($pausedJourneys, 'id'),
            'recent_visible_message_refs' => array_column($messages, 'message_id'),
            'conversation_memory_refs' => [],
            'summary_ref' => null,
            'authoritative_state_refs' => [[
                'resource_type' => 'cart_snapshot',
                'resource_id' => 'woocommerce_cart',
                'resource_version' => (string) $authority['version'],
                'ownership_state' => 'owned',
                'authority_source' => 'woocommerce_runtime',
            ]],
            'durable_preference_refs' => [],
            'knowledge_evidence_refs' => [],
            'modalities' => $modalities,
            'selected_data' => [
                'current_input' => $input,
                'recent_visible_messages' => $messages,
                'conversation_memory' => [],
                'requirement_state' => $requirementState,
                'validated_summary' => null,
                'runtime_context' => $runtime,
                'authoritative_state' => $authority,
            ],
            'source_manifest' => $this->sourceManifest(
                $turnMessageId,
                $now,
                $focus,
                $foregroundJourney,
                $pausedJourneys,
                $inputSources,
                $messages,
                $requirementState,
                $runtime,
                $authority
            ),
            'selection_manifest' => $selectionManifest,
            'privacy' => [
                'provider_route_id' => $this->policy->providerRouteId,
                'route_manifest_version' => $this->policy->routeManifestVersion,
                'transmission_authorized' => $this->policy->transmissionAuthorized,
                'decision_code' => $this->policy->transmissionDecisionCode,
                'purpose' => $this->policy->purpose,
                'allowed_data_classes' => $this->policy->allowedDataClasses,
                'redactions_applied' => $redactions,
            ],
            'limits' => [
                'max_bytes' => $this->policy->maximumBytes,
                'actual_bytes' => 0,
                'max_items' => $this->policy->maximumItems,
                'actual_items' => (int) $selectionManifest['included_count'],
            ],
            'assembled_at' => $now->format(DATE_ATOM),
            'expires_at' => $now->modify('+' . $this->policy->ttlSeconds . ' seconds')->format(DATE_ATOM),
        ];
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            try {
                $bytes = strlen(CanonicalJson::encode($bundle));
            } catch (\Throwable) {
                throw new ContextBundleException('context_bundle_encoding_failed');
            }
            if ($bundle['limits']['actual_bytes'] === $bytes) {
                return $bundle;
            }
            $bundle['limits']['actual_bytes'] = $bytes;
        }
        throw new ContextBundleException('context_bundle_limit_accounting_invalid');
    }

    /** @return list<array<string, mixed>> */
    private function sourceManifest(
        string $turnMessageId,
        \DateTimeImmutable $now,
        ?array $focus,
        ?array $journey,
        array $pausedJourneys,
        array $inputSources,
        array $messages,
        ?array $requirements,
        array $runtime,
        array $authority
    ): array {
        $sources = [
            $this->source('shopper_statement', $turnMessageId, $turnMessageId, $turnMessageId, 'current_input', 'personal', 'shopper_statement', 'current_turn_required', $now, 'fresh'),
            $this->source('runtime_context', 'runtime_context', (string) $runtime['version'], null, 'runtime_context', 'internal', 'authoritative', 'server_runtime_snapshot', $now, 'fresh'),
            $this->source('woocommerce', 'woocommerce_cart', (string) $authority['version'], null, 'authoritative_state', 'commerce_confidential', 'authoritative', 'single_woocommerce_cart_snapshot', $now, $authority['freshness'] === 'current' ? 'fresh' : 'unknown'),
        ];
        if ($focus !== null) {
            $sources[] = $this->source('validated_state', 'conversation_focus', (string) $focus['version'], (string) $focus['source_message_id'], 'focus', 'personal', 'validated', 'current_validated_focus', $now, 'fresh');
        }
        if ($journey !== null) {
            $sources[] = $this->source('validated_state', (string) $journey['journey_id'], (string) $journey['version'], null, 'foreground_journey', 'personal', 'validated', 'focus_selected_foreground_journey', $now, 'fresh');
        }
        foreach ($pausedJourneys as $pausedJourney) {
            $sources[] = $this->source('validated_state', (string) $pausedJourney['id'], (string) $pausedJourney['version'], null, 'paused_journeys', 'personal', 'validated', 'actor_owned_paused_journey_ids', $now, 'fresh');
        }
        foreach ($inputSources as $inputSource) {
            $sources[] = $this->source(
                'historical_snapshot',
                (string) $inputSource['source_id'],
                (string) $inputSource['source_version'],
                (string) ($inputSource['source_message_id'] ?? $inputSource['source_id']),
                'modalities',
                'personal',
                'historical',
                'explicit_supported_turn_modalities',
                new \DateTimeImmutable((string) $inputSource['observed_at']),
                'unknown'
            );
        }
        foreach ($messages as $message) {
            $observed = new \DateTimeImmutable((string) $message['created_at']);
            $sources[] = $this->source(
                'historical_snapshot',
                (string) $message['message_id'],
                hash('sha256', CanonicalJson::encode($message)),
                (string) $message['message_id'],
                'recent_visible_messages',
                'personal',
                'historical',
                'recent_actor_owned_visible_messages',
                $observed,
                'unknown'
            );
        }
        if ($requirements !== null) {
            $sources[] = $this->source(
                'validated_state',
                'requirement_state',
                (int) $requirements['resource_version'],
                null,
                'requirement_state',
                'personal',
                'validated',
                'exact_actor_owned_requirement_head',
                $now,
                'fresh'
            );
        }
        return $sources;
    }

    /** @return array<string, mixed> */
    private function source(
        string $class,
        string $id,
        int|string $version,
        ?string $messageId,
        string $section,
        string $classification,
        string $authority,
        string $reason,
        \DateTimeImmutable $observedAt,
        string $freshness
    ): array {
        return [
            'source' => [
                'source_class' => $class,
                'source_id' => $id,
                'source_version' => $version,
                'source_message_id' => $messageId,
            ],
            'version' => $version,
            'freshness' => [
                'state' => $freshness,
                'observed_at' => $observedAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
                'valid_until' => null,
                'source_version' => $version,
                'stale_reason' => $freshness === 'fresh' ? null : 'historical_or_unversioned_source',
            ],
            'classification' => $classification,
            'actor_scope_validated' => true,
            'section' => $section,
            'authority' => $authority,
            'purpose' => $this->policy->purpose,
            'selection_reason' => $reason,
        ];
    }

    /** @return array<string, mixed> */
    private function modality(string $type, string $sourceId): array
    {
        return [
            'type' => $type,
            'source_id' => $sourceId,
            'classification' => 'personal',
            'uncertainty_preserved' => true,
        ];
    }

    /**
     * Builds the exact per-item inclusion/exclusion ledger persisted outside
     * the provider projection. The provider sees only selected data; this
     * metadata ledger is for actor-scoped audit, export, erasure and retention.
     *
     * @param array<string, mixed> $bundle
     * @param list<ContextBundleSource> $excluded
     * @return list<ContextBundleSource>
     */
    private function sourceAccounting(array $bundle, array $excluded, \DateTimeImmutable $now): array
    {
        $included = [];
        $sourceVersions = [];
        foreach (is_array($bundle['source_manifest'] ?? null) ? $bundle['source_manifest'] : [] as $entry) {
            if (!is_array($entry) || !is_array($entry['source'] ?? null) || !is_array($entry['freshness'] ?? null)) {
                throw new ContextBundleException('context_bundle_manifest_accounting_invalid');
            }
            $source = $entry['source'];
            $sourceId = is_string($source['source_id'] ?? null) ? $source['source_id'] : '';
            $sourceVersions[$sourceId] = [
                'version' => $source['source_version'] ?? '',
                'observed_at' => $entry['freshness']['observed_at'] ?? $now->format(DATE_ATOM),
                'source_message_id' => is_string($source['source_message_id'] ?? null)
                    ? $source['source_message_id']
                    : null,
            ];
            // A modality is one selected item, even when two modality types
            // point at the same historical message. Build those below with a
            // type-qualified accounting identity.
            if (($entry['section'] ?? null) === 'modalities') {
                continue;
            }
            $included[] = new ContextBundleSource(
                is_string($source['source_class'] ?? null) ? $source['source_class'] : '',
                $sourceId,
                is_int($source['source_version'] ?? null) || is_string($source['source_version'] ?? null)
                    ? $source['source_version']
                    : '',
                is_string($source['source_message_id'] ?? null) ? $source['source_message_id'] : null,
                is_string($entry['section'] ?? null) ? $entry['section'] : '',
                is_string($entry['classification'] ?? null) ? $entry['classification'] : '',
                is_string($entry['authority'] ?? null) ? $entry['authority'] : '',
                is_string($entry['freshness']['state'] ?? null) ? $entry['freshness']['state'] : '',
                is_string($entry['freshness']['observed_at'] ?? null) ? $entry['freshness']['observed_at'] : '',
                'included',
                is_string($entry['selection_reason'] ?? null) ? $entry['selection_reason'] : ''
            );
        }

        $selected = is_array($bundle['selected_data'] ?? null) ? $bundle['selected_data'] : [];
        $requirements = is_array($selected['requirement_state'] ?? null)
            ? $selected['requirement_state']
            : null;
        if ($requirements !== null) {
            foreach ($requirements['active_requirements'] as $criterion) {
                if (!is_array($criterion)) {
                    throw new ContextBundleException('context_bundle_manifest_accounting_invalid');
                }
                $messageId = is_array($criterion['source'] ?? null)
                    && is_string($criterion['source']['message_id'] ?? null)
                        ? $criterion['source']['message_id']
                        : null;
                $included[] = new ContextBundleSource(
                    'validated_state',
                    is_string($criterion['id'] ?? null) ? $criterion['id'] : '',
                    is_int($criterion['version'] ?? null) ? $criterion['version'] : 0,
                    $messageId,
                    'requirement_state',
                    'personal',
                    'validated',
                    'fresh',
                    is_string($criterion['updated_at'] ?? null)
                        ? (new \DateTimeImmutable($criterion['updated_at']))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM)
                        : $now->format(DATE_ATOM),
                    'included',
                    'active_requirement_selected'
                );
            }
        }

        $authority = is_array($selected['authoritative_state'] ?? null)
            ? $selected['authoritative_state']
            : [];
        $authorityVersion = is_int($authority['version'] ?? null) || is_string($authority['version'] ?? null)
            ? $authority['version']
            : 'woo_unavailable';
        $cartLines = is_array($authority['cart']['lines'] ?? null) ? $authority['cart']['lines'] : [];
        foreach ($cartLines as $line) {
            if (!is_array($line)) {
                throw new ContextBundleException('context_bundle_manifest_accounting_invalid');
            }
            $included[] = new ContextBundleSource(
                'woocommerce',
                $this->safeSourceId('cart_line', $line['line_id'] ?? null, $line),
                $authorityVersion,
                null,
                'authoritative_state',
                'commerce_confidential',
                'authoritative',
                ($authority['freshness'] ?? null) === 'current' ? 'fresh' : 'unknown',
                $now->format(DATE_ATOM),
                'included',
                'current_cart_line_selected'
            );
        }

        foreach (is_array($bundle['modalities'] ?? null) ? $bundle['modalities'] : [] as $modality) {
            if (!is_array($modality) || !is_string($modality['type'] ?? null)
                || !is_string($modality['source_id'] ?? null)
            ) {
                throw new ContextBundleException('context_bundle_manifest_accounting_invalid');
            }
            $type = $modality['type'];
            $sourceId = $modality['source_id'];
            $metadata = $sourceVersions[$sourceId] ?? [
                'version' => $type === 'text' ? $bundle['turn_message_id'] : $this->payloadVersion($modality),
                'observed_at' => $now->format(DATE_ATOM),
            ];
            $included[] = new ContextBundleSource(
                $type === 'text' ? 'shopper_statement' : 'historical_snapshot',
                $this->safeSourceId($type . '_modality', $type . ':' . $sourceId, $modality),
                is_int($metadata['version']) || is_string($metadata['version']) ? $metadata['version'] : '',
                is_string($metadata['source_message_id'] ?? null) ? $metadata['source_message_id'] : $sourceId,
                'modalities',
                is_string($modality['classification'] ?? null) ? $modality['classification'] : 'personal',
                $type === 'text' ? 'shopper_statement' : 'historical',
                $type === 'text' ? 'fresh' : 'unknown',
                is_string($metadata['observed_at'] ?? null) ? $metadata['observed_at'] : $now->format(DATE_ATOM),
                'included',
                'explicit_supported_turn_modalities'
            );
        }

        foreach ($excluded as $source) {
            if (!$source instanceof ContextBundleSource || $source->disposition !== 'excluded') {
                throw new ContextBundleException('context_bundle_manifest_accounting_invalid');
            }
        }
        return array_merge($included, array_values($excluded));
    }

    private function excludedProjectedMessage(array $message, string $reason): ContextBundleSource
    {
        $observed = $this->normalizedInstant($message['created_at'] ?? null)
            ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        return $this->excludedSource(
            'historical_snapshot',
            $this->safeSourceId('visible_message', $message['message_id'] ?? null, $message),
            $this->payloadVersion($message),
            $this->opaque($message['message_id'] ?? null) ? (string) $message['message_id'] : null,
            'recent_visible_messages',
            'personal',
            'historical',
            'unknown',
            new \DateTimeImmutable($observed),
            $reason
        );
    }

    private function excludedCartLine(
        mixed $line,
        string $version,
        \DateTimeImmutable $now,
        string $reason
    ): ContextBundleSource {
        $lineId = is_array($line) ? ($line['line_id'] ?? null) : null;
        $identity = $reason === 'duplicate_cart_line_omitted'
            ? $this->safeSourceId('duplicate_cart_line', null, $line)
            : $this->safeSourceId('cart_line', $lineId, $line);
        return $this->excludedSource(
            'woocommerce',
            $identity,
            $version,
            null,
            'authoritative_state',
            'commerce_confidential',
            'unvalidated',
            'unknown',
            $now,
            $reason
        );
    }

    private function excludedSource(
        string $class,
        string $id,
        int|string $version,
        ?string $messageId,
        string $section,
        string $classification,
        string $authority,
        string $freshness,
        \DateTimeImmutable $observedAt,
        string $reason
    ): ContextBundleSource {
        return new ContextBundleSource(
            $class,
            $id,
            $version,
            $messageId,
            $section,
            $classification,
            $authority,
            $freshness,
            $observedAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'excluded',
            $reason
        );
    }

    /** @param list<ContextBundleSource> $sources @return list<string> */
    private function exclusionReasons(array $sources): array
    {
        $reasons = [];
        foreach ($sources as $source) {
            if ($source instanceof ContextBundleSource && !in_array($source->decisionReason, $reasons, true)) {
                $reasons[] = $source->decisionReason;
            }
        }
        return array_slice($reasons, 0, 20);
    }

    private function safeSourceId(string $prefix, mixed $candidate, mixed $payload): string
    {
        if ($this->identifier($candidate, 191)) {
            return (string) $candidate;
        }
        $prefix = preg_replace('/[^A-Za-z0-9._:-]/', '_', $prefix) ?? 'source';
        return substr($prefix, 0, 80) . ':' . substr($this->payloadVersion($payload), 0, 48);
    }

    private function payloadVersion(mixed $payload): string
    {
        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $encoded = get_debug_type($payload);
        }
        return hash('sha256', is_string($encoded) ? $encoded : get_debug_type($payload));
    }

    /**
     * Redacts provider-bound text only. Persisted visible messages remain
     * immutable; identity, versions, timestamps and source links are untouched.
     *
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $messages
     * @param list<string> $redactions
     * @return array{0:array<string,mixed>,1:list<array<string,mixed>>}
     */
    private function sanitizeProviderContent(
        array $input,
        ?array $focus,
        ?array $foregroundJourney,
        array $messages,
        ?array $requirements,
        array $authority,
        array &$redactions
    ): array {
        if (!$this->sanitizer instanceof ProviderOutboundSanitizer) {
            return [$input, $focus, $foregroundJourney, $messages, $requirements, $authority];
        }
        try {
            $input['text'] = $this->sanitizedText((string) ($input['text'] ?? ''), 12000, $redactions);
            if (is_array($input['reply_quote'] ?? null)) {
                $input['reply_quote']['text'] = $this->sanitizedText(
                    (string) ($input['reply_quote']['text'] ?? ''),
                    4000,
                    $redactions
                );
            }
            foreach ($messages as $index => $message) {
                if (!is_array($message)) {
                    throw new ContextBundleException('context_bundle_prohibited_data_sanitization_failed');
                }
                $messages[$index]['text'] = $this->sanitizedText(
                    (string) ($message['text'] ?? ''),
                    12000,
                    $redactions
                );
            }

            // Pending-question enum labels may be shopper-authored content;
            // IDs, versions, resource links and timestamps remain untouched.
            if (is_array($focus['pending_question']['answer_schema']['enum'] ?? null)) {
                $safeEnum = $this->sanitizedValue(
                    $focus['pending_question']['answer_schema']['enum'],
                    $redactions
                );
                if (!is_array($safeEnum) || !array_is_list($safeEnum)) {
                    throw new ContextBundleException('context_bundle_prohibited_data_sanitization_failed');
                }
                $focus['pending_question']['answer_schema']['enum'] = $safeEnum;
            }

            // Requirement values are the remaining recursively shaped
            // shopper-content surface. The durable requirement head and its
            // source hash remain unchanged; only this provider projection is
            // redacted before contract/hash/attestation issuance.
            if ($requirements !== null) {
                foreach ($requirements['active_requirements'] as $index => $criterion) {
                    if (!is_array($criterion) || !array_key_exists('value', $criterion)) {
                        throw new ContextBundleException('context_bundle_prohibited_data_sanitization_failed');
                    }
                    $requirements['active_requirements'][$index]['value'] = $this->sanitizedValue(
                        $criterion['value'],
                        $redactions
                    );
                }
            }

            // Product names are display content; cart line IDs, product IDs,
            // quantities, currency, totals, version and cart hash are
            // authoritative structural data and must retain exact equality.
            foreach ($authority['cart']['lines'] ?? [] as $index => $line) {
                if (!is_array($line)) {
                    throw new ContextBundleException('context_bundle_prohibited_data_sanitization_failed');
                }
                $authority['cart']['lines'][$index]['name'] = $this->sanitizedText(
                    (string) ($line['name'] ?? ''),
                    500,
                    $redactions
                );
            }
        } catch (ContextBundleException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new ContextBundleException('context_bundle_prohibited_data_sanitization_failed');
        }
        return [$input, $focus, $foregroundJourney, $messages, $requirements, $authority];
    }

    /** @param list<string> $redactions */
    private function sanitizedText(string $text, int $maximumBytes, array &$redactions): string
    {
        $value = $this->sanitizedValue($text, $redactions);
        if (!is_string($value)
            || strlen($value) > $maximumBytes
            || preg_match('//u', $value) !== 1
        ) {
            throw new ContextBundleException('context_bundle_prohibited_data_sanitization_failed');
        }
        return $value;
    }

    /** @param list<string> $redactions */
    private function sanitizedValue(mixed $value, array &$redactions): mixed
    {
        if (!$this->sanitizer instanceof ProviderOutboundSanitizer) {
            throw new ContextBundleException('context_bundle_prohibited_data_sanitization_failed');
        }
        $result = $this->sanitizer->redact($value);
        if (!is_array($result)
            || array_diff(array_keys($result), ['value', 'classifications', 'redactions']) !== []
            || array_diff(['value', 'classifications', 'redactions'], array_keys($result)) !== []
            || !is_array($result['classifications'] ?? null)
            || !array_is_list($result['classifications'])
            || !is_array($result['redactions'] ?? null)
            || !array_is_list($result['redactions'])
        ) {
            throw new ContextBundleException('context_bundle_prohibited_data_sanitization_failed');
        }
        foreach (array_merge($result['classifications'], $result['redactions']) as $classification) {
            if (!is_string($classification) || !$this->identifier($classification, 80)) {
                throw new ContextBundleException('context_bundle_prohibited_data_sanitization_failed');
            }
        }
        foreach ($result['redactions'] as $classification) {
            $marker = 'provider_redaction:' . $classification;
            if (!in_array($marker, $redactions, true)) {
                $redactions[] = $marker;
            }
        }
        return $result['value'];
    }

    /** @param array<string, string> $resources @return list<array{resource_type:string,resource_id:string}> */
    private function resourceMap(array $resources): array
    {
        $result = [];
        foreach ($resources as $type => $id) {
            if (is_string($type) && $type !== '' && strlen($type) <= 64 && $this->opaque($id)) {
                $result[] = ['resource_type' => $type, 'resource_id' => $id];
            }
        }
        if (count($result) > 20) {
            throw new ContextBundleException('context_bundle_resource_limit_exceeded');
        }
        usort($result, static fn (array $left, array $right): int => [$left['resource_type'], $left['resource_id']] <=> [$right['resource_type'], $right['resource_id']]);
        return $result;
    }

    /** @param array<string, int|string> $values @return array<string, int|string> */
    private function scalarMap(array $values, int $maximum): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || $key === '' || strlen($key) > 80
                || (!is_int($value) && (!is_string($value) || $value === '' || strlen($value) > 128))
            ) {
                continue;
            }
            $result[$key] = $value;
        }
        ksort($result, SORT_STRING);
        return array_slice($result, 0, $maximum, true);
    }

    /** @return array<string, string> */
    private function stringMap(mixed $values, int $maximum): array
    {
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new ContextBundleException('context_bundle_runtime_invalid');
        }
        $result = [];
        foreach ($values as $key => $value) {
            if (!is_string($key) || $key === '' || strlen($key) > 80
                || !is_string($value) || !in_array($value, ['On', 'Off', 'Degraded', 'Blocked'], true)
            ) {
                throw new ContextBundleException('context_bundle_runtime_invalid');
            }
            $result[$key] = $value;
        }
        if ($result === [] || count($result) > $maximum) {
            throw new ContextBundleException('context_bundle_runtime_invalid');
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function pseudonym(string $material): string
    {
        if (function_exists('wp_salt')) {
            $secret = (string) wp_salt('auth');
        } elseif (defined('AUTH_SALT') && is_string(AUTH_SALT) && AUTH_SALT !== '') {
            $secret = AUTH_SALT;
        } else {
            $secret = hash('sha256', __FILE__ . '|veyra-context-bundle-scope');
        }
        return 'scope_' . substr(hash_hmac('sha256', $material, $secret), 0, 32);
    }

    private function opaque(mixed $value): bool
    {
        return is_string($value) && strlen($value) >= 8 && strlen($value) <= 191
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }

    private function identifier(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }

    private function normalizedInstant(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 64) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
