<?php

declare(strict_types=1);

namespace Veyra\Identity\Application;

use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\Capability;
use Veyra\Identity\Domain\CapabilityRegistry;

final class CapabilityPolicy
{
    public function allows(Actor $actor, Capability $capability): bool
    {
        return CapabilityRegistry::contains($capability->value()) && $actor->hasCapability($capability);
    }
}

