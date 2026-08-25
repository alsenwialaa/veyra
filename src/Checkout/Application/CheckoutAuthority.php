<?php

declare(strict_types=1);

namespace Veyra\Checkout\Application;

use Veyra\Checkout\Domain\CheckoutState;

/**
 * Current commerce facts and calculations. Implementations must use supported
 * WooCommerce APIs or an explicitly approved typed adapter.
 */
interface CheckoutAuthority
{
    public function available(): bool;

    /**
     * Prove that the effective WooCommerce customer/session belongs to the
     * server-resolved WordPress customer before any cart or checkout read or
     * write is attributed to that actor.
     */
    public function actorMatches(int $wordpressUserId): bool;

    /** @return array<string, mixed> */
    public function cart(): array;

    /** @return array<string, mixed> */
    public function classifyFulfillment(): array;

    /** @return array<string, mixed> */
    public function fulfillmentModes(CheckoutState $state): array;

    /** @return array<string, mixed> */
    public function requiredFields(CheckoutState $state): array;

    /** @return array<string, mixed> */
    public function shippingPackages(CheckoutState $state): array;

    /**
     * @param list<array{package_id: string, rate_id: string}> $selections
     * @return array<string, mixed>
     */
    public function selectShippingRates(CheckoutState $state, array $selections): array;

    /** @return array<string, mixed> */
    public function paymentMethods(CheckoutState $state): array;

    /** @return array<string, mixed> */
    public function selectPaymentMethod(CheckoutState $state, string $paymentMethodId): array;

    /**
     * Capture the effective WooCommerce-session checkout selections before a
     * write that must be coordinated with Veyra's checkout-state CAS.
     *
     * @return array{shipping_methods: list<string>|null, payment_method_id: string|null}
     */
    public function selectionSnapshot(): array;

    /**
     * Restore and verify a previously captured effective selection snapshot.
     * A false result means the authority state is not known to match it and the
     * caller must preserve reconciliation evidence instead of claiming failure.
     *
     * @param array{shipping_methods: list<string>|null, payment_method_id: string|null} $snapshot
     */
    public function restoreSelectionSnapshot(array $snapshot): bool;

    /** @return array<string, mixed> */
    public function calculate(CheckoutState $state): array;
}
