<?php

declare(strict_types=1);

namespace Veyra\PaymentReview\Domain;

final class PaymentOrderSnapshot implements \JsonSerializable
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderStatus,
        public readonly string $paymentStatus,
        public readonly string $paymentMethodId,
        public readonly string $paymentMethodTitle,
        public readonly string $orderTotal,
        public readonly string $currency,
        public readonly string $orderVersion,
        public readonly string $observedAt
    ) {
        if ($orderId < 1
            || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $orderStatus) !== 1
            || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $paymentStatus) !== 1
            || $paymentMethodId === ''
            || strlen($paymentMethodId) > 191
            || !preg_match('/^\d+(?:\.\d{1,8})?$/D', $orderTotal)
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || $orderVersion === ''
        ) {
            throw new \InvalidArgumentException('Payment order snapshot is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'order_id' => $this->orderId,
            'order_status' => $this->orderStatus,
            'payment_status' => $this->paymentStatus,
            'payment_method_id' => $this->paymentMethodId,
            'payment_method_title' => $this->paymentMethodTitle,
            'order_total' => $this->orderTotal,
            'currency' => $this->currency,
            'order_version' => $this->orderVersion,
            'observed_at' => $this->observedAt,
        ];
    }
}
