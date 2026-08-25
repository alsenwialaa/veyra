<?php

declare(strict_types=1);

namespace Veyra\Checkout\Application;

use Veyra\Checkout\Domain\CheckoutState;
use Veyra\Infrastructure\Database\Repository\ActorScope;

interface CheckoutStateRepository
{
    public function findForConversation(ActorScope $actor, string $conversationId): ?CheckoutState;

    public function findById(ActorScope $actor, string $checkoutId): ?CheckoutState;

    public function insert(CheckoutState $state): bool;

    public function save(CheckoutState $state, int $expectedVersion): bool;
}
