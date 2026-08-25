<?php

declare(strict_types=1);

namespace Veyra\Features\Application;

use Veyra\Features\Domain\EffectiveFeatureState;
use Veyra\Features\Domain\FeatureKey;

final class FeatureGate
{
    public function __construct(private readonly EffectiveFeatureStateService $states)
    {
    }

    public function inspect(FeatureKey $feature): EffectiveFeatureState
    {
        return $this->states->get($feature);
    }

    public function allows(FeatureKey $feature): bool
    {
        return $this->inspect($feature)->usable();
    }
}

