<?php

declare(strict_types=1);

namespace Veyra\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Media\Domain\Attachment;
use Veyra\Shared\Domain\UtcInstant;

final class AttachmentExpiryTest extends TestCase
{
    public function testCleanAttachmentStopsBeingUsableAtExactExpiry(): void
    {
        $createdAt = UtcInstant::fromDatabase('2026-08-24 10:00:00');
        $attachment = Attachment::quarantined(
            new ActorScope('customer', 'wp-user-42'),
            '11111111-1111-4111-8111-111111111111',
            null,
            'payment_evidence',
            'private_fs',
            '2026/08/' . str_repeat('a', 48) . '.png',
            'image/png',
            128,
            str_repeat('b', 64),
            $createdAt,
            3600
        )->withScanResult('clean', $createdAt);

        self::assertTrue($attachment->isUsable($createdAt->addSeconds(3599)));
        self::assertFalse($attachment->isUsable($createdAt->addSeconds(3600)));
        self::assertFalse($attachment->safeMetadata($createdAt->addSeconds(3600))['usable_as_evidence']);
    }
}
