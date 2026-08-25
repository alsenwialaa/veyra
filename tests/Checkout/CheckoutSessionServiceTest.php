<?php

declare(strict_types=1);

namespace Veyra\Tests\Checkout;

use PHPUnit\Framework\TestCase;
use Veyra\Checkout\Application\CheckoutSessionService;
use Veyra\Checkout\Application\CheckoutStateConflict;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Checkout\Support\InMemoryCheckoutStateRepository;
use Veyra\Tests\Support\FrozenClock;

final class CheckoutSessionServiceTest extends TestCase
{
    public function testOpenIsActorScopedAndConversationIdempotent(): void
    {
        $repository = new InMemoryCheckoutStateRepository();
        $service = new CheckoutSessionService(
            $repository,
            new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00')),
            3600
        );
        $actor = new ActorScope('customer', 'wp-user-5');
        $conversation = '33333333-3333-4333-8333-333333333333';
        $first = $service->open($actor, $conversation, str_repeat('d', 64));
        $second = $service->open($actor, $conversation, str_repeat('e', 64));

        self::assertSame($first->id, $second->id);
        self::assertSame(str_repeat('d', 64), $second->cartHash, 'A duplicate open must not silently rewrite checkout state.');
        self::assertNull($service->current(new ActorScope('customer', 'wp-user-6'), $conversation));
    }

    public function testMutationUsesOptimisticVersioning(): void
    {
        $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
        $service = new CheckoutSessionService(new InMemoryCheckoutStateRepository(), $clock, 3600);
        $actor = new ActorScope('customer', 'wp-user-5');
        $conversation = '44444444-4444-4444-8444-444444444444';
        $opened = $service->open($actor, $conversation, str_repeat('f', 64));
        $changed = $service->mutate(
            $actor,
            $conversation,
            $opened->version,
            static fn (): array => ['fulfillment_mode' => 'delivery']
        );
        self::assertSame(2, $changed->version);

        $this->expectException(CheckoutStateConflict::class);
        $service->mutate($actor, $conversation, $opened->version, static fn (): array => ['fulfillment_mode' => 'pickup']);
    }
}
