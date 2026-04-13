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
 * When a reprocess action has just run, the caller passes the affected
 * post ID and a pre-built admin notice HTML. The renderer emits that
 * notice as a div directly below the "Recent Newsletters" heading and
 * above the table, keeping the notice close to the section the user
 * was clicking in without triggering HTML-table parsing quirks that
 * foster-parent content out of table cells.
 *
 * @param int    $reprocessed_post_id     Post ID that was just reprocessed (used
 *                                         as a "did a reprocess happen" guard;
 *                                         the notice above the table renders
 *                                         when this is non-zero AND the notice
 *                                         HTML is non-empty). 0 = no notice.
 *                                         Defaults to 0.
 * @param string $reprocessed_notice_html Pre-escaped admin notice HTML to
 *                                         render inline in an ARIA live
 *                                         region below the reprocessed row.
 *                                         Ignored if $reprocessed_post_id
 *                                         doesn't match any rendered row or
 *                                         this string is empty. Defaults to ''.
 * @return string HTML markup.
 */
function indivisible_newsletter_render_recent_newsletters_section(
    int $reprocessed_post_id = 0,
    string $reprocessed_notice_html = ''
): string {
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
    <?php if ( $reprocessed_post_id && '' !== $reprocessed_notice_html ) : ?>
        <div class="in-recent-newsletters-notice">
            <?php echo $reprocessed_notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside handler ?>
        </div>
    <?php endif; ?>
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
 * Returns an associative array describing the outcome:
 *   - 'post_id' (int|null): the post that was reprocessed, or null if no
 *     action was taken (no POST data, invalid nonce, or invalid post_id).
 *   - 'notice'  (string):    an admin notice HTML string for the caller to
 *     display inline, or '' when no action was taken. Error notices use the
 *     notice-error class; success notices use notice-success. The renderer
 *     places this inline next to the reprocessed row (see
 *     indivisible_newsletter_render_recent_newsletters_section).
 *
 * @return array{post_id:int|null,notice:string}
 */
function indivisible_newsletter_handle_reprocess_action(): array {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below
    if ( ! isset( $_POST['in_reprocess'] ) || ! isset( $_POST['in_reprocess_post_id'] ) ) {
        return array( 'post_id' => null, 'notice' => '' );
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via absint()
    $post_id = absint( $_POST['in_reprocess_post_id'] );
    if ( 0 === $post_id ) {
        return array( 'post_id' => null, 'notice' => '' );
    }

    $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'in_reprocess_action_' . $post_id ) ) {
        return array( 'post_id' => null, 'notice' => '' );
    }

    $result = indivisible_newsletter_reprocess_post( $post_id );

    if ( is_wp_error( $result ) ) {
        return array(
            'post_id' => $post_id,
            'notice'  => '<div class="in-reprocess-notice-error" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #d63638;padding:1px 12px;margin:5px 0 15px;"><p>' . esc_html( $result->get_error_message() ) . '</p></div>',
        );
    }

    $post      = get_post( $post_id );
    $title     = $post ? $post->post_title : '(unknown)';
    $permalink = $post ? get_permalink( $post_id ) : '#';

    return array(
        'post_id' => $post_id,
        'notice'  => sprintf(
            '<div class="in-reprocess-notice-success" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #00a32a;padding:1px 12px;margin:5px 0 15px;"><p>Reprocessed: <strong>%s</strong> — <a href="%s" target="_blank" rel="noopener">view post</a></p></div>',
            esc_html( $title ),
            esc_url( $permalink )
        ),
    );
}
