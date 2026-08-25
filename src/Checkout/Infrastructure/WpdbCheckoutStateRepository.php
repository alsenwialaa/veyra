<?php

declare(strict_types=1);

namespace Veyra\Checkout\Infrastructure;

use Veyra\Checkout\Application\CheckoutStateRepository;
use Veyra\Checkout\Domain\CheckoutState;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\Repository\ActorScopedRepository;

final class WpdbCheckoutStateRepository extends ActorScopedRepository implements CheckoutStateRepository
{
    public function __construct(\wpdb $database)
    {
        parent::__construct($database, $database->prefix . 'veyra_checkout_sessions');
    }

    public function findForConversation(ActorScope $actor, string $conversationId): ?CheckoutState
    {
        $query = $this->database->prepare(
            "SELECT * FROM {$this->table} WHERE conversation_id = %s AND actor_type = %s AND actor_id = %s AND actor_key_hash = %s LIMIT 1",
            $conversationId,
            $actor->actorType,
            $actor->actorId,
            $actor->hash()
        );
        $row = $this->database->get_row($query, ARRAY_A);

        return is_array($row) ? CheckoutState::fromRow($row) : null;
    }

    public function findById(ActorScope $actor, string $checkoutId): ?CheckoutState
    {
        $row = $this->findScopedRow($actor, $checkoutId);

        return $row !== null ? CheckoutState::fromRow($row) : null;
    }

    public function insert(CheckoutState $state): bool
    {
        $values = $state->persistenceValues();

        return $this->database->insert(
            $this->table,
            $values,
            [
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s',
            ]
        ) === 1;
    }

    public function save(CheckoutState $state, int $expectedVersion): bool
    {
        if ($state->version !== $expectedVersion + 1) {
            throw new \InvalidArgumentException('Checkout state version transition is invalid.');
        }
        $values = $state->persistenceValues();
        unset(
            $values['public_id'],
            $values['conversation_id'],
            $values['actor_type'],
            $values['actor_id'],
            $values['actor_key_hash'],
            $values['version'],
            $values['created_at']
        );

        return $this->updateScopedVersioned($state->actor, $state->id, $expectedVersion, $values);
    }
}
