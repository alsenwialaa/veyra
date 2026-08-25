<?php

declare(strict_types=1);

namespace Veyra\Shared\Domain;

final class CanonicalJson
{
    /** @param mixed $value */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /** @param mixed $value @return mixed */
    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return self::normalize($value->jsonSerialize());
        }

        if (is_object($value)) {
            throw new \InvalidArgumentException('Canonical payloads must not contain arbitrary objects.');
        }

        if (!is_array($value)) {
            if (is_resource($value)) {
                throw new \InvalidArgumentException('Canonical payloads must not contain resources.');
            }

            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Canonical objects must use string keys.');
            }

            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}

