<?php

declare(strict_types=1);

namespace Veyra\PaymentReview\Application;

use Veyra\AI\Tool\ToolContext;
use Veyra\PaymentReview\Domain\PaymentOrderSnapshot;

interface PaymentReviewAuthority
{
    public function resolveOwnedEligibleOrder(ToolContext $context, int $orderId): PaymentOrderResolution;

    public function currentOwnedOrder(ToolContext $context, int $orderId): PaymentOrderResolution;
}
