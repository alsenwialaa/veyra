<?php
declare(strict_types=1);

namespace Veyra\Operations\Presentation;

use Closure;

/**
 * Capability-scoped WordPress shell for the five Veyra administration products.
 * All state and outcomes are loaded from injected REST routes; the shell never
 * claims a draft, validation, publication, rollback, or operation succeeded.
 */
final class AdminProducts
{
    /** @var array<string, array{title:string,menu:string,capability:string,description:string}> */
    private const PRODUCTS = [
        'agent' => [
            'title' => 'Agent Studio',
            'menu' => 'Agent Studio',
            'capability' => 'manage_veyra_agent',
            'description' => 'Define Veyra’s public identity and bounded sales behavior. Provider and tool authority stay separate.',
        ],
        'knowledge' => [
            'title' => 'Knowledge & Context',
            'menu' => 'Knowledge & Context',
            'capability' => 'manage_veyra_context_knowledge',
            'description' => 'Publish approved store knowledge, markets, branches, time, location, culture, and governed memory policy.',
        ],
        'experience' => [
            'title' => 'Experience Studio',
            'menu' => 'Experience Studio',
            'capability' => 'manage_veyra_experience',
            'description' => 'Configure the accessible mobile-first experience while preserving mandatory commercial truth.',
        ],
        'commerce' => [
            'title' => 'Commerce Control',
            'menu' => 'Commerce Control',
            'capability' => 'manage_veyra_features',
            'description' => 'Review configured and effective feature states, dependencies, safe fallbacks, and remediation.',
        ],
        'operations' => [
            'title' => 'Operations Console',
            'menu' => 'Operations Console',
            'capability' => 'view_veyra_dashboard',
            'description' => 'Read privacy-minimized health and workload state. Opening the console has no customer or commerce side effect.',
        ],
    ];

    private string $pluginFile;
    private string $templatePath;
    private string $assetVersion;
    private Closure $configurationProvider;
    private ?string $parentProduct = null;

    /** @param callable():array<string,mixed> $configurationProvider */
    public function __construct(
        string $pluginFile,
        callable $configurationProvider,
        string $assetVersion = '0.1.7'
    ) {
        $this->pluginFile = $pluginFile;
        $this->templatePath = dirname($pluginFile) . '/templates/admin/studio-shell.php';
        $this->assetVersion = $assetVersion;
        $this->configurationProvider = Closure::fromCallable($configurationProvider);
    }

