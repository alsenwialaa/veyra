<?php

declare(strict_types=1);

namespace Veyra\Checkout\Infrastructure;

use Veyra\Checkout\Application\CheckoutAuthority;
use Veyra\Checkout\Domain\CheckoutState;
use Veyra\Shared\Domain\StateHash;

/**
 * WooCommerce-authoritative checkout projection.
 *
 * No order CRUD or gateway execution occurs here. This adapter only reads and
 * calculates the current cart, applies actor-owned checkout choices to the
 * current Woo session, and validates currently eligible shipping/payment data.
 */
final class WooCommerceCheckoutAuthority implements CheckoutAuthority
{
    public function available(): bool
    {
        return function_exists('WC')
            && WC() !== null
            && WC()->cart !== null
            && WC()->customer !== null
            && WC()->session !== null;
    }

    public function actorMatches(int $wordpressUserId): bool
    {
        if ($wordpressUserId < 1
            || !$this->available()
            || !function_exists('get_current_user_id')
            || (int) get_current_user_id() !== $wordpressUserId
            || !is_object(WC()->customer)
            || !method_exists(WC()->customer, 'get_id')
            || !is_object(WC()->session)
            || !method_exists(WC()->session, 'get_customer_id')
        ) {
            return false;
        }

        $sessionCustomerId = (string) WC()->session->get_customer_id();

        return (int) WC()->customer->get_id() === $wordpressUserId
            && $sessionCustomerId !== ''
            && hash_equals((string) $wordpressUserId, $sessionCustomerId);
    }

