<?php
/**
 * Extended tests for Indivisible Newsletter processor functions.
 *
 * Covers forwarded content extraction edge cases, HTML cleaning,
 * post creation details, and webmaster notifications.
 */
class Test_IN_Processor_Extended extends IN_Test_Case {

  private const EMAIL_DATE       = '2026-02-17';
  private const WEBMASTER_EMAIL  = 'admin@example.com';

  private $sent_emails = array();

  /** @var resource|null Server side of injected socket pair. */
  private $injected_server = null;

  public function setUp(): void {
    parent::setUp();
    delete_option( IN_OPTION_KEY );
    $this->sent_emails = array();
    $GLOBALS['in_imap_tag_counter']   = 0;
    $GLOBALS['in_imap_fetch_counter'] = 1000;
    $this->injected_server            = null;
    // Intercept wp_mail.
    add_filter( 'pre_wp_mail', array( $this, 'capture_email' ), 10, 2 );
  }

  public function tearDown(): void {
    remove_filter( 'pre_wp_mail', array( $this, 'capture_email' ) );
    remove_all_filters( 'indivisible_newsletter_imap_socket_client' );
    if ( is_resource( $this->injected_server ) ) {
      fclose( $this->injected_server );
      $this->injected_server = null;
    }
    delete_option( IN_OPTION_KEY );
    delete_option( IN_PROCESSED_KEY );
    parent::tearDown();
  }

  public function capture_email( $null, $atts ) {
    $this->sent_emails[] = $atts;
    return true; // Prevent actual sending.
  }

  // --- clean_subject ---

  public function test_clean_subject_multiple_nested_prefixes() {
    $this->assertEquals(
      'Newsletter',
      indivisible_newsletter_clean_subject( 'Fwd: Fwd: Re: Newsletter' )
    );
  }

  public function test_clean_subject_preserves_fwd_in_middle() {
    $this->assertEquals(
      'My Fwd: Newsletter',
      indivisible_newsletter_clean_subject( 'My Fwd: Newsletter' )
    );
  }

  public function test_clean_subject_empty_string() {
    $this->assertEquals( '', indivisible_newsletter_clean_subject( '' ) );
  }

  public function test_clean_subject_only_prefix() {
    $this->assertEquals( '', indivisible_newsletter_clean_subject( 'Fwd: Re:' ) );
  }

  public function test_clean_subject_trims_whitespace() {
    $this->assertEquals(
      'Newsletter',
      indivisible_newsletter_clean_subject( '  Fwd:   Newsletter  ' )
    );
  }

  // --- extract_forwarded_content ---

  public function test_extract_forwarded_content_strips_body_wrapper() {
    $html = '<html><head><title>Test</title></head><body><p>Content</p></body></html>';
    $result = indivisible_newsletter_extract_forwarded_content( $html );
    $this->assertEquals( '<p>Content</p>', $result );
  }

  public function test_extract_forwarded_content_no_body_tag() {
    $html = '<p>Just a paragraph</p>';
    $result = indivisible_newsletter_extract_forwarded_content( $html );
    $this->assertEquals( '<p>Just a paragraph</p>', $result );
  }

  public function test_extract_forwarded_content_apple_mail_format() {
    $html = '<div>Begin forwarded message:</div>' .
      '<blockquote type="cite">' .
      '<div><span><b>From: </b></span>sender@example.com</div>' .
      '<div><span><b>Subject: </b></span>Test Subject</div>' .
      '<div><span><b>Date: </b></span>Feb 17, 2026</div>' .
      '<div><span><b>To: </b></span>recipient@example.com</div>' .
      '<p>Actual newsletter content here</p>' .
      '</blockquote>';

    $result = indivisible_newsletter_extract_forwarded_content( $html );

    $this->assertStringContainsString( 'Actual newsletter content here', $result );
    $this->assertStringNotContainsString( 'Begin forwarded message', $result );
    $this->assertStringNotContainsString( 'sender@example.com', $result );
    $this->assertStringNotContainsString( 'recipient@example.com', $result );
  }

