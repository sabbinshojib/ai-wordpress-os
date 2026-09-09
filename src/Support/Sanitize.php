<?php
/**
 * WP-aware sanitization + secret redaction helpers.
 *
 * @package AIOS\Support
 */

declare( strict_types=1 );

namespace AIOS\Support;

final class Sanitize {

	/**
	 * Secret-shaped regex patterns redacted from logs and tool output.
	 *
	 * @var array<array{pattern: string, replacement: string}>
	 */
	private const SECRET_PATTERNS = array(
		// Application passwords: "xxxx xxxx xxxx xxxx xxxx xxxx xxxx xxxx"
		array(
			'pattern'     => '/(app[_ -]?pass(word)?[\'" :=]+)[A-Za-z0-9 ]{16,}/i',
			'replacement' => '$1[REDACTED]',
		),
		// Generic key/token assignments in text or JSON.
		array(
			'pattern'     => '/((?:api[_ -]?key|secret|token|password|passwd|client[_ -]?secret|auth[_ -]?key|access[_ -]?key)[\'" :=]+)[^\'"\\s,;}]{8,}/i',
			'replacement' => '$1[REDACTED]',
		),
		// sk-style provider keys.
		array(
			'pattern'     => '/\bsk-[A-Za-z0-9_-]{16,}\b/',
			'replacement' => '[REDACTED]',
		),
		// Bearer tokens.
		array(
			'pattern'     => '/\bBearer\s+[A-Za-z0-9._~+\/-]{16,}/i',
			'replacement' => 'Bearer [REDACTED]',
		),
		// WP salts/constants values when dumped.
		array(
			'pattern'     => '/((?:AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)[\'" ,:=]+)[\'"][^\'"]{8,}[\'"]/',
			'replacement' => '$1\'[REDACTED]\'',
		),
		// DB password define.
		array(
			'pattern'     => '/(DB_PASSWORD[\'" ,:=]+)[\'"][^\'"]*[\'"]/',
			'replacement' => '$1\'[REDACTED]\'',
		),
	);

	/**
	 * Redact secret-shaped substrings from any scalar or nested structure.
	 */
	public static function redact( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			return self::redactString( $value );
		}
		if ( is_array( $value ) ) {
			$result = array();
			foreach ( $value as $key => $item ) {
				$redacted_key            = is_string( $key ) ? self::redactString( $key ) : $key;
				$result[ $redacted_key ] = self::redact( $item );
			}
			return $result;
		}
		return $value;
	}

	public static function redactString( string $text ): string {
		foreach ( self::SECRET_PATTERNS as $rule ) {
			$text = preg_replace( $rule['pattern'], $rule['replacement'], $text ) ?? $text;
		}
		return $text;
	}

	/**
	 * True when a string looks like it contains a secret (used to refuse
	 * storing/echoing values entirely rather than partially redacting).
	 */
	public static function looksLikeSecret( string $text ): bool {
		foreach ( self::SECRET_PATTERNS as $rule ) {
			if ( preg_match( $rule['pattern'], $text ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Coerce an argument to a positive integer with a fallback.
	 */
	public static function positiveInt( mixed $value, int $fallback, int $max = PHP_INT_MAX ): int {
		$int = is_numeric( $value ) ? (int) $value : $fallback;
		if ( $int < 1 ) {
			$int = $fallback;
		}
		return min( $int, $max );
	}

	/**
	 * Clamp a numeric argument between bounds.
	 */
	public static function clamp( mixed $value, float $min, float $max, float $fallback ): float {
		if ( ! is_numeric( $value ) ) {
			$value = $fallback;
		}
		$num = (float) $value;
		return max( $min, min( $max, $num ) );
	}

	/**
	 * Whitelist an enum-style string argument.
	 */
	public static function enum( mixed $value, array $allowed, string $fallback ): string {
		$value = is_string( $value ) ? $value : $fallback;
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Normalize a "search" string for safe DB LIKE usage.
	 */
	public static function like( string $text ): string {
		return Strings::truncate( trim( $text ), 200 );
	}
}
