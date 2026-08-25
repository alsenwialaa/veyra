<?php

declare(strict_types=1);

namespace Veyra\Tests\Media;

use PHPUnit\Framework\TestCase;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Media\Domain\Attachment;
use Veyra\Media\Infrastructure\MagicByteFileValidator;
use Veyra\Shared\Domain\UtcInstant;

final class AttachmentAndValidationTest extends TestCase
{
    public function testCleanScanIsRequiredBeforeEvidenceUse(): void
    {
        $now = UtcInstant::fromDatabase('2026-08-24 10:00:00');
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
            $now,
            86400
        );
        self::assertFalse($attachment->isUsable($now));
        self::assertArrayNotHasKey('storage_key', $attachment->safeMetadata($now));

        $clean = $attachment->withScanResult('clean', $now->addSeconds(5));
        self::assertTrue($clean->isUsable($now->addSeconds(86399)));
        self::assertFalse($clean->isUsable($now->addSeconds(86400)));
        self::assertFalse($clean->safeMetadata($now->addSeconds(86400))['usable_as_evidence']);
        self::assertSame(2, $clean->version);
    }

    public function testMagicByteValidationRejectsClaimedMimeMismatch(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'veyra-pdf-');
        self::assertIsString($path);
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
        try {
            $validator = new MagicByteFileValidator();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('upload_mime_mismatch');
            $validator->validate($path, 'image/png');
        } finally {
            @unlink($path);
        }
    }

    public function testPdfWithActiveContentIsRejectedBeforeScanning(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'veyra-pdf-');
        self::assertIsString($path);
        file_put_contents($path, "%PDF-1.4\n/JavaScript 1 0 R\n%%EOF\n");
        try {
            $validator = new MagicByteFileValidator();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('upload_pdf_active_content_rejected');
            $validator->validate($path, 'application/pdf');
        } finally {
            @unlink($path);
        }
    }
}