    public function register(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('admin_menu', [$this, 'registerMenus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenus(): void
    {
        if (!function_exists('add_menu_page') || !function_exists('add_submenu_page')) {
            return;
        }

        $defaultProduct = null;
        if (function_exists('current_user_can')) {
            foreach (self::PRODUCTS as $key => $definition) {
                if (current_user_can($definition['capability'])) {
                    $defaultProduct = $key;
                    if ($key === 'operations') {
                        break;
                    }
                }
            }
        }
        if ($defaultProduct === null) {
            return;
        }
        $this->parentProduct = $defaultProduct;
        $parentCapability = self::PRODUCTS[$defaultProduct]['capability'];

        add_menu_page(
            'Veyra',
            'Veyra',
            $parentCapability,
            'veyra-operations',
            function () use ($defaultProduct): void {
                $this->renderProduct($defaultProduct);
            },
            'dashicons-format-chat',
            56
        );

        foreach (self::PRODUCTS as $key => $definition) {
            if ($key === 'operations') {
                // The top-level page is the Operations product for dashboard
                // viewers, or the first independently authorized studio for a
                // narrower staff account. Re-registering the same slug would
                // replace that capability-scoped callback.
                continue;
            }
            $slug = $key === 'operations' ? 'veyra-operations' : 'veyra-' . $key;
            add_submenu_page(
                'veyra-operations',
                $definition['title'],
                $definition['menu'],
                $definition['capability'],
                $slug,
                function () use ($key): void {
                    $this->renderProduct($key);
                }
            );
        }
    }

    public function enqueueAssets(string $hookSuffix = ''): void
    {
        unset($hookSuffix);
        $product = $this->requestedProduct();
        if ($product === null || !function_exists('wp_enqueue_style')) {
            return;
        }

        $definition = self::PRODUCTS[$product];
        if (!function_exists('current_user_can') || !current_user_can($definition['capability'])) {
            return;
        }

        wp_enqueue_style(
            'veyra-admin-products',
            plugins_url('assets/admin/veyra-admin.css', $this->pluginFile),
            [],
            $this->assetVersion
        );
        wp_enqueue_script(
            'veyra-admin-products',
            plugins_url('assets/admin/veyra-admin.js', $this->pluginFile),
            [],
            $this->assetVersion,
            true
        );

        if (function_exists('wp_add_inline_script')) {
            $configuration = $this->configuration();
            $json = function_exists('wp_json_encode')
                ? wp_json_encode($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                : json_encode($configuration, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            if (is_string($json)) {
                wp_add_inline_script(
                    'veyra-admin-products',
                    'window.VeyraAdminBootstrap = Object.freeze(' . $json . ');',
                    'before'
                );
            }
        }
    }

    private function renderProduct(string $product): void
    {
        if (!isset(self::PRODUCTS[$product])) {
            return;
        }

        $veyraProduct = $product;
        $veyraDefinition = self::PRODUCTS[$product];
        if (!function_exists('current_user_can') || !current_user_can($veyraDefinition['capability'])) {
            if (function_exists('wp_die')) {
                wp_die('You do not have permission to access this Veyra product.', 'Veyra', ['response' => 403]);
            }
            return;
        }

        if (is_readable($this->templatePath)) {
            require $this->templatePath;
        }
    }

    private function requestedProduct(): ?string
    {
        $page = isset($_GET['page']) && is_string($_GET['page']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key(wp_unslash($_GET['page'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';
        if ($page === 'veyra-operations') {
            return $this->parentProduct ?? 'operations';
        }
        if (str_starts_with($page, 'veyra-')) {
            $product = substr($page, 6);
            return isset(self::PRODUCTS[$product]) ? $product : null;
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function configuration(): array
    {
        $provided = ($this->configurationProvider)();
        if (!is_array($provided)) {
            $provided = [];
        }
        $restBase = function_exists('rest_url') ? rest_url('veyra/v1') : '/wp-json/veyra/v1';
        $defaults = [
            'schema_version' => 'veyra.admin_bootstrap.v1',
            'rest_base' => $restBase,
            'nonce' => function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '',
            'locale' => function_exists('determine_locale') ? determine_locale() : 'en_US',
            'direction' => function_exists('is_rtl') && is_rtl() ? 'rtl' : 'ltr',
            'request_timeout_ms' => 90000,
            'routes' => [
                'state' => '/admin/products/{product}',
                'draft' => '/admin/products/{product}/draft',
                'validate' => '/admin/products/{product}/validate',
                'simulate' => '/admin/products/{product}/simulate',
                'publish' => '/admin/products/{product}/publish',
                'schedule' => '/admin/products/{product}/schedule',
                'rollback' => '/admin/products/{product}/rollback',
                'import' => '/admin/products/{product}/import',
                'provider_credential' => '/admin/provider/credential',
                'provider_readiness' => '/admin/provider/readiness',
            ],
            'strings' => [
                'loading' => __('Loading authoritative state…', 'veyra-ai-commerce-agent'),
                'unavailable' => __('The authoritative service is unavailable. No change was made.', 'veyra-ai-commerce-agent'),
                'saved' => __('Draft saved by the server.', 'veyra-ai-commerce-agent'),
                'validated' => __('Validation completed.', 'veyra-ai-commerce-agent'),
                'published' => __('Published version confirmed by the server.', 'veyra-ai-commerce-agent'),
                'rolled_back' => __('Rollback confirmed by the server.', 'veyra-ai-commerce-agent'),
                'conflict' => __('The configuration changed elsewhere. Reload before continuing.', 'veyra-ai-commerce-agent'),
                'not_authorized' => __('You are not authorized for this action.', 'veyra-ai-commerce-agent'),
                'dirty' => __('Unsaved draft changes', 'veyra-ai-commerce-agent'),
                'clean' => __('Draft matches the loaded server version', 'veyra-ai-commerce-agent'),
            ],
        ];

        $configuration = array_replace($defaults, $provided);
        $configuration['routes'] = array_replace(
            $defaults['routes'],
            is_array($provided['routes'] ?? null) ? $provided['routes'] : []
        );
        $configuration['strings'] = array_replace(
            $defaults['strings'],
            is_array($provided['strings'] ?? null) ? $provided['strings'] : []
        );
        foreach ($defaults['strings'] as $key => $fallback) {
            if (!is_string($configuration['strings'][$key] ?? null) || trim($configuration['strings'][$key]) === '') {
                $configuration['strings'][$key] = $fallback;
            } else {
                $configuration['strings'][$key] = function_exists('mb_substr')
                    ? mb_substr(trim($configuration['strings'][$key]), 0, 500, 'UTF-8')
                    : substr(trim($configuration['strings'][$key]), 0, 500);
            }
        }
        $configuration['request_timeout_ms'] = max(90000, min(120000, (int) $configuration['request_timeout_ms']));
        $configuration['rest_base'] = is_string($configuration['rest_base']) ? $configuration['rest_base'] : $restBase;
        $configuration['nonce'] = is_string($configuration['nonce'])
            && strlen($configuration['nonce']) <= 128
            && preg_match('/^[A-Za-z0-9_-]+$/D', $configuration['nonce']) === 1
                ? $configuration['nonce']
                : '';

        return $configuration;
    }
}
