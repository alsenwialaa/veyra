<?php

declare(strict_types=1);

namespace Veyra\Tests\Unit\Confirmation;

use PHPUnit\Framework\TestCase;
use Veyra\Confirmation\Application\ConfirmationService;
use Veyra\Confirmation\Domain\ConfirmationRequest;
use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\ActorId;
use Veyra\Identity\Domain\ActorType;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Support\FrozenClock;
use Veyra\Tests\Support\InMemoryConfirmationRepository;

final class ConfirmationServiceTest extends TestCase
{
    public function testCrossRequestConsumptionPreservesActorStateAndSingleUseBindings(): void
    {
        $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
        $repository = new InMemoryConfirmationRepository();
        $service = new ConfirmationService(
            $repository,
            new SecretDigester(str_repeat('k', 32)),
            $clock
        );
        $actor = new Actor(new ActorId('wp-user-42'), ActorType::Customer, 42);
        $creationCorrelation = CorrelationId::generate();
        $consumptionCorrelation = CorrelationId::generate();
        $state = StateHash::fromPayload(['cart_version' => 8, 'total' => '75.00']);
        $issued = $service->create(
            $actor,
            new ConfirmationRequest(
                'order.place',
                ['cart' => 'cart-42'],
                ['total' => '75.00'],
                $state,
                'msg_' . str_repeat('a', 32),
                1,
                ['terms'],
                'checkout:cart-42',
                $creationCorrelation
            )
        );

        self::assertNotSame($issued->token, $issued->record->tokenDigest);
        self::assertFalse($creationCorrelation->equals($consumptionCorrelation));
        self::assertTrue($service->validate($actor, $issued->token, $state)->valid);

        $other = new Actor(new ActorId('wp-user-43'), ActorType::Customer, 43);
        self::assertSame(
            'confirmation_not_found',
            $service->consume($other, $issued->token, $state, $consumptionCorrelation)->code
        );
        $wrongToken = substr($issued->token, 0, -1) . (str_ends_with($issued->token, 'A') ? 'B' : 'A');
        self::assertSame(
            'confirmation_not_found',
            $service->consume($actor, $wrongToken, $state, $consumptionCorrelation)->code
        );
        self::assertSame(
            'confirmation_state_changed',
            $service->consume(
                $actor,
                $issued->token,
                StateHash::fromPayload(['cart_version' => 9, 'total' => '76.00']),
                $consumptionCorrelation
            )->code
        );

        $consumed = $service->consume($actor, $issued->token, $state, $consumptionCorrelation);
        self::assertTrue($consumed->consumed);
        self::assertTrue($consumed->record?->correlationId->equals($consumptionCorrelation));
        self::assertSame(['cart' => 'cart-42'], $consumed->record?->resourceScope);
        self::assertSame('checkout:cart-42', $consumed->record?->idempotencyScope);
        self::assertFalse(
            $service->consume($actor, $issued->token, $state, CorrelationId::generate())->consumed,
            'A consumed confirmation must not replay under another request.'
        );

        $expiring = $service->create(
            $actor,
            new ConfirmationRequest(
                'order.place',
                ['cart' => 'cart-expiring'],
                ['total' => '25.00'],
                StateHash::fromPayload(['cart_version' => 1, 'total' => '25.00']),
                'msg_' . str_repeat('b', 32),
                1,
                [],
                'checkout:cart-expiring',
                CorrelationId::generate(),
                ttlSeconds: 30
            )
        );
        $clock->advance(31);
        self::assertSame(
            'confirmation_expired',
            $service->consume(
                $actor,
                $expiring->token,
                StateHash::fromPayload(['cart_version' => 1, 'total' => '25.00']),
                CorrelationId::generate()
            )->code
        );
    }
}
