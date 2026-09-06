<?php
/**
 * Internal debug logging (spec §43): details stay inside WordPress,
 * never travel to external clients.
 *
 * @package AIOS\Logging
 */

declare( strict_types=1 );

namespace AIOS\Logging;

final class DebugLog {

	/**
	 * Log to the WordPress debug log when WP_DEBUG_LOG is on. Silent
	 * otherwise — never throws, never breaks a request.
	 */
	public static function log( string $channel, string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$context_json = empty( $context ) ? '' : ' ' . wp_json_encode( \AIOS\Support\Sanitize::redact( $context ), JSON_UNESCAPED_SLASHES );
		error_log( sprintf( '[AI WordPress OS/%s] %s%s', $channel, $message, $context_json ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Structured error detail logger: the ONLY place internal error
	 * details go. The client envelope only carries the safe summary.
	 */
	public static function internal( \Throwable $e, string $where ): void {
		self::log(
			'internal',
			sprintf( '%s: %s @ %s:%d', $where, $e->getMessage(), basename( $e->getFile() ), $e->getLine() )
		);
	}
}
