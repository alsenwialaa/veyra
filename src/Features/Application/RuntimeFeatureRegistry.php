<?php

declare(strict_types=1);

namespace Veyra\Features\Application;

use Veyra\Features\Domain\FeatureKey;
use Veyra\Features\Domain\FeatureState;

final class RuntimeFeatureRegistry
{
    /** @var array<string, RuntimeFeatureAvailability> */
    private array $availability = [];

    public function register(FeatureKey $key, FeatureState $state, string $reasonCode = 'runtime_ready'): void
    {
        $this->availability[$key->value()] = new RuntimeFeatureAvailability($state, $reasonCode);
    }

    public function availability(FeatureKey $key): RuntimeFeatureAvailability
    {
        return $this->availability[$key->value()]
            ?? new RuntimeFeatureAvailability(FeatureState::Blocked, 'feature_implementation_unavailable');
    }
}

