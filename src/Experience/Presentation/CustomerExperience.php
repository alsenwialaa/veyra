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
        $strings = [
            'open_chat' => 'Open Veyra chat',
            'close_chat' => 'Close chat',
            'back' => 'Back',
            'history' => 'Conversation history',
            'new_conversation' => 'New conversation',
            'load_older' => 'Load older messages',
            'jump_latest' => 'Jump to latest',
            'timeline' => 'Conversation timeline',
            'empty_title' => 'How can I help?',
            'empty_body' => 'Ask naturally about products, your cart, checkout, an order, or support.',
            'message_label' => 'Message Veyra',
            'message_placeholder' => 'Type a message…',
            'send' => 'Send',
            'sending' => 'Sending message',
            'processing' => 'Veyra is working on your request',
            'stop' => 'Stop response',
            'remove_reply' => 'Remove reply quote',
            'remove_reference' => 'Remove product reference',
            'reply' => 'Reply',
            'ask_about' => 'Ask about this product',
            'quote_pending' => 'Quote will be verified when sent',
            'reference_pending' => 'Product reference will be verified when sent',
            'original_unavailable' => 'Original message unavailable',
            'current_unavailable' => 'Current state unavailable',
            'offline' => 'You are offline. Your draft is saved; nothing will be sent automatically.',
            'reconnecting' => 'Connection restored. Refreshing this conversation without resending anything.',
            'session_expired' => 'Your session expired. Your draft is preserved; sign in or refresh before sending.',
            'delivery_uncertain' => 'Delivery is uncertain. Refresh the conversation before deciding whether to send again.',
            'failed' => 'The message was not accepted.',
            'retry_safe' => 'Retry safely',
            'retrying' => 'Retrying',
            'refresh' => 'Refresh conversation',
            'cancelled' => 'Response stopped',
            'confirm_title' => 'Confirm this action',
            'confirm_action' => 'Confirm',
            'cancel_action' => 'Cancel',
            'historical' => 'Shown as it appeared then',
            'current' => 'Current state',
            'ai_badge' => 'AI',
            'human_badge' => 'Store team',
            'system_badge' => 'Store update',
            'quick_replies' => 'Quick replies',
            'product_references' => 'Product references',
            'you' => 'You',
            'yes' => 'Yes',
            'no' => 'No',
            'delivered_server' => 'Delivered to server',
            'product' => 'Product',
            'product_reference' => 'Product reference',
            'variation' => 'Variation',
            'quantity' => 'Quantity',
            'shown_price' => 'Shown price',
            'shown_stock' => 'Shown stock',
            'why_fits' => 'Why this fits',
            'current_stock' => 'Current stock',
            'purchasable' => 'Purchasable',
            'cart_result' => 'Cart result',
            'changed' => 'Changed',
            'unchanged' => 'Unchanged',
            'could_not_change' => 'Could not change',
            'discounts' => 'Discounts',
            'fees' => 'Fees',
            'tax' => 'Tax',
            'shipping' => 'Shipping',
            'current_total' => 'Current total',
            'checkout' => 'Checkout',
            'fulfillment' => 'Fulfillment',
            'contact' => 'Contact',
            'shipping_method' => 'Shipping method',
            'payment_method' => 'Payment method',
            'subtotal' => 'Subtotal',
            'final_total' => 'Final total',
            'confirmation_unavailable' => 'Confirmation is unavailable until every material value is fresh and complete.',
            'order' => 'Order',
            'order_number' => 'Order number',
            'order_status' => 'Order status',
            'payment_status' => 'Payment status',
            'fulfillment_status' => 'Fulfillment status',
            'tracking_status' => 'Tracking status',
            'crm_case' => 'CRM case',
            'payment_review' => 'Payment review',
            'case' => 'Case',
            'review' => 'Review',
            'submission_status' => 'Submission status',
            'decision_status' => 'Decision status',
            'execution_status' => 'Execution status',
            'current_service_status' => 'Current service status',
            'decision_execution_separate' => 'A review decision is separate from any WooCommerce execution.',
            'confirmation_expired' => 'This confirmation expired. Refresh the conversation for a current summary.',
            'historical_preserved' => 'Historical message content was preserved; refreshed state is shown separately.',
            'maximum_references' => 'A message can reference at most three products.',
        ];

        if (function_exists('__')) {
            foreach ($strings as $key => $value) {
                $strings[$key] = __($value, 'veyra-ai-commerce-agent');
            }
        }

        return $strings;
    }
}
