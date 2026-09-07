<?php
/**
 * Durable ChangeSet persistence (Sprint 0.3A Phase 2 hardening,
 * Packages 1/2/3/6/7/8). Operates on the CURRENT site's table exactly
 * like every other repository in this codebase — the caller (
 * AIOS\Mutation\DurableMutationCoordinator) is responsible for
 * switch_to_blog()-ing to the ChangeSet's own site first, when it
 * differs from the current request's, before calling any method here.
 *
 * Encryption at rest: `payload_ciphertext` (the OperationRegistry-
 * serialized ChangeSet — MAY contain secret-shaped content) and
 * `recovery_ciphertext` (snapshots/execution-journal state) are both
 * AIOS\Support\Crypto envelopes, never plaintext, never PHP
 * serialize(). This class never logs a decrypted payload; callers
 * (DurableMutationCoordinator) must not either — see its own docblock.
 *
 * Compare-and-swap: every state transition is a single `UPDATE ...
 * SET state_version = state_version + 1 WHERE id = ? AND state_version = ?`
 * — 0 rows affected means the row moved (or vanished) since the
 * caller last read it, and the caller must reload rather than assume
 * success. ChangeSetState::assertTransition() is consulted before the
 * UPDATE is even attempted, so an illegal transition never reaches SQL.
 *
 * Idempotency: `idempotency_key` carries a UNIQUE index at the schema
 * level (the authoritative guarantee under real concurrent MySQL).
 * create() ALSO checks-then-inserts explicitly, because the test
 * shim's fake SQL engine does not enforce UNIQUE constraints — the
 * explicit check keeps behavior identical in both environments and
 * costs nothing extra in production.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

use AIOS\Database\Database;
use AIOS\Database\DatabaseInterface;
use AIOS\Support\CryptoInterface;

final class ChangeSetRepository {

	private const LEASE_DEFAULT_TTL_SECONDS = 300;

	/**
	 * Typed against the interfaces, not the concrete Database/Crypto —
	 * every real (production) caller still passes the real classes;
	 * this is what lets a test substitute a deterministic
	 * fault-injecting collaborator (tests/Support/FaultInjectingDatabase.php,
	 * tests/Support/FaultInjectingCrypto.php) for repository
	 * fault-injection coverage without any production code path
	 * changing.
	 */
	public function __construct(
		private readonly DatabaseInterface $db,
		private readonly CryptoInterface $crypto,
		private readonly OperationJournalRepository $journal
	) {}

	private function table(): string {
		return $this->db->table( Database::TABLE_CHANGE_SETS );
	}

	/**
	 * Persist a brand-new ChangeSet row in PLANNED state. Encrypts the
	 * operation payload. Idempotent when $idempotency_key is provided
	 * and non-empty: a second create() with the same key returns the
	 * EXISTING row instead of inserting a duplicate.
	 *
	 * @return array<string, mixed> The hydrated row (existing or newly created).
	 */
	public function create( ChangeSet $change_set, string $fingerprint, ?string $idempotency_key = null ): array {
		if ( null !== $idempotency_key && '' !== $idempotency_key ) {
			$existing = $this->findByIdempotencyKey( $idempotency_key );
			if ( null !== $existing ) {
				return $existing;
			}
		}

		$payload      = OperationRegistry::serialize( $change_set );
		$payload_json = (string) json_encode( $payload, JSON_UNESCAPED_SLASHES );

		$now = $this->now();
		$this->db->insert(
			$this->table(),
			array(
				'change_set_id'      => $change_set->id(),
				'schema_version'     => OperationRegistry::SCHEMA_VERSION,
				'site_id'            => $change_set->siteId(),
				'principal_user_id'  => $change_set->principalUserId(),
				'principal_type'     => $change_set->principalType(),
				'state'              => ChangeSetState::PLANNED,
				'state_version'      => 0,
				'risk'               => $change_set->riskLevel(),
				'fingerprint'        => $fingerprint,
				'idempotency_key'    => ( null !== $idempotency_key && '' !== $idempotency_key ) ? $idempotency_key : null,
				'payload_ciphertext' => $this->crypto->encrypt( $payload_json ),
				'payload_hash'       => hash( 'sha256', $payload_json ),
				'attempt_count'      => 0,
				// Explicit empty string, not SQL NULL: acquireLease()'s
				// "no lease held" check is an equality match (the
				// array-based Database::update() WHERE builder can only
				// express `col = value`, never `col IS NULL`), so "no
				// lease" must have a real, matchable value.
				'lease_owner'        => '',
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);

		/** @var array<string, mixed> $row */
		$row = $this->load( $change_set->id() );
		return $row;
	}

	/**
	 * Raw hydrated row (non-secret columns only — never decrypts).
	 *
	 * @return array<string, mixed>|null
	 */
	public function load( string $id ): ?array {
		$sql = $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE change_set_id = %s', $id );
		$row = $this->db->getRow( $sql );
		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function findByIdempotencyKey( string $key ): ?array {
		$sql = $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE idempotency_key = %s', $key );
		$row = $this->db->getRow( $sql );
		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Decrypt + rehydrate the durable ChangeSet back into a real
	 * ChangeSet object via OperationRegistry — the only path a
	 * persisted payload is ever turned into live operation objects.
	 *
	 * @throws MutationException "changeset.decrypt_failed" on a
	 *         tampered/wrong-key/malformed ciphertext, "changeset.integrity_failed"
	 *         when the decrypted plaintext's hash does not match the
	 *         stored payload_hash (defense in depth beyond Crypto's own
	 *         AEAD tag), or whatever OperationRegistry::rehydrate() throws.
	 */
	public function loadChangeSet( string $id, ?\AIOS\Security\PathGuard $path_guard = null ): ?ChangeSet {
		$row = $this->load( $id );
		if ( null === $row ) {
			return null;
		}

		$plaintext = $this->crypto->decrypt( (string) $row['payload_ciphertext'] );
		if ( null === $plaintext ) {
			throw new MutationException( 'changeset.decrypt_failed', 'Failed to decrypt the persisted ChangeSet payload (wrong key, tampered ciphertext, or malformed envelope).' );
		}
		if ( ! hash_equals( (string) $row['payload_hash'], hash( 'sha256', $plaintext ) ) ) {
			throw new MutationException( 'changeset.integrity_failed', 'Decrypted ChangeSet payload does not match its stored integrity hash.' );
		}

		$decoded = json_decode( $plaintext, true );
		if ( ! is_array( $decoded ) ) {
			throw new MutationException( 'changeset.decrypt_failed', 'Decrypted ChangeSet payload is not valid JSON.' );
		}

		return OperationRegistry::rehydrate( $decoded, $path_guard );
	}

	/**
	 * Compare-and-swap state transition. Consults ChangeSetState first
	 * (fails closed on an illegal transition before any SQL runs), then
	 * performs the UPDATE conditioned on the row still being at
	 * $expected_version.
	 *
	 * @param array<string, mixed> $extra_fields Additional columns to set atomically with the transition
	 *        (e.g. ['error_code' => ..., 'approval_id' => ...]).
	 *
	 * @return bool true iff exactly one row was updated (the CAS succeeded).
	 * @throws MutationException "changeset.illegal_transition" via ChangeSetState.
	 */
	public function transition( string $id, string $from_state, string $to_state, int $expected_version, array $extra_fields = array() ): bool {
		ChangeSetState::assertTransition( $from_state, $to_state );

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
			array( 'change_set_id' => $id, 'state' => $from_state, 'state_version' => $expected_version )
		);
		return 1 === $updated;
	}

	/**
	 * Persist the (encrypted) snapshot/execution-journal state — called
	 * before Apply, so a crash mid-apply leaves durable evidence of
	 * what was already applied. Per-ChangeSet granularity (one journal
	 * blob per ChangeSet, listing each operation's status), not a
	 * separate per-operation table — a deliberate scope limit, see
	 * docs/ARCHITECTURE.md §13.
	 *
	 * @param array<string, mixed> $recovery_state
	 */
	public function saveRecovery( string $id, array $recovery_state ): bool {
		$json = (string) json_encode( $recovery_state, JSON_UNESCAPED_SLASHES );
		$updated = $this->db->update(
			$this->table(),
			array(
				'recovery_ciphertext' => $this->crypto->encrypt( $json ),
				'recovery_hash'       => hash( 'sha256', $json ),
				'updated_at'          => $this->now(),
			),
			array( 'change_set_id' => $id )
		);
		return $updated > 0;
	}

	/**
	 * @return array<string, mixed>|null
	 * @throws MutationException On decrypt/integrity failure (same codes as loadChangeSet()).
	 */
	public function loadRecovery( string $id ): ?array {
		$row = $this->load( $id );
		if ( null === $row || null === ( $row['recovery_ciphertext'] ?? null ) ) {
			return null;
		}
		$plaintext = $this->crypto->decrypt( (string) $row['recovery_ciphertext'] );
		if ( null === $plaintext ) {
			throw new MutationException( 'changeset.decrypt_failed', 'Failed to decrypt the persisted recovery state.' );
		}
		if ( ! hash_equals( (string) $row['recovery_hash'], hash( 'sha256', $plaintext ) ) ) {
			throw new MutationException( 'changeset.integrity_failed', 'Decrypted recovery state does not match its stored integrity hash.' );
		}
		$decoded = json_decode( $plaintext, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Atomically acquire the execution lease: succeeds when the row has
	 * no lease, or its lease already expired. Never in-memory-only —
	 * this is a single conditional UPDATE, safe across processes.
	 */
	public function acquireLease( string $id, string $owner_token, int $ttl_seconds = self::LEASE_DEFAULT_TTL_SECONDS ): bool {
		$now = $this->now();
		$expires = gmdate( 'Y-m-d H:i:s', time() + max( 1, $ttl_seconds ) );

		// Try to take an unheld or expired lease.
		$updated = $this->db->update(
			$this->table(),
			array( 'lease_owner' => $owner_token, 'lease_acquired_at' => $now, 'lease_expires_at' => $expires, 'updated_at' => $now ),
			array( 'change_set_id' => $id, 'lease_owner' => '' )
		);
		if ( $updated > 0 ) {
			return true;
		}

		// Row exists with a lease — only takeover if it demonstrably expired.
		$row = $this->load( $id );
		if ( null === $row ) {
			return false;
		}
		$expires_at = $row['lease_expires_at'] ?? null;
		if ( null === $expires_at || strtotime( (string) $expires_at ) >= time() ) {
			return false; // No lease row to steal from, or still held/unexpired.
		}

		$updated = $this->db->update(
			$this->table(),
			array( 'lease_owner' => $owner_token, 'lease_acquired_at' => $now, 'lease_expires_at' => $expires, 'updated_at' => $now ),
			array( 'change_set_id' => $id, 'lease_expires_at' => (string) $expires_at )
		);
		return $updated > 0;
	}

	public function releaseLease( string $id, string $owner_token ): bool {
		$updated = $this->db->update(
			$this->table(),
			array( 'lease_owner' => '', 'lease_acquired_at' => null, 'lease_expires_at' => null, 'updated_at' => $this->now() ),
			array( 'change_set_id' => $id, 'lease_owner' => $owner_token )
		);
		return $updated > 0;
	}

	public function recordAttempt( string $id ): void {
		$row = $this->load( $id );
		$attempts = null === $row ? 1 : ( (int) ( $row['attempt_count'] ?? 0 ) + 1 );
		$this->db->update( $this->table(), array( 'attempt_count' => $attempts, 'updated_at' => $this->now() ), array( 'change_set_id' => $id ) );
	}

	/**
	 * Purge terminal (COMPLETED/ROLLED_BACK/POLICY_REJECTED/REJECTED/
	 * EXPIRED/CANCELLED) rows older than $days — never a row in an
	 * active, pending-approval, or failure-requiring-manual-recovery
	 * state, regardless of age.
	 */
	public function purgeTerminalOlderThan( int $days, int $limit = 200 ): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * DAY_IN_SECONDS );
		$limit  = max( 1, min( 1000, $limit ) );

		// Fetched by date only (a single, well-supported comparison) and
		// filtered by state IN PHP rather than a SQL "IN (...)" clause —
		// deliberately, not for convenience: this is the one place a
		// filtering mistake deletes data, so the state check that decides
		// "never purge an active/pending/manual-recovery row" is plain,
		// auditable PHP using the same ChangeSetState::isTerminal()
		// authority everything else in this class defers to, not a
		// hand-built SQL clause that would silently need to stay in sync
		// with it.
		$sql  = $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE updated_at < %s ORDER BY id ASC LIMIT %d', $cutoff, $limit * 4 );
		$rows = $this->db->getResults( $sql );

		$purged = 0;
		foreach ( $rows as $row ) {
			if ( $purged >= $limit ) {
				break;
			}
			$state = (string) ( $row['state'] ?? '' );
			if ( ! ChangeSetState::isTerminal( $state ) || ChangeSetState::MANUAL_RECOVERY_REQUIRED === $state ) {
				continue; // Never purge non-terminal or manual-recovery-required rows.
			}
			// Retention cascade (Package N): a purged ChangeSet's per-
			// operation journal rows go with it — never independently,
			// and always BEFORE the ChangeSet row itself, so a crash
			// between the two leaves an orphaned journal (harmless,
			// cleaned up on the next run) rather than a journal row
			// whose parent ChangeSet has already vanished.
			$this->journal->deleteForChangeSet( (string) $row['change_set_id'] );
			$del_sql = $this->db->prepare( 'DELETE FROM ' . $this->table() . ' WHERE change_set_id = %s', $row['change_set_id'] );
			$purged += (int) ( $this->db->query( $del_sql ) ?? 0 );
		}
		return $purged;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$row['schema_version']    = (int) ( $row['schema_version'] ?? 1 );
		$row['site_id']           = (int) ( $row['site_id'] ?? 0 );
		$row['principal_user_id'] = (int) ( $row['principal_user_id'] ?? 0 );
		$row['state_version']     = (int) ( $row['state_version'] ?? 0 );
		$row['risk']              = (int) ( $row['risk'] ?? 0 );
		$row['attempt_count']     = (int) ( $row['attempt_count'] ?? 0 );
		$row['approval_id']       = isset( $row['approval_id'] ) && null !== $row['approval_id'] ? (int) $row['approval_id'] : null;
		return $row;
	}
}
