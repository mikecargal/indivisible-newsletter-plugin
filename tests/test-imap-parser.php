<?php
/**
 * Tests for indivisible_newsletter_imap_parse_search_uids().
 *
 * @package Indivisible_Newsletter
 */
class Test_IN_Imap_Parser extends WP_UnitTestCase {

	public function test_parses_single_search_line(): void {
		$result = indivisible_newsletter_imap_parse_search_uids( array(
			'* SEARCH 42',
		) );
		$this->assertSame( array( 42 ), $result );
	}

	public function test_parses_multiple_uids_on_one_line(): void {
		$result = indivisible_newsletter_imap_parse_search_uids( array(
			'* SEARCH 10 20 30',
		) );
		$this->assertSame( array( 10, 20, 30 ), $result );
	}

	public function test_parses_multiple_search_lines(): void {
		$result = indivisible_newsletter_imap_parse_search_uids( array(
			'* SEARCH 10 20',
			'* SEARCH 30',
		) );
		$this->assertSame( array( 10, 20, 30 ), $result );
	}

	public function test_handles_extra_whitespace_between_uids(): void {
		$result = indivisible_newsletter_imap_parse_search_uids( array(
			'* SEARCH  10   20  ',
		) );
		$this->assertSame( array( 10, 20 ), $result );
	}

	public function test_empty_search_line_returns_empty(): void {
		$result = indivisible_newsletter_imap_parse_search_uids( array(
			'* SEARCH ',
		) );
		$this->assertSame( array(), $result );
	}

	public function test_ignores_non_search_lines(): void {
		$result = indivisible_newsletter_imap_parse_search_uids( array(
			'* OK [UIDNEXT 100]',
			'* SEARCH 42',
			'A001 OK SEARCH completed',
		) );
		$this->assertSame( array( 42 ), $result );
	}

	public function test_empty_input_returns_empty(): void {
		$result = indivisible_newsletter_imap_parse_search_uids( array() );
		$this->assertSame( array(), $result );
	}

	public function test_case_insensitive_search_keyword(): void {
		$result = indivisible_newsletter_imap_parse_search_uids( array(
			'* search 42 43',
		) );
		$this->assertSame( array( 42, 43 ), $result );
	}

	// --- imap_build_fetch_cmd ---

	public function test_build_fetch_cmd_header_section(): void {
		$cmd = indivisible_newsletter_imap_build_fetch_cmd( 'A001', 5, 'HEADER' );
		$this->assertSame( "A001 FETCH 5 BODY[HEADER]\r\n", $cmd );
	}

	public function test_build_fetch_cmd_empty_section_fetches_full_body(): void {
		$cmd = indivisible_newsletter_imap_build_fetch_cmd( 'A002', 10, '' );
		$this->assertSame( "A002 FETCH 10 BODY[]\r\n", $cmd );
	}

	public function test_build_fetch_cmd_named_section(): void {
		$cmd = indivisible_newsletter_imap_build_fetch_cmd( 'A003', 7, '1.2' );
		$this->assertSame( "A003 FETCH 7 BODY[1.2]\r\n", $cmd );
	}

	// --- imap_read_literal ---

	public function test_read_literal_reads_exact_bytes(): void {
		$stream = fopen( 'php://memory', 'r+' );
		fwrite( $stream, 'Hello, World!' );
		rewind( $stream );

		$result = indivisible_newsletter_imap_read_literal( $stream, 5 );
		$this->assertSame( 'Hello', $result );

		fclose( $stream );
	}

	public function test_read_literal_reads_full_content(): void {
		$stream = fopen( 'php://memory', 'r+' );
		$data   = str_repeat( 'A', 10000 );
		fwrite( $stream, $data );
		rewind( $stream );

		$result = indivisible_newsletter_imap_read_literal( $stream, 10000 );
		$this->assertSame( $data, $result );

		fclose( $stream );
	}

	public function test_read_literal_returns_false_on_empty_stream(): void {
		$stream = fopen( 'php://memory', 'r+' );
		// Don't write anything — stream is empty.

		$result = indivisible_newsletter_imap_read_literal( $stream, 100 );
		$this->assertFalse( $result );

		fclose( $stream );
	}
}
