<?php

declare(strict_types=1);

namespace Veyra\Requirements\Infrastructure;

use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Requirements\Contract\RequirementStateRepository;
use Veyra\Requirements\Domain\RequirementCriterion;
use Veyra\Requirements\Domain\RequirementState;
use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Shared\Domain\Uuid;

final class WpdbRequirementStateRepository implements RequirementStateRepository
{
    private readonly string $table;
    private readonly string $conversationTable;
    private readonly string $messageTable;

    public function __construct(private readonly \wpdb $database, TableNames $tables)
    {
        $this->table = $tables->requirementStates();
        $this->conversationTable = $tables->conversations();
        $this->messageTable = $tables->messages();
    }

    public function loadOwned(
        string $conversationId,
        string $actorType,
        string $actorId
    ): ?RequirementState {
        $actor = new ActorScope($actorType, $actorId);
        $row = $this->database->get_row($this->database->prepare(
            "SELECT conversation_id,actor_type,actor_id,state_json,state_hash,version,
                    last_source_message_id,created_at,updated_at
             FROM {$this->table}
             WHERE conversation_id = %s AND actor_type = %s AND actor_id = %s
             AND actor_key_hash = %s LIMIT 1",
            $conversationId,
            $actor->actorType,
            $actor->actorId,
            $actor->hash()
        ), ARRAY_A);
        if (!is_array($row)) {
            if (isset($this->database->last_error) && trim((string) $this->database->last_error) !== '') {
                throw new \RuntimeException('Requirement-state read failed.');
            }

            return null;
        }

        foreach (['conversation_id', 'actor_type', 'actor_id', 'state_json', 'state_hash', 'created_at', 'updated_at'] as $column) {
            if (!isset($row[$column]) || !is_string($row[$column]) || $row[$column] === '') {
                throw new \UnexpectedValueException('Stored requirement-state row is incomplete.');
            }
        }

        $raw = json_decode($row['state_json'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \UnexpectedValueException('Stored requirement-state JSON is invalid.');
        }
        $criteria = [];
        foreach ($raw as $criterion) {
            if (!is_array($criterion)) {
                throw new \UnexpectedValueException('Stored requirement criterion is invalid.');
            }
            $criteria[] = RequirementCriterion::fromStored($criterion);
        }
        $version = $row['version'] ?? null;
        if ((!is_int($version) && (!is_string($version) || preg_match('/^[1-9][0-9]*$/D', $version) !== 1))
            || (int) $version < 1
        ) {
            throw new \UnexpectedValueException('Stored requirement-state version is invalid.');
        }
        $sourceMessageId = $row['last_source_message_id'] ?? null;
        if ($sourceMessageId !== null && !is_string($sourceMessageId)) {
            throw new \UnexpectedValueException('Stored requirement-state source is invalid.');
        }

        return RequirementState::fromStored(
            $row['conversation_id'],
            $row['actor_type'],
            $row['actor_id'],
            (int) $version,
            $row['state_hash'],
            $criteria,
            $sourceMessageId,
            UtcInstant::fromDatabase($row['created_at'])->toIso8601(),
            UtcInstant::fromDatabase($row['updated_at'])->toIso8601()
        );
    }

    public function compareAndSwap(RequirementState $expected, RequirementState $next): bool
    {
        $this->validateTransition($expected, $next);
        $actor = new ActorScope($expected->actorType, $expected->actorId);

        if ($expected->resourceVersion === 0) {
            // The conversation_id unique key is the missing-row concurrency
            // primitive: exactly one writer may create the first head. The
            // INSERT ... SELECT also rechecks conversation and source-message
            // ownership in the same SQL statement, closing the account-link
            // race between the application-level ownership check and insert.
            $result = $this->database->query($this->database->prepare(
                "INSERT INTO {$this->table}
                 (public_id,conversation_id,actor_type,actor_id,actor_key_hash,state_json,state_hash,
                  version,last_source_message_id,created_at,updated_at)
                 SELECT %s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s
                 FROM {$this->conversationTable} AS owned_conversation
                 WHERE owned_conversation.public_id = %s
                 AND owned_conversation.actor_type = %s
                 AND owned_conversation.actor_id = %s
                 AND owned_conversation.actor_key_hash = %s
                 AND EXISTS (
                    SELECT 1 FROM {$this->messageTable} AS source_message
                    WHERE source_message.public_id = %s
                    AND source_message.conversation_id = owned_conversation.public_id
                    AND source_message.actor_type = owned_conversation.actor_type
                    AND source_message.actor_id = owned_conversation.actor_id
                    AND source_message.actor_key_hash = owned_conversation.actor_key_hash
                    AND source_message.sender_type = 'customer'
                 ) LIMIT 1
                 ON DUPLICATE KEY UPDATE id = id",
                Uuid::v4(),
                $next->conversationId,
                $next->actorType,
                $next->actorId,
                $actor->hash(),
                CanonicalJson::encode($next->criteriaArray()),
                $next->stateHash,
                $next->resourceVersion,
                $next->lastSourceMessageId,
                $this->databaseTimestamp($next->createdAt),
                $this->databaseTimestamp($next->updatedAt),
                $expected->conversationId,
                $expected->actorType,
                $expected->actorId,
                $actor->hash(),
                $next->lastSourceMessageId
            ));
            if ($result === false) {
                throw new \RuntimeException('Requirement-state first-head write failed.');
            }

            return $result === 1;
        }

        $result = $this->database->query($this->database->prepare(
            "UPDATE {$this->table}
             SET state_json = %s, state_hash = %s, version = %d,
                 last_source_message_id = %s, updated_at = %s
             WHERE conversation_id = %s AND actor_type = %s AND actor_id = %s
             AND actor_key_hash = %s AND version = %d AND state_hash = %s",
            CanonicalJson::encode($next->criteriaArray()),
            $next->stateHash,
            $next->resourceVersion,
            $next->lastSourceMessageId,
            $this->databaseTimestamp($next->updatedAt),
            $expected->conversationId,
            $expected->actorType,
            $expected->actorId,
            $actor->hash(),
            $expected->resourceVersion,
            $expected->stateHash
        ));
        if ($result === false) {
            throw new \RuntimeException('Requirement-state successor write failed.');
        }

        return $result === 1;
    }

    private function validateTransition(RequirementState $expected, RequirementState $next): void
    {
        if (!hash_equals($expected->conversationId, $next->conversationId)
            || !hash_equals($expected->actorType, $next->actorType)
            || !hash_equals($expected->actorId, $next->actorId)
            || $next->resourceVersion !== $expected->resourceVersion + 1
            || !hash_equals($expected->createdAt, $next->createdAt)
        ) {
            throw new \InvalidArgumentException('Requirement-state CAS transition is invalid.');
        }
    }

    private function databaseTimestamp(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Requirement-state persistence timestamp is invalid.');
        }
    }
}
