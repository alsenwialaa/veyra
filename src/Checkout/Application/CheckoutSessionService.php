<?php

declare(strict_types=1);

namespace Veyra\Checkout\Application;

use Veyra\Checkout\Domain\CheckoutState;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\Clock;

final class CheckoutSessionService
{
    public function __construct(
        private readonly CheckoutStateRepository $states,
        private readonly Clock $clock,
        private readonly int $lifetimeSeconds = 172800
    ) {
        if ($lifetimeSeconds < 900 || $lifetimeSeconds > 2592000) {
            throw new \InvalidArgumentException('Checkout session lifetime is outside the safe bound.');
        }
    }

    /**
     * Server-side entry/preflight operation. The conversation ID and actor are
     * resolved by the runtime, never accepted from the model as identity.
     */
    public function open(
        ActorScope $actor,
        string $conversationId,
        string $cartHash,
        ?string $journeyId = null
    ): CheckoutState {
        $existing = $this->states->findForConversation($actor, $conversationId);
        if ($existing !== null) {
            if (!$existing->isExpiredAt($this->clock->now())) {
                return $existing;
            }

            // The conversation key is intentionally unique. Revive the same
            // actor-owned record with a CAS update, but clear every expired
            // checkout choice so addresses, shipping, payment and totals are
            // never silently carried into a new checkout entry.
            $revived = $existing->evolve([
                'journey_id' => $journeyId,
                'cart_hash' => $cartHash,
                'fulfillment_mode' => null,
                'contacts' => [],
                'billing_address' => [],
                'shipping_address' => [],
                'package_selection' => [],
                'payment_method_id' => null,
                'totals' => [],
                'status' => 'active',
            ], $this->clock->now(), $this->lifetimeSeconds);
            if ($this->states->save($revived, $existing->version)) {
                return $revived;
            }

            $concurrentRevival = $this->states->findForConversation($actor, $conversationId);
            if ($concurrentRevival !== null && !$concurrentRevival->isExpiredAt($this->clock->now())) {
                return $concurrentRevival;
            }

            throw new CheckoutStateConflict('Expired checkout state could not be reopened.');
        }

        $state = CheckoutState::open($actor, $conversationId, $cartHash, $this->clock->now(), $this->lifetimeSeconds, $journeyId);
        if ($this->states->insert($state)) {
            return $state;
        }

        // The conversation key is unique. A concurrent open is recovered as an
        // idempotent success only when the resulting record belongs to the actor.
        $concurrent = $this->states->findForConversation($actor, $conversationId);
        if ($concurrent !== null) {
            return $concurrent;
        }

        throw new CheckoutStateConflict('Checkout state could not be opened.');
    }

    public function current(ActorScope $actor, string $conversationId): ?CheckoutState
    {
        $state = $this->states->findForConversation($actor, $conversationId);
        if ($state === null || $state->isExpiredAt($this->clock->now())) {
            return null;
        }

        return $state;
    }

    /**
     * @param callable(CheckoutState): array<string, mixed> $mutation
     */
    public function mutate(
        ActorScope $actor,
        string $conversationId,
        int $expectedVersion,
        callable $mutation
    ): CheckoutState {
        $current = $this->current($actor, $conversationId);
        if ($current === null) {
            throw new CheckoutStateConflict('Checkout state is missing or expired.');
        }
        if ($current->version !== $expectedVersion) {
            throw new CheckoutStateConflict('Checkout state version is stale.');
        }
        $changes = $mutation($current);
        $next = $current->evolve($changes, $this->clock->now(), $this->lifetimeSeconds);
        if (!$this->states->save($next, $expectedVersion)) {
            throw new CheckoutStateConflict('Checkout state was changed concurrently.');
        }

        return $next;
    }
}
