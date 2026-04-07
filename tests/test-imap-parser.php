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
}
