<?php
/**
 * Tests for the admin settings page (class-in-admin.php).
 */
class Test_IN_Admin_Settings extends WP_UnitTestCase {

  private int $admin_id;

  public function setUp(): void {
    parent::setUp();
    $this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
  }

  public function tearDown(): void {
    delete_option( IN_OPTION_KEY );
    wp_clear_scheduled_hook( IN_CRON_HOOK );
    parent::tearDown();
  }

  // --- Helper ---

  private function render_page(): string {
    ob_start();
    indivisible_newsletter_render_settings_page();
    return ob_get_clean();
  }

  // --- Page registration ---

  public function test_settings_page_is_registered(): void {
    wp_set_current_user( $this->admin_id );
    do_action( 'admin_menu' );

    $page_hook = get_plugin_page_hookname( 'indivisible-newsletter', 'options-general.php' );
    $this->assertNotEmpty( $page_hook );
  }

  public function test_plugin_action_links_adds_settings_link(): void {
    $links = indivisible_newsletter_plugin_action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );

    $this->assertArrayHasKey( 0, $links );
    $this->assertStringContainsString( 'Settings', $links[0] );
    $this->assertStringContainsString( 'indivisible-newsletter', $links[0] );
  }

  // --- Capability gating ---

  public function test_non_admin_renders_nothing(): void {
    $subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
    wp_set_current_user( $subscriber_id );

    $output = $this->render_page();

    $this->assertEmpty( $output );
  }

  // --- Page title and structure ---

  public function test_render_contains_page_title(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertStringContainsString( '<h1>Newsletter Poster Settings</h1>', $output );
  }

  public function test_render_wraps_in_div_wrap(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertStringContainsString( '<div class="wrap">', $output );
  }

  public function test_form_action_is_options_php(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertStringContainsString( 'action="options.php"', $output );
  }

  // --- Settings API sections ---

  public function test_settings_sections_registered(): void {
    wp_set_current_user( $this->admin_id );
    // Trigger admin_init to register settings.
    indivisible_newsletter_register_settings();

    global $wp_settings_sections;
    $this->assertArrayHasKey( 'indivisible-newsletter', $wp_settings_sections );
    $sections = $wp_settings_sections['indivisible-newsletter'];
    $this->assertArrayHasKey( 'in_imap_section', $sections );
    $this->assertArrayHasKey( 'in_processing_section', $sections );
    $this->assertArrayHasKey( 'in_post_section', $sections );
  }

  public function test_settings_fields_registered(): void {
    wp_set_current_user( $this->admin_id );
    indivisible_newsletter_register_settings();

    global $wp_settings_fields;
    $fields = $wp_settings_fields['indivisible-newsletter'];

    // IMAP section fields.
    $this->assertArrayHasKey( 'imap_host', $fields['in_imap_section'] );
    $this->assertArrayHasKey( 'imap_port', $fields['in_imap_section'] );
    $this->assertArrayHasKey( 'imap_encryption', $fields['in_imap_section'] );
    $this->assertArrayHasKey( 'email_username', $fields['in_imap_section'] );
    $this->assertArrayHasKey( 'email_password', $fields['in_imap_section'] );
    $this->assertArrayHasKey( 'imap_folder', $fields['in_imap_section'] );

    // Processing section fields.
    $this->assertArrayHasKey( 'filter_by_sender', $fields['in_processing_section'] );
    $this->assertArrayHasKey( 'qualified_senders', $fields['in_processing_section'] );
    $this->assertArrayHasKey( 'check_interval', $fields['in_processing_section'] );

    // Post section fields.
    $this->assertArrayHasKey( 'post_status', $fields['in_post_section'] );
    $this->assertArrayHasKey( 'post_category', $fields['in_post_section'] );
    $this->assertArrayHasKey( 'webmaster_email', $fields['in_post_section'] );
  }

  // --- Field rendering ---

  public function test_text_field_renders_input(): void {
    wp_set_current_user( $this->admin_id );

    ob_start();
    indivisible_newsletter_field_text( array( 'field' => 'imap_host', 'placeholder' => 'imap.example.com' ) );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'type=\'text\'', $output );
    $this->assertStringContainsString( IN_OPTION_KEY . '[imap_host]', $output );
    $this->assertStringContainsString( 'imap.example.com', $output );
  }

  public function test_text_field_renders_description(): void {
    wp_set_current_user( $this->admin_id );

    ob_start();
    indivisible_newsletter_field_text( array( 'field' => 'webmaster_email', 'description' => 'Notify this address' ) );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'Notify this address', $output );
    $this->assertStringContainsString( 'class="description"', $output );
  }

  public function test_number_field_renders_number_type(): void {
    wp_set_current_user( $this->admin_id );

    ob_start();
    indivisible_newsletter_field_text( array( 'field' => 'imap_port', 'type' => 'number' ) );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'type=\'number\'', $output );
  }

  public function test_password_field_no_password_set(): void {
    wp_set_current_user( $this->admin_id );
    delete_option( IN_OPTION_KEY );

    ob_start();
    indivisible_newsletter_field_password( array( 'field' => 'email_password' ) );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'type=\'password\'', $output );
    $this->assertStringContainsString( 'Enter password', $output );
  }

  public function test_password_field_password_already_set(): void {
    wp_set_current_user( $this->admin_id );
    update_option( IN_OPTION_KEY, array( 'email_password' => 'encrypted-value' ) );

    ob_start();
    indivisible_newsletter_field_password( array( 'field' => 'email_password' ) );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'Password is set', $output );
  }

  public function test_select_field_renders_options(): void {
    wp_set_current_user( $this->admin_id );

    ob_start();
    indivisible_newsletter_field_select( array(
      'field'   => 'imap_encryption',
      'options' => array( 'ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None' ),
    ) );
    $output = ob_get_clean();

    $this->assertStringContainsString( '<select', $output );
    $this->assertStringContainsString( 'SSL', $output );
    $this->assertStringContainsString( 'TLS', $output );
    $this->assertStringContainsString( 'None', $output );
  }

  public function test_checkbox_field_renders(): void {
    wp_set_current_user( $this->admin_id );

    ob_start();
    indivisible_newsletter_field_checkbox( array( 'field' => 'filter_by_sender', 'label' => 'Only filter' ) );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'type=\'checkbox\'', $output );
    $this->assertStringContainsString( 'Only filter', $output );
  }

  public function test_textarea_field_renders(): void {
    wp_set_current_user( $this->admin_id );

    ob_start();
    indivisible_newsletter_field_textarea( array(
      'field'       => 'qualified_senders',
      'placeholder' => 'sender@example.com',
      'description' => 'One per line',
    ) );
    $output = ob_get_clean();

    $this->assertStringContainsString( '<textarea', $output );
    $this->assertStringContainsString( 'sender@example.com', $output );
    $this->assertStringContainsString( 'One per line', $output );
  }

  public function test_category_field_renders_dropdown(): void {
    wp_set_current_user( $this->admin_id );

    ob_start();
    indivisible_newsletter_field_category( array( 'field' => 'post_category' ) );
    $output = ob_get_clean();

    $this->assertStringContainsString( '<select', $output );
    $this->assertStringContainsString( IN_OPTION_KEY . '[post_category]', $output );
  }

  // --- Action buttons ---

  public function test_test_connection_button_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertStringContainsString( 'in_test_connection', $output );
    $this->assertStringContainsString( 'Test Connection', $output );
  }

  public function test_check_now_button_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertStringContainsString( 'in_check_now', $output );
    $this->assertStringContainsString( 'Check Now', $output );
  }

  public function test_diagnose_button_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertStringContainsString( 'in_diagnose', $output );
    $this->assertStringContainsString( 'Diagnose', $output );
  }

  // --- Nonce fields ---

  public function test_test_connection_nonce_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $nonce = wp_create_nonce( 'in_test_connection_action' );
    $this->assertStringContainsString( $nonce, $output );
  }

  public function test_check_now_nonce_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $nonce = wp_create_nonce( 'in_check_now_action' );
    $this->assertStringContainsString( $nonce, $output );
  }

  public function test_diagnose_nonce_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $nonce = wp_create_nonce( 'in_diagnose_action' );
    $this->assertStringContainsString( $nonce, $output );
  }

  // --- Scheduled check display ---

  public function test_no_scheduled_check_shows_message(): void {
    wp_set_current_user( $this->admin_id );
    wp_clear_scheduled_hook( IN_CRON_HOOK );

    $output = $this->render_page();

    $this->assertStringContainsString( 'No check is currently scheduled', $output );
  }

  public function test_scheduled_check_shows_next_time(): void {
    wp_set_current_user( $this->admin_id );
    wp_schedule_single_event( time() + 3600, IN_CRON_HOOK );

    $output = $this->render_page();

    $this->assertStringContainsString( 'Next scheduled check', $output );
  }

  // --- Reliable Scheduling section ---

  public function test_reliable_scheduling_section_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertStringContainsString( 'Reliable Scheduling', $output );
    $this->assertStringContainsString( 'wp-cron.php', $output );
    $this->assertStringContainsString( 'DISABLE_WP_CRON', $output );
  }

  // --- Saved values reflected ---

  public function test_saved_host_reflected_in_text_field(): void {
    wp_set_current_user( $this->admin_id );
    update_option( IN_OPTION_KEY, array( 'imap_host' => 'mail.custom.org' ) );

    ob_start();
    indivisible_newsletter_field_text( array( 'field' => 'imap_host' ) );
    $output = ob_get_clean();

    $this->assertStringContainsString( 'mail.custom.org', $output );
  }

  public function test_saved_encryption_selected_in_dropdown(): void {
    wp_set_current_user( $this->admin_id );
    update_option( IN_OPTION_KEY, array( 'imap_encryption' => 'tls' ) );

    ob_start();
    indivisible_newsletter_field_select( array(
      'field'   => 'imap_encryption',
      'options' => array( 'ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None' ),
    ) );
    $output = ob_get_clean();

    $this->assertMatchesRegularExpression(
      '/value=\'tls\'[^>]*selected/',
      $output
    );
  }

  // --- Settings group registered ---

  public function test_settings_group_registered(): void {
    wp_set_current_user( $this->admin_id );
    indivisible_newsletter_register_settings();

    $output = $this->render_page();

    $this->assertStringContainsString( 'indivisible_newsletter_group', $output );
  }
}
