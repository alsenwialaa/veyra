<?php
declare(strict_types=1);

namespace Veyra\Conversation\Domain;

use Veyra\Shared\Domain\CanonicalJson;

/**
 * Per-runtime issuer boundary for Context Bundles.
 *
 * A structurally plausible ContextBundle created by another caller is not a
 * provider authorization. The adapter-side gate accepts only a bundle issued
 * by the exact attestor instance shared with the actor-owned assembler.
 */
final class ContextBundleAttestor
{
    private readonly string $key;

    public function __construct(?string $key = null)
    {
        $key ??= random_bytes(32);
        if (strlen($key) < 32) {
            throw new \InvalidArgumentException('Context Bundle attestation key is invalid.');
        }
        $this->key = $key;
    }

    /** @param array<string, mixed> $projection */
    public function attest(
        array $projection,
        string $actorType,
        string $actorId,
        bool $manifestPersisted
    ): string
    {
        return hash_hmac('sha256', CanonicalJson::encode([
            'projection' => $projection,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'manifest_persisted' => $manifestPersisted,
        ]), $this->key);
    }

    public function verify(ContextBundle $bundle): bool
    {
        try {
            return hash_equals(
                $this->attest(
                    $bundle->forProvider(),
                    $bundle->actorType,
                    $bundle->actorId,
                    $bundle->manifestPersisted()
                ),
                $bundle->attestation()
            );
        } catch (\Throwable) {
            return false;
        }
    }
}
