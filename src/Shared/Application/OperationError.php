<?php

declare(strict_types=1);

namespace Veyra\Shared\Application;

final class OperationError implements \JsonSerializable
{
    /** @param array<string, scalar|null> $safeDetails */
    public function __construct(
        public readonly string $code,
        public readonly string $safeMessageKey,
        public readonly array $safeDetails = []
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,95}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('Operation error code is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'message_key' => $this->safeMessageKey,
            'details' => $this->safeDetails,
        ];
    }
}

