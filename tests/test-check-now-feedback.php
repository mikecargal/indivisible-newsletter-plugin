<?php
/**
 * CON10 A2 — Check Now reports per-email batch outcomes in its result banner
 * via .ids-alert instead of logging failures only.
 *
 * The notice logic is a pure function over process_emails()'s structured
 * result {created, failures, notify_failures} (or a WP_Error fetch failure),
 * so the success / partial-failure / total-failure / empty branches are
 * testable without an IMAP connection:
 *   - fetch WP_Error            → .ids-alert-error
 *   - created > 0, no failures  → .ids-alert-success
 *   - created == 0, no failures → .ids-alert (info: nothing to do)
 *   - some failures, some created → .ids-alert-warning (partial)
 *   - created == 0, all failed  → .ids-alert-error
 *   - notify_failures present   → surfaced in the banner (A3 overlap)
 *
 * @package Indivisible_Newsletter
 */

class Test_Check_Now_Feedback extends IN_Test_Case {

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( IN_OPTION_KEY );
	}

	public function tearDown(): void {
		unset( $_POST['in_check_now'], $_REQUEST['_wpnonce'] );
		delete_option( IN_OPTION_KEY );
		parent::tearDown();
	}

	private function result( int $created, array $failures = array(), array $notify_failures = array() ): array {
		return array(
			'created'         => $created,
			'failures'        => $failures,
			'notify_failures' => $notify_failures,
		);
	}

	// --- pure notice logic ---

	public function test_notice_is_error_on_fetch_wp_error(): void {
		$notice = indivisible_newsletter_check_now_notice( new WP_Error( 'fetch', 'IMAP unreachable' ) );

		$this->assertHtml( $notice )->find( '.ids-alert.ids-alert-error' )->exists();
		$this->assertHtml( $notice )->find( '.ids-alert-error .ids-alert-body' )->containsText( 'IMAP unreachable' );
	}

	public function test_notice_is_success_on_clean_run(): void {
		$notice = indivisible_newsletter_check_now_notice( $this->result( 2 ) );

		$this->assertHtml( $notice )->find( '.ids-alert.ids-alert-success' )->exists();
		$this->assertHtml( $notice )->find( '.ids-alert-success .ids-alert-body' )->containsText( '2' );
	}

	public function test_notice_is_info_when_nothing_to_process(): void {
		$notice = indivisible_newsletter_check_now_notice( $this->result( 0 ) );

		// Some .ids-alert renders (not a silent empty string); not an error.
		$this->assertHtml( $notice )->find( '.ids-alert' )->exists();
		$this->assertHtml( $notice )->find( '.ids-alert-error' )->doesNotExist();
	}

	public function test_notice_is_warning_on_partial_failure(): void {
		$notice = indivisible_newsletter_check_now_notice(
			$this->result( 1, array( 'Failed to create post for "Bad One": insert error' ) )
		);

		$this->assertHtml( $notice )->find( '.ids-alert.ids-alert-warning' )->exists();
		$this->assertHtml( $notice )->find( '.ids-alert-warning .ids-alert-body' )->containsText( 'Bad One' );
	}

	public function test_notice_is_error_when_all_failed(): void {
		$notice = indivisible_newsletter_check_now_notice(
			$this->result( 0, array( 'Failed A', 'Failed B' ) )
		);

		$this->assertHtml( $notice )->find( '.ids-alert.ids-alert-error' )->exists();
	}

	public function test_notice_surfaces_notify_failures(): void {
		$notice = indivisible_newsletter_check_now_notice(
			$this->result( 1, array(), array( 'Webmaster notification failed for "Weekly Update".' ) )
		);

		// Not a clean success — the notify failure is visible (warning), not swallowed.
		$this->assertHtml( $notice )->find( '.ids-alert-warning' )->exists();
		$this->assertHtml( $notice )->find( '.ids-alert-warning .ids-alert-body' )->containsText( 'Webmaster notification failed' );
	}

	// --- handler integration: missing settings → fetch error → .ids-alert-error ---

	public function test_handler_renders_ids_alert_error_when_settings_missing(): void {
		$_POST['in_check_now'] = '1';
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'in_check_now_action' );

		$notice = indivisible_newsletter_handle_check_now_action();

		$this->assertHtml( $notice )->find( '.ids-alert.ids-alert-error' )->exists();
	}

	public function test_handler_silent_when_not_submitted(): void {
		$this->assertSame( '', indivisible_newsletter_handle_check_now_action() );
	}
}
