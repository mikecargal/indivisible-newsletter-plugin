<?php
/**
 * Tests for Indivisible Newsletter cron scheduling.
 */
class Test_IN_Cron extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		indivisible_newsletter_clear_cron();
	}

	public function tearDown(): void {
		indivisible_newsletter_clear_cron();
		parent::tearDown();
	}

	// --- Custom cron schedules ---

	public function test_cron_schedules_adds_five_minutes() {
		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( 'five_minutes', $schedules );
		$this->assertEquals( 300, $schedules['five_minutes']['interval'] );
	}

	public function test_cron_schedules_adds_fifteen_minutes() {
		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( 'fifteen_minutes', $schedules );
		$this->assertEquals( 900, $schedules['fifteen_minutes']['interval'] );
	}

	public function test_cron_schedules_adds_thirty_minutes() {
		$schedules = wp_get_schedules();
		$this->assertArrayHasKey( 'thirty_minutes', $schedules );
		$this->assertEquals( 1800, $schedules['thirty_minutes']['interval'] );
	}

	public function test_cron_schedules_preserves_existing() {
		$schedules = wp_get_schedules();
		// WordPress built-in schedules should still exist.
		$this->assertArrayHasKey( 'hourly', $schedules );
		$this->assertArrayHasKey( 'daily', $schedules );
	}

	// --- Schedule and clear ---

	public function test_schedule_cron_creates_event() {
		indivisible_newsletter_schedule_cron( 'hourly' );
		$timestamp = wp_next_scheduled( IN_CRON_HOOK );
		$this->assertNotFalse( $timestamp );
	}

	public function test_schedule_cron_does_not_duplicate() {
		indivisible_newsletter_schedule_cron( 'hourly' );
		$first = wp_next_scheduled( IN_CRON_HOOK );

		indivisible_newsletter_schedule_cron( 'hourly' );
		$second = wp_next_scheduled( IN_CRON_HOOK );

		// Should be the same event, not duplicated.
		$this->assertEquals( $first, $second );
	}

	public function test_schedule_cron_uses_settings_when_no_argument() {
		update_option( IN_OPTION_KEY, array( 'check_interval' => 'daily' ) );

		indivisible_newsletter_schedule_cron();
		$this->assertNotFalse( wp_next_scheduled( IN_CRON_HOOK ) );
	}

	public function test_clear_cron_removes_event() {
		indivisible_newsletter_schedule_cron( 'hourly' );
		$this->assertNotFalse( wp_next_scheduled( IN_CRON_HOOK ) );

		indivisible_newsletter_clear_cron();
		$this->assertFalse( wp_next_scheduled( IN_CRON_HOOK ) );
	}

	public function test_clear_cron_no_error_when_not_scheduled() {
		// Should not throw error when no event exists.
		indivisible_newsletter_clear_cron();
		$this->assertFalse( wp_next_scheduled( IN_CRON_HOOK ) );
	}

	// --- Cron callback ---

	public function test_cron_hook_is_registered() {
		$this->assertNotFalse( has_action( IN_CRON_HOOK, 'indivisible_newsletter_cron_callback' ) );
	}
}
