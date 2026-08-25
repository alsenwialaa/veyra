<?php
declare(strict_types=1);

namespace Veyra\Experience\Presentation;

use Closure;
use Veyra\Identity\Domain\CapabilityRegistry;

/**
 * WordPress presentation adapter for Veyra's customer messaging surfaces.
 *
 * The adapter registers no REST routes and performs no commerce work. It mounts
 * the client only when the injected effective-state provider returns enabled.
 */
final class CustomerExperience
{
    private string $pluginFile;
    private string $templatePath;
    private string $assetVersion;
    private Closure $configurationProvider;
    private bool $assetsEnqueued = false;
    private bool $configurationPrinted = false;
    private int $instanceSequence = 0;

    /**
     * @param callable():array<string,mixed> $configurationProvider
     */
    public function __construct(
        string $pluginFile,
        callable $configurationProvider,
        string $assetVersion = '0.1.7'
    ) {
        $this->pluginFile = $pluginFile;
        $this->templatePath = dirname($pluginFile) . '/templates/customer/chat-shell.php';
        $this->assetVersion = $assetVersion;
        $this->configurationProvider = Closure::fromCallable($configurationProvider);
    }

    public function register(): void
    {
        if (!function_exists('add_action') || !function_exists('add_shortcode')) {
            return;
        }

        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);
        add_action('wp_footer', [$this, 'renderLauncher'], 30);
        add_shortcode('veyra_chat', [$this, 'renderShortcode']);
    }

    public function registerAssets(): void
    {
        if (!function_exists('wp_register_style') || !function_exists('plugins_url')) {
            return;
        }

        wp_register_style(
            'veyra-customer-experience',
            plugins_url('assets/customer/veyra-chat.css', $this->pluginFile),
            [],
            $this->assetVersion
        );
        wp_register_script(
            'veyra-customer-experience',
            plugins_url('assets/customer/veyra-chat.js', $this->pluginFile),
            [],
            $this->assetVersion,
            true
        );

        // Enqueue during wp_enqueue_scripts so both launcher and shortcode
        // surfaces receive CSS before wp_head has finished rendering.
        $configuration = $this->configuration();
        if ($configuration['enabled']) {
            $this->enqueueAssets($configuration);
        }
    }

    public function renderLauncher(): void
    {
        $configuration = $this->configuration();
        if (!$configuration['enabled'] || !$configuration['mount_launcher']) {
            return;
        }

        echo $this->renderSurface('launcher', false, $configuration); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function renderShortcode(array $attributes = []): string
    {
        $configuration = $this->configuration();
        if (!$configuration['enabled']) {
            return '';
        }

        if (function_exists('shortcode_atts')) {
            $attributes = shortcode_atts(
                ['surface' => 'full', 'open' => 'true'],
                $attributes,
                'veyra_chat'
            );
        }

        $surface = isset($attributes['surface']) && in_array($attributes['surface'], ['full', 'embedded', 'panel'], true)
            ? (string) $attributes['surface']
            : 'full';
        $open = !isset($attributes['open']) || $attributes['open'] !== 'false';

        return $this->renderSurface($surface, $open, $configuration);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function renderSurface(string $surface, bool $open, array $configuration): string
    {
        $this->enqueueAssets($configuration);
        if (!is_readable($this->templatePath)) {
            return '';
        }

        ++$this->instanceSequence;
        $veyraInstanceId = 'veyra-chat-' . $this->instanceSequence;
        $veyraSurface = $surface;
        $veyraOpen = $open;
        $veyraAiName = (string) $configuration['ai_name'];
        $veyraDisclosure = (string) $configuration['ai_disclosure'];
        $veyraDirection = (string) $configuration['direction'];
        $veyraStrings = $configuration['strings'];

        ob_start();
        require $this->templatePath;
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $configuration */
    private function enqueueAssets(array $configuration): void
    {
        if ($this->assetsEnqueued || !function_exists('wp_enqueue_style')) {
            return;
        }

        wp_enqueue_style('veyra-customer-experience');
        wp_enqueue_script('veyra-customer-experience');

        if (!$this->configurationPrinted && function_exists('wp_add_inline_script')) {
            $json = function_exists('wp_json_encode')
                ? wp_json_encode($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                : json_encode($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (is_string($json)) {
                wp_add_inline_script(
                    'veyra-customer-experience',
                    'window.VeyraChatBootstrap = Object.freeze(' . $json . ');',
                    'before'
                );
                $this->configurationPrinted = true;
            }
        }

        $this->assetsEnqueued = true;
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $provided = ($this->configurationProvider)();
        if (!is_array($provided)) {
            $provided = [];
        }

        $defaults = [
            'schema_version' => 'veyra.customer_bootstrap.v1',
            'enabled' => false,
            'mount_launcher' => true,
            'rest_base' => function_exists('rest_url') ? rest_url('veyra/v1') : '/wp-json/veyra/v1',
            'nonce' => function_exists('is_user_logged_in') && is_user_logged_in() && function_exists('wp_create_nonce')
                ? wp_create_nonce('wp_rest')
                : '',
            'locale' => function_exists('determine_locale') ? determine_locale() : 'en_US',
            'direction' => function_exists('is_rtl') && is_rtl() ? 'rtl' : 'ltr',
            'ai_name' => 'Veyra',
            'ai_disclosure' => function_exists('__')
                ? __('AI shopping assistant. Store staff may review retained conversations.', 'veyra-ai-commerce-agent')
                : 'AI shopping assistant. Store staff may review retained conversations.',
            'conversation_id' => null,
            'actor_scope' => 'guest',
            'draft_storage' => 'session',
            // Three bounded 25-second provider calls plus server rendering and
            // persistence must finish before the browser aborts the request.
            'request_timeout_ms' => 90000,
            'max_message_length' => 4000,
            'routes' => [
                'history' => '/conversations/current/messages',
                'send' => '/conversations/current/messages',
                'cancel' => '/conversations/current/turns/cancel',
                'interaction' => '/conversations/current/interactions',
                'new_conversation' => '/conversations',
            ],
            'capabilities' => [
                'new_conversation' => false,
                'stop_response' => true,
                'quick_replies' => true,
                'product_references' => true,
            ],
            'strings' => $this->defaultStrings(),
        ];

        $configuration = array_replace($defaults, $provided);
        $configuration['routes'] = array_replace(
            $defaults['routes'],
            is_array($provided['routes'] ?? null) ? $provided['routes'] : []
        );
        $configuration['capabilities'] = array_replace(
            $defaults['capabilities'],
            is_array($provided['capabilities'] ?? null) ? $provided['capabilities'] : []
        );
        $configuration['strings'] = array_replace(
            $defaults['strings'],
            is_array($provided['strings'] ?? null) ? $provided['strings'] : []
        );
        foreach ($defaults['strings'] as $key => $fallback) {
            $configuration['strings'][$key] = $this->boundedText(
                $configuration['strings'][$key] ?? null,
                $fallback,
                500
            );
        }

        $configuration['enabled'] = $configuration['enabled'] === true;
        $configuration['mount_launcher'] = $configuration['mount_launcher'] === true;
        if (!$this->customerSurfaceAllowed()) {
            // WordPress users carrying any Veyra staff capability are resolved as
            // staff actors by the backend. Do not expose a customer composer that
            // its REST permission boundary must reject.
            $configuration['enabled'] = false;
            $configuration['mount_launcher'] = false;
            $configuration['nonce'] = '';
            $configuration['actor_scope'] = 'staff_blocked';
        }
        $configuration['ai_name'] = $this->boundedText($configuration['ai_name'], $defaults['ai_name'], 80);
        $configuration['ai_disclosure'] = $this->boundedText($configuration['ai_disclosure'], $defaults['ai_disclosure'], 240);
        if (!in_array($configuration['draft_storage'], ['none', 'session', 'local'], true)) {
            $configuration['draft_storage'] = 'session';
        }
        $configuration['request_timeout_ms'] = max(90000, min(120000, (int) $configuration['request_timeout_ms']));
        $configuration['max_message_length'] = max(250, min(20000, (int) $configuration['max_message_length']));
        $configuration['rest_base'] = is_string($configuration['rest_base'])
            ? $configuration['rest_base']
            : $defaults['rest_base'];
        $configuration['nonce'] = is_string($configuration['nonce'])
            && strlen($configuration['nonce']) <= 128
            && preg_match('/^[A-Za-z0-9_-]*$/D', $configuration['nonce']) === 1
                ? $configuration['nonce']
                : '';
        $configuration['actor_scope'] = is_string($configuration['actor_scope'])
            ? $this->boundedText($configuration['actor_scope'], 'guest', 128)
            : 'guest';
        $configuration['direction'] = in_array($configuration['direction'], ['ltr', 'rtl', 'auto'], true)
            ? $configuration['direction']
            : 'ltr';

        return $configuration;
    }

    private function customerSurfaceAllowed(): bool
    {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !function_exists('wp_get_current_user')) {
            return true;
        }
        $user = wp_get_current_user();
        if (!$user instanceof \WP_User || !$user->exists()) {
            return true;
        }
        foreach (CapabilityRegistry::names() as $capability) {
            if ($user->has_cap($capability)) {
                return false;
            }
        }

        return true;
    }

    /** @param mixed $value */
    private function boundedText($value, string $fallback, int $maximum): string
    {
        if (!is_string($value) || trim($value) === '') {
            return $fallback;
        }

        $value = trim($value);
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $maximum, 'UTF-8')
            : substr($value, 0, $maximum);
    }

    /** @return array<string, string> */
    private function defaultStrings(): array
    {
        return [
            'open_chat' => __('Open Veyra chat', 'veyra-ai-commerce-agent'),
            'close_chat' => __('Close chat', 'veyra-ai-commerce-agent'),
            'back' => __('Back', 'veyra-ai-commerce-agent'),
            'history' => __('Conversation history', 'veyra-ai-commerce-agent'),
            'new_conversation' => __('New conversation', 'veyra-ai-commerce-agent'),
            'load_older' => __('Load older messages', 'veyra-ai-commerce-agent'),
            'jump_latest' => __('Jump to latest', 'veyra-ai-commerce-agent'),
            'timeline' => __('Conversation timeline', 'veyra-ai-commerce-agent'),
            'empty_title' => __('How can I help?', 'veyra-ai-commerce-agent'),
            'empty_body' => __('Ask naturally about products, your cart, checkout, an order, or support.', 'veyra-ai-commerce-agent'),
            'message_label' => __('Message Veyra', 'veyra-ai-commerce-agent'),
            'message_placeholder' => __('Type a message…', 'veyra-ai-commerce-agent'),
            'send' => __('Send', 'veyra-ai-commerce-agent'),
            'sending' => __('Sending message', 'veyra-ai-commerce-agent'),
            'processing' => __('Veyra is working on your request', 'veyra-ai-commerce-agent'),
            'stop' => __('Stop response', 'veyra-ai-commerce-agent'),
            'remove_reply' => __('Remove reply quote', 'veyra-ai-commerce-agent'),
            'remove_reference' => __('Remove product reference', 'veyra-ai-commerce-agent'),
            'reply' => __('Reply', 'veyra-ai-commerce-agent'),
            'ask_about' => __('Ask about this product', 'veyra-ai-commerce-agent'),
            'quote_pending' => __('Quote will be verified when sent', 'veyra-ai-commerce-agent'),
            'reference_pending' => __('Product reference will be verified when sent', 'veyra-ai-commerce-agent'),
            'original_unavailable' => __('Original message unavailable', 'veyra-ai-commerce-agent'),
            'current_unavailable' => __('Current state unavailable', 'veyra-ai-commerce-agent'),
            'offline' => __('You are offline. Your draft is saved; nothing will be sent automatically.', 'veyra-ai-commerce-agent'),
            'reconnecting' => __('Connection restored. Refreshing this conversation without resending anything.', 'veyra-ai-commerce-agent'),
            'session_expired' => __('Your session expired. Your draft is preserved; sign in or refresh before sending.', 'veyra-ai-commerce-agent'),
            'delivery_uncertain' => __('Delivery is uncertain. Refresh the conversation before deciding whether to send again.', 'veyra-ai-commerce-agent'),
            'failed' => __('The message was not accepted.', 'veyra-ai-commerce-agent'),
            'retry_safe' => __('Retry safely', 'veyra-ai-commerce-agent'),
            'retrying' => __('Retrying', 'veyra-ai-commerce-agent'),
            'refresh' => __('Refresh conversation', 'veyra-ai-commerce-agent'),
            'cancelled' => __('Response stopped', 'veyra-ai-commerce-agent'),
            'confirm_title' => __('Confirm this action', 'veyra-ai-commerce-agent'),
            'confirm_action' => __('Confirm', 'veyra-ai-commerce-agent'),
            'cancel_action' => __('Cancel', 'veyra-ai-commerce-agent'),
            'historical' => __('Shown as it appeared then', 'veyra-ai-commerce-agent'),
            'current' => __('Current state', 'veyra-ai-commerce-agent'),
            'ai_badge' => __('AI', 'veyra-ai-commerce-agent'),
            'human_badge' => __('Store team', 'veyra-ai-commerce-agent'),
            'system_badge' => __('Store update', 'veyra-ai-commerce-agent'),
            'quick_replies' => __('Quick replies', 'veyra-ai-commerce-agent'),
            'product_references' => __('Product references', 'veyra-ai-commerce-agent'),
            'you' => __('You', 'veyra-ai-commerce-agent'),
            'yes' => __('Yes', 'veyra-ai-commerce-agent'),
            'no' => __('No', 'veyra-ai-commerce-agent'),
            'delivered_server' => __('Delivered to server', 'veyra-ai-commerce-agent'),
            'product' => __('Product', 'veyra-ai-commerce-agent'),
            'product_reference' => __('Product reference', 'veyra-ai-commerce-agent'),
            'variation' => __('Variation', 'veyra-ai-commerce-agent'),
            'quantity' => __('Quantity', 'veyra-ai-commerce-agent'),
            'shown_price' => __('Shown price', 'veyra-ai-commerce-agent'),
            'shown_stock' => __('Shown stock', 'veyra-ai-commerce-agent'),
            'why_fits' => __('Why this fits', 'veyra-ai-commerce-agent'),
            'current_stock' => __('Current stock', 'veyra-ai-commerce-agent'),
            'purchasable' => __('Purchasable', 'veyra-ai-commerce-agent'),
            'cart_result' => __('Cart result', 'veyra-ai-commerce-agent'),
            'changed' => __('Changed', 'veyra-ai-commerce-agent'),
            'unchanged' => __('Unchanged', 'veyra-ai-commerce-agent'),
            'could_not_change' => __('Could not change', 'veyra-ai-commerce-agent'),
            'discounts' => __('Discounts', 'veyra-ai-commerce-agent'),
            'fees' => __('Fees', 'veyra-ai-commerce-agent'),
            'tax' => __('Tax', 'veyra-ai-commerce-agent'),
            'shipping' => __('Shipping', 'veyra-ai-commerce-agent'),
            'current_total' => __('Current total', 'veyra-ai-commerce-agent'),
            'checkout' => __('Checkout', 'veyra-ai-commerce-agent'),
            'fulfillment' => __('Fulfillment', 'veyra-ai-commerce-agent'),
            'contact' => __('Contact', 'veyra-ai-commerce-agent'),
            'shipping_method' => __('Shipping method', 'veyra-ai-commerce-agent'),
            'payment_method' => __('Payment method', 'veyra-ai-commerce-agent'),
            'subtotal' => __('Subtotal', 'veyra-ai-commerce-agent'),
            'final_total' => __('Final total', 'veyra-ai-commerce-agent'),
            'confirmation_unavailable' => __('Confirmation is unavailable until every material value is fresh and complete.', 'veyra-ai-commerce-agent'),
            'order' => __('Order', 'veyra-ai-commerce-agent'),
            'order_number' => __('Order number', 'veyra-ai-commerce-agent'),
            'order_status' => __('Order status', 'veyra-ai-commerce-agent'),
            'payment_status' => __('Payment status', 'veyra-ai-commerce-agent'),
            'fulfillment_status' => __('Fulfillment status', 'veyra-ai-commerce-agent'),
            'tracking_status' => __('Tracking status', 'veyra-ai-commerce-agent'),
            'crm_case' => __('CRM case', 'veyra-ai-commerce-agent'),
            'payment_review' => __('Payment review', 'veyra-ai-commerce-agent'),
            'case' => __('Case', 'veyra-ai-commerce-agent'),
            'review' => __('Review', 'veyra-ai-commerce-agent'),
            'submission_status' => __('Submission status', 'veyra-ai-commerce-agent'),
            'decision_status' => __('Decision status', 'veyra-ai-commerce-agent'),
            'execution_status' => __('Execution status', 'veyra-ai-commerce-agent'),
            'current_service_status' => __('Current service status', 'veyra-ai-commerce-agent'),
            'decision_execution_separate' => __('A review decision is separate from any WooCommerce execution.', 'veyra-ai-commerce-agent'),
            'confirmation_expired' => __('This confirmation expired. Refresh the conversation for a current summary.', 'veyra-ai-commerce-agent'),
            'historical_preserved' => __('Historical message content was preserved; refreshed state is shown separately.', 'veyra-ai-commerce-agent'),
            'maximum_references' => __('A message can reference at most three products.', 'veyra-ai-commerce-agent'),
        ];
    }
}
