<?php

declare(strict_types=1);

namespace Veyra\Shared\Domain;

final class StateHash implements \Stringable, \JsonSerializable
{
    public function __construct(private readonly string $value)
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('State hash must be a lowercase SHA-256 digest.');
        }
    }

    /** @param mixed $payload */
    public static function fromPayload(mixed $payload): self
    {
        return new self(hash('sha256', CanonicalJson::encode($payload)));
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

