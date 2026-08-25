<?php

declare(strict_types=1);

namespace Veyra\Audit\Application;

use Veyra\Identity\Application\CapabilityPolicy;
use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\Capability;
use Veyra\Infrastructure\Database\Repository\ActorScope;

final class AuditReader
{
    public function __construct(
        private readonly AuditRepository $repository,
        private readonly CapabilityPolicy $capabilities
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listForActor(Actor $viewer, ActorScope $subject, int $limit = 50): array
    {
        if (!$this->capabilities->allows($viewer, new Capability('view_veyra_audit'))) {
            return [];
        }

        return $this->repository->listForActor($subject, max(1, min($limit, 100)));
    }
}