  public function test_extract_forwarded_content_apple_mail_with_reply_to() {
    $html = '<blockquote type="cite">' .
      '<div><span><b>From: </b></span>test@example.com</div>' .
      '<div><span><b>Reply-To: </b></span>reply@example.com</div>' .
      '<p>Content</p>' .
      '</blockquote>';

    $result = indivisible_newsletter_extract_forwarded_content( $html );
    $this->assertStringContainsString( 'Content', $result );
    $this->assertStringNotContainsString( 'Reply-To', $result );
  }

  public function test_extract_forwarded_content_no_blockquote_cite() {
    // Regular blockquote without type="cite" should NOT be treated as forwarded.
    $html = '<blockquote><p>Quoted text</p></blockquote>';
    $result = indivisible_newsletter_extract_forwarded_content( $html );
    $this->assertStringContainsString( 'Quoted text', $result );
  }

  public function test_extract_forwarded_content_body_with_attributes() {
    $html = '<html><body style="margin:0; padding:0;"><p>Styled body</p></body></html>';
    $result = indivisible_newsletter_extract_forwarded_content( $html );
    $this->assertEquals( '<p>Styled body</p>', $result );
  }

  // --- clean_html ---

  public function test_clean_html_removes_unsubscribe_link() {
    $html = '<p>Content</p><a href="http://example.com/unsub">Unsubscribe</a><p>More</p>';
    $result = indivisible_newsletter_clean_html( $html );

    $this->assertStringNotContainsString( 'Unsubscribe', $result );
    $this->assertStringContainsString( 'Content', $result );
    $this->assertStringContainsString( 'More', $result );
  }

  public function test_clean_html_removes_unsubscribe_case_insensitive() {
    $html = '<a href="http://example.com">Click to UNSUBSCRIBE</a>';
    $result = indivisible_newsletter_clean_html( $html );
    $this->assertStringNotContainsString( 'UNSUBSCRIBE', $result );
  }

  public function test_clean_html_removes_unsubscribe_with_surrounding_text() {
    $html = '<a href="http://example.com">Click here to unsubscribe from this list</a>';
    $result = indivisible_newsletter_clean_html( $html );
    $this->assertStringNotContainsString( 'unsubscribe', $result );
  }

  public function test_clean_html_removes_unsubscribe_sentence_keeps_prior_content() {
    $html = '<p style="margin: 0">'
      . 'paid for by Indivisible<br />Columbus GA Indivisibles<br />Columbus<br />'
      . 'Columbus, GA 31904<br />United States<br />'
      . 'If you believe you received this message in error or wish to no longer '
      . 'receive email from us, please '
      . '<a href="https://example.com/unsub?data=abc123">unsubscribe</a>.'
      . '</p>';
    $result = indivisible_newsletter_clean_html( $html );

    // Prior content preserved.
    $this->assertStringContainsString( 'paid for by Indivisible', $result );
    $this->assertStringContainsString( 'United States', $result );
    // Unsubscribe sentence fully removed.
    $this->assertStringNotContainsString( 'unsubscribe', $result );
    $this->assertStringNotContainsString( 'If you believe', $result );
    $this->assertStringNotContainsString( 'no longer receive email', $result );
  }

  public function test_clean_html_removes_unsubscribe_sentence_with_multiline_tag() {
    // Real-world emails often have attributes split across lines.
    $html = "<p>Footer text<br />Please "
      . "<a\n  href=\"https://example.com/unsub\"\n  >unsubscribe</a\n>.</p>";
    $result = indivisible_newsletter_clean_html( $html );

    $this->assertStringContainsString( 'Footer text', $result );
    $this->assertStringNotContainsString( 'unsubscribe', $result );
    $this->assertStringNotContainsString( 'Please', $result );
  }

