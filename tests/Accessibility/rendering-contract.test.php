<?php
declare(strict_types=1);

use Veyra\Experience\Contract\ExperienceConfigurationValidator;
use Veyra\Experience\Contract\MessagePayloadAssembler;
use Veyra\Experience\Contract\ProductReferenceIdentity;
use Veyra\Experience\Contract\RenderingPayload;
use Veyra\Experience\Presentation\ChatRestController;
use Veyra\Http\CustomerMessagePresenter;

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

fwrite(STDOUT, "Veyra PHP rendering contracts passed.\n");
