<?php
declare(strict_types=1);

namespace Veyra\Conversation\Domain;

use Veyra\Shared\Domain\CanonicalJson;

/**
 * Durable metadata-only record of one Context Bundle selection decision.
 *
 * It deliberately cannot retain selected_data, message text, provider bodies,
 * tool payloads, hidden reasoning, credentials, or runtime attestations.
 */
final class ContextBundleManifest
{
    public const SCHEMA_VERSION = '1.0.0';

    private const ACTOR_TYPES = ['guest', 'customer', 'support', 'reviewer', 'manager', 'administrator'];

    private const SECTIONS = [
        'current_input', 'focus', 'foreground_journey', 'paused_journeys',
        'recent_visible_messages', 'conversation_memory', 'requirement_state',
        'validated_summary', 'runtime_context', 'authoritative_state',
        'durable_preferences', 'knowledge_evidence', 'modalities',
    ];

    /** @var list<ContextBundleSource> */
    private array $sources;

    /** @var array<string, mixed> */
    private array $selectionManifest;

    /** @var list<string> */
    private array $redactions;

    /**
     * @param list<ContextBundleSource> $sources
     * @param array<string, mixed> $selectionManifest
     * @param list<string> $redactions
     */
    private function __construct(
        public readonly string $bundleId,
        public readonly string $bundleSchemaVersion,
        public readonly int $bundleVersion,
        public readonly string $bundleHash,
        public readonly string $conversationId,
        public readonly string $turnMessageId,
        public readonly string $actorType,
        public readonly string $actorId,
        public readonly string $assembledActorType,
        public readonly string $actorScopeId,
        public readonly string $siteScopeId,
        public readonly string $providerRouteId,
        public readonly string $routeManifestVersion,
        public readonly string $purpose,
        public readonly bool $transmissionAuthorized,
        public readonly string $transmissionDecisionCode,
        array $sources,
        array $selectionManifest,
        array $redactions,
        public readonly int $actualBytes,
        public readonly int $actualItems,
        public readonly string $assembledAt,
        public readonly string $bundleExpiresAt,
        public readonly ?string $retentionExpiresAt,
        public readonly bool $legalHold,
        public readonly int $version
    ) {
        foreach ([
            [$bundleId, 64],
            [$conversationId, 36],
            [$turnMessageId, 36],
            [$actorId, 191],
            [$actorScopeId, 64],
            [$siteScopeId, 64],
        ] as [$id, $maximum]) {
            if (!self::identifier($id, $maximum)) {
                throw new ContextBundleException('context_bundle_manifest_invalid');
            }
        }
        foreach ([$bundleSchemaVersion, $providerRouteId, $routeManifestVersion, $transmissionDecisionCode] as $id) {
            if (!self::identifier($id, 128)) {
                throw new ContextBundleException('context_bundle_manifest_invalid');
            }
        }
        if (!in_array($actorType, self::ACTOR_TYPES, true)
            || !in_array($assembledActorType, self::ACTOR_TYPES, true)
            || $bundleVersion < 1 || $version < 1
            || preg_match('/^[a-f0-9]{64}$/D', $bundleHash) !== 1
            || $purpose === '' || strlen($purpose) > 160 || preg_match('//u', $purpose) !== 1
            || $actualBytes < 1 || $actualItems < 1
            || !array_is_list($sources) || count($sources) > 4000
            || !array_is_list($redactions) || count($redactions) > 100
        ) {
            throw new ContextBundleException('context_bundle_manifest_invalid');
        }
        foreach ($redactions as $redaction) {
            if (!self::reason($redaction)) {
                throw new ContextBundleException('context_bundle_manifest_invalid');
            }
        }
        $this->validateInstants($assembledAt, $bundleExpiresAt, $retentionExpiresAt);
        $this->validateSelection($selectionManifest, $sources, $actualItems);
        $seen = [];
        $decisions = [];
        foreach ($sources as $source) {
            if (!$source instanceof ContextBundleSource || isset($seen[$source->accountingId])) {
                throw new ContextBundleException('context_bundle_manifest_source_invalid');
            }
            // One complete ledger may decide each section-qualified source
            // identity exactly once. An identity cannot be both included and
            // excluded, even with a different reason.
            $decision = $source->section . '|' . $source->sourceClass . '|' . $source->sourceId . '|'
                . (string) $source->sourceVersion;
            if (isset($decisions[$decision])) {
                throw new ContextBundleException('context_bundle_manifest_source_invalid');
            }
            $seen[$source->accountingId] = true;
            $decisions[$decision] = true;
        }
        $this->sources = array_values($sources);
        $this->selectionManifest = self::copy($selectionManifest);
        $this->redactions = array_values($redactions);
    }

