<?php
/**
 * Rolling-window rate limiter.
 *
 * Uses transients (object-cache friendly, spec §38). One window per
 * principal per counter name. Atomicity note: the check-and-increment
 * is not strictly atomic under extreme concurrency; acceptable here
 * because limits are protective, not billing-precise.
 *
 * @package AIOS\Security
 */

declare( strict_types=1 );

namespace AIOS\Security;

final class RateLimiter {

	private const PREFIX = 'ai_os_rl_';

	/**
	 * @param int $limit   Max events per window.
	 * @param int $window  Window length in seconds.
	 */
	public function __construct(
		private int $limit = 120,
		private int $window = 60
	) {}

	/**
	 * Configure from settings (invoked by the service provider).
	 */
	public function configure( int $limit, int $window = 60 ): void {
		$this->limit  = max( 1, $limit );
		$this->window = max( 5, $window );
	}

	/**
	 * Record an event and report whether the principal is still within
	 * the limit.
	 *
	 * @param string $principal Stable principal id (e.g. "user:42").
	 */
	public function allow( string $principal, string $counter = 'default' ): bool {
		$key = self::PREFIX . $counter . '_' . md5( $principal );

		$bucket = get_transient( $key );
		if ( ! is_array( $bucket ) || ! isset( $bucket['reset_at'] ) || $bucket['reset_at'] <= time() ) {
			$bucket = array( 'count' => 0, 'reset_at' => time() + $this->window );
		}

		$bucket['count']++;

		// Renew TTL so idle principals clean themselves up.
		set_transient( $key, $bucket, $this->window * 2 );

		return $bucket['count'] <= $this->limit;
	}

	/**
	 * Metadata for rate-limit error envelopes (client retry hints).
	 *
	 * @return array{limit: int, window: int, retry_after: int}
	 */
	public function meta( string $principal, string $counter = 'default' ): array {
		$key     = self::PREFIX . $counter . '_' . md5( $principal );
		$bucket  = get_transient( $key );
		$reset   = is_array( $bucket ) && isset( $bucket['reset_at'] ) ? (int) $bucket['reset_at'] : time() + $this->window;
		return array(
			'limit'       => $this->limit,
			'window'      => $this->window,
			'retry_after' => max( 0, $reset - time() ),
		);
	}

	/**
	 * Reset (admin action / tests).
	 */
	public function reset( string $principal, string $counter = 'default' ): void {
		delete_transient( self::PREFIX . $counter . '_' . md5( $principal ) );
	}
}
