<?php
/**
 * Tests for plugin bootstrap.
 *
 * @package Indivisible_Newsletter
 */

class Test_Plugin_Bootstrap extends IN_Test_Case {
	public function test_version_constant_matches_plugin_header(): void {
		$plugin_data = get_file_data(
			dirname( __DIR__ ) . '/src/indivisible-newsletter.php',
			array( 'Version' => 'Version' )
		);
		$this->assertSame( $plugin_data['Version'], IN_VERSION );
	}

	public function test_frontend_css_is_not_hooked_to_wp_head(): void {
		// CON21 (audit P4-2): the .in-newsletter-content rules moved verbatim to
		// the theme's style.css. The plugin must not echo an inline <style>.
		$this->assertFalse(
			has_action( 'wp_head', 'indivisible_newsletter_frontend_css' ),
			'Frontend CSS must not be hooked to wp_head; the theme serves it'
		);
	}

	// --- Activation / Deactivation ---

	public function test_activate_creates_default_options_when_missing(): void {
		delete_option( IN_OPTION_KEY );

		indivisible_newsletter_activate();

		$this->assertNotEmpty( get_option( IN_OPTION_KEY ) );
	}

	public function test_activate_does_not_overwrite_existing_options(): void {
		$custom = array( 'imap_host' => 'custom.example.com' );
		update_option( IN_OPTION_KEY, $custom );

		indivisible_newsletter_activate();

		$saved = get_option( IN_OPTION_KEY );
		$this->assertEquals( 'custom.example.com', $saved['imap_host'] );
	}

	public function test_activate_schedules_cron(): void {
		wp_clear_scheduled_hook( IN_CRON_HOOK );

		indivisible_newsletter_activate();

		$this->assertNotFalse( wp_next_scheduled( IN_CRON_HOOK ) );
	}

	public function test_deactivate_clears_cron(): void {
		wp_schedule_single_event( time() + 3600, IN_CRON_HOOK );
		$this->assertNotFalse( wp_next_scheduled( IN_CRON_HOOK ) );

		indivisible_newsletter_deactivate();

		$this->assertFalse( wp_next_scheduled( IN_CRON_HOOK ) );
	}

	// --- Uninstall ---

	public function test_uninstall_removes_options_and_cron(): void {
		// Set up data that uninstall should clean.
		update_option( 'indivisible_newsletter_settings', array( 'imap_host' => 'test' ) );
		update_option( 'indivisible_newsletter_processed_ids', array( 1, 2, 3 ) );
		wp_schedule_single_event( time() + 3600, 'indivisible_newsletter_check_email' );

		// Define the guard constant so uninstall.php doesn't exit.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		require dirname( __DIR__ ) . '/src/uninstall.php';

		$this->assertFalse( get_option( 'indivisible_newsletter_settings' ) );
		$this->assertFalse( get_option( 'indivisible_newsletter_processed_ids' ) );
		$this->assertFalse( wp_next_scheduled( 'indivisible_newsletter_check_email' ) );
	}
}
