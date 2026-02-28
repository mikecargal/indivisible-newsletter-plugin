<?php
/**
 * Tests for Indivisible Newsletter email parsing functions.
 */
class Test_IN_Email_Parsing extends WP_UnitTestCase {

	// --- parse_headers ---

	public function test_parse_headers_basic() {
		$raw = "From: sender@example.com\r\nSubject: Hello World\r\nDate: Mon, 17 Feb 2026 10:00:00 +0000";

		$headers = indivisible_newsletter_parse_headers( $raw );
		$this->assertEquals( 'sender@example.com', $headers['from'] );
		$this->assertEquals( 'Hello World', $headers['subject'] );
	}

	public function test_parse_headers_lowercase_keys() {
		$raw = "Message-ID: <abc123@example.com>\r\nContent-Type: text/html";

		$headers = indivisible_newsletter_parse_headers( $raw );
		$this->assertArrayHasKey( 'message-id', $headers );
		$this->assertArrayHasKey( 'content-type', $headers );
	}

	public function test_parse_headers_unfolds_continuation_lines() {
		$raw = "Subject: This is a very long\r\n subject that wraps";

		$headers = indivisible_newsletter_parse_headers( $raw );
		$this->assertEquals( 'This is a very long subject that wraps', $headers['subject'] );
	}

	public function test_parse_headers_tab_continuation() {
		$raw = "Content-Type: multipart/mixed;\r\n\tboundary=\"abc123\"";

		$headers = indivisible_newsletter_parse_headers( $raw );
		$this->assertStringContainsString( 'multipart/mixed', $headers['content-type'] );
		$this->assertStringContainsString( 'boundary="abc123"', $headers['content-type'] );
	}

	public function test_parse_headers_empty_input() {
		$headers = indivisible_newsletter_parse_headers( '' );
		$this->assertIsArray( $headers );
		$this->assertEmpty( $headers );
	}

	// --- decode_transfer_encoding ---

	public function test_decode_base64() {
		$original = '<p>Hello World</p>';
		$encoded  = base64_encode( $original );

		$result = indivisible_newsletter_decode_transfer_encoding( $encoded, 'base64' );
		$this->assertEquals( $original, $result );
	}

	public function test_decode_quoted_printable() {
		$encoded = 'Hello=20World=0D=0ANew=20Line';

		$result = indivisible_newsletter_decode_transfer_encoding( $encoded, 'quoted-printable' );
		$this->assertStringContainsString( 'Hello World', $result );
	}

	public function test_decode_7bit_passthrough() {
		$original = '<p>Plain ASCII</p>';
		$result   = indivisible_newsletter_decode_transfer_encoding( $original, '7bit' );
		$this->assertEquals( $original, $result );
	}

	public function test_decode_8bit_passthrough() {
		$original = '<p>Extended chars: café</p>';
		$result   = indivisible_newsletter_decode_transfer_encoding( $original, '8bit' );
		$this->assertEquals( $original, $result );
	}

	public function test_decode_binary_passthrough() {
		$original = 'binary data here';
		$result   = indivisible_newsletter_decode_transfer_encoding( $original, 'binary' );
		$this->assertEquals( $original, $result );
	}

	public function test_decode_unknown_encoding_passthrough() {
		$original = '<p>content</p>';
		$result   = indivisible_newsletter_decode_transfer_encoding( $original, 'unknown' );
		$this->assertEquals( $original, $result );
	}

	public function test_decode_empty_encoding_passthrough() {
		$original = '<p>content</p>';
		$result   = indivisible_newsletter_decode_transfer_encoding( $original, '' );
		$this->assertEquals( $original, $result );
	}

	// --- decode_mime_header (RFC 2047) ---

	public function test_decode_mime_header_plain_text() {
		$this->assertEquals( 'Hello World', indivisible_newsletter_decode_mime_header( 'Hello World' ) );
	}

	public function test_decode_mime_header_base64_utf8() {
		$original = 'Héllo Wörld';
		$encoded  = '=?UTF-8?B?' . base64_encode( $original ) . '?=';

		$result = indivisible_newsletter_decode_mime_header( $encoded );
		$this->assertEquals( $original, $result );
	}

	public function test_decode_mime_header_quoted_printable() {
		// In QP headers, underscores mean spaces, =XX is hex encoding.
		$encoded = '=?UTF-8?Q?Hello_World?=';

		$result = indivisible_newsletter_decode_mime_header( $encoded );
		$this->assertEquals( 'Hello World', $result );
	}

