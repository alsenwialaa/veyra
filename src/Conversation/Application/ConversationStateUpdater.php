<?php
declare(strict_types=1);

namespace Veyra\Conversation\Application;

use Veyra\Conversation\Domain\ConversationFocus;
use Veyra\Conversation\Domain\PendingQuestion;

final class ConversationStateUpdater
{
    public function __construct(private readonly ConversationStore $store)
    {
    }

    /**
     * @param array<string, mixed>  $proposal
     * @param array<string, array<string, true>> $authorizedResources
     * @return array{focus_updated:bool,memory_updated:bool,warnings:array<int,string>}
     */
    public function applyValidatedProposal(
        string $conversationId,
        string $actorType,
        string $actorId,
        string $focusSourceMessageId,
        string $memoryUpdateSourceMessageId,
        array $proposal,
        array $authorizedResources
    ): array {
        $warnings = [];
        $focusUpdated = false;
        $memoryUpdated = false;
        $currentFocus = $this->store->focus($conversationId, $actorType, $actorId);

        if (is_array($proposal['focus'] ?? null)) {
            $candidate = $this->buildFocus(
                $proposal['focus'],
                $focusSourceMessageId,
                $authorizedResources,
                (int) ($currentFocus?->version ?? 0) + 1,
                $currentFocus?->foregroundJourneyId
            );
            if ($candidate !== null) {
                $focusUpdated = $this->store->saveFocus(
                    $conversationId,
                    $actorType,
                    $actorId,
                    $candidate,
                    $currentFocus?->version ?? '0'
                );
                if (!$focusUpdated) {
                    $warnings[] = 'focus_version_conflict';
                }
            } else {
                $warnings[] = 'focus_update_rejected';
            }
        }

        if (is_array($proposal['memory'] ?? null)) {
            if ($proposal['memory'] !== []) {
                // Exact excerpt membership proves provenance only. It does not
                // prove polarity, field/value entailment, correction intent, or
                // that a proposed preference should become authoritative state.
                // Keep the model-proposed memory boundary fail-closed until a
                // typed server validator and pending-answer CAS promotion path
                // are composed.
                $warnings[] = 'memory_update_binding_required';
            }
        }
        return ['focus_updated' => $focusUpdated, 'memory_updated' => $memoryUpdated, 'warnings' => $warnings];
    }