  public function test_clean_html_preserves_non_unsubscribe_links() {
    $html = '<a href="http://example.com">Read More</a>';
    $result = indivisible_newsletter_clean_html( $html );
    $this->assertStringContainsString( 'Read More', $result );
  }

  public function test_clean_html_does_not_rewrite_nl_container_background_color(): void {
    $html = '<table class="nl-container" style="background-color: #f0f0f0; padding: 10px;">Content</table>';

    $result = indivisible_newsletter_clean_html( $html );

    $this->assertHtml( $result )
      ->find( 'table.nl-container[style*="background-color: #f0f0f0"]' )
      ->exists();

    $this->assertHtml( $result )
      ->find( 'table.nl-container[style*="var(--wp--preset--color--background)"]' )
      ->doesNotExist();
  }

  public function test_clean_html_does_not_force_black_text_color_on_nl_container(): void {
    $html = '<table class="nl-container" style="background-color: #ffffff;">Content</table>';

    $result = indivisible_newsletter_clean_html( $html );

    $this->assertHtml( $result )
      ->find( 'table.nl-container[style*="color: #000000"]' )
      ->doesNotExist();
  }

  public function test_clean_html_preserves_other_content() {
    $html = '<table><tr><td>Regular table</td></tr></table>';
    $result = indivisible_newsletter_clean_html( $html );
    $this->assertStringContainsString( 'Regular table', $result );
  }

  // --- responsive table fixes ---

  public function test_clean_html_makes_row_content_tables_responsive() {
    $html = '<table class="row-content stack" align="center" border="0" '
      . 'cellpadding="0" cellspacing="0" role="presentation" '
      . 'style="background-color:#fff;color:#000;width:600px;margin:0 auto" '
      . 'width="600"><tbody><tr><td>Content</td></tr></tbody></table>';
    $result = indivisible_newsletter_clean_html( $html );

    // Fixed width replaced with responsive width.
    $this->assertStringContainsString( 'width:100%;max-width:600px', $result );
    $this->assertDoesNotMatchRegularExpression( '/[^-]width:600px/', $result );
    // HTML width attribute removed.
    $this->assertStringNotContainsString( 'width="600"', $result );
    // Content preserved.
    $this->assertStringContainsString( 'Content', $result );
  }

  public function test_clean_html_handles_multiple_row_content_tables() {
    $html = '<table class="row-content stack" style="width:600px" width="600">'
      . '<tr><td>Row 1</td></tr></table>'
      . '<table class="row-content stack" style="width:600px" width="600">'
      . '<tr><td>Row 2</td></tr></table>';
    $result = indivisible_newsletter_clean_html( $html );

    $this->assertDoesNotMatchRegularExpression( '/[^-]width:600px/', $result );
    $this->assertStringNotContainsString( 'width="600"', $result );
    $this->assertStringContainsString( 'Row 1', $result );
    $this->assertStringContainsString( 'Row 2', $result );
    $this->assertEquals( 2, substr_count( $result, 'width:100%;max-width:600px' ) );
  }

  public function test_clean_html_preserves_tables_without_fixed_width() {
    $html = '<table class="social-table" width="176px" border="0">'
      . '<tr><td>Social</td></tr></table>';
    $result = indivisible_newsletter_clean_html( $html );

    // Non-row-content table untouched.
    $this->assertStringContainsString( 'width="176px"', $result );
    $this->assertStringContainsString( 'Social', $result );
  }

  public function test_clean_html_responsive_fix_with_border_radius() {
    // Some row-content tables also have border-radius in their styles.
    $html = '<table class="row-content stack" style="background-color:#fff;'
      . 'color:#000;border-radius:0;width:600px;margin:0 auto" width="600">'
      . '<tr><td>Styled</td></tr></table>';
    $result = indivisible_newsletter_clean_html( $html );

    $this->assertStringContainsString( 'width:100%;max-width:600px', $result );
    $this->assertDoesNotMatchRegularExpression( '/[^-]width:600px/', $result );
    $this->assertStringContainsString( 'border-radius:0', $result );
  }

