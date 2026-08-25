<?php
declare(strict_types=1);

namespace Veyra\AI\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;

/** Validates every handler result at the universal execution boundary. */
final class ToolResultValidator
{
    private readonly ToolInputValidator $schemaValidator;

    public function __construct(?ToolInputValidator $schemaValidator = null)
    {
        $this->schemaValidator = $schemaValidator ?? new ToolInputValidator();
    }

    public function validate(
        ToolResult $result,
        ToolCall $call,
        ToolDefinition $definition,
        string $correlationId
    ): bool {
        if ($result->schemaVersion !== '1.0.0'
            || $result->callId !== $call->callId
            || $result->tool !== $call->name
            || $result->toolVersion !== $call->version
            || $result->correlationId !== $correlationId
            || preg_match('/^[a-z][a-z0-9_]{0,119}$/D', $result->code) !== 1
            || count($result->changedResources) > 100
        ) {
            return false;
        }

        $seen = [];
        foreach ($result->changedResources as $resource) {
            if (!is_string($resource) || $resource === '' || strlen($resource) > 512 || isset($seen[$resource])) {
                return false;
            }
            $seen[$resource] = true;
        }

        if (in_array($definition->classification, ['read', 'advisory'], true)
            && $result->changedResources !== []
        ) {
            return false;
        }
        if ($definition->classification === 'advisory'
            && $result->status === 'succeeded'
            && $result->authoritative
        ) {
            return false;
        }
        if ($definition->outputSchema !== []
            && in_array($result->status, ['succeeded', 'partial', 'stale'], true)
            && !$this->schemaValidator->validate($result->data, $definition->outputSchema)
        ) {
            return false;
        }

        $encoded = json_encode($result->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($encoded) && strlen($encoded) <= 262144;
    }
}
