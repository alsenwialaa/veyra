<?php
declare(strict_types=1);

namespace Veyra\Conversation\Domain;

use Veyra\Shared\Domain\CanonicalJson;

/** One metadata-only inclusion or exclusion decision for a bounded source. */
final class ContextBundleSource
{
    private const SECTIONS = [
        'current_input', 'focus', 'foreground_journey', 'paused_journeys',
        'recent_visible_messages', 'conversation_memory', 'requirement_state',
        'validated_summary', 'runtime_context', 'authoritative_state',
        'durable_preferences', 'knowledge_evidence', 'modalities',
    ];

    private const CLASSIFICATIONS = [
        'public', 'internal', 'personal', 'sensitive_personal',
        'commerce_confidential', 'protected_file', 'credential_reference',
    ];

    private const AUTHORITIES = [
        'shopper_statement', 'historical', 'validated', 'authoritative', 'unvalidated',
    ];

    private const FRESHNESS = ['fresh', 'stale', 'unknown', 'expired'];

    public readonly string $accountingId;

    public function __construct(
        public readonly string $sourceClass,
        public readonly string $sourceId,
        public readonly int|string $sourceVersion,
        public readonly ?string $sourceMessageId,
        public readonly string $section,
        public readonly string $classification,
        public readonly string $authority,
        public readonly string $freshness,
        public readonly string $observedAt,
        public readonly string $disposition,
        public readonly string $decisionReason,
        ?string $accountingId = null
    ) {
        if (!self::identifier($sourceClass, 80)
            || !self::identifier($sourceId, 191)
            || !self::version($sourceVersion)
            || ($sourceMessageId !== null && !self::identifier($sourceMessageId, 191))
            || !in_array($section, self::SECTIONS, true)
            || !in_array($classification, self::CLASSIFICATIONS, true)
            || !in_array($authority, self::AUTHORITIES, true)
            || !in_array($freshness, self::FRESHNESS, true)
            || !in_array($disposition, ['included', 'excluded'], true)
            || !self::reason($decisionReason)
        ) {
            throw new ContextBundleException('context_bundle_manifest_source_invalid');
        }
        try {
            $instant = new \DateTimeImmutable($observedAt);
        } catch (\Throwable) {
            throw new ContextBundleException('context_bundle_manifest_source_invalid');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $observedAt) !== 1
            || $instant->getOffset() !== 0
            || $instant->format(DATE_ATOM) !== $observedAt
        ) {
            throw new ContextBundleException('context_bundle_manifest_source_invalid');
        }
        $expectedAccountingId = hash('sha256', CanonicalJson::encode([
            'source_class' => $sourceClass,
            'source_id' => $sourceId,
            'source_version' => $sourceVersion,
            'section' => $section,
            'disposition' => $disposition,
            'decision_reason' => $decisionReason,
        ]));
        $this->accountingId = $accountingId ?? $expectedAccountingId;
        if (preg_match('/^[a-f0-9]{64}$/D', $this->accountingId) !== 1
            || !hash_equals($expectedAccountingId, $this->accountingId)
        ) {
            throw new ContextBundleException('context_bundle_manifest_source_invalid');
        }
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'accounting_id' => $this->accountingId,
            'source_class' => $this->sourceClass,
            'source_id' => $this->sourceId,
            'source_version' => $this->sourceVersion,
            'source_message_id' => $this->sourceMessageId,
            'section' => $this->section,
            'classification' => $this->classification,
            'authority' => $this->authority,
            'freshness' => $this->freshness,
            'observed_at' => $this->observedAt,
            'disposition' => $this->disposition,
            'decision_reason' => $this->decisionReason,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $keys = [
            'accounting_id', 'source_class', 'source_id', 'source_version',
            'source_message_id', 'section', 'classification', 'authority',
            'freshness', 'observed_at', 'disposition', 'decision_reason',
        ];
        if (array_diff(array_keys($row), $keys) !== [] || array_diff($keys, array_keys($row)) !== []) {
            throw new ContextBundleException('context_bundle_manifest_source_invalid');
        }
        return new self(
            is_string($row['source_class']) ? $row['source_class'] : '',
            is_string($row['source_id']) ? $row['source_id'] : '',
            is_int($row['source_version']) || is_string($row['source_version']) ? $row['source_version'] : '',
            is_string($row['source_message_id'] ?? null) ? $row['source_message_id'] : null,
            is_string($row['section']) ? $row['section'] : '',
            is_string($row['classification']) ? $row['classification'] : '',
            is_string($row['authority']) ? $row['authority'] : '',
            is_string($row['freshness']) ? $row['freshness'] : '',
            is_string($row['observed_at']) ? $row['observed_at'] : '',
            is_string($row['disposition']) ? $row['disposition'] : '',
            is_string($row['decision_reason']) ? $row['decision_reason'] : '',
            is_string($row['accounting_id']) ? $row['accounting_id'] : ''
        );
    }

    private static function identifier(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maximum
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }

    private static function version(mixed $value): bool
    {
        return (is_int($value) && $value >= 0)
            || (is_string($value) && $value !== '' && strlen($value) <= 191
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1);
    }

    private static function reason(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 160
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) === 1;
    }
}
