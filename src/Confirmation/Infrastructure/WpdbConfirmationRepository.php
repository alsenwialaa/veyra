<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Infrastructure;

use Veyra\Confirmation\Application\ConfirmationRepository;
use Veyra\Confirmation\Domain\ConfirmationId;
use Veyra\Confirmation\Domain\ConfirmationRecord;
use Veyra\Confirmation\Domain\ConfirmationStatus;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\UtcInstant;

final class WpdbConfirmationRepository implements ConfirmationRepository
{
    private readonly string $table;

    public function __construct(private readonly \wpdb $database, TableNames $tables)
    {
        $this->table = $tables->confirmations();
    }

    public function insert(ConfirmationRecord $record): bool
    {
        return $this->database->insert(
            $this->table,
            [
                'public_id' => $record->id->value(),
                'token_digest' => $record->tokenDigest,
                'actor_type' => $record->actor->actorType,
                'actor_id' => $record->actor->actorId,
                'actor_key_hash' => $record->actor->hash(),
                'session_id' => $record->sessionId,
                'conversation_id' => $record->conversationId,
                'journey_id' => $record->journeyId,
                'action_key' => $record->action,
                'resource_scope_json' => CanonicalJson::encode($record->resourceScope),
                'material_payload_json' => CanonicalJson::encode($record->materialPayload),
                'state_hash' => $record->stateHash->value(),
                'summary_message_id' => $record->summaryMessageId,
                'summary_version' => $record->summaryVersion,
                'acknowledgements_json' => CanonicalJson::encode($record->acknowledgements),
                'idempotency_scope' => $record->idempotencyScope,
                'correlation_id' => $record->correlationId->value(),
                'status' => $record->status->value,
                'version' => $record->version,
                'expires_at' => $record->expiresAt->toDatabase(),
                'consumed_at' => $record->consumedAt?->toDatabase(),
                'invalidation_reason' => $record->invalidationReason,
                'created_at' => $record->createdAt->toDatabase(),
                'updated_at' => $record->updatedAt->toDatabase(),
            ]
        ) === 1;
    }

    public function findByTokenDigest(ActorScope $actor, string $tokenDigest): ?ConfirmationRecord
    {
        $query = $this->database->prepare(
            "SELECT * FROM {$this->table}
             WHERE token_digest = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s LIMIT 1",
            $tokenDigest,
            $actor->actorType,
            $actor->actorId,
            $actor->hash()
        );
        $row = $this->database->get_row($query, ARRAY_A);

        return is_array($row) ? $this->map($row) : null;
    }

    public function findActiveForJourney(ActorScope $actor, ?string $journeyId, UtcInstant $now, int $limit = 2): array
    {
        $limit = max(1, min($limit, 2));

        if ($journeyId === null) {
            $query = $this->database->prepare(
                "SELECT * FROM {$this->table}
                 WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s
                 AND journey_id IS NULL AND status = 'active' AND expires_at > %s
                 ORDER BY created_at DESC LIMIT %d",
                $actor->actorType,
                $actor->actorId,
                $actor->hash(),
                $now->toDatabase(),
                $limit
            );
        } else {
            $query = $this->database->prepare(
                "SELECT * FROM {$this->table}
                 WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s
                 AND journey_id = %s AND status = 'active' AND expires_at > %s
                 ORDER BY created_at DESC LIMIT %d",
                $actor->actorType,
                $actor->actorId,
                $actor->hash(),
                $journeyId,
                $now->toDatabase(),
                $limit
            );
        }

        $rows = $this->database->get_results($query, ARRAY_A);

        return is_array($rows)
            ? array_map(fn (array $row): ConfirmationRecord => $this->map($row), $rows)
            : [];
    }

    public function consume(
        ConfirmationRecord $record,
        StateHash $currentState,
        UtcInstant $consumedAt,
        CorrelationId $consumptionCorrelationId
    ): bool
    {
        $query = $this->database->prepare(
            "UPDATE {$this->table}
             SET status = 'consumed', consumed_at = %s, updated_at = %s, correlation_id = %s, version = version + 1
             WHERE public_id = %s AND token_digest = %s
             AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             AND state_hash = %s AND status = 'active' AND version = %d AND expires_at > %s",
            $consumedAt->toDatabase(),
            $consumedAt->toDatabase(),
            $consumptionCorrelationId->value(),
            $record->id->value(),
            $record->tokenDigest,
            $record->actor->actorType,
            $record->actor->actorId,
            $record->actor->hash(),
            $currentState->value(),
            $record->version,
            $consumedAt->toDatabase()
        );

        return $this->database->query($query) === 1;
    }

    public function invalidate(ConfirmationRecord $record, string $reason, UtcInstant $invalidatedAt): bool
    {
        if (preg_match('/^[a-z][a-z0-9_:-]{2,95}$/D', $reason) !== 1) {
            throw new \InvalidArgumentException('Confirmation invalidation reason is invalid.');
        }

        $query = $this->database->prepare(
            "UPDATE {$this->table}
             SET status = 'invalidated', invalidation_reason = %s, updated_at = %s, version = version + 1
             WHERE public_id = %s AND token_digest = %s
             AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             AND status = 'active' AND version = %d",
            $reason,
            $invalidatedAt->toDatabase(),
            $record->id->value(),
            $record->tokenDigest,
            $record->actor->actorType,
            $record->actor->actorId,
            $record->actor->hash(),
            $record->version
        );

        return $this->database->query($query) === 1;
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): ConfirmationRecord
    {
        $resourceScope = json_decode((string) $row['resource_scope_json'], true);
        $materialPayload = json_decode((string) $row['material_payload_json'], true);
        $acknowledgements = json_decode((string) ($row['acknowledgements_json'] ?? '[]'), true);

        if (!is_array($resourceScope) || !is_array($materialPayload) || !is_array($acknowledgements)) {
            throw new \UnexpectedValueException('Stored confirmation JSON is invalid.');
        }

        return new ConfirmationRecord(
            new ConfirmationId((string) $row['public_id']),
            (string) $row['token_digest'],
            new ActorScope((string) $row['actor_type'], (string) $row['actor_id']),
            self::nullableString($row['session_id'] ?? null),
            self::nullableString($row['conversation_id'] ?? null),
            self::nullableString($row['journey_id'] ?? null),
            (string) $row['action_key'],
            $resourceScope,
            $materialPayload,
            new StateHash((string) $row['state_hash']),
            (string) $row['summary_message_id'],
            (int) $row['summary_version'],
            array_values(array_filter($acknowledgements, 'is_string')),
            (string) $row['idempotency_scope'],
            new CorrelationId((string) $row['correlation_id']),
            ConfirmationStatus::from((string) $row['status']),
            (int) $row['version'],
            UtcInstant::fromDatabase((string) $row['expires_at']),
            self::nullableInstant($row['consumed_at'] ?? null),
            self::nullableString($row['invalidation_reason'] ?? null),
            UtcInstant::fromDatabase((string) $row['created_at']),
            UtcInstant::fromDatabase((string) $row['updated_at'])
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function nullableInstant(mixed $value): ?UtcInstant
    {
        return is_string($value) && $value !== '' ? UtcInstant::fromDatabase($value) : null;
    }
}
