<?php

declare(strict_types=1);

namespace Veyra\Identity\Application;

use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\ResourceReference;

interface OwnershipResolver
{
    public function supports(string $resourceType): bool;

    public function owns(Actor $actor, ResourceReference $resource): bool;
}

