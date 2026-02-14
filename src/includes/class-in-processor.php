<?php
/**
 * HTML processing and post creation for Indivisible Newsletter Poster.
 *
 * Cleans up newsletter HTML and creates WordPress posts.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Process all qualified emails: fetch, clean, create posts.
 *
 * @return string|WP_Error Result message or error.
 */
function indivisible_newsletter_process_emails() {
    $emails = indivisible_newsletter_fetch_emails();

    if (is_wp_error($emails)) {
        error_log('Newsletter Poster: ' . $emails->get_error_message());
        return $emails;
    }

    if (empty($emails)) {
        return 'No new newsletter emails found.';
    }

    $processed_ids = get_option(IN_PROCESSED_KEY, array());
    $count         = 0;

    foreach ($emails as $email) {
        $result = indivisible_newsletter_create_post_from_email($email);

        if (is_wp_error($result)) {
            error_log('Newsletter Poster: Failed to create post for "' . $email['subject'] . '": ' . $result->get_error_message());
            continue;
        }

        // Track processed message ID to avoid duplicates.
        if (!empty($email['message_id'])) {
            $processed_ids[] = $email['message_id'];
        }
        $count++;
    }

    // Save processed IDs (keep last 500 to prevent unbounded growth).
    $processed_ids = array_slice($processed_ids, -500);
    update_option(IN_PROCESSED_KEY, $processed_ids);

    $message = "Processed {$count} newsletter email(s).";
    error_log('Newsletter Poster: ' . $message);
    return $message;
}

/**
 * Create a WordPress post from a newsletter email.
 *
 * @param array $email Email data with 'subject', 'html', 'date', 'message_id'.
 * @return int|WP_Error Post ID or error.
 */
function indivisible_newsletter_create_post_from_email($email) {
    $settings = indivisible_newsletter_get_settings();

    // Clean the HTML.
    $html = indivisible_newsletter_clean_html($email['html']);

    // Build the post title from the email subject.
    $title = indivisible_newsletter_clean_subject($email['subject']);

    // Wrap HTML in a Gutenberg Custom HTML block.
    $content = "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";

    // Create the post.
    $post_data = array(
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => $settings['post_status'],
        'post_type'    => 'post',
        'post_author'  => 1, // Default to admin user.
    );

    // Set category if configured.
    if (!empty($settings['post_category'])) {
        $post_data['post_category'] = array(absint($settings['post_category']));
    }

    $post_id = wp_insert_post($post_data, true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    // Mark as login-required (integrates with Login Required Content plugin).
    update_post_meta($post_id, '_login_required', '1');

    // Send notification email.
    indivisible_newsletter_notify_webmaster($post_id, $title, $settings);

    return $post_id;
}

/**
 * Clean newsletter HTML by applying the transformations from the manual process.
 *
 * 1. Hide WP admin bar
 * 2. Remove Unsubscribe links
 * 3. Fix background colors to use theme variable
 *
 * @param string $html Raw newsletter HTML.
 * @return string Cleaned HTML.
 */
function indivisible_newsletter_clean_html($html) {
    // 1. Inject admin bar hide CSS.
    $admin_bar_css = '#wpadminbar {display: none !important;}';

    if (preg_match('/<style[^>]*>/i', $html)) {
        // Add to existing style tag.
        $html = preg_replace(
            '/(<style[^>]*>)/i',
            '$1' . "\n" . $admin_bar_css . "\n",
            $html,
            1
        );
    } else {
        // Create a style tag at the beginning.
        $html = '<style>' . $admin_bar_css . '</style>' . "\n" . $html;
    }

    // 2. Remove Unsubscribe links.
    // Match <a> tags containing "unsubscribe" (case-insensitive).
    $html = preg_replace(
        '/<a\b[^>]*>(?:[^<]*\b[Uu]nsubscribe\b[^<]*)<\/a>/i',
        '',
        $html
    );

    // 3. Fix background colors to use theme CSS variable.
    // Replace common background-color declarations with the theme variable.
    // Target inline styles and CSS declarations with background-color hex/rgb values.
    $html = preg_replace(
        '/background-color\s*:\s*(?:#[0-9a-fA-F]{3,8}|rgb[a]?\([^)]+\)|[a-zA-Z]+)\s*;?/i',
        'background-color: var(--wp--preset--color--background);',
        $html
    );

    return $html;
}

/**
 * Clean email subject line for use as post title.
 *
 * Strips common forwarding prefixes (Fwd:, Fw:, Re:).
 *
 * @param string $subject Email subject.
 * @return string Cleaned subject.
 */
function indivisible_newsletter_clean_subject($subject) {
    // Remove Fwd:/Fw:/Re: prefixes (possibly multiple).
    $subject = preg_replace('/^(\s*(Fwd?|Re)\s*:\s*)+/i', '', $subject);
    return trim($subject);
}

/**
 * Send notification email to webmaster about a new newsletter post.
 *
 * @param int    $post_id  The created post ID.
 * @param string $title    The post title.
 * @param array  $settings Plugin settings.
 */
function indivisible_newsletter_notify_webmaster($post_id, $title, $settings) {
    $to = $settings['webmaster_email'];
    if (empty($to)) {
        return;
    }

    $edit_link = get_edit_post_link($post_id, 'raw');
    $view_link = get_permalink($post_id);
    $status    = $settings['post_status'];

    $subject = '[Newsletter Poster] New newsletter post created: ' . $title;
    $message = "A new newsletter post has been created.\n\n";
    $message .= "Title: {$title}\n";
    $message .= "Status: {$status}\n";
    $message .= "Edit: {$edit_link}\n";
    $message .= "View: {$view_link}\n";

    wp_mail($to, $subject, $message);
}
