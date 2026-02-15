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

    // Extract the original newsletter content (strips forwarding wrapper if present).
    $html = indivisible_newsletter_extract_forwarded_content($email['html']);

    // Clean the HTML.
    $html = indivisible_newsletter_clean_html($html);

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
 * Extract the original newsletter content from a forwarded email's HTML.
 *
 * Handles both forwarded messages (strips forwarding wrapper) and direct messages
 * (returns HTML as-is). Supports Apple Mail forwarding format.
 *
 * @param string $html The HTML from the email.
 * @return string The newsletter HTML content.
 */
function indivisible_newsletter_extract_forwarded_content($html) {
    // Check if this is a forwarded message by looking for Apple Mail's
    // "Begin forwarded message:" pattern inside a blockquote.
    if (preg_match('/<blockquote[^>]*type="cite"[^>]*>/i', $html)) {
        // Apple Mail format: content is inside <blockquote type="cite">.
        // The forwarding headers (From, Subject, Date, To) are in <div> elements
        // before the actual newsletter content.
        // Extract everything inside the blockquote.
        if (preg_match('/<blockquote[^>]*type="cite"[^>]*>(.*)<\/blockquote>/is', $html, $m)) {
            $content = $m[1];

            // Remove the "Begin forwarded message:" div.
            $content = preg_replace('/<div[^>]*>\s*Begin forwarded message:\s*<\/div>/i', '', $content);

            // Remove Apple interchange newlines.
            $content = preg_replace('/<br[^>]*class="Apple-interchange-newline"[^>]*>/i', '', $content);

            // Remove the forwarding header divs (From:, Subject:, Date:, To:, Reply-To:).
            // These are <div> elements containing <span><b>From: </b></span> etc.
            $content = preg_replace(
                '/<div[^>]*>\s*<span[^>]*>\s*<b>\s*(?:From|Subject|Date|To|Reply-To|Cc|Bcc)\s*:\s*<\/b>\s*<\/span>.*?<\/div>/is',
                '',
                $content
            );

            // Remove any leading <br> tags between headers and content.
            $content = preg_replace('/^\s*(<br[^>]*>\s*)+/i', '', $content);

            // The remaining content should be the newsletter.
            // It may be wrapped in a <div>, extract inner content if so.
            if (preg_match('/^\s*<div[^>]*>(.*)<\/div>\s*$/is', $content, $dm)) {
                $content = $dm[1];
            }

            return trim($content);
        }
    }

    // Not a forwarded message (or unrecognized format) - return the HTML body as-is.
    // Strip the outer <html><head>...<body>...</body></html> wrapper if present,
    // keeping just the body content.
    if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) {
        return trim($m[1]);
    }

    return $html;
}

/**
 * Clean newsletter HTML for posting.
 *
 * 1. Remove Unsubscribe links
 * 2. Set nl-container background to theme background color
 * 3. Set text color to black
 *
 * @param string $html Raw newsletter HTML.
 * @return string Cleaned HTML.
 */
function indivisible_newsletter_clean_html($html) {
    // 1. Remove Unsubscribe links.
    $html = preg_replace(
        '/<a\b[^>]*>(?:[^<]*\b[Uu]nsubscribe\b[^<]*)<\/a>/i',
        '',
        $html
    );

    // 2. Set nl-container background to theme background color.
    // The nl-container table has an inline background-color that clashes with the site theme.
    $html = preg_replace_callback(
        '/(<table\b[^>]*class="nl-container"[^>]*style="[^"]*?)background-color:\s*[^;"]+;?/i',
        function ($m) {
            return $m[1] . 'background-color: var(--wp--preset--color--background);';
        },
        $html
    );

    // 3. Set text color to black on the nl-container.
    $html = preg_replace_callback(
        '/(<table\b[^>]*class="nl-container"[^>]*style=")([^"]*")/i',
        function ($m) {
            return $m[1] . 'color: #000000; ' . $m[2];
        },
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
