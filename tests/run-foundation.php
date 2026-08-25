<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** @var list<string> $veyraRuntimeHooks */
$veyraRuntimeHooks = [];
if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        unset($callback, $priority, $acceptedArgs);
        global $veyraRuntimeHooks;
        $veyraRuntimeHooks[] = $hook;
        return true;
    }
}

use Veyra\Audit\Application\SafeAuditMetadata;
use Veyra\AI\Tool\ToolInputValidator;
use Veyra\AI\Tool\ToolContext;
use Veyra\AI\Tool\ToolDefinition;
use Veyra\AI\Tool\ToolHandler;
use Veyra\AI\Tool\ToolRegistry;
use Veyra\AI\Tool\TurnMutationGuard;
use Veyra\AI\Contract\ToolCall;
use Veyra\AI\Contract\ProviderFunctionResult;
use Veyra\AI\Contract\ProviderRequest;
use Veyra\AI\Provider\ProviderPayloadValidator;
use Veyra\AI\Provider\ProviderReleaseGate;
use Veyra\AI\Provider\GeminiStatelessContinuation;
use Veyra\AI\Provider\GeminiInteractionResponse;
use Veyra\AI\Provider\RouteManifest;
use Veyra\AI\Orchestration\ServerComponentBuilder;
use Veyra\AI\Orchestration\MutationFailureOutcome;
use Veyra\AI\Orchestration\ResponseVerifier;
use Veyra\AI\Contract\ToolResult;
use Veyra\Conversation\Application\ConversationStateUpdater;
use Veyra\Cart\Domain\MutationPlanOutcome;
use Veyra\Cart\Tool\CartToolHandler;
use Veyra\Checkout\Tool\CheckoutToolHandler;
use Veyra\Bootstrap\EnvironmentSnapshot;
use Veyra\Bootstrap\CompatibilityIssue;
use Veyra\Bootstrap\CompatibilityReport;
use Veyra\Bootstrap\Container;
use Veyra\Bootstrap\RuntimeCompatibility;
use Veyra\Confirmation\Application\ConfirmationService;
use Veyra\Confirmation\Application\IdempotencyService;
use Veyra\Confirmation\Domain\ConfirmationRequest;
use Veyra\Confirmation\Domain\IdempotencyDecisionStatus;
use Veyra\Context\Tool\ContextToolHandler;
use Veyra\Features\Application\EffectiveFeatureStateService;
use Veyra\Features\Application\RuntimeFeatureRegistry;
use Veyra\Features\Domain\FeatureKey;
use Veyra\Features\Domain\FeatureRegistry;
use Veyra\Features\Domain\FeatureState;
use Veyra\Features\Domain\ReleaseUnit;
use Veyra\Identity\Application\CapabilityPolicy;
use Veyra\Identity\Domain\Actor;
use Veyra\Identity\Domain\ActorId;
use Veyra\Identity\Domain\ActorType;
use Veyra\Identity\Domain\Capability;
use Veyra\Identity\Domain\CapabilityRegistry;
use Veyra\Requirements\Application\RequirementStateService;
use Veyra\Requirements\Tool\RequirementsToolHandler;
use Veyra\Runtime\RuntimeModule;
use Veyra\Shared\Application\OperationResult;
use Veyra\Shared\Domain\CanonicalJson;
use Veyra\Shared\Domain\CorrelationId;
use Veyra\Shared\Domain\SecretDigester;
use Veyra\Shared\Domain\StateHash;
use Veyra\Shared\Domain\UtcInstant;
use Veyra\Tests\Support\FrozenClock;
use Veyra\Tests\Support\InMemoryConfirmationRepository;
use Veyra\Tests\Support\InMemoryFeatureConfigurationStore;
use Veyra\Tests\Support\InMemoryIdempotencyRepository;
use Veyra\Tests\Support\InMemoryRequirementStateRepository;
use Veyra\Tests\Support\MemoryConversationStore;
use Veyra\Tests\Support\InMemoryGuestSessionRepository;
use Veyra\Identity\Application\GuestSessionManager;

