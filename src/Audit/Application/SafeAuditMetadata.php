<?php

declare(strict_types=1);

namespace Veyra\Audit\Application;

final class SafeAuditMetadata
{
    /**
     * Audit metadata is an operational allowlist, not a second copy of
     * customer input. In addition to credentials and payment-card data,
     * reject bank-account identifiers explicitly: those values are never
     * legitimate audit payloads even when a caller gives them an innocuous
     * scalar value.
     */
    private const FORBIDDEN_KEY = '/(?:secret|password|passwd|token|nonce|card|cvv|cvc|otp|credential|authorization|cookie|raw_content|transcript|reasoning|prompt|(?:^|_)(?:iban|swift(?:_code)?|bic(?:_code)?|pin(?:_code)?|account_(?:number|no)|bank(?:ing)?_(?:account|details?)|routing_(?:number|no)|sort_code|payment_account)(?:_|$))/i';

    /** @param array<string, mixed> $metadata @return array<string, scalar|array|null> */
    public static function sanitize(array $metadata): array
    {
        return self::sanitizeLevel($metadata, 0);
    }

    /** @param array<string, mixed> $metadata @return array<string, scalar|array|null> */
    private static function sanitizeLevel(array $metadata, int $depth): array
    {
        if ($depth > 3) {
            return [];
        }

        $safe = [];

        foreach (array_slice($metadata, 0, 32, true) as $key => $value) {
            if (!is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
                || preg_match(self::FORBIDDEN_KEY, $key) === 1
            ) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = self::sanitizeLevel($value, $depth + 1);
                continue;
            }

            if (is_string($value)) {
                $safe[$key] = function_exists('mb_substr') ? mb_substr($value, 0, 256) : substr($value, 0, 256);
                continue;
            }

            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
