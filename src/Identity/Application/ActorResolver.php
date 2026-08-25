<?php

declare(strict_types=1);

namespace Veyra\Identity\Application;

use Veyra\Identity\Domain\Actor;

interface ActorResolver
{
    /** Resolve only an already authenticated user or existing guest session. */
    public function resolve(bool $allowGuest = true): ?Actor;

    /**
     * Explicit guest bootstrap boundary. Public permission callbacks must never
     * call this method because it persists a session and issues cookies.
     */
    public function resolveOrCreateGuest(): Actor;
}
