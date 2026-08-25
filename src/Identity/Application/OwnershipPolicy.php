<?php

declare(strict_types=1);

namespace Veyra\Identity\Application;

use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\ResourceReference;

final class OwnershipPolicy
{
    /** @param iterable<OwnershipResolver> $resolvers */
    public function __construct(private readonly iterable $resolvers)
    {
    }

    public function owns(Actor $actor, ResourceReference $resource): bool
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($resource->type)) {
                return $resolver->owns($actor, $resource);
            }
        }

        return false;
    }
}

