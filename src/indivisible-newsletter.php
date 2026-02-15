<?php
/**
 * Plugin Name: Indivisible Newsletter Poster
 * Description: Automatically monitors an email inbox and creates WordPress posts from newsletter emails.
 * Version: 1.0.0
 * Author: Mike Cargal
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IN_VERSION', '1.0.0');
define('IN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IN_OPTION_KEY', 'indivisible_newsletter_settings');
define('IN_PROCESSED_KEY', 'indivisible_newsletter_processed_ids');
define('IN_CRON_HOOK', 'indivisible_newsletter_check_email');

// Include files.
require_once IN_PLUGIN_DIR . 'includes/class-in-admin.php';
require_once IN_PLUGIN_DIR . 'includes/class-in-email.php';
require_once IN_PLUGIN_DIR . 'includes/class-in-processor.php';
require_once IN_PLUGIN_DIR . 'includes/class-in-cron.php';

// Activation hook.
register_activation_hook(__FILE__, 'indivisible_newsletter_activate');
function indivisible_newsletter_activate() {
    $defaults = indivisible_newsletter_get_defaults();
    if (!get_option(IN_OPTION_KEY)) {
        add_option(IN_OPTION_KEY, $defaults);
    }
    indivisible_newsletter_schedule_cron();
}

// Deactivation hook.
register_deactivation_hook(__FILE__, 'indivisible_newsletter_deactivate');
function indivisible_newsletter_deactivate() {
    indivisible_newsletter_clear_cron();
}

/**
 * Get default settings.
 */
function indivisible_newsletter_get_defaults() {
    return array(
        'imap_host'       => '',
        'imap_port'       => '993',
        'imap_encryption' => 'ssl',
        'email_username'  => '',
        'email_password'  => '',
        'imap_folder'     => 'INBOX',
        'filter_by_sender' => false,
        'qualified_senders' => '',
        'check_interval'  => 'hourly',
        'post_status'     => 'draft',
        'webmaster_email' => get_option('admin_email'),
        'post_category'   => 0,
    );
}

/**
 * Get plugin settings merged with defaults.
 */
function indivisible_newsletter_get_settings() {
    $defaults = indivisible_newsletter_get_defaults();
    $settings = get_option(IN_OPTION_KEY, array());
    return wp_parse_args($settings, $defaults);
}
