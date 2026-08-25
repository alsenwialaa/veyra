<?php

declare(strict_types=1);

namespace Veyra\Identity\Domain;

use Veyra\Shared\Domain\Identifier;

final class Capability extends Identifier
{
    public function __construct(string $value)
    {
        if (preg_match('/^(manage|view|play|join|pause|send|add|decide|execute|export|erase)_veyra_[a-z0-9_]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid Veyra capability name.');
        }

        parent::__construct($value);
    }
}

