<?php

declare(strict_types=1);

namespace Veyra\CRM\Infrastructure;

// Internal persistence exceptions are never rendered; adapters escape at the output boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use Veyra\AI\Tool\ToolContext;
use Veyra\Infrastructure\Clock\SystemClock;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\Uuid;

final class WpdbCaseRepository
{
    private readonly string $table;
    private readonly string $attachments;
    private readonly Clock $clock;

    public function __construct(private readonly \wpdb $database, TableNames $tables, ?Clock $clock = null)
    {
        $this->table = $tables->cases();
        $this->attachments = $tables->attachments();
        $this->clock = $clock ?? new SystemClock();
    }

    /** @param array<string, mixed> $request @return array<string, mixed>|null */
    public function createDraft(ToolContext $context, string $caseType, ?int $orderId, array $request): ?array
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $inserted = $this->database->insert($this->table, [
            'public_id' => $id,
            'actor_type' => $context->actorType,
            'actor_id' => $context->actorId,
            'actor_key_hash' => $this->actorHash($context),
            'conversation_id' => $context->conversationId,
            'order_id' => $orderId,
            'case_type' => $caseType,
            'submission_status' => 'draft',
            'decision_status' => null,
            'execution_status' => null,
            'request_json' => CanonicalJson::encode($request),
            'decision_json' => null,
            'execution_json' => null,
            'assigned_user_id' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted !== 1) {
            return null;
        }

        return $this->readKnownWrite($context, $id, 1);
    }

    /** @return array<string, mixed>|null */
    public function get(ToolContext $context, string $caseId): ?array
    {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT * FROM {$this->table} WHERE public_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s LIMIT 1",
            $caseId,
            $context->actorType,
            $context->actorId,
            $this->actorHash($context)
        ), ARRAY_A);
        return is_array($row) ? $this->map($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function list(ToolContext $context, int $limit, ?string $caseType = null, ?int $orderId = null, bool $openOnly = false): array
    {
        $where = ['actor_type = %s', 'actor_id = %s', 'actor_key_hash = %s'];
        $values = [$context->actorType, $context->actorId, $this->actorHash($context)];
        if ($caseType !== null) {
            $where[] = 'case_type = %s';
            $values[] = $caseType;
        }
        if ($orderId !== null) {
            $where[] = 'order_id = %d';
            $values[] = $orderId;
        }
        if ($openOnly) {
            $where[] = "submission_status IN ('draft','submitted')";
            $where[] = "(execution_status IS NULL OR execution_status NOT IN ('completed','closed'))";
        }
        $values[] = max(1, min(50, $limit));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC LIMIT %d',
            ...$values
        ), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }
        return array_values(array_map(fn (array $row): array => $this->map($row), $rows));
    }

    /** @param array<string, mixed> $request */
    public function updateDraft(ToolContext $context, string $caseId, int $expectedVersion, array $request): ?array
    {
        $written = $this->database->query($this->database->prepare(
            "UPDATE {$this->table} SET request_json = %s, version = version + 1, updated_at = %s
             WHERE public_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             AND submission_status = 'draft' AND version = %d",
            CanonicalJson::encode($request),
            gmdate('Y-m-d H:i:s'),
            $caseId,
            $context->actorType,
            $context->actorId,
            $this->actorHash($context),
            $expectedVersion
        ));
        if ($written !== 1) {
            return null;
        }

        return $this->readKnownWrite($context, $caseId, $expectedVersion + 1);
    }

    public function attachmentUsable(ToolContext $context, string $attachmentId): bool
    {
        $value = $this->database->get_var($this->database->prepare(
            "SELECT public_id FROM {$this->attachments}
             WHERE public_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             AND conversation_id = %s AND purpose = 'crm_evidence'
             AND status = 'active' AND scan_status = 'clean' AND deleted_at IS NULL
             AND expires_at > %s LIMIT 1",
            $attachmentId,
            $context->actorType,
            $context->actorId,
            $this->actorHash($context),
            $context->conversationId,
            $this->clock->now()->toDatabase()
        ));
        return is_string($value) && hash_equals($attachmentId, $value);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function map(array $row): array
    {
        $request = json_decode((string) $row['request_json'], true, 64);
        return [
            'case_id' => (string) $row['public_id'],
            'case_number' => 'VYR-' . strtoupper(substr(str_replace('-', '', (string) $row['public_id']), 0, 10)),
            'conversation_id' => (string) $row['conversation_id'],
            'order_id' => isset($row['order_id']) ? (int) $row['order_id'] : null,
            'case_type' => (string) $row['case_type'],
            'submission_status' => (string) $row['submission_status'],
            'decision_status' => is_string($row['decision_status'] ?? null) ? $row['decision_status'] : null,
            'execution_status' => is_string($row['execution_status'] ?? null) ? $row['execution_status'] : null,
            'request' => is_array($request) ? $request : [],
            'version' => (int) $row['version'],
            'created_at' => (string) $row['created_at'] . 'Z',
            'updated_at' => (string) $row['updated_at'] . 'Z',
        ];
    }

    private function actorHash(ToolContext $context): string
    {
        return hash('sha256', $context->actorType . ':' . $context->actorId);
    }

    /** @return array<string, mixed> */
    private function readKnownWrite(ToolContext $context, string $caseId, int $knownVersion): array
    {
        try {
            $case = $this->get($context, $caseId);
        } catch (\Throwable $error) {
            throw new CaseWriteOutcomeUncertain($caseId, $knownVersion, $error);
        }

        if ($case === null
            || !hash_equals($caseId, (string) ($case['case_id'] ?? ''))
            || (int) ($case['version'] ?? 0) < $knownVersion
        ) {
            throw new CaseWriteOutcomeUncertain($caseId, $knownVersion);
        }

        return $case;
    }
}
