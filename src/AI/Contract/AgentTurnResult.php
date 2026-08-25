<?php
declare(strict_types=1);

namespace Veyra\AI\Contract;

final class AgentTurnResult
{
    /**
     * @param array<int, array<string, mixed>> $components
     * @param array<int, array<string, mixed>> $evidence
     * @param array<int, ToolResult>            $toolResults
     */
    public function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly string $visibleText,
        public readonly array $components,
        public readonly array $evidence,
        public readonly array $toolResults,
        public readonly ?array $focusUpdate,
        public readonly string $correlationId
    ) {
    }
}
