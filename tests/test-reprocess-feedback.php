<?php
/**
 * CON10 A1 + D1 — the per-row Reprocess action surfaces every outcome through
 * the canonical .ids-alert family instead of silent returns or hand-built
 * inline-styled notice divs.
 *
 * A1: an attempted Reprocess with a bad nonce wp_die()s (matching the
 *     check_admin_referer siblings — Check Now / Test Connection / Diagnose);
 *     an invalid/missing post id surfaces a .ids-alert-error instead of
 *     returning silently.
 * D1: the success / error notices render as canonical .ids-alert banners, not
 *     the old .in-reprocess-notice-success / .in-reprocess-notice-error divs.
 *
 * @package Indivisible_Newsletter
 */

class Test_Reprocess_Feedback extends IN_Test_Case {

	public function setUp(): void {
		parent::setUp();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tearDown(): void {
		unset(
			$_POST['in_reprocess'],
			$_POST['in_reprocess_post_id'],
			$_POST['_wpnonce'],
			$_REQUEST['_wpnonce']
		);
		parent::tearDown();
	}

	private function seed_reprocessable_post(): int {
		$post_id = $this->factory->post->create( array( 'post_title' => 'Weekly Update' ) );
		update_post_meta( $post_id, '_in_newsletter_raw_body', '<p>Hello</p>' );
		return $post_id;
	}

	private function submit_reprocess( int $post_id, string $nonce ): void {
		$_POST['in_reprocess']         = '1';
		$_POST['in_reprocess_post_id'] = (string) $post_id;
		$_POST['_wpnonce']             = $nonce;
		$_REQUEST['_wpnonce']          = $nonce;
	}

	// --- A1: nonce failure aligns with the wp_die siblings ---

	public function test_bad_nonce_wp_dies_like_siblings(): void {
		$post_id = $this->seed_reprocessable_post();
		$this->submit_reprocess( $post_id, 'not-a-valid-nonce' );

		$this->expectException( WPDieException::class );
		indivisible_newsletter_handle_reprocess_action();
	}

	// --- A1: invalid/missing post id surfaces .ids-alert-error (no silent return) ---

	public function test_invalid_post_id_surfaces_ids_alert_error(): void {
		$_POST['in_reprocess']         = '1';
		$_POST['in_reprocess_post_id'] = '0';

		$result = indivisible_newsletter_handle_reprocess_action();

		$this->assertNotSame( '', $result['notice'], 'An attempted reprocess with a bad post id must not return an empty (silent) notice.' );
		$this->assertHtml( $result['notice'] )->find( '.ids-alert.ids-alert-error' )->exists();
	}

	public function test_no_reprocess_submitted_stays_silent(): void {
		// No $_POST['in_reprocess'] — not an attempt, so no notice and no die.
		$result = indivisible_newsletter_handle_reprocess_action();

		$this->assertSame( '', $result['notice'] );
		$this->assertNull( $result['post_id'] );
	}

	// --- D1: success notice is a canonical .ids-alert-success with the view link ---

	public function test_success_notice_is_ids_alert_success(): void {
		$post_id = $this->seed_reprocessable_post();
		$this->submit_reprocess( $post_id, wp_create_nonce( 'in_reprocess_action_' . $post_id ) );

		$result = indivisible_newsletter_handle_reprocess_action();

		$this->assertSame( $post_id, $result['post_id'] );
		$this->assertHtml( $result['notice'] )->find( '.ids-alert.ids-alert-success' )->exists();
		$this->assertHtml( $result['notice'] )->find( '.ids-alert-success .ids-alert-body' )->containsText( 'Weekly Update' );
		// The "view post" link survives the migration (ids_render_alert escapes its
		// message, so the success banner is hand-emitted to keep the anchor).
		$this->assertHtml( $result['notice'] )->find( '.ids-alert-success .ids-alert-body a' )->exists();
		// The deprecated hand-built inline-styled div is gone.
		$this->assertHtml( $result['notice'] )->find( '.in-reprocess-notice-success' )->doesNotExist();
	}

	// --- D1: error notice (reprocess WP_Error) is a canonical .ids-alert-error ---

	public function test_error_notice_is_ids_alert_error(): void {
		// A post WITHOUT _in_newsletter_raw_body → reprocess returns WP_Error.
		$post_id = $this->factory->post->create();
		$this->submit_reprocess( $post_id, wp_create_nonce( 'in_reprocess_action_' . $post_id ) );

		$result = indivisible_newsletter_handle_reprocess_action();

		$this->assertHtml( $result['notice'] )->find( '.ids-alert.ids-alert-error' )->exists();
		$this->assertHtml( $result['notice'] )->find( '.in-reprocess-notice-error' )->doesNotExist();
	}
}
