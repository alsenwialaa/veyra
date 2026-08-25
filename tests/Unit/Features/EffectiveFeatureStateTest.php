<?php

declare(strict_types=1);

namespace Veyra\Tests\Unit\Features;

use PHPUnit\Framework\TestCase;
use Veyra\Features\Application\EffectiveFeatureStateService;
use Veyra\Features\Application\RuntimeFeatureRegistry;
use Veyra\Features\Domain\FeatureKey;
use Veyra\Features\Domain\FeatureRegistry;
use Veyra\Features\Domain\FeatureState;
use Veyra\Features\Domain\ReleaseUnit;
use Veyra\Tests\Support\InMemoryFeatureConfigurationStore;

final class EffectiveFeatureStateTest extends TestCase
{
    public function testCanonicalCountsAndSafeOptionalGate(): void
    {
        $registry = FeatureRegistry::canonical();
        self::assertCount(20, $registry->byReleaseUnit(ReleaseUnit::ProductionCore));
        self::assertCount(17, $registry->byReleaseUnit(ReleaseUnit::OptionalModule));

        $runtime = new RuntimeFeatureRegistry();
        $runtime->register(new FeatureKey('commerce_chat_checkout'), FeatureState::On);
        $runtime->register(new FeatureKey('shopper_guest_checkout'), FeatureState::On);
        $states = new EffectiveFeatureStateService(
            $registry,
            new InMemoryFeatureConfigurationStore(
                [
                    'commerce_chat_checkout' => FeatureState::On,
                    'shopper_guest_checkout' => FeatureState::On,
                ]
            ),
            $runtime
        );

        self::assertSame(
            'optional_module_not_certified',
            $states->get(new FeatureKey('shopper_guest_checkout'))->reasonCode
        );
    }

    public function testUnimplementedFoundationalFeatureIsBlocked(): void
    {
        $registry = FeatureRegistry::canonical();
        $states = new EffectiveFeatureStateService(
            $registry,
            new InMemoryFeatureConfigurationStore(),
            new RuntimeFeatureRegistry()
        );

        self::assertSame(
            FeatureState::Blocked,
            $states->get(new FeatureKey('ai_semantic_orchestration'))->state
        );
    }
}

