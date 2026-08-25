<?php
declare(strict_types=1);

namespace Veyra\AI\Contract;

final class ProviderResult
{
    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, scalar|null> $usage
     */
    private function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly ?array $payload,
        public readonly array $usage,
        public readonly bool $retrySafe,
        public readonly string $routeVersion,
        public readonly ?ProviderContinuation $continuation
    ) {
    }

    /** @param array<string, mixed> $payload @param array<string, scalar|null> $usage */
    public static function success(
        array $payload,
        array $usage,
        string $routeVersion,
        ?ProviderContinuation $continuation = null
    ): self
    {
        return new self('succeeded', 'provider_ok', $payload, $usage, false, $routeVersion, $continuation);
    }

    public static function failure(string $code, bool $retrySafe, string $routeVersion = ''): self
    {
        return new self('failed', $code, null, [], $retrySafe, $routeVersion, null);
    }
}