  public function test_clean_html_removes_width_attr_from_responsive_images() {
    $html = '<img src="https://example.com/image.png" '
      . 'style="display:block;height:auto;border:0;width:100%" '
      . 'width="600" alt="" title="" height="auto">';
    $result = indivisible_newsletter_clean_html( $html );

    $this->assertStringNotContainsString( 'width="600"', $result );
    $this->assertStringContainsString( 'width:100%', $result );
    $this->assertStringContainsString( 'height="auto"', $result );
  }

  public function test_clean_html_preserves_width_attr_on_non_responsive_images() {
    $html = '<img src="https://example.com/icon.png" width="32" height="auto">';
    $result = indivisible_newsletter_clean_html( $html );

    $this->assertStringContainsString( 'width="32"', $result );
  }

  // --- create_post_from_email ---

  public function test_create_post_from_email_basic() {
    update_option( IN_OPTION_KEY, array(
      'post_status'     => 'draft',
      'post_category'   => 0,
      'webmaster_email' => '',
    ) );

    $email = array(
      'subject'    => 'Fwd: Test Newsletter',
      'html'       => '<html><body><p>Newsletter body</p></body></html>',
      'date'       => self::EMAIL_DATE,
      'message_id' => 'test-basic-123',
    );

    $post_id = indivisible_newsletter_create_post_from_email( $email );

    $this->assertIsInt( $post_id );
    $this->assertGreaterThan( 0, $post_id );

    $post = get_post( $post_id );
    $this->assertEquals( 'Test Newsletter', $post->post_title );
    $this->assertEquals( 'draft', $post->post_status );
  }

  public function test_create_post_wraps_in_gutenberg_html_block() {
    update_option( IN_OPTION_KEY, array(
      'post_status'     => 'draft',
      'post_category'   => 0,
      'webmaster_email' => '',
    ) );

    $email = array(
      'subject'    => 'Newsletter',
      'html'       => '<p>Content</p>',
      'date'       => self::EMAIL_DATE,
      'message_id' => 'test-gutenberg',
    );

    $post_id = indivisible_newsletter_create_post_from_email( $email );
    $post    = get_post( $post_id );

    $this->assertStringContainsString( '<!-- wp:html -->', $post->post_content );
    $this->assertStringContainsString( '<!-- /wp:html -->', $post->post_content );
    $this->assertStringContainsString( 'Content', $post->post_content );
  }

  public function test_create_post_sets_login_required_meta() {
    update_option( IN_OPTION_KEY, array(
      'post_status'     => 'draft',
      'post_category'   => 0,
      'webmaster_email' => '',
    ) );

    $email = array(
      'subject'    => 'Newsletter',
      'html'       => '<p>Protected</p>',
      'date'       => self::EMAIL_DATE,
      'message_id' => 'test-meta',
    );

    $post_id = indivisible_newsletter_create_post_from_email( $email );
    $this->assertEquals( '1', get_post_meta( $post_id, '_login_required', true ) );
  }

  public function test_create_post_uses_publish_status() {
    update_option( IN_OPTION_KEY, array(
      'post_status'     => 'publish',
      'post_category'   => 0,
      'webmaster_email' => '',
    ) );

    $email = array(
      'subject'    => 'Published Newsletter',
      'html'       => '<p>Content</p>',
      'date'       => self::EMAIL_DATE,
      'message_id' => 'test-publish',
    );

    $post_id = indivisible_newsletter_create_post_from_email( $email );
    $post    = get_post( $post_id );
    $this->assertEquals( 'publish', $post->post_status );
  }

  public function test_create_post_sets_category() {
    $cat_id = wp_create_category( 'Test Newsletters' );
    update_option( IN_OPTION_KEY, array(
      'post_status'     => 'draft',
      'post_category'   => $cat_id,
      'webmaster_email' => '',
    ) );

    $email = array(
      'subject'    => 'Categorized Newsletter',
      'html'       => '<p>Content</p>',
      'date'       => self::EMAIL_DATE,
      'message_id' => 'test-cat',
    );

    $post_id    = indivisible_newsletter_create_post_from_email( $email );
    $categories = wp_get_post_categories( $post_id );
    $this->assertContains( $cat_id, $categories );

    wp_delete_category( $cat_id );
  }

