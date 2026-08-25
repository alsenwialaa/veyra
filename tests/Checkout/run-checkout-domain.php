<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use Veyra\Checkout\Application\CheckoutSessionService;
use Veyra\Checkout\Application\CheckoutStateRepository;
use Veyra\Checkout\Application\CheckoutStateConflict;
use Veyra\Checkout\Application\CheckoutInputSanitizer;
use Veyra\Checkout\Domain\CheckoutState;
use Veyra\Checkout\Tool\CheckoutToolHandler;
use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Tool\FoundationActorMapper;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Application\IdempotencyRepository;
use Veyra\Confirmation\Application\LockManager;
use Veyra\Confirmation\Domain\IdempotencyRecord;
use Veyra\Infrastructure\Database\Repository\ActorScope;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Checkout\Support\FakeCheckoutAuthority;
use Veyra\Tests\Checkout\Support\InMemoryCheckoutStateRepository;
use Veyra\Tests\Checkout\Support\InMemoryLockRepository;
use Veyra\Tests\Support\FrozenClock;
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
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$scenario('actor-scoped idempotent checkout open', static function () use ($assert): void {
    $service = new CheckoutSessionService(
        new InMemoryCheckoutStateRepository(),
        new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00')),
        3600
    );
    $actor = new ActorScope('customer', 'wp-user-12');
    $conversation = '55555555-5555-4555-8555-555555555555';
    $first = $service->open($actor, $conversation, str_repeat('a', 64));
    $second = $service->open($actor, $conversation, str_repeat('b', 64));
    $assert($first->id === $second->id, 'Duplicate open created a second checkout state.');
    $assert($service->current(new ActorScope('customer', 'wp-user-13'), $conversation) === null, 'Cross-actor state read succeeded.');
});

$scenario('versioned mutation and canonical digest', static function () use ($assert): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $service = new CheckoutSessionService(new InMemoryCheckoutStateRepository(), $clock, 3600);
    $actor = new ActorScope('customer', 'wp-user-12');
    $conversation = '66666666-6666-4666-8666-666666666666';
    $first = $service->open($actor, $conversation, str_repeat('c', 64));
    $next = $service->mutate($actor, $conversation, 1, static fn (): array => [
        'fulfillment_mode' => 'delivery',
        'contacts' => ['shipping' => ['first_name' => 'Amina']],
    ]);
    $assert($next->version === 2, 'Checkout version did not advance.');
    $assert($next->stateHash()->value() !== $first->stateHash()->value(), 'Material mutation did not change the digest.');
    $row = $next->persistenceValues();
    $assert(\Veyra\Checkout\Domain\CheckoutState::fromRow($row)->stateHash()->equals($next->stateHash()), 'Persisted state did not rehydrate consistently.');

    try {
        $service->mutate($actor, $conversation, 1, static fn (): array => ['fulfillment_mode' => 'pickup']);
        throw new RuntimeException('A stale checkout version was accepted.');
    } catch (CheckoutStateConflict) {
        // Expected compare-and-swap rejection.
    }
});

$scenario('expired checkout is CAS-reopened without carrying sensitive choices', static function () use ($assert): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $repository = new InMemoryCheckoutStateRepository();
    $service = new CheckoutSessionService($repository, $clock, 900);
    $actor = new ActorScope('customer', 'wp-user-14');
    $conversation = '99999999-9999-4999-8999-999999999999';
    $opened = $service->open($actor, $conversation, str_repeat('a', 64));
    $populated = $service->mutate($actor, $conversation, $opened->version, static fn (): array => [
        'contacts' => ['shipping' => ['first_name' => 'Amina']],
        'shipping_address' => ['address_1' => 'Private address'],
        'payment_method_id' => 'cod',
        'status' => 'active',
    ]);
    $clock->advance(901);
    $revived = $service->open($actor, $conversation, str_repeat('b', 64));
    $assert($revived->id === $populated->id && $revived->version === $populated->version + 1, 'Expired checkout was not revived with CAS.');
    $assert($revived->cartHash === str_repeat('b', 64) && $revived->status === 'active', 'Revived checkout did not bind current cart state.');
    $assert($revived->contacts === [] && $revived->shippingAddress === [] && $revived->paymentMethodId === null, 'Expired sensitive checkout choices were carried forward.');
    $assert($service->current($actor, $conversation) !== null, 'Revived checkout remained expired.');
});

