<?php

declare(strict_types=1);

namespace Veyra\Tests\Unit\Identity;

use PHPUnit\Framework\TestCase;
use Veyra\Identity\Application\CapabilityPolicy;
use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\ActorId;
use Veyra\Identity\Domain\ActorType;
use Veyra\Identity\Domain\Capability;
use Veyra\Identity\Domain\CapabilityRegistry;

final class CapabilityRegistryTest extends TestCase
{
    public function testAllCanonicalCapabilitiesRemainSeparate(): void
    {
        self::assertCount(28, CapabilityRegistry::names());
        self::assertCount(28, array_unique(CapabilityRegistry::names()));

        $actor = new Actor(
            new ActorId('wp-user-8'),
            ActorType::Staff,
            8,
            null,
            ['view_veyra_conversations']
        );
        $policy = new CapabilityPolicy();

        self::assertTrue($policy->allows($actor, new Capability('view_veyra_conversations')));
        self::assertFalse($policy->allows($actor, new Capability('send_veyra_support_messages')));
    }
}

