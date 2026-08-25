<?php

declare(strict_types=1);

namespace Veyra\Shared\Domain;

final class SecretDigester
{
    public function __construct(private readonly string $key)
    {
        if (strlen($key) < 16) {
            throw new \InvalidArgumentException('Digest key must contain at least 16 bytes.');
        }
    }

    public function digest(string $secret, string $purpose): string
    {
        if ($secret === '' || $purpose === '') {
            throw new \InvalidArgumentException('Secret and digest purpose are required.');
        }

        return hash_hmac('sha256', $purpose . "\0" . $secret, $this->key);
    }
}

