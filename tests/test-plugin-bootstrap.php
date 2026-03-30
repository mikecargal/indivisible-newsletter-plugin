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
}
