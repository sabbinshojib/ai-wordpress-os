<?php
/**
 * Ties AIOS\Mutation\ChangeSetRepository (durable state, encryption
 * at rest, lease, replay protection) to AIOS\Mutation\MutationEngine
 * (the already-real, already-tested in-process Policy/Snapshot/Diff/
 * Approval/Apply/Verify/Audit/Rollback pipeline) without duplicating
 * either. This is the ONLY entry point a caller needs for the durable
 * path: resume() takes just a ChangeSet id — the caller never
 * reconstructs a ChangeSet manually (contrast MutationEngine::
 * resumeApproved(), which still requires that, and remains available
 * for the in-memory-only, single-request use case).
 *
 * Durable state is reflected AFTER MutationEngine has already made
 * every real decision — this class never re-implements Policy,
 * Snapshot, Diff, or Approval logic, it only records what happened.
 * Each step of the reflected walk is its own compare-and-swap
 * (AIOS\Mutation\ChangeSetRepository::transition()), so a concurrent
 * mutation of the same row (extremely unlikely given the lease, but
 * not impossible — e.g. a manual admin action) stops the walk rather
 * than overwriting something else changed.
 *
 * Not wired to any AI-facing tool, REST endpoint, or MCP surface.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

use AIOS\Audit\AuditLogger;
use AIOS\Security\PathGuard;
use WP_User;

final class DurableMutationCoordinator {

	public function __construct(
		private readonly ChangeSetRepository $repository,
		private readonly MutationEngine $engine,
		private readonly OperationJournalRepository $journal,
		private readonly AuditLogger $audit
	) {}

	/**
	 * Persist the ChangeSet, then run it through MutationEngine::submit().
	 * Idempotent: a second submit() with the same $idempotency_key never
	 * creates a duplicate durable row or re-runs a completed mutation.
	 */
	public function submit( ChangeSet $change_set, WP_User $acting, ?string $idempotency_key = null ): MutationResult {
		$fingerprint = ChangeSetFingerprint::compute( $change_set );
		try {
			$row = $this->repository->create( $change_set, $fingerprint, $idempotency_key );
		} catch ( \Throwable $e ) {
			// Fault-injection matrix items A/I: a real repository OR
			// encryption failure surfaces as a safe, typed result —
			// never an uncaught exception, never a false "success" for
			// a ChangeSet that was never actually durably persisted.
			// \Throwable (not just MutationException) because
			// AIOS\Support\Crypto::encrypt() — called from inside
			// create() — documents its own failure mode as a bare
			// \RuntimeException, not a MutationException; its message
			// is always one of Crypto's own fixed, non-secret strings
			// (verified by CryptoTest::test_error_messages_never_contain_key_material),
			// never a raw wpdb error (Database::insert() never throws —
			// it returns null, handled above, not here).
			return MutationResult::rejected( $change_set->id(), $e->getMessage() );
		}

		if ( $row['change_set_id'] !== $change_set->id() ) {
			// Idempotent hit: create() returned a PRE-EXISTING row for this key.
			if ( ChangeSetState::COMPLETED === $row['state'] ) {
				return MutationResult::alreadyCompleted( $row['change_set_id'] );
			}
			return MutationResult::rejected( $change_set->id(), 'An in-progress or already-decided ChangeSet exists for this idempotency key.' );
		}

		try {
			$this->createJournalRows( $change_set );
		} catch ( \Throwable $e ) {
			// Fault-injection matrix item B: apply must never start if
			// even one operation's journal row could not be durably
			// created — mark the ChangeSet FAILED (never left at
			// PLANNED, which would look like nothing was ever
			// attempted) and stop before engine->submit() runs anything.
			$this->repository->transition( $change_set->id(), ChangeSetState::PLANNED, ChangeSetState::FAILED, (int) $row['state_version'] );
			return MutationResult::rejected( $change_set->id(), $e->getMessage() );
		}

		try {
			$result = $this->engine->submit( $change_set, $acting, $this->journalEventHook( $change_set->id(), $change_set ) );
		} catch ( \Throwable $e ) {
			return $this->failClosedOnUnexpectedThrow( $change_set->id(), $e );
		}
		$this->reflectFromPlanned( $change_set->id(), $result );
		return $result;
	}

	/**
	 * Outermost safety net around an engine->submit()/resumeApproved()
	 * call (fault-injection matrix item I): MutationEngine's own
	 * captureSnapshots()/applyChangeSet() only catch MutationException
	 * specifically — a bare \RuntimeException from
	 * AIOS\Support\Crypto::encrypt(), reached from deep inside the
	 * 'snapshot_captured' event hook (always BEFORE any operation's
	 * apply() has run — see journalEventHook()'s docblock), would
	 * otherwise escape both MutationEngine and this class entirely
	 * uncaught. reflectFromPlanned()/reflectFromPendingApproval() have
	 * no path for an exception that skipped MutationResult entirely, so
	 * this explicitly marks the row FAILED itself rather than leaving
	 * it looking untouched.
	 */
	private function failClosedOnUnexpectedThrow( string $change_set_id, \Throwable $e ): MutationResult {
		$row = $this->repository->load( $change_set_id );
		if ( null !== $row && ChangeSetState::isValidTransition( (string) $row['state'], ChangeSetState::FAILED ) ) {
			$this->repository->transition( $change_set_id, (string) $row['state'], ChangeSetState::FAILED, (int) $row['state_version'] );
		}
		return MutationResult::rejected( $change_set_id, $e->getMessage() );
	}

	/**
	 * Continue a durably-persisted ChangeSet after a human decision.
	 * The caller supplies only the ChangeSet's id — NOT the ChangeSet
	 * itself — this is what makes the durable path self-sufficient
	 * across requests (Package 9). Acquires the execution lease before
	 * touching anything, so two concurrent resume() calls for the same
	 * ChangeSet can never both apply it.
	 */
	public function resume( string $change_set_id, int $approval_id, string $decision, WP_User $deciding_user, ?PathGuard $path_guard = null ): MutationResult {
		$row = $this->repository->load( $change_set_id );
		if ( null === $row ) {
			return MutationResult::rejected( $change_set_id, 'Unknown ChangeSet.' );
		}
		if ( ChangeSetState::COMPLETED === $row['state'] ) {
			// Durable replay protection (Package 7): a completed
			// ChangeSet is NEVER re-applied, regardless of how many
			// times resume() is called for it.
			return MutationResult::alreadyCompleted( $change_set_id );
		}

		$owner_token = bin2hex( random_bytes( 16 ) );
		if ( ! $this->repository->acquireLease( $change_set_id, $owner_token ) ) {
			return MutationResult::rejected( $change_set_id, 'Execution lease for this ChangeSet is held by another executor.' );
		}

		try {
			try {
				$change_set = $this->repository->loadChangeSet( $change_set_id, $path_guard );
			} catch ( \Throwable $e ) {
				// Fault-injection matrix item J: a decrypt/integrity
				// failure on the stored payload itself must never be
				// guessed past — fail closed rather than risk resuming
				// against a tampered or corrupted ChangeSet.
				return $this->failClosedOnUnexpectedThrow( $change_set_id, $e );
			}
			if ( null === $change_set ) {
				return MutationResult::rejected( $change_set_id, 'Unknown ChangeSet.' );
			}
			$this->repository->recordAttempt( $change_set_id );

			try {
				$result = $this->engine->resumeApproved( $approval_id, $change_set, $decision, $deciding_user, $this->journalEventHook( $change_set_id, $change_set ) );
			} catch ( \Throwable $e ) {
				return $this->failClosedOnUnexpectedThrow( $change_set_id, $e );
			}
			$this->reflectFromPendingApproval( $change_set_id, $result );
			return $result;
		} finally {
			$this->repository->releaseLease( $change_set_id, $owner_token );
		}
	}

	/**
	 * Crash recovery (Sprint 0.3A Phase 2 final hardening, Package E).
	 * Called for a ChangeSet a caller suspects was interrupted mid-flight
	 * (e.g. an operations dashboard listing durable rows stuck in a
	 * non-terminal state with no active lease) — NEVER as part of the
	 * normal submit()/resume() path, and never automatically.
	 *
	 * This does not attempt to determine "was it safe to auto-continue"
	 * for a partially-applied ChangeSet: several of the six operation
	 * types are not safely re-appliable (FileCreateOperation once the
	 * file exists; FileDeleteOperation once the file is already gone),
	 * so blind auto-continuation across mixed idempotency is unsafe.
	 * Only the two unambiguous edge cases skip escalation:
	 *
	 *   - the ChangeSet is already terminal (nothing to recover), or
	 *   - every one of its operations' journal rows shows apply() was
	 *     never even attempted (still SNAPSHOTTED/PENDING — this is the
	 *     ordinary "awaiting approval" or "never submitted for apply"
	 *     state, not a crash).
	 *
	 * Anything else — a single operation applied-but-not-verified, a
	 * ChangeSet where operation 2 of 3 crashed before starting, a
	 * rollback that itself failed — fails closed to
	 * ChangeSetState::MANUAL_RECOVERY_REQUIRED, a terminal state that
	 * retention purge (AIOS\Mutation\ChangeSetRepository::
	 * purgeTerminalOlderThan()) never removes.
	 */
	public function recover( string $change_set_id ): MutationResult {
		$row = $this->repository->load( $change_set_id );
		if ( null === $row ) {
			return MutationResult::rejected( $change_set_id, 'Unknown ChangeSet.' );
		}

		$state = (string) $row['state'];
		if ( ChangeSetState::isTerminal( $state ) ) {
			return MutationResult::recoveryNotNeeded( $change_set_id );
		}

		$journal_rows = $this->journal->loadForChangeSet( $change_set_id );
		if ( array() === $journal_rows ) {
			// Nothing was ever journaled for this ChangeSet (e.g. it
			// predates this journal, or Policy rejected it before any
			// row was created) — there is no per-operation evidence to
			// recover from either way.
			return MutationResult::recoveryNotNeeded( $change_set_id );
		}

		$never_touched = array( OperationJournalState::PENDING, OperationJournalState::SNAPSHOTTED );
		$all_clean     = true;
		foreach ( $journal_rows as $jrow ) {
			if ( ! in_array( (string) $jrow['state'], $never_touched, true ) ) {
				$all_clean = false;
				break;
			}
		}
		if ( $all_clean ) {
			return MutationResult::recoveryNotNeeded( $change_set_id );
		}

		$error = 'Crash recovery found this ChangeSet mid-flight (at least one operation was applied or is in an in-flight state) with no evidence it reached a terminal outcome. Automatic continuation is not attempted — manual review of the operation journal is required.';
		$this->markManualRecovery( $change_set_id, $state );
		$this->auditRecoveryEscalation( $change_set_id, $row, $error );
		return MutationResult::manualRecoveryRequired( $change_set_id, $error );
	}

	/**
	 * Recovery audit lifecycle (Package L): escalating a ChangeSet to
	 * MANUAL_RECOVERY_REQUIRED is itself a security-relevant event —
	 * it means a mutation may be sitting in an unknown/partial state on
	 * live WordPress data — so it is always audited, independent of
	 * (and never gated by) whether the underlying state transition
	 * actually happened. No acting WP_User: recover() is an operator/
	 * maintenance action, not something performed on behalf of the
	 * original principal.
	 */
	/**
	 * @param array<string, mixed> $row
	 */
	private function auditRecoveryEscalation( string $change_set_id, array $row, string $error ): void {
		$this->audit->log(
			array(
				'user'             => null,
				'principal_type'   => 'system',
				'client'           => 'mutation-coordinator',
				'tool'             => 'mutation.recover',
				'action'           => sprintf( 'changeset %s: manual_recovery_required', $change_set_id ),
				'risk'             => (int) ( $row['risk'] ?? 0 ),
				'status'           => AuditLogger::STATUS_BLOCKED,
				'error'            => $error,
				'affected_objects' => array(
					array(
						'type'   => 'change_set',
						'target' => $change_set_id,
					),
				),
			)
		);
	}

	private function markManualRecovery( string $change_set_id, string $current_state ): void {
		if ( ! ChangeSetState::isValidTransition( $current_state, ChangeSetState::MANUAL_RECOVERY_REQUIRED ) ) {
			return; // Already at/past a state that does not need this — never force it.
		}
		$row = $this->repository->load( $change_set_id );
		if ( null === $row ) {
			return;
		}
		$this->repository->transition( $change_set_id, $current_state, ChangeSetState::MANUAL_RECOVERY_REQUIRED, (int) $row['state_version'] );
	}

	// ---------------------------------------------------------------- per-operation journal

	private function createJournalRows( ChangeSet $change_set ): void {
		foreach ( array_values( $change_set->operations() ) as $index => $operation ) {
			$this->journal->create( $change_set->id(), $index, $operation, $change_set->siteId() );
		}
	}

	/**
	 * Builds the MutationEngine $on_event hook that journals every
	 * per-operation lifecycle event into OperationJournalRepository.
	 * Never makes a security decision — every decision MutationEngine
	 * makes is made before this fires; this only records what already
	 * happened, exactly like reflectFromPlanned()/reflectFromPendingApproval()
	 * do for the ChangeSet-level state.
	 *
	 * Journal rows are addressed by OPERATION INDEX, not operation id:
	 * AbstractOperation generates a fresh random id on every
	 * construction, so the resumeApproved() path — which hands
	 * MutationEngine a freshly REHYDRATED ChangeSet (OperationRegistry::
	 * rehydrate(), via ChangeSetRepository::loadChangeSet()) — fires
	 * events for operation OBJECTS with different ids than the ones
	 * submit() originally journaled under. Only each operation's fixed
	 * position within $change_set->operations() survives a rehydrate.
	 * $index_by_object resolves an event's operation object back to
	 * that position for THIS specific $change_set instance/call.
	 *
	 * Idempotent by construction (advance() only transitions when the
	 * row is still at an expected "from" state) because resumeApproved()
	 * re-runs captureSnapshots() a SECOND time — its 'snapshot_captured'
	 * events must not fail just because the row already left PENDING
	 * during the original submit().
	 *
	 * Fault-injection matrix items C/D (Sprint 0.3A Phase 2 exit-gate
	 * closure): 'snapshot_captured' and 'apply_started' use STRICT
	 * advance() — a durable write failure there throws, which
	 * MutationEngine is specifically written to route through its
	 * existing snapshot-failure / apply-failure-and-rollback paths (see
	 * MutationEngine::captureSnapshots()'s callers and the try block in
	 * applyChangeSet()), so live WordPress state is never mutated
	 * without first durably recording the intent, and anything already
	 * applied earlier in the same ChangeSet is rolled back exactly as
	 * if apply() itself had failed. Every event AFTER an operation's own
	 * apply() has already run (apply_completed onward) intentionally
	 * stays lenient/best-effort: MutationEngine has no equivalent "undo
	 * what already happened" hook for a POST-mutation journal failure,
	 * so throwing there would abandon the rollback loop for any sibling
	 * operation partway through rather than making anything safer — a
	 * deliberate, documented scope boundary, not an oversight.
	 */
	private function journalEventHook( string $change_set_id, ChangeSet $change_set ): \Closure {
		$index_by_object = array();
		foreach ( array_values( $change_set->operations() ) as $index => $operation ) {
			$index_by_object[ spl_object_id( $operation ) ] = $index;
		}

		return function ( string $event, ChangeOperationInterface $operation, array $context ) use ( $change_set_id, $index_by_object ): void {
			$operation_index = $index_by_object[ spl_object_id( $operation ) ] ?? null;
			if ( null === $operation_index ) {
				return; // Not one of this ChangeSet's own operations — should be unreachable.
			}

			switch ( $event ) {
				case 'snapshot_captured':
					/** @var Snapshot $snapshot */
					$snapshot = $context['snapshot'];
					$state    = $snapshot->state();
					// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- snapshot-hash input: plain json_encode() keeps byte-stable output across WP versions (P2-09 policy).
					$json = (string) json_encode( $state, JSON_UNESCAPED_SLASHES );
					if ( ! $this->journal->saveRecovery( $change_set_id, $operation_index, $state, hash( 'sha256', $json ) ) ) {
						throw new MutationException( 'operation_journal.write_failed', 'Failed to durably persist this operation\'s recovery state — refusing to proceed to Diff/Approval/Apply without it.' );
					}
					$this->advance( $change_set_id, $operation_index, array( OperationJournalState::PENDING ), OperationJournalState::SNAPSHOTTED, array(), true );
					break;

				case 'apply_started':
					$this->advance( $change_set_id, $operation_index, array( OperationJournalState::SNAPSHOTTED ), OperationJournalState::APPLYING, array(), true );
					$this->journal->markTimestamp( $change_set_id, $operation_index, 'apply_started_at' );
					break;

				case 'apply_completed':
					$this->advance( $change_set_id, $operation_index, array( OperationJournalState::APPLYING ), OperationJournalState::APPLIED );
					$this->journal->markTimestamp( $change_set_id, $operation_index, 'apply_completed_at' );
					break;

				case 'apply_failed':
					$error = substr( (string) ( $context['error'] ?? '' ), 0, 191 );
					$this->advance( $change_set_id, $operation_index, array( OperationJournalState::APPLYING ), OperationJournalState::FAILED, array( 'failure_code' => $error ) );
					break;

				case 'stale_state_detected':
					// This operation's own apply() never ran — the stale
					// check happens immediately before it, for the FIRST
					// not-yet-applied operation only.
					$this->advance( $change_set_id, $operation_index, array( OperationJournalState::SNAPSHOTTED ), OperationJournalState::FAILED, array( 'failure_code' => 'stale_state' ) );
					break;

				case 'verify_started':
					$this->advance( $change_set_id, $operation_index, array( OperationJournalState::APPLIED ), OperationJournalState::VERIFYING );
					$this->journal->markTimestamp( $change_set_id, $operation_index, 'verify_started_at' );
					break;

				case 'verify_completed':
					/** @var VerificationResult $verification */
					$verification = $context['verification'];
					$this->journal->markTimestamp( $change_set_id, $operation_index, 'verify_completed_at' );
					if ( $verification->ok() ) {
						$this->advance( $change_set_id, $operation_index, array( OperationJournalState::VERIFYING ), OperationJournalState::VERIFIED );
					} else {
						$this->advance(
							$change_set_id,
							$operation_index,
							array( OperationJournalState::VERIFYING ),
							OperationJournalState::ROLLBACK_REQUIRED,
							array( 'failure_code' => substr( $verification->message(), 0, 191 ) )
						);
					}
					break;

				case 'rollback_started':
					// APPLIED/VERIFIED operations must pass through
					// ROLLBACK_REQUIRED before ROLLING_BACK; an operation
					// whose OWN verify() already failed reached
					// ROLLBACK_REQUIRED via the 'verify_completed' branch
					// above already, so this is a no-op for it here.
					$this->advance( $change_set_id, $operation_index, array( OperationJournalState::APPLIED, OperationJournalState::VERIFIED ), OperationJournalState::ROLLBACK_REQUIRED );
					$this->advance( $change_set_id, $operation_index, array( OperationJournalState::ROLLBACK_REQUIRED ), OperationJournalState::ROLLING_BACK );
					$this->journal->markTimestamp( $change_set_id, $operation_index, 'rollback_started_at' );
					break;

				case 'rollback_completed':
					/** @var RollbackRecord $record */
					$record = $context['rollback'];
					$this->journal->markTimestamp( $change_set_id, $operation_index, 'rollback_completed_at' );
					$extra = $record->ok() ? array() : array( 'failure_code' => substr( $record->message(), 0, 191 ) );
					$this->advance( $change_set_id, $operation_index, array( OperationJournalState::ROLLING_BACK ), $record->ok() ? OperationJournalState::ROLLED_BACK : OperationJournalState::ROLLBACK_FAILED, $extra );
					break;
			}
		};
	}

	/**
	 * Load the journal row's CURRENT state and transition only when it
	 * is still one of $allowed_from. A row already past $allowed_from
	 * (e.g. resumeApproved()'s second 'snapshot_captured' firing for a
	 * row already SNAPSHOTTED) is ALWAYS a legitimate no-op, strict or
	 * not — that is not a failure to report.
	 *
	 * $strict distinguishes what happens when the row WAS at an
	 * allowed state but the CAS transition() itself then failed (a
	 * real durable-write fault, not a legitimate skip): non-strict
	 * (the default — every event from 'apply_completed' onward)
	 * silently proceeds, matching this hook's general "never make a
	 * security decision" contract; strict (only 'snapshot_captured'
	 * and 'apply_started' — see this class's journalEventHook()
	 * docblock for why exactly those two) throws, and MutationEngine
	 * is specifically written to catch that the same way it catches a
	 * real apply()/captureSnapshot() failure.
	 *
	 * @param string[] $allowed_from
	 * @param array<string, mixed> $extra_fields
	 * @throws MutationException "operation_journal.write_failed" when $strict and the CAS transition itself failed.
	 */
	private function advance( string $change_set_id, int $operation_index, array $allowed_from, string $to, array $extra_fields = array(), bool $strict = false ): void {
		$row = $this->journal->load( $change_set_id, $operation_index );
		if ( null === $row ) {
			if ( $strict ) {
				throw new MutationException( 'operation_journal.write_failed', 'No journal row exists for this operation — refusing to proceed without one.' );
			}
			return;
		}
		$from = (string) $row['state'];
		if ( ! in_array( $from, $allowed_from, true ) ) {
			return; // Always a legitimate skip, never an error — see docblock.
		}
		$ok = $this->journal->transition( $change_set_id, $operation_index, $from, $to, (int) $row['state_version'], $extra_fields );
		if ( ! $ok && $strict ) {
			throw new MutationException( 'operation_journal.write_failed', sprintf( 'Failed to durably record operation state %s -> %s.', $from, $to ) );
		}
	}

	// ---------------------------------------------------------------- durable-state reflection

	private function reflectFromPlanned( string $id, MutationResult $result ): void {
		$path = match ( $result->status() ) {
			MutationResult::STATUS_POLICY_DENIED      => array( ChangeSetState::POLICY_REJECTED ),
			MutationResult::STATUS_SNAPSHOT_FAILED     => array( ChangeSetState::FAILED ),
			MutationResult::STATUS_APPROVAL_REQUIRED   => array( ChangeSetState::SNAPSHOTTED, ChangeSetState::DIFF_READY, ChangeSetState::PENDING_APPROVAL ),
			MutationResult::STATUS_APPLIED             => array( ChangeSetState::SNAPSHOTTED, ChangeSetState::DIFF_READY, ChangeSetState::APPLYING, ChangeSetState::VERIFYING, ChangeSetState::COMPLETED ),
			MutationResult::STATUS_APPLY_FAILED,
			MutationResult::STATUS_STALE_STATE         => array_merge(
				array( ChangeSetState::SNAPSHOTTED, ChangeSetState::DIFF_READY, ChangeSetState::APPLYING, ChangeSetState::FAILED ),
				$this->rollbackTail( $result )
			),
			MutationResult::STATUS_VERIFICATION_FAILED => array_merge(
				array( ChangeSetState::SNAPSHOTTED, ChangeSetState::DIFF_READY, ChangeSetState::APPLYING, ChangeSetState::VERIFYING, ChangeSetState::ROLLBACK_REQUIRED ),
				$this->rollbackTail( $result, /* already at ROLLBACK_REQUIRED */ true )
			),
			default => array(),
		};
		$this->walk( $id, $path, $result );
	}

	private function reflectFromPendingApproval( string $id, MutationResult $result ): void {
		$path = match ( $result->status() ) {
			MutationResult::STATUS_REJECTED            => array( ChangeSetState::REJECTED ),
			MutationResult::STATUS_FINGERPRINT_MISMATCH,
			MutationResult::STATUS_POLICY_DENIED       => array( ChangeSetState::FAILED ),
			MutationResult::STATUS_SNAPSHOT_FAILED     => array( ChangeSetState::APPROVED, ChangeSetState::FAILED ),
			MutationResult::STATUS_APPLIED             => array( ChangeSetState::APPROVED, ChangeSetState::APPLYING, ChangeSetState::VERIFYING, ChangeSetState::COMPLETED ),
			MutationResult::STATUS_APPLY_FAILED,
			MutationResult::STATUS_STALE_STATE         => array_merge(
				array( ChangeSetState::APPROVED, ChangeSetState::APPLYING, ChangeSetState::FAILED ),
				$this->rollbackTail( $result )
			),
			MutationResult::STATUS_VERIFICATION_FAILED => array_merge(
				array( ChangeSetState::APPROVED, ChangeSetState::APPLYING, ChangeSetState::VERIFYING, ChangeSetState::ROLLBACK_REQUIRED ),
				$this->rollbackTail( $result, true )
			),
			default => array(),
		};
		$this->walk( $id, $path, $result );
	}

	/**
	 * @return string[] [ROLLING_BACK, (ROLLED_BACK|ROLLBACK_FAILED)], or [] when there was nothing to roll back.
	 */
	private function rollbackTail( MutationResult $result, bool $already_at_rollback_required = false ): array {
		if ( array() === $result->rollbacks() ) {
			return $already_at_rollback_required ? array( ChangeSetState::ROLLING_BACK, ChangeSetState::ROLLED_BACK ) : array( ChangeSetState::ROLLBACK_REQUIRED, ChangeSetState::ROLLING_BACK, ChangeSetState::ROLLED_BACK );
		}
		$all_ok = true;
		foreach ( $result->rollbacks() as $record ) {
			if ( ! $record->ok() ) {
				$all_ok = false;
				break;
			}
		}
		$terminal = $all_ok ? ChangeSetState::ROLLED_BACK : ChangeSetState::ROLLBACK_FAILED;
		return $already_at_rollback_required
			? array( ChangeSetState::ROLLING_BACK, $terminal )
			: array( ChangeSetState::ROLLBACK_REQUIRED, ChangeSetState::ROLLING_BACK, $terminal );
	}

	/**
	 * @param string[] $path
	 */
	private function walk( string $id, array $path, MutationResult $result ): void {
		$row = $this->repository->load( $id );
		if ( null === $row ) {
			return;
		}
		$from       = (string) $row['state'];
		$version    = (int) $row['state_version'];
		$last_index = count( $path ) - 1;

		foreach ( array_values( $path ) as $index => $to ) {
			if ( ! ChangeSetState::isValidTransition( $from, $to ) ) {
				return; // Fail closed: never force an illegal transition, even internally.
			}
			$extra = ( $index === $last_index ) ? $this->terminalExtras( $to, $result ) : array();
			if ( ! $this->repository->transition( $id, $from, $to, $version, $extra ) ) {
				return; // CAS lost the race (row changed under us) — stop, do not force further.
			}
			$from = $to;
			++$version;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function terminalExtras( string $to, MutationResult $result ): array {
		$extras = array();
		if ( in_array( $to, array( ChangeSetState::FAILED, ChangeSetState::ROLLBACK_FAILED, ChangeSetState::POLICY_REJECTED, ChangeSetState::REJECTED ), true ) && null !== $result->error() ) {
			$extras['error_code'] = substr( $result->status(), 0, 80 );
		}
		if ( null !== $result->approvalId() ) {
			$extras['approval_id'] = $result->approvalId();
		}
		return $extras;
	}
}
