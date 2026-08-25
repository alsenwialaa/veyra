<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

final class MigrationResult
{
    /** @param list<string> $appliedVersions */
    public function __construct(
        public readonly bool $succeeded,
        public readonly string $code,
        public readonly array $appliedVersions,
        public readonly ?string $failedVersion = null
    ) {
    }
}

