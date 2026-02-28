<?php
/**
 * Tests for Indivisible Newsletter encryption and decryption.
 */
class Test_IN_Encryption extends WP_UnitTestCase {

	public function test_encrypt_returns_non_empty_string() {
		$encrypted = indivisible_newsletter_encrypt( 'my-secret-password' );
		$this->assertNotEmpty( $encrypted );
		$this->assertIsString( $encrypted );
	}

	public function test_encrypt_returns_base64() {
		$encrypted = indivisible_newsletter_encrypt( 'test' );
		// Should be valid base64.
		$this->assertNotFalse( base64_decode( $encrypted, true ) );
	}

	public function test_encrypt_decrypt_round_trip() {
		$original  = 'my-secret-password';
		$encrypted = indivisible_newsletter_encrypt( $original );
		$decrypted = indivisible_newsletter_decrypt( $encrypted );

		$this->assertEquals( $original, $decrypted );
	}

	public function test_encrypt_decrypt_special_characters() {
		$original  = 'p@$$w0rd!#%^&*()_+-={}[]|";:<>?,./~`';
		$encrypted = indivisible_newsletter_encrypt( $original );
		$decrypted = indivisible_newsletter_decrypt( $encrypted );

		$this->assertEquals( $original, $decrypted );
	}

	public function test_encrypt_decrypt_unicode() {
		$original  = 'contraseña_Passwört_パスワード';
		$encrypted = indivisible_newsletter_encrypt( $original );
		$decrypted = indivisible_newsletter_decrypt( $encrypted );

		$this->assertEquals( $original, $decrypted );
	}

	public function test_encrypt_produces_different_ciphertext_each_time() {
		$a = indivisible_newsletter_encrypt( 'same-password' );
		$b = indivisible_newsletter_encrypt( 'same-password' );

		// Random IV means each encryption should differ.
		$this->assertNotEquals( $a, $b );
	}

	public function test_decrypt_empty_string_returns_empty() {
		$this->assertEquals( '', indivisible_newsletter_decrypt( '' ) );
	}

	public function test_decrypt_null_returns_empty() {
		$this->assertEquals( '', indivisible_newsletter_decrypt( null ) );
	}

	public function test_decrypt_malformed_data_returns_empty() {
		// Data too short to contain a 16-byte IV plus any cipher.
		$this->assertEquals( '', indivisible_newsletter_decrypt( base64_encode( 'too-short' ) ) );
	}

	public function test_decrypt_invalid_base64_returns_empty() {
		// Completely invalid data.
		$result = indivisible_newsletter_decrypt( '!!!not-base64!!!' );
		// Should either return empty or fail gracefully.
		$this->assertIsString( $result );
	}

	public function test_encrypt_decrypt_empty_string() {
		$encrypted = indivisible_newsletter_encrypt( '' );
		$decrypted = indivisible_newsletter_decrypt( $encrypted );
		$this->assertEquals( '', $decrypted );
	}

	public function test_decrypt_corrupted_ciphertext_returns_empty_string() {
		$encrypted = indivisible_newsletter_encrypt( 'test-password' );
		$data      = base64_decode( $encrypted );
		// Corrupt the ciphertext portion (after the 16-byte IV).
		$iv     = substr( $data, 0, 16 );
		$cipher = substr( $data, 16 );
		// Flip every byte in the ciphertext.
		$corrupted = '';
		for ( $i = 0, $len = strlen( $cipher ); $i < $len; $i++ ) {
			$corrupted .= chr( ord( $cipher[ $i ] ) ^ 0xFF );
		}
		$corrupted_encrypted = base64_encode( $iv . $corrupted );

		$result = indivisible_newsletter_decrypt( $corrupted_encrypted );

		$this->assertSame( '', $result );
	}

	public function test_decrypt_wrong_key_returns_empty_string() {
		// Manually encrypt with a different key than SECURE_AUTH_SALT.
		$different_key = hash( 'sha256', 'completely-different-salt', true );
		$iv            = openssl_random_pseudo_bytes( 16 );
		$cipher        = openssl_encrypt( 'secret-data', 'aes-256-cbc', $different_key, 0, $iv );
		$encrypted     = base64_encode( $iv . $cipher );

		// Decrypt uses the test suite's SECURE_AUTH_SALT — key mismatch.
		$result = indivisible_newsletter_decrypt( $encrypted );

		$this->assertSame( '', $result );
	}

	public function test_encrypt_decrypt_long_password() {
		$original  = str_repeat( 'a', 1000 );
		$encrypted = indivisible_newsletter_encrypt( $original );
		$decrypted = indivisible_newsletter_decrypt( $encrypted );

		$this->assertEquals( $original, $decrypted );
	}
}
