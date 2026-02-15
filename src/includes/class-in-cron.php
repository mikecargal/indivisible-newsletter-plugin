<?php
/**
 * WP-Cron scheduling for Indivisible Newsletter Poster.
 *
 * Relies on WP-Cron being triggered reliably. For low-traffic sites,
 * configure a system cron job to hit wp-cron.php on schedule.
 * DreamHost: Create a cron job in the panel with:
 *   wget -q -O - https://your-site.com/wp-cron.php?doing_wp_cron
 * And add to wp-config.php: define('DISABLE_WP_CRON', true);
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register custom cron intervals.
 */
add_filter('cron_schedules', 'indivisible_newsletter_cron_schedules');
function indivisible_newsletter_cron_schedules($schedules) {
    $schedules['five_minutes'] = array(
        'interval' => 300,
        'display'  => 'Every 5 Minutes',
    );
    $schedules['fifteen_minutes'] = array(
        'interval' => 900,
        'display'  => 'Every 15 Minutes',
    );
    $schedules['thirty_minutes'] = array(
        'interval' => 1800,
        'display'  => 'Every 30 Minutes',
    );
    return $schedules;
}

/**
 * Schedule the cron event.
 *
 * @param string|null $interval Override interval, or use saved setting.
 */
function indivisible_newsletter_schedule_cron($interval = null) {
    if (is_null($interval)) {
        $settings = indivisible_newsletter_get_settings();
        $interval = $settings['check_interval'];
    }

    if (!wp_next_scheduled(IN_CRON_HOOK)) {
        wp_schedule_event(time(), $interval, IN_CRON_HOOK);
    }
}

/**
 * Clear the scheduled cron event.
 */
function indivisible_newsletter_clear_cron() {
    $timestamp = wp_next_scheduled(IN_CRON_HOOK);
    if ($timestamp) {
        wp_unschedule_event($timestamp, IN_CRON_HOOK);
    }
}

/**
 * Cron callback: process emails.
 */
add_action(IN_CRON_HOOK, 'indivisible_newsletter_cron_callback');
function indivisible_newsletter_cron_callback() {
    indivisible_newsletter_process_emails();
}
