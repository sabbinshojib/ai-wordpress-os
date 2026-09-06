<?php
/**
 * Unit tests: secret encryption at rest (Crypto).
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Support\Crypto;
use AIOS\Tests\TestCase;

final class CryptoTest extends TestCase {

	public function test_roundtrip(): void {
		$crypto = new Crypto( hash( 'sha256', 'test-key-material', true ) );
		$plain  = 'my secret provider key abc123';
		$blob   = $crypto->encrypt( $plain );
		$this->assertNotEquals( $plain, $blob );
		$this->assertEquals( $plain, $crypto->decrypt( $blob ) );
	}

	public function test_unique_ciphertexts(): void {
		$crypto = new Crypto( hash( 'sha256', 'k', true ) );
		$a = $crypto->encrypt( 'same message' );
		$b = $crypto->encrypt( 'same message' );
		$this->assertNotEquals( $a, $b, 'random IV/nonce must make ciphertexts unique' );
	}

	public function test_tamper_detection(): void {
		$crypto = new Crypto( hash( 'sha256', 'k', true ) );
		$blob   = $crypto->encrypt( 'payload' );
		$tampered = base64_encode( 'zzz' . base64_decode( $blob ) );
		$this->assertNull( $crypto->decrypt( $tampered ) );
		$this->assertNull( $crypto->decrypt( 'not-base64!!!' ) );
	}

	public function test_wrong_key_fails(): void {
		$blob = ( new Crypto( hash( 'sha256', 'a', true ) ) )->encrypt( 'secret' );
		$other = new Crypto( hash( 'sha256', 'b', true ) );
		$this->assertNull( $other->decrypt( $blob ) );
	}

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
}