$scenario('tool surface is preview-only and state writes are idempotent', static function () use ($assert): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $sessions = new CheckoutSessionService(new InMemoryCheckoutStateRepository(), $clock, 3600);
    $hash = str_repeat('d', 64);
    $handler = new CheckoutToolHandler(
        $sessions,
        new FakeCheckoutAuthority($hash),
        new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('k', 32)), $clock),
        new FoundationActorMapper(),
        new CheckoutInputSanitizer(),
        new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('l', 32)), $clock)
    );
    $context = new ToolContext(
        'customer',
        'wp-user-21',
        21,
        null,
        '77777777-7777-4777-8777-777777777777',
        [],
        ['commerce_chat_checkout' => 'On'],
        'en_US',
        '88888888-8888-4888-8888-888888888888'
    );
    $registry = new ToolRegistry(new ToolInputValidator());
    $registry->register($handler);
    $entry = $registry->execute(new ToolCall('call-entry', 'checkout.get_journey_state', '1.0.0', []), $context);
    $assert($entry->status === 'succeeded', 'Exact checkout entry tool did not open actor-owned state through deterministic preflight.');
    $opened = $sessions->current(new ActorScope('customer', 'wp-user-21'), $context->conversationId);
    $assert($opened !== null, 'Checkout preflight did not persist state.');
    $names = array_map(static fn ($definition): string => $definition->name, $handler->definitions());
    $assert(!in_array('order.place_confirmed', $names, true), 'Sensitive order placement leaked into the checkout tool handler.');
    $assert(!in_array('payment.create_gateway_handoff', $names, true), 'Gateway execution leaked into the checkout tool handler.');
    $preview = $handler->execute(new ToolCall('call-preview', 'checkout.get_preview', '1.0.0', []), $context);
    $assert($preview->status === 'succeeded' && ($preview->data['preview_complete'] ?? false) === true, 'Authoritative preview was not reported complete.');
    $assert(($preview->data['ready_for_confirmation'] ?? true) === false, 'Preview claimed confirmation readiness without a placement executor.');
    $assert(($preview->data['execution_supported'] ?? true) === false, 'Preview claimed unsupported execution.');
    $assert(($preview->data['blocking_reasons'][0] ?? '') === 'order_placement_tool_not_published', 'Preview omitted the truthful placement blocker.');

    $call = new ToolCall('call-1', 'checkout.select_fulfillment_mode', '1.0.0', [
        'fulfillment_mode' => 'delivery',
        'expected_version' => $opened->version,
        'idempotency_key' => 'checkout-request-0001',
    ]);
    $result = $handler->execute($call, $context);
    $assert($result->status === 'succeeded', 'Valid fulfillment selection did not succeed.');
    $replay = $handler->execute(new ToolCall('call-2', $call->name, '1.0.0', $call->arguments), $context);
    $assert($replay->status === 'succeeded' && ($replay->data['idempotent_replay'] ?? false) === true, 'Identical checkout retry was not replayed safely.');
});

