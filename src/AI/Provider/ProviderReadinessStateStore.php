<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

/** Single persistence boundary shared by readiness and send-time gating. */
final class ProviderReadinessStateStore
{
    public const OPTION = 'veyra_provider_readiness_v1';

    /** @return array<string, mixed> */
    public function current(RouteManifest $manifest): array
    {
        $state = function_exists('get_option') ? get_option(self::OPTION, []) : [];
        if (!is_array($state)) {
            $state = [];
        }
        return array_merge([
            'state' => 'Unconfigured',
            'route_id' => ProviderReleaseGate::ROUTE_ID,
            'route_version' => $manifest->version(),
            'checked_at' => null,
            'capabilities' => [],
            'safe_error_code' => null,
            'release_certified' => false,
        ], $state);
    }

    /** @param array<string, mixed> $state */
    public function replace(array $state): void
    {
        if (!function_exists('update_option') || !function_exists('get_option')) {
            throw new \RuntimeException('Provider readiness persistence is unavailable.');
        }
        update_option(self::OPTION, $state, false);
        $stored = get_option(self::OPTION, null);
        if (!is_array($stored) || $stored !== $state) {
            throw new \RuntimeException('Provider readiness persistence could not be verified.');
        }
    }
}
