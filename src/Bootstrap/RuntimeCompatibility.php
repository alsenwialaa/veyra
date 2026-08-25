<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

final class RuntimeCompatibility
{
    public function __construct(
        private readonly string $minimumPhp = '8.1.0',
        private readonly string $minimumWordPress = '6.5.0',
        private readonly string $minimumWooCommerce = '8.5.0'
    ) {
    }

    public function evaluate(EnvironmentSnapshot $environment): CompatibilityReport
    {
        $issues = [];

        if (version_compare($environment->phpVersion, $this->minimumPhp, '<')) {
            $issues[] = new CompatibilityIssue(
                'veyra_php_too_old',
                'php',
                sprintf('Veyra requires PHP %s or newer.', $this->minimumPhp),
                true
            );
        }

        if (version_compare($environment->wordpressVersion, $this->minimumWordPress, '<')) {
            $issues[] = new CompatibilityIssue(
                'veyra_wordpress_too_old',
                'wordpress',
                sprintf('Veyra requires WordPress %s or newer.', $this->minimumWordPress),
                true
            );
        }

        if (!$environment->databaseAvailable) {
            $issues[] = new CompatibilityIssue(
                'veyra_database_unavailable',
                'database',
                'Veyra could not access the WordPress database adapter.',
                true
            );
        }

        if ($environment->woocommerceVersion === null) {
            $issues[] = new CompatibilityIssue(
                'veyra_woocommerce_missing',
                'woocommerce',
                'WooCommerce is unavailable. Veyra commerce capabilities are blocked.',
                false
            );
        } elseif (version_compare($environment->woocommerceVersion, $this->minimumWooCommerce, '<')) {
            $issues[] = new CompatibilityIssue(
                'veyra_woocommerce_too_old',
                'woocommerce',
                sprintf('Veyra requires WooCommerce %s or newer for commerce capabilities.', $this->minimumWooCommerce),
                false
            );
        }

        return new CompatibilityReport($issues);
    }
}

