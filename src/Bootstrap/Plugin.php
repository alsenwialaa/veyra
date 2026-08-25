<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

final class Plugin
{
    private static ?self $instance = null;

    private ?Container $container = null;

    private ?CompatibilityReport $compatibility = null;

    private function __construct(private readonly string $pluginFile)
    {
    }

    public static function register(string $pluginFile): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = new self($pluginFile);
        self::$instance->registerHooks();

        return self::$instance;
    }

    private function registerHooks(): void
    {
        add_action('plugins_loaded', array($this, 'boot'), 20);
        add_action('init', array($this, 'loadTranslations'), 0);
        add_action('admin_notices', array($this, 'renderCompatibilityNotice'));
        add_action('veyra_run_migrations', array(Activator::class, 'resumeMigrations'));
    }

    public function loadTranslations(): void
    {
        if (!function_exists('load_plugin_textdomain') || !function_exists('plugin_basename')) {
            return;
        }

        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- The installable ZIP bundles Arabic translations in its own languages directory and must work outside WordPress.org language-pack delivery.
        load_plugin_textdomain(
            'veyra-ai-commerce-agent',
            false,
            dirname(plugin_basename($this->pluginFile)) . '/languages'
        );
    }

    public function boot(): void
    {
        $compatibility = new RuntimeCompatibility(
            defined('VEYRA_MIN_PHP_VERSION') ? VEYRA_MIN_PHP_VERSION : '8.1.0',
            defined('VEYRA_MIN_WP_VERSION') ? VEYRA_MIN_WP_VERSION : '6.5.0',
            defined('VEYRA_MIN_WC_VERSION') ? VEYRA_MIN_WC_VERSION : '8.5.0'
        );

        $this->compatibility = $compatibility->evaluate(EnvironmentSnapshot::fromWordPress());

        if (!$this->compatibility->foundationReady()) {
            return;
        }

        $requiredSchema = defined('VEYRA_SCHEMA_VERSION') ? (string) VEYRA_SCHEMA_VERSION : '0.0.0';
        $currentSchema = function_exists('get_option')
            ? (string) get_option(\Veyra\Infrastructure\Database\Migration\Migrator::SCHEMA_OPTION, '0.0.0')
            : '0.0.0';
        // Older schemas schedule bounded asynchronous work; unknown newer or
        // malformed schemas are health-blocked and never queried by this code.
        if ($currentSchema !== $requiredSchema) {
            Activator::scheduleMigrationResume($requiredSchema);
            return;
        }

        try {
            $this->container = FoundationFactory::createContainer($this->compatibility);

            /**
             * Allows complete, independently gated modules to register services.
             * Incomplete modules must not hook into this action.
             */
            do_action('veyra_register_services', $this->container, $this->compatibility);

            if ($this->compatibility->commerceReady()) {
                do_action('veyra_booted', $this->container);
            }
        } catch (\Throwable) {
            // Composition errors must never take down ordinary storefront or
            // admin requests. Individual modules expose no public surface until
            // their own composition succeeds; retain only a stable health code.
            $this->container = null;
            self::recordRuntimeBootFailure();
            return;
        }

        self::clearRuntimeBootFailure($this->compatibility);
    }

    public function renderCompatibilityNotice(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        if ($this->compatibility === null) {
            $this->compatibility = (new RuntimeCompatibility())->evaluate(EnvironmentSnapshot::fromWordPress());
        }

        if ($this->compatibility->issues === []) {
            return;
        }

        foreach ($this->compatibility->issues as $issue) {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s <code>%s</code></p></div>',
                esc_html__('Veyra is not fully available.', 'veyra-ai-commerce-agent'),
                esc_html($issue->message),
                esc_html($issue->code)
            );
        }
    }

    public function container(): ?Container
    {
        return $this->container;
    }

    private static function recordRuntimeBootFailure(): void
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        try {
            $health = get_option(Activator::HEALTH_OPTION, []);
            $health = is_array($health) ? $health : [];
            $codes = is_array($health['codes'] ?? null)
                ? array_values(array_filter($health['codes'], 'is_string'))
                : [];
            $codes[] = 'runtime_boot_failed';
            $health['state'] = 'blocked';
            $health['codes'] = array_values(array_unique($codes));
            $health['runtime_boot_state'] = 'blocked';
            $health['checked_at'] = gmdate('Y-m-d H:i:s');
            update_option(Activator::HEALTH_OPTION, $health, false);
        } catch (\Throwable) {
            // Health persistence is best-effort at this final containment edge.
        }
    }

    private static function clearRuntimeBootFailure(CompatibilityReport $compatibility): void
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        try {
            $health = get_option(Activator::HEALTH_OPTION, []);
            if (!is_array($health) || !is_array($health['codes'] ?? null)) {
                return;
            }
            $codes = array_values(array_filter($health['codes'], 'is_string'));
            if (!in_array('runtime_boot_failed', $codes, true)) {
                return;
            }
            $health['codes'] = array_values(array_filter(
                $codes,
                static fn (string $code): bool => $code !== 'runtime_boot_failed'
            ));
            $health['runtime_boot_state'] = 'ready';
            $health['state'] = $compatibility->commerceReady() && $health['codes'] === []
                ? 'ready'
                : 'blocked';
            $health['checked_at'] = gmdate('Y-m-d H:i:s');
            update_option(Activator::HEALTH_OPTION, $health, false);
        } catch (\Throwable) {
            // A successful runtime remains usable even if stale health cannot
            // be cleared; the next activation rewrites the canonical record.
        }
    }
}
