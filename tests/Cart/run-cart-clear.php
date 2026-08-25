<?php

declare(strict_types=1);

class wpdb
{
    public bool $failTransactionStart = false;
    public function query(string $sql): int|false
    {
        return $this->failTransactionStart && $sql === 'START TRANSACTION' ? false : 1;
    }
}

class WC_Product
{
    public function __construct(private string $name = 'Product', private string $price = '10.00') {}
    public function get_name(): string { return $this->name; }
    public function get_price(): string { return $this->price; }
    public function get_min_purchase_quantity(): int { return 1; }
    public function get_max_purchase_quantity(): int { return 99; }
    public function is_purchasable(): bool { return true; }
    public function is_in_stock(): bool { return true; }
    public function has_enough_stock(float $quantity): bool { return $quantity <= 99; }
}

final class VeyraFakeCart
{
    public int $emptyCount = 0;
    public int $calculateCount = 0;
    public int $setQuantityCount = 0;

    /** @param array<string, array<string, mixed>> $lines */
    public function __construct(private array $lines, private array $coupons = ['SAVE10' => true]) {}
    public function get_cart(): array { return $this->lines; }
    public function get_totals(): array {
        return [
            'subtotal' => $this->lines === [] ? '0' : '10.00',
            'discount_total' => $this->coupons === [] ? '0' : '1.00',
            'shipping_total' => '0', 'shipping_tax' => '0', 'fee_total' => '0', 'total_tax' => '0',
            'total' => $this->lines === [] ? '0' : '9.00',
        ];
    }
    public function get_cart_hash(): string { return hash('sha256', serialize([$this->lines, $this->coupons])); }
    public function get_cart_contents_count(): int { return array_sum(array_map(static fn (array $line): int => (int) $line['quantity'], $this->lines)); }
    public function get_total(string $context = 'view'): string { return (string) $this->get_totals()['total']; }
    public function get_coupons(): array { return $this->coupons; }
    /** @param array<string, array<string, mixed>> $lines */
    public function replaceState(array $lines, array $coupons): void { $this->lines = $lines; $this->coupons = $coupons; }
    public function empty_cart(bool $clearPersistentCart = true): void { ++$this->emptyCount; $this->lines = []; $this->coupons = []; }
    public function calculate_totals(): void { ++$this->calculateCount; }
    public function get_cart_for_session(): array { return $this->lines; }
    public function set_quantity(string $lineId, float $quantity, bool $refreshTotals = true): bool {
        ++$this->setQuantityCount;
        if (!isset($this->lines[$lineId])) { return false; }
        if ($quantity <= 0) {
            unset($this->lines[$lineId]);
        } else {
            $this->lines[$lineId]['quantity'] = $quantity;
        }
        return true;
    }
}

final class VeyraFakeSession
{
    public array $values = [];
    public function __construct(private string $customerId) {}
    public function get_customer_id(): string { return $this->customerId; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
}

final class VeyraFakeCustomer
{
    public function __construct(private int $id) {}
    public function get_id(): int { return $this->id; }
}

/** @var object|null $veyraWoo */
$veyraWoo = null;
/** @var int $veyraCurrentUserId */
$veyraCurrentUserId = 0;
function WC(): ?object { global $veyraWoo; return $veyraWoo; }
function get_current_user_id(): int { global $veyraCurrentUserId; return $veyraCurrentUserId; }
function is_user_logged_in(): bool { return get_current_user_id() > 0; }
function get_woocommerce_currency(): string { return 'USD'; }
function sanitize_title(string $value): string { return strtolower(trim($value)); }

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Orchestration\WooAuthoritativeContextProvider;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\Audit\Application\AuditRepository;
use Veyra\Audit\Application\AuditWriter;
use Veyra\Audit\Domain\AuditEvent;
use Veyra\Cart\Tool\CartToolHandler;
use Veyra\Confirmation\Application\ConfirmationService;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\LockRepository;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Confirmation\Application\SensitiveActionGate;
use Veyra\Confirmation\Domain\ConfirmationRequest;
use Veyra\Confirmation\Domain\LockRecord;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Infrastructure\Database\WpdbTransactionManager;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Checkout\Support\InMemoryLockRepository;
use Veyra\Tests\Support\FrozenClock;
use Veyra\Tests\Support\InMemoryConfirmationRepository;
use Veyra\Tests\Support\InMemoryIdempotencyRepository;

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
    if (!$condition) { throw new RuntimeException($message); }
};

