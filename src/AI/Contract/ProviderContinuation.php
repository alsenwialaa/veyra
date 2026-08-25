<?php
declare(strict_types=1);

namespace Veyra\AI\Contract;

/**
 * Opaque provider-owned state required to continue a stateless interaction.
 *
 * Orchestration may carry this value between calls, but provider-specific
 * history remains confined to the adapter boundary.
 */
interface ProviderContinuation
{
    public function providerKey(): string;
}
