<?php

declare(strict_types=1);

namespace Veyra\Features\Domain;

enum ReleaseUnit: string
{
    case ProductionCore = 'production_core';
    case OptionalModule = 'optional_module';
}

