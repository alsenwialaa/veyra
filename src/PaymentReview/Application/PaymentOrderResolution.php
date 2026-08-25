<?php

declare(strict_types=1);

namespace Veyra\PaymentReview\Application;

use Veyra\PaymentReview\Domain\PaymentOrderSnapshot;

final class PaymentOrderResolution
{
    public function __construct(
        public readonly string $code,
        public readonly ?PaymentOrderSnapshot $snapshot
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{1,95}$/D', $code) !== 1
            || (($code === 'ok') !== ($snapshot instanceof PaymentOrderSnapshot))
        ) {
            throw new \InvalidArgumentException('Payment order resolution is invalid.');
        }
    }

    public static function success(PaymentOrderSnapshot $snapshot): self
    {
        return new self('ok', $snapshot);
    }

    public static function failed(string $code): self
    {
        return new self($code, null);
    }
}
