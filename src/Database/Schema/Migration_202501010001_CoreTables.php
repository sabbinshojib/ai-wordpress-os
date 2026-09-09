<?php
/**
 * Migration 202501010001 — core tables.
 *
 * Creates the four Phase 1 tables: audit_logs, tool_executions,
 * approvals, api_keys. Uses CREATE TABLE IF NOT EXISTS + guarded
 * index creation so the migration is safely re-runnable.
 *
 * Column notes:
 *  - ip is stored as VARBINARY(16) (packed inet_pton) so IPv6 fits
 *    and IPs are not stored in a queryable-by-humans plain form.
 *  - args_json stores a REDACTED copy of tool arguments.
 *  - ENUMs are emulated with VARCHAR + CHECK-less validation in PHP
 *    (MySQL/MariaDB ENUM churn across hosts is not worth it).
 *
 * @package AIOS\Database\Schema
 */

declare( strict_types=1 );

namespace AIOS\Database\Schema;

use AIOS\Database\Database;
use AIOS\Database\MigrationInterface;

final class Migration_202501010001_CoreTables implements MigrationInterface {

	public static function version(): string {
			return '202501010001';
	}

	public function up( Database $db ): bool {
			global $wpdb;

			$collate = $db->charsetCollate();

			$audit     = $db->table( Database::TABLE_AUDIT_LOGS );
			$execs     = $db->table( Database::TABLE_TOOL_EXECUTIONS );
			$approvals = $db->table( Database::TABLE_APPROVALS );
			$keys      = $db->table( Database::TABLE_API_KEYS );

			// dbDelta lives in wp-admin; guard for headless contexts.
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
                        PRIMARY KEY  (id),
                        KEY occurred_at (occurred_at),
                        KEY user_id (user_id),
                        KEY tool (tool),
                        KEY status (status),
                        KEY risk (risk)
                ) {$collate};";
			dbDelta( $sql );

			$sql = "CREATE TABLE {$execs} (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        occurred_at DATETIME NOT NULL,
                        tool VARCHAR(190) NOT NULL DEFAULT '',
                        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        client VARCHAR(64) NOT NULL DEFAULT '',
                        success TINYINT UNSIGNED NOT NULL DEFAULT 1,
                        duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                        error_code VARCHAR(120) NOT NULL DEFAULT '',
                        PRIMARY KEY  (id),
                        KEY tool_time (tool, occurred_at),
                        KEY success (success)
                ) {$collate};";
			dbDelta( $sql );

			$sql = "CREATE TABLE {$approvals} (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        created_at DATETIME NOT NULL,
                        expires_at DATETIME NOT NULL,
                        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        client VARCHAR(64) NOT NULL DEFAULT '',
                        tool VARCHAR(190) NOT NULL DEFAULT '',
                        args_json LONGTEXT NULL,
                        risk TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        reason TEXT NULL,
                        preview LONGTEXT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'pending',
                        decided_by BIGINT UNSIGNED NULL,
                        decided_at DATETIME NULL,
                        execution_status VARCHAR(20) NOT NULL DEFAULT 'not_started',
                        execution_result LONGTEXT NULL,
                        execution_log_id BIGINT UNSIGNED NULL,
                        PRIMARY KEY  (id),
                        KEY status (status),
                        KEY risk_status (risk, status)
                ) {$collate};";
			dbDelta( $sql );

			$sql = "CREATE TABLE {$keys} (
                        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        created_at DATETIME NOT NULL,
                        label VARCHAR(190) NOT NULL DEFAULT '',
                        key_prefix VARCHAR(12) NOT NULL DEFAULT '',
                        key_hash CHAR(64) NOT NULL,
                        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        capabilities LONGTEXT NULL,
                        max_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        last_used_at DATETIME NULL,
                        last_ip VARBINARY(16) NULL,
                        revoked_at DATETIME NULL,
                        expires_at DATETIME NULL,
                        PRIMARY KEY  (id),
                        KEY key_hash (key_hash),
                        KEY user_id (user_id)
                ) {$collate};";
			dbDelta( $sql );

			// dbDelta() returns arrays of messages; absence of errors is success.
			return '' === $db->lastError();
	}
}
