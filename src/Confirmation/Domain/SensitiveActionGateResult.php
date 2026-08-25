<?php

declare(strict_types=1);

namespace Veyra\Confirmation\Domain;

final class SensitiveActionGateResult
{
    private function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly ?SensitiveActionLease $lease,
        public readonly ?IdempotencyRecord $idempotency
    ) {
    }

    public static function ready(SensitiveActionLease $lease): self
    {
        return new self('ready', 'sensitive_action_ready', $lease, $lease->idempotency);
    }

    public static function fromIdempotency(IdempotencyDecision $decision): self
    {
        return new self($decision->status->value, $decision->code, null, $decision->record);
    }

    public static function blocked(string $code): self
    {
        return new self('blocked', $code, null, null);
    }

    public static function uncertain(string $code): self
    {
        return new self('uncertain', $code, null, null);
    }
}
