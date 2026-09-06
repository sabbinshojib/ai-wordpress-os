<?php
/**
 * Versioned, forward-only migration contract.
 *
 * @package AIOS\Database
 */

declare( strict_types=1 );

namespace AIOS\Database;

interface MigrationInterface {

	/**
	 * Monotonic version id, e.g. "202501010001". Static so the runner
	 * can order/filter without instantiating every migration.
	 */
	public static function version(): string;

	/**
	 * Apply the migration. Must be idempotent (CREATE TABLE IF NOT
	 * EXISTS / guarded index adds) so a half-applied migration can be
	 * re-run after a failure. Return false to signal failure.
	 */
	public function up( Database $db ): bool;
}
