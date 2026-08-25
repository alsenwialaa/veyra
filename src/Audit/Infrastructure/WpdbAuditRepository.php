<?php

declare(strict_types=1);

namespace Veyra\Audit\Infrastructure;

use Veyra\Audit\Application\AuditRepository;
use Veyra\Audit\Domain\AuditEvent;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Shared\Domain\CanonicalJson;

final class WpdbAuditRepository implements AuditRepository
{
    private readonly string $table;

    public function __construct(private readonly \wpdb $database, TableNames $tables)
    {
        $this->table = $tables->audit();
    }

    public function append(AuditEvent $event): bool
    {
        $actorScope = ActorScope::fromActor($event->actor);

        return $this->database->insert(
            $this->table,
            [
                'public_id' => $event->id,
                'actor_type' => $actorScope->actorType,
                'actor_id' => $actorScope->actorId,
                'actor_key_hash' => $actorScope->hash(),
                'action_key' => $event->action,
                'target_type' => $event->targetType,
                'target_id' => $event->targetId,
                'result_code' => $event->resultCode,
                'correlation_id' => $event->correlationId->value(),
                'metadata_json' => CanonicalJson::encode($event->metadata),
                'occurred_at' => $event->occurredAt->toDatabase(),
                'created_at' => $event->occurredAt->toDatabase(),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        ) === 1;
    }

    public function listForActor(ActorScope $actor, int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        $query = $this->database->prepare(
            "SELECT public_id, action_key, target_type, target_id, result_code, correlation_id, metadata_json, occurred_at
             FROM {$this->table}
             WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             ORDER BY occurred_at DESC, id DESC LIMIT %d",
            $actor->actorType,
            $actor->actorId,
            $actor->hash(),
            $limit
        );
        $rows = $this->database->get_results($query, ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(
            static function (array $row): array {
                $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
                $row['metadata'] = is_array($metadata) ? $metadata : [];
                unset($row['metadata_json']);

                return $row;
            },
            $rows
        );
    }
}

