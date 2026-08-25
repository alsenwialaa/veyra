<?php

declare(strict_types=1);

namespace Veyra\Audit\Application;

use Veyra\Audit\Domain\AuditEvent;
use Veyra\Infrastructure\Database\Repository\ActorScope;

interface AuditRepository
{
    public function append(AuditEvent $event): bool;

    /** @return list<array<string, mixed>> */
    public function listForActor(ActorScope $actor, int $limit = 50): array;
}

