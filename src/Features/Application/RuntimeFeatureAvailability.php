<?php

declare(strict_types=1);

namespace Veyra\Features\Application;

use Veyra\Features\Domain\FeatureState;

final class RuntimeFeatureAvailability
{
    public function __construct(
        public readonly FeatureState $state,
        public readonly string $reasonCode
    ) {
        if ($state === FeatureState::Off) {
            throw new \InvalidArgumentException('Runtime availability uses Blocked, Degraded, or On; configured Off is separate.');
        }
    }
}

