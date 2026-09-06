<?php
/**
 * Unit tests: secret redaction (Sanitize).
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Support\Sanitize;
use AIOS\Tests\TestCase;

final class SanitizeTest extends TestCase {

	public function test_redact_api_keys(): void {
		$dirty  = 'config: api_key=sk1234567890abcdefghij';
		$clean  = Sanitize::redactString( $dirty );
		$this->assertStringNotContains( 'sk1234567890abcdefghij', $clean );
		$this->assertStringContains( 'REDACTED', $clean );
	}

	public function test_redact_bearer_tokens(): void {
		$dirty = 'Authorization: Bearer abcdef1234567890abcdef';
		$clean = Sanitize::redactString( $dirty );
		$this->assertStringNotContains( 'abcdef1234567890abcdef', $clean );
	}

	public function test_redact_db_password_define(): void {
		$dirty = "define( 'DB_PASSWORD', 'sup3rs3cret!' );";
		$clean = Sanitize::redactString( $dirty );
		$this->assertStringNotContains( 'sup3rs3cret', $clean );
	}

	public function test_redact_wp_salts(): void {
		$dirty = "define( 'AUTH_KEY', 'rand0mstring0fchars' );";
		$clean = Sanitize::redactString( $dirty );
		$this->assertStringNotContains( 'rand0mstring0fchars', $clean );
	}

	public function test_redact_nested_structures(): void {
		$dirty = array(
			'body'    => 'the api key is sk-abcdefghijklmnop12345 done',
			'nested'  => array( 'token' => 'value tok_abcdefgh12345678' ),
			'number'  => 5,
		);
		$clean = Sanitize::redact( $dirty );
		$this->assertStringNotContains( 'sk-abcdefghijklmnop12345', (string) $clean['body'] );
		$this->assertEquals( 5, $clean['number'] );
	}

	public function test_plain_text_survives_redaction(): void {
		$plain = 'body { color: #333; font-size: 12px; }';
		$this->assertEquals( $plain, Sanitize::redactString( $plain ) );
	}

	public function test_looks_like_secret(): void {
		$this->assertTrue( Sanitize::looksLikeSecret( 'api_key: kFj3092jsd0fj' ) );
		$this->assertFalse( Sanitize::looksLikeSecret( 'Just a normal sentence about cats.' ) );
	}

	public function test_clamping(): void {
		$this->assertEquals( 5, Sanitize::positiveInt( 5, 1 ) );
		$this->assertEquals( 1, Sanitize::positiveInt( 0, 1 ) );
		$this->assertEquals( 1, Sanitize::positiveInt( -7, 1 ) );
		$this->assertEquals( 'draft', Sanitize::enum( 'draft', array( 'draft', 'publish' ), 'publish' ) );
		$this->assertEquals( 'publish', Sanitize::enum( 'weird', array( 'draft', 'publish' ), 'publish' ) );
	}
}
