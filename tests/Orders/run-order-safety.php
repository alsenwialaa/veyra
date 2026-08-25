<?php

declare(strict_types=1);

final class VeyraFakeWooDate extends DateTimeImmutable
{
    public function date(string $format): string { return $this->format($format); }
}

class WC_Order
{
    public function __construct(
        private int $id,
        private int $customerId,
        private string $status = 'processing',
        private string $number = ''
    ) {
        $this->number = $number !== '' ? $number : (string) $id;
    }

    public function get_id(): int { return $this->id; }
    public function get_customer_id(): int { return $this->customerId; }
    public function get_status(): string { return $this->status; }
    public function get_order_number(): string { return $this->number; }
    public function get_date_created(): VeyraFakeWooDate { return new VeyraFakeWooDate('2026-08-24T10:00:00Z'); }
    public function get_date_modified(): VeyraFakeWooDate { return new VeyraFakeWooDate('2026-08-24T10:05:00Z'); }
    public function get_currency(): string { return 'USD'; }
    public function get_total(): string { return '42.00'; }
    public function is_paid(): bool { return false; }
    public function get_payment_method_title(): string { return 'Test'; }
}

/** @var array<int, WC_Order> $veyraOrders */
$veyraOrders = [];
/** @var array<string, array<string, mixed>> $veyraOrderActions */
$veyraOrderActions = [];
/** @var array<string, mixed> $veyraLastOrderQuery */
$veyraLastOrderQuery = [];

function wc_get_order(int $id): ?WC_Order
{
    global $veyraOrders;
    return $veyraOrders[$id] ?? null;
}

function wc_get_orders(array $args): object
{
    global $veyraOrders, $veyraLastOrderQuery;
    $veyraLastOrderQuery = $args;
    $statuses = is_array($args['status'] ?? null) ? $args['status'] : [];
    $orders = array_values(array_filter($veyraOrders, static function (WC_Order $order) use ($args, $statuses): bool {
        if ($order->get_customer_id() !== (int) $args['customer_id']) {
            return false;
        }
        return $statuses === []
            || in_array($order->get_status(), $statuses, true)
            || in_array('wc-' . $order->get_status(), $statuses, true);
    }));
    $total = count($orders);
    $limit = max(1, (int) ($args['limit'] ?? 10));
    return (object) [
        'orders' => array_slice($orders, 0, $limit),
        'total' => $total,
        'max_num_pages' => (int) ceil($total / $limit),
    ];
}

function wc_get_order_statuses(): array
{
    return ['wc-processing' => 'Processing', 'wc-completed' => 'Completed'];
}

