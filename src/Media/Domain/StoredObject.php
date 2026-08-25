<?php

declare(strict_types=1);

namespace Veyra\Media\Domain;

final class StoredObject
{
    public function __construct(
        public readonly string $driver,
        public readonly string $key,
        public readonly int $byteSize,
        public readonly string $checksumSha256
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $driver) !== 1
            || $key === ''
            || strlen($key) > 255
            || $byteSize < 1
            || preg_match('/^[a-f0-9]{64}$/D', $checksumSha256) !== 1
        ) {
            throw new \InvalidArgumentException('Protected stored-object metadata is invalid.');
        }
    }
}
