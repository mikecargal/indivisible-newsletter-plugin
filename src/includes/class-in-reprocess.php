<?php
/**
 * Newsletter reprocess feature: reprocess existing newsletter posts
 * by re-running the current cleaner against their stored raw email body.
 *
 * @package Indivisible_Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Re-run the current cleaner against a newsletter post's stored raw email body
 * and update the post content in place.
 *
 * @param int $post_id Post ID to reprocess.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function indivisible_newsletter_reprocess_post( int $post_id ) {
    $post = get_post( $post_id );
    if ( null === $post ) {
        return new WP_Error(
            'reprocess_not_found',
            sprintf( 'Post %d does not exist.', $post_id )
        );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return new WP_Error(
            'reprocess_forbidden',
            'You do not have permission to reprocess newsletters.'
        );
    }

    $raw_body = get_post_meta( $post_id, '_in_newsletter_raw_body', true );
    if ( empty( $raw_body ) ) {
        return new WP_Error(
            'reprocess_no_raw_body',
            "This post doesn't have the original email stored. Only newsletters created after the reprocess feature shipped can be reprocessed."
        );
    }

    // Happy path: run the current cleaner against the stored raw body and
    // update the post content via wp_update_post (which auto-creates a revision).
    $cleaned = indivisible_newsletter_clean_html( $raw_body );
    $cleaned = wp_kses_post( $cleaned );
    $wrapped = '<div class="in-newsletter-content">' . $cleaned . '</div>';
    $content = "<!-- wp:html -->\n" . $wrapped . "\n<!-- /wp:html -->";

    $update_result = wp_update_post(
        array(
            'ID'           => $post_id,
            'post_content' => $content,
        ),
        true
    );

    if ( is_wp_error( $update_result ) ) {
        return $update_result;
    }

    return true;
}
