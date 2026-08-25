<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

final class SensitiveActionLease
{
    public function __construct(
        public readonly ConfirmationRecord $confirmation,
        public readonly IdempotencyRecord $idempotency,
        public readonly string $auditReference
    ) {
    }
}

