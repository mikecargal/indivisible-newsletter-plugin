<?php
/**
 * CON10 Foundation — the newsletter plugin declares and gates on its
 * indivisible-shared (CON6 canonical-feedback) dependency, so the .ids-alert
 * family (ids_render_alert PHP, IDS.confirmModal JS, .ids-alert CSS) is never
 * silently missing on the settings page.
 *
 * Models the IEC dependency-guard pattern (iec_check_design_system_dependency):
 * a required-version constant + an admin_init check that surfaces an admin
 * notice when the design system is absent or too old.
 *
 * @package Indivisible_Newsletter
 */

class Test_Admin_Deps extends IN_Test_Case {

	public function test_required_ids_version_is_defined(): void {
		$this->assertTrue(
			defined( 'IN_REQUIRED_IDS_VERSION' ),
			'IN_REQUIRED_IDS_VERSION must be defined so the plugin can gate on the canonical-feedback (CON6) design-system floor.'
		);
		$this->assertIsString( IN_REQUIRED_IDS_VERSION );
		$this->assertTrue(
			version_compare( IN_REQUIRED_IDS_VERSION, '0.0.0', '>' ),
			'IN_REQUIRED_IDS_VERSION must be a usable version string.'
		);
	}

	public function test_live_design_system_satisfies_required_version(): void {
		$this->assertTrue(
			defined( 'IDS_VERSION' ),
			'indivisible-shared (IDS_VERSION) must be loaded in the test env — see tests/bootstrap.php.'
		);
		$this->assertTrue(
			indivisible_newsletter_ids_version_satisfied( IDS_VERSION, IN_REQUIRED_IDS_VERSION ),
			sprintf(
				'Live IDS_VERSION (%s) must satisfy IN_REQUIRED_IDS_VERSION (%s); pick a floor the deployed design system meets.',
				IDS_VERSION,
				IN_REQUIRED_IDS_VERSION
			)
		);
	}

	/**
	 * The version gate is pure so it is unit-testable without un-defining the
	 * live IDS_VERSION constant: null current (design system absent) and an
	 * out-of-date current both fail; exact and newer pass.
	 *
	 * @dataProvider version_cases
	 */
	public function test_version_satisfied_logic( ?string $current, string $required, bool $expected ): void {
		$this->assertSame(
			$expected,
			indivisible_newsletter_ids_version_satisfied( $current, $required )
		);
	}

	public function version_cases(): array {
		return array(
			'design system absent (null)' => array( null, '3.0.0', false ),
			'too old'                     => array( '2.9.0', '3.0.0', false ),
			'exact match'                 => array( '3.0.0', '3.0.0', true ),
			'newer'                       => array( '3.4.0', '3.0.0', true ),
		);
	}

	public function test_dependency_guard_is_registered_on_admin_init(): void {
		$this->assertTrue(
			function_exists( 'indivisible_newsletter_check_design_system' ),
			'A dependency guard function must exist to surface a notice when the design system is missing/outdated.'
		);
		$this->assertNotFalse(
			has_action( 'admin_init', 'indivisible_newsletter_check_design_system' ),
			'The dependency guard must be hooked on admin_init (mirrors IEC iec_check_design_system_dependency).'
		);
	}
}
