<?php

declare(strict_types=1);

namespace Veyra\Features\Application;

use Veyra\Features\Domain\EffectiveFeatureState;
use Veyra\Features\Domain\FeatureDefinition;
use Veyra\Features\Domain\FeatureKey;
use Veyra\Features\Domain\FeatureRegistry;
use Veyra\Features\Domain\FeatureState;
use Veyra\Features\Domain\ReleaseUnit;

final class EffectiveFeatureStateService
{
    public function __construct(
        private readonly FeatureRegistry $features,
        private readonly FeatureConfigurationStore $configuration,
        private readonly RuntimeFeatureRegistry $runtime
    ) {
    }

    public function get(FeatureKey $key): EffectiveFeatureState
    {
        return $this->evaluate($this->features->get($key), []);
    }

    /** @param array<string, true> $stack */
    private function evaluate(FeatureDefinition $feature, array $stack): EffectiveFeatureState
    {
        $key = $feature->key->value();

        if (isset($stack[$key])) {
            return new EffectiveFeatureState($feature->key, FeatureState::Blocked, 'feature_dependency_cycle', $feature->safeFallback);
        }

        $configured = $this->configuration->configuredState($feature);

        if ($configured === FeatureState::Off) {
            if ($feature->foundational) {
                return new EffectiveFeatureState(
                    $feature->key,
                    FeatureState::Blocked,
                    'foundational_feature_cannot_be_disabled',
                    $feature->safeFallback
                );
            }

            return new EffectiveFeatureState($feature->key, FeatureState::Off, 'feature_configured_off', $feature->safeFallback);
        }

        if ($feature->releaseUnit === ReleaseUnit::OptionalModule && !$this->configuration->optionalModuleCertified($feature)) {
            return new EffectiveFeatureState(
                $feature->key,
                FeatureState::Blocked,
                'optional_module_not_certified',
                $feature->safeFallback
            );
        }

        $stack[$key] = true;

        foreach ($feature->dependencies as $dependency) {
            $dependencyState = $this->evaluate($this->features->get($dependency), $stack);

            if (!$dependencyState->usable()) {
                return new EffectiveFeatureState(
                    $feature->key,
                    FeatureState::Blocked,
                    'feature_dependency_unavailable:' . $dependency->value(),
                    $feature->safeFallback
                );
            }
        }

        $runtime = $this->runtime->availability($feature->key);

        return new EffectiveFeatureState($feature->key, $runtime->state, $runtime->reasonCode, $feature->safeFallback);
    }
}

