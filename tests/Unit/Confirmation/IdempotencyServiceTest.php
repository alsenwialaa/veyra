<?php

declare(strict_types=1);

namespace Veyra\Tests\Unit\Confirmation;

use PHPUnit\Framework\TestCase;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Domain\IdempotencyDecisionStatus;
use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\ActorId;
use Veyra\Identity\Domain\ActorType;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Support\FrozenClock;
use Veyra\Tests\Support\InMemoryIdempotencyRepository;

final class IdempotencyServiceTest extends TestCase
{
    public function testDuplicateAndConflictSemantics(): void
    {
        $repository = new InMemoryIdempotencyRepository();
        $service = new IdempotencyService(
            $repository,
            new SecretDigester(str_repeat('i', 32)),
            new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'))
        );
        $actor = new Actor(new ActorId('wp-user-9'), ActorType::Customer, 9);
        $correlation = CorrelationId::generate();
        $first = $service->begin($actor, 'cart.add', 'request-key-0001', ['product' => 5], 'cart:9', $correlation);

        self::assertSame(IdempotencyDecisionStatus::Claimed, $first->status);
        self::assertSame(
            IdempotencyDecisionStatus::InProgress,
            $service->begin($actor, 'cart.add', 'request-key-0001', ['product' => 5], 'cart:9', $correlation)->status
        );
        self::assertTrue($service->complete($first->record, 'cart_item_added', ['line' => 'x']));
        self::assertSame(
            IdempotencyDecisionStatus::Replay,
            $service->begin($actor, 'cart.add', 'request-key-0001', ['product' => 5], 'cart:9', $correlation)->status
        );
        self::assertSame(
            IdempotencyDecisionStatus::Conflict,
            $service->begin($actor, 'cart.add', 'request-key-0001', ['product' => 6], 'cart:9', $correlation)->status
        );
    }
}