$scenario('confirmed clear consumes exact state once, recalculates and invalidates checkout', static function () use ($assert): void {
    global $veyraWoo, $veyraCurrentUserId;
    $originalLines = [
        'line-a' => [
            'product_id' => 7, 'variation_id' => 0, 'quantity' => 1,
            'variation' => [], 'data' => new WC_Product('Widget'),
            'line_subtotal' => '10.00', 'line_total' => '9.00', 'line_tax' => '0',
        ],
    ];
    $cart = new VeyraFakeCart($originalLines);
    $veyraCurrentUserId = 9;
    $veyraWoo = (object) [
        'cart' => $cart,
        'session' => new VeyraFakeSession('9'),
        'customer' => new VeyraFakeCustomer(9),
    ];
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $auditRepository = new class implements AuditRepository {
        public int $count = 0;
        public function append(AuditEvent $event): bool { ++$this->count; return true; }
        public function listForActor(ActorScope $actor, int $limit = 50): array { return []; }
    };
    $audit = new AuditWriter($auditRepository, $clock);
    $confirmations = new ConfirmationService(new InMemoryConfirmationRepository(), new SecretDigester(str_repeat('c', 32)), $clock);
    $idempotency = new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('i', 32)), $clock);
    $locks = new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('l', 32)), $clock);
    $gate = new SensitiveActionGate(new WpdbTransactionManager(new wpdb()), $confirmations, $idempotency, $audit);
    $mapper = new FoundationActorMapper();
    $handler = new CartToolHandler($idempotency, $mapper, null, $gate, $locks, $audit);
    $context = new ToolContext(
        'customer', 'wp-user-9', 9, null,
        '11111111-1111-4111-8111-111111111111', [], ['commerce_cart' => 'On'], 'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $preview = $handler->execute(new ToolCall('preview', 'cart.clear_preview', '1.0.0', []), $context);
    $assert($preview->status === 'succeeded' && ($preview->data['preview']['line_count'] ?? 0) === 1, 'Clear preview was not exact.');
    $issued = $confirmations->create($mapper->map($context), new ConfirmationRequest(
        'cart.clear_confirmed',
        ['scope' => $preview->data['confirmation_scope']],
        $preview->data['preview'],
        new StateHash($preview->data['state_hash']),
        'summary-message-1',
        1,
        ['cart_clear_acknowledged'],
        $preview->data['confirmation_scope'],
        new CorrelationId($context->correlationId),
        null,
        $context->conversationId
    ));
    $arguments = [
        'confirmation_token' => $issued->token,
        'preview_state_hash' => $preview->data['state_hash'],
        'idempotency_key' => 'cart-clear-request-0001',
    ];
    $cart->replaceState([
        'line-b' => [
            'product_id' => 8, 'variation_id' => 0, 'quantity' => 1,
            'variation' => [], 'data' => new WC_Product('Changed widget'),
            'line_subtotal' => '12.00', 'line_total' => '12.00', 'line_tax' => '0',
        ],
    ], []);
    $staleArguments = $arguments;
    $staleArguments['idempotency_key'] = 'cart-clear-stale-0001';
    $stale = $handler->execute(new ToolCall('stale', 'cart.clear_confirmed', '1.0.0', $staleArguments), $context);
    $assert($stale->status === 'stale' && $stale->code === 'cart_clear_preview_stale', 'Fresh request bypassed authoritative preview-state validation.');
    $assert($cart->emptyCount === 0 && $auditRepository->count === 0, 'Stale fresh request consumed confirmation or mutated the cart.');
    $cart->replaceState($originalLines, ['SAVE10' => true]);
    $result = $handler->execute(new ToolCall('clear', 'cart.clear_confirmed', '1.0.0', $arguments), $context);
    $assert($result->status === 'succeeded' && $result->code === 'cart_cleared', 'Confirmed clear did not report verified success.');
    $assert($cart->emptyCount === 1 && $cart->calculateCount === 1 && $cart->get_cart() === [], 'Cart was not cleared with one final recalculation.');
    $assert(($result->data['dependent_state_invalidated']['checkout_state'] ?? '') === 'stale', 'Checkout dependency invalidation was omitted.');
    $assert(is_string($result->data['audit_reference'] ?? null) && $auditRepository->count === 2, 'Required confirmation and clear audits were not persisted.');
    $replay = $handler->execute(new ToolCall('replay', 'cart.clear_confirmed', '1.0.0', $arguments), $context);
    $assert($replay->status === 'succeeded' && ($replay->data['idempotent_replay'] ?? false) === true, 'Exact retry was not replayed.');
    $assert($cart->emptyCount === 1, 'Idempotent retry cleared the cart twice.');
});

