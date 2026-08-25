<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

final class Container
{
    /** @var array<string, object|callable(self): object> */
    private array $entries = [];

    /** @var array<string, object> */
    private array $resolved = [];

    /** @param object|callable(self): object $entry */
    public function set(string $id, object|callable $entry): void
    {
        $this->entries[$id] = $entry;
        unset($this->resolved[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->entries[$id]) || isset($this->resolved[$id]);
    }

    public function get(string $id): object
    {
        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        if (!isset($this->entries[$id])) {
            throw new \RuntimeException(sprintf('Unknown Veyra service "%s".', $id));
        }

        $entry = $this->entries[$id];
        $service = is_callable($entry) ? $entry($this) : $entry;

        if (!is_object($service)) {
            throw new \RuntimeException(sprintf('Veyra service "%s" did not resolve to an object.', $id));
        }

        $this->resolved[$id] = $service;

        return $service;
    }
}

