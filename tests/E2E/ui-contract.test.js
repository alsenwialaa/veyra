'use strict';

const assert = require('node:assert/strict');
const customer = require('../../assets/customer/veyra-chat.js');
const admin = require('../../assets/admin/veyra-admin.js');

const operation = {
    schema_version: 'veyra.operation.v1',
    status: 'succeeded',
    code: 'message_accepted',
    retry_safety: 'safe_no_side_effect',
    correlation_id: 'corr:123'
};

assert.equal(customer.validateOperationEnvelope(operation).valid, true, 'typed operation result is accepted');
assert.equal(admin.validateOperationEnvelope(operation).valid, true, 'admin and customer clients share the REST envelope');
assert.equal(
    customer.validateOperationEnvelope({ ...operation, retry_safety: 'retry' }).valid,
    false,
    'unknown retry semantics are rejected'
);
assert.equal(customer.deriveRetryPolicy({ ...operation, retry_safety: 'safe_no_side_effect' }), 'manual_safe');
assert.equal(customer.deriveRetryPolicy({ ...operation, retry_safety: 'reconcile_before_retry' }), 'reconcile');
assert.equal(customer.deriveRetryPolicy({ ...operation, retry_safety: 'never_retry' }), 'never');
assert.equal(
    customer.validateOperationEnvelope({ ...operation, schema_version: '1.0.0' }).valid,
    false,
    'unrecognized operation envelope versions are rejected'
);

const message = {
    schema_version: 'veyra.message.v1',
    message_id: 'message:1',
    conversation_id: 'conversation:1',
    sender: 'ai',
    content: { text: 'متوفر الآن · SKU AB-12', language: 'ar', direction: 'rtl' },
    created_at: '2026-08-24T05:00:00+03:00',
    display_timezone: 'Asia/Aden',
    state: 'delivered',
    rendering_version: 'render:1',
    product_references: [],
    components: [{
        schema_version: 'veyra.component.v1',
        component_id: 'component:1',
        type: 'product',
        snapshot: { name: 'قهوة AB-12', price: '1,200 YER' },
        current_state: { stock: 'in_stock' },
        actions: []
    }]
};

assert.equal(customer.validateMessagePayload(message).valid, true, 'Arabic mixed-direction rendering payload is accepted');
assert.equal(customer.isAbsoluteTimestamp('2026-08-24T05:00:00+03:00'), true, 'offset timestamps are absolute');
assert.equal(customer.isAbsoluteTimestamp('2026-08-24T02:00:00Z'), true, 'UTC timestamps are absolute');
assert.equal(customer.isAbsoluteTimestamp('2026-08-24 05:00:00'), false, 'timezone-less timestamps are rejected');
const shopperMessage = {
    ...message,
    message_id: 'message:shopper:1',
    sender: 'shopper',
    content: { text: 'أريد هذا المنتج AB-12', language: 'ar', direction: 'rtl' },
    components: []
};
assert.deepEqual(
    customer.extractReturnedMessages({ message }).messages.map((item) => item.message_id),
    ['message:1'],
    'a synchronous endpoint may return its primary message'
);
assert.deepEqual(
    customer.extractReturnedMessages({ messages: [shopperMessage, message] }).messages.map((item) => item.message_id),
    ['message:shopper:1', 'message:1'],
    'a synchronous endpoint may return the persisted shopper and AI messages'
);
assert.equal(
    customer.extractReturnedMessages({ message, messages: [{ ...message, content: { ...message.content, text: 'conflict' } }] }).valid,
    false,
    'conflicting copies of one historical message are rejected'
);
assert.equal(
    customer.validateMessagePayload({ ...message, product_references: [{}, {}, {}, {}] }).valid,
    false,
    'more than three product references are rejected'
);
const historicalReference = {
    schema_version: 'veyra.product_reference.v1',
    reference_id: 'ref:presentation-only',
    source_message_id: 'message:persisted:42',
    snapshot: { product_id: 42, variation_id: 7, name: 'قهوة AB-12', shown_price: '1,200 YER' },
    context_only: true,
    commerce_authorization: false
};
assert.equal(
    customer.validateMessagePayload({ ...message, product_references: [historicalReference] }).valid,
    true,
    'a context-only historical product reference with a persisted source message is accepted'
);
assert.equal(
    customer.productReferenceSourceMessageId(historicalReference),
    'message:persisted:42',
    'the UI retains the actor-owned persisted source message'
);
const exactBinding = {
    schema_version: 'veyra.product_reference_binding.v1',
    reference_id: 'ref:presentation-only',
    source_message_id: 'message:persisted:42',
    product_id: 42,
    variation_id: 7
};
assert.deepEqual(
    customer.productReferenceBinding(historicalReference),
    exactBinding,
    'the outgoing command binds the presentation token to the exact product and variation tuple'
);
assert.deepEqual(
    customer.productReferenceBindings([
        { binding: exactBinding },
        { binding: { ...exactBinding, reference_id: 'ref:second-product', product_id: 84, variation_id: 0 } }
    ]).map((binding) => binding.reference_id),
    ['ref:presentation-only', 'ref:second-product'],
    'two exact references from one source message remain independently addressable'
);
assert.deepEqual(
    customer.productReferenceBindings([{ sourceMessageId: 'message:persisted:42' }]),
    [],
    'legacy source-only reference drafts fail closed'
);
assert.deepEqual(
    customer.normalizeQuickReplies([
        { choice_id: 'choice:delivery', label: ' Delivery ', pending_question_id: 'question:fulfillment' },
        { choice_id: 'choice:delivery', label: 'Duplicate', pending_question_id: 'question:fulfillment' },
        { choice_id: 'choice:pickup', label: 'Pickup', pending_question_id: null },
        { choice_id: 'choice:blank', label: '   ', pending_question_id: 'question:fulfillment' }
    ]),
    [{ choice_id: 'choice:delivery', label: 'Delivery', pending_question_id: 'question:fulfillment' }],
    'quick replies require one exact pending-question binding and bounded visible label'
);
assert.equal(
    customer.validateMessagePayload({
        ...message,
        product_references: [{ ...historicalReference, commerce_authorization: true }]
    }).valid,
    false,
    'a historical reference cannot claim commerce authority'
);
assert.equal(
    customer.validateMessagePayload({ ...message, components: [{ ...message.components[0], type: 'raw_html' }] }).valid,
    false,
    'unknown component renderers are rejected'
);

