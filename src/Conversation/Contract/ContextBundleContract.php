<?php
declare(strict_types=1);

namespace Veyra\Conversation\Contract;

// Internal validation exceptions are never rendered; adapters escape at the output boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use Veyra\AI\Tool\ToolContext;
use Veyra\Conversation\Domain\ContextBundleException;
use Veyra\Conversation\Domain\ContextBundlePolicy;
use Veyra\Requirements\Domain\RequirementCriterion;
use Veyra\Shared\Domain\CanonicalJson;

/**
 * Runtime enforcement for the checked-in Context Bundle wire contract.
 *
 * The generic tool-schema validator intentionally is not used here: it does
 * not resolve JSON Schema references. This boundary therefore validates the
 * complete provider projection, including unknown-field rejection.
 */
final class ContextBundleContract
{
    public const SCHEMA_VERSION = '1.1.0';
    public const BUNDLE_VERSION = 1;

    private const TOP_LEVEL_KEYS = [
        'schema_version', 'bundle_id', 'bundle_version', 'conversation_id',
        'turn_message_id', 'actor_scope', 'purpose', 'focus',
        'foreground_journey', 'paused_journey_ids',
        'recent_visible_message_refs', 'conversation_memory_refs',
        'summary_ref', 'authoritative_state_refs', 'durable_preference_refs',
        'knowledge_evidence_refs', 'modalities', 'selected_data',
        'source_manifest', 'selection_manifest', 'privacy', 'limits',
        'assembled_at', 'expires_at',
    ];

    private const SECTIONS = [
        'current_input', 'focus', 'foreground_journey', 'paused_journeys',
        'recent_visible_messages', 'conversation_memory', 'requirement_state',
        'validated_summary', 'runtime_context', 'authoritative_state',
        'durable_preferences', 'knowledge_evidence', 'modalities',
    ];

