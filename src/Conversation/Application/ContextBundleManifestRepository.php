<?php
declare(strict_types=1);

namespace Veyra\Conversation\Application;

use Veyra\Conversation\Domain\ContextBundleManifest;

/** Durable actor-scoped storage for metadata-only Context Bundle manifests. */
interface ContextBundleManifestRepository
{
    /**
     * Persists one immutable manifest before its bundle may cross a provider
     * boundary. Reusing a bundle ID is an error, never an update.
     */
    public function save(ContextBundleManifest $manifest): bool;

    public function findOwned(
        string $bundleId,
        string $actorType,
        string $actorId
    ): ?ContextBundleManifest;
}
