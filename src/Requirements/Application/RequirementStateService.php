<?php
declare(strict_types=1);

namespace Veyra\Requirements\Application;

use Veyra\Conversation\Application\ConversationStore;
use Veyra\Requirements\Contract\RequirementStateRepository;
use Veyra\Requirements\Domain\RequirementCriterion;
use Veyra\Requirements\Domain\RequirementState;
use Veyra\Shared\Domain\Clock;

final class RequirementStateService
{
    private const MAXIMUM_RECORDS = 64;

    public function __construct(
        private readonly ConversationStore $conversations,
        private readonly RequirementStateRepository $states,
        private readonly Clock $clock
    ) {
    }

    /** @return array<string, mixed> */
    public function get(string $conversationId, string $actorType, string $actorId): array
    {
        if ($this->conversations->getOwnedConversation($conversationId, $actorType, $actorId) === null) {
            return ['ok' => false, 'code' => 'conversation_not_owned'];
        }
        $loaded = $this->load($conversationId, $actorType, $actorId);
        if (($loaded['ok'] ?? false) !== true) {
            return $loaded;
        }
        /** @var RequirementState $state */
        $state = $loaded['state'];
        $records = $state->criteria;
        return [
            'ok' => true,
            'scope' => 'current_conversation_only',
            'conversation_id' => $conversationId,
            'resource_version' => $state->resourceVersion,
            'state_hash' => $state->stateHash,
            // Retained temporarily for older internal consumers. It is a hash,
            // never the optimistic-concurrency resource version.
            'version' => $state->stateHash,
            'requirements' => array_map(static fn (RequirementCriterion $record): array => $record->toArray(), $records),
            'active_requirements' => array_values(array_map(
                static fn (RequirementCriterion $record): array => $record->toArray(),
                array_filter($records, static fn (RequirementCriterion $record): bool => $record->status === 'active')
            )),
            'durable_preference_memory_used' => false,
        ];
    }

    /**
     * @param array<int, mixed> $changes
     * @return array<string, mixed>
     */
    public function proposeUpdate(
        string $conversationId,
        string $actorType,
        string $actorId,
        int $expectedResourceVersion,
        string $expectedStateHash,
        string $sourceMessageId,
        array $changes
    ): array {
        if ($this->conversations->getOwnedConversation($conversationId, $actorType, $actorId) === null) {
            return ['ok' => false, 'code' => 'conversation_not_owned'];
        }
        $message = $this->conversations->visibleMessage($conversationId, $actorType, $actorId, $sourceMessageId);
        if (!is_array($message) || ($message['sender_type'] ?? null) !== 'customer') {
            return ['ok' => false, 'code' => 'requirements_source_message_invalid'];
        }
        $text = $message['content']['text'] ?? null;
        if (!is_string($text) || $text === '') {
            return ['ok' => false, 'code' => 'requirements_source_text_unavailable'];
        }
        if ($changes === [] || count($changes) > 16) {
            return ['ok' => false, 'code' => 'requirements_change_set_invalid'];
        }
        if ($expectedResourceVersion < 0 || preg_match('/^[a-f0-9]{64}$/D', $expectedStateHash) !== 1) {
            return ['ok' => false, 'code' => 'requirements_expected_state_invalid'];
        }

        $loaded = $this->load($conversationId, $actorType, $actorId);
        if (($loaded['ok'] ?? false) !== true) {
            return $loaded;
        }
        /** @var RequirementState $state */
        $state = $loaded['state'];
        $records = $state->criteria;
        if ($state->resourceVersion !== $expectedResourceVersion
            || !hash_equals($state->stateHash, $expectedStateHash)
        ) {
            return ['ok' => false, 'code' => 'requirements_version_conflict'];
        }
        $now = $this->clock->now()->toIso8601();
        $changedIds = [];
        try {
            foreach ($changes as $change) {
                if (!is_array($change)) {
                    throw new \InvalidArgumentException('Requirement change must be an object.');
                }
                $excerpt = $this->sourceExcerpt($change, $text);
                $source = [
                    'message_id' => $sourceMessageId,
                    'excerpt_sha256' => hash('sha256', $excerpt),
                    'excerpt_offset_bytes' => (int) strpos($text, $excerpt),
                    'excerpt_length_bytes' => strlen($excerpt),
                    'source_kind' => 'customer_visible_message',
                ];
                $operation = $change['operation'] ?? null;
                if ($operation === 'upsert' || $operation === 'correct') {
                    $target = $operation === 'correct' ? $this->requiredTarget($change) : null;
                    if ($target !== null && $this->find($records, $target) === null) {
                        throw new \DomainException('requirements_target_unavailable');
                    }
                    $superseded = [];
                    $newStatus = $this->requiredString($change, 'status');
                    if ($operation === 'correct' && $newStatus !== 'active') {
                        throw new \DomainException('requirements_correction_must_be_active');
                    }
                    foreach ($records as $index => $record) {
                        $sameField = $newStatus === 'active' && $this->sameSlot($record, $change);
                        $targeted = $target !== null && $record->id === $target;
                        if (($sameField && $record->status === 'active') || $targeted) {
                            $superseded[] = $record->id;
                        }
                    }
                    $new = RequirementCriterion::proposed(
                        $this->requiredString($change, 'field'),
                        $this->requiredString($change, 'operator'),
                        $change['value'] ?? null,
                        $this->requiredString($change, 'strength'),
                        $newStatus,
                        $source,
                        $superseded,
                        $now
                    );
                    foreach ($records as $index => $record) {
                        if (in_array($record->id, $superseded, true)) {
                            $records[$index] = $record->withStatus('superseded', $new->id, $sourceMessageId, $now);
                            $changedIds[] = $record->id;
                        }
                    }
                    $records[] = $new;
                    $changedIds[] = $new->id;
                    continue;
                }
                if ($operation === 'dispute' || $operation === 'remove') {
                    $target = $this->requiredTarget($change);
                    $index = $this->findIndex($records, $target);
                    if ($index === null) {
                        throw new \DomainException('requirements_target_unavailable');
                    }
                    $records[$index] = $records[$index]->withStatus(
                        $operation === 'dispute' ? 'disputed' : 'superseded',
                        $operation === 'remove' ? 'removed' : null,
                        $sourceMessageId,
                        $now
                    );
                    $changedIds[] = $target;
                    continue;
                }
                throw new \DomainException('requirements_operation_invalid');
            }
        } catch (\DomainException $error) {
            return ['ok' => false, 'code' => $error->getMessage()];
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'requirements_change_set_invalid'];
        }
        if (count($records) > self::MAXIMUM_RECORDS) {
            return ['ok' => false, 'code' => 'requirements_history_limit_reached'];
        }

