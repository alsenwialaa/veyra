<?php
declare(strict_types=1);

namespace Veyra\Orders\Tool;

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ToolResult;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\Shared\Domain\StateHash;

final class OrderToolHandler implements ToolHandler
{
    public function definitions(): array
    {
        $actors = ['customer'];
        $features = ['commerce_order_service'];
        return [
            $this->read('orders.list_customer_orders', 'List a bounded set of only the current customer owned orders for exact selection.', [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                'status' => ['type' => 'string', 'maxLength' => 40],
            ], [], $actors, $features),
            $this->read('orders.resolve_customer_order', 'Resolve one exact current-customer order by server ID or displayed order number; never choose an arbitrary recent order.', [
                'order_id' => ['type' => 'integer', 'minimum' => 1],
                'order_reference' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            ], [], $actors, $features),
            $this->read('orders.get_customer_order', 'Read one exact owned order with immutable purchase lines and separate current status facts.', [
                'order_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['order_id'], $actors, $features),
            $this->read('orders.get_item_context', 'Read an exact purchased order item and separately refresh its current catalog product.', [
                'order_id' => ['type' => 'integer', 'minimum' => 1],
                'order_item_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['order_id', 'order_item_id'], $actors, $features),
            $this->read('orders.get_tracking', 'Read approved tracking state when a supported adapter supplies it; never infer tracking from order status.', [
                'order_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['order_id'], $actors, $features),
            $this->read('orders.get_customer_action_matrix', 'Read current WooCommerce customer-facing action availability for an owned order.', [
                'order_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['order_id'], $actors, $features),
            $this->read('orders.validate_cancellation', 'Validate whether the current customer may currently request direct cancellation and produce a non-mutating preview.', [
                'order_id' => ['type' => 'integer', 'minimum' => 1],
            ], ['order_id'], $actors, $features),
            $this->read('orders.validate_change', 'Validate a proposed order change without mutating the order; unsupported routes must use CRM.', [
                'order_id' => ['type' => 'integer', 'minimum' => 1],
                'change_type' => ['type' => 'string', 'maxLength' => 80],
                'change' => ['type' => 'object'],
            ], ['order_id', 'change_type', 'change'], $actors, $features),
        ];
    }

    public function execute(ToolCall $call, ToolContext $context): ToolResult
    {
        if ($context->actorType !== 'customer' || $context->userId === null || !function_exists('wc_get_order')) {
            return ToolResult::denied($call, 'authentication_required', $context->correlationId);
        }
        if ($call->name === 'orders.list_customer_orders' && !function_exists('wc_get_orders')) {
            return ToolResult::failed($call, 'orders_runtime_unavailable', $context->correlationId, false);
        }
        $data = match ($call->name) {
            'orders.list_customer_orders' => $this->list($context->userId, (int) ($call->arguments['limit'] ?? 10), (string) ($call->arguments['status'] ?? 'any')),
            'orders.resolve_customer_order' => $this->resolve(
                $context->userId,
                isset($call->arguments['order_id']) ? (int) $call->arguments['order_id'] : null,
                isset($call->arguments['order_reference']) ? (string) $call->arguments['order_reference'] : null
            ),
            'orders.get_customer_order' => $this->readOrder($context->userId, (int) $call->arguments['order_id']),
            'orders.get_item_context' => $this->item($context->userId, (int) $call->arguments['order_id'], (int) $call->arguments['order_item_id']),
            'orders.get_tracking' => $this->tracking($context->userId, (int) $call->arguments['order_id']),
            'orders.get_customer_action_matrix' => $this->actionMatrix($context->userId, (int) $call->arguments['order_id']),
            'orders.validate_cancellation' => $this->validateCancellation($context->userId, (int) $call->arguments['order_id']),
            'orders.validate_change' => $this->validateChange($context->userId, (int) $call->arguments['order_id'], (string) $call->arguments['change_type'], $call->arguments['change']),
            default => ['ok' => false, 'code' => 'tool_operation_unknown'],
        };
        if (($data['ok'] ?? false) !== true) {
            return ToolResult::failed($call, (string) ($data['code'] ?? 'order_operation_failed'), $context->correlationId, false);
        }
        unset($data['ok']);
        return ToolResult::success($call, $data, $context->correlationId);
    }

    /** @param array<string, array<string, mixed>> $properties @param array<int, string> $required @param array<int, string> $actors @param array<int, string> $features */
    private function read(string $name, string $description, array $properties, array $required, array $actors, array $features): ToolDefinition
    {
        return new ToolDefinition($name, '1.0.0', $description, 'read', [
            'type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties,
        ], $actors, [], $features, true);
    }

    /** @return array<string, mixed> */
    private function list(int $userId, int $limit, string $status): array
    {
        $status = trim($status);
        $knownStatuses = function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : [];
        if ($knownStatuses === []) {
            return ['ok' => false, 'code' => 'order_status_registry_unavailable'];
        }
        if ($status !== '' && $status !== 'any') {
            $normalizedStatus = str_starts_with($status, 'wc-') ? $status : 'wc-' . $status;
            if (!in_array($normalizedStatus, $knownStatuses, true)) {
                return ['ok' => false, 'code' => 'order_status_invalid'];
            }
            $queryStatuses = [$normalizedStatus];
        } else {
            // Query only registered customer-visible order states. Querying
            // `any` and filtering checkout drafts afterwards lets hidden block
            // orders fill the bounded first page and conceal real purchases.
            $queryStatuses = array_values(array_filter(
                $knownStatuses,
                fn (string $candidate): bool => !in_array(
                    str_starts_with($candidate, 'wc-') ? substr($candidate, 3) : $candidate,
                    ['checkout-draft', 'auto-draft', 'trash'],
                    true
                )
            ));
            if ($queryStatuses === []) {
                return ['ok' => false, 'code' => 'order_status_registry_unavailable'];
            }
        }
        $boundedLimit = max(1, min(20, $limit));
        $args = [
            'customer_id' => $userId,
            'limit' => $boundedLimit,
            'page' => 1,
            'paginate' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'return' => 'objects',
            'status' => $queryStatuses,
        ];
        $query = wc_get_orders($args);
        $orders = is_object($query) && is_array($query->orders ?? null)
            ? $query->orders
            : (is_array($query) ? $query : []);
        $sourceTotal = is_object($query) && is_numeric($query->total ?? null)
            ? (int) $query->total
            : count($orders);
        $sourcePages = is_object($query) && is_numeric($query->max_num_pages ?? null)
            ? (int) $query->max_num_pages
            : 1;
        $items = [];
        $excluded = 0;
        foreach ($orders as $order) {
            if ($order instanceof \WC_Order && $this->owned($order, $userId) && !$this->hiddenCustomerStatus($order)) {
                $items[] = $this->summary($order);
            } else {
                ++$excluded;
            }
        }
        return [
            'ok' => true,
            'orders' => $items,
            'count' => count($items),
            'source_total' => $sourceTotal,
            'source_pages' => $sourcePages,
            'complete' => $sourcePages <= 1 && $excluded === 0,
            'truncated' => $sourcePages > 1,
            'selection_required' => count($items) !== 1,
        ];
    }

    /** @return array<string, mixed> */
    private function resolve(int $userId, ?int $orderId, ?string $orderReference): array
    {
        $reference = trim((string) $orderReference);
        if (($orderId !== null && $orderId > 0) === ($reference !== '')) {
            return ['ok' => false, 'code' => 'exact_order_reference_required'];
        }
        $order = $orderId !== null && $orderId > 0
            ? $this->ownedOrder($userId, $orderId)
            : $this->ownedOrderByReference($userId, $reference);
        return $order ? ['ok' => true, 'resolved' => true, 'order' => $this->summary($order)] : ['ok' => false, 'code' => 'order_not_owned_or_unavailable'];
    }

    /** @return array<string, mixed> */
    private function readOrder(int $userId, int $orderId): array
    {
        $order = $this->ownedOrder($userId, $orderId);
        if (!$order) {
            return ['ok' => false, 'code' => 'order_not_owned_or_unavailable'];
        }
        $lines = [];
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $lines[] = [
                'order_item_id' => (int) $itemId,
                'historical' => [
                    'name' => $item->get_name(),
                    'product_id' => $item->get_product_id(),
                    'variation_id' => $item->get_variation_id(),
                    'quantity' => $item->get_quantity(),
                    'subtotal' => (string) $item->get_subtotal(),
                    'total' => (string) $item->get_total(),
                    'tax' => (string) $item->get_total_tax(),
                    'meta' => $this->publicItemMeta($item),
                ],
                'current_product' => $this->currentProduct($item->get_product_id(), $item->get_variation_id()),
            ];
        }
        return ['ok' => true, 'order' => array_merge($this->summary($order), [
            'lines' => $lines,
            'subtotal' => (string) $order->get_subtotal(),
            'discount_total' => (string) $order->get_discount_total(),
            'shipping_total' => (string) $order->get_shipping_total(),
            'fee_total' => (string) $this->feeTotal($order),
            'tax_total' => (string) $order->get_total_tax(),
            'total' => (string) $order->get_total(),
            'shipping_method' => $order->get_shipping_method(),
            'shipping_contact' => [
                'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
                'company' => $order->get_shipping_company(),
                'address_1' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(),
                'phone' => method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : '',
            ],
        ])];
    }

    /** @return array<string, mixed> */
    private function item(int $userId, int $orderId, int $itemId): array
    {
        $order = $this->ownedOrder($userId, $orderId);
        if (!$order) {
            return ['ok' => false, 'code' => 'order_not_owned_or_unavailable'];
        }
        $item = $order->get_item($itemId);
        if (!$item instanceof \WC_Order_Item_Product) {
            return ['ok' => false, 'code' => 'order_item_not_available'];
        }
        return ['ok' => true, 'item' => [
            'order_id' => $orderId,
            'order_item_id' => $itemId,
            'historical' => [
                'name' => $item->get_name(), 'product_id' => $item->get_product_id(), 'variation_id' => $item->get_variation_id(),
                'quantity' => $item->get_quantity(), 'subtotal' => (string) $item->get_subtotal(), 'total' => (string) $item->get_total(),
                'meta' => $this->publicItemMeta($item),
            ],
            'current_product' => $this->currentProduct($item->get_product_id(), $item->get_variation_id()),
        ]];
    }

    /** @return array<string, mixed> */
    private function tracking(int $userId, int $orderId): array
    {
        $order = $this->ownedOrder($userId, $orderId);
        if (!$order) {
            return ['ok' => false, 'code' => 'order_not_owned_or_unavailable'];
        }
        // No tracking adapter is certified in the canonical registry for this
        // candidate. An untyped filter payload could mix shipment state with
        // arbitrary extension data, so remain explicitly unavailable until a
        // versioned, allowlisted adapter contract is published.
        return ['ok' => true, 'order_id' => $orderId, 'tracking' => [
            'available' => false,
            'status' => 'unavailable',
            'source' => 'none',
            'reason' => 'approved_tracking_adapter_not_published',
            'observed_at' => gmdate(DATE_ATOM),
        ]];
    }

    /** @return array<string, mixed> */
    private function actionMatrix(int $userId, int $orderId): array
    {
        $order = $this->ownedOrder($userId, $orderId);
        if (!$order) {
            return ['ok' => false, 'code' => 'order_not_owned_or_unavailable'];
        }
        if (!function_exists('wc_get_account_orders_actions')) {
            return ['ok' => false, 'code' => 'customer_action_matrix_unavailable'];
        }
        $actions = wc_get_account_orders_actions($order);
        $allowed = [];
        foreach ($actions as $key => $action) {
            if (!is_string($key) || !is_array($action)) {
                continue;
            }
            $allowed[] = $this->projectAction($key, $action);
        }
        return [
            'ok' => true,
            'order_id' => $orderId,
            'order_version' => $this->orderVersion($order),
            'actions' => $allowed,
            'source' => 'woocommerce_customer_account_actions',
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function validateCancellation(int $userId, int $orderId): array
    {
        $order = $this->ownedOrder($userId, $orderId);
        if (!$order) {
            return ['ok' => false, 'code' => 'order_not_owned_or_unavailable'];
        }
        $matrix = $this->actionMatrix($userId, $orderId);
        if (($matrix['ok'] ?? false) !== true) {
            return $matrix;
        }
        $canCancel = false;
        foreach ($matrix['actions'] as $action) {
            if (($action['action'] ?? null) === 'cancel' && ($action['direct_execution_allowed'] ?? false) === true) {
                $canCancel = true;
                break;
            }
        }
        $preview = [
            'order_id' => $orderId,
            'order_number' => $order->get_order_number(),
            'current_status' => $order->get_status(),
            'total' => (string) $order->get_total(),
            'currency' => $order->get_currency(),
            'customer_action_allowed' => $canCancel,
            'order_version' => $this->orderVersion($order),
        ];
        return [
            'ok' => true,
            'allowed' => $canCancel,
            'preview' => $preview,
            'state_hash' => StateHash::fromPayload($preview)->value(),
            'confirmation_required' => $canCancel,
            'execution_supported' => false,
            'blocking_reason' => $canCancel ? 'confirmed_cancellation_tool_not_published' : null,
            'fallback' => $canCancel ? null : 'crm',
        ];
    }

    /** @param array<string, mixed> $change @return array<string, mixed> */
    private function validateChange(int $userId, int $orderId, string $changeType, array $change): array
    {
        $order = $this->ownedOrder($userId, $orderId);
        if (!$order) {
            return ['ok' => false, 'code' => 'order_not_owned_or_unavailable'];
        }
        $matrix = $this->actionMatrix($userId, $orderId);
        if (($matrix['ok'] ?? false) !== true) {
            return $matrix;
        }
        $supported = apply_filters('veyra_customer_order_change_validation', null, $order, $changeType, $change, $userId);
        if (!is_array($supported) || ($supported['allowed'] ?? false) !== true) {
            return ['ok' => true, 'allowed' => false, 'order_id' => $orderId, 'change_type' => $changeType, 'reason' => 'direct_change_not_supported', 'fallback' => 'crm'];
        }
        $customerAction = is_string($supported['customer_action'] ?? null) ? $supported['customer_action'] : '';
        $actionAllowed = false;
        foreach ($matrix['actions'] as $action) {
            if (($action['action'] ?? null) === $customerAction && ($action['direct_execution_allowed'] ?? false) === true) {
                $actionAllowed = true;
                break;
            }
        }
        if (!$actionAllowed) {
            return [
                'ok' => true,
                'allowed' => false,
                'order_id' => $orderId,
                'change_type' => $changeType,
                'reason' => 'woocommerce_customer_action_not_directly_allowed',
                'fallback' => 'crm',
            ];
        }

        return [
            'ok' => true,
            'allowed' => true,
            'order_id' => $orderId,
            'change_type' => $changeType,
            'customer_action' => $customerAction,
            'order_version' => $matrix['order_version'],
            'validation' => $supported,
            'confirmation_required' => true,
            'execution_supported' => false,
            'blocking_reason' => 'confirmed_order_change_tool_not_published',
            'mutation_executed' => false,
        ];
    }

    private function ownedOrder(int $userId, int $orderId): ?\WC_Order
    {
        $order = wc_get_order($orderId);
        return $order instanceof \WC_Order && $this->owned($order, $userId) && !$this->hiddenCustomerStatus($order) ? $order : null;
    }

    private function ownedOrderByReference(int $userId, string $reference): ?\WC_Order
    {
        if ($reference === '') {
            return null;
        }
        $adapted = function_exists('apply_filters')
            ? apply_filters('veyra_resolve_customer_order_reference', null, $reference, $userId)
            : null;
        $candidate = null;
        $exactReference = null;
        if ($adapted instanceof \WC_Order) {
            $candidate = $adapted;
            $exactReference = (string) $adapted->get_order_number();
        } elseif (is_array($adapted) && ($adapted['order'] ?? null) instanceof \WC_Order && is_string($adapted['exact_reference'] ?? null)) {
            $candidate = $adapted['order'];
            $exactReference = trim($adapted['exact_reference']);
        }
        if ($candidate instanceof \WC_Order
            && hash_equals($reference, (string) $exactReference)
            && $this->owned($candidate, $userId)
            && !$this->hiddenCustomerStatus($candidate)
        ) {
            return $candidate;
        }

        if (ctype_digit($reference)) {
            $candidate = wc_get_order((int) $reference);
            if ($candidate instanceof \WC_Order
                && ($reference === (string) $candidate->get_id() || $reference === (string) $candidate->get_order_number())
                && $this->owned($candidate, $userId)
                && !$this->hiddenCustomerStatus($candidate)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    private function owned(\WC_Order $order, int $userId): bool
    {
        return $order->get_customer_id() === $userId && $userId > 0;
    }

    private function hiddenCustomerStatus(\WC_Order $order): bool
    {
        return in_array($order->get_status(), ['checkout-draft', 'auto-draft', 'trash'], true);
    }

    /** @param array<string, mixed> $action @return array<string, mixed> */
    private function projectAction(string $key, array $action): array
    {
        [$mode, $direct] = match ($key) {
            'cancel' => ['sensitive_direct', true],
            'pay' => ['gateway_handoff', false],
            'view' => ['read', false],
            'order-again' => ['reorder_preview_required', false],
            default => ['customer_handoff', false],
        };

        return [
            'action' => $key,
            'name' => (string) ($action['name'] ?? $key),
            'customer_facing' => true,
            'execution_mode' => $mode,
            'direct_execution_allowed' => $direct,
            'confirmation_required' => $direct,
            'idempotency_required' => $direct,
            'fresh_order_version_required' => $direct,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(\WC_Order $order): array
    {
        return [
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'created_at' => $order->get_date_created()?->date(DATE_ATOM),
            'currency' => $order->get_currency(),
            'total' => (string) $order->get_total(),
            'order_status' => $order->get_status(),
            'payment_status' => $order->is_paid() ? 'paid' : 'not_paid',
            'fulfillment_status' => 'not_available_without_approved_adapter',
            'shipment_status' => 'not_available_without_approved_adapter',
            'tracking_status' => 'not_available_without_approved_adapter',
            'payment_method' => $order->get_payment_method_title(),
            'version' => $this->orderVersion($order),
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    private function orderVersion(\WC_Order $order): string
    {
        return hash('sha256', implode('|', [
            (string) $order->get_id(), (string) $order->get_status(), (string) $order->get_total(),
            (string) $order->get_date_modified()?->getTimestamp(),
        ]));
    }

    /** @return array<int, array<string, string>> */
    private function publicItemMeta(\WC_Order_Item_Product $item): array
    {
        $result = [];
        foreach ($item->get_formatted_meta_data('') as $meta) {
            $result[] = ['key' => wp_strip_all_tags((string) $meta->display_key), 'value' => wp_strip_all_tags((string) $meta->display_value)];
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function currentProduct(int $productId, int $variationId): array
    {
        $product = wc_get_product($variationId > 0 ? $variationId : $productId);
        $parent = $variationId > 0 ? wc_get_product($productId) : $product;
        if (!$product instanceof \WC_Product
            || !$parent instanceof \WC_Product
            || $product->get_status() !== 'publish'
            || !$product->is_visible()
            || $parent->get_status() !== 'publish'
            || !$parent->is_visible()
        ) {
            return ['available' => false];
        }
        return [
            'available' => $product->get_status() === 'publish',
            'product_id' => $productId,
            'variation_id' => $variationId,
            'name' => $product->get_name(),
            'current_price' => (string) $product->get_price(),
            'stock_status' => $product->get_stock_status(),
            'purchasable' => $product->is_purchasable(),
            'observed_at' => gmdate(DATE_ATOM),
        ];
    }

    private function feeTotal(\WC_Order $order): float
    {
        $total = 0.0;
        foreach ($order->get_fees() as $fee) {
            $total += (float) $fee->get_total();
        }
        return $total;
    }
}
