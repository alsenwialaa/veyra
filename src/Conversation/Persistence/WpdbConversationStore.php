<?php
declare(strict_types=1);

namespace Veyra\Conversation\Persistence;

use Veyra\Conversation\Application\ConversationStore;
use Veyra\Conversation\Domain\ConversationFocus;
use Veyra\Conversation\Domain\JourneyState;
use Veyra\Conversation\Domain\PendingQuestion;
use Veyra\Shared\Domain\Uuid;

final class WpdbConversationStore implements ConversationStore
{
    /** @param \wpdb $db */
    public function __construct(private readonly object $db)
    {
    }

    public function createConversation(string $actorType, string $actorId, ?int $userId, ?string $guestSessionId): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $actorKeyHash = $this->actorKeyHash($actorType, $actorId);
        $inserted = $this->db->insert(
            $this->table('conversations'),
            [
                'public_id' => $id,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_key_hash' => $actorKeyHash,
                'user_id' => $userId,
                'guest_session_id' => $guestSessionId,
                'status' => 'active',
                'foreground_journey_id' => null,
                'focus_json' => '{}',
                'memory_json' => '{}',
                'summary_json' => '{}',
                'configuration_version' => '1',
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
        if ($inserted !== 1) {
            throw new \RuntimeException('Conversation creation failed.');
        }
        return $id;
    }

    public function getOwnedConversation(string $conversationId, string $actorType, string $actorId): ?array
    {
        $row = $this->db->get_row($this->db->prepare(
            'SELECT * FROM ' . $this->table('conversations') . ' WHERE public_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s LIMIT 1',
            $conversationId,
            $actorType,
            $actorId,
            $this->actorKeyHash($actorType, $actorId)
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function currentOwnedConversation(string $actorType, string $actorId): ?array
    {
        $row = $this->db->get_row($this->db->prepare(
            'SELECT * FROM ' . $this->table('conversations') . ' WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s AND status = %s ORDER BY updated_at DESC, id DESC LIMIT 1',
            $actorType,
            $actorId,
            $this->actorKeyHash($actorType, $actorId),
            'active'
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function recentVisibleMessages(string $conversationId, string $actorType, string $actorId, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT public_id,sender_type,content_json,render_json,language,direction,reply_to_message_id,product_reference_json,status,rendering_schema_version,correlation_id,created_at FROM ' .
                $this->table('messages') . ' WHERE conversation_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s ORDER BY id DESC LIMIT %d',
            $conversationId,
            $actorType,
            $actorId,
            $this->actorKeyHash($actorType, $actorId),
            $limit
        ), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $result = [];
        foreach (array_reverse($rows) as $row) {
            $result[] = [
                'message_id' => (string) $row['public_id'],
                'sender_type' => (string) $row['sender_type'],
                'content' => $this->decode((string) $row['content_json']),
                'render' => $this->decode((string) $row['render_json']),
                'language' => (string) $row['language'],
                'direction' => (string) $row['direction'],
                'reply_to_message_id' => $row['reply_to_message_id'] !== null ? (string) $row['reply_to_message_id'] : null,
                'product_references' => $this->decode((string) $row['product_reference_json']),
                'status' => (string) $row['status'],
                'rendering_schema_version' => (string) $row['rendering_schema_version'],
                'correlation_id' => (string) $row['correlation_id'],
                'created_at' => (string) $row['created_at'] . 'Z',
            ];
        }
        return $result;
    }

    public function visibleMessage(string $conversationId, string $actorType, string $actorId, string $messageId): ?array
    {
        $row = $this->db->get_row($this->db->prepare(
            'SELECT public_id,sender_type,content_json,render_json,language,direction,product_reference_json,correlation_id,created_at FROM ' .
                $this->table('messages') . ' WHERE public_id = %s AND conversation_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s LIMIT 1',
            $messageId,
            $conversationId,
            $actorType,
            $actorId,
            $this->actorKeyHash($actorType, $actorId)
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return [
            'message_id' => (string) $row['public_id'],
            'sender_type' => (string) $row['sender_type'],
            'content' => $this->decode((string) $row['content_json']),
            'render' => $this->decode((string) $row['render_json']),
            'language' => (string) $row['language'],
            'direction' => (string) $row['direction'],
            'product_references' => $this->decode((string) $row['product_reference_json']),
            'created_at' => (string) $row['created_at'] . 'Z',
            'correlation_id' => (string) $row['correlation_id'],
        ];
    }

    public function journeys(string $conversationId, string $actorType, string $actorId): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            'SELECT * FROM ' . $this->table('journeys') . ' WHERE conversation_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s AND status IN (%s,%s) ORDER BY updated_at DESC',
            $conversationId,
            $actorType,
            $actorId,
            $this->actorKeyHash($actorType, $actorId),
            'active',
            'paused'
        ), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $state = $this->decode((string) $row['state_json']);
            $dependencies = $this->decode((string) $row['dependencies_json']);
            $result[] = new JourneyState(
                (string) $row['public_id'],
                $conversationId,
                $actorId,
                (string) $row['journey_type'],
                (string) $row['version'],
                (string) $row['status'],
                (string) $row['current_step'],
                (string) ($state['resume_step'] ?? $row['current_step']),
                is_array($state['fields'] ?? null) ? $state['fields'] : $state,
                is_array($state['open_question_ids'] ?? null) ? $state['open_question_ids'] : [],
                is_array($state['related_resources'] ?? null) ? $state['related_resources'] : [],
                $dependencies,
                isset($state['last_verified_checkpoint']) ? (string) $state['last_verified_checkpoint'] : null
            );
        }
        return $result;
    }

    public function focus(string $conversationId, string $actorType, string $actorId): ?ConversationFocus
    {
        $row = $this->db->get_row($this->db->prepare(
            'SELECT * FROM ' . $this->table('conversation_focus') . ' WHERE conversation_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s LIMIT 1',
            $conversationId,
            $actorType,
            $actorId,
            $this->actorKeyHash($actorType, $actorId)
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $pending = null;
        if (is_string($row['pending_question_id'] ?? null) && $row['pending_question_id'] !== '') {
            $pendingRow = $this->db->get_row($this->db->prepare(
                'SELECT * FROM ' . $this->table('pending_questions') . ' WHERE public_id = %s AND conversation_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s LIMIT 1',
                $row['pending_question_id'],
                $conversationId,
                $actorType,
                $actorId,
                $this->actorKeyHash($actorType, $actorId)
            ), ARRAY_A);
            if (is_array($pendingRow)) {
                if (!$this->journeyId($pendingRow['journey_id'] ?? null)) {
                    return null;
                }
                $dependencyVersions = isset($pendingRow['dependency_versions_json'])
                    && is_string($pendingRow['dependency_versions_json'])
                    && $pendingRow['dependency_versions_json'] !== ''
                        ? $this->decode($pendingRow['dependency_versions_json'])
                        : [];
                $invalidationReason = $pendingRow['invalidation_reason'] !== null
                    ? (string) $pendingRow['invalidation_reason']
                    : null;
                if (!isset($pendingRow['dependency_versions_json']) || $pendingRow['dependency_versions_json'] === null) {
                    $invalidationReason = $invalidationReason ?? 'legacy_dependency_versions_unavailable';
                }
                $pending = new PendingQuestion(
                    (string) $pendingRow['public_id'],
                    (string) $pendingRow['journey_id'],
                    (string) $pendingRow['question_type'],
                    (string) $pendingRow['visible_message_id'],
                    $this->decode((string) $pendingRow['expected_schema_json']),
                    array_values(array_filter($this->decode((string) $pendingRow['allowed_choices_json']), 'is_string')),
                    $this->decode((string) $pendingRow['resource_scope_json']),
                    (string) $pendingRow['sensitivity'],
                    new \DateTimeImmutable((string) $pendingRow['created_at'], new \DateTimeZone('UTC')),
                    new \DateTimeImmutable((string) $pendingRow['expires_at'], new \DateTimeZone('UTC')),
                    $dependencyVersions,
                    $invalidationReason,
                    $pendingRow['state'] === 'answered'
                        ? (is_string($pendingRow['answered_binding_id'] ?? null) && $pendingRow['answered_binding_id'] !== ''
                            ? (string) $pendingRow['answered_binding_id']
                            : (string) $pendingRow['public_id'])
                        : null,
                    max(1, (int) ($pendingRow['version'] ?? 1))
                );
            }
        }
        $unresolvedReferences = $this->unresolvedReferencesFromStorage(
            $row['unresolved_references_json'] ?? null
        );
        if ($unresolvedReferences === null) {
            return null;
        }

        $foregroundJourneyId = $row['foreground_journey_id'] !== null
            ? (string) $row['foreground_journey_id']
            : null;
        if (($foregroundJourneyId !== null && !$this->journeyId($foregroundJourneyId))
            || ($pending !== null && $pending->journeyId !== $foregroundJourneyId)
        ) {
            return null;
        }

        return new ConversationFocus(
            (string) $row['version'],
            $foregroundJourneyId,
            $this->decode((string) $row['focused_resources_json']),
            $pending,
            $unresolvedReferences,
            (string) $row['source_message_id'],
            new \DateTimeImmutable((string) $row['updated_at'], new \DateTimeZone('UTC'))
        );
    }

    public function memory(string $conversationId, string $actorType, string $actorId): array
    {
        $conversation = $this->getOwnedConversation($conversationId, $actorType, $actorId);
        return $conversation === null ? [] : $this->decode((string) $conversation['memory_json']);
    }

    public function summary(string $conversationId, string $actorType, string $actorId): array
    {
        $conversation = $this->getOwnedConversation($conversationId, $actorType, $actorId);
        return $conversation === null ? [] : $this->decode((string) $conversation['summary_json']);
    }

    public function appendVisibleMessage(
        string $conversationId,
        string $actorType,
        string $actorId,
        string $senderType,
        string $text,
        array $renderPayload,
        array $evidence,
        string $correlationId
    ): string {
        if ($this->getOwnedConversation($conversationId, $actorType, $actorId) === null) {
            throw new \RuntimeException('Conversation ownership check failed.');
        }
        $id = 'msg_' . bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');
        $actorKeyHash = $this->actorKeyHash($actorType, $actorId);
        $content = ['text' => $text, 'evidence' => $evidence];
        $inserted = $this->db->insert($this->table('messages'), [
            'public_id' => $id,
            'conversation_id' => $conversationId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_key_hash' => $actorKeyHash,
            'sender_type' => $senderType,
            'content_json' => $this->encode($content),
            'render_json' => $this->encode($renderPayload),
            'language' => (string) ($renderPayload['language'] ?? 'en'),
            'direction' => in_array($renderPayload['direction'] ?? null, ['ltr', 'rtl'], true) ? $renderPayload['direction'] : 'ltr',
            'reply_to_message_id' => $renderPayload['reply_to_message_id'] ?? null,
            'product_reference_json' => $this->encode($renderPayload['product_references'] ?? []),
            'status' => 'delivered_to_server',
            'rendering_schema_version' => '1.0.0',
            'correlation_id' => $correlationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted !== 1) {
            throw new \RuntimeException('Message persistence failed.');
        }
        return $id;
    }

    public function saveFocus(string $conversationId, string $actorType, string $actorId, ConversationFocus $focus, string $expectedVersion): bool
    {
        if (!$this->validUnresolvedReferences($focus->unresolvedReferences)
            || $this->db->query('START TRANSACTION') === false
        ) {
            return false;
        }
        try {
            $actorKeyHash = $this->actorKeyHash($actorType, $actorId);
            $owner = $this->db->get_row($this->db->prepare(
                'SELECT public_id FROM ' . $this->table('conversations') . ' WHERE public_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s FOR UPDATE',
                $conversationId,
                $actorType,
                $actorId,
                $actorKeyHash
            ), ARRAY_A);
            if (!is_array($owner)) {
                $this->db->query('ROLLBACK');
                return false;
            }

            $current = $this->db->get_row($this->db->prepare(
                'SELECT id,public_id,version,pending_question_id FROM ' . $this->table('conversation_focus') . ' WHERE conversation_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s FOR UPDATE',
                $conversationId,
                $actorType,
                $actorId,
                $actorKeyHash
            ), ARRAY_A);
            if (is_array($current) && (string) $current['version'] !== (string) $expectedVersion) {
                $this->db->query('ROLLBACK');
                return false;
            }
            if (!is_array($current) && $expectedVersion !== '0') {
                $this->db->query('ROLLBACK');
                return false;
            }
            $questionId = null;
            if ($focus->pendingQuestion !== null) {
                $question = $focus->pendingQuestion;
                $questionId = $question->id;
                $inserted = $this->db->insert($this->table('pending_questions'), [
                    'public_id' => $question->id,
                    'conversation_id' => $conversationId,
                    'journey_id' => $question->journeyId,
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'actor_key_hash' => $actorKeyHash,
                    'visible_message_id' => $question->messageId,
                    'question_type' => $question->stepId,
                    'expected_schema_json' => $this->encode($question->answerSchema),
                    'allowed_choices_json' => $this->encode($question->allowedChoiceIds),
                    'resource_scope_json' => $this->encode($question->focusedResources),
                    'sensitivity' => $question->sensitivity,
                    'state' => 'active',
                    'dependency_hash' => hash('sha256', $this->encode($question->dependencyVersions)),
                    'dependency_versions_json' => $this->encode($question->dependencyVersions),
                    'version' => 1,
                    'expires_at' => $question->expiresAt->format('Y-m-d H:i:s'),
                    'invalidation_reason' => null,
                    'answered_at' => null,
                    'created_at' => $question->createdAt->format('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                if ($inserted !== 1) {
                    throw new \RuntimeException('Pending question persistence failed.');
                }
            }
            $previousQuestionId = is_array($current) && is_string($current['pending_question_id'] ?? null)
                ? (string) $current['pending_question_id']
                : '';
            if ($previousQuestionId !== '' && $previousQuestionId !== $questionId) {
                $invalidated = $this->db->query($this->db->prepare(
                    'UPDATE ' . $this->table('pending_questions') . ' SET state = %s, invalidation_reason = %s, version = version + 1, updated_at = %s WHERE public_id = %s AND conversation_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s AND state = %s',
                    'invalidated',
                    'focus_replaced',
                    gmdate('Y-m-d H:i:s'),
                    $previousQuestionId,
                    $conversationId,
                    $actorType,
                    $actorId,
                    $actorKeyHash,
                    'active'
                ));
                if ($invalidated === false) {
                    throw new \RuntimeException('Replaced Pending Question invalidation failed.');
                }
            }
            $row = [
                'conversation_id' => $conversationId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_key_hash' => $actorKeyHash,
                'foreground_journey_id' => $focus->foregroundJourneyId,
                'pending_question_id' => $questionId,
                'focused_resources_json' => $this->encode($focus->focusedResources),
                'unresolved_references_json' => $this->encode($focus->unresolvedReferences),
                'expected_answer_schema_json' => $this->encode($focus->pendingQuestion?->answerSchema ?? []),
                'sensitivity' => $focus->pendingQuestion?->sensitivity ?? 'informational',
                'source_message_id' => $focus->sourceMessageId,
                'version' => (int) $focus->version,
                'expires_at' => $focus->pendingQuestion?->expiresAt->format('Y-m-d H:i:s'),
                'invalidation_reason' => null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ];
            if (is_array($current)) {
                $written = $this->db->update($this->table('conversation_focus'), $row, [
                    'id' => (int) $current['id'],
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'actor_key_hash' => $actorKeyHash,
                    'version' => (int) $expectedVersion,
                ]);
            } else {
                $row['public_id'] = Uuid::v4();
                $row['created_at'] = gmdate('Y-m-d H:i:s');
                $written = $this->db->insert($this->table('conversation_focus'), $row);
            }
            if ($written !== 1) {
                throw new \RuntimeException('Focus persistence failed.');
            }
            if ($this->db->query('COMMIT') === false) {
                throw new \RuntimeException('Focus transaction commit failed.');
            }
            return true;
        } catch (\Throwable $error) {
            $this->db->query('ROLLBACK');
            return false;
        }
    }

    public function consumePendingQuestion(
        string $conversationId,
        string $actorType,
        string $actorId,
        string $questionId,
        string $expectedFocusVersion,
        int $expectedQuestionVersion,
        string $customerMessageId,
        array $validatedBinding
    ): array {
        if ($expectedQuestionVersion < 1
            || preg_match('/^[1-9][0-9]*$/D', $expectedFocusVersion) !== 1
            || !$this->validBindingRecord($validatedBinding, $questionId, $customerMessageId)
        ) {
            return ['consumed' => false, 'code' => 'binding_record_invalid', 'binding_id' => null];
        }

        $bindingId = 'bind_' . bin2hex(random_bytes(16));
        $actorKeyHash = $this->actorKeyHash($actorType, $actorId);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->query('START TRANSACTION');
        try {
            $focus = $this->db->get_row($this->db->prepare(
                'SELECT id,version,pending_question_id FROM ' . $this->table('conversation_focus') . ' WHERE conversation_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s FOR UPDATE',
                $conversationId,
                $actorType,
                $actorId,
                $actorKeyHash
            ), ARRAY_A);
            if (!is_array($focus)
                || (string) ($focus['version'] ?? '') !== $expectedFocusVersion
                || (string) ($focus['pending_question_id'] ?? '') !== $questionId
            ) {
                $this->db->query('ROLLBACK');
                return ['consumed' => false, 'code' => 'focus_version_conflict', 'binding_id' => null];
            }

            $question = $this->db->get_row($this->db->prepare(
                'SELECT id,version,state,expires_at,invalidation_reason FROM ' . $this->table('pending_questions') . ' WHERE public_id = %s AND conversation_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s FOR UPDATE',
                $questionId,
                $conversationId,
                $actorType,
                $actorId,
                $actorKeyHash
            ), ARRAY_A);
            if (!is_array($question)
                || (int) ($question['version'] ?? 0) !== $expectedQuestionVersion
                || ($question['state'] ?? null) !== 'active'
                || ($question['invalidation_reason'] ?? null) !== null
                || !is_string($question['expires_at'] ?? null)
                || strtotime((string) $question['expires_at'] . ' UTC') <= time()
            ) {
                $this->db->query('ROLLBACK');
                return ['consumed' => false, 'code' => 'pending_question_not_active', 'binding_id' => null];
            }

            $bindingRecord = $validatedBinding;
            $bindingRecord['schema_version'] = 'veyra.validated_answer_binding.v1';
            $bindingRecord['binding_id'] = $bindingId;
            $bindingRecord['question_id'] = $questionId;
            $bindingRecord['customer_message_id'] = $customerMessageId;
            $bindingRecord['focus_version'] = $expectedFocusVersion;
            $bindingRecord['question_version'] = $expectedQuestionVersion;
            $bindingRecord['validated_at'] = gmdate(DATE_ATOM);

            $questionUpdated = $this->db->update(
                $this->table('pending_questions'),
                [
                    'state' => 'answered',
                    'answered_binding_id' => $bindingId,
                    'answered_message_id' => $customerMessageId,
                    'answer_binding_json' => $this->encode($bindingRecord),
                    'answered_at' => $now,
                    'version' => $expectedQuestionVersion + 1,
                    'updated_at' => $now,
                ],
                [
                    'id' => (int) $question['id'],
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'actor_key_hash' => $actorKeyHash,
                    'state' => 'active',
                    'version' => $expectedQuestionVersion,
                ]
            );
            if ($questionUpdated !== 1) {
                throw new \RuntimeException('Pending Question consumption compare-and-set failed.');
            }

            $focusUpdated = $this->db->update(
                $this->table('conversation_focus'),
                [
                    'pending_question_id' => null,
                    'expected_answer_schema_json' => '{}',
                    'sensitivity' => 'informational',
                    'source_message_id' => $customerMessageId,
                    'version' => (int) $expectedFocusVersion + 1,
                    'expires_at' => null,
                    'invalidation_reason' => null,
                    'updated_at' => $now,
                ],
                [
                    'id' => (int) $focus['id'],
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'actor_key_hash' => $actorKeyHash,
                    'pending_question_id' => $questionId,
                    'version' => (int) $expectedFocusVersion,
                ]
            );
            if ($focusUpdated !== 1) {
                throw new \RuntimeException('Conversation Focus consumption compare-and-set failed.');
            }

            $this->db->query('COMMIT');
            return ['consumed' => true, 'code' => 'pending_question_consumed', 'binding_id' => $bindingId];
        } catch (\Throwable) {
            $this->db->query('ROLLBACK');
            return ['consumed' => false, 'code' => 'pending_question_consumption_failed', 'binding_id' => null];
        }
    }

    public function saveMemory(string $conversationId, string $actorType, string $actorId, array $memory, string $sourceMessageId): bool
    {
        $updated = $this->db->query($this->db->prepare(
            'UPDATE ' . $this->table('conversations') . ' SET memory_json = %s, version = version + 1, updated_at = %s WHERE public_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s',
            $this->encode($memory),
            gmdate('Y-m-d H:i:s'),
            $conversationId,
            $actorType,
            $actorId,
            $this->actorKeyHash($actorType, $actorId)
        ));
        return $updated === 1;
    }

    private function table(string $suffix): string
    {
        return $this->db->prefix . 'veyra_' . $suffix;
    }

    private function actorKeyHash(string $actorType, string $actorId): string
    {
        return hash('sha256', $actorType . ':' . $actorId);
    }

    private function journeyId(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,35}$/D', $value) === 1;
    }

    /** @param array<int, mixed> $references */
    private function validUnresolvedReferences(array $references): bool
    {
        if (!array_is_list($references) || count($references) > 10) {
            return false;
        }
        $seen = [];
        foreach ($references as $reference) {
            if (!is_string($reference)
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,190}$/D', $reference) !== 1
                || isset($seen[$reference])
            ) {
                return false;
            }
            $seen[$reference] = true;
        }

        return true;
    }

    /** @return list<string>|null */
    private function unresolvedReferencesFromStorage(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_string($value)) {
            return null;
        }
        $decoded = json_decode($value, true, 16);
        if (!is_array($decoded) || !$this->validUnresolvedReferences($decoded)) {
            return null;
        }

        return $decoded;
    }

    /** @param array<string, mixed> $binding */
    private function validBindingRecord(array $binding, string $questionId, string $customerMessageId): bool
    {
        $required = ['proposed_value', 'validated_value', 'target_resource_ids', 'validation_code', 'decision_id'];
        if (array_diff(array_keys($binding), $required) !== []
            || array_diff($required, array_keys($binding)) !== []
            || !is_array($binding['target_resource_ids'])
            || !array_is_list($binding['target_resource_ids'])
            || count($binding['target_resource_ids']) > 20
            || !is_string($binding['validation_code'])
            || $binding['validation_code'] !== 'binding_valid'
            || !is_string($binding['decision_id'])
            || $binding['decision_id'] === ''
            || strlen($binding['decision_id']) > 191
            || $questionId === ''
            || $customerMessageId === ''
        ) {
            return false;
        }
        foreach ($binding['target_resource_ids'] as $resourceId) {
            if (!is_string($resourceId) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $resourceId) !== 1) {
                return false;
            }
        }
        $encoded = json_encode($binding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) && strlen($encoded) <= 32768;
    }

    /** @param mixed $value */
    private function encode(mixed $value): string
    {
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('JSON encoding failed.');
        }
        return $encoded;
    }

    /** @return array<string|int, mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, 64);
        return is_array($decoded) ? $decoded : [];
    }
}
