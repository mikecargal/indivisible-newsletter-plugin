<?php
/**
 * WordPress test configuration for Indivisible Newsletter plugin.
 *
 * WARNING: This points to a dedicated test database. The WordPress test
 * framework drops and recreates all tables on each run.
 */

define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', 'db' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'localhost' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

// Path to the WordPress installation inside the Docker container.
define( 'ABSPATH', '/var/www/html/' );