function wc_get_account_orders_actions(WC_Order $order): array
{
    global $veyraOrderActions;
    return $veyraOrderActions;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    global $veyraOrders;
    if ($hook === 'veyra_resolve_customer_order_reference' && ($args[0] ?? null) === 'INV-501') {
        return ['order' => $veyraOrders[501] ?? null, 'exact_reference' => 'INV-501'];
    }
    return $value;
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Tool\ToolContext;
use Veyra\Orders\Tool\OrderToolHandler;

$passed = 0;
$failed = 0;
$scenario = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        ++$passed;
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$context = new ToolContext(
    'customer',
    'wp-user-9',
    9,
    null,
    '11111111-1111-4111-8111-111111111111',
    [],
    ['commerce_order_service' => 'On'],
    'en_US',
    '22222222-2222-4222-8222-222222222222'
);

$scenario('owned-order reads reject cross-customer and checkout drafts', static function () use ($assert, $context): void {
    global $veyraOrders;
    $veyraOrders = [
        501 => new WC_Order(501, 9, 'processing', 'INV-501'),
        502 => new WC_Order(502, 10),
        503 => new WC_Order(503, 9, 'checkout-draft'),
    ];
    $handler = new OrderToolHandler();
    $foreign = $handler->execute(new ToolCall('foreign', 'orders.get_customer_order', '1.0.0', ['order_id' => 502]), $context);
    $draft = $handler->execute(new ToolCall('draft', 'orders.get_customer_order', '1.0.0', ['order_id' => 503]), $context);
    $assert($foreign->status === 'failed' && $foreign->code === 'order_not_owned_or_unavailable', 'Cross-customer order read was not denied.');
    $assert($draft->status === 'failed' && $draft->code === 'order_not_owned_or_unavailable', 'Checkout draft was exposed as a customer order.');
});

$scenario('alternate order references require exact approved resolution', static function () use ($assert, $context): void {
    $handler = new OrderToolHandler();
    $exact = $handler->execute(new ToolCall('exact', 'orders.resolve_customer_order', '1.0.0', ['order_reference' => 'INV-501']), $context);
    $unknown = $handler->execute(new ToolCall('unknown', 'orders.resolve_customer_order', '1.0.0', ['order_reference' => 'INV-50']), $context);
    $ambiguous = $handler->execute(new ToolCall('ambiguous', 'orders.resolve_customer_order', '1.0.0', ['order_id' => 501, 'order_reference' => 'INV-501']), $context);
    $assert($exact->status === 'succeeded' && ($exact->data['order']['order_id'] ?? 0) === 501, 'Approved exact reference did not resolve.');
    $assert($unknown->status === 'failed', 'Partial reference was guessed.');
    $assert($ambiguous->code === 'exact_order_reference_required', 'Multiple references were not rejected.');
});

$scenario('Woo customer action matrix classifies direct and handoff actions safely', static function () use ($assert, $context): void {
    global $veyraOrderActions;
    $veyraOrderActions = [
        'cancel' => ['name' => 'Cancel'],
        'pay' => ['name' => 'Pay'],
        'extension-action' => ['name' => 'Extension action'],
    ];
    $result = (new OrderToolHandler())->execute(new ToolCall('matrix', 'orders.get_customer_action_matrix', '1.0.0', ['order_id' => 501]), $context);
    $assert($result->status === 'succeeded' && ($result->data['source'] ?? '') === 'woocommerce_customer_account_actions', 'Woo action source was not explicit.');
    $actions = array_column($result->data['actions'], null, 'action');
    $assert(($actions['cancel']['direct_execution_allowed'] ?? false) === true && ($actions['cancel']['confirmation_required'] ?? false) === true, 'Woo cancellation was not classified as confirmation-gated.');
    $assert(($actions['pay']['execution_mode'] ?? '') === 'gateway_handoff' && ($actions['pay']['direct_execution_allowed'] ?? true) === false, 'Payment action was not kept behind a gateway handoff.');
    $assert(($actions['extension-action']['execution_mode'] ?? '') === 'customer_handoff' && ($actions['extension-action']['direct_execution_allowed'] ?? true) === false, 'Unknown extension action was incorrectly direct-executable.');
});

$scenario('tracking stays unavailable without a certified typed adapter', static function () use ($assert, $context): void {
    $result = (new OrderToolHandler())->execute(new ToolCall('tracking', 'orders.get_tracking', '1.0.0', ['order_id' => 501]), $context);
    $assert($result->status === 'succeeded', 'Owned tracking availability could not be read.');
    $assert(($result->data['tracking']['available'] ?? true) === false, 'Uncertified tracking was advertised as available.');
    $assert(($result->data['tracking']['reason'] ?? '') === 'approved_tracking_adapter_not_published', 'Tracking unavailability lost its certification reason.');
});

$scenario('order list excludes checkout drafts and rejects unknown status filters', static function () use ($assert, $context): void {
    global $veyraLastOrderQuery;
    $handler = new OrderToolHandler();
    $list = $handler->execute(new ToolCall('list', 'orders.list_customer_orders', '1.0.0', []), $context);
    $invalid = $handler->execute(new ToolCall('invalid-status', 'orders.list_customer_orders', '1.0.0', ['status' => 'invented']), $context);
    $assert($list->status === 'succeeded' && ($list->data['count'] ?? -1) === 1, 'Hidden draft was present in the order list.');
    $assert(($list->data['complete'] ?? false) === true, 'A server-filtered complete list was reported as incomplete.');
    $assert(in_array('wc-processing', $veyraLastOrderQuery['status'] ?? [], true), 'Registered customer order statuses were not sent to Woo authority.');
    $assert(!in_array('wc-checkout-draft', $veyraLastOrderQuery['status'] ?? [], true), 'Checkout drafts were included in the authoritative query.');
    $assert($invalid->status === 'failed' && $invalid->code === 'order_status_invalid', 'Unknown order status was accepted.');
});

fwrite(STDOUT, sprintf("Order safety scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