$scenario('sensitive confirmation infrastructure failure remains uncertain end to end', static function () use ($assert): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $database = new wpdb();
    $database->failTransactionStart = true;
    $auditRepository = new class implements AuditRepository {
        public function append(AuditEvent $event): bool { return true; }
        public function listForActor(ActorScope $actor, int $limit = 50): array { return []; }
    };
    $confirmations = new ConfirmationService(new InMemoryConfirmationRepository(), new SecretDigester(str_repeat('c', 32)), $clock);
    $idempotency = new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('i', 32)), $clock);
    $gate = new SensitiveActionGate(
        new WpdbTransactionManager($database),
        $confirmations,
        $idempotency,
        new AuditWriter($auditRepository, $clock)
    );
    $mapper = new FoundationActorMapper();
    $context = new ToolContext(
        'customer', 'wp-user-9', 9, null,
        '11111111-1111-4111-8111-111111111111', [], ['commerce_cart' => 'On'], 'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $gateResult = $gate->begin(
        $mapper->map($context),
        str_repeat('t', 32),
        StateHash::fromPayload(['cart' => 'current']),
        'cart.clear_confirmed',
        'cart-clear-gate-failure-0001',
        ['cart' => 'current'],
        'cart:customer:9',
        new CorrelationId($context->correlationId)
    );
    $assert($gateResult->status === 'uncertain', 'A transaction-start failure escaped or became a safe denial.');
    $assert($gateResult->code === 'sensitive_action_gate_outcome_uncertain', 'Sensitive gate uncertainty lost its stable code.');

    $handler = new CartToolHandler($idempotency, $mapper);
    $method = new ReflectionMethod(CartToolHandler::class, 'clearGateResult');
    $method->setAccessible(true);
    $toolResult = $method->invoke(
        $handler,
        new ToolCall('gate-failure', 'cart.clear_confirmed', '1.0.0', []),
        $context,
        $gateResult->status,
        $gateResult->code,
        null
    );
    $assert($toolResult->status === 'uncertain' && $toolResult->retrySafe === false, 'Cart downgraded sensitive-gate uncertainty to a retry-safe denial.');
});

$scenario('cart handler denies guests and mismatched Woo sessions before any cart access', static function () use ($assert): void {
    global $veyraWoo, $veyraCurrentUserId;
    $cart = new VeyraFakeCart([]);
    $veyraCurrentUserId = 9;
    $veyraWoo = (object) [
        'cart' => $cart,
        'session' => new VeyraFakeSession('8'),
        'customer' => new VeyraFakeCustomer(9),
    ];
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $repository = new InMemoryIdempotencyRepository();
    $handler = new CartToolHandler(
        new IdempotencyService($repository, new SecretDigester(str_repeat('g', 32)), $clock),
        new FoundationActorMapper()
    );
    $definitions = $handler->definitions();
    foreach ($definitions as $definition) {
        $assert($definition->actors === ['customer'], $definition->name . ' remained available to a guest actor.');
    }

    $guest = new ToolContext(
        'guest', 'guest-session-1', null, 'guest-session-1',
        '11111111-1111-4111-8111-111111111111', [], ['commerce_cart' => 'On'], 'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $guestResult = $handler->execute(new ToolCall('guest-cart', 'cart.get', '1.0.0', []), $guest);
    $assert(
        $guestResult->status === 'blocked' && $guestResult->code === 'cart_authenticated_customer_required',
        'Guest actor reached the process-global Woo cart.'
    );

    $customer = new ToolContext(
        'customer', 'wp-user-9', 9, null,
        '11111111-1111-4111-8111-111111111111', [], ['commerce_cart' => 'On'], 'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $mismatch = $handler->execute(new ToolCall(
        'mismatched-add',
        'cart.add_item',
        '1.0.0',
        ['product_id' => 7, 'quantity' => 1, 'idempotency_key' => 'mismatched-session-1']
    ), $customer);
    $assert(
        $mismatch->status === 'blocked' && $mismatch->code === 'cart_actor_binding_mismatch',
        'A Woo session bound to another principal reached cart execution.'
    );
    $assert($repository->count() === 0, 'Denied cart execution created an idempotency record.');
    $assert($cart->get_cart() === [], 'Denied cart execution changed the Woo cart.');

    $authority = new WooAuthoritativeContextProvider();
    $guestContext = $authority->commerce($guest);
    $assert(
        ($guestContext['cart']['available'] ?? true) === false,
        'Context assembly exposed a process-global Woo cart to a guest actor.'
    );
    $mismatchedContext = $authority->commerce($customer);
    $assert(
        ($mismatchedContext['cart']['available'] ?? true) === false
            && ($mismatchedContext['version'] ?? null) === 'woo_actor_binding_unavailable',
        'Context assembly exposed a process-global cart from a mismatched Woo session.'
    );
    $veyraWoo->session = new VeyraFakeSession('9');
    $boundContext = $authority->commerce($customer);
    $assert(
        ($boundContext['cart']['available'] ?? false) === true
            && ($boundContext['freshness'] ?? null) === 'current',
        'An exact WordPress, Woo customer and Woo session binding did not expose authoritative cart context.'
    );
});

