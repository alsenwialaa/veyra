<?php

declare(strict_types=1);

namespace Veyra\Media\Domain;

final class ValidatedFile
{
    /** @param array<string, int> $dimensions */
    public function __construct(
        public readonly string $path,
        public readonly string $mimeType,
        public readonly int $byteSize,
        public readonly string $checksumSha256,
        public readonly array $dimensions = []
    ) {
        if (!is_file($path)
            || $byteSize < 1
            || preg_match('/^[a-f0-9]{64}$/D', $checksumSha256) !== 1
            || preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/D', $mimeType) !== 1
        ) {
            throw new \InvalidArgumentException('Validated file metadata is invalid.');
        }
    }
}
