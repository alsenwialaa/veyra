<?php

declare(strict_types=1);

namespace Veyra\Shared\Domain;

final class UtcInstant implements \Stringable, \JsonSerializable
{
    private readonly \DateTimeImmutable $value;

    public function __construct(\DateTimeImmutable $value)
    {
        $this->value = $value->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function fromDatabase(string $value): self
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        if (!$date instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('Invalid UTC database instant.');
        }

        return new self($date);
    }

    public function addSeconds(int $seconds): self
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Seconds must not be negative.');
        }

        return new self($this->value->modify(sprintf('+%d seconds', $seconds)));
    }

    public function isBefore(self $other): bool
    {
        return $this->value < $other->value;
    }

    public function isAtOrBefore(self $other): bool
    {
        return $this->value <= $other->value;
    }

    public function toDatabase(): string
    {
        return $this->value->format('Y-m-d H:i:s');
    }

    public function toIso8601(): string
    {
        return $this->value->format('Y-m-d\TH:i:s\Z');
    }

    public function __toString(): string
    {
        return $this->toIso8601();
    }

    public function jsonSerialize(): string
    {
        return $this->toIso8601();
    }
}

