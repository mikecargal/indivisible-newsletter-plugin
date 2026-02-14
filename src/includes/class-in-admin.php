<?php
/**
 * Admin settings page for Indivisible Newsletter Poster.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register the settings page under the Settings menu.
 */
add_action('admin_menu', 'indivisible_newsletter_add_settings_page');
function indivisible_newsletter_add_settings_page() {
    add_options_page(
        'Newsletter Poster Settings',
        'Newsletter Poster',
        'manage_options',
        'indivisible-newsletter',
        'indivisible_newsletter_render_settings_page'
    );
}

/**
 * Register settings and fields.
 */
add_action('admin_init', 'indivisible_newsletter_register_settings');
function indivisible_newsletter_register_settings() {
    register_setting(
        'indivisible_newsletter_group',
        IN_OPTION_KEY,
        array('sanitize_callback' => 'indivisible_newsletter_sanitize_settings')
    );

    // IMAP Connection Section.
    add_settings_section(
        'in_imap_section',
        'IMAP Connection Settings',
        function () {
            echo '<p>Configure the email account to monitor for newsletter messages.</p>';
        },
        'indivisible-newsletter'
    );

    add_settings_field('imap_host', 'IMAP Host', 'indivisible_newsletter_field_text', 'indivisible-newsletter', 'in_imap_section', array('field' => 'imap_host', 'placeholder' => 'imap.gmail.com'));
    add_settings_field('imap_port', 'IMAP Port', 'indivisible_newsletter_field_text', 'indivisible-newsletter', 'in_imap_section', array('field' => 'imap_port', 'type' => 'number'));
    add_settings_field('imap_encryption', 'Encryption', 'indivisible_newsletter_field_select', 'indivisible-newsletter', 'in_imap_section', array('field' => 'imap_encryption', 'options' => array('ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None')));
    add_settings_field('email_username', 'Email Username', 'indivisible_newsletter_field_text', 'indivisible-newsletter', 'in_imap_section', array('field' => 'email_username', 'placeholder' => 'user@example.com'));
    add_settings_field('email_password', 'Email Password', 'indivisible_newsletter_field_password', 'indivisible-newsletter', 'in_imap_section', array('field' => 'email_password'));
    add_settings_field('imap_folder', 'Mailbox Folder', 'indivisible_newsletter_field_text', 'indivisible-newsletter', 'in_imap_section', array('field' => 'imap_folder', 'placeholder' => 'INBOX'));

    // Processing Section.
    add_settings_section(
        'in_processing_section',
        'Email Processing Settings',
        function () {
            echo '<p>Configure how newsletter emails are identified and processed.</p>';
        },
        'indivisible-newsletter'
    );

    add_settings_field('qualified_sender', 'Qualified Sender Email', 'indivisible_newsletter_field_text', 'indivisible-newsletter', 'in_processing_section', array('field' => 'qualified_sender', 'placeholder' => 'mike@cargal.net', 'description' => 'Only emails forwarded from this address will be processed.'));
    add_settings_field('check_interval', 'Check Interval', 'indivisible_newsletter_field_select', 'indivisible-newsletter', 'in_processing_section', array('field' => 'check_interval', 'options' => array('five_minutes' => 'Every 5 Minutes', 'fifteen_minutes' => 'Every 15 Minutes', 'thirty_minutes' => 'Every 30 Minutes', 'hourly' => 'Hourly', 'daily' => 'Daily')));

    // Post Creation Section.
    add_settings_section(
        'in_post_section',
        'Post Creation Settings',
        function () {
            echo '<p>Configure how newsletter posts are created.</p>';
        },
        'indivisible-newsletter'
    );

    add_settings_field('post_status', 'Post Status', 'indivisible_newsletter_field_select', 'indivisible-newsletter', 'in_post_section', array('field' => 'post_status', 'options' => array('draft' => 'Draft', 'publish' => 'Published')));
    add_settings_field('post_category', 'Post Category', 'indivisible_newsletter_field_category', 'indivisible-newsletter', 'in_post_section', array('field' => 'post_category'));
    add_settings_field('webmaster_email', 'Webmaster Email', 'indivisible_newsletter_field_text', 'indivisible-newsletter', 'in_post_section', array('field' => 'webmaster_email', 'placeholder' => get_option('admin_email'), 'description' => 'Notification email sent here when a newsletter post is created.'));
}

/**
 * Sanitize settings before saving.
 */
function indivisible_newsletter_sanitize_settings($input) {
    $current  = indivisible_newsletter_get_settings();
    $defaults = indivisible_newsletter_get_defaults();

    $sanitized = array();
    $sanitized['imap_host']       = sanitize_text_field($input['imap_host'] ?? $defaults['imap_host']);
    $sanitized['imap_port']       = absint($input['imap_port'] ?? $defaults['imap_port']);
    $sanitized['imap_encryption'] = in_array($input['imap_encryption'] ?? '', array('ssl', 'tls', 'none'), true) ? $input['imap_encryption'] : $defaults['imap_encryption'];
    $sanitized['email_username']  = sanitize_text_field($input['email_username'] ?? $defaults['email_username']);
    $sanitized['imap_folder']     = sanitize_text_field($input['imap_folder'] ?? $defaults['imap_folder']);
    $sanitized['qualified_sender'] = sanitize_email($input['qualified_sender'] ?? $defaults['qualified_sender']);
    $sanitized['check_interval']  = sanitize_text_field($input['check_interval'] ?? $defaults['check_interval']);
    $sanitized['post_status']     = in_array($input['post_status'] ?? '', array('draft', 'publish'), true) ? $input['post_status'] : $defaults['post_status'];
    $sanitized['post_category']   = absint($input['post_category'] ?? $defaults['post_category']);
    $sanitized['webmaster_email'] = sanitize_email($input['webmaster_email'] ?? $defaults['webmaster_email']);

    // Handle password: encrypt if changed, keep existing if blank.
    if (!empty($input['email_password'])) {
        $sanitized['email_password'] = indivisible_newsletter_encrypt($input['email_password']);
    } else {
        $sanitized['email_password'] = $current['email_password'];
    }

    // Reschedule cron if interval changed.
    if ($sanitized['check_interval'] !== $current['check_interval']) {
        indivisible_newsletter_clear_cron();
        indivisible_newsletter_schedule_cron($sanitized['check_interval']);
    }

    return $sanitized;
}

