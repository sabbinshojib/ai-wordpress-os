<?php
/**
 * Closed whitelist of approved developer test strategy identifiers.
 *
 * Prevents arbitrary command injection or shell execution: only
 * recognized, static test identifiers are allowed in DeveloperTaskPlan
 * test strategies.
 *
 * @package AIOS\Developer\Support
 */

declare( strict_types=1 );

namespace AIOS\Developer\Support;

final class DeveloperTestIdentifier {

	public const PHP82_NATIVE = 'php82.native';
	public const PHP83_NATIVE = 'php83.native';
	public const ACCEPTANCE   = 'acceptance';
	public const PHP_SYNTAX   = 'php.syntax';
	public const PHPSTAN      = 'phpstan';
	public const PHPCS        = 'phpcs';
	public const WP_REAL_DB   = 'wp.real_db';

	/**
	 * Whitelist of all permitted test strategy identifiers.
	 *
	 * @var string[]
	 */
	public const ALL = array(
		self::PHP82_NATIVE,
		self::PHP83_NATIVE,
		self::ACCEPTANCE,
		self::PHP_SYNTAX,
		self::PHPSTAN,
		self::PHPCS,
		self::WP_REAL_DB,
	);

	/**
	 * Check whether a given identifier is in the whitelist and contains
	 * no disallowed shell or control characters.
	 *
	 * @param string $identifier The test identifier to validate.
	 * @return bool True if valid and approved, false otherwise.
	 */
	public static function isValid( string $identifier ): bool {
		if ( ! in_array( $identifier, self::ALL, true ) ) {
			return false;
		}

		// Strictly disallow any shell metacharacters or whitespace.
		return 1 === preg_match( '/^[a-z0-9_.-]+$/', $identifier );
	}

	/**
	 * Assert that a list of test strategy identifiers contains only approved IDs.
	 *
	 * @param array<int, mixed> $identifiers The list of identifiers.
	 * @throws \InvalidArgumentException If any identifier is unapproved or invalid.
	 */
	public static function assertAllValid( array $identifiers ): void {
		foreach ( $identifiers as $identifier ) {
			if ( ! is_string( $identifier ) || ! self::isValid( $identifier ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Invalid or unapproved test strategy identifier: %s', is_scalar( $identifier ) ? (string) $identifier : gettype( $identifier ) )
				);
			}
		}
	}
}
