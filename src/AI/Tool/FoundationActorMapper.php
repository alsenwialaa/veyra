<?php
declare(strict_types=1);

namespace Veyra\AI\Tool;

use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\ActorId;
use Veyra\Identity\Domain\ActorType;
use Veyra\Identity\Domain\GuestSessionId;

final class FoundationActorMapper
{
    public function map(ToolContext $context): Actor
    {
        $type = match ($context->actorType) {
            'guest' => ActorType::Guest,
            'customer' => ActorType::Customer,
            default => ActorType::Staff,
        };
        $guest = $type === ActorType::Guest && $context->guestSessionId !== null
            ? new GuestSessionId($context->guestSessionId)
            : null;
        return new Actor(
            new ActorId($context->actorId),
            $type,
            $context->userId,
            $guest,
            $context->capabilities
        );
    }
}
