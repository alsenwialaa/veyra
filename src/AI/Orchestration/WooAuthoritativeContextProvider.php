<?php
declare(strict_types=1);

namespace Veyra\AI\Orchestration;

use Veyra\AI\Tool\ToolContext;

final class WooAuthoritativeContextProvider implements AuthoritativeContextProvider
{
    public function runtime(ToolContext $context): array
    {
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $utc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return [
            'version' => $utc->format('YmdHi'),
            'utc' => $utc->format(DATE_ATOM),
            'local' => $utc->setTimezone($timezone)->format(DATE_ATOM),
            'timezone' => $timezone->getName(),
            'locale' => $context->locale,
            'feature_states' => $context->featureStates,
        ];
    }

    public function commerce(ToolContext $context): array
    {
        // A browser-global Woo cart is not automatically the same principal as
        // a Veyra guest/support actor. Until a persisted guest↔Woo-session
        // binding exists, expose cart authority only for the exact logged-in
        // customer whose WordPress identity, Woo customer and Woo session all
        // resolve to the same principal.
        if ($context->actorType !== 'customer' || $context->userId === null
            || !function_exists('get_current_user_id')
            || (int) get_current_user_id() !== $context->userId
            || (function_exists('is_user_logged_in') && !is_user_logged_in())
        ) {
            return $this->unavailable('woo_actor_binding_unavailable');
        }
        if (!function_exists('WC')) {
            return $this->unavailable('woo_unavailable');
        }
        $woo = WC();
        if (!is_object($woo)
            || !is_object($woo->cart ?? null)
            || !is_object($woo->session ?? null)
            || !is_object($woo->customer ?? null)
        ) {
            return $this->unavailable('woo_unavailable');
        }
        $wooCustomerId = method_exists($woo->customer, 'get_id')
            ? (int) $woo->customer->get_id()
            : 0;
        if ($wooCustomerId !== $context->userId) {
            return $this->unavailable('woo_actor_binding_unavailable');
        }
        if (!method_exists($woo->session, 'get_customer_id')) {
            return $this->unavailable('woo_actor_binding_unavailable');
        }
        $sessionCustomerId = (string) $woo->session->get_customer_id();
        if ($sessionCustomerId === '' || !hash_equals((string) $context->userId, $sessionCustomerId)) {
            return $this->unavailable('woo_actor_binding_unavailable');
        }
        $cart = $woo->cart;
        $lines = [];
        foreach ($cart->get_cart() as $key => $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof \WC_Product) {
                continue;
            }
            $lines[] = [
                'line_id' => (string) $key,
                'product_id' => (int) ($item['product_id'] ?? 0),
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'name' => $product->get_name(),
                'quantity' => (float) ($item['quantity'] ?? 0),
            ];
        }
        $cartHash = method_exists($cart, 'get_cart_hash') ? (string) $cart->get_cart_hash() : hash('sha256', wp_json_encode($lines));
        return [
            'version' => $cartHash,
            'freshness' => 'current',
            'cart' => [
                'available' => true,
                'hash' => $cartHash,
                'item_count' => (int) $cart->get_cart_contents_count(),
                'lines' => $lines,
                'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
                'total' => function_exists('wc_format_decimal') ? wc_format_decimal($cart->get_total('edit')) : (string) $cart->get_total('edit'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(string $version): array
    {
        return ['version' => $version, 'freshness' => 'unknown', 'cart' => ['available' => false]];
    }
}
