<?php
/**
 * Rate limit counter repository (spec Sprint 0.3A-A).
 *
 * The only repository whose write is a single atomic
 * `INSERT ... ON DUPLICATE KEY UPDATE` rather than a separate
 * read-then-write — that is the entire point: MySQL/MariaDB serialize
 * this statement per unique key via an InnoDB row lock, so two
 * concurrent hits for the same `rate_key` cannot both read a stale
 * count and both increment from it. Every other repository in this
 * plugin can safely use the ordinary insert()/update() helpers on
 * `Database` because nothing else needs a check-and-increment; this
 * one specifically cannot, which is why it builds its own SQL instead
 * of going through `Database::insert()`/`update()`.
 *
 * @package AIOS\Database\Repositories
 */

declare( strict_types=1 );

namespace AIOS\Database\Repositories;

use AIOS\Database\Database;

final class RateLimitRepository {

	private Database $db;

	public function __construct( Database $db ) {
		$this->db = $db;
	}

	private function table(): string {
		return $this->db->table( Database::TABLE_RATE_LIMITS );
	}

	/**
	 * Atomically record one hit for `$rate_key` and return the
	 * resulting count for the CURRENT window, opening a fresh window
	 * when the previous one has expired.
	 *
	 * Returns null when the counter could not be read back after the
	 * write (e.g. the table does not exist yet) — callers must treat
	 * null as "unknown, fail closed", never as "no hits yet".
	 *
	 * @param int $window_seconds Window length; only used when this
	 *                            hit opens a new window.
	 */
	public function hit( string $rate_key, int $window_seconds ): ?int {
		$now      = gmdate( 'Y-m-d H:i:s' );
		$reset_at = gmdate( 'Y-m-d H:i:s', time() + max( 1, $window_seconds ) );
		$table    = $this->table();

		// One atomic statement: insert a fresh row at count 1, or —
		// under the same row lock — either roll a stale window over to
		// a fresh count of 1, or increment the live window. There is
		// no separate "read the count" step before this write, which
		// is exactly what made the previous transient-based limiter
		// racy: two concurrent callers could both read the same
		// pre-increment value and both decide they were still under
		// the limit.
		$sql = $this->db->prepare(
			"INSERT INTO {$table} (rate_key, count, reset_at) VALUES (%s, 1, %s)
			ON DUPLICATE KEY UPDATE
				count = IF( reset_at <= %s, 1, count + 1 ),
				reset_at = IF( reset_at <= %s, %s, reset_at )",
			$rate_key,
			$reset_at,
			$now,
			$now,
			$reset_at
		);
		$this->db->query( $sql );

		// Read back the row this statement just committed. A
		// concurrent hit for the SAME key landing between our write
		// and this read can only ever make the observed count higher
		// than what our own hit contributed, never lower — so this can
		// bias toward rejecting a borderline request, never toward
		// silently letting one through it shouldn't (spec: "no silent
		// rate-limit bypass").
		$row = $this->get( $rate_key );
		return null === $row ? null : $row['count'];
	}

	/**
	 * @return array{count: int, reset_at: string}|null
	 */
	public function get( string $rate_key ): ?array {
		$sql = $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE rate_key = %s', $rate_key );
		$row = $this->db->getRow( $sql );
		if ( null === $row ) {
			return null;
		}
		return array(
			'count'    => (int) ( $row['count'] ?? 0 ),
			'reset_at' => (string) ( $row['reset_at'] ?? '' ),
		);
	}

	/**
	 * Admin/test reset: delete the counter row outright so the next
	 * hit opens a brand new window.
	 */
	public function clear( string $rate_key ): void {
		$sql = $this->db->prepare( 'DELETE FROM ' . $this->table() . ' WHERE rate_key = %s', $rate_key );
		$this->db->query( $sql );
	}

	/**
	 * Retention purge (runs alongside the audit/execution purges) —
	 * counters past their own window are inert but otherwise accumulate
	 * forever.
	 */
	public function purgeExpired(): int {
		$sql = $this->db->prepare( 'DELETE FROM ' . $this->table() . ' WHERE reset_at < %s', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		return (int) $this->db->query( $sql );
	}
}
