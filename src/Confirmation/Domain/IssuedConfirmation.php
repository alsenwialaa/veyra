<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

final class IssuedConfirmation
{
    public function __construct(
        public readonly ConfirmationRecord $record,
        public readonly string $token
    ) {
    }
}

