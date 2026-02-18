<?php
/**
 * PHPUnit bootstrap for Indivisible Newsletter plugin.
 */

$_plugin_dir = dirname( __DIR__ ) . '/src/';
$_tests_dir  = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find wp-phpunit. Run 'composer install' inside the Docker container." . PHP_EOL;
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugin during test bootstrap.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/src/indivisible-newsletter.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Define SECURE_AUTH_SALT if not already set (needed for encryption tests).
if ( ! defined( 'SECURE_AUTH_SALT' ) ) {
	define( 'SECURE_AUTH_SALT', 'test-salt-for-phpunit-only' );
}

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