    /**
     * @param array<string, mixed> $projection
     * @param list<ContextBundleSource> $sources
     */
    public static function fromBundle(
        array $projection,
        string $actorType,
        string $actorId,
        string $bundleHash,
        array $sources
    ): self {
        $actorScope = is_array($projection['actor_scope'] ?? null) ? $projection['actor_scope'] : [];
        $privacy = is_array($projection['privacy'] ?? null) ? $projection['privacy'] : [];
        $limits = is_array($projection['limits'] ?? null) ? $projection['limits'] : [];
        $selection = is_array($projection['selection_manifest'] ?? null)
            ? $projection['selection_manifest']
            : [];
        $redactions = is_array($privacy['redactions_applied'] ?? null)
            ? array_values(array_filter($privacy['redactions_applied'], 'is_string'))
            : [];

        $assembledActorType = ($actorScope['actor_type'] ?? null) === 'payment_reviewer'
            ? 'reviewer'
            : (is_string($actorScope['actor_type'] ?? null) ? $actorScope['actor_type'] : '');

        return new self(
            is_string($projection['bundle_id'] ?? null) ? $projection['bundle_id'] : '',
            is_string($projection['schema_version'] ?? null) ? $projection['schema_version'] : '',
            is_int($projection['bundle_version'] ?? null) ? $projection['bundle_version'] : 0,
            $bundleHash,
            is_string($projection['conversation_id'] ?? null) ? $projection['conversation_id'] : '',
            is_string($projection['turn_message_id'] ?? null) ? $projection['turn_message_id'] : '',
            $actorType,
            $actorId,
            $assembledActorType,
            is_string($actorScope['actor_id'] ?? null) ? $actorScope['actor_id'] : '',
            is_string($actorScope['site_id'] ?? null) ? $actorScope['site_id'] : '',
            is_string($privacy['provider_route_id'] ?? null) ? $privacy['provider_route_id'] : '',
            is_string($privacy['route_manifest_version'] ?? null) ? $privacy['route_manifest_version'] : '',
            is_string($projection['purpose'] ?? null) ? $projection['purpose'] : '',
            ($privacy['transmission_authorized'] ?? false) === true,
            is_string($privacy['decision_code'] ?? null) ? $privacy['decision_code'] : '',
            $sources,
            $selection,
            $redactions,
            is_int($limits['actual_bytes'] ?? null) ? $limits['actual_bytes'] : 0,
            is_int($limits['actual_items'] ?? null) ? $limits['actual_items'] : 0,
            is_string($projection['assembled_at'] ?? null) ? $projection['assembled_at'] : '',
            is_string($projection['expires_at'] ?? null) ? $projection['expires_at'] : '',
            null,
            false,
            1
        );
    }

    /** @return list<ContextBundleSource> */
    public function sources(): array
    {
        return $this->sources;
    }

    /** @return array<string, mixed> */
    public function storageProjection(): array
    {
        return [
            'manifest_schema_version' => self::SCHEMA_VERSION,
            'bundle_id' => $this->bundleId,
            'bundle_schema_version' => $this->bundleSchemaVersion,
            'bundle_version' => $this->bundleVersion,
            'bundle_hash' => $this->bundleHash,
            'conversation_id' => $this->conversationId,
            'turn_message_id' => $this->turnMessageId,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'assembled_actor_type' => $this->assembledActorType,
            'actor_scope_id' => $this->actorScopeId,
            'site_scope_id' => $this->siteScopeId,
            'provider_route_id' => $this->providerRouteId,
            'route_manifest_version' => $this->routeManifestVersion,
            'purpose' => $this->purpose,
            'transmission_authorized' => $this->transmissionAuthorized,
            'transmission_decision_code' => $this->transmissionDecisionCode,
            'source_accounting' => array_map(
                static fn (ContextBundleSource $source): array => $source->toArray(),
                $this->sources
            ),
            'selection_manifest' => self::copy($this->selectionManifest),
            'redactions' => $this->redactions,
            'actual_bytes' => $this->actualBytes,
            'actual_items' => $this->actualItems,
            'assembled_at' => $this->assembledAt,
            'bundle_expires_at' => $this->bundleExpiresAt,
            'retention_expires_at' => $this->retentionExpiresAt,
            'legal_hold' => $this->legalHold,
            'version' => $this->version,
        ];
    }

    public function metadataHash(): string
    {
        $immutable = $this->storageProjection();
        // Actor ownership, legal hold, retention and row version are mutable
        // only through lifecycle services. The hash attests the immutable
        // bundle/source/selection record so guest linking does not invalidate
        // it while save/read-back still verifies the exact actor scope.
        unset(
            $immutable['actor_type'],
            $immutable['actor_id'],
            $immutable['retention_expires_at'],
            $immutable['legal_hold'],
            $immutable['version']
        );
        return hash('sha256', CanonicalJson::encode($immutable));
    }

