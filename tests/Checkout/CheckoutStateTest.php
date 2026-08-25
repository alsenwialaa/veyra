<?php

declare(strict_types=1);

namespace Veyra\Tests\Checkout;

use PHPUnit\Framework\TestCase;
use Veyra\Checkout\Domain\CheckoutState;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\UtcInstant;

final class CheckoutStateTest extends TestCase
{
    public function testStateHashCoversActorChoicesVersionAndCart(): void
    {
        $now = UtcInstant::fromDatabase('2026-08-24 10:00:00');
        $state = CheckoutState::open(
            new ActorScope('customer', 'wp-user-42'),
            '11111111-1111-4111-8111-111111111111',
            str_repeat('a', 64),
            $now,
            3600
        );
        $next = $state->evolve([
            'fulfillment_mode' => 'delivery',
            'contacts' => ['shipping' => ['first_name' => 'fixture-name', 'phone' => 'fixture-phone']],
            'shipping_address' => ['country' => 'ZZ', 'city' => 'fixture-city', 'address_1' => 'fixture-address'],
        ], $now->addSeconds(10), 3600);

        self::assertSame(2, $next->version);
        self::assertNotSame($state->stateHash()->value(), $next->stateHash()->value());
        self::assertSame('delivery', $next->jsonSerialize()['fulfillment_mode']);
        self::assertArrayNotHasKey('actor_id', $next->jsonSerialize());
    }

    public function testPersistedDigestIsVerifiedDuringHydration(): void
    {
        $state = CheckoutState::open(
            new ActorScope('customer', 'wp-user-7'),
            '22222222-2222-4222-8222-222222222222',
            str_repeat('b', 64),
            UtcInstant::fromDatabase('2026-08-24 10:00:00'),
            3600
        );
        $row = $state->persistenceValues();
        self::assertSame($state->id, CheckoutState::fromRow($row)->id);

        $row['cart_hash'] = str_repeat('c', 64);
        $this->expectException(\UnexpectedValueException::class);
        CheckoutState::fromRow($row);
    }
}
