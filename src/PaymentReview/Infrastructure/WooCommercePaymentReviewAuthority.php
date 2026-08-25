<?php

declare(strict_types=1);

namespace Veyra\PaymentReview\Infrastructure;

use Veyra\AI\Tool\ToolContext;
use Veyra\PaymentReview\Application\PaymentOrderResolution;
use Veyra\PaymentReview\Application\PaymentReviewAuthority;
use Veyra\PaymentReview\Domain\PaymentOrderSnapshot;

/** WooCommerce CRUD is the sole order/payment authority for this slice. */
final class WooCommercePaymentReviewAuthority implements PaymentReviewAuthority
{
    /** @param list<string> $eligibleGatewayIds @param list<string> $eligibleOrderStatuses */
    public function __construct(
        private readonly array $eligibleGatewayIds,
        private readonly array $eligibleOrderStatuses = ['pending', 'on-hold', 'failed']
    ) {
    }

    public function resolveOwnedEligibleOrder(ToolContext $context, int $orderId): PaymentOrderResolution
    {
        $resolved = $this->resolveOwned($context, $orderId);
        if (!$resolved->snapshot instanceof PaymentOrderSnapshot) {
            return $resolved;
        }
        if ($this->eligibleGatewayIds === []) {
            return PaymentOrderResolution::failed('payment_review_policy_unconfigured');
        }
        if (!in_array($resolved->snapshot->paymentMethodId, $this->eligibleGatewayIds, true)) {
            return PaymentOrderResolution::failed('payment_review_method_not_eligible');
        }
        if (!in_array($resolved->snapshot->orderStatus, $this->eligibleOrderStatuses, true)) {
            return PaymentOrderResolution::failed('payment_review_order_status_not_eligible');
        }
        if ($resolved->snapshot->paymentStatus === 'paid') {
            return PaymentOrderResolution::failed('payment_review_order_already_paid');
        }

        return $resolved;
    }

    public function currentOwnedOrder(ToolContext $context, int $orderId): PaymentOrderResolution
    {
        return $this->resolveOwned($context, $orderId);
    }

    private function resolveOwned(ToolContext $context, int $orderId): PaymentOrderResolution
    {
        if ($context->actorType !== 'customer' || $context->userId === null || $orderId < 1) {
            return PaymentOrderResolution::failed('payment_review_authentication_required');
        }
        if (!function_exists('wc_get_order')) {
            return PaymentOrderResolution::failed('woocommerce_order_authority_unavailable');
        }
        $order = wc_get_order($orderId);
        if (!is_object($order)
            || !method_exists($order, 'get_customer_id')
            || !method_exists($order, 'get_status')
            || !method_exists($order, 'get_payment_method')
        ) {
            return PaymentOrderResolution::failed('payment_review_order_not_owned_or_unavailable');
        }
        if ((int) $order->get_customer_id() !== $context->userId) {
            return PaymentOrderResolution::failed('payment_review_order_not_owned_or_unavailable');
        }
        try {
            $modified = method_exists($order, 'get_date_modified') ? $order->get_date_modified() : null;
            $modifiedIso = is_object($modified) && method_exists($modified, 'date')
                ? (string) $modified->date(DATE_ATOM)
                : '';
            $status = (string) $order->get_status();
            $method = (string) $order->get_payment_method();
            $total = (string) $order->get_total();
            $currency = strtoupper((string) $order->get_currency());
            $paid = method_exists($order, 'is_paid') && $order->is_paid();
            $version = hash('sha256', implode('|', [$orderId, $status, $method, $total, $currency, $modifiedIso]));
            return PaymentOrderResolution::success(new PaymentOrderSnapshot(
                $orderId,
                $status,
                $paid ? 'paid' : 'unpaid_or_unsettled',
                $method,
                (string) $order->get_payment_method_title(),
                $total,
                $currency,
                $version,
                gmdate(DATE_ATOM)
            ));
        } catch (\Throwable) {
            return PaymentOrderResolution::failed('payment_review_order_authority_read_failed');
        }
    }
}