$scenario('authority selection is compensated on CAS loss or reported uncertain', static function () use ($assert): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $hash = str_repeat('f', 64);
    $context = new ToolContext(
        'customer',
        'wp-user-22',
        22,
        null,
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        [],
        ['commerce_chat_checkout' => 'On'],
        'en_US',
        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'
    );
    $actor = new ActorScope('customer', 'wp-user-22');

    $repository = new InMemoryCheckoutStateRepository();
    $sessions = new CheckoutSessionService($repository, $clock, 3600);
    $opened = $sessions->open($actor, $context->conversationId, $hash);
    $authority = new FakeCheckoutAuthority($hash);
    $authority->seedSelections(['legacy_rate'], 'bank_transfer');
    $handler = new CheckoutToolHandler(
        $sessions,
        $authority,
        new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('k', 32)), $clock),
        new FoundationActorMapper(),
        new CheckoutInputSanitizer(),
        new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('l', 32)), $clock)
    );
    $repository->failNextSave();
    $stale = $handler->execute(new ToolCall('call-cas-loss', 'checkout.select_payment_method', '1.0.0', [
        'payment_method_id' => 'cod',
        'expected_version' => $opened->version,
        'idempotency_key' => 'checkout-cas-loss-0001',
    ]), $context);
    $assert($stale->status === 'stale' && $stale->code === 'checkout_version_stale', 'Proven CAS loss was not returned as stale.');
    $assert($stale->retrySafe === true, 'Verified compensation was not marked retry-safe.');
    $assert($authority->selectionWriteCount() === 1 && $authority->restoreAttemptCount() === 1, 'Authority mutation was not followed by one compensation attempt.');
    $assert($authority->currentSelections() === [
        'shipping_methods' => ['legacy_rate'],
        'payment_method_id' => 'bank_transfer',
    ], 'Verified compensation did not restore the exact prior Woo selection.');
    $current = $sessions->current($actor, $context->conversationId);
    $assert($current !== null && $current->version === $opened->version && $current->paymentMethodId === null, 'CAS failure mutated persisted checkout state.');
    $assert(($stale->data['authority_reconciliation']['compensation_verified'] ?? false) === true, 'Verified compensation evidence was omitted.');

    $repository = new InMemoryCheckoutStateRepository();
    $sessions = new CheckoutSessionService($repository, $clock, 3600);
    $opened = $sessions->open($actor, $context->conversationId, $hash);
    $authority = new FakeCheckoutAuthority($hash);
    $authority->seedSelections(['legacy_rate'], 'bank_transfer');
    $authority->failSelectionRestore();
    $handler = new CheckoutToolHandler(
        $sessions,
        $authority,
        new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('m', 32)), $clock),
        new FoundationActorMapper(),
        new CheckoutInputSanitizer(),
        new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('n', 32)), $clock)
    );
    $repository->failNextSave();
    $uncertain = $handler->execute(new ToolCall('call-cas-uncertain', 'checkout.select_payment_method', '1.0.0', [
        'payment_method_id' => 'cod',
        'expected_version' => $opened->version,
        'idempotency_key' => 'checkout-cas-loss-0002',
    ]), $context);
    $assert($uncertain->status === 'uncertain' && $uncertain->code === 'checkout_session_reconciliation_required', 'Unverified compensation was not fail-closed as uncertain.');
    $assert($uncertain->retrySafe === false, 'Unverified authority state was incorrectly marked retry-safe.');
    $assert($authority->currentSelections()['payment_method_id'] === 'cod', 'Failed compensation unexpectedly changed the simulated authority state.');
    $assert(($uncertain->data['authority_reconciliation']['compensation_verified'] ?? true) === false, 'Unverified compensation evidence was omitted.');
    $assert(($uncertain->data['authority_reconciliation']['reconciliation_required'] ?? false) === true, 'Reconciliation requirement was not preserved.');
    $snapshotHash = $uncertain->data['authority_reconciliation']['selection_snapshot_hash'] ?? '';
    $assert(
        is_string($snapshotHash)
            && strlen($snapshotHash) === 64
            && strspn($snapshotHash, '0123456789abcdef') === 64,
        'Opaque prior-selection evidence was not preserved.'
    );
});

$scenario('unsupported required extension fields fail closed to standard checkout', static function () use ($assert): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $hash = str_repeat('9', 64);
    $sessions = new CheckoutSessionService(new InMemoryCheckoutStateRepository(), $clock, 3600);
    $authority = new FakeCheckoutAuthority($hash);
    $authority->requireUnsupportedFields(['billing_vat_number']);
    $handler = new CheckoutToolHandler(
        $sessions,
        $authority,
        new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('k', 32)), $clock),
        new FoundationActorMapper(),
        new CheckoutInputSanitizer(),
        new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('l', 32)), $clock)
    );
    $context = new ToolContext(
        'customer',
        'wp-user-24',
        24,
        null,
        'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        [],
        ['commerce_chat_checkout' => 'On'],
        'en_US',
        'dddddddd-dddd-4ddd-8ddd-dddddddddddd'
    );
    $registry = new ToolRegistry(new ToolInputValidator());
    $registry->register($handler);
    $entry = $registry->execute(new ToolCall('call-extension-entry', 'checkout.get_journey_state', '1.0.0', []), $context);
    $assert($entry->status === 'blocked', 'Unsupported extension field did not block conversational checkout entry.');
    $assert($entry->code === 'checkout_extension_fields_require_standard_handoff', 'Unsupported field did not produce an explicit standard-checkout handoff.');
    $assert(($entry->data['standard_checkout_handoff_required'] ?? false) === true, 'Required handoff was omitted from the typed result.');
});

$scenario('checkout denies a Woo session bound to another customer', static function () use ($assert): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $hash = str_repeat('7', 64);
    $authority = new FakeCheckoutAuthority($hash);
    $authority->bindActor(91);
    $handler = new CheckoutToolHandler(
        new CheckoutSessionService(new InMemoryCheckoutStateRepository(), $clock, 3600),
        $authority,
        new IdempotencyService(new InMemoryIdempotencyRepository(), new SecretDigester(str_repeat('q', 32)), $clock),
        new FoundationActorMapper(),
        new CheckoutInputSanitizer(),
        new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('r', 32)), $clock)
    );
    $context = new ToolContext(
        'customer',
        'wp-user-92',
        92,
        null,
        'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        [],
        ['commerce_chat_checkout' => 'On'],
        'en_US',
        'ffffffff-ffff-4fff-8fff-ffffffffffff'
    );
    $result = $handler->execute(
        new ToolCall('call-wrong-woo-actor', 'checkout.get_journey_state', '1.0.0', []),
        $context
    );
    $assert($result->status === 'blocked', 'Mismatched Woo customer/session was not denied.');
    $assert($result->code === 'checkout_actor_binding_invalid', 'Wrong actor-binding denial code was returned.');
});

