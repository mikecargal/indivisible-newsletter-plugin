<?php
/**
 * CON10 B1 (enqueue) — the settings page enqueues the per-row Reprocess confirm
 * script, which declares ids-confirm-modal as a dependency so IDS.confirmModal
 * is defined before in-reprocess.js runs.
 *
 * @package Indivisible_Newsletter
 */

class Test_Admin_Enqueue_Ids extends IN_Test_Case {

	private const SETTINGS_HOOK = 'settings_page_indivisible-newsletter';

	public function setUp(): void {
		parent::setUp();
		// Fresh script/style registries so prior tests' enqueues don't leak,
		// then re-register the shared ids-* handles (normally done on the
		// admin_enqueue_scripts hook) so the dependency resolves.
		$GLOBALS['wp_scripts'] = new WP_Scripts();
		$GLOBALS['wp_styles']  = new WP_Styles();
		indivisible_shared_register_scripts();
	}

	public function test_settings_page_enqueues_in_reprocess_depending_on_confirm_modal(): void {
		indivisible_newsletter_enqueue_admin_assets( self::SETTINGS_HOOK );

		$registered = wp_scripts()->registered['in-reprocess'] ?? null;
		$this->assertNotNull(
			$registered,
			'in-reprocess must be registered when the settings page loads.'
		);
		$this->assertContains(
			'ids-confirm-modal',
			$registered->deps,
			'in-reprocess must depend on ids-confirm-modal so IDS.confirmModal exists before it runs.'
		);
		$this->assertTrue(
			wp_script_is( 'in-reprocess', 'enqueued' ),
			'in-reprocess must be enqueued on the settings page.'
		);
	}

	public function test_other_admin_pages_do_not_enqueue_in_reprocess(): void {
		indivisible_newsletter_enqueue_admin_assets( 'index.php' );

		$this->assertFalse(
			wp_script_is( 'in-reprocess', 'enqueued' ),
			'in-reprocess must not load outside the newsletter settings page.'
		);
	}
}