  // --- notify_webmaster ---

  public function test_notify_webmaster_sends_email() {
    update_option( IN_OPTION_KEY, array(
      'post_status'     => 'draft',
      'post_category'   => 0,
      'webmaster_email' => self::WEBMASTER_EMAIL,
    ) );

    $email = array(
      'subject'    => 'Notify Test',
      'html'       => '<p>Content</p>',
      'date'       => self::EMAIL_DATE,
      'message_id' => 'test-notify',
    );

    indivisible_newsletter_create_post_from_email( $email );

    $this->assertCount( 1, $this->sent_emails );
    $this->assertEquals( self::WEBMASTER_EMAIL, $this->sent_emails[0]['to'] );
    $this->assertStringContainsString( 'Notify Test', $this->sent_emails[0]['subject'] );
  }

  public function test_notify_webmaster_skips_when_no_email() {
    // Call notify_webmaster directly with empty webmaster_email,
    // since get_settings() has fallback logic that fills empty values.
    $post_id = wp_insert_post( array(
      'post_title'  => 'No Notify',
      'post_status' => 'draft',
    ) );

    $settings = array(
      'post_status'     => 'draft',
      'webmaster_email' => '',
    );
    indivisible_newsletter_notify_webmaster( $post_id, 'No Notify', $settings );
    $this->assertEmpty( $this->sent_emails );
  }

  public function test_notify_webmaster_includes_post_details() {
    update_option( IN_OPTION_KEY, array(
      'post_status'     => 'draft',
      'post_category'   => 0,
      'webmaster_email' => self::WEBMASTER_EMAIL,
    ) );

    $email = array(
      'subject'    => 'Details Test',
      'html'       => '<p>Content</p>',
      'date'       => self::EMAIL_DATE,
      'message_id' => 'test-details',
    );

    indivisible_newsletter_create_post_from_email( $email );

    $this->assertCount( 1, $this->sent_emails );
    $body = $this->sent_emails[0]['message'];
    $this->assertStringContainsString( 'Details Test', $body );
    $this->assertStringContainsString( 'draft', $body );
  }

  public function test_create_post_wraps_content_with_word_break() {
    update_option( IN_OPTION_KEY, array(
      'post_status'     => 'draft',
      'post_category'   => 0,
      'webmaster_email' => '',
    ) );

    $email = array(
      'subject'    => 'Newsletter',
      'html'       => '<p>Content with a <a href="https://example.com">long link</a></p>',
      'date'       => self::EMAIL_DATE,
      'message_id' => 'test-word-break',
    );

    $post_id = indivisible_newsletter_create_post_from_email( $email );
    $post    = get_post( $post_id );

    // Content must be wrapped in a div with in-newsletter-content class.
    // The class triggers CSS rules (injected via wp_head) that restore
    // word-break behavior stripped by wp_kses_post.
    $this->assertStringContainsString(
      '<div class="in-newsletter-content">',
      $post->post_content
    );
    $this->assertStringContainsString( 'Content', $post->post_content );
  }

  // --- IMAP socket helpers (for process_emails integration tests) ---

  /**
   * Build minimal IMAP settings merged with post-creation settings.
   *
   * @return array Settings suitable for IN_OPTION_KEY.
   */
  private function make_imap_settings(): array {
    return array(
      'imap_host'         => 'imap.example.com',
      'imap_port'         => '993',
      'imap_encryption'   => 'ssl',
      'email_username'    => 'user@example.com',
      'email_password'    => indivisible_newsletter_encrypt( 'secret' ),
      'imap_folder'       => 'INBOX',
      'filter_by_sender'  => false,
      'qualified_senders' => '',
      'post_status'       => 'draft',
      'post_category'     => 0,
      'webmaster_email'   => '',
    );
  }

