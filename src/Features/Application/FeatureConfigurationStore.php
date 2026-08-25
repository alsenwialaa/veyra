<?php

declare(strict_types=1);

namespace Veyra\Features\Application;

use Veyra\Features\Domain\FeatureDefinition;
use Veyra\Features\Domain\FeatureState;

interface FeatureConfigurationStore
{
    public function configuredState(FeatureDefinition $feature): FeatureState;

    public function optionalModuleCertified(FeatureDefinition $feature): bool;
}

