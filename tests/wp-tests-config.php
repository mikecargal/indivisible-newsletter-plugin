<?php
/**
 * WordPress test configuration for Indivisible Newsletter plugin.
 *
 * WARNING: This points to a dedicated test database. The WordPress test
 * framework drops and recreates all tables on each run.
 */

// Paratest sets TEST_TOKEN (1, 2, 3, ...) per process for database isolation.
$db_suffix = getenv( 'TEST_TOKEN' ) ? '_' . getenv( 'TEST_TOKEN' ) : '';
define( 'DB_NAME', 'wordpress_test' . $db_suffix );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', 'db' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_in_';

define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

// Path to the WordPress installation inside the Docker container.
define( 'ABSPATH', '/var/www/html/' );
