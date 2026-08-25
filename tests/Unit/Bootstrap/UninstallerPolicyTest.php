<?php

declare(strict_types=1);

namespace Veyra\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use Veyra\Bootstrap\Uninstaller;

final class UninstallerPolicyTest extends TestCase
{
    public function testPersistedWordPressBooleanScalarEnablesExplicitDeletion(): void
    {
        self::assertTrue(Uninstaller::deletionEnabled(true));
        self::assertTrue(Uninstaller::deletionEnabled(1));
        self::assertTrue(Uninstaller::deletionEnabled('1'));
    }

    public function testAmbiguousOrDisabledValuesPreserveData(): void
    {
        foreach ([false, 0, '0', 'true', 'yes', null, [], new \stdClass()] as $value) {
            self::assertFalse(Uninstaller::deletionEnabled($value));
        }
    }
}
