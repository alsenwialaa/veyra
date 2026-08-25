<?php
declare(strict_types=1);

use Veyra\Experience\Contract\ExperienceConfigurationValidator;
use Veyra\Experience\Contract\MessagePayloadAssembler;
use Veyra\Experience\Contract\ProductReferenceIdentity;
use Veyra\Experience\Contract\RenderingPayload;
use Veyra\Experience\Presentation\ChatRestController;
use Veyra\Experience\Presentation\CustomerExperience;
use Veyra\Http\CustomerMessagePresenter;

$veyraCustomerPresentationCalls = [
    'actions' => [],
    'shortcodes' => [],
    'registered_styles' => [],
    'registered_scripts' => [],
    'enqueued_styles' => [],
    'enqueued_scripts' => [],
    'inline_scripts' => [],
];

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        global $veyraCustomerPresentationCalls;
        $veyraCustomerPresentationCalls['actions'][] = [$hook, $callback, $priority, $acceptedArgs];
        return true;
    }
}
if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): bool
    {
        global $veyraCustomerPresentationCalls;
        $veyraCustomerPresentationCalls['shortcodes'][] = [$tag, $callback];
        return true;
    }
}
if (!function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'https://shop.example/wp-content/plugins/veyra-ai-commerce-agent/' . ltrim($path, '/');
    }
}
if (!function_exists('wp_register_style')) {
    function wp_register_style(string $handle, string $src, array $dependencies = [], $version = false, string $media = 'all'): bool
    {
        global $veyraCustomerPresentationCalls;
        $veyraCustomerPresentationCalls['registered_styles'][] = [$handle, $src, $dependencies, $version, $media];
        return true;
    }
}
if (!function_exists('wp_register_script')) {
    function wp_register_script(string $handle, string $src, array $dependencies = [], $version = false, $arguments = false): bool
    {
        global $veyraCustomerPresentationCalls;
        $veyraCustomerPresentationCalls['registered_scripts'][] = [$handle, $src, $dependencies, $version, $arguments];
        return true;
    }
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle): void
    {
        global $veyraCustomerPresentationCalls;
        $veyraCustomerPresentationCalls['enqueued_styles'][] = $handle;
    }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle): void
    {
        global $veyraCustomerPresentationCalls;
        $veyraCustomerPresentationCalls['enqueued_scripts'][] = $handle;
    }
}
if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        global $veyraCustomerPresentationCalls;
        $veyraCustomerPresentationCalls['inline_scripts'][] = [$handle, $data, $position];
        return true;
    }
}
if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'https://shop.example/wp-json/' . ltrim($path, '/');
    }
}
if (!function_exists('determine_locale')) {
    function determine_locale(): string
    {
        return 'en_US';
    }
}
if (!function_exists('is_rtl')) {
    function is_rtl(): bool
    {
        return false;
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return false;
    }
}
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../../src/Experience/Contract/RenderingPayload.php';
require_once __DIR__ . '/../../src/Experience/Contract/ExperienceConfigurationValidator.php';
require_once __DIR__ . '/../../src/Experience/Contract/MessagePayloadAssembler.php';
require_once __DIR__ . '/../../src/Http/CustomerMessagePresenter.php';

function veyraAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = [
    'schema_version' => RenderingPayload::SCHEMA_VERSION,
    'message_id' => 'message:1',
    'conversation_id' => 'conversation:1',
    'sender' => 'ai',
    'content' => [
        'text' => 'متوفر الآن · SKU AB-12',
        'language' => 'ar',
        'direction' => 'rtl',
    ],
    'created_at' => '2026-08-24T05:00:00+03:00',
    'display_timezone' => 'Asia/Aden',
    'state' => 'delivered',
    'rendering_version' => 'render:1',
    'reply_quote' => [
        'schema_version' => 'veyra.reply_quote.v1',
        'source_message_id' => 'message:0',
        'source_sender' => 'shopper',
        'excerpt' => 'هل AB-12 متوفر؟',
        'source_time' => '2026-08-24T04:59:00+03:00',
        'source_available' => true,
        'redacted' => false,
    ],
    'product_references' => [[
        'schema_version' => 'veyra.product_reference.v1',
        'reference_id' => 'ref:presentation-only',
        'source_message_id' => 'message:persisted:42',
        'snapshot' => ['product_id' => 42, 'variation_id' => 7, 'name' => 'قهوة AB-12', 'shown_price' => '1,200 YER'],
        'context_only' => true,
        'commerce_authorization' => false,
    ]],
    'components' => [[
        'schema_version' => 'veyra.component.v1',
        'component_id' => 'component:1',
        'type' => 'product',
        'snapshot' => ['name' => 'قهوة AB-12', 'price' => '1,200 YER'],
        'current_state' => ['stock' => 'in_stock'],
        'actions' => [],
    ]],
    'correlation_id' => 'corr:1',
];

