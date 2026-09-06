<?php
/**
 * Migration 202509060001 — rate limit counters (spec Sprint 0.3A-A).
 *
 * Replaces the transient-backed rate limiter's non-atomic
 * read-increment-write with a real table and a single atomic
 * `INSERT ... ON DUPLICATE KEY UPDATE` statement per hit — MySQL
 * serializes that statement per unique key via an InnoDB row lock,
 * so two concurrent requests for the same principal can no longer
 * both observe a stale count and both pass a check that should have
 * rejected one of them (the transient-based race this migration
 * exists to close).
 *
 * Table participates in the same per-site `$wpdb->prefix` mechanism
 * as every other AI OS table, so multisite isolation is automatic
 * and requires no special handling here (verified in
 * tests/Integration/MultisiteLifecycleTest.php).
 *
 * @package AIOS\Database\Schema
 */

declare( strict_types=1 );

namespace AIOS\Database\Schema;

use AIOS\Database\Database;
use AIOS\Database\MigrationInterface;

final class Migration_202509060001_RateLimits implements MigrationInterface {

	public static function version(): string {
		return '202509060001';
	}

	public function up( Database $db ): bool {
		$collate = $db->charsetCollate();
		$table   = $db->table( Database::TABLE_RATE_LIMITS );

		$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( is_file( $upgrade ) && ! function_exists( 'dbDelta' ) ) {
			require_once $upgrade;
		}

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rate_key VARCHAR(191) NOT NULL,
			count INT UNSIGNED NOT NULL DEFAULT 0,
			reset_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY rate_key (rate_key)
		) {$collate};";
		dbDelta( $sql );

		return '' === $db->lastError();
	}
}
