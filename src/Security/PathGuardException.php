<?php
/**
 * PathGuard exception taxonomy.
 *
 * @package AIOS\Security
 */

declare( strict_types=1 );

namespace AIOS\Security;

final class PathGuardException extends \RuntimeException {

	public const E_INVALID      = 'invalid';
	public const E_TRAVERSAL    = 'traversal';
	public const E_OUTSIDE_ROOT = 'outside_root';
	public const E_PROTECTED    = 'protected';
	public const E_EXTENSION    = 'extension';
	public const E_NOT_FOUND    = 'not_found';

	private string $reason;

	public function __construct( string $message, string $reason ) {
		parent::__construct( $message );
		$this->reason = $reason;
	}

	/**
	 * Machine-readable reason for structured errors.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
