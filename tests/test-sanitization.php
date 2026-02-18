<?php
/**
 * Tests for Indivisible Newsletter admin settings sanitization.
 */
class Test_IN_Sanitization extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( IN_OPTION_KEY );
	}

	public function tearDown(): void {
		delete_option( IN_OPTION_KEY );
		parent::tearDown();
	}

	private function get_valid_input() {
		return array(
			'imap_host'         => 'imap.example.com',
			'imap_port'         => '993',
			'imap_encryption'   => 'ssl',
			'email_username'    => 'user@example.com',
			'email_password'    => '',
			'imap_folder'       => 'INBOX',
			'filter_by_sender'  => '',
			'qualified_senders' => '',
			'check_interval'    => 'hourly',
			'post_status'       => 'draft',
			'post_category'     => '1',
			'webmaster_email'   => 'admin@example.com',
		);
	}

	public function test_sanitize_imap_host_strips_tags() {
		$input = $this->get_valid_input();
		$input['imap_host'] = '<script>alert("xss")</script>imap.example.com';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertStringNotContainsString( '<script>', $result['imap_host'] );
		$this->assertStringContainsString( 'imap.example.com', $result['imap_host'] );
	}

	public function test_sanitize_imap_port_is_absint() {
		$input = $this->get_valid_input();
		$input['imap_port'] = '-993';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( 993, $result['imap_port'] );
	}

	public function test_sanitize_imap_port_non_numeric() {
		$input = $this->get_valid_input();
		$input['imap_port'] = 'abc';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( 0, $result['imap_port'] );
	}

	public function test_sanitize_encryption_valid_ssl() {
		$input = $this->get_valid_input();
		$input['imap_encryption'] = 'ssl';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( 'ssl', $result['imap_encryption'] );
	}

	public function test_sanitize_encryption_valid_tls() {
		$input = $this->get_valid_input();
		$input['imap_encryption'] = 'tls';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( 'tls', $result['imap_encryption'] );
	}

	public function test_sanitize_encryption_valid_none() {
		$input = $this->get_valid_input();
		$input['imap_encryption'] = 'none';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( 'none', $result['imap_encryption'] );
	}

	public function test_sanitize_encryption_invalid_falls_to_default() {
		$input = $this->get_valid_input();
		$input['imap_encryption'] = 'invalid';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( 'ssl', $result['imap_encryption'] );
	}

	public function test_sanitize_post_status_valid_draft() {
		$input = $this->get_valid_input();
		$input['post_status'] = 'draft';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( 'draft', $result['post_status'] );
	}

	public function test_sanitize_post_status_valid_publish() {
		$input = $this->get_valid_input();
		$input['post_status'] = 'publish';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( 'publish', $result['post_status'] );
	}

	public function test_sanitize_post_status_invalid_falls_to_default() {
		$input = $this->get_valid_input();
		$input['post_status'] = 'private';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( 'draft', $result['post_status'] );
	}

	public function test_sanitize_filter_by_sender_enabled() {
		$input = $this->get_valid_input();
		$input['filter_by_sender'] = '1';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertTrue( $result['filter_by_sender'] );
	}

	public function test_sanitize_filter_by_sender_disabled() {
		$input = $this->get_valid_input();
		$input['filter_by_sender'] = '';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertFalse( $result['filter_by_sender'] );
	}

	public function test_sanitize_qualified_senders_sanitizes_emails() {
		$input = $this->get_valid_input();
		$input['qualified_senders'] = "valid@example.com\ninvalid<>@email\nother@test.com";

		$result = indivisible_newsletter_sanitize_settings( $input );
		$lines = array_filter( explode( "\n", $result['qualified_senders'] ) );
		// Invalid email should be stripped.
		$this->assertContains( 'valid@example.com', $lines );
		$this->assertContains( 'other@test.com', $lines );
	}

	public function test_sanitize_qualified_senders_trims_whitespace() {
		$input = $this->get_valid_input();
		$input['qualified_senders'] = "  user@example.com  \n  admin@test.com  ";

		$result = indivisible_newsletter_sanitize_settings( $input );
		$lines = array_filter( explode( "\n", $result['qualified_senders'] ) );
		$this->assertContains( 'user@example.com', $lines );
		$this->assertContains( 'admin@test.com', $lines );
	}

	public function test_sanitize_webmaster_email() {
		$input = $this->get_valid_input();
		$input['webmaster_email'] = 'valid@example.com';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( 'valid@example.com', $result['webmaster_email'] );
	}

	public function test_sanitize_post_category_is_absint() {
		$input = $this->get_valid_input();
		$input['post_category'] = '42';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( 42, $result['post_category'] );
	}

	public function test_sanitize_password_encrypts_when_provided() {
		$input = $this->get_valid_input();
		$input['email_password'] = 'new-password';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertNotEquals( 'new-password', $result['email_password'] );
		// Should be able to decrypt back.
		$this->assertEquals( 'new-password', indivisible_newsletter_decrypt( $result['email_password'] ) );
	}

	public function test_sanitize_password_keeps_existing_when_blank() {
		// Save an existing encrypted password.
		$encrypted = indivisible_newsletter_encrypt( 'existing-password' );
		update_option( IN_OPTION_KEY, array( 'email_password' => $encrypted ) );

		$input = $this->get_valid_input();
		$input['email_password'] = '';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertEquals( $encrypted, $result['email_password'] );
	}

	public function test_sanitize_reschedules_cron_when_interval_changes() {
		// Set current interval.
		update_option( IN_OPTION_KEY, array( 'check_interval' => 'hourly' ) );

		// Clear any existing cron.
		indivisible_newsletter_clear_cron();

		// Schedule with current interval.
		indivisible_newsletter_schedule_cron( 'hourly' );
		$old_timestamp = wp_next_scheduled( IN_CRON_HOOK );
		$this->assertNotFalse( $old_timestamp );

		// Now sanitize with new interval.
		$input = $this->get_valid_input();
		$input['check_interval'] = 'daily';

		indivisible_newsletter_sanitize_settings( $input );

		// Cron should be rescheduled.
		$new_timestamp = wp_next_scheduled( IN_CRON_HOOK );
		$this->assertNotFalse( $new_timestamp );
	}

	public function test_sanitize_does_not_reschedule_when_interval_same() {
		update_option( IN_OPTION_KEY, array( 'check_interval' => 'hourly' ) );

		indivisible_newsletter_clear_cron();
		indivisible_newsletter_schedule_cron( 'hourly' );
		$old_timestamp = wp_next_scheduled( IN_CRON_HOOK );

		$input = $this->get_valid_input();
		$input['check_interval'] = 'hourly';

		indivisible_newsletter_sanitize_settings( $input );

		$new_timestamp = wp_next_scheduled( IN_CRON_HOOK );
		// Timestamp should be the same since interval didn't change.
		$this->assertEquals( $old_timestamp, $new_timestamp );
	}

	public function test_sanitize_email_username_strips_tags() {
		$input = $this->get_valid_input();
		$input['email_username'] = '<b>user</b>@example.com';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertStringNotContainsString( '<b>', $result['email_username'] );
	}

	public function test_sanitize_imap_folder_strips_tags() {
		$input = $this->get_valid_input();
		$input['imap_folder'] = '<script>INBOX</script>';

		$result = indivisible_newsletter_sanitize_settings( $input );
		$this->assertStringNotContainsString( '<script>', $result['imap_folder'] );
	}
}
