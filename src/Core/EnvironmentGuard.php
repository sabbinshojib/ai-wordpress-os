<?php
/**
 * Centralized environment/capability detection (spec BUG-004).
 *
 * Two different kinds of environment check exist and must not be
 * confused:
 *
 *   - HARD requirements (PHP version, WordPress version): the plugin
 *     cannot run correctly at all without these, so activation is
 *     refused with a clear message. This mirrors the existing
 *     ai_wp_os_environment_failed() guard in the bootstrap file,
 *     which must stay self-contained (it runs before the AIOS\
 *     autoloader is even registered) — this class does not replace
 *     it, it is the single place everything ELSE is checked from.
 *
 *   - OPTIONAL capabilities (mbstring, an encryption backend): the
 *     plugin degrades gracefully without these (see
 *     AIOS\Support\Strings for the mbstring fallback, and
 *     AIOS\Support\Crypto for the encryption-backend guard) rather
 *     than fataling, but the degradation is real and worth surfacing
 *     to an administrator — this class is what the admin notice, the
 *     REST status endpoint, and the WP-CLI status command all read
 *     from, so there is exactly one place that decides what counts as
 *     "degraded" and one human-readable explanation per capability.
 *
 * Activation is never blocked by a missing optional capability.
 *
 * @package AIOS\Core
 */

declare( strict_types=1 );

namespace AIOS\Core;

final class EnvironmentGuard {

	/**
	 * Extension => what degrades when it is absent. Every entry here
	 * is optional: its absence is reported, never fatal, and never
	 * blocks activation.
	 *
	 * @var array<string, string>
	 */
	private const OPTIONAL_EXTENSIONS = array(
		'mbstring' => 'Multi-byte-safe string length and truncation fall back to byte-based equivalents (AIOS\Support\Strings): field-length limits stay enforced, but on non-ASCII content the boundary may land one character earlier than it would with mbstring loaded.',
	);

	/**
	 * At least one of these must be present for AIOS\Support\Crypto to
	 * function; it is not called anywhere in Phase 1 today, but a
	 * future feature that persists encrypted data will need one.
	 *
	 * @var string[]
	 */
	private const ENCRYPTION_BACKENDS = array( 'sodium', 'openssl' );

	/**
	 * @return array<string, bool> extension => loaded
	 */
	public static function optionalExtensionStatus(): array {
		$status = array();
		foreach ( array_keys( self::OPTIONAL_EXTENSIONS ) as $extension ) {
			$status[ $extension ] = extension_loaded( $extension );
		}
		return $status;
	}

	/**
	 * Whether AIOS\Support\Crypto has a usable backend on this host.
	 */
	public static function hasEncryptionBackend(): bool {
		foreach ( self::ENCRYPTION_BACKENDS as $extension ) {
			if ( extension_loaded( $extension ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Human-readable diagnostics for every currently-degraded optional
	 * capability. Empty means "nothing degraded" — the common case.
	 *
	 * @return string[]
	 */
	public static function degradedCapabilities(): array {
		$messages = array();

		foreach ( self::OPTIONAL_EXTENSIONS as $extension => $explanation ) {
			if ( ! extension_loaded( $extension ) ) {
				$messages[] = sprintf( 'The "%s" PHP extension is not loaded. %s', $extension, $explanation );
			}
		}

		if ( ! self::hasEncryptionBackend() ) {
			$messages[] = sprintf(
				'Neither the "%s" PHP extension is loaded. Secret encryption (AIOS\Support\Crypto) will refuse to run — with a clear error, never by falling back to an insecure method — until one of these is available.',
				implode( '" nor the "', self::ENCRYPTION_BACKENDS )
			);
		}

		return $messages;
	}

	/**
	 * @return array<string, mixed> A compact snapshot for the REST
	 *         status endpoint and WP-CLI, never exposed to a
	 *         non-privileged caller.
	 */
	public static function snapshot(): array {
		return array(
			'php_version'         => PHP_VERSION,
			'optional_extensions' => self::optionalExtensionStatus(),
			'encryption_backend'  => self::hasEncryptionBackend(),
			'degraded'            => self::degradedCapabilities(),
		);
	}
}
