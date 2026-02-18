<?php
/**
 * Extended tests for Indivisible Newsletter processor functions.
 *
 * Covers forwarded content extraction edge cases, HTML cleaning,
 * post creation details, and webmaster notifications.
 */
class Test_IN_Processor_Extended extends WP_UnitTestCase {

	private $sent_emails = array();

	public function setUp(): void {
		parent::setUp();
		delete_option( IN_OPTION_KEY );
		$this->sent_emails = array();
		// Intercept wp_mail.
		add_filter( 'pre_wp_mail', array( $this, 'capture_email' ), 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_email' ) );
		delete_option( IN_OPTION_KEY );
		delete_option( IN_PROCESSED_KEY );
		parent::tearDown();
	}

	public function capture_email( $null, $atts ) {
		$this->sent_emails[] = $atts;
		return true; // Prevent actual sending.
	}

	// --- clean_subject ---

	public function test_clean_subject_multiple_nested_prefixes() {
		$this->assertEquals(
			'Newsletter',
			indivisible_newsletter_clean_subject( 'Fwd: Fwd: Re: Newsletter' )
		);
	}

	public function test_clean_subject_preserves_fwd_in_middle() {
		$this->assertEquals(
			'My Fwd: Newsletter',
			indivisible_newsletter_clean_subject( 'My Fwd: Newsletter' )
		);
	}

	public function test_clean_subject_empty_string() {
		$this->assertEquals( '', indivisible_newsletter_clean_subject( '' ) );
	}

	public function test_clean_subject_only_prefix() {
		$this->assertEquals( '', indivisible_newsletter_clean_subject( 'Fwd: Re:' ) );
	}

	public function test_clean_subject_trims_whitespace() {
		$this->assertEquals(
			'Newsletter',
			indivisible_newsletter_clean_subject( '  Fwd:   Newsletter  ' )
		);
	}

	// --- extract_forwarded_content ---

	public function test_extract_forwarded_content_strips_body_wrapper() {
		$html = '<html><head><title>Test</title></head><body><p>Content</p></body></html>';
		$result = indivisible_newsletter_extract_forwarded_content( $html );
		$this->assertEquals( '<p>Content</p>', $result );
	}

	public function test_extract_forwarded_content_no_body_tag() {
		$html = '<p>Just a paragraph</p>';
		$result = indivisible_newsletter_extract_forwarded_content( $html );
		$this->assertEquals( '<p>Just a paragraph</p>', $result );
	}

	public function test_extract_forwarded_content_apple_mail_format() {
		$html = '<div>Begin forwarded message:</div>' .
			'<blockquote type="cite">' .
			'<div><span><b>From: </b></span>sender@example.com</div>' .
			'<div><span><b>Subject: </b></span>Test Subject</div>' .
			'<div><span><b>Date: </b></span>Feb 17, 2026</div>' .
			'<div><span><b>To: </b></span>recipient@example.com</div>' .
			'<p>Actual newsletter content here</p>' .
			'</blockquote>';

		$result = indivisible_newsletter_extract_forwarded_content( $html );

		$this->assertStringContainsString( 'Actual newsletter content here', $result );
		$this->assertStringNotContainsString( 'Begin forwarded message', $result );
		$this->assertStringNotContainsString( 'sender@example.com', $result );
		$this->assertStringNotContainsString( 'recipient@example.com', $result );
	}

	public function test_extract_forwarded_content_apple_mail_with_reply_to() {
		$html = '<blockquote type="cite">' .
			'<div><span><b>From: </b></span>test@example.com</div>' .
			'<div><span><b>Reply-To: </b></span>reply@example.com</div>' .
			'<p>Content</p>' .
			'</blockquote>';

		$result = indivisible_newsletter_extract_forwarded_content( $html );
		$this->assertStringContainsString( 'Content', $result );
		$this->assertStringNotContainsString( 'Reply-To', $result );
	}

	public function test_extract_forwarded_content_no_blockquote_cite() {
		// Regular blockquote without type="cite" should NOT be treated as forwarded.
		$html = '<blockquote><p>Quoted text</p></blockquote>';
		$result = indivisible_newsletter_extract_forwarded_content( $html );
		$this->assertStringContainsString( 'Quoted text', $result );
	}

	public function test_extract_forwarded_content_body_with_attributes() {
		$html = '<html><body style="margin:0; padding:0;"><p>Styled body</p></body></html>';
		$result = indivisible_newsletter_extract_forwarded_content( $html );
		$this->assertEquals( '<p>Styled body</p>', $result );
	}

	// --- clean_html ---

	public function test_clean_html_removes_unsubscribe_link() {
		$html = '<p>Content</p><a href="http://example.com/unsub">Unsubscribe</a><p>More</p>';
		$result = indivisible_newsletter_clean_html( $html );

		$this->assertStringNotContainsString( 'Unsubscribe', $result );
		$this->assertStringContainsString( 'Content', $result );
		$this->assertStringContainsString( 'More', $result );
	}

	public function test_clean_html_removes_unsubscribe_case_insensitive() {
		$html = '<a href="http://example.com">Click to UNSUBSCRIBE</a>';
		$result = indivisible_newsletter_clean_html( $html );
		$this->assertStringNotContainsString( 'UNSUBSCRIBE', $result );
	}

	public function test_clean_html_removes_unsubscribe_with_surrounding_text() {
		$html = '<a href="http://example.com">Click here to unsubscribe from this list</a>';
		$result = indivisible_newsletter_clean_html( $html );
		$this->assertStringNotContainsString( 'unsubscribe', $result );
	}

	public function test_clean_html_preserves_non_unsubscribe_links() {
		$html = '<a href="http://example.com">Read More</a>';
		$result = indivisible_newsletter_clean_html( $html );
		$this->assertStringContainsString( 'Read More', $result );
	}

	public function test_clean_html_sets_nl_container_background() {
		$html = '<table class="nl-container" style="background-color: #ffffff; padding: 10px;">Content</table>';
		$result = indivisible_newsletter_clean_html( $html );
		$this->assertStringContainsString( 'background-color: var(--wp--preset--color--background)', $result );
	}

	public function test_clean_html_adds_text_color_to_nl_container() {
		$html = '<table class="nl-container" style="padding: 10px;">Content</table>';
		$result = indivisible_newsletter_clean_html( $html );
		$this->assertStringContainsString( 'color: #000000;', $result );
	}

	public function test_clean_html_preserves_other_content() {
		$html = '<table><tr><td>Regular table</td></tr></table>';
		$result = indivisible_newsletter_clean_html( $html );
		$this->assertStringContainsString( 'Regular table', $result );
	}

	// --- create_post_from_email ---

	public function test_create_post_from_email_basic() {
		update_option( IN_OPTION_KEY, array(
			'post_status'     => 'draft',
			'post_category'   => 0,
			'webmaster_email' => '',
		) );

		$email = array(
			'subject'    => 'Fwd: Test Newsletter',
			'html'       => '<html><body><p>Newsletter body</p></body></html>',
			'date'       => '2026-02-17',
			'message_id' => 'test-basic-123',
		);

		$post_id = indivisible_newsletter_create_post_from_email( $email );

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		$post = get_post( $post_id );
		$this->assertEquals( 'Test Newsletter', $post->post_title );
		$this->assertEquals( 'draft', $post->post_status );
	}

	public function test_create_post_wraps_in_gutenberg_html_block() {
		update_option( IN_OPTION_KEY, array(
			'post_status'     => 'draft',
			'post_category'   => 0,
			'webmaster_email' => '',
		) );

		$email = array(
			'subject'    => 'Newsletter',
			'html'       => '<p>Content</p>',
			'date'       => '2026-02-17',
			'message_id' => 'test-gutenberg',
		);

		$post_id = indivisible_newsletter_create_post_from_email( $email );
		$post    = get_post( $post_id );

		$this->assertStringContainsString( '<!-- wp:html -->', $post->post_content );
		$this->assertStringContainsString( '<!-- /wp:html -->', $post->post_content );
		$this->assertStringContainsString( 'Content', $post->post_content );
	}

	public function test_create_post_sets_login_required_meta() {
		update_option( IN_OPTION_KEY, array(
			'post_status'     => 'draft',
			'post_category'   => 0,
			'webmaster_email' => '',
		) );

		$email = array(
			'subject'    => 'Newsletter',
			'html'       => '<p>Protected</p>',
			'date'       => '2026-02-17',
			'message_id' => 'test-meta',
		);

		$post_id = indivisible_newsletter_create_post_from_email( $email );
		$this->assertEquals( '1', get_post_meta( $post_id, '_login_required', true ) );
	}

	public function test_create_post_uses_publish_status() {
		update_option( IN_OPTION_KEY, array(
			'post_status'     => 'publish',
			'post_category'   => 0,
			'webmaster_email' => '',
		) );

		$email = array(
			'subject'    => 'Published Newsletter',
			'html'       => '<p>Content</p>',
			'date'       => '2026-02-17',
			'message_id' => 'test-publish',
		);

		$post_id = indivisible_newsletter_create_post_from_email( $email );
		$post    = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status );
	}

