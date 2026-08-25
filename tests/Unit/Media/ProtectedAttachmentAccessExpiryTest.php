<?php

declare(strict_types=1);

namespace Veyra\Tests\Unit\Media;

use PHPUnit\Framework\TestCase;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Media\Application\ProtectedAttachmentAccessService;
use Veyra\Media\Application\ProtectedStorage;
use Veyra\Media\Domain\Attachment;
use Veyra\Media\Domain\StoredObject;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Media\Support\InMemoryAttachmentRepository;
use Veyra\Tests\Support\FrozenClock;

final class ProtectedAttachmentAccessExpiryTest extends TestCase
{
    public function testExactExpiryDeniesBytesBeforeStorageOpen(): void
    {
        $createdAt = UtcInstant::fromDatabase('2026-08-24 10:00:00');
        $clock = new FrozenClock($createdAt);
        $repository = new InMemoryAttachmentRepository();
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
        self::assertTrue($repository->insert($attachment));

        $storage = new class implements ProtectedStorage {
            public bool $opened = false;

            public function store(string $sourcePath, string $mimeType, string $checksumSha256): StoredObject
            {
                throw new \LogicException('Storage writes are not expected in this fixture.');
            }

            public function open(string $key)
            {
                $this->opened = true;
                return fopen('php://temp', 'w+b');
            }

            public function delete(string $key): bool
            {
                return true;
            }
        };
        $service = new ProtectedAttachmentAccessService(
            $repository,
            $storage,
            new FoundationActorMapper(),
            $clock
        );
        $clock->advance(3600);

        try {
            $service->open(new ToolContext(
                'customer',
                'wp-user-42',
                42,
                null,
                '11111111-1111-4111-8111-111111111111',
                [],
                ['ai_multimodal_understanding' => 'On'],
                'en_US',
                '22222222-2222-4222-8222-222222222222'
            ), $attachment->id);
            self::fail('Expired attachment bytes were opened.');
        } catch (\RuntimeException $error) {
            self::assertSame('attachment_not_owned_or_unavailable', $error->getMessage());
        }

        self::assertFalse($storage->opened);
    }

    public function testStoredBytesMustMatchPersistedSizeAndChecksum(): void
    {
        [$service, $attachment] = $this->accessFixture('persisted evidence', 'tampered evidence');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('attachment_integrity_verification_failed');
        $service->open($this->context(), $attachment->id);
    }

    public function testVerifiedBytesAreRewoundForControlledDelivery(): void
    {
        [$service, $attachment] = $this->accessFixture('persisted evidence', 'persisted evidence');

        $opened = $service->open($this->context(), $attachment->id);
        try {
            self::assertSame('persisted evidence', stream_get_contents($opened['stream']));
        } finally {
            fclose($opened['stream']);
        }
    }

    /** @return array{ProtectedAttachmentAccessService,Attachment} */
    private function accessFixture(string $recorded, string $stored): array
    {
        $createdAt = UtcInstant::fromDatabase('2026-08-24 10:00:00');
        $clock = new FrozenClock($createdAt);
        $repository = new InMemoryAttachmentRepository();
        $attachment = Attachment::quarantined(
            new ActorScope('customer', 'wp-user-42'),
            '11111111-1111-4111-8111-111111111111',
            null,
            'payment_evidence',
            'private_fs',
            '2026/08/' . str_repeat('c', 48) . '.png',
            'image/png',
            strlen($recorded),
            hash('sha256', $recorded),
            $createdAt,
            3600
        )->withScanResult('clean', $createdAt);
        self::assertTrue($repository->insert($attachment));
        $storage = new class($stored) implements ProtectedStorage {
            public function __construct(private readonly string $stored)
            {
            }

            public function store(string $sourcePath, string $mimeType, string $checksumSha256): StoredObject
            {
                throw new \LogicException('Storage writes are not expected in this fixture.');
            }

            public function open(string $key)
            {
                unset($key);
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $this->stored);
                rewind($stream);
                return $stream;
            }

            public function delete(string $key): bool
            {
                return true;
            }
        };

        return [
            new ProtectedAttachmentAccessService($repository, $storage, new FoundationActorMapper(), $clock),
            $attachment,
        ];
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
            ['ai_multimodal_understanding' => 'On'],
            'en_US',
            '22222222-2222-4222-8222-222222222222'
        );
    }
}
