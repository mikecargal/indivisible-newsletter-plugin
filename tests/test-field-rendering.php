<?php
/**
 * Tests for field rendering callbacks in class-in-admin.php.
 *
 * Verifies that all admin field rendering functions use proper
 * output escaping (esc_attr for attributes, esc_html for text content).
 */
class Test_IN_Field_Rendering extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( IN_OPTION_KEY );
	}

	public function tearDown(): void {
		delete_option( IN_OPTION_KEY );
		parent::tearDown();
	}

	// --- Helper ---

	private function capture_field( callable $callback, array $args ): string {
		ob_start();
		$callback( $args );
		return ob_get_clean();
	}

	// --- Text field ---

	public function test_text_field_renders_type_and_name_attributes(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_text', array(
			'field' => 'imap_host',
		) );

		$this->assertStringContainsString( "type='text'", $html );
		$this->assertStringContainsString( "name='indivisible_newsletter_settings[imap_host]'", $html );
	}

	public function test_text_field_renders_number_type(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_text', array(
			'field' => 'imap_port',
			'type'  => 'number',
		) );

		$this->assertStringContainsString( "type='number'", $html );
	}

	public function test_text_field_escapes_special_characters_in_value(): void {
		update_option( IN_OPTION_KEY, array( 'imap_host' => 'host"<script>alert(1)</script>' ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_text', array(
			'field' => 'imap_host',
		) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '&quot;', $html );
	}

	public function test_text_field_escapes_placeholder(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_text', array(
			'field'       => 'imap_host',
			'placeholder' => 'host"<b>bold</b>',
		) );

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;', $html );
	}

	public function test_text_field_renders_description(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_text', array(
			'field'       => 'webmaster_email',
			'description' => 'Notify this <b>email</b>',
		) );

		$this->assertStringContainsString( '<p class="description">', $html );
		$this->assertStringContainsString( 'Notify this &lt;b&gt;email&lt;/b&gt;', $html );
	}

	// --- Textarea field ---

	public function test_textarea_escapes_content(): void {
		update_option( IN_OPTION_KEY, array( 'qualified_senders' => 'user@test.com</textarea><script>alert(1)</script>' ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_textarea', array(
			'field' => 'qualified_senders',
		) );

		$this->assertStringNotContainsString( '</textarea><script>', $html );
	}

	public function test_textarea_renders_name_attribute(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_textarea', array(
			'field' => 'qualified_senders',
		) );

		$this->assertStringContainsString( "name='indivisible_newsletter_settings[qualified_senders]'", $html );
	}

	public function test_textarea_renders_placeholder(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_textarea', array(
			'field'       => 'qualified_senders',
			'placeholder' => 'user@example.com',
		) );

		$this->assertStringContainsString( "placeholder='user@example.com'", $html );
	}

	// --- Select field ---

	public function test_select_field_escapes_option_values(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_select', array(
			'field'   => 'imap_encryption',
			'options' => array(
				'val"ue'  => 'Label One',
				'normal'  => 'Label <b>Two</b>',
			),
		) );

		$this->assertStringContainsString( "name='indivisible_newsletter_settings[imap_encryption]'", $html );
		// Option values must be escaped.
		$this->assertStringContainsString( 'val&quot;ue', $html );
		// Option labels must be escaped.
		$this->assertStringNotContainsString( '<b>Two</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;Two&lt;/b&gt;', $html );
	}

	public function test_select_field_marks_current_value_selected(): void {
		update_option( IN_OPTION_KEY, array( 'imap_encryption' => 'tls' ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_select', array(
			'field'   => 'imap_encryption',
			'options' => array( 'ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None' ),
		) );

		$this->assertStringContainsString( "value='tls'  selected='selected'", $html );
	}

	// --- Checkbox field ---

	public function test_checkbox_renders_correctly(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_checkbox', array(
			'field' => 'filter_by_sender',
			'label' => 'Enable sender filter',
		) );

		$this->assertStringContainsString( "type='checkbox'", $html );
		$this->assertStringContainsString( "name='indivisible_newsletter_settings[filter_by_sender]'", $html );
		$this->assertStringContainsString( "value='1'", $html );
		$this->assertStringContainsString( 'Enable sender filter', $html );
	}

	public function test_checkbox_renders_checked_when_enabled(): void {
		update_option( IN_OPTION_KEY, array( 'filter_by_sender' => true ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_checkbox', array(
			'field' => 'filter_by_sender',
			'label' => 'Enable sender filter',
		) );

		$this->assertStringContainsString( 'checked', $html );
	}

	public function test_checkbox_escapes_label(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_checkbox', array(
			'field' => 'filter_by_sender',
			'label' => 'Filter <script>xss</script>',
		) );

		$this->assertStringNotContainsString( '<script>xss</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;xss&lt;/script&gt;', $html );
	}

	// --- Password field ---

	public function test_password_field_never_outputs_stored_password(): void {
		update_option( IN_OPTION_KEY, array( 'email_password' => 'encrypted-secret-value' ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_password', array(
			'field' => 'email_password',
		) );

		$this->assertStringContainsString( "type='password'", $html );
		$this->assertStringContainsString( "value=''", $html );
		$this->assertStringNotContainsString( 'encrypted-secret-value', $html );
	}

	public function test_password_field_shows_placeholder_when_password_set(): void {
		update_option( IN_OPTION_KEY, array( 'email_password' => 'some-encrypted-value' ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_password', array(
			'field' => 'email_password',
		) );

		$this->assertStringContainsString( 'Password is set', $html );
	}

	public function test_password_field_shows_enter_placeholder_when_empty(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_password', array(
			'field' => 'email_password',
		) );

		$this->assertStringContainsString( 'Enter password', $html );
	}

	public function test_password_field_name_attribute(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_password', array(
			'field' => 'email_password',
		) );

		$this->assertStringContainsString( "name='indivisible_newsletter_settings[email_password]'", $html );
	}
}
