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

        if (self::isBelowMinimum($environment->phpVersion, $this->minimumPhp)) {
            $issues[] = new CompatibilityIssue(
                'veyra_php_too_old',
                'php',
                sprintf('Veyra requires PHP %s or newer.', $this->minimumPhp),
                true
            );
        }

        if (self::isBelowMinimum($environment->wordpressVersion, $this->minimumWordPress)) {
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
        } elseif (self::isBelowMinimum($environment->woocommerceVersion, $this->minimumWooCommerce)) {
            $issues[] = new CompatibilityIssue(
                'veyra_woocommerce_too_old',
                'woocommerce',
                sprintf('Veyra requires WooCommerce %s or newer for commerce capabilities.', $this->minimumWooCommerce),
                false
            );
        }

        return new CompatibilityReport($issues);
    }

    private static function isBelowMinimum(string $current, string $minimum): bool
    {
        return version_compare(
            self::normalizeReleaseVersion($current),
            self::normalizeReleaseVersion($minimum),
            '<'
        );
    }

    /**
     * Pads an abbreviated numeric release core without changing its suffix.
     *
     * WordPress publishes stable releases such as "6.5", while plugin headers
     * conventionally declare the same minimum as "6.5.0". Preserving suffixes
     * keeps versions such as "6.5-RC1" below the corresponding stable release.
     */
    private static function normalizeReleaseVersion(string $version): string
    {
        if (preg_match('/^(\d+(?:\.\d+){0,2})(.*)$/D', $version, $matches) !== 1) {
            return $version;
        }

        $segments = explode('.', $matches[1]);
        while (count($segments) < 3) {
            $segments[] = '0';
        }

        return implode('.', $segments) . $matches[2];
    }
}
