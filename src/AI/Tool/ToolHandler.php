<?php
declare(strict_types=1);

namespace Veyra\AI\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;

interface ToolHandler
{
    /** @return array<int, ToolDefinition> */
    public function definitions(): array;

    public function execute(ToolCall $call, ToolContext $context): ToolResult;
}
