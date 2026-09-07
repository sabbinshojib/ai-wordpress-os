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

use AIOS\Security\PathGuard;
use WP_User;

final class DurableMutationCoordinator {

	public function __construct(
		private readonly ChangeSetRepository $repository,
		private readonly MutationEngine $engine
	) {}

	/**
	 * Persist the ChangeSet, then run it through MutationEngine::submit().
	 * Idempotent: a second submit() with the same $idempotency_key never
	 * creates a duplicate durable row or re-runs a completed mutation.
	 */
	public function submit( ChangeSet $change_set, WP_User $acting, ?string $idempotency_key = null ): MutationResult {
		$fingerprint = ChangeSetFingerprint::compute( $change_set );
		$row         = $this->repository->create( $change_set, $fingerprint, $idempotency_key );

		if ( $row['change_set_id'] !== $change_set->id() ) {
			// Idempotent hit: create() returned a PRE-EXISTING row for this key.
			if ( ChangeSetState::COMPLETED === $row['state'] ) {
				return MutationResult::alreadyCompleted( $row['change_set_id'] );
			}
			return MutationResult::rejected( $change_set->id(), 'An in-progress or already-decided ChangeSet exists for this idempotency key.' );
		}

		$result = $this->engine->submit( $change_set, $acting );
		$this->reflectFromPlanned( $change_set->id(), $result );
		return $result;
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
			$change_set = $this->repository->loadChangeSet( $change_set_id, $path_guard );
			if ( null === $change_set ) {
				return MutationResult::rejected( $change_set_id, 'Unknown ChangeSet.' );
			}
			$this->repository->recordAttempt( $change_set_id );

			$result = $this->engine->resumeApproved( $approval_id, $change_set, $decision, $deciding_user );
			$this->reflectFromPendingApproval( $change_set_id, $result );
			return $result;
		} finally {
			$this->repository->releaseLease( $change_set_id, $owner_token );
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
		$from      = (string) $row['state'];
		$version   = (int) $row['state_version'];
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
			$version++;
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