$passed = 0;
$failed = 0;

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$test = static function (string $name, callable $scenario) use (&$passed, &$failed): void {
    try {
        $scenario();
        ++$passed;
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
};

$test('canonical state hash and operation envelope', static function () use ($check): void {
    $left = StateHash::fromPayload(['b' => 2, 'a' => ['y' => 2, 'x' => 1]]);
    $right = StateHash::fromPayload(['a' => ['x' => 1, 'y' => 2], 'b' => 2]);
    $check($left->equals($right), 'Associative key order changed the state hash.');
    $check(CanonicalJson::encode(['b' => 2, 'a' => 1]) === '{"a":1,"b":2}', 'Canonical JSON order is unstable.');
    $result = OperationResult::succeeded('foundation_ok', ['ready' => true], CorrelationId::generate());
    $check($result->jsonSerialize()['schema_version'] === '1.0.0', 'Result schema version is missing.');
});

$test('28 independently grantable capabilities', static function () use ($check): void {
    $check(count(CapabilityRegistry::names()) === 28, 'Canonical capability count changed.');
    $actor = new Actor(new ActorId('wp-user-8'), ActorType::Staff, 8, null, ['view_veyra_conversations']);
    $policy = new CapabilityPolicy();
    $check($policy->allows($actor, new Capability('view_veyra_conversations')), 'Granted capability was denied.');
    $check(!$policy->allows($actor, new Capability('send_veyra_support_messages')), 'Viewing implied messaging authority.');
});

$test('feature registry counts and fail-closed state', static function () use ($check): void {
    $registry = FeatureRegistry::canonical();
    $check(count($registry->byReleaseUnit(ReleaseUnit::ProductionCore)) === 20, 'Core feature count changed.');
    $check(count($registry->byReleaseUnit(ReleaseUnit::OptionalModule)) === 17, 'Optional feature count changed.');
    $states = new EffectiveFeatureStateService(
        $registry,
        new InMemoryFeatureConfigurationStore(),
        new RuntimeFeatureRegistry()
    );
    $check(
        $states->get(new FeatureKey('ai_semantic_orchestration'))->state === FeatureState::Blocked,
        'Unimplemented semantic orchestration did not fail closed.'
    );
});

$test('customer PII profile is excluded from model-visible tools', static function () use ($check): void {
    $handler = new ContextToolHandler(new MemoryConversationStore('message-1', 'profile request'));
    $definitions = [];
    foreach ($handler->definitions() as $definition) {
        $definitions[$definition->name] = $definition;
    }

    $check(isset($definitions['identity.get_customer_profile']), 'Customer profile definition is missing.');
    $check(
        $definitions['identity.get_customer_profile']->modelVisible === false,
        'Full customer PII profile was exposed to the model.'
    );
    $check(
        $definitions['identity.get_current_actor']->modelVisible === true,
        'The non-PII current actor projection was unexpectedly hidden.'
    );
});

$test('missing Woo is graceful and newer Woo has no upper restriction', static function () use ($check): void {
    $compatibility = new RuntimeCompatibility();
    $missing = $compatibility->evaluate(new EnvironmentSnapshot('8.2.0', '6.9.0', null, true));
    $check($missing->foundationReady() && !$missing->commerceReady(), 'Missing Woo broke the wrong scope.');
    $future = $compatibility->evaluate(new EnvironmentSnapshot('8.3.0', '7.0.0', '99.0.0', true));
    $check($future->commerceReady(), 'An arbitrary Woo upper bound was applied.');
});

$test('abbreviated stable releases satisfy minimums while prereleases remain blocked', static function () use ($check): void {
    $compatibility = new RuntimeCompatibility();
    $stable = $compatibility->evaluate(new EnvironmentSnapshot('8.1', '6.5', '8.5', true));
    $check($stable->foundationReady() && $stable->commerceReady(), 'Equivalent abbreviated releases were blocked.');

    $phpPrerelease = $compatibility->evaluate(new EnvironmentSnapshot('8.1-RC1', '6.5', '8.5', true));
    $check(in_array('veyra_php_too_old', $phpPrerelease->codes(), true), 'An old PHP prerelease was accepted.');
    $wpPrerelease = $compatibility->evaluate(new EnvironmentSnapshot('8.1', '6.5-RC1', '8.5', true));
    $check(in_array('veyra_wordpress_too_old', $wpPrerelease->codes(), true), 'An old WordPress prerelease was accepted.');
    $wooPrerelease = $compatibility->evaluate(new EnvironmentSnapshot('8.1', '6.5', '8.5-RC1', true));
    $check(in_array('veyra_woocommerce_too_old', $wooPrerelease->codes(), true), 'An old WooCommerce prerelease was accepted.');
});

$test('published customer AI identity text is valid UTF-8 and byte bounded', static function () use ($check): void {
    $method = new ReflectionMethod(RuntimeModule::class, 'boundedPublishedText');
    $method->setAccessible(true);
    $bounded = $method->invoke(null, str_repeat('a', 81), 'fallback', 80);
    $check(is_string($bounded) && strlen($bounded) === 80, 'Published AI identity exceeded its byte bound.');
    $utf8 = $method->invoke(null, str_repeat('ع', 41), 'fallback', 81);
    $check(is_string($utf8) && strlen($utf8) === 80 && preg_match('//u', $utf8) === 1, 'UTF-8 was split at the byte bound.');
    $check($method->invoke(null, "\xC3", 'fallback', 80) === 'fallback', 'Invalid UTF-8 did not fail closed.');
});

$test('runtime feature truth blocks unmounted Woo surfaces', static function () use ($check): void {
    $method = new ReflectionMethod(RuntimeModule::class, 'runtimeFeaturePlan');
    $method->setAccessible(true);
    $missingWoo = new CompatibilityReport([
        new CompatibilityIssue('veyra_woocommerce_missing', 'woocommerce', 'WooCommerce is unavailable.', false),
    ]);
    $blocked = $method->invoke(null, true, ['allowed' => true, 'reason_code' => 'runtime_ready'], $missingWoo);
    $check(is_array($blocked), 'Runtime feature plan was not returned.');
    foreach (['ai_semantic_orchestration', 'ai_context_graph', 'commerce_product_assistance', 'commerce_cart', 'commerce_chat_checkout', 'commerce_order_service'] as $feature) {
        $check(($blocked[$feature]['state'] ?? null) === FeatureState::Blocked, sprintf('%s was advertised while its Woo surface was unmounted.', $feature));
        $check(($blocked[$feature]['reason'] ?? null) === 'veyra_woocommerce_missing', sprintf('%s lost the authoritative compatibility reason.', $feature));
    }

    $ready = $method->invoke(null, true, ['allowed' => true, 'reason_code' => 'runtime_ready'], new CompatibilityReport([]));
    $check(($ready['ai_semantic_orchestration']['state'] ?? null) === FeatureState::On, 'A compatible certified AI runtime was not available.');
    $check(($ready['commerce_chat_checkout']['state'] ?? null) === FeatureState::Degraded, 'The intentionally incomplete checkout runtime was not reported as degraded.');

    $schemaBlocked = $method->invoke(null, false, ['allowed' => true, 'reason_code' => 'runtime_ready'], new CompatibilityReport([]));
    $check(($schemaBlocked['ai_semantic_orchestration']['state'] ?? null) === FeatureState::Blocked, 'A stale schema advertised the AI runtime.');
    $check(($schemaBlocked['ai_semantic_orchestration']['reason'] ?? null) === 'schema_migration_required', 'A stale schema lost its remediation reason.');
});

$test('runtime composition failure publishes no partial hooks and blocks every feature', static function () use ($check): void {
    global $veyraRuntimeHooks;
    $veyraRuntimeHooks = [];
    $features = FeatureRegistry::canonical();
    $runtime = new RuntimeFeatureRegistry();
    $container = new Container();
    $container->set(FeatureRegistry::class, $features);
    $container->set(RuntimeFeatureRegistry::class, $runtime);

    $thrown = false;
    try {
        RuntimeModule::registerServices($container, new CompatibilityReport([]));
    } catch (Throwable) {
        $thrown = true;
    }

    $check($thrown, 'Composition failure was not handed to the request-safe Plugin boundary.');
    $check($veyraRuntimeHooks === [], 'A hook was registered before runtime composition completed.');
    foreach ($features->all() as $definition) {
        $availability = $runtime->availability($definition->key);
        $check($availability->state === FeatureState::Blocked, sprintf('%s survived failed runtime composition.', $definition->key->value()));
        $check($availability->reasonCode === 'runtime_module_composition_failed', sprintf('%s lost the failed-composition reason.', $definition->key->value()));
    }
});

$test('cart and checkout serialize the same actor-owned Woo authority', static function () use ($check): void {
    $context = new ToolContext(
        'customer',
        'wp-user-42',
        42,
        null,
        '11111111-1111-4111-8111-111111111111',
        [],
        ['commerce_cart' => 'On', 'commerce_chat_checkout' => 'On'],
        'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $cart = (new ReflectionClass(CartToolHandler::class))->newInstanceWithoutConstructor();
    $checkout = (new ReflectionClass(CheckoutToolHandler::class))->newInstanceWithoutConstructor();
    $cartKey = new ReflectionMethod(CartToolHandler::class, 'woocommerceAuthorityLockKey');
    $checkoutKey = new ReflectionMethod(CheckoutToolHandler::class, 'woocommerceAuthorityLockKey');
    $cartKey->setAccessible(true);
    $checkoutKey->setAccessible(true);
    $left = $cartKey->invoke($cart, $context);
    $right = $checkoutKey->invoke($checkout, $context);
    $check(is_string($left) && hash_equals($left, (string) $right), 'Cart and checkout used different Woo authority leases.');
    $check(str_starts_with($left, 'woocommerce-commerce-authority:'), 'Shared Woo authority lease lost its bounded namespace.');
});

$test('checkout replay preserves the persisted retry-safety decision', static function () use ($check): void {
    $context = new ToolContext(
        'customer',
        'wp-user-42',
        42,
        null,
        '11111111-1111-4111-8111-111111111111',
        [],
        ['commerce_chat_checkout' => 'On'],
        'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $handler = (new ReflectionClass(CheckoutToolHandler::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(CheckoutToolHandler::class, 'replay');
    $method->setAccessible(true);
    $result = $method->invoke(
        $handler,
        new ToolCall('checkout-replay', 'checkout.select_payment_method', '1.0.0', []),
        $context,
        'failed',
        'checkout_authority_reconciliation_required',
        ['result_status' => 'uncertain', 'data' => ['reconciliation_required' => true]],
        false
    );
    $check($result->status === 'uncertain', 'Stored checkout uncertainty changed status on replay.');
    $check($result->retrySafe === false, 'Stored non-retry-safe checkout outcome became retry-safe on replay.');
});

$test('confirmation requests reject unbounded or ambiguous material contracts', static function () use ($check): void {
    $state = StateHash::fromPayload(['cart_version' => 1]);
    $correlation = CorrelationId::generate();
    $invalidFactories = [
        static fn (): ConfirmationRequest => new ConfirmationRequest(
            'cart.clear_confirmed',
            ['cart-42'],
            ['total' => '25.00'],
            $state,
            'message:summary',
            1,
            [],
            'cart:customer:42',
            $correlation
        ),
        static fn (): ConfirmationRequest => new ConfirmationRequest(
            'cart.clear_confirmed',
            ['cart' => 'cart-42'],
            ['payload' => str_repeat('x', 65537)],
            $state,
            'message:summary',
            1,
            [],
            'cart:customer:42',
            $correlation
        ),
        static fn (): ConfirmationRequest => new ConfirmationRequest(
            'cart.clear_confirmed',
            ['cart' => 'cart-42'],
            ['total' => '25.00'],
            $state,
            'invalid summary id',
            1,
            [],
            'cart:customer:42',
            $correlation
        ),
        static fn (): ConfirmationRequest => new ConfirmationRequest(
            'cart.clear_confirmed',
            ['cart' => 'cart-42'],
            ['total' => '25.00'],
            $state,
            'message:summary',
            1,
            ['terms', 'terms'],
            'cart:customer:42',
            $correlation
        ),
    ];

    foreach ($invalidFactories as $index => $factory) {
        $rejected = false;
        try {
            $factory();
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        $check($rejected, sprintf('Unsafe confirmation contract %d was accepted.', $index + 1));
    }
});

$test('confirmation tokens are actor scoped, digested, and single use', static function () use ($check): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $repository = new InMemoryConfirmationRepository();
    $service = new ConfirmationService($repository, new SecretDigester(str_repeat('k', 32)), $clock);
    $actor = new Actor(new ActorId('wp-user-42'), ActorType::Customer, 42);
    $creationCorrelation = CorrelationId::generate();
    $consumptionCorrelation = CorrelationId::generate();
    $state = StateHash::fromPayload(['cart_version' => 8, 'total' => '75.00']);
    $issued = $service->create($actor, new ConfirmationRequest(
        'order.place',
        ['cart' => 'cart-42'],
        ['total' => '75.00'],
        $state,
        'msg_' . str_repeat('a', 32),
        1,
        ['terms'],
        'checkout:cart-42',
        $creationCorrelation
    ));
    $check(!hash_equals($issued->token, $issued->record->tokenDigest), 'Plain confirmation token was persisted.');
    $other = new Actor(new ActorId('wp-user-43'), ActorType::Customer, 43);
    $check(
        $service->consume($other, $issued->token, $state, $consumptionCorrelation)->code === 'confirmation_not_found',
        'Cross-actor confirmation consumption succeeded.'
    );
    $wrongToken = substr($issued->token, 0, -1) . (str_ends_with($issued->token, 'A') ? 'B' : 'A');
    $check(
        $service->consume($actor, $wrongToken, $state, $consumptionCorrelation)->code === 'confirmation_not_found',
        'Wrong confirmation token was accepted.'
    );
    $check(
        $service->consume(
            $actor,
            $issued->token,
            StateHash::fromPayload(['cart_version' => 9, 'total' => '76.00']),
            $consumptionCorrelation
        )->code === 'confirmation_state_changed',
        'Stale confirmation state was consumed.'
    );
    $consumed = $service->consume($actor, $issued->token, $state, $consumptionCorrelation);
    $check($consumed->consumed, 'Confirmation did not consume under the later request correlation.');
    $check(
        $consumed->record !== null && $consumed->record->correlationId->equals($consumptionCorrelation),
        'Consumed result was not linked to the consumption correlation.'
    );
    $check(
        $consumed->record !== null
            && $consumed->record->resourceScope === ['cart' => 'cart-42']
            && $consumed->record->idempotencyScope === 'checkout:cart-42',
        'Confirmation scope changed during cross-request consumption.'
    );
    $check(
        !$service->consume($actor, $issued->token, $state, CorrelationId::generate())->consumed,
        'Confirmation replay succeeded.'
    );
    $expiringState = StateHash::fromPayload(['cart_version' => 1, 'total' => '25.00']);
    $expiring = $service->create($actor, new ConfirmationRequest(
        'order.place',
        ['cart' => 'cart-expiring'],
        ['total' => '25.00'],
        $expiringState,
        'msg_' . str_repeat('b', 32),
        1,
        [],
        'checkout:cart-expiring',
        CorrelationId::generate(),
        ttlSeconds: 30
    ));
    $clock->advance(31);
    $check(
        $service->consume($actor, $expiring->token, $expiringState, CorrelationId::generate())->code === 'confirmation_expired',
        'Expired confirmation was consumed.'
    );
});

$test('idempotency distinguishes in-progress, replay, and conflict', static function () use ($check): void {
    $service = new IdempotencyService(
        new InMemoryIdempotencyRepository(),
        new SecretDigester(str_repeat('i', 32)),
        new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'))
    );
    $actor = new Actor(new ActorId('wp-user-9'), ActorType::Customer, 9);
    $correlation = CorrelationId::generate();
    $first = $service->begin($actor, 'cart.add', 'request-key-0001', ['product' => 5], 'cart:9', $correlation);
    $check($first->status === IdempotencyDecisionStatus::Claimed, 'Initial idempotency claim failed.');
    $second = $service->begin($actor, 'cart.add', 'request-key-0001', ['product' => 5], 'cart:9', $correlation);
    $check($second->status === IdempotencyDecisionStatus::InProgress, 'Concurrent duplicate was not detected.');
    $inProgress = $service->reconciliationStatus($actor, 'cart.add', 'request-key-0001', 'cart:9');
    $check($inProgress['known'] && !$inProgress['complete'], 'In-progress reconciliation was declared complete.');
    $check($service->complete($first->record, 'cart_item_added', ['line' => 'x']), 'Idempotency completion failed.');
    $terminal = $service->reconciliationStatus($actor, 'cart.add', 'request-key-0001', 'cart:9');
    $check($terminal['complete'] && $terminal['status'] === 'succeeded', 'Terminal idempotency state did not reconcile.');
    $wrongScope = $service->reconciliationStatus($actor, 'cart.add', 'request-key-0001', 'cart:other');
    $check(!$wrongScope['known'] && !$wrongScope['complete'], 'A reconciliation handle crossed resource scope.');
    $replay = $service->begin($actor, 'cart.add', 'request-key-0001', ['product' => 5], 'cart:9', $correlation);
    $check($replay->status === IdempotencyDecisionStatus::Replay, 'Completed duplicate did not replay.');
    $conflict = $service->begin($actor, 'cart.add', 'request-key-0001', ['product' => 6], 'cart:9', $correlation);
    $check($conflict->status === IdempotencyDecisionStatus::Conflict, 'Same key with different payload was accepted.');
});

$test('audit metadata strips secret-bearing fields', static function () use ($check): void {
    $safe = SafeAuditMetadata::sanitize([
        'action' => 'cart.add',
        'api_token' => 'fixture-token-value',
        'nested' => [
            'result_code' => 'ok',
            'card_number' => 'fixture-card-value',
            'iban' => 'fixture-iban-value',
            'bank_account' => 'fixture-account-value',
            'routing_number' => 'fixture-routing-value',
            'swift_code' => 'fixture-swift-value',
        ],
    ]);
    $check(isset($safe['action']), 'Safe metadata was removed.');
    $check(!isset($safe['api_token']), 'Token leaked into audit metadata.');
    $check(!isset($safe['nested']['card_number']), 'Card data leaked into audit metadata.');
    $check(!isset($safe['nested']['iban']), 'IBAN leaked into audit metadata.');
    $check(!isset($safe['nested']['bank_account']), 'Bank-account data leaked into audit metadata.');
    $check(!isset($safe['nested']['routing_number']), 'Routing data leaked into audit metadata.');
    $check(!isset($safe['nested']['swift_code']), 'SWIFT data leaked into audit metadata.');
});

$test('tool input validation follows JSON Schema object defaults', static function () use ($check): void {
    $validator = new ToolInputValidator();
    $check($validator->validate([], ['type' => 'object', 'additionalProperties' => false, 'properties' => []]), 'An empty JSON object was rejected.');
    $check($validator->validate(['attribute_pa_color' => 'black'], ['type' => 'object']), 'Schema-default additional properties were rejected.');
    $check(!$validator->validate(['unexpected' => 'value'], ['type' => 'object', 'additionalProperties' => false, 'properties' => []]), 'Explicitly forbidden additional properties were accepted.');
    $check($validator->validate(
        ['attribute_pa_color' => 'black'],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'maxLength' => 20]]
    ), 'Typed additional properties were rejected.');
    $check(!$validator->validate(
        ['attribute_pa_color' => ['nested']],
        ['type' => 'object', 'additionalProperties' => ['type' => 'string']]
    ), 'Typed additional-property validation was bypassed.');
});

$test('provider execution cannot invoke a server-only tool by name', static function () use ($check): void {
    $handler = new class implements ToolHandler {
        public bool $executed = false;

        public function definitions(): array
        {
            return [new ToolDefinition(
                'internal.inspect',
                '1.0.0',
                'Server-only inspection.',
                'read',
                ['type' => 'object', 'additionalProperties' => false, 'properties' => []],
                ['guest'],
                [],
                [],
                false
            )];
        }

        public function execute(ToolCall $call, ToolContext $context): ToolResult
        {
            $this->executed = true;
            return ToolResult::success($call, ['exposed' => true], $context->correlationId);
        }
    };
    $registry = new ToolRegistry(new ToolInputValidator());
    $registry->register($handler);
    $call = new ToolCall('call-hidden', 'internal.inspect', '1.0.0', []);
    $context = new ToolContext(
        'guest',
        'guest-session-1',
        null,
        'guest-session-1',
        '11111111-1111-4111-8111-111111111111',
        [],
        [],
        'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $result = $registry->execute($call, $context);
    $check($result->status === 'blocked' && $result->code === 'tool_not_model_visible', 'Hidden tool was not denied.');
    $check(!$handler->executed, 'Hidden tool handler executed despite the deny boundary.');
});

$test('pending-answer turns exclude and deny every provider mutation', static function () use ($check): void {
    $handler = new class implements ToolHandler {
        public bool $executed = false;

        public function definitions(): array
        {
            $schema = ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
            return [
                new ToolDefinition('test.pending_read', '1.0.0', 'Read.', 'read', $schema, ['guest'], [], [], true),
                new ToolDefinition('test.pending_write', '1.0.0', 'Write.', 'write', $schema, ['guest'], [], [], true),
            ];
        }

        public function execute(ToolCall $call, ToolContext $context): ToolResult
        {
            $this->executed = true;
            return ToolResult::success($call, [], $context->correlationId);
        }
    };
    $registry = new ToolRegistry(new ToolInputValidator());
    $registry->register($handler);
    $context = new ToolContext(
        'guest', 'guest-session-2', null, 'guest-session-2',
        '11111111-1111-4111-8111-111111111111', [], [], 'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $modelNames = array_column($registry->modelTools($context, false), 'name');
    $check(in_array('test.pending_read', $modelNames, true), 'Read tool was removed from a pending-answer turn.');
    $check(!in_array('test.pending_write', $modelNames, true), 'Write tool remained visible before pending-answer CAS consumption.');
    $result = $registry->execute(new ToolCall('call-pending-write', 'test.pending_write', '1.0.0', []), $context, false);
    $check(
        $result->status === 'blocked' && $result->code === 'turn_mutation_binding_required',
        'A provider-named mutation bypassed the pending-answer gate.'
    );
    $check(!$handler->executed, 'Pending-answer write handler executed despite the deny boundary.');
});

$test('provider mutation identity is server-derived and stable across rounds', static function () use ($check): void {
    $definition = new ToolDefinition(
        'cart.add_item',
        '1.0.0',
        'Mutation replay test.',
        'write',
        [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['product_id', 'quantity', 'idempotency_key'],
            'properties' => [
                'product_id' => ['type' => 'integer'],
                'quantity' => ['type' => 'number'],
                'idempotency_key' => ['type' => 'string'],
            ],
        ],
        ['customer'],
        [],
        [],
        true
    );
    $modelSchema = $definition->forModel()['input_schema'];
    $check(!isset($modelSchema['properties']['idempotency_key']), 'Model schema retained the server-owned idempotency field.');
    $check(!in_array('idempotency_key', $modelSchema['required'], true), 'Model schema still requires an idempotency key.');

    $context = new ToolContext(
        'customer',
        'wp-user-23',
        23,
        null,
        '11111111-1111-4111-8111-111111111111',
        [],
        [],
        'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $guard = new TurnMutationGuard();
    $profile = ['classification' => 'write', 'accepts_idempotency_key' => true];
    $first = $guard->prepare(
        new ToolCall('round-one', 'cart.add_item', '1.0.0', ['product_id' => 42, 'quantity' => 1, 'idempotency_key' => 'model-key-one']),
        $context,
        'msg_' . str_repeat('a', 32),
        $profile
    );
    $second = $guard->prepare(
        new ToolCall('round-two', 'cart.add_item', '1.0.0', [
            'quantity' => 1,
            'idempotency_key' => 'model-key-two',
            'product_id' => 42,
            'expected_version' => 9,
            'state_hash' => 'fresh-round-state',
            'confirmation_id' => 'fresh-round-confirmation',
        ]),
        $context,
        'msg_' . str_repeat('a', 32),
        $profile
    );
    $check($first['fingerprint'] === $second['fingerprint'], 'Equivalent cross-round writes produced different semantic identities.');
    $check($first['call']->arguments['idempotency_key'] === $second['call']->arguments['idempotency_key'], 'Model-selected keys changed the server replay boundary.');

    $writes = 0;
    ++$writes;
    $stored = ToolResult::success($first['call'], ['line_id' => 'line-42'], $context->correlationId, ['cart:after']);
    $guard->remember($first['fingerprint'], $stored);
    $replay = $guard->replay($second['fingerprint'], $second['call'], $context->correlationId);
    $check($replay instanceof ToolResult && $replay->callId === 'round-two', 'Equivalent later-round call did not receive a rebound replay result.');
    $check($replay->data === ['line_id' => 'line-42'], 'Replay polluted the registered ToolResult data contract.');
    $check($writes === 1, 'Equivalent cross-round mutation executed more than once.');
});

$test('write handler exceptions are uncertain, never verified no-side-effect failures', static function () use ($check): void {
    $handler = new class implements ToolHandler {
        public function definitions(): array
        {
            return [new ToolDefinition(
                'test.throwing_write',
                '1.0.0',
                'Test-only write boundary.',
                'write',
                ['type' => 'object', 'additionalProperties' => false, 'required' => [], 'properties' => []],
                ['customer'],
                [],
                [],
                true
            )];
        }

        public function execute(ToolCall $call, ToolContext $context): ToolResult
        {
            throw new RuntimeException('post-write failure');
        }
    };
    $registry = new ToolRegistry(new ToolInputValidator());
    $registry->register($handler);
    $context = new ToolContext(
        'customer',
        'wp-user-23',
        23,
        null,
        '11111111-1111-4111-8111-111111111111',
        [],
        [],
        'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $result = $registry->execute(new ToolCall('throw-one', 'test.throwing_write', '1.0.0', []), $context);
    $check($result->status === 'uncertain' && !$result->retrySafe, 'A throwing write was mislabeled safe or failed-with-no-effect.');
    $check(MutationFailureOutcome::classify([$result]) === 'uncertain', 'A throwing write allowed a false no-action outcome.');
});

$test('post-mutation orchestration failures never claim that no action ran', static function () use ($check): void {
    $call = new ToolCall('call-write', 'cart.add_item', '1.0.0', ['product_id' => 42]);
    $changed = ToolResult::success($call, ['line_id' => 'line-42'], 'corr-write', ['cart:after']);
    $check(MutationFailureOutcome::classify([$changed]) === 'partial', 'Known completed mutation was classified as no action.');
    $uncertain = new ToolResult('call-unknown', 'cart.add_item', 'uncertain', 'cart_mutation_uncertain', [], [], true, false, 'corr-write');
    $check(MutationFailureOutcome::classify([$changed, $uncertain]) === 'uncertain', 'Uncertain mutation was not fail-closed.');
    $failed = ToolResult::failed($call, 'cart_item_add_failed', 'corr-write', true);
    $check(MutationFailureOutcome::classify([$failed]) === 'none', 'Verified no-side-effect failure was mislabeled as a mutation.');
    $idempotentNoOp = ToolResult::success($call, ['changed' => false], 'corr-write');
    $check(MutationFailureOutcome::classify([$idempotentNoOp]) === 'partial', 'A completed write operation allowed a false no-action claim.');
});

$test('semantic verification contract cannot self-certify incomplete checks', static function () use ($check): void {
    $validator = new ProviderPayloadValidator();
    $checks = array_map(static fn (string $name): array => ['check' => $name, 'status' => 'pass'], [
        'latest_goal_answered', 'subgoals_complete_or_pending', 'commerce_claims_current',
        'policy_claims_approved', 'hard_requirements_preserved', 'tool_results_exact',
        'stale_not_current', 'culture_location_time_format_correct',
        'no_protected_trait_or_manipulation', 'confirmation_and_disclosure_present',
    ]);
    $valid = [
        'schema_version' => '1.0.0',
        'verdict' => 'supported',
        'checks' => $checks,
        'reason_codes' => ['all_material_claims_supported'],
        'unsupported_spans' => [],
    ];
    $check($validator->validateSemanticVerificationPayload($valid) !== null, 'Complete supported verification was rejected.');
    $invalid = $valid;
    $invalid['checks'][2]['status'] = 'uncertain';
    $check($validator->validateSemanticVerificationPayload($invalid) === null, 'Supported verdict accepted an uncertain material check.');
    array_pop($valid['checks']);
    $check($validator->validateSemanticVerificationPayload($valid) === null, 'Incomplete verification checklist was accepted.');
});

$test('provider turn claims require typed assertions and bounded source pointers', static function () use ($check): void {
    $validator = new ProviderPayloadValidator();
    $payload = [
        'schema_version' => '1.0.0',
        'turn_type' => 'response',
        'language' => 'en',
        'direction' => 'ltr',
        'reply' => ['text' => 'The current price is USD 75.00.', 'components' => []],
        'tool_calls' => [],
        'proposed_updates' => [],
        'evidence_requirements' => [],
        'claims' => [[
            'claim_id' => 'claim-price',
            'type' => 'price',
            'status' => 'verified',
            'source_call_id' => 'call-product',
            'source_path' => '/data/product/price',
            'asserted_value' => [
                'kind' => 'money',
                'string_value' => '75.00',
                'integer_value' => null,
                'number_value' => null,
                'boolean_value' => null,
                'currency' => 'USD',
                'currency_source_path' => '/data/product/currency',
            ],
        ]],
    ];
    $check($validator->validateTurnPayload($payload) !== null, 'A complete typed money claim was rejected.');
    $schema = $validator->responseSchema();
    $required = $schema['properties']['claims']['items']['required'] ?? [];
    $check(in_array('asserted_value', $required, true), 'Provider schema does not require asserted_value.');
    $assertedSchema = $schema['properties']['claims']['items']['properties']['asserted_value'] ?? [];
    $check(!isset($assertedSchema['properties']['value']), 'Provider schema retained an unconstrained mixed-type assertion slot.');
    $check(in_array('string_value', $assertedSchema['required'] ?? [], true), 'Provider schema does not declare fixed typed assertion slots.');

    $missingAssertion = $payload;
    unset($missingAssertion['claims'][0]['asserted_value']);
    $check($validator->validateTurnPayload($missingAssertion) === null, 'A claim without asserted_value was accepted.');
    $invalidPointer = $payload;
    $invalidPointer['claims'][0]['source_path'] = '/data/product/~2price';
    $check($validator->validateTurnPayload($invalidPointer) === null, 'An invalid JSON Pointer escape was accepted.');
    $badCurrency = $payload;
    $badCurrency['claims'][0]['asserted_value']['currency'] = 'usd';
    $check($validator->validateTurnPayload($badCurrency) === null, 'A non-canonical currency assertion was accepted.');
    $multipleSlots = $payload;
    $multipleSlots['claims'][0]['asserted_value']['integer_value'] = 75;
    $check($validator->validateTurnPayload($multipleSlots) === null, 'A claim populated more than its selected typed assertion slot.');
    $unknownWithSource = $payload;
    $unknownWithSource['claims'][0]['status'] = 'unknown';
    $unknownWithSource['claims'][0]['asserted_value'] = [
        'kind' => 'null', 'string_value' => null, 'integer_value' => null, 'number_value' => null,
        'boolean_value' => null, 'currency' => null, 'currency_source_path' => null,
    ];
    $check($validator->validateTurnPayload($unknownWithSource) === null, 'An unknown claim retained an authoritative-source citation.');
});

$test('Gemini stateless continuation preserves exact model steps and typed function results', static function () use ($check): void {
    $initial = [['type' => 'text', 'text' => '{"request":"find a blue jacket"}']];
    $thought = [
        'type' => 'thought',
        'id' => 'thought-1',
        'signature' => 'opaque-provider-signature',
        'content' => [['type' => 'text', 'text' => 'opaque']],
        'forward_compatible_field' => ['preserve' => true],
    ];
    $call = [
        'type' => 'function_call',
        'id' => 'call-product',
        'name' => 'catalog__search',
        'arguments' => ['query' => 'blue jacket'],
    ];
    $continuation = GeminiStatelessContinuation::start($initial)->appendModelSteps([$thought, $call]);
    $toolResult = new ToolResult(
        'call-product',
        'catalog.search',
        'succeeded',
        'catalog_search_completed',
        ['product_ids' => [42]],
        [],
        true,
        false,
        'private-correlation-id'
    );
    $projectedResult = [
        'schema_version' => 'veyra.provider_tool_result.v1',
        'call_id' => 'call-product',
        'tool' => 'catalog.search',
        'tool_version' => '1.0.0',
        'status' => 'succeeded',
        'code' => 'catalog_search_completed',
        'data' => ['product_ids' => [42]],
        'data_schema_hash' => str_repeat('a', 64),
        'changed_resources' => [],
        'authoritative' => true,
        'retry_safe' => false,
        'redactions' => [],
    ];
    $functionResult = ProviderFunctionResult::fromProjected($projectedResult);
    $continued = $continuation->appendFunctionResults([$functionResult]);
    $history = $continued->history();
    $check($history[0] === ['type' => 'user_input', 'content' => $initial], 'Initial input was not represented as an exact user_input step.');
    $check($history[1] === $thought && $history[2] === $call, 'Provider-generated steps were rewritten or dropped.');
    $check(($history[3]['type'] ?? null) === 'function_result', 'Typed function result step was not appended.');
    $check(($history[3]['name'] ?? null) === 'catalog__search' && ($history[3]['call_id'] ?? null) === 'call-product', 'Function result lost its provider name or call ID binding.');
    $encodedResult = $history[3]['result'][0]['text'] ?? '';
    $check(is_string($encodedResult) && !str_contains($encodedResult, 'private-correlation-id'), 'Internal correlation metadata leaked into provider function results.');
    $check(str_contains($encodedResult, 'catalog_search_completed'), 'Typed tool result was not serialized for the provider.');

    $request = new ProviderRequest(
        'default_text_tool_orchestration',
        'system',
        $initial,
        [],
        ['type' => 'object'],
        20,
        [],
        $continuation,
        [$functionResult]
    );
    $check($request->continuation === $continuation, 'Typed provider continuation was not retained.');

    $rejected = false;
    try {
        new ProviderRequest(
            'default_text_tool_orchestration',
            'system',
            $initial,
            [],
            ['type' => 'object'],
            20,
            [],
            null,
            [$functionResult]
        );
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $check($rejected, 'Function results were accepted without their provider continuation.');

    foreach ([$toolResult->toArray(), $projectedResult + ['correlation_id' => 'private-correlation-id']] as $unsafe) {
        $rejected = false;
        try {
            ProviderFunctionResult::fromProjected($unsafe);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        $check($rejected, 'Raw or open ToolResult data was accepted as a provider function result.');
    }
});

$test('Gemini REST response fixtures decode completed and requires-action interactions', static function () use ($check): void {
    $finalJson = '{"schema_version":"1.0.0","turn_type":"response"}';
    $completed = new GeminiInteractionResponse([
        'object' => 'interaction',
        'status' => 'completed',
        'steps' => [[
            'type' => 'model_output',
            'content' => [['type' => 'text', 'text' => $finalJson]],
        ]],
        'usage' => ['total_input_tokens' => 41, 'total_output_tokens' => 17],
    ]);
    $check($completed->status() === 'completed' && $completed->outputText() === $finalJson, 'Raw completed model_output text was not decoded.');
    $check($completed->usage('input_tokens') === 41 && $completed->usage('output_tokens') === 17, 'Current Interactions usage fields were not decoded.');

    $thought = ['type' => 'thought', 'signature' => 'opaque-signature'];
    $functionCall = [
        'type' => 'function_call',
        'id' => 'call-availability',
        'name' => 'catalog__get_availability',
        'arguments' => ['product_id' => 42],
    ];
    $requiresAction = new GeminiInteractionResponse([
        'object' => 'interaction',
        'status' => 'requires_action',
        'steps' => [$thought, $functionCall],
    ]);
    $check($requiresAction->modelSteps() === [$thought, $functionCall], 'Requires-action steps were not preserved exactly.');
    $calls = $requiresAction->nativeToolCalls();
    $check(count($calls) === 1 && $calls[0] === [
        'call_id' => 'call-availability',
        'name' => 'catalog.get_availability',
        'version' => '1.0.0',
        'arguments' => ['product_id' => 42],
    ], 'Native function call fixture did not decode into the logical tool contract.');

    $continuationFinal = new GeminiInteractionResponse([
        'object' => 'interaction',
        'status' => 'completed',
        'steps' => [
            ['type' => 'thought', 'signature' => 'second-opaque-signature'],
            ['type' => 'model_output', 'content' => [
                ['type' => 'text', 'text' => '{"reply":'],
                ['type' => 'text', 'text' => '{"text":"In stock"}}'],
            ]],
        ],
    ]);
    $check($continuationFinal->outputText() === '{"reply":{"text":"In stock"}}', 'Continuation model_output chunks were not assembled in order.');

    $thoughtOnly = new GeminiInteractionResponse([
        'object' => 'interaction',
        'status' => 'completed',
        'steps' => [['type' => 'thought', 'signature' => 'opaque']],
    ]);
    $check($thoughtOnly->outputText() === null && $thoughtOnly->nativeToolCalls() === [], 'Thought-only output was misclassified as a final response.');
    $malformed = new GeminiInteractionResponse([
        'object' => 'interaction',
        'status' => 'requires_action',
        'steps' => [['type' => 'function_call', 'name' => 'catalog__search', 'arguments' => 'not-an-object']],
    ]);
    $check($malformed->nativeToolCalls() === [], 'Malformed native function call was accepted.');

    $duplicate = new GeminiInteractionResponse([
        'object' => 'interaction',
        'status' => 'requires_action',
        'steps' => [$functionCall, $functionCall],
    ]);
    $check($duplicate->nativeToolCalls() === [], 'Duplicate native function call ID was silently deduplicated.');
    $conflicting = $functionCall;
    $conflicting['name'] = 'catalog__get_stock';
    $duplicateConflict = new GeminiInteractionResponse([
        'object' => 'interaction',
        'status' => 'requires_action',
        'steps' => [$functionCall, $conflicting],
    ]);
    $check($duplicateConflict->nativeToolCalls() === [], 'Conflicting native function call ID selected a last value.');
});

$test('deterministic verifier resolves typed values and currency against tool data', static function () use ($check): void {
    $verifier = new ResponseVerifier();
    $result = new ToolResult(
        'call-product',
        'catalog.get_product',
        'succeeded',
        'tool_ok',
        ['product' => ['product_id' => 42, 'price' => '75.00', 'currency' => 'USD', 'stock' => 3]],
        [],
        true,
        false,
        'corr-product'
    );
    $payload = [
        'reply' => ['text' => 'The current price is USD 75.00.', 'components' => []],
        'claims' => [[
            'claim_id' => 'claim-price',
            'type' => 'price',
            'status' => 'verified',
            'source_call_id' => 'call-product',
            'source_path' => '/data/product/price',
            'asserted_value' => [
                'kind' => 'money',
                'string_value' => '75.00',
                'integer_value' => null,
                'number_value' => null,
                'boolean_value' => null,
                'currency' => 'USD',
                'currency_source_path' => '/data/product/currency',
            ],
        ]],
    ];
    $verified = $verifier->verify($payload, [$result]);
    $check($verified['valid'] && count($verified['evidence']) === 1, 'An exact money assertion did not produce verified evidence.');

    $missing = $payload;
    $missing['claims'][0]['source_path'] = '/data/product/missing';
    $outcome = $verifier->verify($missing, [$result]);
    $check(in_array('claim_source_path_not_found:claim-price', $outcome['errors'], true), 'A nonexistent source path was accepted.');

    $wrongType = $payload;
    $wrongType['claims'][0]['asserted_value'] = [
        'kind' => 'integer', 'string_value' => null, 'integer_value' => 75, 'number_value' => null,
        'boolean_value' => null, 'currency' => null, 'currency_source_path' => null,
    ];
    $outcome = $verifier->verify($wrongType, [$result]);
    $check(in_array('claim_type_mismatch:claim-price', $outcome['errors'], true), 'A source/assertion type mismatch was accepted.');

    $wrongValue = $payload;
    $wrongValue['claims'][0]['asserted_value']['string_value'] = '74.00';
    $outcome = $verifier->verify($wrongValue, [$result]);
    $check(in_array('claim_value_mismatch:claim-price', $outcome['errors'], true), 'A wrong asserted value was accepted.');

    $wrongCurrency = $payload;
    $wrongCurrency['claims'][0]['asserted_value']['currency'] = 'EUR';
    $outcome = $verifier->verify($wrongCurrency, [$result]);
    $check(in_array('claim_currency_mismatch:claim-price', $outcome['errors'], true), 'A wrong asserted currency was accepted.');
});

$test('advisory results cannot authorize verified commerce claims', static function () use ($check): void {
    $call = new ToolCall('call-advisory', 'recommendation.rank', '1.0.0', []);
    $result = ToolResult::advisorySuccess($call, [
        'ranked_candidates' => [['product_id' => 42, 'score' => 99.0]],
    ], 'corr-advisory');
    $payload = [
        'reply' => ['text' => 'Product 42 is the verified best choice.', 'components' => []],
        'claims' => [[
            'claim_id' => 'claim-advisory',
            'type' => 'product',
            'status' => 'verified',
            'source_call_id' => 'call-advisory',
            'source_path' => '/data/ranked_candidates/0/product_id',
            'asserted_value' => [
                'kind' => 'integer',
                'string_value' => null,
                'integer_value' => 42,
                'number_value' => null,
                'boolean_value' => null,
                'currency' => null,
                'currency_source_path' => null,
            ],
        ]],
    ];
    $verified = (new ResponseVerifier())->verify($payload, [$result]);
    $check(!$verified['valid'], 'Advisory recommendation authorized a verified claim.');
    $check(in_array('claim_without_authoritative_source:claim-advisory', $verified['errors'], true), 'Advisory evidence denial code was absent.');
});

$test('post-tool prose or material components cannot omit the claim ledger', static function () use ($check): void {
    $verifier = new ResponseVerifier();
    $read = new ToolResult('call-read', 'catalog.get_product', 'succeeded', 'ok', ['product_id' => 42], [], true, false, 'corr-read');
    $textOnly = $verifier->verify(['reply' => ['text' => 'I found it.', 'components' => []], 'claims' => []], [$read]);
    $check(in_array('claims_required_for_post_tool_response', $textOnly['errors'], true), 'Post-tool prose was accepted with an empty claim ledger.');

    $changed = new ToolResult('call-change', 'cart.add_item', 'uncertain', 'reconcile_first', [], ['cart:version:9'], true, false, 'corr-change');
    $componentOnly = $verifier->verify([
        'reply' => ['text' => '', 'components' => [['type' => 'cart', 'evidence_call_id' => 'call-change']]],
        'claims' => [],
    ], [$changed]);
    $check(in_array('claims_required_for_post_tool_response', $componentOnly['errors'], true), 'A changed-result component omitted the claim ledger.');

    $noticeOnly = $verifier->verify([
        'reply' => ['text' => '', 'components' => [['type' => 'notice', 'label' => 'Working']]],
        'claims' => [],
    ], [$read]);
    $check($noticeOnly['valid'], 'A non-material empty notice was incorrectly forced to assert a claim.');
});

$test('mutation success cites and asserts the exact changed resource', static function () use ($check): void {
    $verifier = new ResponseVerifier();
    $result = new ToolResult(
        'call-cart',
        'cart.add_item',
        'succeeded',
        'cart_item_added',
        ['reported_resource' => 'cart:version:8'],
        ['cart:version:8', 'audit:event:9'],
        true,
        false,
        'corr-cart'
    );
    $payload = [
        'reply' => ['text' => 'The cart was updated.', 'components' => []],
        'claims' => [[
            'claim_id' => 'claim-cart-change',
            'type' => 'mutation_success',
            'status' => 'verified',
            'source_call_id' => 'call-cart',
            'source_path' => '/changed_resources/0',
            'asserted_value' => [
                'kind' => 'resource',
                'string_value' => 'cart:version:8',
                'integer_value' => null,
                'number_value' => null,
                'boolean_value' => null,
                'currency' => null,
                'currency_source_path' => null,
            ],
        ]],
    ];
    $check($verifier->verify($payload, [$result])['valid'], 'An exact changed-resource mutation assertion was rejected.');

    $unrelated = $payload;
    $unrelated['claims'][0]['source_path'] = '/changed_resources/1';
    $outcome = $verifier->verify($unrelated, [$result]);
    $check(in_array('claim_value_mismatch:claim-cart-change', $outcome['errors'], true), 'A mutation claim cited an unrelated changed resource.');

    $dataPath = $payload;
    $dataPath['claims'][0]['source_path'] = '/data/reported_resource';
    $outcome = $verifier->verify($dataPath, [$result]);
    $check(in_array('mutation_claim_source_not_changed_resource:claim-cart-change', $outcome['errors'], true), 'A mutation-success claim cited ordinary result data instead of changed_resources.');

    $unchanged = new ToolResult('call-cart', 'cart.add_item', 'succeeded', 'ok', ['reported_resource' => 'cart:version:8'], [], true, false, 'corr-cart');
    $outcome = $verifier->verify($payload, [$unchanged]);
    $check(in_array('mutation_claim_without_changed_resource:claim-cart-change', $outcome['errors'], true), 'Mutation success was accepted without an authoritative changed resource.');
});

$test('capability readiness cannot bypass provider release certification', static function () use ($check): void {
    $manifest = new RouteManifest(dirname(__DIR__) . '/config/provider-route-manifest.php');
    $gate = new ProviderReleaseGate($manifest);
    $forgedReadyState = [
        'state' => 'Ready',
        'route_id' => ProviderReleaseGate::ROUTE_ID,
        'route_version' => $manifest->version(),
        'release_certified' => true,
        'checked_at' => gmdate('c'),
        'capabilities' => [
            'structured_output' => true,
            'function_calling' => true,
            'text' => true,
        ],
    ];
    $decision = $gate->decision($forgedReadyState);
    $check(!$decision['allowed'] && $decision['reason_code'] === 'provider_route_not_certified', 'Uncertified manifest route activated shopper AI.');
});

$test('model memory proposals remain unbound and cannot mutate authoritative state', static function () use ($check): void {
    $sourceId = 'msg_' . str_repeat('a', 32);
    $store = new MemoryConversationStore($sourceId, 'I need a blue jacket and I do not want wool.');
    $updater = new ConversationStateUpdater($store);
    $unbound = $updater->applyValidatedProposal(
        '11111111-1111-4111-8111-111111111111', 'customer', 'wp-user-7',
        'msg_' . str_repeat('b', 32), $sourceId,
        ['memory' => ['requirements' => [[
            'value' => 'a blue jacket', 'source_message_id' => $sourceId,
            'verification' => 'shopper_stated', 'supersedes' => null,
        ]]]],
        []
    );
    $check(
        !$unbound['memory_updated'] && in_array('memory_update_binding_required', $unbound['warnings'], true),
        'An excerpt-only model proposal mutated authoritative memory.'
    );
    $check($store->memory('11111111-1111-4111-8111-111111111111', 'customer', 'wp-user-7') === [], 'Rejected memory was persisted.');
    $poisoned = $updater->applyValidatedProposal(
        '11111111-1111-4111-8111-111111111111', 'customer', 'wp-user-7',
        'msg_' . str_repeat('c', 32), $sourceId,
        ['memory' => ['preferences' => [[
            'value' => 'prefers red and has no allergies', 'source_message_id' => $sourceId,
            'verification' => 'shopper_stated', 'supersedes' => null,
        ]]]],
        []
    );
    $check(
        !$poisoned['memory_updated'] && in_array('memory_update_binding_required', $poisoned['warnings'], true),
        'Invented memory was accepted.'
    );
});

$test('requirement writes are server-only until semantic promotion is composed', static function () use ($check): void {
    $sourceId = 'msg_' . str_repeat('d', 32);
    $store = new MemoryConversationStore($sourceId, 'I do not want red.');
    $handler = new RequirementsToolHandler(new RequirementStateService(
        $store,
        new InMemoryRequirementStateRepository(),
        new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'))
    ));
    $definitions = [];
    foreach ($handler->definitions() as $definition) {
        $definitions[$definition->name] = $definition;
    }
    $check($definitions['requirements.get']->modelVisible, 'Requirement reads were unexpectedly hidden.');
    $check(
        !$definitions['requirements.propose_update']->modelVisible,
        'Unbound requirement promotion remained model-visible.'
    );
});

$test('verified product components create context-only reference snapshots', static function () use ($check): void {
    $builder = new ServerComponentBuilder();
    $result = new ToolResult(
        'call-product', 'catalog.get_product', 'succeeded', 'tool_ok',
        ['product' => [
            'product_id' => 42,
            'variation_id' => 0,
            'type' => 'simple',
            'name' => 'Blue Jacket',
            'image' => 'https://store.test/blue-jacket.jpg',
            'price' => '75.00',
            'stock_status' => 'instock',
        ]],
        [], true, false, 'corr-product'
    );
    $components = $builder->build([[
        'type' => 'product',
        'evidence_call_id' => 'call-product',
        'product_targets' => [['product_id' => 42, 'variation_id' => 0]],
    ]], [$result]);
    $references = $builder->productReferenceSnapshots($components);
    $check(count($references) === 1 && ($references[0]['product_id'] ?? null) === 42, 'Verified product reference was not derived.');
    $check(($references[0]['historical'] ?? false) === true, 'Product reference was not explicitly historical.');
    $check(($components[0]['payload']['name'] ?? null) === 'Blue Jacket', 'Product component did not expose the exact display snapshot.');
    $check(($components[0]['payload']['image_url'] ?? null) === 'https://store.test/blue-jacket.jpg', 'Catalog image was not projected into the renderer contract.');
    $check(($components[0]['payload']['stock'] ?? null) === 'instock', 'Catalog stock was not projected into the renderer contract.');
    $check(!isset($components[0]['payload']['product']), 'Raw catalog result wrapper leaked into the product card snapshot.');
});

$test('product components require exact declared identities from catalog evidence', static function () use ($check): void {
    $builder = new ServerComponentBuilder();
    $search = new ToolResult(
        'call-search', 'catalog.search_products', 'succeeded', 'tool_ok',
        ['candidates' => [
            ['product_id' => 11, 'variation_id' => 0, 'type' => 'simple', 'name' => 'First candidate', 'price' => '10.00'],
            ['product_id' => 22, 'variation_id' => 0, 'type' => 'simple', 'name' => 'Presented candidate', 'price' => '20.00'],
        ], 'selection_required' => true],
        [], true, false, 'corr-search'
    );
    $components = $builder->build([[
        'type' => 'product',
        'evidence_call_id' => 'call-search',
        'product_targets' => [['product_id' => 22, 'variation_id' => 0]],
    ]], [$search]);
    $check(count($components) === 1, 'An exact search-result target was not rendered.');
    $check(($components[0]['payload']['product_id'] ?? null) === 22, 'The first search candidate was selected instead of the declared target.');
    $references = $builder->productReferenceSnapshots($components);
    $check(count($references) === 1 && ($references[0]['product_id'] ?? null) === 22, 'An unpresented search candidate became a product reference.');

    $missing = $builder->build([[
        'type' => 'product',
        'evidence_call_id' => 'call-search',
        'product_targets' => [['product_id' => 99, 'variation_id' => 0]],
    ]], [$search]);
    $check($missing === [], 'A target absent from the cited catalog result produced a card.');

    $advisory = new ToolResult(
        'call-recommendation', 'recommendation.rank_candidates', 'succeeded', 'tool_ok',
        ['candidates' => [['product_id' => 22, 'variation_id' => 0, 'name' => 'Presented candidate']]],
        [], false, false, 'corr-recommendation'
    );
    $directRecommendationCard = $builder->build([[
        'type' => 'product',
        'evidence_call_id' => 'call-recommendation',
        'product_targets' => [['product_id' => 22, 'variation_id' => 0]],
    ]], [$advisory]);
    $check($directRecommendationCard === [], 'Advisory recommendation evidence directly produced an authoritative product card.');
});

$test('resolved variation components preserve the exact parent and child tuple', static function () use ($check): void {
    $builder = new ServerComponentBuilder();
    $resolved = new ToolResult(
        'call-variation', 'catalog.resolve_variation', 'succeeded', 'tool_ok',
        ['resolved' => true, 'variation' => [
            'product_id' => 50,
            'variation_id' => 501,
            'type' => 'variation',
            'name' => 'Jacket - Blue / Large',
            'price' => '85.00',
            'attributes' => [
                ['name' => 'color', 'options' => ['blue'], 'variation' => true],
                ['name' => 'size', 'options' => ['large'], 'variation' => true],
            ],
        ]],
        [], true, false, 'corr-variation'
    );
    $components = $builder->build([[
        'type' => 'product',
        'evidence_call_id' => 'call-variation',
        'product_targets' => [['product_id' => 50, 'variation_id' => 501]],
    ]], [$resolved]);
    $check(count($components) === 1, 'An exact resolved variation did not produce a card.');
    $check(($components[0]['payload']['variation_id'] ?? null) === 501, 'Variation identity collapsed to its parent product.');
    $check(($components[0]['payload']['variation'] ?? []) === ['color: blue', 'size: large'], 'Variation attributes were not rendered deterministically.');
    $references = $builder->productReferenceSnapshots($components);
    $check(($references[0]['product_id'] ?? null) === 50 && ($references[0]['variation_id'] ?? null) === 501, 'Variation reference lost its exact tuple.');

    $parentOnly = $builder->build([[
        'type' => 'product',
        'evidence_call_id' => 'call-variation',
        'product_targets' => [['product_id' => 50, 'variation_id' => 0]],
    ]], [$resolved]);
    $check($parentOnly === [], 'A resolved child variation authorized a parent-only product card.');
});

$test('response component intentions carry closed exact product targets', static function () use ($check): void {
    $validator = new ProviderPayloadValidator();
    $schema = $validator->responseContractSchema();
    $componentSchema = $schema['properties']['reply']['properties']['components']['items'] ?? [];
    $check(in_array('product_targets', $componentSchema['required'] ?? [], true), 'Response schema does not require explicit product targets.');
    $targetSchema = $componentSchema['properties']['product_targets']['items'] ?? [];
    $check(($targetSchema['additionalProperties'] ?? true) === false, 'Product target tuple schema is not closed.');
    $staticSchema = json_decode((string) file_get_contents(
        dirname(__DIR__) . '/config/contracts/schemas/agent-response.schema.json'
    ), true);
    $staticComponent = is_array($staticSchema)
        ? ($staticSchema['properties']['reply']['properties']['components']['items'] ?? [])
        : [];
    $check(
        ($staticComponent['required'] ?? null) === ($componentSchema['required'] ?? null)
            && ($staticComponent['properties']['product_targets'] ?? null) === ($componentSchema['properties']['product_targets'] ?? null),
        'Static and runtime product component contracts diverged.'
    );

    $validReply = new ReflectionMethod($validator, 'validReply');
    $validReply->setAccessible(true);
    $base = [
        'text' => 'Here is the exact blue jacket.',
        'components' => [[
            'type' => 'product',
            'evidence_call_id' => 'call-product',
            'label' => '',
            'choices' => [],
            'product_targets' => [['product_id' => 42, 'variation_id' => 0]],
        ]],
    ];
    $check($validReply->invoke($validator, $base) === true, 'One exact product target was rejected.');
    $missing = $base;
    unset($missing['components'][0]['product_targets']);
    $check($validReply->invoke($validator, $missing) === false, 'A product component without an exact target was accepted.');
    $duplicate = $base;
    $duplicate['components'][0]['type'] = 'comparison';
    $duplicate['components'][0]['product_targets'][] = ['product_id' => 42, 'variation_id' => 0];
    $check($validReply->invoke($validator, $duplicate) === false, 'A comparison accepted duplicate product identities.');
});

$test('focus resource authorization preserves sets and ignores cross-domain nested ids', static function () use ($check): void {
    $agent = (new ReflectionClass(\Veyra\AI\Orchestration\CommerceAgent::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($agent, 'extractAuthorizedResources');
    $method->setAccessible(true);
    $catalog = new ToolResult(
        'call-catalog',
        'catalog.search_products',
        'succeeded',
        'ok',
        [
            'candidates' => [
                ['product_id' => 10, 'variation_id' => 101, 'nested' => ['order_id' => 9001]],
                ['product_id' => 20, 'variation_id' => 201],
            ],
            'order_id' => 9002,
        ],
        [],
        true,
        false,
        'corr-catalog'
    );
    $resources = $method->invoke($agent, [$catalog]);
    $check(isset($resources['product']['10'], $resources['product']['20']), 'Multiple authorized product IDs collapsed to one value.');
    $check(isset($resources['variation']['101'], $resources['variation']['201']), 'Multiple authorized variation IDs collapsed to one value.');
    $check(!isset($resources['order']), 'A catalog result conferred order authority from an unrelated nested ID.');

    $reordered = new ToolResult(
        'call-catalog-2',
        'catalog.search_products',
        'succeeded',
        'ok',
        ['candidates' => [
            ['product_id' => 20, 'variation_id' => 201],
            ['product_id' => 10, 'variation_id' => 101],
        ]],
        [],
        true,
        false,
        'corr-catalog'
    );
    $reorderedResources = $method->invoke($agent, [$reordered]);
    $check($resources['product'] === $reorderedResources['product'], 'Resource authority changed with provider output order.');
});

$test('guest touch returns the current CAS version for authenticated linking', static function () use ($check): void {
    $clock = new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'));
    $repository = new InMemoryGuestSessionRepository();
    $manager = new GuestSessionManager($repository, new SecretDigester(str_repeat('g', 32)), $clock);
    $created = $manager->create();
    $resolved = $manager->resolveFromRawToken($created['raw_token']);
    $check($resolved !== null && $resolved->session->version === 2, 'Resolved guest context retained a pre-touch version.');
    $candidate = $manager->candidateForAuthenticatedLink($created['raw_token']);
    $check($candidate !== null && $candidate->version === 2, 'Authenticated link candidate was stale.');
    $check($manager->markLinkedToUser($candidate, 17), 'Current guest session could not be linked.');
    $check($manager->candidateForAuthenticatedLink($created['raw_token']) === null, 'Linked guest token remained active.');
});

$test('compound cart outcomes never label all-failed or partial plans succeeded', static function () use ($check): void {
    $failed = MutationPlanOutcome::fromResults([
        ['operation' => 'remove_item', 'result' => ['ok' => false, 'code' => 'cart_line_not_found']],
        ['operation' => 'apply_coupon', 'result' => ['ok' => false, 'code' => 'coupon_not_applied']],
    ]);
    $check($failed['ok'] === false && $failed['result_status'] === 'failed' && $failed['success_count'] === 0, 'All-failed plan was labeled successful.');
    $partial = MutationPlanOutcome::fromResults([
        ['operation' => 'add_item', 'result' => ['ok' => true, 'code' => 'cart_item_added']],
        ['operation' => 'apply_coupon', 'result' => ['ok' => false, 'code' => 'coupon_not_applied']],
    ]);
    $check($partial['ok'] === true && $partial['result_status'] === 'partial' && $partial['failure_count'] === 1, 'Mixed plan was not labeled partial.');
});

$test('compound cart validates every nested operation before any side effect', static function () use ($check): void {
    $repository = new InMemoryIdempotencyRepository();
    $handler = new CartToolHandler(
        new IdempotencyService(
            $repository,
            new SecretDigester(str_repeat('p', 32)),
            new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'))
        ),
        new \Veyra\AI\Tool\FoundationActorMapper()
    );
    $definitions = [];
    foreach ($handler->definitions() as $definition) {
        $definitions[$definition->name] = $definition;
    }
    $schema = $definitions['cart.execute_mutation_plan']->inputSchema;
    $check(isset($schema['properties']['operations']['items']['properties']['operation']['enum']), 'Plan item schema does not expose the bounded operation enum.');
    $check(($schema['properties']['operations']['minItems'] ?? null) === 1, 'Plan schema does not reject an empty operation list.');

    $call = new ToolCall('call-cart-plan', 'cart.execute_mutation_plan', '1.0.0', [
        'operations' => [
            ['operation' => 'update_quantity', 'arguments' => ['line_id' => 'line-one', 'quantity' => 2]],
            // This field is valid for another operation, but not for apply_coupon.
            // Exact discriminated validation must reject the whole plan before
            // the first update or the idempotency claim can occur.
            ['operation' => 'apply_coupon', 'arguments' => ['line_id' => 'line-two']],
        ],
        'idempotency_key' => 'cart-plan-regression-0001',
    ]);
    $context = new ToolContext(
        'customer',
        'wp-user-19',
        19,
        null,
        '11111111-1111-4111-8111-111111111111',
        [],
        ['commerce_cart' => 'On'],
        'en_US',
        '22222222-2222-4222-8222-222222222222'
    );
    $result = $handler->execute($call, $context);
    $check($result->status === 'failed' && $result->code === 'cart_plan_invalid_or_ambiguous', 'Malformed later operation reached the cart runtime.');
    $check($repository->count() === 0, 'Malformed plan created an idempotency or mutation-side effect.');
});

$test('cart plans reject overlapping resources and require exact final postconditions', static function () use ($check): void {
    $handler = new CartToolHandler(
        new IdempotencyService(
            new InMemoryIdempotencyRepository(),
            new SecretDigester(str_repeat('q', 32)),
            new FrozenClock(UtcInstant::fromDatabase('2026-08-24 10:00:00'))
        ),
        new \Veyra\AI\Tool\FoundationActorMapper()
    );
    $validate = new ReflectionMethod($handler, 'validatePlan');
    $validate->setAccessible(true);
    $conflicting = $validate->invoke($handler, [
        ['operation' => 'update_quantity', 'arguments' => ['line_id' => 'line-one', 'quantity' => 2]],
        ['operation' => 'remove_item', 'arguments' => ['line_id' => 'line-one']],
    ]);
    $check($conflicting === null, 'Update and remove of the same line were accepted in one plan.');
    $distinctVariations = $validate->invoke($handler, [
        ['operation' => 'add_item', 'arguments' => ['product_id' => 5, 'variation_id' => 51, 'quantity' => 1]],
        ['operation' => 'add_item', 'arguments' => ['product_id' => 5, 'variation_id' => 52, 'quantity' => 1]],
    ]);
    $check(is_array($distinctVariations), 'Distinct exact variations were incorrectly treated as one mutation target.');

    $verify = new ReflectionMethod($handler, 'mutationPostconditionsSatisfied');
    $verify->setAccessible(true);
    $operation = [
        'ok' => true,
        'code' => 'cart_item_added',
        'line_id' => 'line-added',
        'product_id' => 5,
        'variation_id' => 51,
        'quantity' => 1.0,
        'expected_quantity' => 2.0,
    ];
    $cart = [
        'lines' => [[
            'line_id' => 'line-added',
            'product_id' => 5,
            'variation_id' => 51,
            'quantity' => 2.0,
        ]],
        'coupons' => [],
    ];
    $check($verify->invoke($handler, 'cart.add_item', $operation, $cart) === true, 'Exact merged-line postcondition was rejected.');
    $cart['lines'][0]['quantity'] = 1.0;
    $check($verify->invoke($handler, 'cart.add_item', $operation, $cart) === false, 'Hook-modified cart quantity was reported as verified success.');
});

fwrite(STDOUT, sprintf("Foundation scenarios: %d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
