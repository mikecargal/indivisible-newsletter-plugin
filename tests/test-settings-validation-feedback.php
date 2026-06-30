<?php
/**
 * CON10 C1 — saving settings reports each rejected/coerced value instead of an
 * unconditional "Settings saved."
 *
 * The sanitizer keeps coercing bad input (unchanged — covered by
 * test-sanitization.php) and records a settings error per rejected/coerced
 * value via add_settings_error(); WordPress's native dismissible
 * settings_errors() notices surface them on the settings page. (An earlier
 * parallel .ids-alert renderer was dropped — it duplicated WP's own notice.)
 *
 * @package Indivisible_Newsletter
 */

class Test_Settings_Validation_Feedback extends IN_Test_Case {

	public function setUp(): void {
		parent::setUp();
		delete_option( IN_OPTION_KEY );
		// Isolate the settings-error queue (a request-global + transient).
		$GLOBALS['wp_settings_errors'] = array();
		delete_transient( 'settings_errors' );
	}

	public function tearDown(): void {
		$GLOBALS['wp_settings_errors'] = array();
		delete_transient( 'settings_errors' );
		delete_option( IN_OPTION_KEY );
		parent::tearDown();
	}

	// --- sanitizer records validation feedback (without changing coercion) ---

	public function test_invalid_encryption_records_error_and_still_coerces(): void {
		$result = indivisible_newsletter_sanitize_settings( array( 'imap_encryption' => 'rot13' ) );

		// Coercion behaviour preserved.
		$this->assertContains( $result['imap_encryption'], array( 'ssl', 'tls', 'none' ) );
		// And the rejection is now reported.
		$this->assertNotEmpty( get_settings_errors( IN_OPTION_KEY ), 'An invalid encryption value must be reported.' );
	}

	public function test_invalid_post_status_records_error(): void {
		indivisible_newsletter_sanitize_settings( array( 'post_status' => 'bogus' ) );

		$this->assertNotEmpty( get_settings_errors( IN_OPTION_KEY ) );
	}

	public function test_invalid_webmaster_email_records_error_and_is_dropped(): void {
		$result = indivisible_newsletter_sanitize_settings( array( 'webmaster_email' => 'not-an-email' ) );

		$this->assertSame( '', $result['webmaster_email'], 'An invalid webmaster email is dropped (existing behaviour).' );
		$this->assertNotEmpty( get_settings_errors( IN_OPTION_KEY ), 'A dropped webmaster email must be reported.' );
	}

	public function test_dropped_qualified_senders_record_error(): void {
		$result = indivisible_newsletter_sanitize_settings(
			array( 'qualified_senders' => "good@example.com\nnot-an-email" )
		);

		$this->assertStringContainsString( 'good@example.com', $result['qualified_senders'] );
		$this->assertStringNotContainsString( 'not-an-email', $result['qualified_senders'] );
		$this->assertNotEmpty( get_settings_errors( IN_OPTION_KEY ), 'Dropped sender emails must be reported.' );
	}

	public function test_clean_input_records_no_error_feedback(): void {
		indivisible_newsletter_sanitize_settings( array(
			'imap_encryption' => 'ssl',
			'post_status'     => 'draft',
			'webmaster_email' => 'admin@example.com',
			'qualified_senders' => "a@example.com\nb@example.com",
		) );

		// A fully-valid save queues no error feedback for this option group; WP
		// then shows its own default "Settings saved." dismissible notice.
		$this->assertEmpty(
			get_settings_errors( IN_OPTION_KEY ),
			'A fully-valid save must not queue any newsletter settings feedback.'
		);
	}
}
