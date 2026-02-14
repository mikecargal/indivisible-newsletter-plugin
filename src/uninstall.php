<?php
/**
 * Uninstall handler for Indivisible Newsletter Poster.
 *
 * Cleans up all plugin data when uninstalled.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options.
delete_option('indivisible_newsletter_settings');
delete_option('indivisible_newsletter_processed_ids');

// Clear any scheduled cron events.
$timestamp = wp_next_scheduled('indivisible_newsletter_check_email');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'indivisible_newsletter_check_email');
}
