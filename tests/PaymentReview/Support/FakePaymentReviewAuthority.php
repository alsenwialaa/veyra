<?php

declare(strict_types=1);

namespace Veyra\Tests\PaymentReview\Support;

use Veyra\AI\Tool\ToolContext;
use Veyra\PaymentReview\Application\PaymentOrderResolution;
use Veyra\PaymentReview\Application\PaymentReviewAuthority;
use Veyra\PaymentReview\Domain\PaymentOrderSnapshot;

final class FakePaymentReviewAuthority implements PaymentReviewAuthority
{
    public function __construct(private readonly string $failureCode = '')
    {
    }

    public function resolveOwnedEligibleOrder(ToolContext $context, int $orderId): PaymentOrderResolution
    {
        if ($this->failureCode !== '') {
            return PaymentOrderResolution::failed($this->failureCode);
        }
        return $this->snapshot($context, $orderId);
    }

    public function currentOwnedOrder(ToolContext $context, int $orderId): PaymentOrderResolution
    {
        return $this->snapshot($context, $orderId);
    }

    private function snapshot(ToolContext $context, int $orderId): PaymentOrderResolution
    {
        if ($context->actorType !== 'customer' || $context->userId !== 42 || $orderId !== 7001) {
            return PaymentOrderResolution::failed('payment_review_order_not_owned_or_unavailable');
        }
        return PaymentOrderResolution::success(new PaymentOrderSnapshot(
            $orderId,
            'on-hold',
            'unpaid_or_unsettled',
            'bacs',
            'Bank transfer',
            '100.00',
            'USD',
            str_repeat('a', 64),
            '2026-08-24T10:00:00Z'
        ));
    }
}
