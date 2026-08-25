<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

final class CompatibilityReport
{
    /** @param list<CompatibilityIssue> $issues */
    public function __construct(public readonly array $issues)
    {
    }

    public function foundationReady(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->blocksFoundation) {
                return false;
            }
        }

        return true;
    }

    public function commerceReady(): bool
    {
        if (!$this->foundationReady()) {
            return false;
        }

        foreach ($this->issues as $issue) {
            if ($issue->scope === 'woocommerce') {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(
            static fn (CompatibilityIssue $issue): string => $issue->code,
            $this->issues
        );
    }
}

