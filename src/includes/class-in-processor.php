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
 * @return array{created:int,failures:string[],notify_failures:string[]}|WP_Error
 *               A structured batch report, or a WP_Error when the IMAP fetch
 *               itself fails. CON10 A2/A3 surface failures + notify_failures via
 *               the Check Now .ids-alert banner instead of logging them only.
 */
function indivisible_newsletter_process_emails() {
    $emails = indivisible_newsletter_fetch_emails();

    if (is_wp_error($emails)) {
        indivisible_newsletter_log('Newsletter Poster: ' . $emails->get_error_message());
        return $emails;
    }

    $processed_ids   = get_option(IN_PROCESSED_KEY, array());
    $count           = 0;
    $failures        = array();
    $notify_failures = array();

    foreach ($emails as $email) {
        $result = indivisible_newsletter_create_post_from_email($email, $notify_failures);

        if (is_wp_error($result)) {
            // CON10 A2: collect the per-email failure for the Check Now banner,
            // not just the error log.
            $failure    = sprintf('Failed to create post for "%s": %s', $email['subject'], $result->get_error_message());
            $failures[] = $failure;
            indivisible_newsletter_log('Newsletter Poster: ' . $failure);
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

    indivisible_newsletter_log('Newsletter Poster: ' . sprintf('Processed %d newsletter email(s).', $count));

    return array(
        'created'         => $count,
        'failures'        => $failures,
        'notify_failures' => $notify_failures,
    );
}

/**
 * Create a WordPress post from a newsletter email.
 *
 * @param array    $email           Email data with 'subject', 'html', 'date', 'message_id'.
 * @param string[] $notify_failures Accumulator (by reference): a webmaster
 *                                  notification send failure is appended so the
 *                                  caller can surface it instead of dropping it
 *                                  (CON10 A3).
 * @return int|WP_Error Post ID or error.
 */
function indivisible_newsletter_create_post_from_email($email, array &$notify_failures = array()) {
    $settings = indivisible_newsletter_get_settings();

    // Extract the original newsletter content (strips forwarding wrapper if present).
    $html = indivisible_newsletter_extract_forwarded_content($email['html']);

    // Capture the post-extract, pre-clean HTML for the reprocess feature.
    $raw_body = $html;

    // Build the post title from the email subject.
    $title = indivisible_newsletter_clean_subject($email['subject']);

    // Build the final post content via the shared pipeline (clean → kses →
    // .in-newsletter-content wrap → Gutenberg HTML block).
    $content = indivisible_newsletter_build_post_content( $html );

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

    // Store the original pre-clean HTML so the reprocess feature can re-run
    // the current cleaner against it without re-fetching from IMAP.
    update_post_meta( $post_id, '_in_newsletter_raw_body', $raw_body );

    // Store the source email's Message-ID so the reprocess feature UI can
    // display it and so it can be cross-referenced against the processed-IDs option.
    if ( ! empty( $email['message_id'] ) ) {
        update_post_meta( $post_id, '_in_newsletter_message_id', $email['message_id'] );
    }

    // Store the unmodified subject (pre-clean_subject) so the Recent Newsletters
    // table can distinguish "Fwd:" forwards from direct deliveries at a glance.
    if ( ! empty( $email['subject'] ) ) {
        update_post_meta( $post_id, '_in_newsletter_raw_subject', $email['subject'] );
    }

    // Send notification email; record a send failure so the caller (Check Now)
    // can surface it instead of leaving it silent — M-NONE (CON10 A3).
    if ( ! indivisible_newsletter_notify_webmaster($post_id, $title, $settings) ) {
        $notify_failures[] = sprintf('Webmaster notification failed for "%s".', $title);
    }

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
    // 1. Remove Unsubscribe sentence (sentence containing an unsubscribe link).
    // Match from the preceding <br> through the trailing period after </a>.
    $html = preg_replace(
        '/<br\s*\/?>\s*[^<]*<a\b[^>]*>[^<]*\b[Uu]nsubscribe\b[^<]*<\/a\s*>\s*\.?/is',
        '',
        $html
    );
    // Fallback: remove standalone unsubscribe links without a preceding <br>.
    $html = preg_replace(
        '/<a\b[^>]*>[^<]*\b[Uu]nsubscribe\b[^<]*<\/a\s*>/is',
        '',
        $html
    );

    // 2. Make row-content tables responsive.
    // Email HTML has fixed width:600px inline styles and width="600" attributes
    // that cause horizontal overflow on narrow screens. The original email includes
    // a <style> media query to fix this, but wp_kses_post() strips <style> tags.
    $html = preg_replace_callback(
        '/(<table\b[^>]*class="[^"]*row-content[^"]*"[^>]*style="[^"]*?)width:\s*600px/i',
        function ($m) {
            return $m[1] . 'width:100%;max-width:600px';
        },
        $html
    );
    $html = preg_replace(
        '/(<table\b[^>]*class="[^"]*row-content[^"]*"[^>]*)\s+width="600"/i',
        '$1',
        $html
    );

    // 3. Remove width="600" from images that already have width:100% in their
    // inline style. The HTML attribute overrides the CSS and prevents scaling.
    $html = preg_replace(
        '/(<img\b[^>]*style="[^"]*width:\s*100%[^"]*"[^>]*)\s+width="600"/i',
        '$1',
        $html
    );

    return $html;
}

/**
 * Build the final post_content value from pre-clean newsletter HTML.
 *
 * Applies the standard pipeline used for both initial post creation and
 * reprocess: run the cleaner, sanitize via wp_kses_post, wrap in the
 * .in-newsletter-content div, and wrap in a Gutenberg HTML block comment.
 * Also injects a unique ISO-8601 UTC "built:" timestamp comment inside the
 * block wrapper so consecutive calls produce byte-distinct output — this
 * prevents WordPress's revision deduper from hiding short-circuits in the
 * reprocess path and provides a provenance audit trail.
 *
 * The .in-newsletter-content wrapper restores word-break behavior that
 * wp_kses_post strips from inline styles (via a plugin-provided CSS class).
 *
 * @param string $raw_html Post-extract, pre-clean HTML (output of
 *                         indivisible_newsletter_extract_forwarded_content,
 *                         or the stored _in_newsletter_raw_body meta).
 * @return string Fully wrapped post_content ready for wp_insert_post /
 *                wp_update_post.
 */
function indivisible_newsletter_build_post_content( string $raw_html ): string {
    $cleaned = indivisible_newsletter_clean_html( $raw_html );
    $cleaned = wp_kses_post( $cleaned );
    $wrapped = '<div class="in-newsletter-content">' . $cleaned . '</div>';

    // Unique timestamp injected as an HTML comment between the Gutenberg block
    // marker and the content wrapper. Invisible when rendered; guarantees each
    // build produces byte-distinct output so WordPress creates a revision on
    // every reprocess call (rather than deduping via
    // wp_save_post_revision_check_for_changes). Also serves as a provenance
    // audit trail visible in the Gutenberg HTML block editor.
    //
    // The ISO-8601 UTC portion is for humans (shows when the post was last
    // built); the hrtime suffix is appended because PHP's DateTime('now') has
    // been observed to return the same microsecond value on ~50% of consecutive
    // calls in the Docker test environment. hrtime(true) is a monotonic
    // nanosecond wall clock that always advances between calls, guaranteeing
    // byte-uniqueness for the revision-creation invariant.
    $built_at = ( new DateTime( 'now', new DateTimeZone( 'UTC' ) ) )
        ->format( 'Y-m-d\TH:i:s.u\Z' );
    $built_at .= '-' . hrtime( true );

    return "<!-- wp:html -->\n<!-- built: {$built_at} -->\n" . $wrapped . "\n<!-- /wp:html -->";
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
 * @return bool True when sent (or there is nothing to send); false when
 *              wp_mail() reports a send failure (CON10 A3).
 */
function indivisible_newsletter_notify_webmaster($post_id, $title, $settings): bool {
    $to = $settings['webmaster_email'];
    if (empty($to)) {
        return true; // Nothing to send is not a failure.
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

    return (bool) wp_mail($to, $subject, $message);
}
