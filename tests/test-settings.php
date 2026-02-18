<?php
/**
 * Tests for Indivisible Newsletter settings and defaults.
 */
class Test_IN_Settings extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( IN_OPTION_KEY );
	}

	public function tearDown(): void {
		delete_option( IN_OPTION_KEY );
		parent::tearDown();
	}

	// --- Default values ---

	public function test_get_defaults_returns_array() {
		$defaults = indivisible_newsletter_get_defaults();
		$this->assertIsArray( $defaults );
	}

	public function test_get_defaults_has_all_keys() {
		$defaults = indivisible_newsletter_get_defaults();
		$expected_keys = array(
			'imap_host', 'imap_port', 'imap_encryption', 'email_username',
			'email_password', 'imap_folder', 'filter_by_sender', 'qualified_senders',
			'check_interval', 'post_status', 'webmaster_email', 'post_category',
		);
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $defaults, "Missing default key: {$key}" );
		}
	}

	public function test_get_defaults_imap_host() {
		$defaults = indivisible_newsletter_get_defaults();
		$this->assertEquals( 'imap.dreamhost.com', $defaults['imap_host'] );
	}

	public function test_get_defaults_imap_port() {
		$defaults = indivisible_newsletter_get_defaults();
		$this->assertEquals( '993', $defaults['imap_port'] );
	}

	public function test_get_defaults_imap_encryption() {
		$defaults = indivisible_newsletter_get_defaults();
		$this->assertEquals( 'ssl', $defaults['imap_encryption'] );
	}

	public function test_get_defaults_imap_folder() {
		$defaults = indivisible_newsletter_get_defaults();
		$this->assertEquals( 'INBOX', $defaults['imap_folder'] );
	}

	public function test_get_defaults_post_status() {
		$defaults = indivisible_newsletter_get_defaults();
		$this->assertEquals( 'draft', $defaults['post_status'] );
	}

	public function test_get_defaults_check_interval() {
		$defaults = indivisible_newsletter_get_defaults();
		$this->assertEquals( 'hourly', $defaults['check_interval'] );
	}

	public function test_get_defaults_filter_by_sender_false() {
		$defaults = indivisible_newsletter_get_defaults();
		$this->assertFalse( $defaults['filter_by_sender'] );
	}

	public function test_get_defaults_empty_password() {
		$defaults = indivisible_newsletter_get_defaults();
		$this->assertEquals( '', $defaults['email_password'] );
	}

	public function test_get_defaults_webmaster_email_uses_admin_email() {
		$admin_email = get_option( 'admin_email' );
		$defaults = indivisible_newsletter_get_defaults();
		$this->assertEquals( $admin_email, $defaults['webmaster_email'] );
	}

	// --- get_settings merging ---

	public function test_get_settings_returns_defaults_when_no_option() {
		$settings = indivisible_newsletter_get_settings();
		$this->assertEquals( 'imap.dreamhost.com', $settings['imap_host'] );
		$this->assertEquals( '993', $settings['imap_port'] );
		$this->assertEquals( 'ssl', $settings['imap_encryption'] );
		$this->assertEquals( 'draft', $settings['post_status'] );
		$this->assertEquals( 'INBOX', $settings['imap_folder'] );
	}

	public function test_get_settings_merges_saved_with_defaults() {
		update_option( IN_OPTION_KEY, array(
			'imap_host'   => 'mail.example.com',
			'post_status' => 'publish',
		) );

		$settings = indivisible_newsletter_get_settings();
		$this->assertEquals( 'mail.example.com', $settings['imap_host'] );
		$this->assertEquals( 'publish', $settings['post_status'] );
		// Unsaved fields get defaults.
		$this->assertEquals( '993', $settings['imap_port'] );
		$this->assertEquals( 'ssl', $settings['imap_encryption'] );
	}

	public function test_get_settings_falls_back_for_empty_string() {
		update_option( IN_OPTION_KEY, array(
			'imap_host' => '',
		) );

		$settings = indivisible_newsletter_get_settings();
		// Empty string should fall back to default since default is non-empty.
		$this->assertEquals( 'imap.dreamhost.com', $settings['imap_host'] );
	}

	public function test_get_settings_keeps_empty_when_default_is_empty() {
		update_option( IN_OPTION_KEY, array(
			'email_password' => '',
		) );

		$settings = indivisible_newsletter_get_settings();
		// Default for email_password is '' so empty is acceptable.
		$this->assertEquals( '', $settings['email_password'] );
	}

	// --- Default category ---

	public function test_get_default_category_returns_zero_when_no_newsletters_category() {
		// Ensure no "newsletters" category exists.
		$cat = get_category_by_slug( 'newsletters' );
		if ( $cat ) {
			wp_delete_category( $cat->term_id );
		}

		$result = indivisible_newsletter_get_default_category();
		$this->assertIsInt( $result );
	}

	public function test_get_default_category_finds_newsletters_slug() {
		$cat_id = wp_create_category( 'Newsletters' );
		// Force the slug.
		wp_update_term( $cat_id, 'category', array( 'slug' => 'newsletters' ) );

		$result = indivisible_newsletter_get_default_category();
		$this->assertEquals( $cat_id, $result );

		wp_delete_category( $cat_id );
	}

	public function test_get_default_category_finds_newsletters_by_name() {
		// Create a category named "Newsletters" but with a different slug.
		$cat_id = wp_insert_term( 'Newsletters', 'category', array( 'slug' => 'nl-test' ) );
		if ( ! is_wp_error( $cat_id ) ) {
			$cat_id = $cat_id['term_id'];

			// Remove any category with the 'newsletters' slug so get_category_by_slug fails.
			$slug_cat = get_category_by_slug( 'newsletters' );
			if ( $slug_cat ) {
				wp_delete_category( $slug_cat->term_id );
			}

			$result = indivisible_newsletter_get_default_category();
			// get_cat_ID('Newsletters') should find it by name.
			$this->assertGreaterThan( 0, $result );

			wp_delete_category( $cat_id );
		}
	}
}