  /**
   * Create a socket pair and optionally pre-load a server script.
   *
   * @param string $server_script Data to write on the server side.
   * @return array{0: resource, 1: resource} [client, server].
   */
  private function make_socket_pair( string $server_script = '' ): array {
    $pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
    $this->assertIsArray( $pair, 'stream_socket_pair() must succeed' );
    if ( $server_script !== '' ) {
      fwrite( $pair[1], $server_script );
    }
    return $pair;
  }

  /**
   * Inject a fake IMAP socket so fetch_emails() uses it instead of a real connection.
   *
   * @param resource $client Client side of socket pair.
   * @param resource $server Server side (stored for tearDown cleanup).
   */
  private function inject_socket( $client, $server ): void {
    $this->injected_server = $server;
    add_filter(
      'indivisible_newsletter_imap_socket_client',
      function () use ( $client ) {
        return $client;
      }
    );
  }

  /**
   * Build an IMAP server script that returns $count messages.
   *
   * Each message has a unique Message-ID and minimal HTML content.
   * UIDs are sequential starting from 1. The tag counter starts at A0001.
   *
   * @param int $count Number of messages to simulate.
   * @return string Server script ready to feed to make_socket_pair().
   */
  private function build_imap_script_for_messages( int $count ): string {
    // Greeting + LOGIN (A0001) + SELECT (A0002).
    $script  = "* OK IMAP ready\r\n";
    $script .= "A0001 OK LOGIN completed\r\n";
    $script .= "A0002 OK SELECT completed\r\n";

    // SEARCH ALL → UIDs 1..$count.
    $uids    = implode( ' ', range( 1, $count ) );
    $script .= "* SEARCH {$uids}\r\n";
    $script .= "A0003 OK SEARCH completed\r\n";

    // A-tag counter starts at A0004 (for STORE commands).
    $a_tag   = 4;
    // F-tag counter starts at F1001.
    $f_tag   = 1001;

    for ( $i = 1; $i <= $count; $i++ ) {
      $msg_id      = "<new-msg-{$i}@example.com>";
      $raw_header  = "From: sender@example.com\r\nSubject: Newsletter {$i}\r\nMessage-ID: {$msg_id}\r\n";
      $header_size = strlen( $raw_header );

      $full_body = "From: sender@example.com\r\nSubject: Newsletter {$i}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 7bit\r\n\r\n"
        . "<p>Content {$i}</p>";
      $body_size = strlen( $full_body );

      $f_header_tag = 'F' . $f_tag;
      $f_tag++;
      $f_body_tag = 'F' . $f_tag;
      $f_tag++;

      // Header fetch.
      $script .= "* {$i} FETCH (BODY[HEADER] {{$header_size}}\r\n";
      $script .= $raw_header;
      $script .= ")\r\n{$f_header_tag} OK FETCH completed\r\n";

      // Full body fetch.
      $script .= "* {$i} FETCH (BODY[] {{$body_size}}\r\n";
      $script .= $full_body;
      $script .= ")\r\n{$f_body_tag} OK FETCH completed\r\n";

      // STORE flags.
      $a_store_tag = 'A' . str_pad( $a_tag, 4, '0', STR_PAD_LEFT );
      $a_tag++;
      $script .= "{$a_store_tag} OK STORE completed\r\n";
    }

    // LOGOUT.
    $a_logout_tag = 'A' . str_pad( $a_tag, 4, '0', STR_PAD_LEFT );
    $script      .= "{$a_logout_tag} OK LOGOUT\r\n";

    return $script;
  }

  // --- process_emails (integration-level, fake IMAP socket) ---

