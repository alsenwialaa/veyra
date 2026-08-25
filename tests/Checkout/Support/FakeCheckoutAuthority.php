<?php

declare(strict_types=1);

namespace Veyra\Tests\Checkout\Support;

use Veyra\Checkout\Application\CheckoutAuthority;
use Veyra\Checkout\Domain\CheckoutState;

final class FakeCheckoutAuthority implements CheckoutAuthority
{
    /** @var list<string>|null */
    private ?array $shippingMethods = null;

    private ?string $paymentMethodId = null;

    private bool $restoreSucceeds = true;

    private int $selectionWriteCount = 0;

    private int $restoreAttemptCount = 0;

    private ?int $boundWordPressUserId = null;

    /** @var list<string> */
    private array $unsupportedRequiredFields = [];

    public function __construct(private readonly string $cartHash)
    {
    }

    /** @param list<string>|null $shippingMethods */
    public function seedSelections(?array $shippingMethods, ?string $paymentMethodId): void
    {
        $this->shippingMethods = $shippingMethods;
        $this->paymentMethodId = $paymentMethodId;
    }

    public function failSelectionRestore(): void
    {
        $this->restoreSucceeds = false;
    }

    public function bindActor(int $wordpressUserId): void
    {
        $this->boundWordPressUserId = $wordpressUserId;
    }

    /** @param list<string> $fieldIds */
    public function requireUnsupportedFields(array $fieldIds): void
    {
        $this->unsupportedRequiredFields = $fieldIds;
    }

    /** @return array{shipping_methods: list<string>|null, payment_method_id: string|null} */
    public function currentSelections(): array
    {
        return $this->selectionSnapshot();
    }

    public function selectionWriteCount(): int
    {
        return $this->selectionWriteCount;
    }

    public function restoreAttemptCount(): int
    {
        return $this->restoreAttemptCount;
    }

    public function available(): bool
    {
        return true;
    }

    public function actorMatches(int $wordpressUserId): bool
    {
        return $wordpressUserId > 0
            && ($this->boundWordPressUserId === null || $this->boundWordPressUserId === $wordpressUserId);
    }

    public function cart(): array
    {
        return [
            'ok' => true,
            'hash' => $this->cartHash,
            'empty' => false,
            'needs_shipping' => true,
            'item_count' => 1,
            'lines' => [['line_id' => 'line-1', 'product_id' => 7, 'variation_id' => 0, 'quantity' => 1]],
            'coupons' => [],
            'currency' => 'USD',
            'totals' => ['total' => '12.00'],
        ];
    }

    public function classifyFulfillment(): array
    {
        return ['ok' => true, 'classification' => ['classification' => 'shippable', 'requires_shipping' => true, 'cart_hash' => $this->cartHash]];
    }

    public function fulfillmentModes(CheckoutState $state): array
    {
        return [
            'ok' => true,
            'modes' => [
                ['id' => 'delivery', 'label' => 'Delivery', 'currently_eligible' => true],
                ['id' => 'pickup', 'label' => 'Pickup', 'currently_eligible' => true],
            ],
            'classification' => ['classification' => 'shippable', 'requires_shipping' => true],
        ];
    }

    public function requiredFields(CheckoutState $state): array
    {
        return [
            'ok' => true,
            'fields' => array_map(static fn (string $field): array => [
                'field_id' => $field,
                'required' => true,
                'has_value' => false,
            ], $this->unsupportedRequiredFields),
            'missing_required_fields' => $this->unsupportedRequiredFields,
            'unsupported_required_fields' => $this->unsupportedRequiredFields,
            'chat_checkout_supported' => $this->unsupportedRequiredFields === [],
            'standard_checkout_handoff_required' => $this->unsupportedRequiredFields !== [],
            'complete' => $this->unsupportedRequiredFields === [],
        ];
    }

    public function shippingPackages(CheckoutState $state): array
    {
        return ['ok' => true, 'shipping_required' => true, 'packages' => [], 'package_fingerprint' => str_repeat('e', 64), 'observed_at' => '2026-08-24T10:00:00Z'];
    }

    public function selectShippingRates(CheckoutState $state, array $selections): array
    {
        $this->shippingMethods = array_values(array_map(
            static fn (array $selection): string => (string) ($selection['rate_id'] ?? ''),
            $selections
        ));
        ++$this->selectionWriteCount;

        return ['ok' => true, 'selection' => $selections, 'package_fingerprint' => str_repeat('e', 64), 'selected_at' => '2026-08-24T10:00:00Z'];
    }

    public function paymentMethods(CheckoutState $state): array
    {
        return ['ok' => true, 'methods' => [['payment_method_id' => 'cod', 'title' => 'Cash']], 'selected_method_id' => $state->paymentMethodId];
    }

    public function selectPaymentMethod(CheckoutState $state, string $paymentMethodId): array
    {
        if ($paymentMethodId !== 'cod') {
            return ['ok' => false, 'code' => 'payment_method_not_currently_eligible'];
        }
        $this->paymentMethodId = $paymentMethodId;
        ++$this->selectionWriteCount;

        return ['ok' => true, 'payment_method' => ['payment_method_id' => 'cod', 'title' => 'Cash']];
    }

    public function selectionSnapshot(): array
    {
        return [
            'shipping_methods' => $this->shippingMethods,
            'payment_method_id' => $this->paymentMethodId,
        ];
    }

    public function restoreSelectionSnapshot(array $snapshot): bool
    {
        ++$this->restoreAttemptCount;
        if (!$this->restoreSucceeds) {
            return false;
        }
        $shipping = $snapshot['shipping_methods'] ?? null;
        $payment = $snapshot['payment_method_id'] ?? null;
        if ($shipping !== null && (!is_array($shipping) || !array_is_list($shipping))) {
            return false;
        }
        if ($payment !== null && !is_string($payment)) {
            return false;
        }
        $this->shippingMethods = $shipping;
        $this->paymentMethodId = $payment;

        return $this->selectionSnapshot() === $snapshot;
    }

    public function calculate(CheckoutState $state): array
    {
        return [
            'ok' => true,
            'complete' => true,
            'missing_required_fields' => [],
            'cart' => $this->cart(),
            'classification' => ['classification' => 'shippable', 'requires_shipping' => true],
            'fulfillment_mode' => $state->fulfillmentMode,
            'shipping' => ['selection' => []],
            'payment_method' => ['payment_method_id' => $state->paymentMethodId ?? 'cod', 'title' => 'Cash'],
            'totals' => ['total' => '12.00'],
            'currency' => 'USD',
            'calculated_at' => '2026-08-24T10:00:00Z',
            'woocommerce_authoritative' => true,
        ];
    }
}
