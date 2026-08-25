<?php

declare(strict_types=1);

namespace Veyra\Http;

/** Customer-safe, versioned REST boundary shared by the customer and admin clients. */
final class RestEnvelope
{
    private const STATUSES = ['succeeded', 'failed', 'partial', 'uncertain', 'blocked', 'stale'];
    private const RETRY = ['safe_no_side_effect', 'reconcile_before_retry', 'never_retry'];

    /** @param array<string, mixed>|list<mixed>|null $value @param list<string> $warnings */
    public static function make(
        string $status,
        string $code,
        mixed $value,
        string $correlationId,
        string $retrySafety,
        array $warnings = []
    ): array {
        if (!in_array($status, self::STATUSES, true)
            || !in_array($retrySafety, self::RETRY, true)
            || preg_match('/^[a-z][a-z0-9_]{2,95}$/D', $code) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid REST operation envelope.');
        }

        return [
            'schema_version' => 'veyra.operation.v1',
            'status' => $status,
            'code' => $code,
            'value' => $value,
            'warnings' => array_values(array_filter($warnings, 'is_string')),
            'retry_safety' => $retrySafety,
            'correlation_id' => $correlationId,
        ];
    }

    public static function succeeded(string $code, mixed $value, string $correlationId): array
    {
        return self::make('succeeded', $code, $value, $correlationId, 'safe_no_side_effect');
    }

    /** @param list<string> $warnings */
    public static function partial(string $code, mixed $value, string $correlationId, array $warnings): array
    {
        return self::make('partial', $code, $value, $correlationId, 'reconcile_before_retry', $warnings);
    }

    public static function blocked(string $code, string $message, string $correlationId, string $retrySafety = 'never_retry'): array
    {
        return self::make('blocked', $code, ['message' => $message], $correlationId, $retrySafety);
    }

    public static function failed(string $code, string $message, string $correlationId, string $retrySafety = 'reconcile_before_retry'): array
    {
        return self::make('failed', $code, ['message' => $message], $correlationId, $retrySafety);
    }

    public static function uncertain(string $code, string $message, string $correlationId): array
    {
        return self::make('uncertain', $code, ['message' => $message], $correlationId, 'reconcile_before_retry');
    }
}
