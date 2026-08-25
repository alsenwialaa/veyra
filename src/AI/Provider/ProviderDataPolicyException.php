<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

/** Stable fail-closed error raised while inspecting provider-bound data. */
final class ProviderDataPolicyException extends \RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
