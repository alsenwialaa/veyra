<?php

declare(strict_types=1);

namespace Veyra\AI\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;

/**
 * Deterministic server-side preparation for a registered logical tool.
 *
 * A preflight is selected only after the model has produced an exact authorized
 * tool name and valid arguments. It must never perform the tool's customer-
 * visible commerce side effect. Returning a result stops normal execution.
 */
interface ToolExecutionPreflight
{
    public function beforeExecute(ToolCall $call, ToolContext $context): ?ToolResult;
}
