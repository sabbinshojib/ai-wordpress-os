<?php
/**
 * Migration 202509060002 — audit log tamper-evidence columns (SEC-M4,
 * Sprint 0.3A).
 *
 * Adds four nullable columns to the existing audit_logs table:
 *
 *   integrity_version  TINYINT UNSIGNED NULL  — hash-scheme version
 *   chain_seq          BIGINT UNSIGNED NULL   — per-site chain position
 *   prev_hash          CHAR(64) NULL          — predecessor's record_hash
 *                                                (or the literal sentinel
 *                                                "genesis")
 *   record_hash        CHAR(64) NULL          — HMAC-SHA256 over this
 *                                                row's own redacted
 *                                                content + prev_hash +
 *                                                chain_seq (see
 *                                                AIOS\Audit\AuditIntegrity)
 *
 * All four are NULL on every row written before this migration ran —
 * that is the explicit, documented "legacy" state AuditIntegrity
 * reports rather than silently treating as verified. No existing data
 * is rewritten, reordered, or dropped by this migration.
 *
 * Re-declares the FULL audit_logs schema (spec 202501010001) plus the
 * four new columns: dbDelta() diffs this against the live table and
 * only ADDs columns/indexes that do not already exist — it never
 * drops or rewrites a column it already finds.
 *
 * Uninstall (uninstall.php) already DROPs the whole audit_logs table
 * on the "remove data" opt-in path, which trivially covers these
 * columns too — no uninstall lifecycle change is needed for this
 * migration.
 *
 * @package AIOS\Database\Schema
 */

declare( strict_types=1 );

namespace AIOS\Database\Schema;

use AIOS\Database\Database;
use AIOS\Database\MigrationInterface;

final class Migration_202509060002_AuditIntegrity implements MigrationInterface {

	public static function version(): string {
		return '202509060002';
	}

	public function up( Database $db ): bool {
		$collate = $db->charsetCollate();
		$audit   = $db->table( Database::TABLE_AUDIT_LOGS );

		$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( is_file( $upgrade ) && ! function_exists( 'dbDelta' ) ) {
			require_once $upgrade;
		}

		$sql = "CREATE TABLE {$audit} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			occurred_at DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			client VARCHAR(64) NOT NULL DEFAULT '',
			principal_type VARCHAR(20) NOT NULL DEFAULT 'user',
			tool VARCHAR(190) NOT NULL DEFAULT '',
			action VARCHAR(190) NOT NULL DEFAULT '',
			args_hash CHAR(64) NOT NULL DEFAULT '',
			args_json LONGTEXT NULL,
			risk TINYINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'ok',
			error TEXT NULL,
			affected_objects LONGTEXT NULL,
			affected_files LONGTEXT NULL,
			approval_id BIGINT UNSIGNED NULL,
			rollback_id BIGINT UNSIGNED NULL,
			duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
			ip VARBINARY(16) NULL,
			integrity_version TINYINT UNSIGNED NULL,
			chain_seq BIGINT UNSIGNED NULL,
			prev_hash CHAR(64) NULL,
			record_hash CHAR(64) NULL,
			PRIMARY KEY  (id),
			KEY occurred_at (occurred_at),
			KEY user_id (user_id),
			KEY tool (tool),
			KEY status (status),
			KEY risk (risk),
			KEY chain_seq (chain_seq)
		) {$collate};";
		dbDelta( $sql );

		return '' === $db->lastError();
	}
}
