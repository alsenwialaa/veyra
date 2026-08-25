<?php

declare(strict_types=1);

namespace Veyra\Features\Domain;

use Veyra\Shared\Domain\Identifier;

final class FeatureKey extends Identifier
{
    public function __construct(string $value)
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,95}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Feature key is invalid.');
        }

        parent::__construct($value);
    }
}

