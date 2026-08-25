<?php

declare(strict_types=1);

namespace Veyra\Tests\Support;

use Veyra\Features\Application\FeatureConfigurationStore;
use Veyra\Features\Domain\FeatureDefinition;
use Veyra\Features\Domain\FeatureState;

final class InMemoryFeatureConfigurationStore implements FeatureConfigurationStore
{
    /** @param array<string, FeatureState> $states @param list<string> $certified */
    public function __construct(
        private readonly array $states = [],
        private readonly array $certified = []
    ) {
    }

    public function configuredState(FeatureDefinition $feature): FeatureState
    {
        return $this->states[$feature->key->value()]
            ?? ($feature->defaultOn ? FeatureState::On : FeatureState::Off);
    }

    public function optionalModuleCertified(FeatureDefinition $feature): bool
    {
        return in_array($feature->key->value(), $this->certified, true);
    }
}