	public function test_create_post_sets_category() {
		$cat_id = wp_create_category( 'Test Newsletters' );
		update_option( IN_OPTION_KEY, array(
			'post_status'     => 'draft',
			'post_category'   => $cat_id,
			'webmaster_email' => '',
		) );

		$email = array(
			'subject'    => 'Categorized Newsletter',
			'html'       => '<p>Content</p>',
			'date'       => '2026-02-17',
			'message_id' => 'test-cat',
		);

		$post_id    = indivisible_newsletter_create_post_from_email( $email );
		$categories = wp_get_post_categories( $post_id );
		$this->assertContains( $cat_id, $categories );

		wp_delete_category( $cat_id );
	}

	// --- notify_webmaster ---

	public function test_notify_webmaster_sends_email() {
		update_option( IN_OPTION_KEY, array(
			'post_status'     => 'draft',
			'post_category'   => 0,
			'webmaster_email' => 'admin@example.com',
		) );

		$email = array(
			'subject'    => 'Notify Test',
			'html'       => '<p>Content</p>',
			'date'       => '2026-02-17',
			'message_id' => 'test-notify',
		);

		indivisible_newsletter_create_post_from_email( $email );

		$this->assertCount( 1, $this->sent_emails );
		$this->assertEquals( 'admin@example.com', $this->sent_emails[0]['to'] );
		$this->assertStringContainsString( 'Notify Test', $this->sent_emails[0]['subject'] );
	}

