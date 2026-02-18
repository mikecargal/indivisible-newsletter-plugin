<?php
/**
 * Tests for Indivisible Newsletter processing functions.
 */
class Test_IN_Processor extends WP_UnitTestCase {

	public function test_clean_subject_removes_fwd_prefix() {
		$this->assertEquals(
			'Weekly Newsletter',
			indivisible_newsletter_clean_subject( 'Fwd: Weekly Newsletter' )
		);
	}

	public function test_clean_subject_removes_fw_prefix() {
		$this->assertEquals(
			'Newsletter',
			indivisible_newsletter_clean_subject( 'Fw: Newsletter' )
		);
	}

	public function test_clean_subject_removes_re_prefix() {
		$this->assertEquals(
			'Newsletter',
			indivisible_newsletter_clean_subject( 'Re: Newsletter' )
		);
	}

	public function test_clean_subject_removes_multiple_prefixes() {
		$this->assertEquals(
			'Newsletter',
			indivisible_newsletter_clean_subject( 'Fwd: Re: Fw: Newsletter' )
		);
	}

	public function test_clean_subject_preserves_normal_subjects() {
		$this->assertEquals(
			'Hello World',
			indivisible_newsletter_clean_subject( 'Hello World' )
		);
	}

	public function test_clean_subject_case_insensitive() {
		$this->assertEquals(
			'Newsletter',
			indivisible_newsletter_clean_subject( 'FWD: Newsletter' )
		);
	}

	public function test_clean_html_removes_unsubscribe_links() {
		$html   = '<p>Content</p><a href="http://example.com">Unsubscribe</a><p>More</p>';
		$result = indivisible_newsletter_clean_html( $html );

		$this->assertStringNotContainsString( 'Unsubscribe', $result );
		$this->assertStringContainsString( 'Content', $result );
		$this->assertStringContainsString( 'More', $result );
	}

	public function test_clean_html_removes_unsubscribe_case_insensitive() {
		$html   = '<a href="http://example.com">Click to unsubscribe</a>';
		$result = indivisible_newsletter_clean_html( $html );

		$this->assertStringNotContainsString( 'unsubscribe', $result );
	}

	public function test_extract_forwarded_content_direct_email() {
		$html   = '<html><body><p>Direct content</p></body></html>';
		$result = indivisible_newsletter_extract_forwarded_content( $html );

		$this->assertEquals( '<p>Direct content</p>', $result );
	}

	public function test_extract_forwarded_content_plain_html() {
		$html   = '<p>Just a paragraph</p>';
		$result = indivisible_newsletter_extract_forwarded_content( $html );

		$this->assertEquals( '<p>Just a paragraph</p>', $result );
	}

	public function test_extract_forwarded_content_apple_mail() {
		$html = '<div>Begin forwarded message:</div>' .
				'<blockquote type="cite">' .
				'<div><span><b>From: </b></span>sender@example.com</div>' .
				'<div><span><b>Subject: </b></span>Test</div>' .
				'<p>Actual newsletter content</p>' .
				'</blockquote>';

		$result = indivisible_newsletter_extract_forwarded_content( $html );

		$this->assertStringContainsString( 'Actual newsletter content', $result );
		$this->assertStringNotContainsString( 'Begin forwarded message', $result );
		$this->assertStringNotContainsString( 'sender@example.com', $result );
	}

	public function test_create_post_from_email() {
		// Set up default settings.
		update_option( IN_OPTION_KEY, array(
			'post_status'    => 'draft',
			'post_category'  => 0,
			'webmaster_email' => '',
		) );

		$email = array(
			'subject'    => 'Fwd: Test Newsletter',
			'html'       => '<html><body><p>Newsletter body</p></body></html>',
			'date'       => '2026-02-17',
			'message_id' => 'test-123',
		);

		$post_id = indivisible_newsletter_create_post_from_email( $email );

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		$post = get_post( $post_id );
		$this->assertEquals( 'Test Newsletter', $post->post_title );
		$this->assertEquals( 'draft', $post->post_status );

		// Verify content is wrapped in a Gutenberg HTML block.
		$this->assertStringContainsString( '<!-- wp:html -->', $post->post_content );
		$this->assertStringContainsString( 'Newsletter body', $post->post_content );

		// Verify login-required meta is set.
		$this->assertEquals( '1', get_post_meta( $post_id, '_login_required', true ) );
	}

	public function test_get_settings_returns_defaults_when_no_option() {
		delete_option( IN_OPTION_KEY );

		$settings = indivisible_newsletter_get_settings();

		$this->assertEquals( 'imap.dreamhost.com', $settings['imap_host'] );
		$this->assertEquals( '993', $settings['imap_port'] );
		$this->assertEquals( 'ssl', $settings['imap_encryption'] );
		$this->assertEquals( 'draft', $settings['post_status'] );
		$this->assertEquals( 'INBOX', $settings['imap_folder'] );
	}

	public function test_get_settings_merges_with_saved() {
		update_option( IN_OPTION_KEY, array(
			'imap_host'   => 'mail.example.com',
			'post_status' => 'publish',
		) );

		$settings = indivisible_newsletter_get_settings();

		$this->assertEquals( 'mail.example.com', $settings['imap_host'] );
		$this->assertEquals( 'publish', $settings['post_status'] );
		// Unsaved fields get defaults.
		$this->assertEquals( '993', $settings['imap_port'] );
	}
}
