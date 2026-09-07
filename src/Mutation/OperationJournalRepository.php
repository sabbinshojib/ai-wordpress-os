<?php
/**
 * Durable per-operation recovery journal (Sprint 0.3A Phase 2 final
 * hardening, Package B). Sibling of ChangeSetRepository, same
 * conventions (encrypted-at-rest via the existing Crypto service,
 * compare-and-swap transitions authorized by a single state-machine
 * class — OperationJournalState here — integrity hash checked after
 * every decrypt). Operates on the CURRENT site's table, same as every
 * other repository; the caller switches sites first when needed.
 *
 * One row per ChangeOperationInterface instance within a ChangeSet —
 * this is what makes "which of N operations already succeeded" a
 * durable, queryable fact rather than something only inferable from
 * live target state after a crash.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

use AIOS\Database\Database;
use AIOS\Database\DatabaseInterface;
use AIOS\Support\CryptoInterface;

final class OperationJournalRepository {

	/**
	 * Typed against the interfaces, not the concrete Database/Crypto —
	 * see ChangeSetRepository's constructor docblock for why.
	 */
	public function __construct(
		private readonly DatabaseInterface $db,
		private readonly CryptoInterface $crypto
	) {}

	private function table(): string {
		return $this->db->table( Database::TABLE_OPERATION_JOURNAL );
	}

	/**
	 * Create the PENDING journal row for one operation. Called once
	 * per operation, before Policy/Snapshot even run for it, so a
	 * crash before Snapshot still leaves a durable trace that this
	 * operation was part of the plan.
	 */
	public function create( string $change_set_id, int $operation_index, ChangeOperationInterface $operation, int $site_id ): array {
		$now       = $this->now();
		$insert_id = $this->db->insert(
			$this->table(),
			array(
				'change_set_id'    => $change_set_id,
				'operation_id'     => $operation->id(),
				'operation_index'  => $operation_index,
				'operation_schema_version' => OperationRegistry::SCHEMA_VERSION,
				'operation_type'   => $operation->type(),
				'site_id'          => $site_id,
				'state'            => OperationJournalState::PENDING,
				'state_version'    => 0,
				'payload_hash'     => $operation->payloadFingerprint(),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);

		// Fail closed (Package 4/5 fault-injection review — mirrors the
		// same fix in ChangeSetRepository::create()): never let a
		// discarded INSERT failure fall through to load() returning null
		// against this method's non-nullable `array` return type.
		if ( null === $insert_id ) {
			throw new MutationException( 'operation_journal.persist_failed', 'Failed to persist the operation journal row.' );
		}

		$row = $this->load( $change_set_id, $operation_index );
		if ( null === $row ) {
			throw new MutationException( 'operation_journal.persist_failed', 'Operation journal row could not be read back immediately after insert.' );
		}
		return $row;
	}

	/**
	 * Addressed by ($change_set_id, $operation_index) — NOT operation
	 * id, which is not stable across a rehydrate() (see this table's
	 * migration docblock for why). $operation_index is the operation's
	 * fixed position within the ChangeSet's operations array.
	 *
	 * @return array<string, mixed>|null
	 */
	public function load( string $change_set_id, int $operation_index ): ?array {
		$sql = $this->db->prepare(
			'SELECT * FROM ' . $this->table() . ' WHERE change_set_id = %s AND operation_index = %d',
			$change_set_id,
			$operation_index
		);
		$row = $this->db->getRow( $sql );
		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Every journal row for a ChangeSet, in operation_index order —
	 * the exact ordering crash recovery needs to decide what to do
	 * next.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function loadForChangeSet( string $change_set_id ): array {
		$sql  = $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE change_set_id = %s', $change_set_id );
		$rows = array_map( array( $this, 'hydrate' ), $this->db->getResults( $sql ) );
		usort( $rows, static fn( array $a, array $b ): int => $a['operation_index'] <=> $b['operation_index'] );
		return $rows;
	}

	/**
	 * Compare-and-swap state transition for one operation's journal
	 * row. Consults OperationJournalState first (fails closed before
	 * any SQL runs), then a single conditional UPDATE.
	 *
	 * @param array<string, mixed> $extra_fields Additional columns (e.g. timestamps, failure_code).
	 */
	public function transition( string $change_set_id, int $operation_index, string $from_state, string $to_state, int $expected_version, array $extra_fields = array() ): bool {
		OperationJournalState::assertTransition( $from_state, $to_state );

		$data = array_merge(
			$extra_fields,
			array(
				'state'         => $to_state,
				'state_version' => $expected_version + 1,
				'updated_at'    => $this->now(),
			)
		);

		$updated = $this->db->update(
			$this->table(),
			$data,
			array( 'change_set_id' => $change_set_id, 'operation_index' => $operation_index, 'state' => $from_state, 'state_version' => $expected_version )
		);
		return 1 === $updated;
	}

	/**
	 * Persist the encrypted rollback material for one operation
	 * (its Snapshot's state) — written once, right after a successful
	 * Snapshot, BEFORE Apply — so a crash mid-apply still leaves
	 * durable, decryptable rollback data for this operation.
	 *
	 * @param array<string, mixed> $recovery_state
	 */
	public function saveRecovery( string $change_set_id, int $operation_index, array $recovery_state, string $snapshot_hash ): bool {
		$json = (string) json_encode( $recovery_state, JSON_UNESCAPED_SLASHES );
		$updated = $this->db->update(
			$this->table(),
			array(
				'recovery_ciphertext' => $this->crypto->encrypt( $json ),
				'recovery_hash'       => hash( 'sha256', $json ),
				'snapshot_hash'       => $snapshot_hash,
				'updated_at'          => $this->now(),
			),
			array( 'change_set_id' => $change_set_id, 'operation_index' => $operation_index )
		);
		return $updated > 0;
	}

	/**
	 * @return array<string, mixed>|null
	 * @throws MutationException "changeset.decrypt_failed"/"changeset.integrity_failed" (same codes as ChangeSetRepository).
	 */
	public function loadRecovery( string $change_set_id, int $operation_index ): ?array {
		$row = $this->load( $change_set_id, $operation_index );
		if ( null === $row || null === ( $row['recovery_ciphertext'] ?? null ) ) {
			return null;
		}
		$plaintext = $this->crypto->decrypt( (string) $row['recovery_ciphertext'] );
		if ( null === $plaintext ) {
			throw new MutationException( 'changeset.decrypt_failed', 'Failed to decrypt the persisted operation recovery state.' );
		}
		if ( ! hash_equals( (string) $row['recovery_hash'], hash( 'sha256', $plaintext ) ) ) {
			throw new MutationException( 'changeset.integrity_failed', 'Decrypted operation recovery state does not match its stored integrity hash.' );
		}
		$decoded = json_decode( $plaintext, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public function markTimestamp( string $change_set_id, int $operation_index, string $column ): void {
		$allowed = array( 'apply_started_at', 'apply_completed_at', 'verify_started_at', 'verify_completed_at', 'rollback_started_at', 'rollback_completed_at' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return;
		}
		$this->db->update( $this->table(), array( $column => $this->now(), 'updated_at' => $this->now() ), array( 'change_set_id' => $change_set_id, 'operation_index' => $operation_index ) );
	}

	/**
	 * Retention cascade (Package N): remove every journal row for a
	 * ChangeSet — called only by ChangeSetRepository's own purge, only
	 * for a ChangeSet already confirmed terminal-and-eligible, never
	 * independently.
	 */
	public function deleteForChangeSet( string $change_set_id ): int {
		$sql = $this->db->prepare( 'DELETE FROM ' . $this->table() . ' WHERE change_set_id = %s', $change_set_id );
		return (int) ( $this->db->query( $sql ) ?? 0 );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$row['operation_index']          = (int) ( $row['operation_index'] ?? 0 );
		$row['operation_schema_version'] = (int) ( $row['operation_schema_version'] ?? 1 );
		$row['site_id']                  = (int) ( $row['site_id'] ?? 0 );
		$row['state_version']            = (int) ( $row['state_version'] ?? 0 );
		return $row;
	}
}
