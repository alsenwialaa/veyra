<?php

declare(strict_types=1);

namespace Veyra\Features\Domain;

enum FeatureState: string
{
    case On = 'on';
    case Off = 'off';
    case Blocked = 'blocked';
    case Degraded = 'degraded';
}

