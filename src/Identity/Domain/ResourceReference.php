<?php

declare(strict_types=1);

namespace Veyra\Identity\Domain;

use Veyra\Shared\Domain\Identifier;

final class ResourceReference
{
    public function __construct(
        public readonly string $type,
        public readonly Identifier $id
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $type) !== 1) {
            throw new \InvalidArgumentException('Resource type is invalid.');
        }
    }
}

