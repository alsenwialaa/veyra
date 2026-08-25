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

    public function testAbbreviatedStableVersionsMeetEquivalentMinimums(): void
    {
        $report = (new RuntimeCompatibility())->evaluate(
            new EnvironmentSnapshot('8.1', '6.5', '8.5', true)
        );

        self::assertTrue($report->foundationReady());
        self::assertTrue($report->commerceReady());
        self::assertSame([], $report->codes());
    }

    public function testAbbreviatedPrereleasesRemainBelowStableMinimums(): void
    {
        $phpReport = (new RuntimeCompatibility())->evaluate(
            new EnvironmentSnapshot('8.1-RC1', '6.5', '8.5', true)
        );
        $wordpressReport = (new RuntimeCompatibility())->evaluate(
            new EnvironmentSnapshot('8.1', '6.5-RC1', '8.5', true)
        );
        $woocommerceReport = (new RuntimeCompatibility())->evaluate(
            new EnvironmentSnapshot('8.1', '6.5', '8.5-RC1', true)
        );

        self::assertContains('veyra_php_too_old', $phpReport->codes());
        self::assertContains('veyra_wordpress_too_old', $wordpressReport->codes());
        self::assertContains('veyra_woocommerce_too_old', $woocommerceReport->codes());
    }
}
