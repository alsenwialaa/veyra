<?php

declare(strict_types=1);

namespace Veyra\Bootstrap;

final class EnvironmentSnapshot
{
    public function __construct(
        public readonly string $phpVersion,
        public readonly string $wordpressVersion,
        public readonly ?string $woocommerceVersion,
        public readonly bool $databaseAvailable
    ) {
    }

    public static function fromWordPress(): self
    {
        global $wp_version, $wpdb;

        $wooVersion = defined('WC_VERSION') ? (string) constant('WC_VERSION') : null;

        return new self(
            PHP_VERSION,
            is_string($wp_version ?? null) ? $wp_version : '0.0.0',
            $wooVersion,
            is_object($wpdb) && method_exists($wpdb, 'prepare')
        );
    }
}