	public function test_decode_mime_header_case_insensitive_encoding() {
		$original = 'Test';
		$encoded  = '=?utf-8?b?' . base64_encode( $original ) . '?=';

		$result = indivisible_newsletter_decode_mime_header( $encoded );
		$this->assertEquals( $original, $result );
	}

	public function test_decode_mime_header_iso_8859_1() {
		// ISO-8859-1 encoded é (0xE9).
		$encoded = '=?ISO-8859-1?Q?caf=E9?=';

		$result = indivisible_newsletter_decode_mime_header( $encoded );
		$this->assertEquals( 'café', $result );
	}

	public function test_decode_mime_header_mixed_encoded_and_plain() {
		$encoded = 'Re: =?UTF-8?B?' . base64_encode( 'Newsletter' ) . '?= Update';

		$result = indivisible_newsletter_decode_mime_header( $encoded );
		$this->assertEquals( 'Re: Newsletter Update', $result );
	}

	// --- extract_html_from_raw ---

	public function test_extract_html_simple_html_message() {
		$raw = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 7bit\r\n\r\n<p>Hello World</p>";

		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertEquals( '<p>Hello World</p>', $result );
	}

	public function test_extract_html_base64_encoded() {
		$html    = '<p>Encoded Content</p>';
		$encoded = base64_encode( $html );
		$raw     = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n{$encoded}";

		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertEquals( $html, $result );
	}

	public function test_extract_html_quoted_printable() {
		$raw = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n<p>Hello=20World</p>";

		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertEquals( '<p>Hello World</p>', $result );
	}

	public function test_extract_html_no_body_returns_empty() {
		$raw = "Content-Type: text/html; charset=UTF-8";
		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertEquals( '', $result );
	}

	public function test_extract_html_text_plain_returns_empty() {
		$raw = "Content-Type: text/plain\r\n\r\nPlain text content";
		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertEquals( '', $result );
	}

	// --- find_html_in_multipart ---

	public function test_extract_html_multipart_alternative() {
		$boundary = 'boundary123';
		$raw = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n" .
			"--{$boundary}\r\n" .
			"Content-Type: text/plain\r\n\r\n" .
			"Plain text version\r\n" .
			"--{$boundary}\r\n" .
			"Content-Type: text/html; charset=UTF-8\r\n\r\n" .
			"<p>HTML version</p>\r\n" .
			"--{$boundary}--";

		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertStringContainsString( '<p>HTML version</p>', $result );
	}

	public function test_extract_html_multipart_mixed() {
		$boundary = 'mixed-boundary';
		$raw = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n" .
			"--{$boundary}\r\n" .
			"Content-Type: text/html; charset=UTF-8\r\n\r\n" .
			"<p>HTML in mixed</p>\r\n" .
			"--{$boundary}\r\n" .
			"Content-Type: application/pdf\r\n\r\n" .
			"binary-pdf-data\r\n" .
			"--{$boundary}--";

		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertStringContainsString( '<p>HTML in mixed</p>', $result );
	}

	public function test_extract_html_nested_multipart() {
		$outer = 'outer-boundary';
		$inner = 'inner-boundary';
		$raw = "Content-Type: multipart/mixed; boundary=\"{$outer}\"\r\n\r\n" .
			"--{$outer}\r\n" .
			"Content-Type: multipart/alternative; boundary=\"{$inner}\"\r\n\r\n" .
			"--{$inner}\r\n" .
			"Content-Type: text/plain\r\n\r\n" .
			"Plain text\r\n" .
			"--{$inner}\r\n" .
			"Content-Type: text/html\r\n\r\n" .
			"<p>Nested HTML</p>\r\n" .
			"--{$inner}--\r\n" .
			"--{$outer}--";

		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertStringContainsString( '<p>Nested HTML</p>', $result );
	}

	public function test_extract_html_multipart_base64_html_part() {
		$boundary = 'b64-boundary';
		$html     = '<p>Base64 in multipart</p>';
		$encoded  = base64_encode( $html );
		$raw = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n" .
			"--{$boundary}\r\n" .
			"Content-Type: text/plain\r\n\r\n" .
			"Fallback\r\n" .
			"--{$boundary}\r\n" .
			"Content-Type: text/html; charset=UTF-8\r\n" .
			"Content-Transfer-Encoding: base64\r\n\r\n" .
			"{$encoded}\r\n" .
			"--{$boundary}--";

		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertEquals( $html, $result );
	}

