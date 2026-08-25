<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

final class IdempotencyDecision
{
    public function __construct(
        public readonly IdempotencyDecisionStatus $status,
        public readonly string $code,
        public readonly IdempotencyRecord $record
    ) {
    }
}