    /**
     * @param array<string, mixed> $bundle
     */
    public function assertValid(
        array $bundle,
        ToolContext $context,
        string $turnMessageId,
        ContextBundlePolicy $policy,
        string $expectedActorScopeId,
        string $expectedSiteScopeId
    ): void {
        $this->exact($bundle, self::TOP_LEVEL_KEYS);
        if (($bundle['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($bundle['bundle_version'] ?? null) !== self::BUNDLE_VERSION
            || !$this->opaque($bundle['bundle_id'] ?? null)
            || !$this->opaque($bundle['conversation_id'] ?? null)
            || !$this->opaque($bundle['turn_message_id'] ?? null)
            || ($bundle['conversation_id'] ?? null) !== $context->conversationId
            || ($bundle['turn_message_id'] ?? null) !== $turnMessageId
            || ($bundle['purpose'] ?? null) !== $policy->purpose
        ) {
            $this->fail('context_bundle_contract_invalid');
        }

        $actorScope = $this->object($bundle['actor_scope'] ?? null);
        $this->exact($actorScope, ['site_id', 'actor_type', 'actor_id']);
        $expectedActorType = $context->actorType === 'reviewer' ? 'payment_reviewer' : $context->actorType;
        if (($actorScope['site_id'] ?? null) !== $expectedSiteScopeId
            || ($actorScope['actor_type'] ?? null) !== $expectedActorType
            || ($actorScope['actor_id'] ?? null) !== $expectedActorScopeId
            || !$this->opaque($actorScope['site_id'] ?? null)
            || !$this->opaque($actorScope['actor_id'] ?? null)
            || $expectedActorScopeId === $context->actorId
        ) {
            $this->fail('context_bundle_actor_scope_invalid');
        }

        $focus = $bundle['focus'] ?? null;
        if ($focus !== null) {
            $this->focus($this->object($focus));
        }
        $journey = $bundle['foreground_journey'] ?? null;
        if ($journey !== null) {
            $this->journey($this->object($journey));
        }
        $pausedJourneyIds = $this->journeyIdList($bundle['paused_journey_ids'] ?? null, 20);
        $messageRefs = $this->stringList($bundle['recent_visible_message_refs'] ?? null, 24);
        $memoryRefs = $this->stringList($bundle['conversation_memory_refs'] ?? null, 100);
        $durableRefs = $this->stringList($bundle['durable_preference_refs'] ?? null, 50);
        $knowledgeRefs = $this->stringList($bundle['knowledge_evidence_refs'] ?? null, 80);
        if ($memoryRefs !== [] || $durableRefs !== [] || $knowledgeRefs !== []
            || (($bundle['summary_ref'] ?? null) !== null && !$this->opaque($bundle['summary_ref']))
        ) {
            $this->fail('context_bundle_unvalidated_continuity_state');
        }
        if (($bundle['summary_ref'] ?? null) !== null) {
            $this->fail('context_bundle_unvalidated_continuity_state');
        }
        $authorityRefs = $this->resourceReferences($bundle['authoritative_state_refs'] ?? null);
        $modalities = $this->modalities($bundle['modalities'] ?? null, $policy);

        $selected = $this->object($bundle['selected_data'] ?? null);
        $this->exact($selected, [
            'current_input', 'recent_visible_messages', 'conversation_memory',
            'requirement_state', 'validated_summary', 'runtime_context',
            'authoritative_state',
        ]);
        $this->currentInput($this->object($selected['current_input'] ?? null), $turnMessageId);
        $messages = $this->messages($selected['recent_visible_messages'] ?? null);
        if ($messageRefs !== array_column($messages, 'message_id')) {
            $this->fail('context_bundle_reference_mismatch');
        }
        if (($selected['conversation_memory'] ?? null) !== []
            || ($selected['validated_summary'] ?? null) !== null
        ) {
            $this->fail('context_bundle_unvalidated_continuity_state');
        }
        $this->requirementState($selected['requirement_state'] ?? null);
        $this->runtimeContext($this->object($selected['runtime_context'] ?? null));
        $this->authoritativeState($this->object($selected['authoritative_state'] ?? null));

        $sources = $this->sources($bundle['source_manifest'] ?? null, $policy);
        $selection = $this->selection($bundle['selection_manifest'] ?? null);
        $privacy = $this->privacy($bundle['privacy'] ?? null, $policy);
        $limits = $this->limits($bundle['limits'] ?? null, $policy);

        if (($selection['included_count'] ?? null) !== $this->includedItems($selection)
            || ($selection['excluded_count'] ?? null) !== $this->excludedItems($selection)
            || ($selection['truncated'] ?? null) !== ($this->excludedItems($selection) > 0)
            || ($limits['actual_items'] ?? null) !== $selection['included_count']
            || $limits['actual_items'] > $limits['max_items']
        ) {
            $this->fail('context_bundle_limit_accounting_invalid');
        }
        $this->assertProjectionAlignment(
            $bundle,
            $selected,
            $messages,
            $pausedJourneyIds,
            $authorityRefs,
            $modalities,
            $sources,
            $selection
        );
        if (($privacy['transmission_authorized'] ?? null) !== $policy->transmissionAuthorized) {
            $this->fail('context_bundle_transmission_state_invalid');
        }

        $assembled = $this->instant($bundle['assembled_at'] ?? null);
        $expires = $this->instant($bundle['expires_at'] ?? null);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($expires <= $assembled
            || $expires->getTimestamp() - $assembled->getTimestamp() > $policy->ttlSeconds
            || $assembled > $now->modify('+5 seconds')
            || $expires <= $now
            || $assembled->getOffset() !== 0
            || $expires->getOffset() !== 0
        ) {
            $this->fail('context_bundle_expiry_invalid');
        }

        try {
            $actualBytes = strlen(CanonicalJson::encode($bundle));
        } catch (\Throwable) {
            $this->fail('context_bundle_encoding_failed');
        }
        if (($limits['actual_bytes'] ?? null) !== $actualBytes || $actualBytes > $limits['max_bytes']) {
            $this->fail('context_bundle_limit_accounting_invalid');
        }
    }

    /** @param array<string, mixed> $selection */
    public function includedItems(array $selection): int
    {
        $total = 0;
        foreach (is_array($selection['sections'] ?? null) ? $selection['sections'] : [] as $section) {
            $total += is_array($section) && is_int($section['included_count'] ?? null)
                ? $section['included_count']
                : 0;
        }
        return $total;
    }

    /** @param array<string, mixed> $selection */
    public function excludedItems(array $selection): int
    {
        $total = 0;
        foreach (is_array($selection['sections'] ?? null) ? $selection['sections'] : [] as $section) {
            $total += is_array($section) && is_int($section['excluded_count'] ?? null)
                ? $section['excluded_count']
                : 0;
        }
        return $total;
    }

    /** @param array<string, mixed> $value */
    private function focus(array $value): void
    {
        $this->exact($value, [
            'version', 'foreground_journey_id', 'focused_resources',
            'pending_question', 'unresolved_references', 'source_message_id',
            'updated_at',
        ]);
        if (!$this->boundedString($value['version'] ?? null, 128)
            || (($value['foreground_journey_id'] ?? null) !== null && !$this->journeyId($value['foreground_journey_id']))
            || !$this->opaque($value['source_message_id'] ?? null)
        ) {
            $this->fail('context_bundle_focus_invalid');
        }
        $this->focusedResources($value['focused_resources'] ?? null);
        $this->stringList($value['unresolved_references'] ?? null, 10);
        $this->instant($value['updated_at'] ?? null);
        if (($value['pending_question'] ?? null) !== null) {
            $pending = $this->object($value['pending_question']);
            $this->pendingQuestion($pending);
            if (($value['foreground_journey_id'] ?? null) === null
                || ($pending['journey_id'] ?? null) !== $value['foreground_journey_id']
            ) {
                $this->fail('context_bundle_pending_question_journey_mismatch');
            }
        }
    }

    /** @param array<string, mixed> $value */
    private function pendingQuestion(array $value): void
    {
        $this->exact($value, [
            'id', 'journey_id', 'step_id', 'message_id', 'answer_schema',
            'allowed_choice_ids', 'focused_resources', 'sensitivity',
            'created_at', 'expires_at', 'dependency_versions', 'version',
        ]);
        foreach (['id', 'message_id'] as $key) {
            if (!$this->opaque($value[$key] ?? null)) {
                $this->fail('context_bundle_pending_question_invalid');
            }
        }
        if (!$this->journeyId($value['journey_id'] ?? null)) {
            $this->fail('context_bundle_pending_question_invalid');
        }
        if (!$this->boundedString($value['step_id'] ?? null, 96)
            || !in_array($value['sensitivity'] ?? null, ['informational', 'state_changing', 'confirmation_sensitive'], true)
            || !is_int($value['version'] ?? null) || $value['version'] < 1
        ) {
            $this->fail('context_bundle_pending_question_invalid');
        }
        $answer = $this->object($value['answer_schema'] ?? null);
        if (array_diff(array_keys($answer), ['type', 'enum']) !== []
            || !in_array($answer['type'] ?? null, ['string', 'integer', 'number', 'boolean', 'object', 'array'], true)
        ) {
            $this->fail('context_bundle_pending_question_invalid');
        }
        if (isset($answer['enum']) && (!is_array($answer['enum']) || !array_is_list($answer['enum']) || count($answer['enum']) > 16)) {
            $this->fail('context_bundle_pending_question_invalid');
        }
        $this->identifierList($value['allowed_choice_ids'] ?? null, 8, 128);
        $this->focusedResources($value['focused_resources'] ?? null);
        $this->scalarMap($value['dependency_versions'] ?? null, 6);
        $created = $this->instant($value['created_at'] ?? null);
        $expires = $this->instant($value['expires_at'] ?? null);
        if ($expires <= $created) {
            $this->fail('context_bundle_pending_question_invalid');
        }
    }

    /** @param array<string, mixed> $value */
    private function journey(array $value): void
    {
        $this->exact($value, [
            'journey_id', 'type', 'version', 'status', 'current_step',
            'resume_step', 'open_question_ids', 'related_resources',
            'dependency_versions', 'last_verified_checkpoint',
        ]);
        if (!$this->journeyId($value['journey_id'] ?? null)
            || !$this->boundedString($value['type'] ?? null, 80)
            || !$this->boundedString($value['version'] ?? null, 128)
            || ($value['status'] ?? null) !== 'active'
            || !$this->boundedString($value['current_step'] ?? null, 120)
            || !$this->boundedString($value['resume_step'] ?? null, 160)
            || (($value['last_verified_checkpoint'] ?? null) !== null
                && !$this->boundedString($value['last_verified_checkpoint'], 191))
        ) {
            $this->fail('context_bundle_journey_invalid');
        }
        $this->stringList($value['open_question_ids'] ?? null, 30);
        $this->focusedResources($value['related_resources'] ?? null);
        $this->scalarMap($value['dependency_versions'] ?? null, 30);
    }

    /** @param mixed $value @return list<array<string, mixed>> */
    private function messages(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 24) {
            $this->fail('context_bundle_messages_invalid');
        }
        $ids = [];
        foreach ($value as $message) {
            $message = $this->object($message);
            $this->exact($message, [
                'message_id', 'sender_type', 'text', 'language', 'direction',
                'reply_to_message_id', 'created_at',
            ]);
            if (!$this->opaque($message['message_id'] ?? null)
                || isset($ids[$message['message_id']])
                || !in_array($message['sender_type'] ?? null, ['customer', 'ai', 'staff', 'system'], true)
                || !is_string($message['text'] ?? null) || strlen($message['text']) > 12000
                || !$this->boundedString($message['language'] ?? null, 35)
                || !in_array($message['direction'] ?? null, ['ltr', 'rtl'], true)
                || (($message['reply_to_message_id'] ?? null) !== null && !$this->opaque($message['reply_to_message_id']))
            ) {
                $this->fail('context_bundle_messages_invalid');
            }
            $this->instant($message['created_at'] ?? null);
            $ids[$message['message_id']] = true;
        }
        return $value;
    }

    /** @param array<string, mixed> $value */
    private function currentInput(array $value, string $turnMessageId): void
    {
        $this->exact($value, [
            'message_id', 'text', 'reply_quote',
            'product_reference_bindings', 'quick_reply_hint',
        ]);
        if (($value['message_id'] ?? null) !== $turnMessageId
            || !is_string($value['text'] ?? null) || trim($value['text']) === '' || strlen($value['text']) > 12000
        ) {
            $this->fail('context_bundle_current_input_invalid');
        }
        $this->productReferenceBindings($value['product_reference_bindings'] ?? null);
        if (($value['reply_quote'] ?? null) !== null) {
            $quote = $this->object($value['reply_quote']);
            $this->exact($quote, ['message_id', 'sender_type', 'text', 'created_at']);
            if (!$this->opaque($quote['message_id'] ?? null)
                || !in_array($quote['sender_type'] ?? null, ['customer', 'ai', 'staff', 'system'], true)
                || !is_string($quote['text'] ?? null) || strlen($quote['text']) > 4000
            ) {
                $this->fail('context_bundle_current_input_invalid');
            }
            $this->instant($quote['created_at'] ?? null);
        }
        if (($value['quick_reply_hint'] ?? null) !== null) {
            $hint = $this->object($value['quick_reply_hint']);
            $this->exact($hint, ['schema_version', 'choice_id', 'pending_question_id']);
            if (($hint['schema_version'] ?? null) !== 'veyra.answer_binding.v1'
                || !$this->identifier($hint['choice_id'] ?? null, 128)
                || !$this->opaque($hint['pending_question_id'] ?? null)
            ) {
                $this->fail('context_bundle_current_input_invalid');
            }
        }
    }

    private function requirementState(mixed $value): void
    {
        if ($value === null) {
            return;
        }
        $value = $this->object($value);
        $this->exact($value, [
            'scope', 'resource_version', 'state_hash', 'active_requirements',
            'durable_preference_memory_used',
        ]);
        if (($value['scope'] ?? null) !== 'current_conversation_only'
            || !is_int($value['resource_version'] ?? null) || $value['resource_version'] < 0
            || !is_string($value['state_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $value['state_hash']) !== 1
            || ($value['durable_preference_memory_used'] ?? null) !== false
            || !is_array($value['active_requirements'] ?? null)
            || !array_is_list($value['active_requirements'])
            || count($value['active_requirements']) > 64
        ) {
            $this->fail('context_bundle_requirement_state_invalid');
        }
        foreach ($value['active_requirements'] as $criterion) {
            try {
                $criterion = RequirementCriterion::fromStored($this->object($criterion));
            } catch (\Throwable) {
                $this->fail('context_bundle_requirement_state_invalid');
            }
            if ($criterion->status !== 'active') {
                $this->fail('context_bundle_requirement_state_invalid');
            }
        }
    }

    /** @param array<string, mixed> $value */
    private function runtimeContext(array $value): void
    {
        $this->exact($value, ['version', 'utc', 'local', 'timezone', 'locale', 'feature_states']);
        if (!$this->boundedString($value['version'] ?? null, 128)
            || !$this->boundedString($value['timezone'] ?? null, 80)
            || !$this->boundedString($value['locale'] ?? null, 35)
        ) {
            $this->fail('context_bundle_runtime_invalid');
        }
        $this->instant($value['utc'] ?? null);
        $this->instant($value['local'] ?? null);
        $states = $this->object($value['feature_states'] ?? null);
        if ($states === [] || count($states) > 64) {
            $this->fail('context_bundle_runtime_invalid');
        }
        foreach ($states as $key => $state) {
            if (!$this->boundedString($key, 80) || !in_array($state, ['On', 'Off', 'Degraded', 'Blocked'], true)) {
                $this->fail('context_bundle_runtime_invalid');
            }
        }
    }

    /** @param array<string, mixed> $value */
    private function authoritativeState(array $value): void
    {
        $this->exact($value, ['version', 'freshness', 'cart']);
        if (!$this->boundedString($value['version'] ?? null, 128)
            || !in_array($value['freshness'] ?? null, ['current', 'stale', 'unknown'], true)
        ) {
            $this->fail('context_bundle_authority_invalid');
        }
        $cart = $this->object($value['cart'] ?? null);
        $this->exact($cart, [
            'available', 'hash', 'item_count', 'lines', 'currency', 'total',
            'lines_truncated',
        ]);
        if (!is_bool($cart['available'] ?? null)
            || (($cart['hash'] ?? null) !== null && !$this->boundedString($cart['hash'], 128))
            || !is_int($cart['item_count'] ?? null) || $cart['item_count'] < 0
            || !is_bool($cart['lines_truncated'] ?? null)
            || (($cart['currency'] ?? null) !== null
                && (!is_string($cart['currency']) || preg_match('/^[A-Z]{3}$/D', $cart['currency']) !== 1))
            || (($cart['total'] ?? null) !== null && !$this->boundedString($cart['total'], 80))
            || !is_array($cart['lines'] ?? null) || !array_is_list($cart['lines'])
            || count($cart['lines']) > 50
        ) {
            $this->fail('context_bundle_authority_invalid');
        }
        foreach ($cart['lines'] as $line) {
            $line = $this->object($line);
            $this->exact($line, ['line_id', 'product_id', 'variation_id', 'name', 'quantity']);
            if (!$this->boundedString($line['line_id'] ?? null, 191)
                || !is_int($line['product_id'] ?? null) || $line['product_id'] < 1
                || !is_int($line['variation_id'] ?? null) || $line['variation_id'] < 0
                || !$this->boundedString($line['name'] ?? null, 500)
                || (!is_int($line['quantity'] ?? null) && !is_float($line['quantity'] ?? null))
                || $line['quantity'] <= 0
            ) {
                $this->fail('context_bundle_authority_invalid');
            }
        }
        if ($cart['available'] === false
            && ($cart['hash'] !== null || $cart['item_count'] !== 0 || $cart['lines'] !== []
                || $cart['currency'] !== null || $cart['total'] !== null)
        ) {
            $this->fail('context_bundle_authority_invalid');
        }
    }

    /** @return list<array<string, mixed>> */
    private function modalities(mixed $value, ContextBundlePolicy $policy): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            $this->fail('context_bundle_modalities_invalid');
        }
        $seen = [];
        foreach ($value as $modality) {
            $modality = $this->object($modality);
            $this->exact($modality, ['type', 'source_id', 'classification', 'uncertainty_preserved']);
            if (!in_array($modality['type'] ?? null, ['text', 'reply_quote', 'product_reference'], true)
                || !$this->opaque($modality['source_id'] ?? null)
                || !in_array($modality['classification'] ?? null, $policy->allowedDataClasses, true)
                || ($modality['uncertainty_preserved'] ?? null) !== true
                || isset($seen[(string) ($modality['type'] ?? '') . '|' . (string) ($modality['source_id'] ?? '')])
            ) {
                $this->fail('context_bundle_modalities_invalid');
            }
            $seen[(string) $modality['type'] . '|' . (string) $modality['source_id']] = true;
        }
        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function sources(mixed $value, ContextBundlePolicy $policy): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            $this->fail('context_bundle_sources_invalid');
        }
        $seen = [];
        foreach ($value as $entry) {
            $entry = $this->object($entry);
            $this->exact($entry, [
                'source', 'version', 'freshness', 'classification',
                'actor_scope_validated', 'section', 'authority', 'purpose',
                'selection_reason',
            ]);
            $source = $this->object($entry['source'] ?? null);
            $this->exact($source, ['source_class', 'source_id', 'source_version', 'source_message_id']);
            $key = (string) ($entry['section'] ?? '') . '|' . (string) ($source['source_id'] ?? '');
            if (!in_array($source['source_class'] ?? null, [
                'woocommerce', 'shopper_statement', 'historical_snapshot',
                'validated_state', 'runtime_context',
            ], true)
                || !$this->opaque($source['source_id'] ?? null)
                || !$this->boundedScalarVersion($source['source_version'] ?? null)
                || (($source['source_message_id'] ?? null) !== null && !$this->opaque($source['source_message_id']))
                || !$this->boundedScalarVersion($entry['version'] ?? null)
                || !in_array($entry['classification'] ?? null, $policy->allowedDataClasses, true)
                || ($entry['actor_scope_validated'] ?? null) !== true
                || !in_array($entry['section'] ?? null, self::SECTIONS, true)
                || !in_array($entry['authority'] ?? null, ['shopper_statement', 'historical', 'validated', 'authoritative'], true)
                || ($entry['purpose'] ?? null) !== $policy->purpose
                || !$this->boundedString($entry['selection_reason'] ?? null, 160)
                || isset($seen[$key])
            ) {
                $this->fail('context_bundle_sources_invalid');
            }
            $freshness = $this->object($entry['freshness'] ?? null);
            $this->freshness($freshness);
            if ($source['source_version'] !== $entry['version']
                || $entry['version'] !== ($freshness['source_version'] ?? null)
            ) {
                $this->fail('context_bundle_sources_invalid');
            }
            $seen[$key] = true;
        }
        return $value;
    }

