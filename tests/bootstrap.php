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
 * Load the shared design-system mu-plugin (provides IDS_VERSION, ids_render_alert,
 * and the ids-confirm-modal script registration that CON10 depends on), then load
 * the newsletter plugin. Mirrors the event-calendar (CON7) test bootstrap.
 */
function _manually_load_plugin() {
  $shared_plugin = '/var/www/plugins/indivisible-shared/src/indivisible-shared.php';
  if ( ! defined( 'IDS_VERSION' ) && file_exists( $shared_plugin ) ) {
    require_once $shared_plugin;
  }
  require dirname( __DIR__ ) . '/src/indivisible-newsletter.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Define SECURE_AUTH_SALT if not already set (needed for encryption tests).
if ( ! defined( 'SECURE_AUTH_SALT' ) ) {
  define( 'SECURE_AUTH_SALT', 'test-salt-for-phpunit-only' );
}

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

require_once '/var/www/plugins/indivisible-shared/tests/lib/trait-assert-html.php';
require_once __DIR__ . '/class-in-test-case.php';
