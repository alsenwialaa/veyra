<?php
declare(strict_types=1);

namespace Veyra\Conversation\Domain;

use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\StateHash;

/** Immutable validated provider projection plus non-content correlation data. */
final class ContextBundle
{
    public readonly string $id;
    public readonly string $schemaVersion;
    public readonly int $bundleVersion;
    public readonly string $conversationId;
    public readonly string $turnMessageId;
    public readonly string $hash;

    /**
     * @param array<string, mixed> $providerProjection A projection already
     *        accepted by ContextBundleContract.
     */
    private function __construct(
        private readonly array $providerProjection,
        public readonly string $actorType,
        public readonly string $actorId,
        private readonly string $runtimeAttestation,
        private readonly bool $manifestPersisted
    ) {
        $this->id = (string) $providerProjection['bundle_id'];
        $this->schemaVersion = (string) $providerProjection['schema_version'];
        $this->bundleVersion = (int) $providerProjection['bundle_version'];
        $this->conversationId = (string) $providerProjection['conversation_id'];
        $this->turnMessageId = (string) $providerProjection['turn_message_id'];
        $this->hash = StateHash::fromPayload($providerProjection)->value();
    }

    /**
     * The constructor is private so callers cannot accidentally treat an
     * arbitrary array as a validated bundle. ProviderTransmissionGate also
     * verifies the per-runtime attestation before any credential access.
     *
     * @param array<string, mixed> $providerProjection
     */
    public static function issue(
        array $providerProjection,
        string $actorType,
        string $actorId,
        ContextBundleAttestor $attestor,
        bool $manifestPersisted = false
    ): self {
        return new self(
            $providerProjection,
            $actorType,
            $actorId,
            $attestor->attest($providerProjection, $actorType, $actorId, $manifestPersisted),
            $manifestPersisted
        );
    }

    public function attestation(): string
    {
        return $this->runtimeAttestation;
    }

    public function manifestPersisted(): bool
    {
        return $this->manifestPersisted;
    }

    /** @return array<string, mixed> */
    public function forProvider(): array
    {
        // Canonical round-tripping prevents caller mutation and preserves one
        // byte-stable projection across decision, response and verification.
        $copy = json_decode(CanonicalJson::encode($this->providerProjection), true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($copy)) {
            throw new ContextBundleException('context_bundle_encoding_failed');
        }
        return $copy;
    }

    public function transmissionAuthorized(): bool
    {
        return ($this->providerProjection['privacy']['transmission_authorized'] ?? false) === true
            && ($this->providerProjection['privacy']['decision_code'] ?? null) === 'runtime_ready';
    }

    /**
     * Safe message/provider correlation only. It intentionally excludes the
     * selected data and the source/selection manifests.
     *
     * @return array<string, bool|int|string>
     */
    public function reference(): array
    {
        return [
            'bundle_id' => $this->id,
            'schema_version' => $this->schemaVersion,
            'bundle_version' => $this->bundleVersion,
            'bundle_hash' => $this->hash,
            'conversation_id' => $this->conversationId,
            'turn_message_id' => $this->turnMessageId,
            'provider_route_id' => (string) $this->providerProjection['privacy']['provider_route_id'],
            'route_manifest_version' => (string) $this->providerProjection['privacy']['route_manifest_version'],
            'transmission_decision_code' => (string) $this->providerProjection['privacy']['decision_code'],
            'manifest_persisted' => $this->manifestPersisted,
            'actual_bytes' => (int) $this->providerProjection['limits']['actual_bytes'],
            'actual_items' => (int) $this->providerProjection['limits']['actual_items'],
            'assembled_at' => (string) $this->providerProjection['assembled_at'],
            'expires_at' => (string) $this->providerProjection['expires_at'],
        ];
    }
}
