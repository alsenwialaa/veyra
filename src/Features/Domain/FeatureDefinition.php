<?php

declare(strict_types=1);

namespace Veyra\Features\Domain;

final class FeatureDefinition
{
    /** @param list<FeatureKey> $dependencies */
    public function __construct(
        public readonly FeatureKey $key,
        public readonly ReleaseUnit $releaseUnit,
        public readonly bool $defaultOn,
        public readonly bool $foundational,
        public readonly string $safeFallback,
        public readonly array $dependencies = []
    ) {
    }
}

