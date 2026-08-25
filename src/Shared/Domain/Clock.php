<?php

declare(strict_types=1);

namespace Veyra\Shared\Domain;

interface Clock
{
    public function now(): UtcInstant;
}

