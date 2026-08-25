<?php

declare(strict_types=1);

namespace Veyra\Media\Application;

// This bounded integrity verifier must hash and copy a protected stream without a plaintext disk buffer.
// WP_Filesystem has no equivalent streaming API.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Clock\SystemClock;
use Veyra\Shared\Domain\Clock;

/** Server-side access boundary; callers still need an authorized REST response. */
final class ProtectedAttachmentAccessService
{
    private const MAX_VERIFIED_BYTES = 10485760;

    private readonly Clock $clock;

    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly ProtectedStorage $storage,
        private readonly FoundationActorMapper $actors,
        ?Clock $clock = null
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    /** @return array{metadata:array<string,mixed>,stream:resource} */
    public function open(ToolContext $context, string $attachmentId): array
    {
        $attachment = $this->attachments->find(ActorScope::fromActor($this->actors->map($context)), $attachmentId);
        $now = $this->clock->now();
        if ($attachment === null
            || !$attachment->isUsable($now)
            || !hash_equals($context->conversationId, $attachment->conversationId)
        ) {
            throw new \RuntimeException('attachment_not_owned_or_unavailable');
        }

        return [
            'metadata' => $attachment->safeMetadata($now),
            'stream' => $this->verifiedStream(
                $this->storage->open($attachment->storageKey),
                $attachment->byteSize,
                $attachment->checksumSha256
            ),
        ];
    }

    /** @param resource $source @return resource */
    private function verifiedStream(mixed $source, int $expectedBytes, string $expectedChecksum)
    {
        if (!is_resource($source)
            || $expectedBytes < 1
            || $expectedBytes > self::MAX_VERIFIED_BYTES
            || preg_match('/^[a-f0-9]{64}$/D', $expectedChecksum) !== 1
        ) {
            if (is_resource($source)) {
                fclose($source);
            }
            throw new \RuntimeException('attachment_integrity_verification_failed');
        }

        // The verified copy can contain customer payment evidence. Keep the
        // already-bounded payload in process memory so PHP never spills
        // plaintext into the host's generic temporary directory.
        $verified = fopen('php://memory', 'w+b');
        if (!is_resource($verified)) {
            fclose($source);
            throw new \RuntimeException('attachment_integrity_verification_failed');
        }

        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, 8192);
                if (!is_string($chunk) || ($chunk === '' && !feof($source))) {
                    throw new \RuntimeException('attachment_integrity_verification_failed');
                }
                if ($chunk === '') {
                    continue;
                }
                $bytes += strlen($chunk);
                if ($bytes > $expectedBytes || $bytes > self::MAX_VERIFIED_BYTES) {
                    throw new \RuntimeException('attachment_integrity_verification_failed');
                }
                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($verified, substr($chunk, $offset));
                    if (!is_int($written) || $written < 1) {
                        throw new \RuntimeException('attachment_integrity_verification_failed');
                    }
                    $offset += $written;
                }
            }

            $checksum = hash_final($hash);
            if ($bytes !== $expectedBytes
                || !hash_equals($expectedChecksum, $checksum)
                || fseek($verified, 0) !== 0
            ) {
                throw new \RuntimeException('attachment_integrity_verification_failed');
            }
        } catch (\Throwable $error) {
            fclose($verified);
            throw $error instanceof \RuntimeException
                && $error->getMessage() === 'attachment_integrity_verification_failed'
                    ? $error
                    : new \RuntimeException('attachment_integrity_verification_failed', 0, $error);
        } finally {
            fclose($source);
        }

        return $verified;
    }
}
