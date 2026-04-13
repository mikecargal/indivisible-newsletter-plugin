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
            'This post doesn\'t have the original email stored. Only newsletters created after the reprocess feature shipped can be reprocessed.'
        );
    }

    // Happy path: build the final post content via the shared pipeline and
    // update the post via wp_update_post (which auto-creates a revision).
    $content = indivisible_newsletter_build_post_content( $raw_body );

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

/**
 * Render the "Recent Newsletters" section of the settings page as an HTML
 * string. Returns the markup so callers can echo it or pass it to tests.
 *
 * Lists newsletter-origin posts from the last 90 days that have the
 * _in_newsletter_raw_body meta. Each row offers View and Reprocess buttons.
 *
 * @return string HTML markup.
 */
function indivisible_newsletter_render_recent_newsletters_section(): string {
    $posts = get_posts( array(
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => 50,
        'date_query'     => array(
            array(
                'column' => 'post_date',
                'after'  => '90 days ago',
            ),
        ),
        'meta_key'       => '_in_newsletter_raw_body',
        'meta_compare'   => 'EXISTS',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    ob_start();
    ?>
    <hr />
    <h2>Recent Newsletters</h2>
    <?php if ( empty( $posts ) ) : ?>
        <p class="description">No newsletter posts from the last 90 days. Posts created before the reprocess feature shipped are not shown.</p>
    <?php else : ?>
        <table class="widefat striped in-recent-newsletters">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Message-ID</th>
                    <th>Raw Subject</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $posts as $post ) : ?>
                    <?php
                    $message_id  = (string) get_post_meta( $post->ID, '_in_newsletter_message_id', true );
                    $raw_subject = (string) get_post_meta( $post->ID, '_in_newsletter_raw_subject', true );
                    $mid_display = strlen( $message_id ) > 40 ? substr( $message_id, 0, 40 ) . '…' : $message_id;
                    $sub_display = mb_strlen( $raw_subject, 'UTF-8' ) > 60 ? mb_substr( $raw_subject, 0, 60, 'UTF-8' ) . '…' : $raw_subject;
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">
                                <?php echo esc_html( $post->post_title ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( get_the_date( 'Y-m-d', $post ) ); ?></td>
                        <td><code><?php echo esc_html( $mid_display ); ?></code></td>
                        <td><?php echo esc_html( $sub_display ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" rel="noopener" class="button button-small">View</a>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field( 'in_reprocess_action_' . $post->ID ); ?>
                                <input type="hidden" name="in_reprocess_post_id" value="<?php echo esc_attr( $post->ID ); ?>">
                                <button type="submit" name="in_reprocess" class="button button-small"
                                        onclick="return confirm('Reprocessing will replace the current content with a fresh clean of the original email. Any manual edits will be moved to a post revision. Continue?');">
                                    Reprocess
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

/**
 * Handle a Reprocess form submission from the settings page.
 *
 * Returns an admin notice HTML string (success or error) that the caller
 * can echo at the top of the settings page. Returns empty string when the
 * form was not submitted or the nonce is missing/invalid.
 *
 * @return string Admin notice HTML, or '' if no action was performed.
 */
function indivisible_newsletter_handle_reprocess_action(): string {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below
    if ( ! isset( $_POST['in_reprocess'] ) || ! isset( $_POST['in_reprocess_post_id'] ) ) {
        return '';
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via absint()
    $post_id = absint( $_POST['in_reprocess_post_id'] );
    if ( 0 === $post_id ) {
        return '';
    }

    // check_admin_referer dies on failure by default; use wp_verify_nonce
    // so the test harness can assert "no action taken" without triggering wp_die.
    $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'in_reprocess_action_' . $post_id ) ) {
        return '';
    }

    $result = indivisible_newsletter_reprocess_post( $post_id );

    if ( is_wp_error( $result ) ) {
        return '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
    }

    $post      = get_post( $post_id );
    $title     = $post ? $post->post_title : '(unknown)';
    $permalink = $post ? get_permalink( $post_id ) : '#';

    return sprintf(
        '<div class="notice notice-success"><p>Reprocessed: <strong>%s</strong> — <a href="%s" target="_blank" rel="noopener">view post</a></p></div>',
        esc_html( $title ),
        esc_url( $permalink )
    );
}
