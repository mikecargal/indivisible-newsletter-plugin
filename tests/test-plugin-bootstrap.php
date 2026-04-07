<?php
/**
 * Tests for plugin bootstrap.
 *
 * @package Indivisible_Newsletter
 */

class Test_Plugin_Bootstrap extends WP_UnitTestCase {
	public function test_version_constant_matches_plugin_header(): void {
		$plugin_data = get_file_data(
			dirname( __DIR__ ) . '/src/indivisible-newsletter.php',
			array( 'Version' => 'Version' )
		);
		$this->assertSame( $plugin_data['Version'], IN_VERSION );
	}

	public function test_frontend_css_hook_registered(): void {
		$this->assertNotFalse(
			has_action( 'wp_head', 'indivisible_newsletter_frontend_css' ),
			'Frontend CSS should be hooked to wp_head'
		);
	}

	public function test_frontend_css_outputs_word_break_rule(): void {
		ob_start();
		indivisible_newsletter_frontend_css();
		$output = ob_get_clean();

		$this->assertStringContainsString( '.in-newsletter-content', $output );
		$this->assertStringContainsString( 'break-word', $output );
	}
}
