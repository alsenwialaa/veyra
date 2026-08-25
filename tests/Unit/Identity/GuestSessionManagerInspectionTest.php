<?php

declare(strict_types=1);

namespace Veyra\Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;
use Veyra\Identity\Application\GuestSessionManager;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Support\FrozenClock;
use Veyra\Tests\Support\InMemoryGuestSessionRepository;

final class GuestSessionManagerInspectionTest extends TestCase
{
    public function testInspectionAuthenticatesWithoutTouchingPersistentSession(): void
    {
        $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
        $repository = new InMemoryGuestSessionRepository();
        $manager = new GuestSessionManager(
            $repository,
            new SecretDigester(str_repeat('g', 32)),
            $clock
        );
        $created = $manager->create();
        $token = (string) $created['raw_token'];
        $before = $repository->current();
        self::assertNotNull($before);
        self::assertSame(1, $before->version);

        $clock->advance(30);
        $inspected = $manager->inspectFromRawToken($token);

        self::assertNotNull($inspected);
        self::assertSame(1, $inspected->session->version);
        self::assertSame(1, $repository->current()?->version);
        self::assertSame('2026-08-24 10:00:00', $repository->current()?->lastSeenAt->toDatabase());

        $touched = $manager->resolveFromRawToken($token);
        self::assertNotNull($touched);
        self::assertSame(2, $repository->current()?->version);
        self::assertSame('2026-08-24 10:00:30', $repository->current()?->lastSeenAt->toDatabase());
    }
}
