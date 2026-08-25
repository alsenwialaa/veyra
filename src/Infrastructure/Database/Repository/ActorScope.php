<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Repository;

use Veyra\Identity\Domain\Actor;

final class ActorScope
{
    public function __construct(
        public readonly string $actorType,
        public readonly string $actorId
    ) {
        if ($actorType === '' || $actorId === '') {
            throw new \InvalidArgumentException('Actor scope is required.');
        }
    }

    public static function fromActor(Actor $actor): self
    {
        return new self($actor->type->value, $actor->id->value());
    }

    public function key(): string
    {
        return $this->actorType . ':' . $this->actorId;
    }

    public function hash(): string
    {
        return hash('sha256', $this->key());
    }
}

