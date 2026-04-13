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
}
