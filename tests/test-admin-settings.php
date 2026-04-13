<?php
/**
 * Tests for the admin settings page (class-in-admin.php).
 */
class Test_IN_Admin_Settings extends WP_UnitTestCase {

  use AssertHtmlTrait;

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
    $this->assertHtml( $links[0] )->find( 'a' )->containsText( 'Settings' );
    $this->assertHtml( $links[0] )->find( 'a[href*="indivisible-newsletter"]' )->exists();
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

    $this->assertHtml( $output )->find( 'h1' )->containsText( 'Newsletter Poster Settings' );
  }

  public function test_render_wraps_in_div_wrap(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertHtml( $output )->find( 'div.wrap' )->exists();
  }

  public function test_form_action_is_options_php(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertHtml( $output )->find( 'form[action="options.php"]' )->exists();
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

    $this->assertHtml( $output )->find( 'input[type="text"]' )->exists();
    $this->assertHtml( $output )->find( 'input' )->hasAttribute( 'name', IN_OPTION_KEY . '[imap_host]' );
    $this->assertHtml( $output )->find( 'input' )->hasAttribute( 'placeholder', 'imap.example.com' );
  }

  public function test_text_field_renders_description(): void {
    wp_set_current_user( $this->admin_id );

    ob_start();
    indivisible_newsletter_field_text( array( 'field' => 'webmaster_email', 'description' => 'Notify this address' ) );
    $output = ob_get_clean();

    $this->assertHtml( $output )->find( 'p.description' )->containsText( 'Notify this address' );
  }

  public function test_number_field_renders_number_type(): void {
    wp_set_current_user( $this->admin_id );

    ob_start();
    indivisible_newsletter_field_text( array( 'field' => 'imap_port', 'type' => 'number' ) );
    $output = ob_get_clean();

    $this->assertHtml( $output )->find( 'input[type="number"]' )->exists();
  }

  public function test_password_field_no_password_set(): void {
    wp_set_current_user( $this->admin_id );
    delete_option( IN_OPTION_KEY );

    ob_start();
    indivisible_newsletter_field_password( array( 'field' => 'email_password' ) );
    $output = ob_get_clean();

    $this->assertHtml( $output )->find( 'input[type="password"]' )->exists();
    // assertHtml-ok: placeholder text is a substring; hasAttribute requires exact match
    $this->assertStringContainsString( 'Enter password', $output );
  }

  public function test_password_field_password_already_set(): void {
    wp_set_current_user( $this->admin_id );
    update_option( IN_OPTION_KEY, array( 'email_password' => 'encrypted-value' ) );

    ob_start();
    indivisible_newsletter_field_password( array( 'field' => 'email_password' ) );
    $output = ob_get_clean();

    // assertHtml-ok: placeholder text is a substring; hasAttribute requires exact match
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

    $this->assertHtml( $output )->find( 'select' )->exists();
    $this->assertHtml( $output )->find( 'select' )->hasOptionWithValue( 'ssl' );
    $this->assertHtml( $output )->find( 'select' )->hasOptionWithValue( 'tls' );
    $this->assertHtml( $output )->find( 'select' )->hasOptionWithValue( 'none' );
  }

  public function test_checkbox_field_renders(): void {
    wp_set_current_user( $this->admin_id );

    ob_start();
    indivisible_newsletter_field_checkbox( array( 'field' => 'filter_by_sender', 'label' => 'Only filter' ) );
    $output = ob_get_clean();

    $this->assertHtml( $output )->find( 'input[type="checkbox"]' )->exists();
    $this->assertHtml( $output )->find( 'label' )->containsText( 'Only filter' );
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

    $this->assertHtml( $output )->find( 'textarea' )->exists();
    $this->assertHtml( $output )->find( 'textarea' )->hasAttribute( 'placeholder', 'sender@example.com' );
    $this->assertHtml( $output )->find( 'p.description' )->containsText( 'One per line' );
  }

  public function test_category_field_renders_dropdown(): void {
    wp_set_current_user( $this->admin_id );

    ob_start();
    indivisible_newsletter_field_category( array( 'field' => 'post_category' ) );
    $output = ob_get_clean();

    $this->assertHtml( $output )->find( 'select' )->exists();
    $this->assertHtml( $output )->find( 'select' )->hasAttribute( 'name', IN_OPTION_KEY . '[post_category]' );
  }

  // --- Action buttons ---

  public function test_test_connection_button_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertHtml( $output )->find( 'button[name="in_test_connection"]' )->containsText( 'Test Connection' );
  }

  public function test_check_now_button_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertHtml( $output )->find( 'button[name="in_check_now"]' )->containsText( 'Check Now' );
  }

  public function test_diagnose_button_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    $this->assertHtml( $output )->find( 'button[name="in_diagnose"]' )->containsText( 'Diagnose' );
  }

  // --- Nonce fields ---

  public function test_test_connection_nonce_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    // assertHtml-ok: dynamic nonce value, not HTML structure
    $nonce = wp_create_nonce( 'in_test_connection_action' );
    $this->assertStringContainsString( $nonce, $output );
  }

  public function test_check_now_nonce_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    // assertHtml-ok: dynamic nonce value, not HTML structure
    $nonce = wp_create_nonce( 'in_check_now_action' );
    $this->assertStringContainsString( $nonce, $output );
  }

  public function test_diagnose_nonce_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    // assertHtml-ok: dynamic nonce value, not HTML structure
    $nonce = wp_create_nonce( 'in_diagnose_action' );
    $this->assertStringContainsString( $nonce, $output );
  }

  // --- Scheduled check display ---

  public function test_no_scheduled_check_shows_message(): void {
    wp_set_current_user( $this->admin_id );
    wp_clear_scheduled_hook( IN_CRON_HOOK );

    $output = $this->render_page();

    // assertHtml-ok: page has multiple <p class="description"> elements; API finds first match only
    $this->assertStringContainsString( 'No check is currently scheduled', $output );
  }

  public function test_scheduled_check_shows_next_time(): void {
    wp_set_current_user( $this->admin_id );
    wp_schedule_single_event( time() + 3600, IN_CRON_HOOK );

    $output = $this->render_page();

    // assertHtml-ok: page has multiple <p class="description"> elements; API finds first match only
    $this->assertStringContainsString( 'Next scheduled check', $output );
  }

  // --- Reliable Scheduling section ---

  public function test_reliable_scheduling_section_present(): void {
    wp_set_current_user( $this->admin_id );
    $output = $this->render_page();

    // assertHtml-ok: page has multiple <h2> tags; API finds first match only, can't select by text
    $this->assertStringContainsString( 'Reliable Scheduling', $output );
    // assertHtml-ok: page has multiple <code> elements; API finds first match only
    $this->assertStringContainsString( 'wp-cron.php', $output );
    $this->assertStringContainsString( 'DISABLE_WP_CRON', $output );
  }

  // --- POST action handler behavior ---

  public function test_check_now_handler_renders_error_when_settings_missing(): void {
    wp_set_current_user( $this->admin_id );
    delete_option( IN_OPTION_KEY );

    $_POST['in_check_now'] = '1';
    $_REQUEST['_wpnonce']  = wp_create_nonce( 'in_check_now_action' );

    $output = $this->render_page();

    unset( $_POST['in_check_now'], $_REQUEST['_wpnonce'] );

    $this->assertHtml( $output )->find( 'div.notice-error' )->exists();
  }

  public function test_check_now_handler_dies_without_valid_nonce(): void {
    wp_set_current_user( $this->admin_id );
    delete_option( IN_OPTION_KEY );

    $_POST['in_check_now'] = '1';
    // No nonce set — check_admin_referer calls wp_die().

    $this->expectException( 'WPDieException' );

    try {
      ob_start();
      indivisible_newsletter_render_settings_page();
    } finally {
      ob_end_clean();
      unset( $_POST['in_check_now'] );
    }
  }

  public function test_test_connection_handler_renders_error_when_settings_missing(): void {
    wp_set_current_user( $this->admin_id );
    delete_option( IN_OPTION_KEY );

    $_POST['in_test_connection'] = '1';
    $_REQUEST['_wpnonce']        = wp_create_nonce( 'in_test_connection_action' );

    $output = $this->render_page();

    unset( $_POST['in_test_connection'], $_REQUEST['_wpnonce'] );

    $this->assertHtml( $output )->find( 'div.notice-error' )->exists();
  }

  public function test_diagnose_handler_renders_report_when_settings_missing(): void {
    wp_set_current_user( $this->admin_id );
    delete_option( IN_OPTION_KEY );

    $_POST['in_diagnose']  = '1';
    $_REQUEST['_wpnonce']  = wp_create_nonce( 'in_diagnose_action' );

    $output = $this->render_page();

    unset( $_POST['in_diagnose'], $_REQUEST['_wpnonce'] );

    // Diagnose should render a report (either error or diagnostic output in <pre>).
    // assertHtml-ok: checking presence of pre tag for diagnostic report
    $this->assertStringContainsString( '<pre', $output );
  }

  // --- Saved values reflected ---

  public function test_saved_host_reflected_in_text_field(): void {
    wp_set_current_user( $this->admin_id );
    update_option( IN_OPTION_KEY, array( 'imap_host' => 'mail.custom.org' ) );

    ob_start();
    indivisible_newsletter_field_text( array( 'field' => 'imap_host' ) );
    $output = ob_get_clean();

    $this->assertHtml( $output )->find( 'input' )->hasAttribute( 'value', 'mail.custom.org' );
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

    $this->assertHtml( $output )->find( 'option[value="tls"][selected]' )->exists();
  }

  // --- Settings group registered ---

  public function test_settings_group_registered(): void {
    wp_set_current_user( $this->admin_id );
    indivisible_newsletter_register_settings();

    $output = $this->render_page();

    $this->assertHtml( $output )->find( 'input[name="option_page"][value="indivisible_newsletter_group"]' )->exists();
  }

  // --- Recent Newsletters table ---

  public function test_recent_newsletters_table_shows_posts_with_raw_body_meta(): void {
    $with_meta = $this->factory->post->create( array(
      'post_title' => 'Meta Newsletter',
      'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
    ) );
    update_post_meta( $with_meta, '_in_newsletter_raw_body', '<p>Body</p>' );

    $without_meta = $this->factory->post->create( array(
      'post_title' => 'Plain Post',
      'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
    ) );

    $html = indivisible_newsletter_render_recent_newsletters_section();

    $this->assertHtml( $html )
      ->find( 'table.in-recent-newsletters tbody tr' )
      ->count( 1 );
    $this->assertHtml( $html )
      ->find( 'table.in-recent-newsletters' )
      ->containsText( 'Meta Newsletter' );
    $this->assertHtml( $html )
      ->find( 'table.in-recent-newsletters' )
      ->doesNotContainText( 'Plain Post' );
  }

  public function test_recent_newsletters_table_limits_to_90_days(): void {
    $recent = $this->factory->post->create( array(
      'post_title' => 'Recent Newsletter',
      'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-10 days' ) ),
    ) );
    update_post_meta( $recent, '_in_newsletter_raw_body', '<p>Body</p>' );

    $old = $this->factory->post->create( array(
      'post_title' => 'Old Newsletter',
      'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) ),
    ) );
    update_post_meta( $old, '_in_newsletter_raw_body', '<p>Body</p>' );

    $html = indivisible_newsletter_render_recent_newsletters_section();

    $this->assertHtml( $html )
      ->find( 'table.in-recent-newsletters' )
      ->containsText( 'Recent Newsletter' );
    $this->assertHtml( $html )
      ->find( 'table.in-recent-newsletters' )
      ->doesNotContainText( 'Old Newsletter' );
  }

  public function test_recent_newsletters_table_includes_reprocess_button_with_nonce(): void {
    $post_id = $this->factory->post->create( array(
      'post_title' => 'Test',
      'post_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
    ) );
    update_post_meta( $post_id, '_in_newsletter_raw_body', '<p>Body</p>' );

    $html = indivisible_newsletter_render_recent_newsletters_section();

    $this->assertHtml( $html )
      ->find( 'table.in-recent-newsletters button[name="in_reprocess"]' )
      ->exists();
    $this->assertHtml( $html )
      ->find( 'table.in-recent-newsletters input[name="_wpnonce"]' )
      ->exists();
    $this->assertHtml( $html )
      ->find( 'table.in-recent-newsletters input[name="in_reprocess_post_id"]' )
      ->hasAttribute( 'value', (string) $post_id );
  }
}
