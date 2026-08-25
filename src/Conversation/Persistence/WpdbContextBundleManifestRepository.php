<?php
declare(strict_types=1);

namespace Veyra\Conversation\Persistence;

use Veyra\Conversation\Application\ContextBundleManifestRepository;
use Veyra\Conversation\Domain\ContextBundleManifest;
use Veyra\Infrastructure\Database\TableNames;

/** Actor-scoped immutable persistence for metadata-only bundle manifests. */
final class WpdbContextBundleManifestRepository implements ContextBundleManifestRepository
{
    public function __construct(
        private readonly \wpdb $database,
        private readonly TableNames $tables
    ) {
    }

    public function save(ContextBundleManifest $manifest): bool
    {
        $projection = $manifest->storageProjection();
        $now = self::databaseInstant($manifest->assembledAt);
        $inserted = $this->database->insert($this->tables->contextBundleManifests(), [
            'public_id' => $manifest->bundleId,
            'manifest_schema_version' => ContextBundleManifest::SCHEMA_VERSION,
            'bundle_schema_version' => $manifest->bundleSchemaVersion,
            'bundle_version' => $manifest->bundleVersion,
            'bundle_hash' => $manifest->bundleHash,
            'metadata_hash' => $manifest->metadataHash(),
            'conversation_id' => $manifest->conversationId,
            'turn_message_id' => $manifest->turnMessageId,
            'actor_type' => $manifest->actorType,
            'actor_id' => $manifest->actorId,
            'actor_key_hash' => hash('sha256', $manifest->actorType . ':' . $manifest->actorId),
            'assembled_actor_type' => $manifest->assembledActorType,
            'actor_scope_id' => $manifest->actorScopeId,
            'site_scope_id' => $manifest->siteScopeId,
            'provider_route_id' => $manifest->providerRouteId,
            'route_manifest_version' => $manifest->routeManifestVersion,
            'purpose' => $manifest->purpose,
            'transmission_authorized' => $manifest->transmissionAuthorized ? 1 : 0,
            'transmission_decision_code' => $manifest->transmissionDecisionCode,
            'source_accounting_json' => $this->encode($projection['source_accounting']),
            'selection_manifest_json' => $this->encode($projection['selection_manifest']),
            'redactions_json' => $this->encode($projection['redactions']),
            'actual_bytes' => $manifest->actualBytes,
            'actual_items' => $manifest->actualItems,
            'assembled_at' => $now,
            'bundle_expires_at' => self::databaseInstant($manifest->bundleExpiresAt),
            'retention_expires_at' => $manifest->retentionExpiresAt !== null
                ? self::databaseInstant($manifest->retentionExpiresAt)
                : null,
            'legal_hold' => $manifest->legalHold ? 1 : 0,
            'erased_at' => null,
            'version' => $manifest->version,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted !== 1) {
            return false;
        }

        // A write acknowledgement is insufficient. Re-read through the exact
        // owner scope and verify the immutable metadata hash before allowing
        // the bundle to be marked persistence-backed.
        $stored = $this->findOwned($manifest->bundleId, $manifest->actorType, $manifest->actorId);
        return $stored instanceof ContextBundleManifest
            && hash_equals($manifest->metadataHash(), $stored->metadataHash())
            && hash_equals($manifest->bundleHash, $stored->bundleHash);
    }

    public function findOwned(
        string $bundleId,
        string $actorType,
        string $actorId
    ): ?ContextBundleManifest {
        if (!self::identifier($bundleId, 64) || !self::identifier($actorId, 191)) {
            return null;
        }
        $row = $this->database->get_row($this->database->prepare(
            'SELECT public_id,manifest_schema_version,bundle_schema_version,bundle_version,bundle_hash,metadata_hash,' .
                'conversation_id,turn_message_id,actor_type,actor_id,assembled_actor_type,actor_scope_id,site_scope_id,' .
                'provider_route_id,route_manifest_version,purpose,transmission_authorized,transmission_decision_code,' .
                'source_accounting_json,selection_manifest_json,redactions_json,actual_bytes,actual_items,assembled_at,' .
                'bundle_expires_at,retention_expires_at,legal_hold,version FROM ' .
                $this->tables->contextBundleManifests() .
                ' WHERE public_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s AND erased_at IS NULL LIMIT 1',
            $bundleId,
            $actorType,
            $actorId,
            hash('sha256', $actorType . ':' . $actorId)
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        try {
            $projection = [
                'manifest_schema_version' => (string) $row['manifest_schema_version'],
                'bundle_id' => (string) $row['public_id'],
                'bundle_schema_version' => (string) $row['bundle_schema_version'],
                'bundle_version' => (int) $row['bundle_version'],
                'bundle_hash' => (string) $row['bundle_hash'],
                'conversation_id' => (string) $row['conversation_id'],
                'turn_message_id' => (string) $row['turn_message_id'],
                'actor_type' => (string) $row['actor_type'],
                'actor_id' => (string) $row['actor_id'],
                'assembled_actor_type' => (string) $row['assembled_actor_type'],
                'actor_scope_id' => (string) $row['actor_scope_id'],
                'site_scope_id' => (string) $row['site_scope_id'],
                'provider_route_id' => (string) $row['provider_route_id'],
                'route_manifest_version' => (string) $row['route_manifest_version'],
                'purpose' => (string) $row['purpose'],
                'transmission_authorized' => (int) $row['transmission_authorized'] === 1,
                'transmission_decision_code' => (string) $row['transmission_decision_code'],
                'source_accounting' => $this->decode((string) $row['source_accounting_json']),
                'selection_manifest' => $this->decode((string) $row['selection_manifest_json']),
                'redactions' => $this->decode((string) $row['redactions_json']),
                'actual_bytes' => (int) $row['actual_bytes'],
                'actual_items' => (int) $row['actual_items'],
                'assembled_at' => self::canonicalInstant((string) $row['assembled_at']),
                'bundle_expires_at' => self::canonicalInstant((string) $row['bundle_expires_at']),
                'retention_expires_at' => is_string($row['retention_expires_at'] ?? null)
                    && $row['retention_expires_at'] !== ''
                        ? self::canonicalInstant($row['retention_expires_at'])
                        : null,
                'legal_hold' => (int) $row['legal_hold'] === 1,
                'version' => (int) $row['version'],
            ];
            $manifest = ContextBundleManifest::fromStorageProjection($projection);
            if (!is_string($row['metadata_hash'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $row['metadata_hash']) !== 1
                || !hash_equals((string) $row['metadata_hash'], $manifest->metadataHash())
            ) {
                return null;
            }
            return $manifest;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param mixed $value */
    private function encode(mixed $value): string
    {
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Context Bundle manifest JSON encoding failed.');
        }
        return $encoded;
    }

    /** @return array<string|int, mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Context Bundle manifest JSON decoding failed.');
        }
        return $decoded;
    }

    private static function databaseInstant(string $instant): string
    {
        return (new \DateTimeImmutable($instant))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function canonicalInstant(string $instant): string
    {
        return (new \DateTimeImmutable($instant, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(DATE_ATOM);
    }

    private static function identifier(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }
}
