<?php

declare(strict_types=1);

namespace Veyra\Tests\Unit\Shared;

use PHPUnit\Framework\TestCase;
use Veyra\Shared\Application\OperationResult;
use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\StateHash;

final class CanonicalJsonTest extends TestCase
{
    public function testObjectKeyOrderDoesNotChangeStateHash(): void
    {
        self::assertTrue(
            StateHash::fromPayload(['b' => 2, 'a' => ['y' => 2, 'x' => 1]])->equals(
                StateHash::fromPayload(['a' => ['x' => 1, 'y' => 2], 'b' => 2])
            )
        );
        self::assertSame('{"a":1,"b":2}', CanonicalJson::encode(['b' => 2, 'a' => 1]));
    }

    public function testOperationResultUsesStablePublicEnvelope(): void
    {
        $result = OperationResult::succeeded(
            'foundation_ok',
            ['ready' => true],
            CorrelationId::generate(),
            ['feature:foundation']
        );
        $payload = $result->jsonSerialize();

        self::assertSame('1.0.0', $payload['schema_version']);
        self::assertSame('succeeded', $payload['status']);
        self::assertSame('not_applicable', $payload['retry_safety']);
        self::assertSame(['feature:foundation'], $payload['changed_resources']);
    }
}

