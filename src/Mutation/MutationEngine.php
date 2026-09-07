<?php
/**
 * Phase 2 mutation pipeline orchestrator (design: docs/ARCHITECTURE.md
 * §13). Implements, as real code:
 *
 *   Policy → Snapshot → Diff → Approval → Apply → Verify → Audit → Rollback
 *
 * "Planner" (turning a free-form request into a typed ChangeSet) and
 * "User Request" are the caller's responsibility — this class starts
 * at Policy, on an already-constructed ChangeSet. No step here can be
 * skipped, reordered, or bypassed by a shortcut: submit() is the only
 * public entry point that runs an operation's apply(), and it always
 * runs Policy and Snapshot first, unconditionally.
 *
 * Reuses existing Phase 1 infrastructure rather than parallel
 * implementations: AIOS\Security\PermissionEngine for Policy,
 * AIOS\Database\Repositories\ApprovalRepository for Approval,
 * AIOS\Audit\AuditLogger for Audit.
 *
 * Known, explicitly-scoped gap (see docs/audits/SPRINT-0.3-SECURITY-CI-REPORT.md
 * and docs/ARCHITECTURE.md §13): this class does not persist a
 * ChangeSet's operations across requests. When approval is required,
 * an approval row is created (durable, reused Phase 1 infrastructure)
 * but the in-memory ChangeSet/Snapshot objects are not — resumeApproved()
 * requires the caller to reconstruct and pass back the same ChangeSet.
 * A durable ChangeSet store is future work, not solved by this
 * foundation.
 *
 * Not wired to any AI-facing tool, REST endpoint, or MCP surface.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

use AIOS\Audit\AuditLogger;
use AIOS\Database\Repositories\ApprovalRepository;
use AIOS\Security\PermissionEngine;
use WP_User;

final class MutationEngine {

	/**
	 * Options a ChangeSet may never target, mirroring ToolExecutor's
	 * escalation blocklist intent: a mutation must never be able to
	 * alter AI OS's own security state.
	 *
	 * @var string[]
	 */
	private const PROTECTED_OPTION_TARGETS = array(
		'ai_os_settings',
		PermissionEngine::GRANTS_OPTION,
	);

	public function __construct(
		private readonly PermissionEngine $permissions,
		private readonly ApprovalRepository $approvals,
		private readonly AuditLogger $audit
	) {}

	/**
	 * Run a ChangeSet through Policy → Snapshot → Diff → Approval →
	 * [Apply → Verify → Audit → Rollback-on-failure].
	 */
	public function submit( ChangeSet $change_set, WP_User $acting ): MutationResult {
		$policy_error = $this->checkPolicy( $change_set, $acting );
		if ( null !== $policy_error ) {
			$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_POLICY_DENIED, $policy_error );
			return MutationResult::policyDenied( $change_set->id(), $policy_error );
		}

		try {
			$snapshots = $this->captureSnapshots( $change_set );
		} catch ( MutationException $e ) {
			$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_SNAPSHOT_FAILED, $e->getMessage() );
			return MutationResult::snapshotFailed( $change_set->id(), $e->getMessage() );
		}

		if ( $this->permissions->requiresApproval( $change_set->riskLevel() ) ) {
			$approval_id = $this->approvals->create(
				array(
					'user_id'         => $acting->ID,
					'client'          => 'mutation-engine',
					'tool'            => 'mutation.apply',
					'args'            => array( 'change_set_id' => $change_set->id(), 'description' => $change_set->describe() ),
					'risk'            => $change_set->riskLevel(),
					'reason'          => (string) ( $change_set->metadata()['reason'] ?? '' ),
					'preview'         => $this->buildPreview( $change_set ),
					'expires_minutes' => 15,
				)
			);
			$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_APPROVAL_REQUIRED, null, $approval_id );
			return MutationResult::approvalRequired( $change_set->id(), $approval_id );
		}

		// Auto-execution ceiling (mode ← WP caps ← grants ← key) — a
		// defensive re-check: Settings' mode presets never leave a gap
		// between maxAutoLevel and approvalThreshold, so reaching here
		// with an insufficient ceiling should be unreachable, but this
		// class fails closed rather than assuming that invariant holds.
		$ceiling = $this->permissions->ceilingFor( $acting );
		if ( $change_set->riskLevel() > $ceiling ) {
			$error = sprintf( 'Auto-execution ceiling %d is below the required level %d.', $ceiling, $change_set->riskLevel() );
			$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_POLICY_DENIED, $error );
			return MutationResult::policyDenied( $change_set->id(), $error );
		}

		return $this->applyChangeSet( $change_set, $acting, $snapshots );
	}

	/**
	 * Continue a ChangeSet that required approval, after a human
	 * decision. The caller must reconstruct the SAME ChangeSet (same
	 * operations, same id) — see the class docblock's known-gap note.
	 */
	public function resumeApproved( int $approval_id, ChangeSet $change_set, string $decision, WP_User $deciding_user ): MutationResult {
		$approval = $this->approvals->claimPending( $approval_id, $decision, $deciding_user->ID );
		if ( null === $approval ) {
			return MutationResult::rejected( $change_set->id(), 'Approval is missing, already decided, or expired.' );
		}
		if ( ApprovalRepository::STATUS_REJECTED === $approval['status'] ) {
			$this->auditAttempt( $change_set, $deciding_user, MutationResult::STATUS_REJECTED, null, $approval_id );
			return MutationResult::rejected( $change_set->id(), 'Approval was rejected.' );
		}

		// Approval authorizes beyond the mode ceiling, but never beyond
		// the DECIDING user's real WordPress capabilities (matches
		// ApprovalsController::approve()'s exact rule).
		$ceiling = $this->permissions->capabilityCeilingFor( $deciding_user );
		if ( $change_set->riskLevel() > $ceiling ) {
			$error = sprintf( "Deciding user's capability ceiling %d is below the required level %d.", $ceiling, $change_set->riskLevel() );
			$this->auditAttempt( $change_set, $deciding_user, MutationResult::STATUS_POLICY_DENIED, $error, $approval_id );
			return MutationResult::policyDenied( $change_set->id(), $error );
		}

		try {
			$snapshots = $this->captureSnapshots( $change_set );
		} catch ( MutationException $e ) {
			$this->auditAttempt( $change_set, $deciding_user, MutationResult::STATUS_SNAPSHOT_FAILED, $e->getMessage(), $approval_id );
			return MutationResult::snapshotFailed( $change_set->id(), $e->getMessage() );
		}

		return $this->applyChangeSet( $change_set, $deciding_user, $snapshots, $approval_id );
	}

	// ---------------------------------------------------------------- pipeline steps

	private function checkPolicy( ChangeSet $change_set, WP_User $acting ): ?string {
		if ( ! $acting->exists() ) {
			return 'Authentication required.';
		}
		if ( ! PermissionEngine::canUse( $acting ) ) {
			return 'This principal is not permitted to use AI OS.';
		}
		foreach ( $change_set->operations() as $operation ) {
			if ( 'option.update' === $operation->type() && in_array( $operation->target(), self::PROTECTED_OPTION_TARGETS, true ) ) {
				return 'This ChangeSet targets a protected AI OS security option and is not permitted.';
			}
		}
		$ceiling = $this->permissions->capabilityCeilingFor( $acting );
		if ( $change_set->riskLevel() > $ceiling ) {
			return sprintf( 'This ChangeSet requires level %d; principal capability ceiling is %d.', $change_set->riskLevel(), $ceiling );
		}
		return null;
	}

	/**
	 * @return array<string, Snapshot> operation id => Snapshot
	 */
	private function captureSnapshots( ChangeSet $change_set ): array {
		$snapshots = array();
		foreach ( $change_set->operations() as $operation ) {
			$snapshots[ $operation->id() ] = $operation->captureSnapshot();
		}
		return $snapshots;
	}

	private function buildPreview( ChangeSet $change_set ): string {
		$lines = array();
		foreach ( $change_set->operations() as $operation ) {
			$lines[] = sprintf( '%s: %s', $operation->type(), $operation->target() );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Apply → Verify → Audit → Rollback-on-failure, for every operation
	 * in order. A single operation's apply() failure rolls back every
	 * operation already applied in this ChangeSet, in reverse order.
	 *
	 * @param array<string, Snapshot> $snapshots
	 */
	private function applyChangeSet( ChangeSet $change_set, WP_User $acting, array $snapshots, ?int $approval_id = null ): MutationResult {
		$applied = array(); // operation ids applied so far, in order.

		foreach ( $change_set->operations() as $operation ) {
			try {
				$operation->apply();
				$applied[] = $operation;
			} catch ( MutationException $e ) {
				$rollbacks = $this->rollbackApplied( $applied, $snapshots );
				$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_APPLY_FAILED, $e->getMessage(), $approval_id );
				return MutationResult::applyFailed( $change_set->id(), $e->getMessage(), $rollbacks );
			}
		}

		$verifications = array();
		$all_verified  = true;
		foreach ( $change_set->operations() as $operation ) {
			$result          = $operation->verify();
			$verifications[] = $result;
			if ( ! $result->ok() ) {
				$all_verified = false;
			}
		}

		if ( ! $all_verified ) {
			$rollbacks = $this->rollbackApplied( $applied, $snapshots );
			$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_VERIFICATION_FAILED, 'verification failed', $approval_id );
			return MutationResult::verificationFailed( $change_set->id(), $verifications, $rollbacks );
		}

		$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_APPLIED, null, $approval_id );
		if ( null !== $approval_id ) {
			$this->approvals->markExecuted( $approval_id, 'ok', wp_json_encode( array( 'status' => MutationResult::STATUS_APPLIED ) ) ?: null, null );
		}
		return MutationResult::applied( $change_set->id(), $verifications );
	}

	/**
	 * @param ChangeOperationInterface[] $applied In application order.
	 * @param array<string, Snapshot>    $snapshots
	 * @return RollbackRecord[]
	 */
	private function rollbackApplied( array $applied, array $snapshots ): array {
		$records = array();
		foreach ( array_reverse( $applied ) as $operation ) {
			$snapshot = $snapshots[ $operation->id() ] ?? null;
			if ( null === $snapshot ) {
				$records[] = RollbackRecord::failure( $operation->id(), '', 'no snapshot available for this operation' );
				continue;
			}
			try {
				$records[] = $operation->rollback( $snapshot );
			} catch ( \Throwable $e ) {
				$records[] = RollbackRecord::failure( $operation->id(), $snapshot->id(), $e->getMessage() );
			}
		}
		return $records;
	}

	private function auditAttempt( ChangeSet $change_set, WP_User $acting, string $status, ?string $error, ?int $approval_id = null ): void {
		$audit_status = match ( $status ) {
			MutationResult::STATUS_APPLIED           => AuditLogger::STATUS_OK,
			MutationResult::STATUS_APPROVAL_REQUIRED  => AuditLogger::STATUS_APPROVAL,
			MutationResult::STATUS_REJECTED           => AuditLogger::STATUS_REJECTED,
			MutationResult::STATUS_POLICY_DENIED      => AuditLogger::STATUS_BLOCKED,
			default                                   => AuditLogger::STATUS_ERROR,
		};

		$this->audit->log(
			array(
				'user'        => $acting,
				'client'      => 'mutation-engine',
				'tool'        => 'mutation.apply',
				'action'      => sprintf( 'changeset %s: %s', $change_set->id(), $status ),
				'risk'        => $change_set->riskLevel(),
				'status'      => $audit_status,
				'error'       => $error,
				'approval_id' => $approval_id,
				'affected_objects' => array_map(
					static fn( ChangeOperationInterface $op ): array => array( 'type' => $op->type(), 'target' => $op->target() ),
					$change_set->operations()
				),
			)
		);
	}
}