$scenario('ordinary writes require one actor-wide cart lease and replay without a second mutation', static function () use ($assert): void {
    global $veyraWoo, $veyraCurrentUserId;
    $lines = [
        'line-a' => [
            'product_id' => 7, 'variation_id' => 0, 'quantity' => 1,
            'variation' => [], 'data' => new WC_Product('Widget'),
            'line_subtotal' => '10.00', 'line_total' => '10.00', 'line_tax' => '0',
        ],
    ];
    $cart = new VeyraFakeCart($lines, []);
    $veyraCurrentUserId = 9;
    $veyraWoo = (object) [
        'cart' => $cart,
        'session' => new VeyraFakeSession('9'),
        'customer' => new VeyraFakeCustomer(9),
    ];
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $idempotencyRepository = new InMemoryIdempotencyRepository();
    $idempotency = new IdempotencyService($idempotencyRepository, new SecretDigester(str_repeat('w', 32)), $clock);
    $lockRepository = new InMemoryLockRepository();
    $handler = new CartToolHandler(
        $idempotency,
        new FoundationActorMapper(),
        null,
        null,
        new LockManager($lockRepository, new SecretDigester(str_repeat('x', 32)), $clock)
    );
    $context = new ToolContext(
        'customer', 'wp-user-9', 9, null,
        '11111111-1111-4111-8111-111111111111', [], ['commerce_cart' => 'On'], 'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $call = new ToolCall('write', 'cart.update_quantity', '1.0.0', [
        'line_id' => 'line-a',
        'quantity' => 2,
        'idempotency_key' => 'cart-write-lock-0001',
    ]);
    $result = $handler->execute($call, $context);
    $assert($result->status === 'succeeded' && $result->code === 'cart_quantity_updated', 'Locked ordinary write did not succeed.');
    $assert($cart->setQuantityCount === 1 && $cart->calculateCount === 1, 'Ordinary write did not mutate and recalculate exactly once.');
    $assert(($cart->get_cart()['line-a']['quantity'] ?? 0) === 2.0, 'Verified cart quantity was not persisted.');
    $replay = $handler->execute($call, $context);
    $assert($replay->status === 'succeeded' && ($replay->data['idempotent_replay'] ?? false) === true, 'Ordinary write retry was not replayed.');
    $assert($cart->setQuantityCount === 1 && $cart->calculateCount === 1, 'Idempotent replay repeated a Woo cart mutation.');

    $denyingLocks = new class implements LockRepository {
        public int $attempts = 0;
        public function acquire(LockRecord $candidate, UtcInstant $now): ?LockRecord { ++$this->attempts; return null; }
        public function release(LockRecord $record): bool { return false; }
        public function refresh(LockRecord $record, UtcInstant $newExpiry, UtcInstant $now): ?LockRecord { return null; }
    };
    $blockedRepository = new InMemoryIdempotencyRepository();
    $blockedHandler = new CartToolHandler(
        new IdempotencyService($blockedRepository, new SecretDigester(str_repeat('y', 32)), $clock),
        new FoundationActorMapper(),
        null,
        null,
        new LockManager($denyingLocks, new SecretDigester(str_repeat('z', 32)), $clock)
    );
    $blocked = $blockedHandler->execute(new ToolCall('blocked-write', 'cart.update_quantity', '1.0.0', [
        'line_id' => 'line-a',
        'quantity' => 3,
        'idempotency_key' => 'cart-write-lock-0002',
    ]), $context);
    $assert($blocked->status === 'blocked' && $blocked->code === 'cart_write_lock_unavailable', 'Cart write did not fail closed on lease contention.');
    $assert($denyingLocks->attempts === 1 && $blockedRepository->count() === 0, 'Lease contention left an in-progress idempotency claim.');
    $assert($cart->setQuantityCount === 1 && ($cart->get_cart()['line-a']['quantity'] ?? 0) === 2.0, 'Contended write reached Woo cart authority.');
});

fwrite(STDOUT, sprintf("Cart clear scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
