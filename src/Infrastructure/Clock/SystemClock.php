<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Clock;

use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\UtcInstant;

final class SystemClock implements Clock
{
    public function now(): UtcInstant
    {
        return new UtcInstant(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }
}

