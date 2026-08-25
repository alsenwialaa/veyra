<?php

declare(strict_types=1);

use Veyra\Bootstrap\Activator;
use Veyra\Bootstrap\Plugin;
use Veyra\Features\Application\FeatureGate;
use Veyra\Features\Domain\FeatureKey;
use Veyra\Infrastructure\Database\Migration\Migrator;
use Veyra\Infrastructure\Database\TableNames;

if (!defined('ABSPATH') || !defined('WP_CLI')) {
    throw new RuntimeException('This integration contract must run through WP-CLI.');
}

$failures = [];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    ++$checks;
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(defined('VEYRA_VERSION'), 'The plugin version constant was not loaded.');
$assert(defined('VEYRA_SCHEMA_VERSION'), 'The plugin schema constant was not loaded.');
$assert(class_exists(WC_Product_Simple::class), 'WooCommerce product CRUD is unavailable.');
$assert(class_exists(WC_Order::class), 'WooCommerce order CRUD is unavailable.');
$assert((string) get_option(Migrator::SCHEMA_OPTION, '') === (string) VEYRA_SCHEMA_VERSION, 'The verified schema version was not persisted.');

$health = get_option(Activator::HEALTH_OPTION, []);
$assert(is_array($health), 'Activation health is not a structured record.');
$assert(($health['activation_remote_calls'] ?? null) === 0, 'Activation did not prove that it made zero provider calls.');
$assert(($health['manual_recovery_required'] ?? null) === false, 'Activation requires unexpected manual migration recovery.');
$assert(($health['schema_version'] ?? null) === (string) VEYRA_SCHEMA_VERSION, 'Activation health does not match the verified schema.');

global $wpdb;
$tables = new TableNames($wpdb->prefix);
foreach ($tables->all() as $table) {
    $escaped = $wpdb->esc_like($table);
    $actual = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $escaped));
    $assert($actual === $table, 'Missing Veyra table: ' . $table);
    $engine = $wpdb->get_var($wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $table
    ));
    $assert(is_string($engine) && strcasecmp($engine, 'InnoDB') === 0, 'Veyra table is not InnoDB: ' . $table);
}

$plugin = Plugin::register((string) VEYRA_PLUGIN_FILE);
$container = $plugin->container();
$assert($container !== null, 'The production composition root did not boot.');

$routes = rest_get_server()->get_routes();
foreach (['/veyra/v1/admin/provider'] as $route) {
    $assert(isset($routes[$route]), 'Expected REST route was not registered: ' . $route);
    if (!isset($routes[$route]) || !is_array($routes[$route])) {
        continue;
    }
    foreach ($routes[$route] as $endpoint) {
        if (!is_array($endpoint) || !isset($endpoint['callback'])) {
            continue;
        }
        $assert(isset($endpoint['permission_callback']) && is_callable($endpoint['permission_callback']), 'REST endpoint has no callable permission callback: ' . $route);
    }
}

if ($container !== null) {
    /** @var FeatureGate $features */
    $features = $container->get(FeatureGate::class);
    $aiAvailable = $features->allows(new FeatureKey('ai_semantic_orchestration'));
    foreach (['/veyra/v1/conversations', '/veyra/v1/conversations/current/messages'] as $route) {
        $assert(isset($routes[$route]) === $aiAvailable, 'Customer route exposure does not match the effective AI feature state: ' . $route);
    }
}

$product = new WC_Product_Simple();
$product->set_name('Veyra integration product');
$product->set_status('publish');
$product->set_regular_price('12.50');
$product->set_manage_stock(true);
$product->set_stock_quantity(10);
$productId = $product->save();
$assert($productId > 0, 'WooCommerce did not persist the integration product through CRUD.');
$reloadedProduct = wc_get_product($productId);
$assert($reloadedProduct instanceof WC_Product, 'WooCommerce could not reload the integration product.');
$assert($reloadedProduct instanceof WC_Product && $reloadedProduct->is_purchasable(), 'The integration product is not currently purchasable.');

$customerId = wp_insert_user([
    'user_login' => 'veyra-ci-customer-' . wp_generate_password(8, false, false),
    'user_pass' => wp_generate_password(24, true, true),
    'user_email' => 'veyra-ci-' . wp_generate_password(8, false, false) . '@example.invalid',
    'role' => 'customer',
]);
$assert(!is_wp_error($customerId) && (int) $customerId > 0, 'WordPress could not create the integration customer.');

if (!is_wp_error($customerId)) {
    $order = wc_create_order(['customer_id' => (int) $customerId]);
    $assert($order instanceof WC_Order, 'WooCommerce did not create an order through public CRUD.');
    if ($order instanceof WC_Order && $reloadedProduct instanceof WC_Product) {
        $order->add_product($reloadedProduct, 2);
        $order->calculate_totals();
        $order->save();
        $reloadedOrder = wc_get_order($order->get_id());
        $assert($reloadedOrder instanceof WC_Order, 'WooCommerce could not reload the CRUD order.');
        $assert($reloadedOrder instanceof WC_Order && $reloadedOrder->get_customer_id() === (int) $customerId, 'The CRUD order lost customer ownership.');
        $assert($reloadedOrder instanceof WC_Order && count($reloadedOrder->get_items()) === 1, 'The CRUD order item state is incorrect.');
        $order->delete(true);
    }
    wp_delete_user((int) $customerId);
}

$product->delete(true);

$storageMode = isset($args[0]) ? (string) $args[0] : '';
$hposEnabled = class_exists(Automattic\WooCommerce\Utilities\OrderUtil::class)
    && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
if ($storageMode === 'hpos') {
    $assert($hposEnabled, 'The HPOS-authoritative test requested HPOS, but WooCommerce did not enable it.');
} elseif ($storageMode === 'legacy') {
    $assert(!$hposEnabled, 'The legacy-order-storage test requested posts storage, but HPOS remained authoritative.');
} else {
    $assert(false, 'The integration test did not declare an order-storage mode.');
}

$checkoutMode = isset($args[1]) ? (string) $args[1] : '';
$checkoutPageId = wc_get_page_id('checkout');
$checkoutContent = $checkoutPageId > 0 ? (string) get_post_field('post_content', $checkoutPageId) : '';
if ($checkoutMode === 'classic') {
    $assert(has_shortcode($checkoutContent, 'woocommerce_checkout'), 'The classic-checkout fixture is not active.');
} elseif ($checkoutMode === 'blocks') {
    $assert(has_block('woocommerce/checkout', $checkoutContent), 'The Checkout Block fixture is not active.');
} else {
    $assert(false, 'The integration test did not declare a checkout-rendering mode.');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error(sprintf('Veyra platform integration failed: %d/%d checks failed.', count($failures), $checks));
}

WP_CLI::success(sprintf('Veyra platform integration passed: %d checks.', $checks));
