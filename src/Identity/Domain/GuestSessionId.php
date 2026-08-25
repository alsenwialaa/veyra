<?php

declare(strict_types=1);

namespace Veyra\Identity\Domain;

use Veyra\Shared\Domain\Identifier;
use Veyra\Shared\Domain\Uuid;

final class GuestSessionId extends Identifier
{
    public function __construct(string $value)
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException('Guest session ID must be a UUIDv4.');
        }

        parent::__construct($value);
    }
}