  public function test_process_emails_tracks_processed_ids() {
    update_option( IN_OPTION_KEY, $this->make_imap_settings() );

    $script = $this->build_imap_script_for_messages( 2 );
    [ $client, $server ] = $this->make_socket_pair( $script );
    $this->inject_socket( $client, $server );

    $result = indivisible_newsletter_process_emails();

    // CON10 A2: process_emails() now returns a structured batch report.
    $this->assertSame( 2, $result['created'] );
    $this->assertSame( array(), $result['failures'] );

    $stored = get_option( IN_PROCESSED_KEY, array() );
    $this->assertCount( 2, $stored );
    $this->assertContains( '<new-msg-1@example.com>', $stored );
    $this->assertContains( '<new-msg-2@example.com>', $stored );
  }

  public function test_processed_ids_limit_to_500() {
    update_option( IN_OPTION_KEY, $this->make_imap_settings() );

    // Pre-seed the option with 498 previously-processed IDs.
    $existing_ids = array();
    for ( $i = 1; $i <= 498; $i++ ) {
      $existing_ids[] = "<old-msg-{$i}@example.com>";
    }
    update_option( IN_PROCESSED_KEY, $existing_ids );

    // Simulate 5 new messages via the fake IMAP server.
    // After processing: 498 + 5 = 503 total → trimmed to 500 by production code.
    $script = $this->build_imap_script_for_messages( 5 );
    [ $client, $server ] = $this->make_socket_pair( $script );
    $this->inject_socket( $client, $server );

    $result = indivisible_newsletter_process_emails();

    // CON10 A2: structured batch report.
    $this->assertSame( 5, $result['created'] );

    $stored = get_option( IN_PROCESSED_KEY, array() );
    $this->assertCount( 500, $stored );

    // The oldest 3 pre-seeded IDs (old-msg-1 through old-msg-3) should be trimmed.
    $this->assertNotContains( '<old-msg-1@example.com>', $stored );
    $this->assertNotContains( '<old-msg-2@example.com>', $stored );
    $this->assertNotContains( '<old-msg-3@example.com>', $stored );

    // old-msg-4 should be the oldest surviving entry.
    $this->assertEquals( '<old-msg-4@example.com>', $stored[0] );

    // All 5 new message IDs should be present at the end.
    $this->assertContains( '<new-msg-1@example.com>', $stored );
    $this->assertContains( '<new-msg-5@example.com>', $stored );
    $this->assertEquals( '<new-msg-5@example.com>', end( $stored ) );
  }

  // --- extract_forwarded_content edge cases ---

  public function test_extract_forwarded_content_removes_cc_header() {
    $html   = '<blockquote type="cite"><div><span><b>Cc: </b></span>cc@example.com</div><p>Post-Cc content</p></blockquote>';
    $result = indivisible_newsletter_extract_forwarded_content( $html );

    $this->assertStringContainsString( 'Post-Cc content', $result );
    $this->assertStringNotContainsString( 'Cc:', $result );
  }

  public function test_extract_forwarded_content_removes_bcc_header() {
    $html   = '<blockquote type="cite"><div><span><b>Bcc: </b></span>bcc@example.com</div><p>Post-Bcc content</p></blockquote>';
    $result = indivisible_newsletter_extract_forwarded_content( $html );

    $this->assertStringContainsString( 'Post-Bcc content', $result );
    $this->assertStringNotContainsString( 'Bcc:', $result );
  }

  public function test_extract_forwarded_content_empty_string() {
    $result = indivisible_newsletter_extract_forwarded_content( '' );
    $this->assertEquals( '', $result );
  }

  public function test_extract_forwarded_content_extracts_single_wrapper_div() {
    $html   = '<blockquote type="cite"><div><span><b>From: </b></span>sender@example.com</div><div><p>Unwrapped content</p></div></blockquote>';
    $result = indivisible_newsletter_extract_forwarded_content( $html );

    $this->assertEquals( '<p>Unwrapped content</p>', $result );
  }