const sensitiveAction = {
    action_id: 'checkout:confirm',
    label: 'Confirm order',
    kind: 'interaction',
    idempotency_key: 'idem:1',
    requires_confirmation: true,
    confirmation_id: 'confirm:1',
    summary_message_id: 'message:summary',
    state_hash: 'state:abc',
    confirmation_summary: '2 products · 5,000 YER · delivery · cash on delivery',
    summary_complete: true,
    expires_at: '2099-08-24T06:00:00+03:00'
};
assert.equal(customer.validateAction(sensitiveAction).valid, true, 'complete sensitive confirmation contract is accepted');
assert.equal(
    customer.validateAction({ ...sensitiveAction, summary_complete: false }).valid,
    false,
    'incomplete confirmation summary cannot become an action'
);
assert.equal(
    customer.validateAction({
        action_id: 'cart:add', label: 'Add', kind: 'interaction', requires_confirmation: false
    }).valid,
    false,
    'an interaction without an idempotency key is not actionable'
);

assert.equal(customer.safeUrl('javascript:alert(1)', 'https://shop.example'), null, 'script URLs are rejected');
assert.equal(customer.safeUrl('data:text/html,test', 'https://shop.example'), null, 'data URLs are rejected');
assert.equal(customer.safeUrl('/product/1', 'https://shop.example'), 'https://shop.example/product/1');
assert.equal(
    customer.resolveApiUrl('https://shop.example/wp-json/veyra/v1', '../outside', 'https://shop.example'),
    null,
    'REST route traversal outside the configured namespace is rejected'
);
assert.equal(
    customer.resolveApiUrl('https://shop.example/wp-json/veyra/v1', 'https://evil.example/write', 'https://shop.example'),
    null,
    'cross-origin REST routes are rejected'
);
assert.equal(
    customer.resolveApiUrl('https://evil.example/wp-json/veyra/v1', '/conversations', 'https://shop.example'),
    null,
    'a cross-origin REST base cannot receive browser credentials or CSRF headers'
);

