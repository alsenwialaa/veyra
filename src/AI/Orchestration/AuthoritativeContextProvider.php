<?php
declare(strict_types=1);

namespace Veyra\AI\Orchestration;

use Veyra\AI\Tool\ToolContext;

interface AuthoritativeContextProvider
{
    /** @return array<string, mixed> */
    public function runtime(ToolContext $context): array;

    /** @return array<string, mixed> */
    public function commerce(ToolContext $context): array;
}