$payload = RenderingPayload::fromArray($source);
$source['content']['text'] = 'silently changed';
veyraAssert($payload->toArray()['content']['text'] !== 'silently changed', 'Historical payload must be immutable.');

$invalid = $payload->toArray();
$invalid['product_references'] = [[], [], [], []];
$thrown = false;
try {
    RenderingPayload::fromArray($invalid);
} catch (\InvalidArgumentException $exception) {
    $thrown = true;
}
veyraAssert($thrown, 'A fourth product reference must be rejected.');

$authorityEscalation = $payload->toArray();
$authorityEscalation['product_references'][0]['commerce_authorization'] = true;
$thrown = false;
try {
    RenderingPayload::fromArray($authorityEscalation);
} catch (\InvalidArgumentException $exception) {
    $thrown = true;
}
veyraAssert($thrown, 'A historical product reference must never confer commerce authority.');

$presented = (new CustomerMessagePresenter(new MessagePayloadAssembler()))->present('conversation:1', [
    'message_id' => 'message:persisted:42',
    'sender_type' => 'customer',
    'content' => ['text' => 'Tell me about this product.'],
    'render' => ['language' => 'en', 'direction' => 'ltr'],
    'language' => 'en',
    'direction' => 'ltr',
    'created_at' => '2026-08-24T05:00:00+03:00',
    'rendering_schema_version' => 'render:1',
    'product_references' => [[
        'product_id' => 42,
        'variation_id' => 7,
        'name' => 'Coffee',
        'shown_price' => '1,200 YER',
    ]],
]);
$presentedReference = $presented['product_references'][0];
veyraAssert($presentedReference['source_message_id'] === 'message:persisted:42', 'Presenter must expose the persisted source message id.');
veyraAssert($presentedReference['reference_id'] !== $presentedReference['source_message_id'], 'Presentation identity must remain separate from source authority.');
veyraAssert($presentedReference['context_only'] === true && $presentedReference['commerce_authorization'] === false, 'Historical references must remain context-only.');

$sourceReferences = [[
    'product_id' => 42,
    'variation_id' => 7,
    'name' => 'Coffee, small',
], [
    'product_id' => 42,
    'variation_id' => 8,
    'name' => 'Coffee, large',
]];
$publicReferences = ProductReferenceIdentity::presentReferences($sourceReferences, 'message:persisted:multi');
$secondBinding = [
    'schema_version' => ProductReferenceIdentity::BINDING_SCHEMA_VERSION,
    'reference_id' => $publicReferences[1]['reference_id'],
    'source_message_id' => 'message:persisted:multi',
    'product_id' => 42,
    'variation_id' => 8,
];
$matched = ProductReferenceIdentity::match($sourceReferences, 'message:persisted:multi', $secondBinding);
veyraAssert(($matched['snapshot']['variation_id'] ?? null) === 8, 'An exact token must select its bound variation, not the first source-message reference.');
veyraAssert(
    ProductReferenceIdentity::match($sourceReferences, 'message:persisted:multi', [...$secondBinding, 'variation_id' => 7]) === null,
    'A token and product/variation tuple mismatch must fail closed.'
);

$controller = (new ReflectionClass(ChatRestController::class))->newInstanceWithoutConstructor();
$validMessageCommand = new ReflectionMethod(ChatRestController::class, 'validMessageCommand');
$validMessageCommand->setAccessible(true);
$command = [
    'schema_version' => 'veyra.customer_message_command.v1',
    'client_message_id' => 'message:client:0001',
    'conversation_id' => 'conversation:1',
    'text' => 'Tell me about the large one.',
    'language' => 'en',
    'direction' => 'auto',
    'reply_to_message_id' => null,
    'product_references' => [$secondBinding],
    'answer_binding' => null,
];
veyraAssert($validMessageCommand->invoke($controller, $command) === true, 'The REST command must accept a complete exact product-reference binding.');
$legacyCommand = $command;
unset($legacyCommand['product_references']);
$legacyCommand['product_reference_ids'] = ['message:persisted:multi'];
veyraAssert($validMessageCommand->invoke($controller, $legacyCommand) === false, 'A legacy source-only product-reference command must fail closed.');

