<?php
declare(strict_types=1);

namespace Veyra\Conversation\Application;

use Veyra\Conversation\Domain\ConversationFocus;

final class ShortReplyBindingValidator
{
    /**
     * The model proposes semantics. This service validates only the exact target,
     * expected shape, current dependency versions, ownership scope and sensitivity.
     *
     * @param array<string, mixed>      $proposal
     * @param array<string, int|string> $currentDependencyVersions
     * @return array{valid:bool,code:string,value:mixed}
     */
    public function validate(
        ConversationFocus $focus,
        array $proposal,
        array $currentDependencyVersions,
        \DateTimeImmutable $now
    ): array {
        $question = $focus->pendingQuestion;
        if ($question === null || !$question->isActive($now)) {
            return ['valid' => false, 'code' => 'pending_question_unavailable', 'value' => null];
        }
        if (($proposal['question_id'] ?? null) !== $question->id || ($proposal['focus_version'] ?? null) !== $focus->version) {
            return ['valid' => false, 'code' => 'binding_target_mismatch', 'value' => null];
        }
        $targetResourceIds = $proposal['target_resource_ids'] ?? null;
        if (!is_array($targetResourceIds)
            || !array_is_list($targetResourceIds)
            || count($targetResourceIds) > 20
        ) {
            return ['valid' => false, 'code' => 'binding_resource_scope_invalid', 'value' => null];
        }
        foreach ($targetResourceIds as $resourceId) {
            if (!is_string($resourceId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $resourceId) !== 1) {
                return ['valid' => false, 'code' => 'binding_resource_scope_invalid', 'value' => null];
            }
        }
        if (count(array_unique($targetResourceIds, SORT_STRING)) !== count($targetResourceIds)) {
            return ['valid' => false, 'code' => 'binding_resource_scope_invalid', 'value' => null];
        }
        $expectedResourceIds = array_values(array_filter($question->focusedResources, 'is_string'));
        sort($expectedResourceIds, SORT_STRING);
        $actualResourceIds = $targetResourceIds;
        sort($actualResourceIds, SORT_STRING);
        if ($actualResourceIds !== $expectedResourceIds) {
            return ['valid' => false, 'code' => 'binding_resource_scope_mismatch', 'value' => null];
        }
        foreach ($question->dependencyVersions as $key => $version) {
            if (!array_key_exists($key, $currentDependencyVersions) || (string) $currentDependencyVersions[$key] !== (string) $version) {
                return ['valid' => false, 'code' => 'binding_state_stale', 'value' => null];
            }
        }
        if ($question->sensitivity === 'confirmation_sensitive' && empty($proposal['confirmation_id'])) {
            return ['valid' => false, 'code' => 'confirmation_binding_required', 'value' => null];
        }
        $value = $proposal['value'] ?? null;
        if (!$this->matchesExpectedSchema($value, $question->answerSchema)) {
            return ['valid' => false, 'code' => 'answer_schema_mismatch', 'value' => null];
        }
        if ($question->allowedChoiceIds !== [] && (!is_string($value) || !in_array($value, $question->allowedChoiceIds, true))) {
            return ['valid' => false, 'code' => 'answer_choice_invalid', 'value' => null];
        }
        return ['valid' => true, 'code' => 'binding_valid', 'value' => $value];
    }

    /** @param array<string, mixed> $schema */
    private function matchesExpectedSchema(mixed $value, array $schema): bool
    {
        $type = $schema['type'] ?? 'string';
        $valid = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'object' => is_array($value) && !array_is_list($value),
            'array' => is_array($value) && array_is_list($value),
            default => false,
        };
        if (!$valid) {
            return false;
        }
        return !isset($schema['enum']) || (is_array($schema['enum']) && in_array($value, $schema['enum'], true));
    }
}
