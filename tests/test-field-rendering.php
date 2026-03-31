<?php
/**
 * Tests for field rendering callbacks in class-in-admin.php.
 *
 * Verifies that all admin field rendering functions use proper
 * output escaping (esc_attr for attributes, esc_html for text content).
 */
class Test_IN_Field_Rendering extends WP_UnitTestCase {

	use AssertHtmlTrait;

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

		$this->assertHtml( $html )->find( 'input[type="text"]' )->exists();
		$this->assertHtml( $html )->find( 'input' )->hasAttribute( 'name', 'indivisible_newsletter_settings[imap_host]' );
	}

	public function test_text_field_renders_number_type(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_text', array(
			'field' => 'imap_port',
			'type'  => 'number',
		) );

		$this->assertHtml( $html )->find( 'input[type="number"]' )->exists();
	}

	public function test_text_field_escapes_special_characters_in_value(): void {
		update_option( IN_OPTION_KEY, array( 'imap_host' => 'host"<script>alert(1)</script>' ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_text', array(
			'field' => 'imap_host',
		) );

		// assertHtml-ok: verifying HTML escaping — raw <script> tag must not appear unescaped in attribute
		$this->assertStringNotContainsString( '<script>', $html );
		// assertHtml-ok: verifying HTML encoding of special chars in attribute value
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '&quot;', $html );
	}

	public function test_text_field_escapes_placeholder(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_text', array(
			'field'       => 'imap_host',
			'placeholder' => 'host"<b>bold</b>',
		) );

		// assertHtml-ok: verifying HTML escaping — raw <b> tag must not appear in placeholder attribute
		$this->assertStringNotContainsString( '<b>', $html );
		// assertHtml-ok: verifying HTML encoding of tag in placeholder attribute
		$this->assertStringContainsString( '&lt;b&gt;', $html );
	}

	public function test_text_field_renders_description(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_text', array(
			'field'       => 'webmaster_email',
			'description' => 'Notify this <b>email</b>',
		) );

		$this->assertHtml( $html )->find( 'p.description' )->exists();
		// assertHtml-ok: verifying escaped HTML encoding in text node content
		$this->assertStringContainsString( 'Notify this &lt;b&gt;email&lt;/b&gt;', $html );
	}

	// --- Textarea field ---

	public function test_textarea_escapes_content(): void {
		update_option( IN_OPTION_KEY, array( 'qualified_senders' => 'user@test.com</textarea><script>alert(1)</script>' ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_textarea', array(
			'field' => 'qualified_senders',
		) );

		// assertHtml-ok: verifying textarea injection is escaped — raw </textarea><script> must not appear
		$this->assertStringNotContainsString( '</textarea><script>', $html );
	}

	public function test_textarea_renders_name_attribute(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_textarea', array(
			'field' => 'qualified_senders',
		) );

		$this->assertHtml( $html )->find( 'textarea' )->hasAttribute( 'name', 'indivisible_newsletter_settings[qualified_senders]' );
	}

	public function test_textarea_renders_placeholder(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_textarea', array(
			'field'       => 'qualified_senders',
			'placeholder' => 'user@example.com',
		) );

		$this->assertHtml( $html )->find( 'textarea' )->hasAttribute( 'placeholder', 'user@example.com' );
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

		$this->assertHtml( $html )->find( 'select' )->hasAttribute( 'name', 'indivisible_newsletter_settings[imap_encryption]' );
		// assertHtml-ok: verifying HTML encoding of double-quote in option value attribute
		$this->assertStringContainsString( 'val&quot;ue', $html );
		// assertHtml-ok: verifying raw <b> tag is not present in label text
		$this->assertStringNotContainsString( '<b>Two</b>', $html );
		// assertHtml-ok: verifying HTML encoding of tag in option label
		$this->assertStringContainsString( '&lt;b&gt;Two&lt;/b&gt;', $html );
	}

	public function test_select_field_marks_current_value_selected(): void {
		update_option( IN_OPTION_KEY, array( 'imap_encryption' => 'tls' ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_select', array(
			'field'   => 'imap_encryption',
			'options' => array( 'ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None' ),
		) );

		$this->assertHtml( $html )->find( 'option[value="tls"][selected]' )->exists();
	}

	// --- Checkbox field ---

	public function test_checkbox_renders_correctly(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_checkbox', array(
			'field' => 'filter_by_sender',
			'label' => 'Enable sender filter',
		) );

		$this->assertHtml( $html )->find( 'input[type="checkbox"]' )->exists();
		$this->assertHtml( $html )->find( 'input[type="checkbox"]' )->hasAttribute( 'name', 'indivisible_newsletter_settings[filter_by_sender]' );
		$this->assertHtml( $html )->find( 'input[type="checkbox"]' )->hasAttribute( 'value', '1' );
		$this->assertHtml( $html )->find( 'label' )->containsText( 'Enable sender filter' );
	}

	public function test_checkbox_renders_checked_when_enabled(): void {
		update_option( IN_OPTION_KEY, array( 'filter_by_sender' => true ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_checkbox', array(
			'field' => 'filter_by_sender',
			'label' => 'Enable sender filter',
		) );

		$this->assertHtml( $html )->find( 'input[type="checkbox"]' )->hasAttribute( 'checked' );
	}

	public function test_checkbox_escapes_label(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_checkbox', array(
			'field' => 'filter_by_sender',
			'label' => 'Filter <script>xss</script>',
		) );

		// assertHtml-ok: verifying raw <script> tag does not appear unescaped in label text
		$this->assertStringNotContainsString( '<script>xss</script>', $html );
		// assertHtml-ok: verifying HTML encoding of script tag in label
		$this->assertStringContainsString( '&lt;script&gt;xss&lt;/script&gt;', $html );
	}

	// --- Password field ---

	public function test_password_field_never_outputs_stored_password(): void {
		update_option( IN_OPTION_KEY, array( 'email_password' => 'encrypted-secret-value' ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_password', array(
			'field' => 'email_password',
		) );

		$this->assertHtml( $html )->find( 'input[type="password"]' )->exists();
		$this->assertHtml( $html )->find( 'input[type="password"]' )->hasAttribute( 'value', '' );
		// assertHtml-ok: security verification — stored encrypted value must not appear in rendered output
		$this->assertStringNotContainsString( 'encrypted-secret-value', $html );
	}

	public function test_password_field_shows_placeholder_when_password_set(): void {
		update_option( IN_OPTION_KEY, array( 'email_password' => 'some-encrypted-value' ) );

		$html = $this->capture_field( 'indivisible_newsletter_field_password', array(
			'field' => 'email_password',
		) );

		// assertHtml-ok: placeholder text is a substring of the full placeholder string; hasAttribute requires exact match
		$this->assertStringContainsString( 'Password is set', $html );
	}

	public function test_password_field_shows_enter_placeholder_when_empty(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_password', array(
			'field' => 'email_password',
		) );

		// assertHtml-ok: placeholder text; hasAttribute requires exact match
		$this->assertStringContainsString( 'Enter password', $html );
	}

	public function test_password_field_name_attribute(): void {
		$html = $this->capture_field( 'indivisible_newsletter_field_password', array(
			'field' => 'email_password',
		) );

		$this->assertHtml( $html )->find( 'input[type="password"]' )->hasAttribute( 'name', 'indivisible_newsletter_settings[email_password]' );
	}
}
