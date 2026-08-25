<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Application;

use Veyra\Confirmation\Domain\LockRecord;
use Veyra\Shared\Domain\UtcInstant;

interface LockRepository
{
    public function acquire(LockRecord $candidate, UtcInstant $now): ?LockRecord;

    public function release(LockRecord $record): bool;

    public function refresh(LockRecord $record, UtcInstant $newExpiry, UtcInstant $now): ?LockRecord;
}

