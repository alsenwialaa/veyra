<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

final class ConfirmationConsumeResult
{
    private function __construct(
        public readonly bool $consumed,
        public readonly string $code,
        public readonly ?ConfirmationRecord $record
    ) {
    }

    public static function consumed(ConfirmationRecord $record): self
    {
        return new self(true, 'confirmation_consumed', $record);
    }

    public static function denied(string $code, ?ConfirmationRecord $record = null): self
    {
        return new self(false, $code, $record);
    }
}