  public function test_create_post_stores_raw_body_meta(): void {
    $raw_html = '<table class="nl-container" style="background-color: #0068a5">Content</table>';
    $email = array(
        'subject'    => 'Test Newsletter',
        'html'       => $raw_html,
        'date'       => '2026-04-12',
        'message_id' => '<test@example.com>',
    );

    $post_id = indivisible_newsletter_create_post_from_email( $email );

    $this->assertIsInt( $post_id );
    $stored_raw = get_post_meta( $post_id, '_in_newsletter_raw_body', true );
    $this->assertSame(
        $raw_html,
        $stored_raw,
        'Post meta _in_newsletter_raw_body should equal the pre-clean HTML'
    );
  }

  public function test_create_post_stores_message_id_meta(): void {
    $email = array(
        'subject'    => 'Test Newsletter',
        'html'       => '<p>Body</p>',
        'date'       => '2026-04-12',
        'message_id' => '<abc123@example.com>',
    );

    $post_id = indivisible_newsletter_create_post_from_email( $email );

    $this->assertIsInt( $post_id );
    $stored = get_post_meta( $post_id, '_in_newsletter_message_id', true );
    $this->assertSame(
        '<abc123@example.com>',
        $stored,
        'Post meta _in_newsletter_message_id should equal $email["message_id"]'
    );
  }

  public function test_create_post_stores_raw_subject_meta(): void {
    $email = array(
        'subject'    => 'Fwd: April General Assembly Recap',
        'html'       => '<p>Body</p>',
        'date'       => '2026-04-12',
        'message_id' => '<id@example.com>',
    );

    $post_id = indivisible_newsletter_create_post_from_email( $email );

    $this->assertIsInt( $post_id );
    $stored = get_post_meta( $post_id, '_in_newsletter_raw_subject', true );
    $this->assertSame(
        'Fwd: April General Assembly Recap',
        $stored,
        'Post meta _in_newsletter_raw_subject should equal the unmodified $email["subject"]'
    );
  }

  public function test_build_post_content_runs_pipeline_and_wraps_output(): void {
    $raw = '<p>Hello <strong>World</strong></p>';

    $content = indivisible_newsletter_build_post_content( $raw );

    $this->assertStringStartsWith( "<!-- wp:html -->\n", $content );
    $this->assertStringEndsWith( "\n<!-- /wp:html -->", $content );
    $this->assertHtml( $content )
      ->find( 'div.in-newsletter-content' )
      ->exists();
    $this->assertHtml( $content )
      ->find( 'div.in-newsletter-content' )
      ->containsText( 'Hello World' );
  }

  public function test_build_post_content_includes_unique_build_timestamp(): void {
    $raw = '<p>Body</p>';

    $first  = indivisible_newsletter_build_post_content( $raw );
    $second = indivisible_newsletter_build_post_content( $raw );

    // Each call must emit a distinct <!-- built: ISO --> comment between the
    // Gutenberg block-open marker and the .in-newsletter-content div so that
    // consecutive builds produce byte-distinct output (see test_reprocess_is_idempotent).
    $this->assertMatchesRegularExpression(
      '/<!-- built: \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z-\d+ -->/',
      $first,
      'Helper output must contain a <!-- built: ISO8601-hrtime --> comment'
    );
    $this->assertNotSame(
      $first,
      $second,
      'Two calls to build_post_content with the same input must produce different output due to the unique build timestamp'
    );
  }

  public function test_build_post_content_strips_dangerous_html_via_wp_kses_post(): void {
    $raw = '<p>Safe text</p><script>alert("xss")</script><p>More safe text</p>';

    $content = indivisible_newsletter_build_post_content( $raw );

    // wp_kses_post strips <script> tags entirely. The safe text must survive.
    $this->assertHtml( $content )
      ->find( 'div.in-newsletter-content' )
      ->containsText( 'Safe text' );
    $this->assertHtml( $content )
      ->find( 'div.in-newsletter-content' )
      ->containsText( 'More safe text' );
    $this->assertHtml( $content )
      ->find( 'div.in-newsletter-content script' )
      ->doesNotExist();
  }
}
