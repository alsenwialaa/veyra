<?php

declare(strict_types=1);

namespace Veyra\Tests\Checkout\Support;

use Veyra\Checkout\Application\CheckoutStateRepository;
use Veyra\Checkout\Domain\CheckoutState;
use Veyra\Infrastructure\Database\Repository\ActorScope;

final class InMemoryCheckoutStateRepository implements CheckoutStateRepository
{
    /** @var array<string, CheckoutState> */
    private array $byId = [];

    /** @var array<string, string> */
    private array $conversationIndex = [];

    private bool $failNextSave = false;

    public function failNextSave(): void
    {
        $this->failNextSave = true;
    }

    public function findForConversation(ActorScope $actor, string $conversationId): ?CheckoutState
    {
        $key = $actor->hash() . ':' . $conversationId;
        $id = $this->conversationIndex[$key] ?? null;

        return $id !== null ? ($this->byId[$id] ?? null) : null;
    }

    public function findById(ActorScope $actor, string $checkoutId): ?CheckoutState
    {
        $state = $this->byId[$checkoutId] ?? null;

        return $state !== null && hash_equals($state->actor->hash(), $actor->hash()) ? $state : null;
    }

    public function insert(CheckoutState $state): bool
    {
        $conversationKey = $state->actor->hash() . ':' . $state->conversationId;
        if (isset($this->byId[$state->id]) || isset($this->conversationIndex[$conversationKey])) {
            return false;
        }
        $this->byId[$state->id] = $state;
        $this->conversationIndex[$conversationKey] = $state->id;

        return true;
    }

    public function save(CheckoutState $state, int $expectedVersion): bool
    {
        if ($this->failNextSave) {
            $this->failNextSave = false;

            return false;
        }
        $current = $this->byId[$state->id] ?? null;
        if ($current === null
            || $current->version !== $expectedVersion
            || $state->version !== $expectedVersion + 1
            || !hash_equals($current->actor->hash(), $state->actor->hash())
        ) {
            return false;
        }
        $this->byId[$state->id] = $state;

        return true;
    }
}
