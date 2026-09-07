<?php
/**
 * Migration 202509070001 — durable ChangeSet persistence (Sprint
 * 0.3A Phase 2 hardening: durable persistence, execution lease,
 * replay protection).
 *
 * `id` is a standard auto-increment surrogate key, matching this
 * table's own conventions elsewhere (e.g. api_keys' `id` + `key_hash`).
 * `change_set_id` (`cs_<24 hex chars>`, ~96 bits of CSPRNG entropy,
 * the domain-generated ChangeSet::id()) is the real lookup column —
 * every caller addresses a row by this string, via its own UNIQUE key.
 *
 * `payload_ciphertext`/`recovery_ciphertext` are AIOS\Support\Crypto
 * envelopes (never plaintext, never PHP serialize()) — see
 * AIOS\Mutation\ChangeSetRepository for what goes into them.
 * `payload_hash`/`recovery_hash` are sha256 of the PLAINTEXT before
 * encryption, checked after decryption to detect ciphertext
 * corruption/tampering that somehow still authenticates (defense in
 * depth beyond Crypto's own AEAD tag).
 *
 * `idempotency_key` is nullable + UNIQUE: MySQL/MariaDB treat
 * multiple NULLs as non-conflicting in a unique index, so only rows
 * that both set a real idempotency key can ever collide — exactly the
 * semantics ChangeSetRepository::create() needs for "same request
 * twice returns the same row instead of creating a duplicate."
 *
 * `lease_owner`/`lease_acquired_at`/`lease_expires_at` implement a
 * bounded, DB-backed execution lease (Package 8) — never an
 * in-memory-only lock, since two separate PHP requests/processes must
 * never both execute the same ChangeSet.
 *
 * Table participates in the same per-site `$wpdb->prefix` mechanism
 * as every other AI OS table — multisite isolation is automatic.
 *
 * @package AIOS\Database\Schema
 */

declare( strict_types=1 );

namespace AIOS\Database\Schema;

use AIOS\Database\Database;
use AIOS\Database\MigrationInterface;

final class Migration_202509070001_ChangeSets implements MigrationInterface {

	public static function version(): string {
		return '202509070001';
	}

	public function up( Database $db ): bool {
		$collate = $db->charsetCollate();
		$table   = $db->table( Database::TABLE_CHANGE_SETS );

		$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( is_file( $upgrade ) && ! function_exists( 'dbDelta' ) ) {
			require_once $upgrade;
		}

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			change_set_id VARCHAR(64) NOT NULL,
			schema_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
			site_id BIGINT UNSIGNED NOT NULL,
			principal_user_id BIGINT UNSIGNED NOT NULL,
			principal_type VARCHAR(20) NOT NULL DEFAULT 'user',
			state VARCHAR(30) NOT NULL,
			state_version INT UNSIGNED NOT NULL DEFAULT 0,
			risk TINYINT UNSIGNED NOT NULL DEFAULT 0,
			fingerprint CHAR(64) NOT NULL,
			diff_hash CHAR(64) NULL,
			idempotency_key VARCHAR(191) NULL,
			approval_id BIGINT UNSIGNED NULL,
			payload_ciphertext LONGTEXT NOT NULL,
			payload_hash CHAR(64) NOT NULL,
			recovery_ciphertext LONGTEXT NULL,
			recovery_hash CHAR(64) NULL,
			attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
			lease_owner CHAR(64) NULL,
			lease_acquired_at DATETIME NULL,
			lease_expires_at DATETIME NULL,
			error_code VARCHAR(80) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY change_set_id (change_set_id),
			KEY site_state (site_id, state),
			KEY approval_id (approval_id),
			UNIQUE KEY idempotency_key (idempotency_key)
		) {$collate};";
		dbDelta( $sql );

		return '' === $db->lastError();
	}
}
