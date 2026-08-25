<?php
declare(strict_types=1);

namespace Veyra\AI\Contract;

interface ProviderAdapter
{
    public function providerKey(): string;

    public function execute(ProviderRequest $request): ProviderResult;
}
