<?php
/**
 * Plugin Name: Veyra AI Commerce Agent for WooCommerce
 * Description: AI-led conversational commerce infrastructure for WooCommerce.
 * Version: 0.1.7
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * WC requires at least: 8.5
 * Author: Veyra
 * Text Domain: veyra-ai-commerce-agent
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 *
 * @package Veyra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VEYRA_VERSION', '0.1.7' );
define( 'VEYRA_SCHEMA_VERSION', '1.6.0' );
define( 'VEYRA_MIN_PHP_VERSION', '8.1.0' );
define( 'VEYRA_MIN_WP_VERSION', '6.5.0' );
define( 'VEYRA_MIN_WC_VERSION', '8.5.0' );
define( 'VEYRA_PLUGIN_FILE', __FILE__ );
define( 'VEYRA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VEYRA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// WordPress prevents activation below the declared PHP version. This guard also
// protects direct/manual loading from parsing the PHP 8.1 source tree.
if ( version_compare( PHP_VERSION, VEYRA_MIN_PHP_VERSION, '<' ) ) {
	return;
}

$veyra_composer_autoloader = VEYRA_PLUGIN_DIR . 'vendor/autoload.php';

if ( is_readable( $veyra_composer_autoloader ) ) {
	require_once $veyra_composer_autoloader;
} else {
	require_once VEYRA_PLUGIN_DIR . 'src/Bootstrap/Autoloader.php';
	\Veyra\Bootstrap\Autoloader::register( VEYRA_PLUGIN_DIR . 'src' );
}

register_activation_hook( __FILE__, array( \Veyra\Bootstrap\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Veyra\Bootstrap\Deactivator::class, 'deactivate' ) );

\Veyra\Runtime\RuntimeModule::register( __FILE__ );
\Veyra\Bootstrap\SecurityLifecycleModule::register( __FILE__ );
\Veyra\Bootstrap\Plugin::register( __FILE__ );