        try {
            $next = $state->next($records, $sourceMessageId, $now);
            $written = $this->states->compareAndSwap($state, $next);
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'requirements_write_failed'];
        }
        if (!$written) {
            return ['ok' => false, 'code' => 'requirements_write_conflict'];
        }
        return [
            'ok' => true,
            'scope' => 'current_conversation_only',
            'conversation_id' => $conversationId,
            'resource_version' => $next->resourceVersion,
            'state_hash' => $next->stateHash,
            'version' => $next->stateHash,
            'changed_requirement_ids' => array_values(array_unique($changedIds)),
            'requirements' => $next->criteriaArray(),
            'durable_preference_memory_written' => false,
        ];
    }

    public function isCurrent(
        string $conversationId,
        string $actorType,
        string $actorId,
        int $resourceVersion,
        string $stateHash
    ): bool {
        if ($resourceVersion < 0 || preg_match('/^[a-f0-9]{64}$/D', $stateHash) !== 1
            || $this->conversations->getOwnedConversation($conversationId, $actorType, $actorId) === null
        ) {
            return false;
        }
        $loaded = $this->load($conversationId, $actorType, $actorId);
        if (($loaded['ok'] ?? false) !== true) {
            return false;
        }
        /** @var RequirementState $state */
        $state = $loaded['state'];

        return $state->resourceVersion === $resourceVersion && hash_equals($state->stateHash, $stateHash);
    }

    /** @return array{ok:bool,state?:RequirementState,code?:string} */
    private function load(string $conversationId, string $actorType, string $actorId): array
    {
        try {
            $stored = $this->states->loadOwned($conversationId, $actorType, $actorId);
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'requirements_state_invalid'];
        }
        if ($stored !== null) {
            return ['ok' => true, 'state' => $stored];
        }

        // Upgrade preservation: old releases stored this bounded history in
        // actor-owned conversation memory. Import it lazily without modifying
        // that unrelated memory blob. The unique head insert is the CAS.
        try {
            $memory = $this->conversations->memory($conversationId, $actorType, $actorId);
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'requirements_state_invalid'];
        }
        $raw = $memory['requirements'] ?? [];
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAXIMUM_RECORDS) {
            return ['ok' => false, 'code' => 'requirements_state_invalid'];
        }
        $records = [];
        $ids = [];
        try {
            $importedAt = $this->clock->now()->toIso8601();
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('Requirement row is invalid.');
                }
                $record = RequirementCriterion::fromStored($row);
                $this->validateLegacySource($conversationId, $actorType, $actorId, $record);
                $record = $record->quarantineLegacyActive($importedAt);
                if (isset($ids[$record->id])) {
                    throw new \InvalidArgumentException('Requirement ids must be unique.');
                }
                $ids[$record->id] = true;
                $records[] = $record;
            }
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'requirements_state_invalid'];
        }
        try {
            $empty = RequirementState::empty(
                $conversationId,
                $actorType,
                $actorId,
                $importedAt
            );
            if ($records === []) {
                return ['ok' => true, 'state' => $empty];
            }
            $sourceMessageId = $this->lastSourceMessageId($records);
            $imported = $empty->next($records, $sourceMessageId, $importedAt);
            if ($this->states->compareAndSwap($empty, $imported)) {
                return ['ok' => true, 'state' => $imported];
            }
            // Another request may have won the unique-head insert. Reload only
            // this actor scope; never assume the conflicting row is ours.
            $winner = $this->states->loadOwned($conversationId, $actorType, $actorId);
            if ($winner !== null) {
                return ['ok' => true, 'state' => $winner];
            }
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'requirements_state_invalid'];
        }

        return ['ok' => false, 'code' => 'requirements_write_conflict'];
    }

    /** @param non-empty-list<RequirementCriterion> $records */
    private function lastSourceMessageId(array $records): string
    {
        for ($index = count($records) - 1; $index >= 0; --$index) {
            $record = $records[$index];
            if ($record->statusSourceMessageId !== null) {
                return $record->statusSourceMessageId;
            }
            $source = $record->source['message_id'] ?? null;
            if (is_string($source) && $source !== '') {
                return $source;
            }
        }

        throw new \UnexpectedValueException('Legacy requirement history has no source message.');
    }

    /**
     * Legacy memory is not trusted merely because it matches the stored row
     * shape. Rebind every imported record to an exact customer-visible message
     * in this actor-owned conversation before the new aggregate can label it
     * validated or include it in provider context.
     */
    private function validateLegacySource(
        string $conversationId,
        string $actorType,
        string $actorId,
        RequirementCriterion $record
    ): void {
        $sourceMessageId = $record->source['message_id'] ?? null;
        $offset = $record->source['excerpt_offset_bytes'] ?? null;
        $length = $record->source['excerpt_length_bytes'] ?? null;
        $expectedHash = $record->source['excerpt_sha256'] ?? null;
        if (!is_string($sourceMessageId) || !is_int($offset) || !is_int($length)
            || !is_string($expectedHash)
        ) {
            throw new \UnexpectedValueException('Legacy requirement provenance is invalid.');
        }
        $message = $this->conversations->visibleMessage(
            $conversationId,
            $actorType,
            $actorId,
            $sourceMessageId
        );
        $text = is_array($message) ? ($message['content']['text'] ?? null) : null;
        if (!is_array($message) || ($message['sender_type'] ?? null) !== 'customer'
            || !is_string($text) || $text === ''
            || $offset < 0 || $length < 1 || $offset > strlen($text)
            || $length > strlen($text) - $offset
        ) {
            throw new \UnexpectedValueException('Legacy requirement source is not visible in this actor scope.');
        }
        $excerpt = substr($text, $offset, $length);
        if (strlen($excerpt) !== $length || !hash_equals($expectedHash, hash('sha256', $excerpt))) {
            throw new \UnexpectedValueException('Legacy requirement excerpt does not match its source message.');
        }

        if ($record->statusSourceMessageId === null) {
            return;
        }
        $statusMessage = $this->conversations->visibleMessage(
            $conversationId,
            $actorType,
            $actorId,
            $record->statusSourceMessageId
        );
        if (!is_array($statusMessage) || ($statusMessage['sender_type'] ?? null) !== 'customer'
            || !is_string($statusMessage['content']['text'] ?? null)
            || $statusMessage['content']['text'] === ''
        ) {
            throw new \UnexpectedValueException('Legacy requirement status source is not visible in this actor scope.');
        }
    }

    /** @param array<string, mixed> $change */
    private function sourceExcerpt(array $change, string $messageText): string
    {
        $excerpt = $this->requiredString($change, 'source_excerpt');
        if (strlen($excerpt) > 2000 || !str_contains($messageText, $excerpt)) {
            throw new \DomainException('requirements_source_excerpt_invalid');
        }
        return $excerpt;
    }

    /** @param array<string, mixed> $change */
    private function requiredTarget(array $change): string
    {
        $id = $this->requiredString($change, 'target_requirement_id');
        if (preg_match('/^req_[a-f0-9]{32}$/', $id) !== 1) {
            throw new \DomainException('requirements_target_invalid');
        }
        return $id;
    }

    /** @param array<string, mixed> $value */
    private function requiredString(array $value, string $key): string
    {
        if (!isset($value[$key]) || !is_string($value[$key]) || $value[$key] === '') {
            throw new \InvalidArgumentException('Required requirement field is invalid.');
        }
        return $value[$key];
    }

    /** @param array<int, RequirementCriterion> $records */
    private function find(array $records, string $id): ?RequirementCriterion
    {
        $index = $this->findIndex($records, $id);
        return $index === null ? null : $records[$index];
    }

    /** @param array<int, RequirementCriterion> $records */
    private function findIndex(array $records, string $id): ?int
    {
        foreach ($records as $index => $record) {
            if ($record->id === $id) {
                return $index;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $change */
    private function sameSlot(RequirementCriterion $record, array $change): bool
    {
        if ($record->field !== ($change['field'] ?? null)) {
            return false;
        }
        if (!in_array($record->field, ['attribute', 'compatibility', 'preference'], true)) {
            return true;
        }
        $existing = is_array($record->value) ? $record->value : [];
        $proposed = is_array($change['value'] ?? null) ? $change['value'] : [];
        $selector = $record->field === 'attribute' ? 'name' : 'key';
        return is_string($existing[$selector] ?? null)
            && is_string($proposed[$selector] ?? null)
            && $existing[$selector] === $proposed[$selector];
    }
}