	public function test_extract_html_multipart_no_html_part_returns_empty() {
		$boundary = 'no-html';
		$raw = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n" .
			"--{$boundary}\r\n" .
			"Content-Type: text/plain\r\n\r\n" .
			"Only plain text\r\n" .
			"--{$boundary}--";

		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertEquals( '', $result );
	}

	public function test_extract_html_boundary_without_quotes() {
		$boundary = 'unquoted_boundary';
		$raw = "Content-Type: multipart/alternative; boundary={$boundary}\r\n\r\n" .
			"--{$boundary}\r\n" .
			"Content-Type: text/html\r\n\r\n" .
			"<p>Unquoted boundary</p>\r\n" .
			"--{$boundary}--";

		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertStringContainsString( '<p>Unquoted boundary</p>', $result );
	}

	// --- IMAP search escaping ---

	public function test_imap_search_escapes_sender_quotes() {
		$GLOBALS['in_imap_tag_counter'] = 0;

		// A sender address containing a double-quote that could cause IMAP injection.
		$settings = array(
			'filter_by_sender'  => true,
			'qualified_senders' => 'evil"@example.com',
		);

		// Create socket pair to capture the command sent to the server.
		$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
		fwrite( $pair[1], "* SEARCH \r\nA0001 OK SEARCH completed\r\n" );

		indivisible_newsletter_imap_search_uids( $pair[0], $settings );

		// Read what was sent.
		stream_set_blocking( $pair[1], false );
		$sent = fread( $pair[1], 1024 ) ?: '';

		// The quote must be escaped with a backslash.
		$this->assertStringContainsString( 'SEARCH FROM "evil\\"@example.com"', $sent );

		fclose( $pair[0] );
		fclose( $pair[1] );
	}

	// --- Debug temp file leak ---

	public function test_fetch_process_message_no_temp_files() {
		$GLOBALS['in_imap_tag_counter']   = 0;
		$GLOBALS['in_imap_fetch_counter'] = 1000;

		$uid      = 99999;
		$tmp_file = '/tmp/newsletter_debug_msg_' . $uid . '.txt';

		// Clean up from any previous test run.
		if ( file_exists( $tmp_file ) ) {
			unlink( $tmp_file );
		}

		$raw_header  = "From: sender@example.com\r\nSubject: Temp File Test\r\nMessage-ID: <temp-test@example.com>\r\n";
		$header_size = strlen( $raw_header );

		$full_body = "From: sender@example.com\r\nSubject: Temp File Test\r\n" .
			"Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 7bit\r\n\r\n" .
			'<p>Test content</p>';
		$body_size = strlen( $full_body );

		$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );

		// Header fetch response.
		$script  = "* {$uid} FETCH (BODY[HEADER] {{$header_size}}\r\n";
		$script .= $raw_header;
		$script .= ")\r\nF1001 OK FETCH completed\r\n";
		// Body fetch response.
		$script .= "* {$uid} FETCH (BODY[] {{$body_size}}\r\n";
		$script .= $full_body;
		$script .= ")\r\nF1002 OK FETCH completed\r\n";
		// STORE response.
		$script .= "A0001 OK STORE completed\r\n";

		fwrite( $pair[1], $script );

		$emails = array();
		indivisible_newsletter_fetch_process_message( $pair[0], $uid, array(), $emails );

		// No debug temp file should have been written.
		$this->assertFileDoesNotExist( $tmp_file );

		// Clean up just in case.
		if ( file_exists( $tmp_file ) ) {
			unlink( $tmp_file );
		}

		fclose( $pair[0] );
		fclose( $pair[1] );
	}

	public function test_extract_html_folded_content_type_header() {
		$raw = "Content-Type: multipart/alternative;\r\n\tboundary=\"folded-boundary\"\r\n\r\n" .
			"--folded-boundary\r\n" .
			"Content-Type: text/html\r\n\r\n" .
			"<p>Folded header</p>\r\n" .
			"--folded-boundary--";

		$result = indivisible_newsletter_extract_html_from_raw( $raw );
		$this->assertStringContainsString( '<p>Folded header</p>', $result );
	}
}
