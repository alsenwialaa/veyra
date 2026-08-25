<?php

declare(strict_types=1);

namespace Veyra\Requirements\Contract;

use Veyra\Requirements\Domain\RequirementState;

interface RequirementStateRepository
{
    public function loadOwned(
        string $conversationId,
        string $actorType,
        string $actorId
    ): ?RequirementState;

    public function compareAndSwap(RequirementState $expected, RequirementState $next): bool;
}
