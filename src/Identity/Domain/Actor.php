<?php

declare(strict_types=1);

namespace Veyra\Identity\Domain;

final class Actor
{
    /** @var array<string, true> */
    private array $capabilities = [];

    /** @param iterable<string|Capability> $capabilities */
    public function __construct(
        public readonly ActorId $id,
        public readonly ActorType $type,
        public readonly ?int $wordpressUserId = null,
        public readonly ?GuestSessionId $guestSessionId = null,
        iterable $capabilities = []
    ) {
        if ($type === ActorType::Guest && $guestSessionId === null) {
            throw new \InvalidArgumentException('Guest actors require a guest session ID.');
        }

        if ($wordpressUserId !== null && $wordpressUserId < 1) {
            throw new \InvalidArgumentException('WordPress user ID must be positive.');
        }

        foreach ($capabilities as $capability) {
            $name = $capability instanceof Capability ? $capability->value() : $capability;
            $this->capabilities[(new Capability($name))->value()] = true;
        }
    }

    public function key(): string
    {
        return $this->type->value . ':' . $this->id->value();
    }

    public function hasCapability(Capability $capability): bool
    {
        return isset($this->capabilities[$capability->value()]);
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return array_keys($this->capabilities);
    }
}

