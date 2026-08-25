<?php

declare(strict_types=1);

namespace Veyra\Tests\Support;

use Veyra\Shared\Domain\Clock;
use Veyra\Shared\Domain\UtcInstant;

final class FrozenClock implements Clock
{
    public function __construct(private UtcInstant $instant)
    {
    }

    public function now(): UtcInstant
    {
        return $this->instant;
    }

    public function advance(int $seconds): void
    {
        $this->instant = $this->instant->addSeconds($seconds);
    }
}

