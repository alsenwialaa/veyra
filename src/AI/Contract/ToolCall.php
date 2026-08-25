<?php
declare(strict_types=1);

namespace Veyra\AI\Contract;

final class ToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public readonly string $callId,
        public readonly string $name,
        public readonly string $version,
        public readonly array $arguments
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Invalid logical tool name.');
        }
        if ($callId === '' || $version !== '1.0.0') {
            throw new \InvalidArgumentException('Invalid tool call envelope.');
        }
    }
}
