<?php
declare(strict_types=1);

namespace Veyra\AI\Provider;

final class RouteManifest
{
    /** @var array<string, mixed> */
    private array $manifest;

    public function __construct(string $manifestPath)
    {
        if (!is_file($manifestPath)) {
            throw new \RuntimeException('Provider route manifest is missing.');
        }

        $loaded = require $manifestPath;
        if (!is_array($loaded) || !isset($loaded['routes']) || !is_array($loaded['routes'])) {
            throw new \RuntimeException('Provider route manifest is invalid.');
        }
        $this->manifest = $loaded;
    }

    /** @return array<string, mixed> */
    public function route(string $routeId): array
    {
        $route = $this->manifest['routes'][$routeId] ?? null;
        if (!is_array($route)) {
            throw new \OutOfBoundsException('Unknown provider route.');
        }
        return $route;
    }

    public function version(): string
    {
        return (string) ($this->manifest['manifest_version'] ?? '');
    }
}
