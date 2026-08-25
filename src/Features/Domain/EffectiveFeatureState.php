<?php

declare(strict_types=1);

namespace Veyra\Features\Domain;

final class EffectiveFeatureState
{
    public function __construct(
        public readonly FeatureKey $key,
        public readonly FeatureState $state,
        public readonly string $reasonCode,
        public readonly string $safeFallback
    ) {
    }

    public function usable(): bool
    {
        return $this->state === FeatureState::On || $this->state === FeatureState::Degraded;
    }
}