$scenario('reconciliation contains a checkout-state read failure', static function () use ($assert): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $actor = new ActorScope('customer', 'wp-user-93');
    $conversationId = '12121212-1212-4121-8121-121212121212';
    $hash = str_repeat('8', 64);
    $seedSessions = new CheckoutSessionService(new InMemoryCheckoutStateRepository(), $clock, 3600);
    $state = $seedSessions->open($actor, $conversationId, $hash);
    $states = new class($state) implements CheckoutStateRepository {
        private int $reads = 0;

        public function __construct(private readonly CheckoutState $state)
        {
        }

        public function findForConversation(ActorScope $actor, string $conversationId): ?CheckoutState
        {
            unset($actor, $conversationId);
            ++$this->reads;
            if ($this->reads === 1) {
                return $this->state;
            }

            throw new RuntimeException('simulated checkout repository outage');
        }

        public function findById(ActorScope $actor, string $checkoutId): ?CheckoutState
        {
            unset($actor, $checkoutId);
            return null;
        }

        public function insert(CheckoutState $state): bool
        {
            unset($state);
            return false;
        }

        public function save(CheckoutState $state, int $expectedVersion): bool
        {
            unset($state, $expectedVersion);
            return false;
        }
    };
    $records = new class implements IdempotencyRepository {
        private ?IdempotencyRecord $attempt = null;

        public function insert(IdempotencyRecord $record): bool
        {
            $this->attempt = $record;
            return false;
        }

        public function find(ActorScope $actor, string $action, string $keyDigest): ?IdempotencyRecord
        {
            unset($actor, $action, $keyDigest);
            $record = $this->attempt;
            if (!$record instanceof IdempotencyRecord) {
                return null;
            }

            return new IdempotencyRecord(
                $record->id,
                $record->keyDigest,
                $record->actor,
                $record->action,
                $record->resourceScopeHash,
                $record->payloadHash,
                'uncertain',
                'checkout_mutation_uncertain',
                [],
                false,
                $record->correlationId,
                $record->version,
                $record->expiresAt,
                null,
                $record->createdAt,
                $record->updatedAt
            );
        }

        public function complete(
            IdempotencyRecord $record,
            string $status,
            string $resultCode,
            array $result,
            bool $retrySafe,
            UtcInstant $completedAt
        ): bool {
            unset($record, $status, $resultCode, $result, $retrySafe, $completedAt);
            return false;
        }
    };
    $context = new ToolContext(
        'customer',
        'wp-user-93',
        93,
        null,
        $conversationId,
        [],
        ['commerce_chat_checkout' => 'On'],
        'en_US',
        '34343434-3434-4343-8343-343434343434'
    );
    $handler = new CheckoutToolHandler(
        new CheckoutSessionService($states, $clock, 3600),
        new FakeCheckoutAuthority($hash),
        new IdempotencyService($records, new SecretDigester(str_repeat('s', 32)), $clock),
        new FoundationActorMapper(),
        new CheckoutInputSanitizer(),
        new LockManager(new InMemoryLockRepository(), new SecretDigester(str_repeat('t', 32)), $clock)
    );
    $result = $handler->execute(new ToolCall(
        'call-reconcile-read-failure',
        'checkout.select_fulfillment_mode',
        '1.0.0',
        [
            'fulfillment_mode' => 'delivery',
            'expected_version' => $state->version,
            'idempotency_key' => 'checkout-reconcile-0001',
        ]
    ), $context);

    $assert($result->status === 'uncertain', 'Reconciliation state-read failure escaped or became terminal.');
    $assert($result->code === 'checkout_reconciliation_required', 'Reconciliation state-read failure changed the safe public code.');
    $assert(
        array_key_exists('checkout', $result->data) && $result->data['checkout'] === null,
        'Unavailable reconciliation state was not represented as null.'
    );
    $assert($result->retrySafe === false, 'Unverified reconciliation was marked retry-safe.');
});

fwrite(STDOUT, sprintf("Checkout domain scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