    /** @param array<string, mixed> $value */
    private function freshness(array $value): void
    {
        $this->exact($value, ['state', 'observed_at', 'valid_until', 'source_version', 'stale_reason']);
        if (!in_array($value['state'] ?? null, ['fresh', 'stale', 'unknown', 'expired'], true)
            || (($value['source_version'] ?? null) !== null && !$this->boundedScalarVersion($value['source_version']))
            || (($value['stale_reason'] ?? null) !== null && !$this->boundedString($value['stale_reason'], 240))
        ) {
            $this->fail('context_bundle_sources_invalid');
        }
        $this->instant($value['observed_at'] ?? null);
        if (($value['valid_until'] ?? null) !== null) {
            $this->instant($value['valid_until']);
        }
    }

    /** @return array<string, mixed> */
    private function selection(mixed $value): array
    {
        $value = $this->object($value);
        $this->exact($value, ['included_count', 'excluded_count', 'truncated', 'sections']);
        if (!is_int($value['included_count'] ?? null) || $value['included_count'] < 0
            || !is_int($value['excluded_count'] ?? null) || $value['excluded_count'] < 0
            || !is_bool($value['truncated'] ?? null)
            || !is_array($value['sections'] ?? null) || !array_is_list($value['sections'])
            || count($value['sections']) !== count(self::SECTIONS)
        ) {
            $this->fail('context_bundle_selection_invalid');
        }
        $seen = [];
        foreach ($value['sections'] as $section) {
            $section = $this->object($section);
            $this->exact($section, [
                'section', 'available_count', 'included_count', 'excluded_count',
                'truncated', 'selection_reasons', 'exclusion_reasons',
            ]);
            $name = $section['section'] ?? null;
            if (!is_string($name) || !in_array($name, self::SECTIONS, true) || isset($seen[$name])
                || !is_int($section['available_count'] ?? null) || $section['available_count'] < 0
                || !is_int($section['included_count'] ?? null) || $section['included_count'] < 0
                || !is_int($section['excluded_count'] ?? null) || $section['excluded_count'] < 0
                || $section['available_count'] !== $section['included_count'] + $section['excluded_count']
                || !is_bool($section['truncated'] ?? null)
                || $section['truncated'] !== ($section['excluded_count'] > 0)
            ) {
                $this->fail('context_bundle_selection_invalid');
            }
            $this->boundedStringList($section['selection_reasons'] ?? null, 20, 160);
            $this->boundedStringList($section['exclusion_reasons'] ?? null, 20, 160);
            $seen[$name] = true;
        }
        if (array_diff(self::SECTIONS, array_keys($seen)) !== []) {
            $this->fail('context_bundle_selection_invalid');
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function privacy(mixed $value, ContextBundlePolicy $policy): array
    {
        $value = $this->object($value);
        $this->exact($value, [
            'provider_route_id', 'route_manifest_version',
            'transmission_authorized', 'decision_code', 'purpose',
            'allowed_data_classes', 'redactions_applied',
        ]);
        if (($value['provider_route_id'] ?? null) !== $policy->providerRouteId
            || ($value['route_manifest_version'] ?? null) !== $policy->routeManifestVersion
            || ($value['decision_code'] ?? null) !== $policy->transmissionDecisionCode
            || ($value['purpose'] ?? null) !== $policy->purpose
            || ($value['allowed_data_classes'] ?? null) !== $policy->allowedDataClasses
        ) {
            $this->fail('context_bundle_privacy_invalid');
        }
        $this->boundedStringList($value['redactions_applied'] ?? null, 30, 160);
        return $value;
    }

    /** @return array<string, mixed> */
    private function limits(mixed $value, ContextBundlePolicy $policy): array
    {
        $value = $this->object($value);
        $this->exact($value, ['max_bytes', 'actual_bytes', 'max_items', 'actual_items']);
        if (($value['max_bytes'] ?? null) !== $policy->maximumBytes
            || ($value['max_items'] ?? null) !== $policy->maximumItems
            || !is_int($value['actual_bytes'] ?? null) || $value['actual_bytes'] < 1
            || !is_int($value['actual_items'] ?? null) || $value['actual_items'] < 1
        ) {
            $this->fail('context_bundle_limit_accounting_invalid');
        }
        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function resourceReferences(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            $this->fail('context_bundle_resource_reference_invalid');
        }
        foreach ($value as $reference) {
            $reference = $this->object($reference);
            $this->exact($reference, [
                'resource_type', 'resource_id', 'resource_version',
                'ownership_state', 'authority_source',
            ]);
            if (!$this->boundedString($reference['resource_type'] ?? null, 80)
                || !$this->opaque($reference['resource_id'] ?? null)
                || !$this->boundedScalarVersion($reference['resource_version'] ?? null)
                || !in_array($reference['ownership_state'] ?? null, ['owned', 'authorized_public'], true)
                || !$this->boundedString($reference['authority_source'] ?? null, 120)
            ) {
                $this->fail('context_bundle_resource_reference_invalid');
            }
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $selected
     * @param list<array<string, mixed>> $messages
     * @param list<string> $pausedJourneyIds
     * @param list<array<string, mixed>> $authorityRefs
     * @param list<array<string, mixed>> $modalities
     * @param list<array<string, mixed>> $sources
     * @param array<string, mixed> $selection
     */
    private function assertProjectionAlignment(
        array $bundle,
        array $selected,
        array $messages,
        array $pausedJourneyIds,
        array $authorityRefs,
        array $modalities,
        array $sources,
        array $selection
    ): void {
        $focus = $bundle['focus'];
        $journey = $bundle['foreground_journey'];
        $focusedJourneyId = is_array($focus) ? ($focus['foreground_journey_id'] ?? null) : null;
        if (($journey === null) !== ($focusedJourneyId === null)
            || (is_array($journey) && ($journey['journey_id'] ?? null) !== $focusedJourneyId)
            || ($focusedJourneyId !== null && in_array($focusedJourneyId, $pausedJourneyIds, true))
            || in_array((string) $bundle['turn_message_id'], array_column($messages, 'message_id'), true)
        ) {
            $this->fail('context_bundle_reference_mismatch');
        }
        $authority = $selected['authoritative_state'];
        if (count($authorityRefs) !== 1
            || ($authorityRefs[0]['resource_type'] ?? null) !== 'cart_snapshot'
            || ($authorityRefs[0]['resource_id'] ?? null) !== 'woocommerce_cart'
            || (string) ($authorityRefs[0]['resource_version'] ?? '') !== (string) ($authority['version'] ?? '')
        ) {
            $this->fail('context_bundle_reference_mismatch');
        }

        $requirements = $selected['requirement_state'];
        $expectedIncluded = [
            'current_input' => 1,
            'focus' => $focus === null ? 0 : 1,
            'foreground_journey' => $journey === null ? 0 : 1,
            'paused_journeys' => count($pausedJourneyIds),
            'recent_visible_messages' => count($messages),
            'conversation_memory' => 0,
            'requirement_state' => is_array($requirements) ? 1 + count($requirements['active_requirements']) : 0,
            'validated_summary' => 0,
            'runtime_context' => 1,
            'authoritative_state' => 1 + count($authority['cart']['lines']),
            'durable_preferences' => 0,
            'knowledge_evidence' => 0,
            'modalities' => count($bundle['modalities']),
        ];
        foreach ($selection['sections'] as $section) {
            $name = $section['section'];
            if (($expectedIncluded[$name] ?? null) !== $section['included_count']) {
                $this->fail('context_bundle_selection_invalid');
            }
        }

        $actualSources = [];
        foreach ($sources as $source) {
            $actualSources[(string) $source['section']][] = (string) $source['source']['source_id'];
        }
        $modalitySourceIds = [];
        $actualModalityBindings = [];
        foreach ($modalities as $modality) {
            $actualModalityBindings[] = [(string) $modality['type'], (string) $modality['source_id']];
            if (($modality['type'] ?? null) !== 'text') {
                $modalitySourceIds[(string) $modality['source_id']] = true;
            }
        }
        $input = $selected['current_input'];
        $expectedModalityBindings = [['text', (string) $bundle['turn_message_id']]];
        if (is_array($input['reply_quote'] ?? null)) {
            $expectedModalityBindings[] = ['reply_quote', (string) $input['reply_quote']['message_id']];
        }
        foreach ($input['product_reference_bindings'] as $reference) {
            $expectedModalityBindings[] = ['product_reference', (string) $reference['reference_id']];
        }
        if ($actualModalityBindings !== $expectedModalityBindings) {
            $this->fail('context_bundle_modalities_invalid');
        }
        $expectedSources = [
            'current_input' => [(string) $bundle['turn_message_id']],
            'runtime_context' => ['runtime_context'],
            'authoritative_state' => ['woocommerce_cart'],
            'focus' => $focus === null ? [] : ['conversation_focus'],
            'foreground_journey' => $journey === null ? [] : [(string) $journey['journey_id']],
            'paused_journeys' => $pausedJourneyIds,
            'modalities' => array_keys($modalitySourceIds),
            'recent_visible_messages' => array_column($messages, 'message_id'),
            'requirement_state' => is_array($requirements) ? ['requirement_state'] : [],
        ];
        foreach ($expectedSources as $section => $ids) {
            if (($actualSources[$section] ?? []) !== $ids) {
                $this->fail('context_bundle_source_coverage_invalid');
            }
            unset($actualSources[$section]);
        }
        if ($actualSources !== []) {
            $this->fail('context_bundle_source_coverage_invalid');
        }
        $selectionReasons = [];
        foreach ($selection['sections'] as $section) {
            $selectionReasons[(string) $section['section']] = $section['selection_reasons'];
        }
        foreach ($sources as $source) {
            if (!in_array($source['selection_reason'], $selectionReasons[$source['section']] ?? [], true)) {
                $this->fail('context_bundle_selection_invalid');
            }
        }
    }

    private function focusedResources(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            $this->fail('context_bundle_resource_reference_invalid');
        }
        foreach ($value as $reference) {
            $reference = $this->object($reference);
            $this->exact($reference, ['resource_type', 'resource_id']);
            if (!$this->boundedString($reference['resource_type'] ?? null, 64)
                || !$this->opaque($reference['resource_id'] ?? null)
            ) {
                $this->fail('context_bundle_resource_reference_invalid');
            }
        }
    }

    /** @return array<string, int|string> */
    private function scalarMap(mixed $value, int $maximum): array
    {
        $value = $this->object($value);
        if (count($value) > $maximum) {
            $this->fail('context_bundle_scalar_map_invalid');
        }
        foreach ($value as $key => $item) {
            if (!$this->boundedString($key, 80)
                || (!is_int($item) && !$this->boundedString($item, 128))
            ) {
                $this->fail('context_bundle_scalar_map_invalid');
            }
        }
        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            $this->fail('context_bundle_list_invalid');
        }
        $seen = [];
        foreach ($value as $item) {
            if (!$this->opaque($item) || isset($seen[$item])) {
                $this->fail('context_bundle_list_invalid');
            }
            $seen[$item] = true;
        }
        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function productReferenceBindings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 3) {
            $this->fail('context_bundle_current_input_invalid');
        }
        $seen = [];
        foreach ($value as $binding) {
            $binding = $this->object($binding);
            $this->exact($binding, [
                'schema_version', 'reference_id', 'source_message_id',
                'product_id', 'variation_id',
            ]);
            if (($binding['schema_version'] ?? null) !== 'veyra.product_reference_binding.v1'
                || !$this->opaque($binding['reference_id'] ?? null)
                || !$this->opaque($binding['source_message_id'] ?? null)
                || !is_int($binding['product_id'] ?? null) || $binding['product_id'] < 1
                || !is_int($binding['variation_id'] ?? null) || $binding['variation_id'] < 0
                || isset($seen[$binding['reference_id']])
            ) {
                $this->fail('context_bundle_current_input_invalid');
            }
            $seen[$binding['reference_id']] = true;
        }

        return $value;
    }

    private function boundedStringList(mixed $value, int $maximum, int $length): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            $this->fail('context_bundle_list_invalid');
        }
        foreach ($value as $item) {
            if (!$this->boundedString($item, $length)) {
                $this->fail('context_bundle_list_invalid');
            }
        }
    }

    private function identifierList(mixed $value, int $maximum, int $length): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            $this->fail('context_bundle_list_invalid');
        }
        $seen = [];
        foreach ($value as $item) {
            if (!$this->identifier($item, $length) || isset($seen[$item])) {
                $this->fail('context_bundle_list_invalid');
            }
            $seen[$item] = true;
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail('context_bundle_contract_invalid');
        }
        return $value;
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function exact(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            $this->fail('context_bundle_unknown_or_missing_field');
        }
    }

    private function opaque(mixed $value): bool
    {
        return is_string($value) && strlen($value) >= 8 && strlen($value) <= 191
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }

    private function journeyId(mixed $value): bool
    {
        return is_string($value) && strlen($value) >= 8 && strlen($value) <= 36
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }

    /** @return list<string> */
    private function journeyIdList(mixed $value, int $maximum): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            $this->fail('context_bundle_list_invalid');
        }
        $seen = [];
        foreach ($value as $item) {
            if (!$this->journeyId($item) || isset($seen[$item])) {
                $this->fail('context_bundle_list_invalid');
            }
            $seen[$item] = true;
        }
        return $value;
    }

    private function boundedString(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum
            && preg_match('//u', $value) === 1;
    }

    private function identifier(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }

    private function boundedScalarVersion(mixed $value): bool
    {
        return (is_int($value) && $value >= 0)
            || (is_string($value) && $value !== '' && strlen($value) <= 128 && preg_match('//u', $value) === 1);
    }

    private function instant(mixed $value): \DateTimeImmutable
    {
        if (!is_string($value) || strlen($value) > 64
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1
        ) {
            $this->fail('context_bundle_timestamp_invalid');
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            $this->fail('context_bundle_timestamp_invalid');
        }
    }

    private function fail(string $code): never
    {
        throw new ContextBundleException($code);
    }
}
