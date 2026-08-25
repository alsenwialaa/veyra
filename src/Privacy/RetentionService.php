<?php

declare(strict_types=1);

namespace Veyra\Privacy;

use Veyra\Infrastructure\Database\TableNames;
use Veyra\Media\Infrastructure\ProtectedObjectEraser;

/** Bounded cleanup of records that carry an explicit server-side expiry. */
final class RetentionService
{
    public const HEALTH_OPTION = 'veyra_retention_health_v1';

    public function __construct(
        private readonly \wpdb $database,
        private readonly TableNames $tables,
        private readonly ProtectedObjectEraser $objects
    ) {
    }

    /** @return array{state:string,deleted:int,attachment_failures:int} */
    public function run(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $deleted = 0;
        $attachmentFailures = 0;
        $attachmentTable = $this->tables->attachments();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT id, storage_driver, storage_key FROM {$attachmentTable}
             WHERE expires_at < %s AND deleted_at IS NULL ORDER BY id ASC LIMIT %d",
            $now,
            100
        ), ARRAY_A);
        if (!is_array($rows)) {
            return $this->record('blocked', 0, 0);
        }
        foreach ($rows as $row) {
            $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
            $driver = is_array($row) && is_string($row['storage_driver'] ?? null) ? $row['storage_driver'] : '';
            $key = is_array($row) && is_string($row['storage_key'] ?? null) ? $row['storage_key'] : '';
            if ($id < 1 || !$this->objects->delete($driver, $key)) {
                ++$attachmentFailures;
                continue;
            }
            $updated = $this->database->query($this->database->prepare(
                "UPDATE {$attachmentTable}
                 SET status = %s,
                     scan_status = CASE WHEN scan_status = %s THEN %s ELSE scan_status END,
                     deleted_at = %s,
                     updated_at = %s,
                     version = version + 1
                 WHERE id = %d AND storage_driver = %s AND storage_key = %s AND deleted_at IS NULL",
                'deleted',
                'clean',
                'unavailable',
                $now,
                $now,
                $id,
                $driver,
                $key
            ));
            if ($updated === 1) {
                ++$deleted;
            } else {
                ++$attachmentFailures;
            }
        }

        foreach ([
            $this->tables->guestSessions(),
            $this->tables->confirmations(),
            $this->tables->idempotency(),
            $this->tables->locks(),
            $this->tables->checkoutSessions(),
            $this->tables->rateLimits(),
        ] as $table) {
            $result = $this->database->query($this->database->prepare(
                "DELETE FROM {$table} WHERE expires_at < %s LIMIT %d",
                $now,
                500
            ));
            if ($result === false) {
                return $this->record('blocked', $deleted, $attachmentFailures);
            }
            $deleted += max(0, (int) $result);
        }

        // No default retention period is invented here. A manifest becomes
        // eligible only after an explicit policy has populated its retention
        // deadline, and legal hold always wins. Bundle TTL is a transmission
        // boundary, not a record-retention instruction.
        $manifestTable = $this->tables->contextBundleManifests();
        $manifestResult = $this->database->query($this->database->prepare(
            "DELETE FROM {$manifestTable}
             WHERE retention_expires_at IS NOT NULL
             AND retention_expires_at < %s
             AND legal_hold = 0
             LIMIT %d",
            $now,
            500
        ));
        if ($manifestResult === false) {
            return $this->record('blocked', $deleted, $attachmentFailures);
        }
        $deleted += max(0, (int) $manifestResult);

        return $this->record($attachmentFailures === 0 ? 'ready' : 'degraded', $deleted, $attachmentFailures);
    }

    /** @return array{state:string,deleted:int,attachment_failures:int} */
    private function record(string $state, int $deleted, int $attachmentFailures): array
    {
        $health = [
            'state' => $state,
            'deleted' => $deleted,
            'attachment_failures' => $attachmentFailures,
            'checked_at' => gmdate('Y-m-d H:i:s'),
        ];
        update_option(self::HEALTH_OPTION, $health, false);

        return $health;
    }
}
