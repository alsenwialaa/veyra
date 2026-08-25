<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

final class ConfirmationCheck
{
    private function __construct(
        public readonly bool $valid,
        public readonly string $code,
        public readonly ?ConfirmationRecord $record
    ) {
    }

    public static function valid(ConfirmationRecord $record): self
    {
        return new self(true, 'confirmation_valid', $record);
    }

    public static function invalid(string $code, ?ConfirmationRecord $record = null): self
    {
        return new self(false, $code, $record);
    }
}

