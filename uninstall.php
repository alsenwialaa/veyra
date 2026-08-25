<?php
/**
 * Veyra uninstall entry point.
 *
 * @package Veyra
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.1.0', '<' ) ) {
	return;
}

$veyra_plugin_dir = plugin_dir_path( __FILE__ );
$veyra_autoloader = $veyra_plugin_dir . 'vendor/autoload.php';

if ( is_readable( $veyra_autoloader ) ) {
	require_once $veyra_autoloader;
} else {
	require_once $veyra_plugin_dir . 'src/Bootstrap/Autoloader.php';
	\Veyra\Bootstrap\Autoloader::register( $veyra_plugin_dir . 'src' );
}

\Veyra\Bootstrap\Uninstaller::uninstall();

