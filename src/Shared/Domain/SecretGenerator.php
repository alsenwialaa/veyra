<?php

declare(strict_types=1);

namespace Veyra\Shared\Domain;

final class SecretGenerator
{
    public static function generate(int $bytes = 32): string
    {
        if ($bytes < 24 || $bytes > 128) {
            throw new \InvalidArgumentException('Secret entropy must be between 24 and 128 bytes.');
        }

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}