$assembled = (new MessagePayloadAssembler())->assemble(
    'message:2',
    'conversation:1',
    'ai',
    'Authoritative product result.',
    'en',
    'ltr',
    '2026-08-24T05:01:00+03:00',
    'Asia/Aden',
    'render:1',
    [[
        'schema_version' => '1.0.0',
        'type' => 'product',
        'source_tool' => 'catalog.search',
        'source_call_id' => 'call:1',
        'payload' => ['name' => 'Coffee', 'price' => '1,200 YER'],
        'observed_at' => '2026-08-24T02:01:00Z',
        'authoritative' => true,
        'historical' => true,
    ], [
        'schema_version' => '1.0.0',
        'type' => 'cart',
        'source_call_id' => 'call:2',
        'payload' => ['total' => '9,999 YER'],
        'authoritative' => false,
    ]]
);
veyraAssert(count($assembled->toArray()['components']) === 1, 'Non-authoritative commerce components must be dropped.');
veyraAssert($assembled->toArray()['components'][0]['schema_version'] === 'veyra.component.v1', 'Verified output must be wrapped in the rendering schema.');

$validator = new ExperienceConfigurationValidator();
$issues = $validator->validate([
    'schema_version' => ExperienceConfigurationValidator::SCHEMA_VERSION,
    'hidden_parts' => ['current_total', 'ai_identity'],
    'components' => [],
    'tokens' => [
        'colors' => ['brand' => '#0C4A46'],
        'minimum_touch_target_px' => 44,
        'body_font_size_px' => 16,
        'focus_width_px' => 3,
        'motion' => ['honor_reduced_motion' => true, 'maximum_duration_ms' => 200],
    ],
]);
veyraAssert(count(array_filter($issues, static fn (array $issue): bool => $issue['code'] === 'mandatory_truth_hidden')) === 2, 'Mandatory truth hiding must be rejected.');

$customerExperience = new CustomerExperience(
    dirname(__DIR__, 2) . '/veyra-ai-commerce-agent.php',
    static fn (): array => [
        'enabled' => true,
        'mount_launcher' => true,
        'ai_name' => 'Veyra test assistant',
        'ai_disclosure' => 'Deterministic presentation fixture.',
    ]
);
$customerExperience->register();
$footerHooks = array_values(array_filter(
    $veyraCustomerPresentationCalls['actions'],
    static fn (array $hook): bool => $hook[0] === 'wp_footer'
));
veyraAssert(count($footerHooks) === 1, 'The customer launcher must register exactly one footer renderer.');
veyraAssert($footerHooks[0][2] === 5, 'The customer launcher must render before WordPress prints footer scripts at priority 20.');

$customerExperience->registerAssets();
$registeredScript = $veyraCustomerPresentationCalls['registered_scripts'][0] ?? null;
veyraAssert(is_array($registeredScript), 'The production customer script must be registered.');
veyraAssert($registeredScript[0] === 'veyra-customer-experience', 'The registered customer script handle must be stable.');
veyraAssert($registeredScript[4] === true, 'The customer script must remain in the WordPress footer group.');
veyraAssert(
    $veyraCustomerPresentationCalls['enqueued_scripts'] === ['veyra-customer-experience'],
    'An enabled customer surface must enqueue the production client exactly once.'
);
$inlineScript = $veyraCustomerPresentationCalls['inline_scripts'][0] ?? null;
veyraAssert(is_array($inlineScript) && $inlineScript[2] === 'before', 'The typed bootstrap must print before the customer client.');
veyraAssert(
    str_contains($inlineScript[1], 'veyra.customer_bootstrap.v1') && str_contains($inlineScript[1], 'Object.freeze'),
    'The customer client must receive a frozen versioned bootstrap.'
);

fwrite(STDOUT, "Veyra PHP rendering contracts passed.\n");