assert.equal(customer.shouldReplaceHistorical(null, message), true, 'first historical rendering is accepted');
assert.equal(
    customer.shouldReplaceHistorical(message, { ...message, content: { ...message.content, text: 'new price' } }),
    false,
    'an existing historical message is never silently replaced'
);
assert.match(customer.draftKey('guest:1', 'conversation:1'), /^veyra:draft:v1:/);
assert.equal(customer.bindConversationId(null, 'conversation:1'), 'conversation:1', 'an unbound tab may bind once');
assert.equal(customer.bindConversationId('conversation:1', 'conversation:1'), 'conversation:1', 'the exact conversation remains bound');
assert.equal(customer.bindConversationId('conversation:1', 'conversation:2'), null, 'a tab cannot silently retarget to the latest conversation');
assert.equal(customer.messagesBelongToConversation([message], 'conversation:1'), true, 'history remains inside the bound conversation');
assert.equal(
    customer.messagesBelongToConversation([{ ...message, conversation_id: 'conversation:2' }], 'conversation:1'),
    false,
    'cross-conversation history is rejected'
);
const guestCsrf = 'AbcdEFGH_ijklMNOP-qrstUVWX_1234567890abcd';
assert.equal(
    customer.readGuestCsrf('other=1; veyra_guest_csrf=' + guestCsrf + '; preference=rtl'),
    guestCsrf,
    'the bounded same-origin guest CSRF cookie is read exactly'
);
assert.equal(customer.readGuestCsrf('veyra_guest_csrf=short'), null, 'undersized guest CSRF tokens are rejected');
assert.equal(customer.readGuestCsrf('veyra_guest_csrf=' + 'a'.repeat(193)), null, 'oversized guest CSRF tokens are rejected');
assert.equal(customer.readGuestCsrf('veyra_guest_csrf=' + 'a'.repeat(31) + '!'), null, 'invalid guest CSRF characters are rejected');
assert.deepEqual(
    customer.requestSecurityHeaders('wpNonce_123', 'veyra_guest_csrf=' + guestCsrf),
    { 'X-WP-Nonce': 'wpNonce_123' },
    'a valid WordPress nonce takes precedence over the guest CSRF cookie'
);
assert.deepEqual(
    customer.requestSecurityHeaders('', 'veyra_guest_csrf=' + guestCsrf),
    { 'X-Veyra-CSRF': guestCsrf },
    'a guest mutation sends the separately readable same-origin CSRF cookie'
);
assert.deepEqual(
    customer.requestSecurityHeaders('', 'veyra_guest_csrf=short'),
    {},
    'an invalid guest CSRF cookie is never promoted to a request header'
);

const adminState = {
    schema_version: 'veyra.admin_product_state.v1',
    product: 'experience',
    permissions: { edit: true, publish: true },
    available_actions: ['save_draft', 'validate', 'simulate', 'publish', 'schedule', 'rollback'],
    draft: { version: 'draft:1', configuration: { schema_version: 'veyra.experience.v1' } },
    published: { version: 'published:1' }
};
assert.equal(admin.validateAdminState(adminState, 'experience').valid, true, 'admin product state is typed');
assert.equal(
    admin.validateAdminState({ ...adminState, available_actions: ['execute_order'] }, 'experience').valid,
    false,
    'admin UI cannot invent an execution action'
);

assert.equal(
    admin.featureConfiguredState(
        { features: { ai_context_graph: { configured_state: 'Off' } } },
        { key: 'ai_context_graph', label: 'AI context graph', configured_state: 'On' }
    ),
    'Off',
    'a reloaded commerce feature uses its staged draft state instead of the effective published state'
);
assert.equal(
    admin.featureConfiguredState({}, { key: 'ai_context_graph', configured_state: 'On' }),
    'On',
    'feature resources provide the deterministic fallback when the draft omits a key'
);
assert.equal(
    admin.featureConfiguredState(
        { features: { ai_context_graph: { configured_state: 'invalid' } } },
        { key: 'ai_context_graph', configured_state: 'invalid' }
    ),
    'Off',
    'invalid draft and resource states fail closed'
);
assert.equal(
    admin.featureAccessibleLabel({ key: 'ai_context_graph', label: 'AI context graph' }),
    'Configured state for AI context graph',
    'each dynamic feature select receives an explicit accessible name'
);

