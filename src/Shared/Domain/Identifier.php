<?php

declare(strict_types=1);

namespace Veyra\Shared\Domain;

class Identifier implements \Stringable, \JsonSerializable
{
    public function __construct(private readonly string $value)
    {
        $length = strlen($value);

        if ($length < 1 || $length > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('Identifier must be non-empty, bounded, and contain no control characters.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}