    /** @param array<string, mixed> $row */
    public static function fromStorageProjection(array $row): self
    {
        $keys = [
            'manifest_schema_version', 'bundle_id', 'bundle_schema_version',
            'bundle_version', 'bundle_hash', 'conversation_id', 'turn_message_id',
            'actor_type', 'actor_id', 'assembled_actor_type', 'actor_scope_id',
            'site_scope_id', 'provider_route_id', 'route_manifest_version',
            'purpose', 'transmission_authorized', 'transmission_decision_code',
            'source_accounting', 'selection_manifest', 'redactions', 'actual_bytes',
            'actual_items', 'assembled_at', 'bundle_expires_at',
            'retention_expires_at', 'legal_hold', 'version',
        ];
        if (array_diff(array_keys($row), $keys) !== [] || array_diff($keys, array_keys($row)) !== []
            || ($row['manifest_schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !is_array($row['source_accounting']) || !array_is_list($row['source_accounting'])
            || !is_array($row['selection_manifest']) || !is_array($row['redactions'])
        ) {
            throw new ContextBundleException('context_bundle_manifest_invalid');
        }
        $sources = array_map(
            static fn (mixed $source): ContextBundleSource => ContextBundleSource::fromArray(
                is_array($source) ? $source : []
            ),
            $row['source_accounting']
        );
        return new self(
            is_string($row['bundle_id']) ? $row['bundle_id'] : '',
            is_string($row['bundle_schema_version']) ? $row['bundle_schema_version'] : '',
            is_int($row['bundle_version']) ? $row['bundle_version'] : 0,
            is_string($row['bundle_hash']) ? $row['bundle_hash'] : '',
            is_string($row['conversation_id']) ? $row['conversation_id'] : '',
            is_string($row['turn_message_id']) ? $row['turn_message_id'] : '',
            is_string($row['actor_type']) ? $row['actor_type'] : '',
            is_string($row['actor_id']) ? $row['actor_id'] : '',
            is_string($row['assembled_actor_type']) ? $row['assembled_actor_type'] : '',
            is_string($row['actor_scope_id']) ? $row['actor_scope_id'] : '',
            is_string($row['site_scope_id']) ? $row['site_scope_id'] : '',
            is_string($row['provider_route_id']) ? $row['provider_route_id'] : '',
            is_string($row['route_manifest_version']) ? $row['route_manifest_version'] : '',
            is_string($row['purpose']) ? $row['purpose'] : '',
            $row['transmission_authorized'] === true,
            is_string($row['transmission_decision_code']) ? $row['transmission_decision_code'] : '',
            $sources,
            $row['selection_manifest'],
            array_values(array_filter($row['redactions'], 'is_string')),
            is_int($row['actual_bytes']) ? $row['actual_bytes'] : 0,
            is_int($row['actual_items']) ? $row['actual_items'] : 0,
            is_string($row['assembled_at']) ? $row['assembled_at'] : '',
            is_string($row['bundle_expires_at']) ? $row['bundle_expires_at'] : '',
            is_string($row['retention_expires_at'] ?? null) ? $row['retention_expires_at'] : null,
            $row['legal_hold'] === true,
            is_int($row['version']) ? $row['version'] : 0
        );
    }

    /**
     * @param array<string, mixed> $selection
     * @param list<ContextBundleSource> $sources
     */
    private function validateSelection(array $selection, array $sources, int $actualItems): void
    {
        if (array_diff(array_keys($selection), ['included_count', 'excluded_count', 'truncated', 'sections']) !== []
            || array_diff(['included_count', 'excluded_count', 'truncated', 'sections'], array_keys($selection)) !== []
            || !is_int($selection['included_count']) || $selection['included_count'] < 1
            || !is_int($selection['excluded_count']) || $selection['excluded_count'] < 0
            || !is_bool($selection['truncated'])
            || !is_array($selection['sections']) || !array_is_list($selection['sections'])
            || count($selection['sections']) !== count(self::SECTIONS)
        ) {
            throw new ContextBundleException('context_bundle_manifest_accounting_invalid');
        }
        $counts = [];
        $sourceIncluded = 0;
        $sourceExcluded = 0;
        foreach ($sources as $source) {
            if (!$source instanceof ContextBundleSource) {
                throw new ContextBundleException('context_bundle_manifest_source_invalid');
            }
            $counts[$source->section][$source->disposition] = ($counts[$source->section][$source->disposition] ?? 0) + 1;
            $source->disposition === 'included' ? ++$sourceIncluded : ++$sourceExcluded;
        }
        $included = 0;
        $excluded = 0;
        $seen = [];
        foreach ($selection['sections'] as $section) {
            if (!is_array($section)
                || array_diff(array_keys($section), [
                    'section', 'available_count', 'included_count', 'excluded_count',
                    'truncated', 'selection_reasons', 'exclusion_reasons',
                ]) !== []
                || !is_string($section['section'] ?? null)
                || !in_array($section['section'], self::SECTIONS, true)
                || isset($seen[$section['section']])
                || !is_int($section['available_count'] ?? null)
                || $section['available_count'] < 0
                || !is_int($section['included_count'] ?? null)
                || $section['included_count'] < 0
                || !is_int($section['excluded_count'] ?? null)
                || $section['excluded_count'] < 0
                || $section['available_count'] !== $section['included_count'] + $section['excluded_count']
                || !is_bool($section['truncated'] ?? null)
                || $section['truncated'] !== ($section['excluded_count'] > 0)
                || !self::reasonList($section['selection_reasons'] ?? null)
                || !self::reasonList($section['exclusion_reasons'] ?? null)
                || ($counts[$section['section']]['included'] ?? 0) !== $section['included_count']
                || ($counts[$section['section']]['excluded'] ?? 0) !== $section['excluded_count']
            ) {
                throw new ContextBundleException('context_bundle_manifest_accounting_invalid');
            }
            $includedReasons = [];
            $excludedReasons = [];
            foreach ($sources as $source) {
                if (!$source instanceof ContextBundleSource || $source->section !== $section['section']) {
                    continue;
                }
                if ($source->disposition === 'included') {
                    $includedReasons[$source->decisionReason] = true;
                } else {
                    $excludedReasons[$source->decisionReason] = true;
                }
            }
            $declaredIncludedReasons = array_fill_keys($section['selection_reasons'], true);
            $declaredExcludedReasons = array_fill_keys($section['exclusion_reasons'], true);
            if (array_diff_key($includedReasons, $declaredIncludedReasons) !== []
                || array_diff_key($declaredIncludedReasons, $includedReasons) !== []
                || array_diff_key($excludedReasons, $declaredExcludedReasons) !== []
                || array_diff_key($declaredExcludedReasons, $excludedReasons) !== []
            ) {
                throw new ContextBundleException('context_bundle_manifest_accounting_invalid');
            }
            $seen[$section['section']] = true;
            $included += $section['included_count'];
            $excluded += $section['excluded_count'];
        }
        if (array_diff(self::SECTIONS, array_keys($seen)) !== []
            || array_diff(array_keys($seen), self::SECTIONS) !== []
            || $included !== $selection['included_count'] || $excluded !== $selection['excluded_count']
            || $sourceIncluded !== $included || $sourceExcluded !== $excluded
            || array_diff(array_keys($counts), array_keys($seen)) !== []
            || $selection['truncated'] !== ($excluded > 0) || $actualItems !== $included
        ) {
            throw new ContextBundleException('context_bundle_manifest_accounting_invalid');
        }
    }

    private function validateInstants(string $assembled, string $expires, ?string $retention): void
    {
        foreach (array_filter([$assembled, $expires, $retention], static fn (mixed $value): bool => $value !== null) as $instant) {
            if (!is_string($instant)
                || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $instant) !== 1
            ) {
                throw new ContextBundleException('context_bundle_manifest_invalid');
            }
        }
        try {
            $assembledAt = new \DateTimeImmutable($assembled);
            $expiresAt = new \DateTimeImmutable($expires);
            $retentionAt = $retention !== null ? new \DateTimeImmutable($retention) : null;
        } catch (\Throwable) {
            throw new ContextBundleException('context_bundle_manifest_invalid');
        }
        if ($assembledAt->getOffset() !== 0 || $expiresAt->getOffset() !== 0
            || $assembledAt->format(DATE_ATOM) !== $assembled
            || $expiresAt->format(DATE_ATOM) !== $expires
            || $expiresAt <= $assembledAt
            || ($retentionAt !== null && ($retentionAt->getOffset() !== 0
                || $retentionAt->format(DATE_ATOM) !== $retention
                || $retentionAt <= $assembledAt))
        ) {
            throw new ContextBundleException('context_bundle_manifest_invalid');
        }
    }

    /** @return array<string, mixed> */
    private static function copy(array $value): array
    {
        $copy = json_decode(CanonicalJson::encode($value), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($copy)) {
            throw new ContextBundleException('context_bundle_manifest_invalid');
        }
        return $copy;
    }

    private static function identifier(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }

    private static function reason(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 160
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }

    private static function reasonList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            return false;
        }
        $seen = [];
        foreach ($value as $reason) {
            if (!self::reason($reason) || isset($seen[$reason])) {
                return false;
            }
            $seen[$reason] = true;
        }
        return true;
    }
}