const safeExperience = {
    schema_version: 'veyra.experience.v1',
    hidden_parts: [],
    components: {},
    tokens: {
        minimum_touch_target_px: 44,
        body_font_size_px: 16,
        colors: { brand: '#0C4A46' },
        motion: { honor_reduced_motion: true }
    }
};
assert.deepEqual(admin.evaluateTruthConfiguration(safeExperience), [], 'safe Experience configuration passes client preflight');
assert.ok(
    admin.evaluateTruthConfiguration({ ...safeExperience, hidden_parts: ['current_total'] })
        .some((issue) => issue.code === 'mandatory_truth_hidden'),
    'current total cannot be hidden'
);
assert.ok(
    admin.evaluateTruthConfiguration({
        ...safeExperience,
        components: { ai_identity: { visible: false } }
    }).some((issue) => issue.truth === 'ai_identity'),
    'AI identity cannot be hidden'
);
assert.ok(
    admin.evaluateTruthConfiguration({
        ...safeExperience,
        components: { ai_identity: { visible: 'false' } }
    }).some((issue) => issue.truth === 'ai_identity'),
    'truth visibility must be the literal published-on state'
);
assert.ok(
    admin.evaluateTruthConfiguration({
        ...safeExperience,
        tokens: { ...safeExperience.tokens, minimum_touch_target_px: 20 }
    }).some((issue) => issue.code === 'touch_target_invalid'),
    'undersized touch targets are rejected'
);

assert.equal(
    admin.safeRoute('https://shop.example/wp-json/veyra/v1', '/admin/products/{product}', 'agent', 'https://shop.example'),
    'https://shop.example/wp-json/veyra/v1/admin/products/agent'
);
assert.equal(
    admin.safeRoute('https://shop.example/wp-json/veyra/v1', 'https://evil.example/admin', 'agent', 'https://shop.example'),
    null,
    'admin routes stay in the configured REST namespace'
);
assert.equal(
    admin.safeRoute('https://evil.example/wp-json/veyra/v1', '/admin/provider/credential', 'agent', 'https://shop.example'),
    null,
    'a cross-origin admin REST base cannot receive WordPress nonces or provider commands'
);
assert.equal(
    admin.safeRoute('https://shop.example/wp-json/veyra/v1', '/admin/provider/credential', 'agent', 'https://shop.example'),
    'https://shop.example/wp-json/veyra/v1/admin/provider/credential',
    'provider credential actions remain inside the configured REST namespace'
);

const adminJsSource = require('node:fs').readFileSync(require('node:path').join(__dirname, '../../assets/admin/veyra-admin.js'), 'utf8');
assert.match(adminJsSource, /api_key:\s*credential/, 'the credential command uses the protected provider API field');
assert.doesNotMatch(adminJsSource, /credential:\s*credential/, 'the secret is not copied to an unsupported command field');

const fs = require('node:fs');
const path = require('node:path');
const source = (relative) => fs.readFileSync(path.join(__dirname, '../..', relative), 'utf8');
const permissionSource = source('src/Identity/Presentation/RestPermissionGate.php');
assert.ok(
    permissionSource.indexOf('$this->features->inspect') < permissionSource.indexOf('$this->actors->resolve'),
    'feature denial happens before actor resolution'
);
assert.doesNotMatch(
    permissionSource,
    /resolveOrCreateGuest/,
    'REST permission callbacks never persist a guest session'
);
const chatControllerSource = source('src/Experience/Presentation/ChatRestController.php');
assert.ok(
    chatControllerSource.indexOf('consumePreSession') < chatControllerSource.indexOf('resolveOrCreateGuest'),
    'cookie-less guest bootstrap is bounded before session persistence'
);
assert.equal(
    (chatControllerSource.match(/\$this->idempotency->complete\(/g) || []).length,
    1,
    'chat idempotency completion is invoked only through the Throwable-safe transition wrapper'
);
assert.equal(
    (chatControllerSource.match(/\$this->idempotency->fail\(/g) || []).length,
    1,
    'chat idempotency failure is invoked only through the Throwable-safe transition wrapper'
);
assert.equal(
    (chatControllerSource.match(/\$this->idempotency->markUncertain\(/g) || []).length,
    1,
    'chat uncertainty persistence is invoked only through the Throwable-safe transition wrapper'
);
const uninstallSource = source('src/Bootstrap/Uninstaller.php');
assert.ok(
    uninstallSource.indexOf('deleteProtectedObjects($wpdb') < uninstallSource.indexOf('foreach ($tables->all()'),
    'protected objects are deleted before attachment metadata tables'
);
assert.match(uninstallSource, /veyra_payment_review_gateway_ids/, 'payment-review policy is included in uninstall cleanup');
assert.match(uninstallSource, /WordPressPublishedKnowledgeRepository::OPTION/, 'published knowledge is included in uninstall cleanup');
assert.match(uninstallSource, /WordPressPublishedRecommendationPolicyRepository::OPTION/, 'published recommendation policy is included in uninstall cleanup');

console.log('Veyra UI contract tests passed.');
