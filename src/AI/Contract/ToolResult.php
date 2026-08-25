<?php
declare(strict_types=1);

namespace Veyra\AI\Contract;

final class ToolResult
{
    /** @param array<string, mixed> $data @param array<int, string> $changedResources */
    public function __construct(
        public readonly string $callId,
        public readonly string $tool,
        public readonly string $status,
        public readonly string $code,
        public readonly array $data,
        public readonly array $changedResources,
        public readonly bool $authoritative,
        public readonly bool $retrySafe,
        public readonly string $correlationId,
        public readonly string $toolVersion = '1.0.0',
        public readonly string $schemaVersion = '1.0.0'
    ) {
        if (!in_array($status, ['succeeded', 'failed', 'partial', 'blocked', 'stale', 'uncertain'], true)) {
            throw new \InvalidArgumentException('Invalid tool result status.');
        }
    }

    /** @param array<string, mixed> $data @param array<int, string> $changedResources */
    public static function success(
        ToolCall $call,
        array $data,
        string $correlationId,
        array $changedResources = []
    ): self {
        return new self($call->callId, $call->name, 'succeeded', 'ok', $data, $changedResources, true, false, $correlationId, $call->version);
    }

    /**
     * Successful interpretive output that cannot authorize claims, components,
     * focus, or writes.
     *
     * @param array<string, mixed> $data
     */
    public static function advisorySuccess(ToolCall $call, array $data, string $correlationId): self
    {
        return new self($call->callId, $call->name, 'succeeded', 'ok', $data, [], false, false, $correlationId, $call->version);
    }

    public static function denied(ToolCall $call, string $code, string $correlationId): self
    {
        return new self($call->callId, $call->name, 'blocked', $code, [], [], true, false, $correlationId, $call->version);
    }

    public static function failed(ToolCall $call, string $code, string $correlationId, bool $retrySafe): self
    {
        return new self($call->callId, $call->name, 'failed', $code, [], [], true, $retrySafe, $correlationId, $call->version);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'call_id' => $this->callId,
            'tool' => $this->tool,
            'tool_version' => $this->toolVersion,
            'status' => $this->status,
            'code' => $this->code,
            'data' => $this->data,
            'changed_resources' => $this->changedResources,
            'authoritative' => $this->authoritative,
            'retry_safe' => $this->retrySafe,
            'correlation_id' => $this->correlationId,
        ];
    }
}
