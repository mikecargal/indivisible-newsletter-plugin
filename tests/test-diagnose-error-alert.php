<?php
/**
 * CON10 D1 (Diagnose + Test Connection) — the Actions-row buttons share the
 * canonical .ids-alert idiom.
 *
 * - indivisible_newsletter_action_notice(): the shared success/error wrapper
 *   (used by Test Connection and Check Now) renders .ids-alert-success /
 *   .ids-alert-error from a string|WP_Error result.
 * - indivisible_newsletter_diagnose(): returns a structured {report, error}
 *   so a hard failure can be surfaced as a banner instead of being buried in
 *   the plain-text <pre> report.
 * - The Test Connection / Diagnose handlers render .ids-alert, not .notice.
 *
 * @package Indivisible_Newsletter
 */

class Test_Diagnose_Error_Alert extends IN_Test_Case {

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( IN_OPTION_KEY );
	}

	public function tearDown(): void {
		unset(
			$_POST['in_test_connection'],
			$_POST['in_diagnose'],
			$_REQUEST['_wpnonce']
		);
		delete_option( IN_OPTION_KEY );
		parent::tearDown();
	}

	// --- shared notice wrapper (binary success/error) ---

	public function test_action_notice_renders_ids_alert_error_for_wp_error(): void {
		$notice = indivisible_newsletter_action_notice( new WP_Error( 'x', 'Connection refused' ) );

		$this->assertHtml( $notice )->find( '.ids-alert.ids-alert-error' )->exists();
		$this->assertHtml( $notice )->find( '.ids-alert-error .ids-alert-body' )->containsText( 'Connection refused' );
	}

	public function test_action_notice_renders_ids_alert_success_for_string(): void {
		$notice = indivisible_newsletter_action_notice( 'Connection successful! Mailbox has 12 message(s).' );

		$this->assertHtml( $notice )->find( '.ids-alert.ids-alert-success' )->exists();
		$this->assertHtml( $notice )->find( '.ids-alert-success .ids-alert-body' )->containsText( '12 message(s)' );
	}

	// --- diagnose() structured return ---

	public function test_diagnose_returns_structured_report_and_error(): void {
		// No settings → diagnose stops early with a hard error.
		$result = indivisible_newsletter_diagnose();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'report', $result );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertNotEmpty( $result['report'], 'The full diagnostic report text is still produced for the <pre>.' );
		$this->assertNotNull( $result['error'], 'A hard failure (missing settings) must set the error so it can surface as a banner.' );
	}

	// --- Diagnose handler: report in <pre>, hard error as .ids-alert-error ---

	public function test_diagnose_handler_surfaces_error_as_ids_alert_error(): void {
		$_POST['in_diagnose'] = '1';
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'in_diagnose_action' );

		$result = indivisible_newsletter_handle_diagnose_action();

		$this->assertNotEmpty( $result['report'], 'The diagnostic report text is preserved for the <pre> block.' );
		$this->assertHtml( $result['notice'] )->find( '.ids-alert.ids-alert-error' )->exists();
	}

	public function test_diagnose_handler_silent_when_not_submitted(): void {
		$result = indivisible_newsletter_handle_diagnose_action();

		$this->assertSame( '', $result['report'] );
		$this->assertSame( '', $result['notice'] );
	}

	// --- Test Connection handler: .ids-alert, not .notice ---

	public function test_test_connection_handler_missing_settings_is_ids_alert_error(): void {
		$_POST['in_test_connection'] = '1';
		$_REQUEST['_wpnonce']        = wp_create_nonce( 'in_test_connection_action' );

		$notice = indivisible_newsletter_handle_test_connection_action();

		$this->assertHtml( $notice )->find( '.ids-alert.ids-alert-error' )->exists();
	}

	public function test_test_connection_handler_silent_when_not_submitted(): void {
		$this->assertSame( '', indivisible_newsletter_handle_test_connection_action() );
	}
}
