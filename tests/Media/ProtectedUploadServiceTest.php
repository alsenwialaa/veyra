<?php

declare(strict_types=1);

namespace Veyra\Tests\Media;

use PHPUnit\Framework\TestCase;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Media\Application\FileValidator;
use Veyra\Media\Application\ImageReencoder;
use Veyra\Media\Application\ProtectedStorage;
use Veyra\Media\Application\ProtectedUploadService;
use Veyra\Media\Domain\StoredObject;
use Veyra\Media\Domain\ValidatedFile;
use Veyra\Media\Infrastructure\FailClosedMalwareScanner;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Media\Support\InMemoryAttachmentRepository;
use Veyra\Tests\Media\Support\OwnedConversationStore;
use Veyra\Tests\Support\FrozenClock;
use Veyra\Tests\Support\InMemoryIdempotencyRepository;
use Veyra\Tests\PaymentReview\Support\InMemoryLockRepository;

final class ProtectedUploadServiceTest extends TestCase
{
    public function testUnavailableScannerKeepsEvidenceQuarantinedAndReplayNeverSucceeds(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'veyra-upload-');
        self::assertIsString($path);
        file_put_contents($path, 'bounded evidence fixture');
        $checksum = hash_file('sha256', $path);
        self::assertIsString($checksum);
        $validator = new class($path, $checksum) implements FileValidator {
            public function __construct(private readonly string $path, private readonly string $checksum) {}
            public function validate(string $path, string $claimedMimeType): ValidatedFile
            {
                return new ValidatedFile($this->path, 'application/pdf', (int) filesize($this->path), $this->checksum);
            }
        };
        $images = new class implements ImageReencoder {
            public function reencode(string $sourcePath, string $mimeType): string
            {
                throw new \RuntimeException('Images are not expected in this fixture.');
            }
        };
        $storage = new class implements ProtectedStorage {
            /** @var array<string, string> */
            private array $objects = [];
            public function store(string $sourcePath, string $mimeType, string $checksumSha256): StoredObject
            {
                $key = '2026/08/' . str_repeat('f', 48) . '.pdf';
                $this->objects[$key] = (string) file_get_contents($sourcePath);
                return new StoredObject('private_fs', $key, strlen($this->objects[$key]), $checksumSha256);
            }
            public function open(string $key)
            {
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $this->objects[$key] ?? '');
                rewind($stream);
                return $stream;
            }
            public function delete(string $key): bool
            {
                unset($this->objects[$key]);
                return true;
            }
        };
        $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
        $service = new ProtectedUploadService(
            $validator,
            $images,
            new FailClosedMalwareScanner(),
            $storage,
            new InMemoryAttachmentRepository(),
            new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('k', 32)), $clock),
            new FoundationActorMapper(),
            $clock,
            new OwnedConversationStore(
                '11111111-1111-4111-8111-111111111111',
                'customer',
                'wp-user-42'
            ),
            new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('l', 32)), $clock),
            2592000
        );
        try {
            $first = $service->accept($this->context(), $path, 'application/pdf', 'payment_evidence', 'upload-request-0001');
            $second = $service->accept($this->context(), $path, 'application/pdf', 'payment_evidence', 'upload-request-0001');
            self::assertSame('blocked', $first->status);
            self::assertSame('unavailable', $first->attachment?->scanStatus);
            self::assertFalse($first->attachment?->isUsable($clock->now()) ?? true);
            self::assertNotSame('succeeded', $second->status);
        } finally {
            @unlink($path);
        }
    }

    private function context(): ToolContext
    {
        return new ToolContext(
            'customer',
            'wp-user-42',
            42,
            null,
            '11111111-1111-4111-8111-111111111111',
            [],
            ['payment_offline_review' => 'On'],
            'en_US',
            '22222222-2222-4222-8222-222222222222'
        );
    }
}
