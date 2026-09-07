<?php
/**
 * Migration 202509070002 — per-operation durable recovery journal
 * (Sprint 0.3A Phase 2 final hardening). A dedicated child table of
 * ai_os_change_sets: one row per ChangeOperationInterface instance
 * within a ChangeSet, tracking that ONE operation's own lifecycle
 * (AIOS\Mutation\OperationJournalState) independently of its siblings
 * — this is what makes "operation 2 of 4 was already APPLIED when the
 * process died" a durable, queryable fact rather than something only
 * inferable from live target state.
 *
 * `change_set_id` + `operation_index` together identify a row
 * (UNIQUE) — NOT `operation_id`: AIOS\Mutation\Operations\
 * AbstractOperation generates a fresh random id on every
 * construction, so OperationRegistry::rehydrate() (the path
 * DurableMutationCoordinator::resume() takes to reconstruct a
 * ChangeSet from its durable row) produces operation objects with
 * DIFFERENT ids than the ones submit() originally journaled under.
 * `operation_index` (the operation's fixed position within the
 * ChangeSet's operations array) is the one thing serialize()/
 * rehydrate() preserve deterministically, so it is the correct
 * correlation key. `operation_id` is still stored, but purely as
 * informational metadata (whatever id the operation happened to have
 * at the moment this row was written) — never used to address a row.
 * `id` is the usual auto-increment surrogate, matching every other
 * table in this schema.
 *
 * `recovery_ciphertext` is an AIOS\Support\Crypto envelope (same
 * service, same key, as ai_os_change_sets — no second crypto layer)
 * holding whatever a single operation's rollback() needs (e.g. a
 * file's original content) — encrypted at rest, never plaintext.
 * `snapshot_hash`/`recovery_hash` are sha256 of the plaintext, checked
 * after every decrypt (defense in depth beyond Crypto's AEAD tag,
 * same pattern as the parent table).
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

final class Migration_202509070002_OperationJournal implements MigrationInterface {

	public static function version(): string {
		return '202509070002';
	}

	public function up( Database $db ): bool {
		$collate = $db->charsetCollate();
		$table   = $db->table( Database::TABLE_OPERATION_JOURNAL );

		$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( is_file( $upgrade ) && ! function_exists( 'dbDelta' ) ) {
			require_once $upgrade;
		}

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			change_set_id VARCHAR(64) NOT NULL,
			operation_id VARCHAR(64) NOT NULL,
			operation_index INT UNSIGNED NOT NULL,
			operation_schema_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
			operation_type VARCHAR(60) NOT NULL,
			site_id BIGINT UNSIGNED NOT NULL,
			state VARCHAR(30) NOT NULL,
			state_version INT UNSIGNED NOT NULL DEFAULT 0,
			payload_hash CHAR(64) NOT NULL,
			precondition_hash CHAR(64) NULL,
			snapshot_hash CHAR(64) NULL,
			recovery_ciphertext LONGTEXT NULL,
			recovery_hash CHAR(64) NULL,
			apply_started_at DATETIME NULL,
			apply_completed_at DATETIME NULL,
			verify_started_at DATETIME NULL,
			verify_completed_at DATETIME NULL,
			rollback_started_at DATETIME NULL,
			rollback_completed_at DATETIME NULL,
			failure_code VARCHAR(80) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY change_set_operation (change_set_id, operation_index),
			KEY change_set_state (change_set_id, state)
		) {$collate};";
		dbDelta( $sql );

		return '' === $db->lastError();
	}
}
