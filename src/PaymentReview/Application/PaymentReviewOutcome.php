<?php

declare(strict_types=1);

namespace Veyra\PaymentReview\Application;

use Veyra\PaymentReview\Domain\PaymentReview;

final class PaymentReviewOutcome
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly ?PaymentReview $review,
        public readonly array $data = [],
        public readonly bool $retrySafe = false
    ) {
        if (!in_array($status, ['succeeded', 'failed', 'blocked', 'stale', 'uncertain'], true)
            || preg_match('/^[a-z][a-z0-9_]{1,95}$/D', $code) !== 1
        ) {
            throw new \InvalidArgumentException('Payment review outcome is invalid.');
        }
    }
}