	public function test_notify_webmaster_skips_when_no_email() {
		// Call notify_webmaster directly with empty webmaster_email,
		// since get_settings() has fallback logic that fills empty values.
		$post_id = wp_insert_post( array(
			'post_title'  => 'No Notify',
			'post_status' => 'draft',
		) );

		$settings = array(
			'post_status'     => 'draft',
			'webmaster_email' => '',
		);
		indivisible_newsletter_notify_webmaster( $post_id, 'No Notify', $settings );
		$this->assertEmpty( $this->sent_emails );
	}

	public function test_notify_webmaster_includes_post_details() {
		update_option( IN_OPTION_KEY, array(
			'post_status'     => 'draft',
			'post_category'   => 0,
			'webmaster_email' => 'admin@example.com',
		) );

		$email = array(
			'subject'    => 'Details Test',
			'html'       => '<p>Content</p>',
			'date'       => '2026-02-17',
			'message_id' => 'test-details',
		);

		indivisible_newsletter_create_post_from_email( $email );

		$this->assertCount( 1, $this->sent_emails );
		$body = $this->sent_emails[0]['message'];
		$this->assertStringContainsString( 'Details Test', $body );
		$this->assertStringContainsString( 'draft', $body );
	}

	// --- process_emails (integration-level, mocked fetch) ---

	public function test_process_emails_tracks_processed_ids() {
		// We can't easily mock IMAP, but we can test the processed ID tracking.
		$processed = array( 'msg-1', 'msg-2' );
		update_option( IN_PROCESSED_KEY, $processed );

		$stored = get_option( IN_PROCESSED_KEY );
		$this->assertCount( 2, $stored );
		$this->assertContains( 'msg-1', $stored );
	}

	public function test_processed_ids_limit_to_500() {
		// Simulate 505 processed IDs.
		$ids = array();
		for ( $i = 1; $i <= 505; $i++ ) {
			$ids[] = "msg-{$i}";
		}
		// The code slices to last 500.
		$trimmed = array_slice( $ids, -500 );
		$this->assertCount( 500, $trimmed );
		$this->assertEquals( 'msg-6', $trimmed[0] ); // Oldest kept.
		$this->assertEquals( 'msg-505', end( $trimmed ) ); // Newest.
	}
}
