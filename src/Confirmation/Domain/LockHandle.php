<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

final class LockHandle
{
    public function __construct(
        public readonly LockRecord $record,
        public readonly string $ownerToken
    ) {
    }
}

