<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

/** Stable fail-closed error for a provider ToolResult projection. */
final class ProviderToolResultProjectionException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
