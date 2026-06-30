<?php
/**
 * CON10 A3 — a failed webmaster notification is surfaced instead of M-NONE.
 *
 * indivisible_newsletter_notify_webmaster() now returns the wp_mail() result,
 * and indivisible_newsletter_create_post_from_email() records a send failure
 * into a caller-provided accumulator so process_emails() can aggregate it and
 * Check Now can surface it (see Test_Check_Now_Feedback for the surfacing).
 *
 * @package Indivisible_Newsletter
 */

class Test_Notify_Webmaster_Result extends IN_Test_Case {

	private const WEBMASTER_EMAIL = 'admin@example.com';

	/** @var bool Controls the pre_wp_mail short-circuit return for the current test. */
	private $mail_succeeds = true;

	public function setUp(): void {
		parent::setUp();
		delete_option( IN_OPTION_KEY );
		$this->mail_succeeds = true;
		add_filter( 'pre_wp_mail', array( $this, 'short_circuit_mail' ), 10, 2 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'short_circuit_mail' ) );
		delete_option( IN_OPTION_KEY );
		delete_option( IN_PROCESSED_KEY );
		parent::tearDown();
	}

	public function short_circuit_mail( $null, $atts ) {
		// Returning a non-null value short-circuits wp_mail(): true = "sent",
		// false = send failure. Mirrors what a real MTA failure surfaces.
		return $this->mail_succeeds;
	}

	// --- notify_webmaster() return value ---

	public function test_notify_returns_true_on_successful_send(): void {
		$settings = array( 'post_status' => 'draft', 'webmaster_email' => self::WEBMASTER_EMAIL );
		$post_id  = $this->factory->post->create();

		$this->assertTrue( indivisible_newsletter_notify_webmaster( $post_id, 'Title', $settings ) );
	}

	public function test_notify_returns_false_on_send_failure(): void {
		$this->mail_succeeds = false;
		$settings = array( 'post_status' => 'draft', 'webmaster_email' => self::WEBMASTER_EMAIL );
		$post_id  = $this->factory->post->create();

		$this->assertFalse( indivisible_newsletter_notify_webmaster( $post_id, 'Title', $settings ) );
	}

	public function test_notify_returns_true_when_no_webmaster_email(): void {
		// Nothing to send is not a failure.
		$settings = array( 'post_status' => 'draft', 'webmaster_email' => '' );
		$post_id  = $this->factory->post->create();

		$this->assertTrue( indivisible_newsletter_notify_webmaster( $post_id, 'Title', $settings ) );
	}

	// --- create_post_from_email records notify failures into the accumulator ---

	public function test_create_post_records_notify_failure(): void {
		$this->mail_succeeds = false;
		update_option( IN_OPTION_KEY, array(
			'post_status'     => 'draft',
			'post_category'   => 0,
			'webmaster_email' => self::WEBMASTER_EMAIL,
		) );

		$notify_failures = array();
		$post_id = indivisible_newsletter_create_post_from_email(
			array( 'subject' => 'Boom', 'html' => '<p>x</p>', 'date' => '2026-02-17', 'message_id' => 'n1' ),
			$notify_failures
		);

		$this->assertIsInt( $post_id, 'The post is still created even when notification fails.' );
		$this->assertNotEmpty( $notify_failures, 'A failed wp_mail() must be recorded, not swallowed (M-NONE).' );
	}

	public function test_create_post_no_notify_failure_on_success(): void {
		update_option( IN_OPTION_KEY, array(
			'post_status'     => 'draft',
			'post_category'   => 0,
			'webmaster_email' => self::WEBMASTER_EMAIL,
		) );

		$notify_failures = array();
		indivisible_newsletter_create_post_from_email(
			array( 'subject' => 'OK', 'html' => '<p>x</p>', 'date' => '2026-02-17', 'message_id' => 'n2' ),
			$notify_failures
		);

		$this->assertEmpty( $notify_failures );
	}
}
