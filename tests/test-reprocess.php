<?php
/**
 * Tests for the newsletter reprocess feature.
 *
 * @package Indivisible_Newsletter
 */

class Test_IN_Reprocess extends IN_Test_Case {

    public function test_reprocess_function_exists(): void {
        $this->assertTrue(
            function_exists( 'indivisible_newsletter_reprocess_post' ),
            'indivisible_newsletter_reprocess_post() must be defined'
        );
    }

    public function test_reprocess_returns_not_found_error_when_post_missing(): void {
        wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

        $result = indivisible_newsletter_reprocess_post( 999999 );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'reprocess_not_found', $result->get_error_code() );
    }

    public function test_reprocess_returns_forbidden_error_when_user_lacks_capability(): void {
        $subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $subscriber );
        $post_id = $this->factory->post->create();

        $result = indivisible_newsletter_reprocess_post( $post_id );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'reprocess_forbidden', $result->get_error_code() );
    }

    public function test_reprocess_returns_no_raw_body_error_when_meta_missing(): void {
        wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
        $post_id = $this->factory->post->create();
        // Deliberately do not set _in_newsletter_raw_body meta.

        $result = indivisible_newsletter_reprocess_post( $post_id );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'reprocess_no_raw_body', $result->get_error_code() );
    }

    public function test_reprocess_updates_post_content_with_cleaned_raw_body(): void {
        wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

        $raw_body = '<table class="nl-container" style="background-color: #0068a5"><tr><td>Hello</td></tr></table>';
        $post_id  = $this->factory->post->create( array( 'post_content' => 'OLD CONTENT' ) );
        update_post_meta( $post_id, '_in_newsletter_raw_body', $raw_body );

        $result = indivisible_newsletter_reprocess_post( $post_id );

        $this->assertTrue( $result, 'Reprocess should return true on success' );

        $post = get_post( $post_id );
        $this->assertStringNotContainsString( 'OLD CONTENT', $post->post_content );
        $this->assertHtml( $post->post_content )
            ->find( 'div.in-newsletter-content' )
            ->exists();
        $this->assertHtml( $post->post_content )
            ->find( 'div.in-newsletter-content' )
            ->containsText( 'Hello' );
    }

    public function test_reprocess_is_idempotent(): void {
        wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
        $raw = '<table class="nl-container" style="background-color: #fff">X</table>';
        $post_id = $this->factory->post->create();
        update_post_meta( $post_id, '_in_newsletter_raw_body', $raw );

        indivisible_newsletter_reprocess_post( $post_id );
        $first = get_post( $post_id )->post_content;

        indivisible_newsletter_reprocess_post( $post_id );
        $second = get_post( $post_id )->post_content;

        $this->assertSame(
            $first,
            $second,
            'Two reprocess calls should produce identical post_content'
        );
        $this->assertSame(
            $raw,
            get_post_meta( $post_id, '_in_newsletter_raw_body', true ),
            'Raw body meta should never change after reprocess'
        );
    }

    public function test_reprocess_preserves_post_metadata(): void {
        wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
        $raw = '<p>Body</p>';
        $author_id = $this->factory->user->create( array( 'role' => 'editor' ) );
        $post_id = $this->factory->post->create( array(
            'post_title'  => 'Original Title',
            'post_date'   => '2026-01-15 10:00:00',
            'post_status' => 'publish',
            'post_author' => $author_id,
        ) );
        update_post_meta( $post_id, '_in_newsletter_raw_body', $raw );
        update_post_meta( $post_id, '_login_required', '1' );

        indivisible_newsletter_reprocess_post( $post_id );

        $post = get_post( $post_id );
        $this->assertSame( 'Original Title', $post->post_title );
        $this->assertSame( '2026-01-15 10:00:00', $post->post_date );
        $this->assertSame( 'publish', $post->post_status );
        $this->assertEquals( $author_id, $post->post_author );
        $this->assertSame( '1', get_post_meta( $post_id, '_login_required', true ) );
    }

    public function test_reprocess_creates_revision(): void {
        wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
        $raw = '<p>Body</p>';
        $post_id = $this->factory->post->create( array( 'post_content' => 'ORIGINAL' ) );
        update_post_meta( $post_id, '_in_newsletter_raw_body', $raw );
        $revisions_before = count( wp_get_post_revisions( $post_id ) );

        indivisible_newsletter_reprocess_post( $post_id );

        $revisions_after = count( wp_get_post_revisions( $post_id ) );
        $this->assertGreaterThan(
            $revisions_before,
            $revisions_after,
            'wp_update_post should create a revision so the pre-reprocess content is recoverable'
        );
    }
}