    public function cart(): array
    {
        if (!$this->available()) {
            return ['ok' => false, 'code' => 'checkout_runtime_unavailable'];
        }

        $lines = [];
        foreach (WC()->cart->get_cart() as $lineId => $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $lines[] = [
                'line_id' => (string) $lineId,
                'product_id' => (int) ($item['product_id'] ?? 0),
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'name' => $product->get_name(),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'variation_attributes' => is_array($item['variation'] ?? null) ? $item['variation'] : [],
                'unit_price' => (string) $product->get_price(),
                'line_subtotal' => (string) ($item['line_subtotal'] ?? 0),
                'line_total' => (string) ($item['line_total'] ?? 0),
                'line_tax' => (string) ($item['line_tax'] ?? 0),
                'needs_shipping' => $product->needs_shipping(),
                'virtual' => $product->is_virtual(),
            ];
        }
        $totals = WC()->cart->get_totals();
        $stableLines = [];
        foreach ($lines as $line) {
            $stableLines[] = [
                'line_id' => $line['line_id'],
                'product_id' => $line['product_id'],
                'variation_id' => $line['variation_id'],
                'variation_attributes' => $line['variation_attributes'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
            ];
        }
        $coupons = array_values(array_map('strval', array_keys(WC()->cart->get_coupons())));
        sort($coupons, SORT_STRING);
        $commerceHash = StateHash::fromPayload([
            'lines' => $stableLines,
            'coupons' => $coupons,
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
        ])->value();

        return [
            'ok' => true,
            // The Woo cart hash may include the calculated total and therefore
            // change merely because a shipping rate was selected. Veyra uses a
            // stable commercial-input hash for dependency invalidation and keeps
            // the native hash separate for diagnostics/reconciliation.
            'hash' => $commerceHash,
            'woocommerce_cart_hash' => (string) WC()->cart->get_cart_hash(),
            'empty' => WC()->cart->is_empty(),
            'needs_shipping' => WC()->cart->needs_shipping(),
            'item_count' => (int) WC()->cart->get_cart_contents_count(),
            'lines' => $lines,
            'coupons' => $coupons,
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
            'totals' => $this->totals($totals),
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    public function classifyFulfillment(): array
    {
        $cart = $this->cart();
        if (($cart['ok'] ?? false) !== true) {
            return $cart;
        }
        if (($cart['empty'] ?? true) === true) {
            return ['ok' => false, 'code' => 'checkout_cart_empty', 'cart' => $cart];
        }

        $shippable = 0;
        $virtual = 0;
        foreach ($cart['lines'] as $line) {
            if (($line['needs_shipping'] ?? false) === true) {
                ++$shippable;
            } else {
                ++$virtual;
            }
        }
        $packageCount = 0;
        if ($shippable > 0 && method_exists(WC()->cart, 'get_shipping_packages')) {
            $packageCount = count(WC()->cart->get_shipping_packages());
        }
        $classification = $shippable === 0
            ? 'virtual_only'
            : ($virtual > 0 ? 'mixed' : 'shippable');
        if ($packageCount > 1) {
            $classification = 'multi_package';
        }

        $projection = [
            'classification' => $classification,
            'requires_shipping' => $shippable > 0,
            'shippable_line_count' => $shippable,
            'non_shippable_line_count' => $virtual,
            'package_count' => $packageCount,
            'cart_hash' => $cart['hash'],
            'extension_governed' => false,
            'observed_at' => gmdate(DATE_ATOM),
        ];

        // Approved adapters may replace the high-level projection but cannot
        // mutate the cart or manufacture shipping/payment facts through it.
        $adapted = apply_filters('veyra_checkout_fulfillment_classification', null, $projection, WC()->cart);
        if (is_array($adapted)
            && isset($adapted['classification'], $adapted['requires_shipping'])
            && is_string($adapted['classification'])
            && is_bool($adapted['requires_shipping'])
            && preg_match('/^[a-z][a-z0-9_-]{1,47}$/D', $adapted['classification']) === 1
        ) {
            $projection['classification'] = $adapted['classification'];
            $projection['requires_shipping'] = $adapted['requires_shipping'];
            $projection['extension_governed'] = true;
            $projection['adapter'] = isset($adapted['adapter']) && is_string($adapted['adapter'])
                ? substr($adapted['adapter'], 0, 96)
                : 'approved_filter';
        }

        return ['ok' => true, 'classification' => $projection, 'cart' => $cart];
    }

    public function fulfillmentModes(CheckoutState $state): array
    {
        $classification = $this->classifyFulfillment();
        if (($classification['ok'] ?? false) !== true) {
            return $classification;
        }
        $requiresShipping = (bool) $classification['classification']['requires_shipping'];
        $modes = $requiresShipping
            ? [['id' => 'delivery', 'label' => 'Delivery', 'currently_eligible' => true, 'source' => 'woocommerce']]
            : [['id' => 'digital', 'label' => 'Digital fulfillment', 'currently_eligible' => true, 'source' => 'woocommerce']];

        if ($requiresShipping) {
            $packages = $this->shippingPackages($state);
            if (($packages['ok'] ?? false) === true) {
                foreach ($packages['packages'] as $package) {
                    foreach ($package['rates'] as $rate) {
                        if (($rate['method_id'] ?? '') === 'local_pickup') {
                            $modes[] = ['id' => 'pickup', 'label' => 'Pickup', 'currently_eligible' => true, 'source' => 'woocommerce_rate'];
                            break 2;
                        }
                    }
                }
            }
        }

        $adapted = apply_filters('veyra_checkout_fulfillment_modes', [], $modes, $classification['classification'], $state->jsonSerialize());
        if (is_array($adapted)) {
            foreach ($adapted as $mode) {
                if (!is_array($mode)
                    || !isset($mode['id'], $mode['label'])
                    || !is_string($mode['id'])
                    || !is_string($mode['label'])
                    || preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $mode['id']) !== 1
                    || strlen($mode['label']) > 120
                ) {
                    continue;
                }
                $modes[] = [
                    'id' => $mode['id'],
                    'label' => $mode['label'],
                    'currently_eligible' => ($mode['currently_eligible'] ?? false) === true,
                    'source' => 'approved_adapter',
                ];
            }
        }
        $unique = [];
        foreach ($modes as $mode) {
            $unique[$mode['id']] = $mode;
        }
        $modes = array_values(array_filter($unique, static fn (array $mode): bool => $mode['currently_eligible'] === true));

        return [
            'ok' => true,
            'modes' => $modes,
            'selected_mode' => $state->fulfillmentMode,
            'selection_required' => count($modes) > 1 && $state->fulfillmentMode === null,
            'classification' => $classification['classification'],
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    public function requiredFields(CheckoutState $state): array
    {
        if (!$this->available() || !function_exists('WC')) {
            return ['ok' => false, 'code' => 'checkout_runtime_unavailable'];
        }
        $this->applyStateToCustomer($state);
        $checkout = WC()->checkout();
        if (!$checkout instanceof \WC_Checkout) {
            return ['ok' => false, 'code' => 'checkout_fields_unavailable'];
        }
        $fields = $checkout->get_checkout_fields();
        $result = [];
        $missing = [];
        $unsupportedRequired = [];
        $classification = $this->classifyFulfillment();
        $requiresDeliveryAddress = ($classification['ok'] ?? false) === true
            && ($classification['classification']['requires_shipping'] ?? false) === true
            && $state->fulfillmentMode === 'delivery';
        foreach ($fields as $group => $groupFields) {
            if (!is_array($groupFields)) {
                continue;
            }
            if ((string) $group === 'shipping' && !$requiresDeliveryAddress) {
                continue;
            }
            foreach ($groupFields as $key => $definition) {
                if (!is_string($key) || !is_array($definition)) {
                    continue;
                }
                $required = ($definition['required'] ?? false) === true;
                $value = $this->fieldValue($state, $key);
                $visible = !isset($definition['type']) || $definition['type'] !== 'hidden';
                if (!$visible) {
                    continue;
                }
                $entry = [
                    'field_id' => $key,
                    'group' => (string) $group,
                    'label' => wp_strip_all_tags((string) ($definition['label'] ?? $key)),
                    'type' => (string) ($definition['type'] ?? 'text'),
                    'required' => $required,
                    'has_value' => $value !== '',
                ];
                $result[] = $entry;
                if ($required && $value === '') {
                    $missing[] = $key;
                }
                if ($required && !$this->chatCanCaptureField($key)) {
                    $unsupportedRequired[] = $key;
                }
            }
        }

        return [
            'ok' => true,
            'fields' => $result,
            'missing_required_fields' => $missing,
            'unsupported_required_fields' => array_values(array_unique($unsupportedRequired)),
            'chat_checkout_supported' => $unsupportedRequired === [],
            'standard_checkout_handoff_required' => $unsupportedRequired !== [],
            'complete' => $missing === [] && $unsupportedRequired === [],
        ];
    }

    public function shippingPackages(CheckoutState $state): array
    {
        if (!$this->available() || WC()->shipping() === null) {
            return ['ok' => false, 'code' => 'checkout_shipping_unavailable'];
        }
        $classification = $this->classifyFulfillment();
        if (($classification['ok'] ?? false) !== true) {
            return $classification;
        }
        if (($classification['classification']['requires_shipping'] ?? false) !== true) {
            return [
                'ok' => true,
                'shipping_required' => false,
                'packages' => [],
                'package_fingerprint' => StateHash::fromPayload(['cart_hash' => $state->cartHash, 'packages' => []])->value(),
                'observed_at' => gmdate(DATE_ATOM),
            ];
        }
        $this->applyStateToCustomer($state);
        try {
            $calculated = WC()->cart->calculate_shipping();
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'shipping_calculation_failed'];
        }
        $rawPackages = is_array($calculated) ? $calculated : WC()->shipping()->get_packages();
        $chosen = WC()->session->get('chosen_shipping_methods', []);
        $chosen = is_array($chosen) ? $chosen : [];
        $packages = [];
        $ordinal = 0;
        foreach ($rawPackages as $package) {
            if (!is_array($package)) {
                continue;
            }
            $packageId = 'package_' . (string) $ordinal;
            $rates = [];
            foreach (($package['rates'] ?? []) as $rate) {
                if (!$rate instanceof \WC_Shipping_Rate) {
                    continue;
                }
                $rateId = $rate->get_id();
                $tax = array_sum(array_map('floatval', $rate->get_taxes()));
                $rates[] = [
                    'rate_id' => $rateId,
                    'method_id' => $rate->get_method_id(),
                    'instance_id' => $rate->get_instance_id(),
                    'label' => $rate->get_label(),
                    'cost' => (string) $rate->get_cost(),
                    'tax' => (string) $tax,
                    'currency' => get_woocommerce_currency(),
                    'selected' => isset($chosen[$ordinal]) && hash_equals((string) $chosen[$ordinal], $rateId),
                    'estimate' => method_exists($rate, 'get_delivery_time') ? (string) $rate->get_delivery_time() : null,
                    'estimate_state' => method_exists($rate, 'get_delivery_time') && $rate->get_delivery_time() !== '' ? 'current_gateway_value' : 'unavailable',
                ];
            }
            $contents = [];
            foreach (($package['contents'] ?? []) as $lineId => $item) {
                $contents[] = [
                    'line_id' => (string) $lineId,
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'variation_id' => (int) ($item['variation_id'] ?? 0),
                    'quantity' => (float) ($item['quantity'] ?? 0),
                ];
            }
            $packages[] = [
                'package_id' => $packageId,
                'package_index' => $ordinal,
                'contents' => $contents,
                'destination' => $this->publicDestination(is_array($package['destination'] ?? null) ? $package['destination'] : []),
                'rates' => $rates,
                'rate_selection_required' => count($rates) > 1 && !isset($chosen[$ordinal]),
                'has_available_rate' => $rates !== [],
            ];
            ++$ordinal;
        }
        $fingerprintPayload = [];
        foreach ($packages as $package) {
            $fingerprintPayload[] = [
                'package_id' => $package['package_id'],
                'contents' => $package['contents'],
                'destination' => $package['destination'],
                'rate_ids' => array_column($package['rates'], 'rate_id'),
            ];
        }

        return [
            'ok' => true,
            'shipping_required' => true,
            'packages' => $packages,
            'package_fingerprint' => StateHash::fromPayload(['cart_hash' => $state->cartHash, 'packages' => $fingerprintPayload])->value(),
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    public function selectShippingRates(CheckoutState $state, array $selections): array
    {
        $projection = $this->shippingPackages($state);
        if (($projection['ok'] ?? false) !== true) {
            return $projection;
        }
        if (($projection['shipping_required'] ?? false) !== true) {
            return $selections === []
                ? ['ok' => true, 'selection' => [], 'package_fingerprint' => $projection['package_fingerprint'], 'shipping_required' => false]
                : ['ok' => false, 'code' => 'shipping_selection_not_applicable'];
        }
        if ($projection['packages'] === []) {
            return ['ok' => false, 'code' => 'shipping_packages_unavailable'];
        }
        $byPackage = [];
        foreach ($selections as $selection) {
            $packageId = $selection['package_id'] ?? null;
            $rateId = $selection['rate_id'] ?? null;
            if (!is_string($packageId)
                || !is_string($rateId)
                || $packageId === ''
                || $rateId === ''
                || isset($byPackage[$packageId])
            ) {
                return ['ok' => false, 'code' => 'shipping_selection_invalid'];
            }
            $byPackage[$packageId] = $rateId;
        }

        $chosen = [];
        $normalized = [];
        foreach ($projection['packages'] as $package) {
            $packageId = (string) $package['package_id'];
            if (!isset($byPackage[$packageId])) {
                return ['ok' => false, 'code' => 'shipping_package_selection_missing', 'package_id' => $packageId];
            }
            $matches = array_values(array_filter(
                $package['rates'],
                static fn (array $rate): bool => hash_equals((string) $rate['rate_id'], $byPackage[$packageId])
            ));
            if (count($matches) !== 1) {
                return ['ok' => false, 'code' => 'shipping_rate_not_currently_available', 'package_id' => $packageId];
            }
            $index = (int) $package['package_index'];
            $chosen[$index] = $byPackage[$packageId];
            $normalized[] = [
                'package_id' => $packageId,
                'rate_id' => $byPackage[$packageId],
                'label' => $matches[0]['label'],
                'cost' => $matches[0]['cost'],
                'tax' => $matches[0]['tax'],
            ];
            unset($byPackage[$packageId]);
        }
        if ($byPackage !== []) {
            return ['ok' => false, 'code' => 'shipping_package_unknown'];
        }
        ksort($chosen, SORT_NUMERIC);
        try {
            WC()->session->set('chosen_shipping_methods', array_values($chosen));
            WC()->cart->calculate_totals();
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'shipping_selection_calculation_failed'];
        }

        return [
            'ok' => true,
            'selection' => $normalized,
            'package_fingerprint' => $projection['package_fingerprint'],
            'shipping_required' => true,
            'selected_at' => gmdate(DATE_ATOM),
        ];
    }

    public function paymentMethods(CheckoutState $state): array
    {
        if (!$this->available() || WC()->payment_gateways() === null) {
            return ['ok' => false, 'code' => 'payment_gateways_unavailable'];
        }
        $required = $this->requiredFields($state);
        if (($required['ok'] ?? false) !== true) {
            return $required;
        }
        if (($required['complete'] ?? false) !== true) {
            return [
                'ok' => false,
                'code' => 'payment_eligibility_context_incomplete',
                'missing_required_fields' => $required['missing_required_fields'] ?? [],
            ];
        }
        $classification = $this->classifyFulfillment();
        if (($classification['ok'] ?? false) !== true) {
            return $classification;
        }
        if (($classification['classification']['requires_shipping'] ?? false) === true) {
            $storedSelections = $state->packageSelection['selections'] ?? null;
            if (!is_array($storedSelections) || !array_is_list($storedSelections)) {
                return ['ok' => false, 'code' => 'shipping_selection_missing'];
            }
            $shipping = $this->selectShippingRates($state, $storedSelections);
            if (($shipping['ok'] ?? false) !== true) {
                return $shipping;
            }
            if (!hash_equals(
                (string) ($state->packageSelection['package_fingerprint'] ?? ''),
                (string) ($shipping['package_fingerprint'] ?? '')
            )) {
                return ['ok' => false, 'code' => 'shipping_packages_stale'];
            }
        }
        try {
            $this->applyStateToCustomer($state);
            WC()->cart->calculate_totals();
            $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'payment_eligibility_failed'];
        }
        $methods = [];
        foreach ($gateways as $gateway) {
            if (!$gateway instanceof \WC_Payment_Gateway || $gateway->enabled !== 'yes') {
                continue;
            }
            $methods[] = [
                'payment_method_id' => $gateway->id,
                'title' => wp_strip_all_tags($gateway->get_title()),
                'description' => wp_strip_all_tags($gateway->get_description()),
                'supports' => array_values(array_filter([
                    $gateway->supports('products') ? 'products' : null,
                    $gateway->supports('refunds') ? 'refunds' : null,
                ])),
                'selected' => $state->paymentMethodId !== null && hash_equals($state->paymentMethodId, $gateway->id),
                'authorization_state' => 'not_started',
            ];
        }

        return [
            'ok' => true,
            'methods' => $methods,
            'selected_method_id' => $state->paymentMethodId,
            'selection_required' => count($methods) !== 1 || $state->paymentMethodId === null,
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    public function selectPaymentMethod(CheckoutState $state, string $paymentMethodId): array
    {
        $available = $this->paymentMethods($state);
        if (($available['ok'] ?? false) !== true) {
            return $available;
        }
        $matches = array_values(array_filter(
            $available['methods'],
            static fn (array $method): bool => hash_equals((string) $method['payment_method_id'], $paymentMethodId)
        ));
        if (count($matches) !== 1) {
            return ['ok' => false, 'code' => 'payment_method_not_currently_eligible'];
        }
        try {
            WC()->session->set('chosen_payment_method', $paymentMethodId);
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'payment_method_session_write_failed'];
        }

        return [
            'ok' => true,
            'payment_method' => $matches[0],
            'authorization_state' => 'not_started',
            'payment_settlement_state' => 'not_started',
            'selected_at' => gmdate(DATE_ATOM),
        ];
    }

    public function selectionSnapshot(): array
    {
        if (!$this->available()) {
            throw new \RuntimeException('Checkout authority session is unavailable.');
        }

        $shipping = WC()->session->get('chosen_shipping_methods', null);
        if ($shipping !== null) {
            if (!is_array($shipping) || !array_is_list($shipping) || count($shipping) > 12) {
                throw new \RuntimeException('Checkout authority shipping selection is malformed.');
            }
            $normalized = [];
            foreach ($shipping as $rateId) {
                if (!is_string($rateId)
                    || strlen($rateId) > 191
                    || preg_match('/[\x00-\x1F\x7F]/', $rateId) === 1
                ) {
                    throw new \RuntimeException('Checkout authority shipping selection is malformed.');
                }
                $normalized[] = $rateId;
            }
            $shipping = $normalized;
        }

        $payment = WC()->session->get('chosen_payment_method', null);
        if ($payment !== null
            && (!is_string($payment)
                || strlen($payment) > 191
                || preg_match('/[\x00-\x1F\x7F]/', $payment) === 1)
        ) {
            throw new \RuntimeException('Checkout authority payment selection is malformed.');
        }

        return [
            'shipping_methods' => $shipping,
            'payment_method_id' => $payment,
        ];
    }

    public function restoreSelectionSnapshot(array $snapshot): bool
    {
        if (!$this->available()
            || array_diff(array_keys($snapshot), ['shipping_methods', 'payment_method_id']) !== []
            || !array_key_exists('shipping_methods', $snapshot)
            || !array_key_exists('payment_method_id', $snapshot)
        ) {
            return false;
        }
        $shipping = $snapshot['shipping_methods'];
        if ($shipping !== null && (!is_array($shipping) || !array_is_list($shipping) || count($shipping) > 12)) {
            return false;
        }
        if (is_array($shipping)) {
            foreach ($shipping as $rateId) {
                if (!is_string($rateId)
                    || strlen($rateId) > 191
                    || preg_match('/[\x00-\x1F\x7F]/', $rateId) === 1
                ) {
                    return false;
                }
            }
        }
        $payment = $snapshot['payment_method_id'];
        if ($payment !== null
            && (!is_string($payment)
                || strlen($payment) > 191
                || preg_match('/[\x00-\x1F\x7F]/', $payment) === 1)
        ) {
            return false;
        }

        try {
            WC()->session->set('chosen_shipping_methods', $shipping);
            WC()->session->set('chosen_payment_method', $payment);
            WC()->cart->calculate_totals();

            return $this->selectionSnapshot() === $snapshot;
        } catch (\Throwable) {
            return false;
        }
    }

    public function calculate(CheckoutState $state): array
    {
        $cart = $this->cart();
        if (($cart['ok'] ?? false) !== true) {
            return $cart;
        }
        if (($cart['empty'] ?? true) === true) {
            return ['ok' => false, 'code' => 'checkout_cart_empty'];
        }
        if (!$state->hasCurrentCart((string) $cart['hash'])) {
            return [
                'ok' => false,
                'code' => 'checkout_cart_stale',
                'stored_cart_hash' => $state->cartHash,
                'current_cart_hash' => $cart['hash'],
            ];
        }
        $itemValidation = $this->validateCurrentCartItems();
        if (($itemValidation['ok'] ?? false) !== true) {
            return $itemValidation;
        }

        $classification = $this->classifyFulfillment();
        $modes = $this->fulfillmentModes($state);
        if (($classification['ok'] ?? false) !== true || ($modes['ok'] ?? false) !== true) {
            return ['ok' => false, 'code' => 'fulfillment_eligibility_unavailable'];
        }
        $eligibleModeIds = array_column($modes['modes'], 'id');
        if ($state->fulfillmentMode === null || !in_array($state->fulfillmentMode, $eligibleModeIds, true)) {
            return ['ok' => false, 'code' => 'fulfillment_mode_missing_or_stale', 'eligible_modes' => $modes['modes']];
        }

        $this->applyStateToCustomer($state);
        $shipping = $this->shippingPackages($state);
        if (($shipping['ok'] ?? false) !== true) {
            return $shipping;
        }
        $shippingSelection = ['ok' => true, 'selection' => [], 'shipping_required' => false];
        if (($shipping['shipping_required'] ?? false) === true) {
            $storedSelections = $state->packageSelection['selections'] ?? null;
            if (!is_array($storedSelections) || !array_is_list($storedSelections)) {
                return ['ok' => false, 'code' => 'shipping_selection_missing', 'packages' => $shipping['packages']];
            }
            $shippingSelection = $this->selectShippingRates($state, $storedSelections);
            if (($shippingSelection['ok'] ?? false) !== true) {
                return $shippingSelection;
            }
            if (!hash_equals(
                (string) ($state->packageSelection['package_fingerprint'] ?? ''),
                (string) ($shippingSelection['package_fingerprint'] ?? '')
            )) {
                return ['ok' => false, 'code' => 'shipping_packages_stale', 'packages' => $shipping['packages']];
            }
        }

        $required = $this->requiredFields($state);
        if (($required['ok'] ?? false) !== true) {
            return $required;
        }
        $payments = $this->paymentMethods($state);
        if (($payments['ok'] ?? false) !== true) {
            return $payments;
        }
        if ($state->paymentMethodId === null) {
            return ['ok' => false, 'code' => 'payment_method_missing', 'payment_methods' => $payments['methods']];
        }
        $paymentSelection = $this->selectPaymentMethod($state, $state->paymentMethodId);
        if (($paymentSelection['ok'] ?? false) !== true) {
            return $paymentSelection;
        }

        try {
            WC()->cart->calculate_totals();
        } catch (\Throwable) {
            return ['ok' => false, 'code' => 'checkout_totals_calculation_failed'];
        }
        $current = $this->cart();
        if (($current['ok'] ?? false) !== true || !$state->hasCurrentCart((string) ($current['hash'] ?? ''))) {
            return ['ok' => false, 'code' => 'checkout_cart_changed_during_calculation'];
        }
        $complete = ($required['complete'] ?? false) === true;

        return [
            'ok' => true,
            'complete' => $complete,
            'missing_required_fields' => $required['missing_required_fields'],
            'cart' => $current,
            'classification' => $classification['classification'],
            'fulfillment_mode' => $state->fulfillmentMode,
            'shipping' => $shippingSelection,
            'payment_method' => $paymentSelection['payment_method'],
            'totals' => $current['totals'],
            'currency' => $current['currency'],
            'calculated_at' => gmdate(DATE_ATOM),
            'woocommerce_authoritative' => true,
        ];
    }

    private function applyStateToCustomer(CheckoutState $state): void
    {
        if (!$this->available()) {
            return;
        }
        $shippingContact = is_array($state->contacts['shipping'] ?? null) ? $state->contacts['shipping'] : [];
        $billingContact = is_array($state->contacts['billing'] ?? null) ? $state->contacts['billing'] : [];
        $this->applyCustomerFields('shipping', array_merge($shippingContact, $state->shippingAddress));
        $this->applyCustomerFields('billing', array_merge($billingContact, $state->billingAddress));
    }

    /** @return array<string, mixed> */
    private function validateCurrentCartItems(): array
    {
        $blockers = [];
        foreach (WC()->cart->get_cart() as $lineId => $item) {
            $product = $item['data'] ?? null;
            $quantity = (float) ($item['quantity'] ?? 0);
            if (!$product instanceof \WC_Product) {
                $blockers[] = ['line_id' => (string) $lineId, 'code' => 'product_unavailable'];
                continue;
            }
            if (!$product->is_purchasable()) {
                $blockers[] = ['line_id' => (string) $lineId, 'code' => 'product_not_purchasable'];
            } elseif (!$product->is_in_stock()) {
                $blockers[] = ['line_id' => (string) $lineId, 'code' => 'product_out_of_stock'];
            } elseif (!$product->has_enough_stock($quantity)) {
                $blockers[] = ['line_id' => (string) $lineId, 'code' => 'insufficient_stock'];
            }
            if ($product instanceof \WC_Product_Variation && in_array('', $product->get_variation_attributes(), true)) {
                $blockers[] = ['line_id' => (string) $lineId, 'code' => 'variation_any_not_allowed'];
            }
        }

        return $blockers === []
            ? ['ok' => true]
            : ['ok' => false, 'code' => 'checkout_cart_items_invalid', 'blockers' => $blockers];
    }

    /** @param array<string, string> $values */
    private function applyCustomerFields(string $kind, array $values): void
    {
        $fields = $kind === 'shipping'
            ? ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone']
            : ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }
            $setter = 'set_' . $kind . '_' . $field;
            if (method_exists(WC()->customer, $setter)) {
                WC()->customer->{$setter}((string) $values[$field]);
            }
        }
    }

    private function fieldValue(CheckoutState $state, string $field): string
    {
        if (str_starts_with($field, 'shipping_')) {
            $key = substr($field, 9);
            $contact = is_array($state->contacts['shipping'] ?? null) ? $state->contacts['shipping'] : [];

            return (string) ($contact[$key] ?? $state->shippingAddress[$key] ?? '');
        }
        if (str_starts_with($field, 'billing_')) {
            $key = substr($field, 8);
            $contact = is_array($state->contacts['billing'] ?? null) ? $state->contacts['billing'] : [];

            return (string) ($contact[$key] ?? $state->billingAddress[$key] ?? '');
        }
        $required = is_array($state->contacts['required_fields'] ?? null) ? $state->contacts['required_fields'] : [];

        return (string) ($required[$field] ?? '');
    }

    private function chatCanCaptureField(string $field): bool
    {
        $suffixes = [
            'first_name', 'last_name', 'company', 'email', 'phone',
            'address_1', 'address_2', 'city', 'state', 'postcode', 'country',
        ];
        foreach (['billing_', 'shipping_'] as $prefix) {
            if (str_starts_with($field, $prefix)
                && in_array(substr($field, strlen($prefix)), $suffixes, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $destination @return array<string, string> */
    private function publicDestination(array $destination): array
    {
        $result = [];
        foreach (['country', 'state', 'postcode', 'city', 'address', 'address_1', 'address_2'] as $key) {
            if (isset($destination[$key]) && is_scalar($destination[$key])) {
                $result[$key] = (string) $destination[$key];
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $totals @return array<string, string> */
    private function totals(array $totals): array
    {
        return [
            'subtotal' => (string) ($totals['subtotal'] ?? 0),
            'discount_total' => (string) ($totals['discount_total'] ?? 0),
            'discount_tax' => (string) ($totals['discount_tax'] ?? 0),
            'shipping_total' => (string) ($totals['shipping_total'] ?? 0),
            'shipping_tax' => (string) ($totals['shipping_tax'] ?? 0),
            'fee_total' => (string) ($totals['fee_total'] ?? 0),
            'fee_tax' => (string) ($totals['fee_tax'] ?? 0),
            'cart_contents_tax' => (string) ($totals['cart_contents_tax'] ?? 0),
            'total_tax' => (string) ($totals['total_tax'] ?? 0),
            'total' => (string) ($totals['total'] ?? 0),
        ];
    }
}
