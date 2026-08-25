<?php

declare(strict_types=1);

namespace Veyra\Identity\Application;

use Veyra\Identity\Domain\GuestSessionRecord;

final class GuestSessionContext
{
    public function __construct(
        public readonly GuestSessionRecord $session,
        public readonly ?string $newCsrfToken = null
    ) {
    }
}

