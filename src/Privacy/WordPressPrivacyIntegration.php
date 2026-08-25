<?php

declare(strict_types=1);

namespace Veyra\Privacy;

use Veyra\Audit\Application\AuditWriter;
use Veyra\Identity\Application\ActorResolver;
use Veyra\Identity\Domain\Actor;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\TableNames;
use Veyra\Media\Infrastructure\ProtectedObjectEraser;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\Uuid;

/** Capability-gated WordPress personal-data exporter and eraser adapters. */
final class WordPressPrivacyIntegration
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly \wpdb $database,
        private readonly TableNames $tables,
        private readonly ActorResolver $actors,
        private readonly AuditWriter $audit,
        private readonly ProtectedObjectEraser $objects
    ) {
    }

    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
    }

    /** @param array<string,mixed> $exporters @return array<string,mixed> */
    public function registerExporter(array $exporters): array
    {
        $exporters['veyra-ai-commerce-agent'] = [
            'exporter_friendly_name' => __('Veyra AI Commerce Agent', 'veyra-ai-commerce-agent'),
            'callback' => [$this, 'exportPersonalData'],
        ];
        return $exporters;
    }

    /** @param array<string,mixed> $erasers @return array<string,mixed> */
    public function registerEraser(array $erasers): array
    {
        $erasers['veyra-ai-commerce-agent'] = [
            'eraser_friendly_name' => __('Veyra AI Commerce Agent', 'veyra-ai-commerce-agent'),
            'callback' => [$this, 'erasePersonalData'],
        ];
        return $erasers;
    }

    /** @return array{data:list<array<string,mixed>>,done:bool}|\WP_Error */
    public function exportPersonalData(string $emailAddress, int $page = 1): array|\WP_Error
    {
        $user = $this->authorizedUser($emailAddress, 'export_veyra_conversations');
        $operator = $this->actors->resolve(false);
        if (!$user instanceof \WP_User || !$operator instanceof Actor) {
            return $this->privacyError(
                'veyra_privacy_export_not_authorized',
                __('Veyra personal-data export was not authorized.', 'veyra-ai-commerce-agent')
            );
        }
        try {
            $this->audit->writeRequired(
                $operator,
                'privacy.personal_data.export',
                'customer',
                'wp-user-' . (int) $user->ID,
                'export_page_authorized',
                new CorrelationId(Uuid::v4()),
                ['page' => max(1, $page)]
            );
        } catch (\Throwable) {
            return $this->privacyError(
                'veyra_privacy_export_audit_unavailable',
                __('Veyra could not record the required export audit event.', 'veyra-ai-commerce-agent')
            );
        }

        $scope = new ActorScope('customer', 'wp-user-' . (int) $user->ID);
        $offset = (max(1, $page) - 1) * self::PAGE_SIZE;
        $data = [];
        $done = true;
        foreach ($this->exportSpecs() as $group => $spec) {
            $rows = $this->database->get_results($this->database->prepare(
                "SELECT {$spec['columns']} FROM {$spec['table']}
                 WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s
                 ORDER BY id ASC LIMIT %d OFFSET %d",
                $scope->actorType,
                $scope->actorId,
                $scope->hash(),
                self::PAGE_SIZE,
                $offset
            ), ARRAY_A);
            if (!is_array($rows)) {
                return $this->privacyError(
                    'veyra_privacy_export_query_failed',
                    __('Veyra personal-data export is temporarily unavailable.', 'veyra-ai-commerce-agent')
                );
            }
            $done = $done && count($rows) < self::PAGE_SIZE;
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $data[] = $this->exportItem($group, $row);
                }
            }
        }
        $legacyRequirements = $this->legacyRequirementExport($scope, $offset);
        if ($legacyRequirements === null) {
            return $this->privacyError(
                'veyra_privacy_export_projection_failed',
                __('Veyra could not safely project all personal data for export.', 'veyra-ai-commerce-agent')
            );
        }
        $data = array_merge($data, $legacyRequirements['data']);
        $done = $done && $legacyRequirements['done'];

        return ['data' => $data, 'done' => $done];
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}|\WP_Error */
    public function erasePersonalData(string $emailAddress, int $page = 1): array|\WP_Error
    {
        unset($page);
        $user = $this->authorizedUser($emailAddress, 'erase_veyra_data');
        $operator = $this->actors->resolve(false);
        if (!$user instanceof \WP_User || !$operator instanceof Actor) {
            return $this->privacyError(
                'veyra_privacy_erasure_not_authorized',
                __('Veyra personal-data erasure was not authorized.', 'veyra-ai-commerce-agent')
            );
        }
        $scope = new ActorScope('customer', 'wp-user-' . (int) $user->ID);
        try {
            // The confirmed WordPress privacy request is the erasure authority.
            $this->audit->writeRequired(
                $operator,
                'privacy.personal_data.erase',
                'customer',
                $scope->actorId,
                'erasure_authorized',
                new CorrelationId(Uuid::v4())
            );
        } catch (\Throwable) {
            return $this->privacyError(
                'veyra_privacy_erasure_audit_unavailable',
                __('Veyra could not record the required erasure audit event.', 'veyra-ai-commerce-agent')
            );
        }

        $removed = false;
        $erasureFailure = false;
        $attachmentTable = $this->tables->attachments();
        $reviewTable = $this->tables->paymentReviews();
        $caseTable = $this->tables->cases();
        $guestSessionTable = $this->tables->guestSessions();
        $attachmentRows = $this->database->get_results($this->database->prepare(
            "SELECT a.id, a.storage_driver, a.storage_key FROM {$attachmentTable} a
             WHERE a.actor_type = %s AND a.actor_id = %s AND a.actor_key_hash = %s
             AND NOT EXISTS (
                 SELECT 1 FROM {$reviewTable} r
                 WHERE r.actor_type = a.actor_type AND r.actor_id = a.actor_id
                 AND r.actor_key_hash = a.actor_key_hash
                 AND (r.evidence_attachment_id = a.public_id
                      OR LOCATE(CONCAT(CHAR(34), a.public_id, CHAR(34)), r.evidence_json) > 0)
             )
             AND NOT EXISTS (
                 SELECT 1 FROM {$caseTable} c
                 WHERE c.actor_type = a.actor_type AND c.actor_id = a.actor_id
                 AND c.actor_key_hash = a.actor_key_hash
                 AND LOCATE(CONCAT(CHAR(34), a.public_id, CHAR(34)), c.request_json) > 0
             )
             ORDER BY a.id ASC LIMIT %d",
            $scope->actorType,
            $scope->actorId,
            $scope->hash(),
            self::PAGE_SIZE
        ), ARRAY_A);
        if (!is_array($attachmentRows)) {
            return $this->privacyError(
                'veyra_privacy_erasure_query_failed',
                __('Veyra attachment erasure is temporarily unavailable.', 'veyra-ai-commerce-agent')
            );
        }
        foreach ($attachmentRows as $row) {
            $id = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
            $driver = is_array($row) && is_string($row['storage_driver'] ?? null) ? $row['storage_driver'] : '';
            $key = is_array($row) && is_string($row['storage_key'] ?? null) ? $row['storage_key'] : '';
            if ($id < 1 || !$this->objects->delete($driver, $key)) {
                $erasureFailure = true;
                continue;
            }
            $deleted = $this->database->query($this->database->prepare(
                "DELETE FROM {$attachmentTable} WHERE id = %d AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s",
                $id,
                $scope->actorType,
                $scope->actorId,
                $scope->hash()
            ));
            $removed = $removed || $deleted === 1;
            $erasureFailure = $erasureFailure || $deleted === false;
        }

        foreach ($this->erasableActorTables() as $table) {
            $result = $this->database->query($this->database->prepare(
                "DELETE FROM {$table} WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s LIMIT %d",
                $scope->actorType,
                $scope->actorId,
                $scope->hash(),
                200
            ));
            if ($result === false) {
                $erasureFailure = true;
            } else {
                $removed = $removed || $result > 0;
            }
        }
        $manifestTable = $this->tables->contextBundleManifests();
        $manifestResult = $this->database->query($this->database->prepare(
            "DELETE FROM {$manifestTable}
             WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             AND legal_hold = 0 LIMIT %d",
            $scope->actorType,
            $scope->actorId,
            $scope->hash(),
            200
        ));
        if ($manifestResult === false) {
            $erasureFailure = true;
        } else {
            $removed = $removed || $manifestResult > 0;
        }
        $guestResult = $this->database->query($this->database->prepare(
            "DELETE FROM {$guestSessionTable} WHERE user_id = %d LIMIT %d",
            (int) $user->ID,
            200
        ));
        $erasureFailure = $erasureFailure || $guestResult === false;
        $removed = $removed || (is_int($guestResult) && $guestResult > 0);

        $remaining = $this->erasableRemainingCount($scope, (int) $user->ID);
        if ($remaining === null) {
            $erasureFailure = true;
        }
        $retainedCount = $this->retainedCount($scope);
        if ($retainedCount === null) {
            $erasureFailure = true;
        }
        $retained = ($retainedCount ?? 0) > 0 || $erasureFailure;
        $messages = $retained
            ? [__('Audit records, cases, payment reviews, and evidence required by those workflows are retained for integrity or legal-policy review.', 'veyra-ai-commerce-agent')]
            : [];
        if ($erasureFailure) {
            $messages[] = __('Some protected data could not be erased safely and was retained.', 'veyra-ai-commerce-agent');
        }
        // WordPress marks the privacy request complete when done is true. A
        // failed verification query must therefore fail closed and be retried,
        // even if every preceding bounded delete reported success.
        $done = !$erasureFailure && $remaining === 0;

        return $this->eraseResult($removed, $retained, $messages, $done);
    }

    private function authorizedUser(string $emailAddress, string $capability): ?\WP_User
    {
        if (!current_user_can($capability) || !is_email($emailAddress)) {
            return null;
        }
        $user = get_user_by('email', $emailAddress);
        return $user instanceof \WP_User && $user->exists() ? $user : null;
    }

    /** @return array<string,array{table:string,columns:string}> */
    private function exportSpecs(): array
    {
        // Customer-export allowlist. Internal reasoning, prompts, staff notes,
        // execution detail, storage coordinates and credential material are
        // deliberately absent; new JSON fields require a reviewed projection.
        return [
            'conversations' => ['table' => $this->tables->conversations(), 'columns' => 'public_id,status,configuration_version,created_at,updated_at'],
            'messages' => ['table' => $this->tables->messages(), 'columns' => 'public_id,conversation_id,sender_type,content_json,language,direction,reply_to_message_id,status,created_at'],
            'journeys' => ['table' => $this->tables->journeys(), 'columns' => 'public_id,conversation_id,journey_type,status,current_step,last_checkpoint_at,created_at,updated_at'],
            'conversation-focus' => ['table' => $this->tables->conversationFocus(), 'columns' => 'public_id,conversation_id,foreground_journey_id,pending_question_id,unresolved_references_json,sensitivity,source_message_id,expires_at,invalidation_reason,created_at,updated_at'],
            // Metadata only: no selected_data, provider body, prompt, tool
            // payload, runtime attestation, or visible-message content is
            // stored in this table.
            'context-bundle-manifests' => ['table' => $this->tables->contextBundleManifests(), 'columns' => 'public_id,manifest_schema_version,bundle_schema_version,bundle_version,bundle_hash,conversation_id,turn_message_id,assembled_actor_type,actor_scope_id,site_scope_id,provider_route_id,route_manifest_version,purpose,transmission_authorized,transmission_decision_code,source_accounting_json,selection_manifest_json,redactions_json,actual_bytes,actual_items,assembled_at,bundle_expires_at,retention_expires_at,legal_hold,version,created_at,updated_at'],
            'requirement-states' => ['table' => $this->tables->requirementStates(), 'columns' => 'public_id,conversation_id,state_json,state_hash,version,last_source_message_id,created_at,updated_at'],
            'pending-questions' => ['table' => $this->tables->pendingQuestions(), 'columns' => 'public_id,conversation_id,journey_id,visible_message_id,question_type,sensitivity,state,expires_at,invalidation_reason,answered_binding_id,answered_message_id,answered_at,created_at,updated_at'],
            'confirmations' => ['table' => $this->tables->confirmations(), 'columns' => 'public_id,conversation_id,journey_id,action_key,summary_message_id,summary_version,acknowledgements_json,status,expires_at,consumed_at,invalidation_reason,created_at,updated_at'],
            'checkout-sessions' => ['table' => $this->tables->checkoutSessions(), 'columns' => 'public_id,conversation_id,journey_id,fulfillment_mode,contact_json,billing_address_json,shipping_address_json,package_selection_json,payment_method_id,totals_json,status,expires_at,created_at,updated_at'],
            'cases' => ['table' => $this->tables->cases(), 'columns' => 'public_id,conversation_id,order_id,case_type,submission_status,decision_status,execution_status,request_json,created_at,updated_at'],
            'payment-reviews' => ['table' => $this->tables->paymentReviews(), 'columns' => 'public_id,conversation_id,order_id,evidence_attachment_id,submission_status,decision_status,transition_status,evidence_json,created_at,updated_at'],
            'attachments' => ['table' => $this->tables->attachments(), 'columns' => 'public_id,conversation_id,message_id,purpose,mime_type,byte_size,scan_status,status,expires_at,created_at,updated_at'],
            'audit-references' => ['table' => $this->tables->audit(), 'columns' => 'public_id,action_key,target_type,target_id,result_code,occurred_at'],
        ];
    }

    /**
     * Schema 1.4 imports legacy conversation-memory requirements lazily. A
     * privacy export must not wait for another shopper turn, so project only
     * that reviewed key for conversations which do not yet have a new head.
     * The rest of memory_json remains excluded from this legacy adapter.
     *
     * @return array{data:list<array<string,mixed>>,done:bool}|null
     */
    private function legacyRequirementExport(ActorScope $scope, int $offset): ?array
    {
        $conversations = $this->tables->conversations();
        $requirements = $this->tables->requirementStates();
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT conversation.public_id, conversation.memory_json
             FROM {$conversations} AS conversation
             LEFT JOIN {$requirements} AS requirement_state
             ON requirement_state.conversation_id = conversation.public_id
             AND requirement_state.actor_type = conversation.actor_type
             AND requirement_state.actor_id = conversation.actor_id
             AND requirement_state.actor_key_hash = conversation.actor_key_hash
             WHERE conversation.actor_type = %s
             AND conversation.actor_id = %s
             AND conversation.actor_key_hash = %s
             AND requirement_state.id IS NULL
             ORDER BY conversation.id ASC LIMIT %d OFFSET %d",
            $scope->actorType,
            $scope->actorId,
            $scope->hash(),
            self::PAGE_SIZE,
            $offset
        ), ARRAY_A);
        if (!is_array($rows)) {
            return null;
        }

        $data = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['public_id'] ?? null)) {
                return null;
            }
            $memoryJson = $row['memory_json'] ?? null;
            if ($memoryJson === null || $memoryJson === '' || !is_string($memoryJson)) {
                continue;
            }
            try {
                $memory = json_decode($memoryJson, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
            if (!is_array($memory)) {
                return null;
            }
            $legacy = $memory['requirements'] ?? [];
            if (!is_array($legacy) || $legacy === []) {
                continue;
            }
            $encoded = function_exists('wp_json_encode')
                ? wp_json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                return null;
            }
            $data[] = $this->exportItem('legacy-requirements', [
                'public_id' => $row['public_id'],
                'conversation_id' => $row['public_id'],
                'requirements_json' => $encoded,
                'migration_state' => 'legacy_conversation_memory_not_yet_imported',
            ]);
        }

        return ['data' => $data, 'done' => count($rows) < self::PAGE_SIZE];
    }

    private function erasableRemainingCount(ActorScope $scope, int $userId): ?int
    {
        $attachmentTable = $this->tables->attachments();
        $reviewTable = $this->tables->paymentReviews();
        $caseTable = $this->tables->cases();
        $attachmentCount = $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM {$attachmentTable} a
             WHERE a.actor_type = %s AND a.actor_id = %s AND a.actor_key_hash = %s
             AND NOT EXISTS (
                 SELECT 1 FROM {$reviewTable} r
                 WHERE r.actor_type = a.actor_type AND r.actor_id = a.actor_id
                 AND r.actor_key_hash = a.actor_key_hash
                 AND (r.evidence_attachment_id = a.public_id
                      OR LOCATE(CONCAT(CHAR(34), a.public_id, CHAR(34)), r.evidence_json) > 0)
             )
             AND NOT EXISTS (
                 SELECT 1 FROM {$caseTable} c
                 WHERE c.actor_type = a.actor_type AND c.actor_id = a.actor_id
                 AND c.actor_key_hash = a.actor_key_hash
                 AND LOCATE(CONCAT(CHAR(34), a.public_id, CHAR(34)), c.request_json) > 0
             )",
            $scope->actorType,
            $scope->actorId,
            $scope->hash()
        ));
        if (!is_numeric($attachmentCount)) {
            return null;
        }
        $remaining = (int) $attachmentCount;
        foreach ($this->erasableActorTables() as $table) {
            $value = $this->database->get_var($this->database->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s",
                $scope->actorType,
                $scope->actorId,
                $scope->hash()
            ));
            if (!is_numeric($value)) {
                return null;
            }
            $remaining += (int) $value;
        }
        $manifestTable = $this->tables->contextBundleManifests();
        $manifestCount = $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM {$manifestTable}
             WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             AND legal_hold = 0",
            $scope->actorType,
            $scope->actorId,
            $scope->hash()
        ));
        if (!is_numeric($manifestCount)) {
            return null;
        }
        $remaining += (int) $manifestCount;
        $guestSessionTable = $this->tables->guestSessions();
        $guestCount = $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM {$guestSessionTable} WHERE user_id = %d",
            $userId
        ));
        if (!is_numeric($guestCount)) {
            return null;
        }

        return $remaining + (int) $guestCount;
    }

    /** @return list<string> */
    private function erasableActorTables(): array
    {
        return [
            $this->tables->messages(),
            $this->tables->conversationFocus(),
            $this->tables->requirementStates(),
            $this->tables->pendingQuestions(),
            $this->tables->journeys(),
            $this->tables->checkoutSessions(),
            $this->tables->confirmations(),
            $this->tables->idempotency(),
            $this->tables->conversations(),
        ];
    }

    private function retainedCount(ActorScope $scope): ?int
    {
        $count = 0;
        foreach ([$this->tables->cases(), $this->tables->paymentReviews(), $this->tables->audit()] as $table) {
            $value = $this->database->get_var($this->database->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s",
                $scope->actorType,
                $scope->actorId,
                $scope->hash()
            ));
            if (!is_numeric($value)) {
                return null;
            }
            $count += (int) $value;
        }
        $manifestTable = $this->tables->contextBundleManifests();
        $heldManifests = $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM {$manifestTable}
             WHERE actor_type = %s AND actor_id = %s AND actor_key_hash = %s
             AND legal_hold = 1",
            $scope->actorType,
            $scope->actorId,
            $scope->hash()
        ));
        if (!is_numeric($heldManifests)) {
            return null;
        }
        $count += (int) $heldManifests;
        return $count;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function exportItem(string $group, array $row): array
    {
        $id = is_string($row['public_id'] ?? null) ? $row['public_id'] : hash('sha256', serialize($row));
        $data = [];
        foreach ($row as $name => $value) {
            $data[] = [
                'name' => ucwords(str_replace('_', ' ', (string) $name)),
                'value' => $this->exportValue((string) $name, $value),
            ];
        }
        $groupLabel = sprintf(
            /* translators: %s: exported Veyra data-group name. */
            __('Veyra %s', 'veyra-ai-commerce-agent'),
            ucwords(str_replace('-', ' ', $group))
        );
        return [
            'group_id' => 'veyra-' . $group,
            'group_label' => $groupLabel,
            'item_id' => 'veyra-' . $group . '-' . $id,
            'data' => $data,
        ];
    }

    private function exportValue(string $name, mixed $value): string
    {
        if (str_ends_with($name, '_json')) {
            if (!is_string($value) || $value === '') {
                return '';
            }
            try {
                $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return '[invalid-json-not-exported]';
            }
            if (!is_array($decoded)) {
                return '[unclassified-json-not-exported]';
            }
            $encoded = wp_json_encode($this->scrubExportValue($decoded));
            return is_string($encoded) ? $encoded : '';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }
        $encoded = wp_json_encode($this->scrubExportValue($value));
        return is_string($encoded) ? $encoded : '';
    }

    private function scrubExportValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 12) {
            return '[depth-limited]';
        }
        if (!is_array($value)) {
            return is_string($value) && strlen($value) > 65535
                ? substr($value, 0, 65535) . '[truncated]'
                : $value;
        }
        $safe = [];
        foreach ($value as $key => $item) {
            $normalized = strtolower((string) $key);
            if (preg_match('/(?:api[_-]?key|credential|token[_-]?digest|storage[_-]?key|hidden[_-]?reason|system[_-]?prompt|provider[_-]?payload|raw[_-]?tool|internal[_-]?note|staff[_-]?note|private[_-]?note|chain[_-]?of[_-]?thought|scratchpad|reasoning|tool[_-]?trace)/', $normalized) === 1) {
                $safe[$key] = '[redacted]';
                continue;
            }
            $safe[$key] = $this->scrubExportValue($item, $depth + 1);
        }
        return $safe;
    }

    /** @param list<string> $messages @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    private function eraseResult(bool $removed, bool $retained, array $messages, bool $done): array
    {
        return ['items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => $done];
    }

    private function privacyError(string $code, string $message): \WP_Error
    {
        return new \WP_Error($code, $message);
    }
}
