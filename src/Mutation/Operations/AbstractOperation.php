<?php
/**
 * Shared id-generation for every concrete ChangeOperationInterface.
 *
 * @package AIOS\Mutation\Operations
 */

declare( strict_types=1 );

namespace AIOS\Mutation\Operations;

use AIOS\Mutation\ChangeOperationInterface;

abstract class AbstractOperation implements ChangeOperationInterface {

	protected readonly string $id;

	public function __construct() {
		$this->id = 'op_' . bin2hex( random_bytes( 12 ) );
	}

	public function id(): string {
		return $this->id;
	}

	/**
	 * Deterministic, one-way sha256 hex of a normalized (json-encoded,
	 * never PHP-serialized) representation of $value. Used both for
	 * payloadFingerprint() (binds intended new content) and precondition
	 * fingerprints (binds current/expected live state) — never reversed,
	 * so it is always safe to store/log even for secret-shaped values.
	 */
	protected static function fingerprintOf( mixed $value ): string {
		return hash( 'sha256', (string) json_encode( $value, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Sentinel fingerprint for "this target does not exist" — a fixed,
	 * non-secret marker distinct from any real encoded value (json_encode
	 * of any real PHP value can never equal this literal string).
	 */
	protected static function absentFingerprint(): string {
		return hash( 'sha256', "\0ai-os-mutation-absent\0" );
	}
}
