<?php

declare(strict_types=1);

namespace Veyra\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Veyra\Bootstrap\EnvironmentSnapshot;
use Veyra\Bootstrap\RuntimeCompatibility;

final class RuntimeCompatibilityTest extends TestCase
{
    public function testMissingWooBlocksCommerceWithoutBreakingFoundation(): void
    {
        $report = (new RuntimeCompatibility())->evaluate(
            new EnvironmentSnapshot('8.2.0', '6.9.0', null, true)
        );

        self::assertTrue($report->foundationReady());
        self::assertFalse($report->commerceReady());
        self::assertContains('veyra_woocommerce_missing', $report->codes());
    }

    public function testNewerWooHasNoArbitraryUpperBound(): void
    {
        $report = (new RuntimeCompatibility())->evaluate(
            new EnvironmentSnapshot('8.3.0', '7.0.0', '99.0.0', true)
        );

        self::assertTrue($report->commerceReady());
    }
}

