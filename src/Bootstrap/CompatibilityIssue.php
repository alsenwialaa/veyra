<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

final class CompatibilityIssue
{
    public function __construct(
        public readonly string $code,
        public readonly string $scope,
        public readonly string $message,
        public readonly bool $blocksFoundation
    ) {
    }
}

