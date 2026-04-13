<?php
/**
 * Tests for the newsletter reprocess feature.
 *
 * @package Indivisible_Newsletter
 */

class Test_IN_Reprocess extends IN_Test_Case {

    public function test_reprocess_function_exists(): void {
        $this->assertTrue(
            function_exists( 'indivisible_newsletter_reprocess_post' ),
            'indivisible_newsletter_reprocess_post() must be defined'
        );
    }
}
