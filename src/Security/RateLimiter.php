<?php
/**
 * Rolling-window rate limiter (spec Sprint 0.3A-A).
 *
 * Backed by a real database row per principal-counter pair, written
 * with a single atomic `INSERT ... ON DUPLICATE KEY UPDATE` statement
 * (see `RateLimitRepository::hit()`). This replaces an earlier
 * transient-based implementation whose check-and-increment was two
 * separate operations (get_transient() then set_transient()) — two
 * concurrent requests for the same principal could both read the
 * pre-increment count and both conclude they were still under the
 * limit, letting the limit be exceeded under real concurrency. A
 * transient/object-cache approach cannot close this without either an
 * atomic cache backend (not guaranteed — WordPress's default
 * non-persistent object cache is per-request only, and is not
 * guaranteed to be present or atomic even when a persistent backend
 * is configured) or a database row lock; the row lock is what every
 * WordPress host already has, unconditionally.
 *
 * @package AIOS\Security
 */

declare( strict_types=1 );

namespace AIOS\Security;

use AIOS\Database\Repositories\RateLimitRepository;

final class RateLimiter {

	private const PREFIX = 'ai_os_rl_';

	private RateLimitRepository $repository;

	/**
	 * @param int $limit   Max events per window.
	 * @param int $window  Window length in seconds.
	 */
	public function __construct(
		RateLimitRepository $repository,
		private int $limit = 120,
		private int $window = 60
	) {
		$this->repository = $repository;
	}

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
	 * Fails CLOSED: if the counter cannot be read back after recording
	 * the hit (e.g. the underlying table is unexpectedly missing), the
	 * request is treated as OVER the limit rather than silently
	 * unlimited — a rate limiter that fails open on infrastructure
	 * trouble is not actually a limit (spec: "no silent rate-limit
	 * bypass").
	 *
	 * @param string $principal Stable principal id (e.g. "user:42").
	 */
	public function allow( string $principal, string $counter = 'default' ): bool {
		$count = $this->repository->hit( $this->rateKey( $principal, $counter ), $this->window );
		if ( null === $count ) {
			return false;
		}
		return $count <= $this->limit;
	}

	/**
	 * Metadata for rate-limit error envelopes (client retry hints).
	 *
	 * @return array{limit: int, window: int, retry_after: int}
	 */
	public function meta( string $principal, string $counter = 'default' ): array {
		$row   = $this->repository->get( $this->rateKey( $principal, $counter ) );
		$reset = null !== $row ? strtotime( $row['reset_at'] . ' UTC' ) : false;
		$reset = false === $reset ? time() + $this->window : $reset;

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
		$this->repository->clear( $this->rateKey( $principal, $counter ) );
	}

	/**
	 * Per-principal, per-counter, per-site key. Site isolation is
	 * automatic and requires no explicit blog id here: `Database::table()`
	 * (used throughout `RateLimitRepository`) already resolves to the
	 * CURRENT site's own `$wpdb->prefix`, exactly like every other AI
	 * OS table — the same mechanism verified for multisite isolation
	 * in tests/Integration/MultisiteLifecycleTest.php.
	 */
	private function rateKey( string $principal, string $counter ): string {
		return self::PREFIX . $counter . '_' . hash( 'sha256', $principal );
	}
}