    /** @param array<string, mixed> $data @param array<string, array<string, true>> $authorizedResources */
    private function buildFocus(
        array $data,
        string $sourceMessageId,
        array $authorizedResources,
        int $nextVersion,
        ?string $currentJourneyId
    ): ?ConversationFocus
    {
        if (array_diff(array_keys($data), [
            'foreground_journey_id', 'focused_resources', 'pending_question', 'unresolved_references',
        ])) {
            return null;
        }
        if (array_key_exists('focused_resources', $data)
            && !is_array($data['focused_resources'])
        ) {
            return null;
        }
        $resources = is_array($data['focused_resources'] ?? null) ? $data['focused_resources'] : [];
        if (count($resources) > 20 || ($resources !== [] && array_is_list($resources))) {
            return null;
        }
        foreach ($resources as $kind => $id) {
            if (!is_string($kind) || !is_string($id) || strlen($kind) > 64 || strlen($id) > 191
                || !isset($authorizedResources[$kind][$id])
            ) {
                return null;
            }
        }
        $proposedJourneyId = $data['foreground_journey_id'] ?? null;
        if ($proposedJourneyId !== null
            && (!is_string($proposedJourneyId)
                // Both conversation_focus.foreground_journey_id and
                // pending_questions.journey_id are char(36). Never accept a
                // model-proposed identifier that persistence would truncate.
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,35}$/D', $proposedJourneyId) !== 1)
        ) {
            return null;
        }
        $journeyId = is_string($proposedJourneyId) ? $proposedJourneyId : null;
        if ($journeyId !== null
            && !isset($authorizedResources['journey'][$journeyId])
            && $journeyId !== $currentJourneyId
        ) {
            // Journey IDs must be server-authorized. New journey persistence is
            // handled by the journey service, never by a model-proposed opaque ID.
            return null;
        }
        if (array_key_exists('pending_question', $data)
            && $data['pending_question'] !== null
            && !is_array($data['pending_question'])
        ) {
            return null;
        }
        $unresolvedReferences = $this->unresolvedReferences($data['unresolved_references'] ?? []);
        if ($unresolvedReferences === null) {
            return null;
        }

        $pending = null;
        if (is_array($data['pending_question'] ?? null)) {
            $question = $data['pending_question'];
            if (array_diff(array_keys($question), [
                'step_id', 'answer_schema', 'allowed_choice_ids', 'sensitivity',
                'ttl_seconds', 'dependency_versions',
            ])) {
                return null;
            }
            $stepId = $question['step_id'] ?? null;
            $sensitivity = $question['sensitivity'] ?? null;
            if ($journeyId === null
                || !is_string($stepId) || $stepId === '' || strlen($stepId) > 96
                || !in_array($sensitivity, ['informational', 'state_changing', 'confirmation_sensitive'], true)
            ) {
                return null;
            }
            $answerSchema = $this->answerSchema($question['answer_schema'] ?? null);
            $allowedChoiceIds = $this->choiceIds($question['allowed_choice_ids'] ?? []);
            $dependencyVersions = $this->dependencyVersions($question['dependency_versions'] ?? []);
            if ($answerSchema === null || $allowedChoiceIds === null || $dependencyVersions === null) {
                return null;
            }
            $ttl = $question['ttl_seconds'] ?? 900;
            if (!is_int($ttl) || $ttl < 60 || $ttl > 3600) {
                return null;
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            try {
                $pending = new PendingQuestion(
                    'pq_' . bin2hex(random_bytes(16)),
                    $journeyId,
                    $stepId,
                    $sourceMessageId,
                    $answerSchema,
                    $allowedChoiceIds,
                    $resources,
                    $sensitivity,
                    $now,
                    $now->modify('+' . $ttl . ' seconds'),
                    $dependencyVersions
                );
            } catch (\Throwable) {
                return null;
            }
        }
        return new ConversationFocus(
            (string) $nextVersion,
            $journeyId,
            $resources,
            $pending,
            $unresolvedReferences,
            $sourceMessageId,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    /** @return array<string, mixed>|null */
    private function answerSchema(mixed $value): ?array
    {
        if (!is_array($value) || array_diff(array_keys($value), ['type', 'enum'])) {
            return null;
        }
        $type = $value['type'] ?? null;
        if (!in_array($type, ['string', 'integer', 'number', 'boolean', 'object', 'array'], true)) {
            return null;
        }
        $schema = ['type' => $type];
        if (array_key_exists('enum', $value)) {
            if (!is_array($value['enum']) || !array_is_list($value['enum']) || count($value['enum']) > 16) {
                return null;
            }
            foreach ($value['enum'] as $candidate) {
                $valid = match ($type) {
                    'string' => is_string($candidate) && strlen($candidate) <= 191,
                    'integer' => is_int($candidate),
                    'number' => is_int($candidate) || is_float($candidate),
                    'boolean' => is_bool($candidate),
                    default => false,
                };
                if (!$valid) {
                    return null;
                }
            }
            $schema['enum'] = array_values(array_unique($value['enum'], SORT_REGULAR));
        }
        return $schema;
    }

    /** @return list<string>|null */
    private function choiceIds(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 8) {
            return null;
        }
        $result = [];
        foreach ($value as $choice) {
            if (!is_string($choice) || $choice === '' || strlen($choice) > 128
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $choice) !== 1
            ) {
                return null;
            }
            $result[] = $choice;
        }
        return count(array_unique($result)) === count($result) ? $result : null;
    }

    /** @return list<string>|null */
    private function unresolvedReferences(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 10) {
            return null;
        }
        $seen = [];
        foreach ($value as $reference) {
            if (!is_string($reference)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,190}$/D', $reference) !== 1
                || isset($seen[$reference])
            ) {
                return null;
            }
            $seen[$reference] = true;
        }

        return $value;
    }

    /** @return array<string, int|string>|null */
    private function dependencyVersions(mixed $value): ?array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 6) {
            return null;
        }
        $allowed = ['runtime', 'runtime_version', 'cart', 'cart_hash', 'commerce', 'commerce_version'];
        $result = [];
        foreach ($value as $key => $version) {
            if (!is_string($key) || !in_array($key, $allowed, true)
                || (!is_int($version) && !is_string($version))
                || (is_string($version) && ($version === '' || strlen($version) > 128))
            ) {
                return null;
            }
            $result[$key] = $version;
        }
        return $result;
    }
}