/**
 * Encrypt a string using openssl.
 */
function indivisible_newsletter_encrypt($plaintext) {
    $key    = hash('sha256', SECURE_AUTH_SALT, true);
    $iv     = openssl_random_pseudo_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($iv . '::' . $cipher);
}

/**
 * Decrypt a string using openssl.
 */
function indivisible_newsletter_decrypt($encrypted) {
    if (empty($encrypted)) {
        return '';
    }
    $key  = hash('sha256', SECURE_AUTH_SALT, true);
    $data = base64_decode($encrypted);
    $parts = explode('::', $data, 2);
    if (count($parts) !== 2) {
        return '';
    }
    list($iv, $cipher) = $parts;
    return openssl_decrypt($cipher, 'aes-256-cbc', $key, 0, $iv);
}

// --- Field rendering callbacks ---

function indivisible_newsletter_field_text($args) {
    $settings = indivisible_newsletter_get_settings();
    $field    = $args['field'];
    $type     = $args['type'] ?? 'text';
    $value    = esc_attr($settings[$field] ?? '');
    $placeholder = esc_attr($args['placeholder'] ?? '');
    echo "<input type='{$type}' name='" . IN_OPTION_KEY . "[{$field}]' value='{$value}' placeholder='{$placeholder}' class='regular-text' />";
    if (!empty($args['description'])) {
        echo '<p class="description">' . esc_html($args['description']) . '</p>';
    }
}

function indivisible_newsletter_field_password($args) {
    $settings = indivisible_newsletter_get_settings();
    $field    = $args['field'];
    $has_password = !empty($settings[$field]);
    $placeholder  = $has_password ? 'Password is set (leave blank to keep)' : 'Enter password';
    echo "<input type='password' name='" . IN_OPTION_KEY . "[{$field}]' value='' placeholder='{$placeholder}' class='regular-text' />";
}

function indivisible_newsletter_field_select($args) {
    $settings = indivisible_newsletter_get_settings();
    $field    = $args['field'];
    $current  = $settings[$field] ?? '';
    echo "<select name='" . IN_OPTION_KEY . "[{$field}]'>";
    foreach ($args['options'] as $value => $label) {
        $selected = selected($current, $value, false);
        echo "<option value='{$value}' {$selected}>" . esc_html($label) . "</option>";
    }
    echo "</select>";
}

function indivisible_newsletter_field_category($args) {
    $settings = indivisible_newsletter_get_settings();
    $field    = $args['field'];
    $current  = absint($settings[$field] ?? 0);
    wp_dropdown_categories(array(
        'name'             => IN_OPTION_KEY . "[{$field}]",
        'selected'         => $current,
        'show_option_none' => '-- Select Category --',
        'option_none_value' => '0',
        'hide_empty'       => false,
    ));
}

/**
 * Render the settings page.
 */
function indivisible_newsletter_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Handle "Check Now" action.
    if (isset($_POST['in_check_now']) && check_admin_referer('in_check_now_action')) {
        $result = indivisible_newsletter_process_emails();
        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>' . esc_html($result) . '</p></div>';
        }
    }

    // Handle "Test Connection" action.
    if (isset($_POST['in_test_connection']) && check_admin_referer('in_test_connection_action')) {
        $result = indivisible_newsletter_test_connection();
        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>Connection failed: ' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>' . esc_html($result) . '</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1>Newsletter Poster Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('indivisible_newsletter_group');
            do_settings_sections('indivisible-newsletter');
            submit_button();
            ?>
        </form>

        <hr />
        <h2>Actions</h2>
        <div style="display: flex; gap: 10px;">
            <form method="post">
                <?php wp_nonce_field('in_test_connection_action'); ?>
                <button type="submit" name="in_test_connection" class="button button-secondary">Test Connection</button>
            </form>
            <form method="post">
                <?php wp_nonce_field('in_check_now_action'); ?>
                <button type="submit" name="in_check_now" class="button button-primary">Check Now</button>
            </form>
        </div>

        <?php
        // Show next scheduled check.
        $next = wp_next_scheduled(IN_CRON_HOOK);
        if ($next) {
            $diff = human_time_diff(time(), $next);
            echo '<p class="description">Next scheduled check: in ' . esc_html($diff) . ' (' . esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'Y-m-d H:i:s')) . ')</p>';
        } else {
            echo '<p class="description" style="color: #d63638;">No check is currently scheduled. Save settings to schedule.</p>';
        }
        ?>
    </div>
    <?php
}
