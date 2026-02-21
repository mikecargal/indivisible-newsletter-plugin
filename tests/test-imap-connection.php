<?php
/**
 * Tests for IMAP connection functions in Indivisible Newsletter plugin.
 *
 * Uses stream_socket_pair() to simulate IMAP server responses without a real
 * network connection. The 'indivisible_newsletter_imap_socket_client' filter
 * allows injecting a pre-connected socket pair into imap_connect().
 *
 * Tag counter globals (in_imap_tag_counter, in_imap_fetch_counter) are reset
 * in setUp() so each test starts from a predictable A0001 / F1001 sequence.
 */
class Test_IN_IMAP_Connection extends WP_UnitTestCase {

	/** @var resource|null Server side of injected socket pair. */
	private $injected_server = null;

	public function setUp(): void {
		parent::setUp();
		$GLOBALS['in_imap_tag_counter']   = 0;
		$GLOBALS['in_imap_fetch_counter'] = 1000;
		$this->injected_server            = null;
	}

	public function tearDown(): void {
		remove_all_filters( 'indivisible_newsletter_imap_socket_client' );
		if ( is_resource( $this->injected_server ) ) {
			fclose( $this->injected_server );
			$this->injected_server = null;
		}
		delete_option( IN_OPTION_KEY );
		delete_option( IN_PROCESSED_KEY );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a socket pair and return [client, server].
	 *
	 * Pre-write $server_script to the server side so the client reads it as
	 * the IMAP server response.
	 *
	 * @param string $server_script Data to pre-load on the server side.
	 * @return array{0: resource, 1: resource}
	 */
	private function make_socket_pair( string $server_script = '' ): array {
		$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		$this->assertIsArray( $pair, 'stream_socket_pair() must succeed' );
		if ( $server_script !== '' ) {
			fwrite( $pair[1], $server_script );
		}
		return $pair; // [0] = client, [1] = server.
	}

	/**
	 * Register the socket-injection filter using the given client resource.
	 * Stores the server side in $this->injected_server for tearDown.
	 *
	 * @param resource $client Client side of the socket pair.
	 * @param resource $server Server side of the socket pair.
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
	 * Build minimal valid settings array for imap_connect / test_connection / fetch_emails.
	 *
	 * @param array $overrides Values to merge over the defaults.
	 * @return array
	 */
	private function make_settings( array $overrides = [] ): array {
		return array_merge(
			array(
				'imap_host'       => 'imap.example.com',
				'imap_port'       => '993',
				'imap_encryption' => 'ssl',
				'email_username'  => 'user@example.com',
				'email_password'  => indivisible_newsletter_encrypt( 'secret' ),
				'imap_folder'     => 'INBOX',
				'filter_by_sender'  => false,
				'qualified_senders' => '',
			),
			$overrides
		);
	}

	// -------------------------------------------------------------------------
	// imap_command() — 8 tests
	// -------------------------------------------------------------------------

	public function test_imap_command_sends_tagged_command() {
		// Client writes; we read from server side.
		[ $client, $server ] = $this->make_socket_pair();
		stream_set_blocking( $client, false );

		// Call will fail to read response (no data on server→client direction),
		// but it must have written the command first.
		indivisible_newsletter_imap_command( $client, 'NOOP' );

		stream_set_blocking( $server, false );
		$sent = fread( $server, 256 ) ?: '';

		$this->assertMatchesRegularExpression( '/^A0001 NOOP\r\n$/', $sent );

		fclose( $client );
		fclose( $server );
	}

	public function test_imap_command_ok_response_returns_array() {
		[ $client, $server ] = $this->make_socket_pair( "A0001 OK done\r\n" );

		$result = indivisible_newsletter_imap_command( $client, 'NOOP' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'OK done', $result[0] );

		fclose( $client );
		fclose( $server );
	}

	public function test_imap_command_returns_all_untagged_lines() {
		$script = "* 5 EXISTS\r\n* 1 RECENT\r\nA0001 OK SELECT completed\r\n";
		[ $client, $server ] = $this->make_socket_pair( $script );

		$result = indivisible_newsletter_imap_command( $client, 'SELECT "INBOX"' );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		$this->assertStringContainsString( '* 5 EXISTS', $result[0] );
		$this->assertStringContainsString( '* 1 RECENT', $result[1] );
		$this->assertStringContainsString( 'A0001 OK', $result[2] );

		fclose( $client );
		fclose( $server );
	}

	public function test_imap_command_no_response_returns_wp_error() {
		[ $client, $server ] = $this->make_socket_pair( "A0001 NO login failed\r\n" );

		$result = indivisible_newsletter_imap_command( $client, 'LOGIN "user" "bad"' );

		$this->assertWPError( $result );
		$this->assertEquals( 'imap_error', $result->get_error_code() );

		fclose( $client );
		fclose( $server );
	}

	public function test_imap_command_bad_response_returns_wp_error() {
		[ $client, $server ] = $this->make_socket_pair( "A0001 BAD command unknown\r\n" );

		$result = indivisible_newsletter_imap_command( $client, 'BOGUS' );

		$this->assertWPError( $result );
		$this->assertEquals( 'imap_error', $result->get_error_code() );

		fclose( $client );
		fclose( $server );
	}

	public function test_imap_command_read_failure_returns_wp_error() {
		[ $client, $server ] = $this->make_socket_pair();
		// Close server side so the client socket reaches EOF on read.
		fclose( $server );

		$result = indivisible_newsletter_imap_command( $client, 'NOOP' );

		$this->assertWPError( $result );
		$this->assertEquals( 'read_error', $result->get_error_code() );

		fclose( $client );
	}

	public function test_imap_command_increments_tag_between_calls() {
		// First call (A0001).
		[ $c1, $s1 ] = $this->make_socket_pair( "A0001 OK\r\n" );
		indivisible_newsletter_imap_command( $c1, 'NOOP' );

		// Second call must use A0002.
		[ $c2, $s2 ] = $this->make_socket_pair( "A0002 OK\r\n" );
		$result = indivisible_newsletter_imap_command( $c2, 'NOOP' );

		// Verify tag A0002 used.
		stream_set_blocking( $s2, false );
		$sent = fread( $s2, 256 ) ?: '';
		$this->assertStringStartsWith( 'A0002 ', $sent );

		$this->assertIsArray( $result );

		fclose( $c1 );
		fclose( $s1 );
		fclose( $c2 );
		fclose( $s2 );
	}

	public function test_imap_command_tag_has_correct_format() {
		[ $client, $server ] = $this->make_socket_pair();
		stream_set_blocking( $client, false );

		indivisible_newsletter_imap_command( $client, 'CAPABILITY' );

		stream_set_blocking( $server, false );
		$sent = fread( $server, 256 ) ?: '';
		// Tag must be A followed by exactly 4 zero-padded digits.
		$this->assertMatchesRegularExpression( '/^A\d{4} CAPABILITY\r\n$/', $sent );

		fclose( $client );
		fclose( $server );
	}

	// -------------------------------------------------------------------------
	// imap_fetch_section() — 5 tests
	// -------------------------------------------------------------------------

	public function test_imap_fetch_section_header_sends_body_header_command() {
		[ $client, $server ] = $this->make_socket_pair( "F1001 OK FETCH completed\r\n" );

		indivisible_newsletter_imap_fetch_section( $client, 1, 'HEADER' );

		stream_set_blocking( $server, false );
		$sent = fread( $server, 256 ) ?: '';
		$this->assertStringContainsString( 'FETCH 1 BODY[HEADER]', $sent );

		fclose( $client );
		fclose( $server );
	}

	public function test_imap_fetch_section_empty_section_sends_body_all_command() {
		[ $client, $server ] = $this->make_socket_pair( "F1001 OK FETCH completed\r\n" );

		indivisible_newsletter_imap_fetch_section( $client, 2, '' );

		stream_set_blocking( $server, false );
		$sent = fread( $server, 256 ) ?: '';
		$this->assertStringContainsString( 'FETCH 2 BODY[]', $sent );

		fclose( $client );
		fclose( $server );
	}

	public function test_imap_fetch_section_named_section_sends_correct_command() {
		[ $client, $server ] = $this->make_socket_pair( "F1001 OK FETCH completed\r\n" );

		indivisible_newsletter_imap_fetch_section( $client, 3, '1.2' );

		stream_set_blocking( $server, false );
		$sent = fread( $server, 256 ) ?: '';
		$this->assertStringContainsString( 'FETCH 3 BODY[1.2]', $sent );

		fclose( $client );
		fclose( $server );
	}

	public function test_imap_fetch_section_literal_response_returns_data() {
		$literal_data = "From: sender@example.com\r\nSubject: Test\r\n";
		$size         = strlen( $literal_data );

		// IMAP literal response: line with {N} size indicator, then N bytes of data.
		$script = "* 1 FETCH (BODY[HEADER] {{$size}}\r\n" . $literal_data . ")\r\nF1001 OK FETCH completed\r\n";
		[ $client, $server ] = $this->make_socket_pair( $script );

		$result = indivisible_newsletter_imap_fetch_section( $client, 1, 'HEADER' );

		$this->assertEquals( $literal_data, $result );

		fclose( $client );
		fclose( $server );
	}

	public function test_imap_fetch_section_no_literal_returns_empty_string() {
		// Tagged OK with no literal — nothing to extract.
		[ $client, $server ] = $this->make_socket_pair( "F1001 OK FETCH completed\r\n" );

		$result = indivisible_newsletter_imap_fetch_section( $client, 1, 'HEADER' );

		$this->assertSame( '', $result );

		fclose( $client );
		fclose( $server );
	}

	// -------------------------------------------------------------------------
	// imap_connect() — 4 tests
	// -------------------------------------------------------------------------

	public function test_imap_connect_bad_greeting_returns_wp_error() {
		$settings = $this->make_settings();
		[ $client, $server ] = $this->make_socket_pair( "* BAD server is too busy\r\n" );
		$this->inject_socket( $client, $server );

		$result = indivisible_newsletter_imap_connect( $settings, 'secret' );

		$this->assertWPError( $result );
		$this->assertEquals( 'connection_failed', $result->get_error_code() );
	}

	public function test_imap_connect_login_failure_returns_wp_error() {
		// Greeting OK, but login is rejected.
		$script = "* OK IMAP server ready\r\nA0001 NO Authentication failed\r\n";
		[ $client, $server ] = $this->make_socket_pair( $script );
		$this->inject_socket( $client, $server );

		$result = indivisible_newsletter_imap_connect( $this->make_settings(), 'wrong_password' );

		$this->assertWPError( $result );
		$this->assertEquals( 'auth_failed', $result->get_error_code() );
	}

	public function test_imap_connect_success_returns_resource() {
		$script = "* OK IMAP server ready\r\nA0001 OK LOGIN completed\r\n";
		[ $client, $server ] = $this->make_socket_pair( $script );
		$this->inject_socket( $client, $server );

		$result = indivisible_newsletter_imap_connect( $this->make_settings(), 'secret' );

		$this->assertNotWPError( $result );
		$this->assertTrue( is_resource( $result ) );

		// Clean up the returned connection.
		fclose( $result );
	}

	public function test_imap_connect_builds_ssl_address() {
		$captured_address = null;
		add_filter(
			'indivisible_newsletter_imap_socket_client',
			function ( $conn, $address ) use ( &$captured_address ) {
				$captured_address = $address;
				// Return false to short-circuit without a real socket.
				return false;
			},
			10,
			2
		);

		indivisible_newsletter_imap_connect( $this->make_settings(), 'secret' );

		$this->assertEquals( 'ssl://imap.example.com:993', $captured_address );
	}

	// -------------------------------------------------------------------------
	// test_connection() — 4 tests
	// -------------------------------------------------------------------------

	public function test_test_connection_missing_password() {
		update_option( IN_OPTION_KEY, $this->make_settings( array( 'email_password' => '' ) ) );

		$result = indivisible_newsletter_test_connection();

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_settings', $result->get_error_code() );
	}

	public function test_test_connection_success_reports_message_count() {
		update_option( IN_OPTION_KEY, $this->make_settings() );

		// Full IMAP conversation for test_connection():
		// 1. imap_connect reads greeting (A0001 LOGIN)
		// 2. test_connection calls imap_command(SELECT)  → A0002
		// 3. test_connection calls imap_command(LOGOUT)  → A0003
		$script  = "* OK IMAP server ready\r\n";       // greeting for imap_connect
		$script .= "A0001 OK LOGIN completed\r\n";     // login response
		$script .= "* 5 EXISTS\r\n* 2 RECENT\r\n";    // SELECT untagged responses
		$script .= "A0002 OK SELECT completed\r\n";    // SELECT tagged OK
		$script .= "A0003 OK LOGOUT\r\n";              // LOGOUT tagged OK

		[ $client, $server ] = $this->make_socket_pair( $script );
		$this->inject_socket( $client, $server );

		$result = indivisible_newsletter_test_connection();

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Connection successful', $result );
		$this->assertStringContainsString( '5', $result );
	}

	// -------------------------------------------------------------------------
	// fetch_emails() — 6 tests
	// -------------------------------------------------------------------------

	public function test_fetch_emails_missing_password() {
		update_option( IN_OPTION_KEY, $this->make_settings( array( 'email_password' => '' ) ) );

		$result = indivisible_newsletter_fetch_emails();

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_settings', $result->get_error_code() );
	}

	public function test_fetch_emails_empty_mailbox_returns_empty_array() {
		update_option( IN_OPTION_KEY, $this->make_settings() );

		// fetch_emails() conversation:
		// A0001 LOGIN → OK
		// A0002 SELECT INBOX → OK
		// A0003 SEARCH ALL → empty result
		// A0004 LOGOUT → OK
		$script  = "* OK IMAP ready\r\n";
		$script .= "A0001 OK LOGIN completed\r\n";
		$script .= "A0002 OK SELECT completed\r\n";
		$script .= "* SEARCH \r\n";                   // empty SEARCH result
		$script .= "A0003 OK SEARCH completed\r\n";
		$script .= "A0004 OK LOGOUT\r\n";

		[ $client, $server ] = $this->make_socket_pair( $script );
		$this->inject_socket( $client, $server );

		$result = indivisible_newsletter_fetch_emails();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_fetch_emails_skips_already_processed_message() {
		$message_id = '<already-processed@example.com>';
		update_option( IN_PROCESSED_KEY, array( $message_id ) );
		update_option( IN_OPTION_KEY, $this->make_settings() );

		// Build raw header containing the known Message-ID.
		$raw_header   = "From: sender@example.com\r\nSubject: Old Newsletter\r\nMessage-ID: {$message_id}\r\n";
		$header_size  = strlen( $raw_header );

		// fetch_emails() conversation with UID 1:
		// A0001 LOGIN → OK
		// A0002 SELECT → OK
		// A0003 SEARCH ALL → UID 1
		// A0004 SEARCH completed
		// F1001 FETCH header → literal
		// A0005 LOGOUT → OK
		$script  = "* OK IMAP ready\r\n";
		$script .= "A0001 OK LOGIN completed\r\n";
		$script .= "A0002 OK SELECT completed\r\n";
		$script .= "* SEARCH 1\r\n";
		$script .= "A0003 OK SEARCH completed\r\n";
		// Header fetch via imap_fetch_section (uses F counter).
		$script .= "* 1 FETCH (BODY[HEADER] {{$header_size}}\r\n";
		$script .= $raw_header;
		$script .= ")\r\nF1001 OK FETCH completed\r\n";
		// LOGOUT.
		$script .= "A0004 OK LOGOUT\r\n";

		[ $client, $server ] = $this->make_socket_pair( $script );
		$this->inject_socket( $client, $server );

		$result = indivisible_newsletter_fetch_emails();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	public function test_fetch_emails_returns_new_email_with_html() {
		update_option( IN_OPTION_KEY, $this->make_settings() );

		$html_body   = '<p>Newsletter content</p>';
		$raw_header  = "From: sender@example.com\r\nSubject: Test Newsletter\r\nMessage-ID: <new-msg@example.com>\r\n";
		$header_size = strlen( $raw_header );

		// Build a simple text/html MIME message for the full body fetch.
		$full_body = "From: sender@example.com\r\nSubject: Test Newsletter\r\n" .
			"Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 7bit\r\n\r\n" .
			$html_body;
		$body_size = strlen( $full_body );

		// fetch_emails() conversation with UID 1 (new message):
		// A0001 LOGIN
		// A0002 SELECT
		// A0003 SEARCH ALL → UID 1
		// F1001 FETCH HEADER
		// F1002 FETCH BODY[]
		// A0004 STORE +FLAGS (seen)
		// A0005 LOGOUT
		$script  = "* OK IMAP ready\r\n";
		$script .= "A0001 OK LOGIN completed\r\n";
		$script .= "A0002 OK SELECT completed\r\n";
		$script .= "* SEARCH 1\r\n";
		$script .= "A0003 OK SEARCH completed\r\n";
		// Header fetch.
		$script .= "* 1 FETCH (BODY[HEADER] {{$header_size}}\r\n";
		$script .= $raw_header;
		$script .= ")\r\nF1001 OK FETCH completed\r\n";
		// Full body fetch.
		$script .= "* 1 FETCH (BODY[] {{$body_size}}\r\n";
		$script .= $full_body;
		$script .= ")\r\nF1002 OK FETCH completed\r\n";
		// STORE flags.
		$script .= "A0004 OK STORE completed\r\n";
		// LOGOUT.
		$script .= "A0005 OK LOGOUT\r\n";

		[ $client, $server ] = $this->make_socket_pair( $script );
		$this->inject_socket( $client, $server );

		$result = indivisible_newsletter_fetch_emails();

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );

		$email = $result[0];
		$this->assertEquals( '<new-msg@example.com>', $email['message_id'] );
		$this->assertEquals( 'Test Newsletter', $email['subject'] );
		$this->assertStringContainsString( 'Newsletter content', $email['html'] );
		$this->assertEquals( 1, $email['uid'] );
	}

	public function test_fetch_emails_filter_by_sender_issues_from_search_per_sender() {
		update_option(
			IN_OPTION_KEY,
			$this->make_settings(
				array(
					'filter_by_sender'  => true,
					'qualified_senders' => "alice@example.com\nbob@example.com",
				)
			)
		);

		// With filter_by_sender, fetch_emails() calls SEARCH FROM for each sender.
		// No results from either search → returns empty array after LOGOUT.
		$script  = "* OK IMAP ready\r\n";
		$script .= "A0001 OK LOGIN completed\r\n";
		$script .= "A0002 OK SELECT completed\r\n";
		// SEARCH FROM alice → no results.
		$script .= "* SEARCH \r\n";
		$script .= "A0003 OK SEARCH completed\r\n";
		// SEARCH FROM bob → no results.
		$script .= "* SEARCH \r\n";
		$script .= "A0004 OK SEARCH completed\r\n";
		$script .= "A0005 OK LOGOUT\r\n";

		[ $client, $server ] = $this->make_socket_pair( $script );
		$this->inject_socket( $client, $server );

		$result = indivisible_newsletter_fetch_emails();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );

		// Verify that both SEARCH FROM commands were sent.
		stream_set_blocking( $server, false );
		$sent = stream_get_contents( $server ) ?: '';
		$this->assertStringContainsString( 'SEARCH FROM "alice@example.com"', $sent );
		$this->assertStringContainsString( 'SEARCH FROM "bob@example.com"', $sent );
	}

	public function test_fetch_emails_skips_message_without_html() {
		update_option( IN_OPTION_KEY, $this->make_settings() );

		$raw_header  = "From: sender@example.com\r\nSubject: Text Only\r\nMessage-ID: <text-only@example.com>\r\n";
		$header_size = strlen( $raw_header );

		// Full body is text/plain only — no HTML.
		$full_body = "From: sender@example.com\r\nSubject: Text Only\r\n" .
			"Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 7bit\r\n\r\n" .
			'This is plain text only.';
		$body_size = strlen( $full_body );

		$script  = "* OK IMAP ready\r\n";
		$script .= "A0001 OK LOGIN completed\r\n";
		$script .= "A0002 OK SELECT completed\r\n";
		$script .= "* SEARCH 1\r\n";
		$script .= "A0003 OK SEARCH completed\r\n";
		// Header fetch.
		$script .= "* 1 FETCH (BODY[HEADER] {{$header_size}}\r\n";
		$script .= $raw_header;
		$script .= ")\r\nF1001 OK FETCH completed\r\n";
		// Full body fetch.
		$script .= "* 1 FETCH (BODY[] {{$body_size}}\r\n";
		$script .= $full_body;
		$script .= ")\r\nF1002 OK FETCH completed\r\n";
		// STORE flags (message is still marked seen even if not queued).
		$script .= "A0004 OK STORE completed\r\n";
		// LOGOUT.
		$script .= "A0005 OK LOGOUT\r\n";

		[ $client, $server ] = $this->make_socket_pair( $script );
		$this->inject_socket( $client, $server );

		$result = indivisible_newsletter_fetch_emails();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}
}
