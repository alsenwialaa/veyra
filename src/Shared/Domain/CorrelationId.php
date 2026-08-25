<?php

declare(strict_types=1);

namespace Veyra\Shared\Domain;

final class CorrelationId extends Identifier
{
    public function __construct(string $value)
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException('Correlation ID must be a UUIDv4.');
        }

        parent::__construct($value);
    }

    public static function generate(): self
    {
        return new self(Uuid::v4());
    }
}

