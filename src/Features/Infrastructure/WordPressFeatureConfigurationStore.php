<?php

declare(strict_types=1);

namespace Veyra\Features\Infrastructure;

use Veyra\Features\Application\FeatureConfigurationStore;
use Veyra\Features\Domain\FeatureDefinition;
use Veyra\Features\Domain\FeatureState;

final class WordPressFeatureConfigurationStore implements FeatureConfigurationStore
{
    public const OPTION = 'veyra_feature_configuration';
    public const CERTIFICATION_OPTION = 'veyra_optional_module_certification';

    public function configuredState(FeatureDefinition $feature): FeatureState
    {
        $configuration = get_option(self::OPTION, []);
        $rawState = is_array($configuration) ? ($configuration[$feature->key->value()] ?? null) : null;

        if ($rawState === FeatureState::On->value) {
            return FeatureState::On;
        }

        if ($rawState === FeatureState::Off->value) {
            return FeatureState::Off;
        }

        return $feature->defaultOn ? FeatureState::On : FeatureState::Off;
    }

    public function optionalModuleCertified(FeatureDefinition $feature): bool
    {
        $certifications = get_option(self::CERTIFICATION_OPTION, []);

        return is_array($certifications)
            && isset($certifications[$feature->key->value()])
            && $certifications[$feature->key->value()] === true;
    }
}

