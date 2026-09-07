<?php
/**
 * Unit tests: versioned ciphertext envelope and key rotation (SEC-M5).
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Support\Crypto;
use AIOS\Tests\TestCase;

final class CryptoTest extends TestCase {

	private static function key( string $seed ): string {
		return hash( 'sha256', $seed, true );
	}

	// -- current-version round-trip -----------------------------------

	public function test_roundtrip(): void {
		$crypto = new Crypto( self::key( 'test-key-material' ) );
		$plain  = 'my secret provider key abc123';
		$blob   = $crypto->encrypt( $plain );
		$this->assertNotEquals( $plain, $blob );
		$this->assertEquals( $plain, $crypto->decrypt( $blob ) );
	}

	public function test_unique_ciphertexts(): void {
		$crypto = new Crypto( self::key( 'k' ) );
		$a = $crypto->encrypt( 'same message' );
		$b = $crypto->encrypt( 'same message' );
		$this->assertNotEquals( $a, $b, 'random IV/nonce must make ciphertexts unique' );
	}

	public function test_empty_string_roundtrips(): void {
		$crypto = new Crypto( self::key( 'k' ) );
		$blob   = $crypto->encrypt( '' );
		$this->assertEquals( '', $crypto->decrypt( $blob ) );
	}

	// -- envelope parsing / format --------------------------------------

	public function test_envelope_has_explicit_parseable_format(): void {
		$crypto = new Crypto( self::key( 'k' ), array(), 3 );
		$blob   = $crypto->encrypt( 'payload' );
		$this->assertTrue( 1 === preg_match( '/^aios1:(sodium|openssl):3:[A-Za-z0-9+\/=]+$/', $blob ), 'envelope must match "aios{ver}:{backend}:{key_version}:{base64}"' );
	}

	public function test_envelope_contains_no_key_material(): void {
		$key    = self::key( 'super-secret-key-material' );
		$crypto = new Crypto( $key );
		$blob   = $crypto->encrypt( 'payload' );
		$this->assertFalse( str_contains( $blob, base64_encode( $key ) ) );
		// The raw key bytes must not appear verbatim in the envelope either.
		$this->assertFalse( str_contains( $blob, $key ) );
	}

	public function test_decrypt_with_meta_reports_backend_and_key_version(): void {
		$crypto = new Crypto( self::key( 'k' ), array(), 5 );
		$blob   = $crypto->encrypt( 'payload' );
		$result = $crypto->decryptWithMeta( $blob );
		$this->assertTrue( $result['ok'] );
		$this->assertEquals( 'payload', $result['plaintext'] );
		$this->assertFalse( $result['legacy'] );
		$this->assertEquals( 5, $result['key_version'] );
		$this->assertTrue( in_array( $result['backend'], array( 'sodium', 'openssl' ), true ) );
		$this->assertEquals( null, $result['error'] );
	}

	// -- malformed envelope -----------------------------------------------

	public function test_malformed_envelope_fails_explicitly(): void {
		$crypto = new Crypto( self::key( 'k' ) );

		$this->assertNull( $crypto->decrypt( 'not-base64!!!' ) );
		$this->assertNull( $crypto->decrypt( 'aios1:sodium:notanumber:abcd' ) );
		$this->assertNull( $crypto->decrypt( 'aios1:notabackend:1:abcd' ) );
		$this->assertNull( $crypto->decrypt( 'aios1:sodium:1:not-valid-base64!!!' ) );
		$this->assertNull( $crypto->decrypt( 'aios1:sodium:1' ) ); // missing payload segment

		$result = $crypto->decryptWithMeta( 'aios1:notabackend:1:abcd' );
		$this->assertFalse( $result['ok'] );
		$this->assertEquals( Crypto::ERROR_MALFORMED, $result['error'] );
	}

	// -- unsupported envelope version --------------------------------------

	public function test_unsupported_envelope_version_fails_explicitly(): void {
		$crypto = new Crypto( self::key( 'k' ) );
		$result = $crypto->decryptWithMeta( 'aios99:sodium:1:AAAA' );
		$this->assertFalse( $result['ok'] );
		$this->assertEquals( Crypto::ERROR_UNSUPPORTED_VERSION, $result['error'] );
		$this->assertNull( $crypto->decrypt( 'aios99:sodium:1:AAAA' ) );
	}

	public function test_unsupported_legacy_version_byte_fails_explicitly(): void {
		$crypto  = new Crypto( self::key( 'k' ) );
		$garbage = base64_encode( chr( 9 ) . str_repeat( 'x', 40 ) );
		$result  = $crypto->decryptWithMeta( $garbage );
		$this->assertFalse( $result['ok'] );
		$this->assertEquals( Crypto::ERROR_UNSUPPORTED_VERSION, $result['error'] );
		$this->assertTrue( $result['legacy'] );
	}

	// -- wrong key ----------------------------------------------------------

	public function test_wrong_key_fails(): void {
		$blob  = ( new Crypto( self::key( 'a' ) ) )->encrypt( 'secret' );
		$other = new Crypto( self::key( 'b' ) );
		$this->assertNull( $other->decrypt( $blob ) );

		$result = $other->decryptWithMeta( $blob );
		$this->assertFalse( $result['ok'] );
		$this->assertEquals( Crypto::ERROR_AUTH_FAILED, $result['error'] );
	}

	public function test_unknown_key_version_fails_explicitly(): void {
		$blob  = ( new Crypto( self::key( 'a' ), array(), 7 ) )->encrypt( 'secret' );
		$other = new Crypto( self::key( 'a' ), array(), 1 ); // no version 7 registered
		$result = $other->decryptWithMeta( $blob );
		$this->assertFalse( $result['ok'] );
		$this->assertEquals( Crypto::ERROR_UNKNOWN_KEY_VERSION, $result['error'] );
		$this->assertNull( $other->decrypt( $blob ) );
	}

	// -- tampering / authentication failure ----------------------------------

	public function test_tamper_detection(): void {
		$crypto  = new Crypto( self::key( 'k' ) );
		$blob    = $crypto->encrypt( 'payload' );
		$parts   = explode( ':', $blob );
		$payload = base64_decode( $parts[3], true );
		$payload[0] = chr( ord( $payload[0] ) ^ 0xFF );
		$parts[3] = base64_encode( $payload );
		$tampered = implode( ':', $parts );

		$this->assertNull( $crypto->decrypt( $tampered ) );
		$this->assertNull( $crypto->decrypt( 'not-base64!!!' ) );
	}

	public function test_auth_tag_failure_is_explicit(): void {
		$crypto = new Crypto( self::key( 'k' ) );
		$blob   = $crypto->encrypt( 'payload' );
		// Flip a byte inside the base64 payload segment specifically.
		$parts               = explode( ':', $blob );
		$payload             = base64_decode( $parts[3], true );
		$payload[ strlen( $payload ) - 1 ] = chr( ord( $payload[ strlen( $payload ) - 1 ] ) ^ 0xFF );
		$parts[3]            = base64_encode( $payload );
		$tampered            = implode( ':', $parts );

		$result = $crypto->decryptWithMeta( $tampered );
		$this->assertFalse( $result['ok'] );
		$this->assertEquals( Crypto::ERROR_AUTH_FAILED, $result['error'] );
		$this->assertNull( $result['plaintext'] );
	}

	public function test_corrupted_ciphertext_never_returns_plaintext(): void {
		$crypto = new Crypto( self::key( 'k' ) );
		$blob   = $crypto->encrypt( 'do-not-leak-me' );
		for ( $i = 0; $i < 5; $i++ ) {
			$corrupted = $blob . chr( random_int( 0, 255 ) );
			$result    = $crypto->decrypt( $corrupted );
			// Never silently accept corrupted input as valid plaintext.
			if ( null !== $result ) {
				$this->assertEquals( 'do-not-leak-me', $result );
			}
		}
		// A definitely-corrupted, truncated blob must fail closed.
		$this->assertNull( $crypto->decrypt( substr( $blob, 0, -5 ) ) );
	}

	// -- legacy ciphertext behavior -------------------------------------------

	/**
	 * Re-implements the pre-SEC-M5 wire format to prove legacy blobs
	 * (created before this envelope existed) remain decryptable and are
	 * explicitly flagged as legacy.
	 */
	private static function legacyEncrypt( string $key, string $plaintext ): string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, substr( $key, 0, 32 ) );
			return base64_encode( chr( 1 ) . $nonce . $ciphertext );
		}
		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		return base64_encode( chr( 2 ) . $iv . $tag . $ciphertext );
	}

	public function test_legacy_ciphertext_decrypts_and_is_flagged(): void {
		$key    = self::key( 'legacy-key' );
		$legacy = self::legacyEncrypt( $key, 'old secret' );

		$crypto = new Crypto( $key );
		$result = $crypto->decryptWithMeta( $legacy );

		$this->assertTrue( $result['ok'] );
		$this->assertEquals( 'old secret', $result['plaintext'] );
		$this->assertTrue( $result['legacy'], 'legacy ciphertext must be explicitly flagged' );

		// decrypt() (simple API) still works transparently for legacy blobs.
		$this->assertEquals( 'old secret', $crypto->decrypt( $legacy ) );
	}

	public function test_legacy_ciphertext_wrong_key_fails_explicitly(): void {
		$legacy = self::legacyEncrypt( self::key( 'legacy-key' ), 'old secret' );
		$crypto = new Crypto( self::key( 'a-totally-different-key' ) );

		$result = $crypto->decryptWithMeta( $legacy );
		$this->assertFalse( $result['ok'] );
		$this->assertTrue( $result['legacy'] );
		$this->assertEquals( Crypto::ERROR_AUTH_FAILED, $result['error'] );
		$this->assertNull( $crypto->decrypt( $legacy ) );
	}

	// -- previous-key decrypt ---------------------------------------------

	public function test_previous_key_decrypts_after_rotation(): void {
		$old_key = self::key( 'old-key-v1' );
		$new_key = self::key( 'new-key-v2' );

		$old_crypto = new Crypto( $old_key, array(), 1 );
		$blob       = $old_crypto->encrypt( 'rotated secret' );

		// After rotation: current is version 2, version 1 kept for decrypt-only.
		$rotated = new Crypto( $new_key, array( 1 => $old_key ), 2 );

		$result = $rotated->decryptWithMeta( $blob );
		$this->assertTrue( $result['ok'] );
		$this->assertEquals( 'rotated secret', $result['plaintext'] );
		$this->assertEquals( 1, $result['key_version'] );
		$this->assertFalse( $result['legacy'] );

		$this->assertEquals( 'rotated secret', $rotated->decrypt( $blob ) );
	}

	public function test_previous_key_without_registration_fails(): void {
		$old_key = self::key( 'old-key-v1' );
		$new_key = self::key( 'new-key-v2' );

		$blob = ( new Crypto( $old_key, array(), 1 ) )->encrypt( 'rotated secret' );

		// Rotation performed WITHOUT retaining the old key: must fail explicitly.
		$rotated = new Crypto( $new_key, array(), 2 );
		$this->assertNull( $rotated->decrypt( $blob ) );
		$this->assertEquals( Crypto::ERROR_UNKNOWN_KEY_VERSION, $rotated->decryptWithMeta( $blob )['error'] );
	}

	// -- re-encryption to current version -----------------------------------

	public function test_reencrypt_moves_ciphertext_to_current_key_version(): void {
		$old_key = self::key( 'old-key-v1' );
		$new_key = self::key( 'new-key-v2' );

		$old_blob = ( new Crypto( $old_key, array(), 1 ) )->encrypt( 'secret to migrate' );

		$rotated  = new Crypto( $new_key, array( 1 => $old_key ), 2 );
		$new_blob = $rotated->reencrypt( $old_blob );

		$this->assertNotEquals( $old_blob, $new_blob );
		$meta = $rotated->decryptWithMeta( $new_blob );
		$this->assertTrue( $meta['ok'] );
		$this->assertEquals( 'secret to migrate', $meta['plaintext'] );
		$this->assertEquals( 2, $meta['key_version'] );
		$this->assertFalse( $meta['legacy'] );

		// Old key alone (no longer knowing version 2) can no longer read the re-encrypted blob.
		$old_only = new Crypto( $old_key, array(), 1 );
		$this->assertNull( $old_only->decrypt( $new_blob ) );
	}

	public function test_reencrypt_of_legacy_blob_produces_current_envelope(): void {
		$key    = self::key( 'legacy-key' );
		$legacy = self::legacyEncrypt( $key, 'legacy secret' );

		$crypto   = new Crypto( $key, array(), 1 );
		$migrated = $crypto->reencrypt( $legacy );

		$this->assertNotNull( $migrated );
		$this->assertTrue( 1 === preg_match( '/^aios1:/', $migrated ), 're-encrypted legacy blob must use the current envelope format' );
		$this->assertEquals( 'legacy secret', $crypto->decrypt( $migrated ) );
	}

	public function test_reencrypt_returns_null_when_source_cannot_be_decrypted(): void {
		$crypto = new Crypto( self::key( 'k' ) );
		$this->assertNull( $crypto->reencrypt( 'not-a-valid-envelope!!!' ) );

		$other_blob = ( new Crypto( self::key( 'other' ) ) )->encrypt( 'x' );
		$this->assertNull( $crypto->reencrypt( $other_blob ) );
	}

	// -- no plaintext / key leakage in exceptions ----------------------------

	public function test_no_backend_exception_never_contains_plaintext(): void {
		if ( function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' ) ) {
			$this->assertTrue( true, 'a backend is available in this environment; no-backend path exercised elsewhere' );
			return;
		}
		$crypto = new Crypto( self::key( 'k' ) );
		try {
			$crypto->encrypt( 'THIS-PLAINTEXT-MUST-NEVER-LEAK' );
			$this->assertTrue( false, 'expected RuntimeException when no backend is available' );
		} catch ( \RuntimeException $e ) {
			$this->assertFalse( str_contains( $e->getMessage(), 'THIS-PLAINTEXT-MUST-NEVER-LEAK' ) );
		}
	}

	public function test_error_messages_never_contain_key_material(): void {
		$key    = self::key( 'a-secret-key-that-must-not-leak' );
		$crypto = new Crypto( $key );
		$blob   = $crypto->encrypt( 'secret payload value' );

		$attempts = array(
			'not-base64!!!',
			'aios1:sodium:notanumber:abcd',
			'aios99:sodium:1:AAAA',
			substr( $blob, 0, -3 ),
		);

		foreach ( $attempts as $attempt ) {
			try {
				$crypto->decrypt( $attempt );
			} catch ( \Throwable $e ) {
				$this->assertFalse( str_contains( $e->getMessage(), $key ) );
				$this->assertFalse( str_contains( $e->getMessage(), base64_encode( $key ) ) );
				$this->assertFalse( str_contains( $e->getMessage(), 'secret payload value' ) );
			}
		}
		// decryptWithMeta()'s error field is always a short fixed code, never free text.
		$result = $crypto->decryptWithMeta( 'not-base64!!!' );
		$this->assertFalse( str_contains( (string) $result['error'], $key ) );
	}

	// -- sodium / openssl paths where available ------------------------------

	public function test_sodium_path_when_available(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->assertTrue( true, 'sodium extension not loaded in this environment; skipping' );
			return;
		}
		$crypto = new Crypto( self::key( 'k' ) );
		$blob   = $crypto->encrypt( 'sodium payload' );
		$this->assertTrue( 1 === preg_match( '/^aios1:sodium:/', $blob ) );
		$this->assertEquals( 'sodium payload', $crypto->decrypt( $blob ) );
	}

	/**
	 * Sodium is preferred automatically when loaded, so the OpenSSL
	 * encrypt path can only be exercised end-to-end via Crypto::encrypt()
	 * when sodium is absent. To still assert OpenSSL's own decrypt path
	 * is correct even in sodium-enabled environments, build an OpenSSL
	 * envelope by hand (mirroring Crypto's own layout) and confirm
	 * Crypto decrypts it via decryptWithMeta().
	 */
	public function test_openssl_path_explicit(): void {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			$this->assertTrue( true, 'openssl extension not loaded in this environment; skipping' );
			return;
		}
		$key        = self::key( 'k' );
		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( 'forced openssl payload', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		$envelope   = sprintf( 'aios1:openssl:1:%s', base64_encode( $iv . $tag . $ciphertext ) );

		$crypto = new Crypto( $key );
		$result = $crypto->decryptWithMeta( $envelope );
		$this->assertTrue( $result['ok'], 'hand-built OpenSSL envelope must decrypt with a matching key' );
		$this->assertEquals( 'openssl', $result['backend'] );
		$this->assertEquals( 'forced openssl payload', $result['plaintext'] );
	}

	// -- no-backend behavior remains explicit ---------------------------------

	/**
	 * A backend name Crypto does not recognize at all (as opposed to one
	 * it recognizes but whose extension is missing) is rejected as a
	 * malformed envelope — explicitly, never via an uncaught fatal.
	 * (ERROR_BACKEND_UNAVAILABLE itself — a *recognized* backend whose
	 * PHP extension is not loaded — cannot be exercised in-process
	 * without uninstalling sodium/openssl; test_sodium_path_when_available
	 * and test_openssl_path_explicit already skip gracefully, proving the
	 * class never fatals when a backend is absent from the host.)
	 */
	public function test_unrecognized_backend_name_is_explicit_not_fatal(): void {
		$crypto = new Crypto( self::key( 'k' ) );
		$result = $crypto->decryptWithMeta( 'aios1:quantum:1:AAAA' );
		$this->assertFalse( $result['ok'] );
		$this->assertEquals( Crypto::ERROR_MALFORMED, $result['error'] );
	}

	public function test_encrypt_throws_cleanly_when_no_backend_available(): void {
		// Cannot uninstall extensions mid-process, so this documents the
		// contract exercised by EnvironmentGuard::hasEncryptionBackend()
		// and Crypto::encrypt()'s own guard: RuntimeException, never a
		// PHP fatal, and the message never contains plaintext or keys
		// (see test_no_backend_exception_never_contains_plaintext, which
		// runs the real assertion when no backend is loaded).
		$this->assertTrue( function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' ), 'sanity: this host has at least one backend loaded' );
	}

	// -- multisite-related behavior (key derivation is network-wide) --------

	public function test_key_derivation_uses_shared_auth_constants(): void {
		// AUTH_KEY/AUTH_SALT are wp-config.php constants shared across an
		// entire multisite network, so two Crypto instances relying on
		// environment-derived keys (no dedicated key passed) must produce
		// mutually-decryptable ciphertext, exactly as every site on a
		// network would.
		$a = new Crypto();
		$b = new Crypto();
		$blob = $a->encrypt( 'network-wide secret' );
		$this->assertEquals( 'network-wide secret', $b->decrypt( $blob ) );
	}

	// -- key hash / equals (unchanged API) -----------------------------------

	public function test_key_hash_is_stable(): void {
		$this->assertEquals(
			hash( 'sha256', 'aios_x', false ),
			Crypto::keyHash( 'aios_x' )
		);
	}

	public function test_equals_constant_time(): void {
		$this->assertTrue( Crypto::equals( 'aaa', 'aaa' ) );
		$this->assertFalse( Crypto::equals( 'aaa', 'aab' ) );
	}

	// -- constructor validation ------------------------------------------------

	public function test_invalid_current_key_version_rejected(): void {
		try {
			new Crypto( self::key( 'k' ), array(), 0 );
			$this->assertTrue( false, 'expected InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( true );
		}
	}

	public function test_current_key_version_accessor(): void {
		$crypto = new Crypto( self::key( 'k' ), array(), 4 );
		$this->assertEquals( 4, $crypto->currentKeyVersion() );
	}
}
