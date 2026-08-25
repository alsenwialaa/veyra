<?php

declare(strict_types=1);

namespace Veyra\Shared\Domain;

final class ResourceVersion implements \JsonSerializable
{
    public function __construct(private readonly int $value)
    {
        if ($value < 1) {
            throw new \InvalidArgumentException('Resource version must be positive.');
        }
    }

    public function value(): int
    {
        return $this->value;
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }

    public function jsonSerialize(): int
    {
        return $this->value;
    }
}

