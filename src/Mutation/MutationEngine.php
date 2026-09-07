<?php
/**
 * Phase 2 mutation pipeline orchestrator (design: docs/ARCHITECTURE.md
 * §13). Implements, as real code:
 *
 *   Policy → Snapshot → Diff → Approval → Apply → Verify → Audit → Rollback
 *
 * with the following hardening (Sprint 0.3A Phase 2 hardening pass):
 *
 *   - Diff is a real, reviewable, redacted before/after (DiffRenderer/
 *     MutationDiff/OperationDiff), computed AFTER Snapshot and BEFORE
 *     Approval — not a one-line summary.
 *   - Approval is bound to a canonical ChangeSetFingerprint (id, site,
 *     actor, ordered operations' type/target/payload-fingerprint, risk,
 *     diff hash). resumeApproved() recomputes the fingerprint from the
 *     ChangeSet the caller hands back and rejects on any mismatch —
 *     an approval can never be replayed against a different, reordered,
 *     retargeted, or repayloaded ChangeSet.
 *   - Every operation is re-validated against its LIVE current state
 *     immediately before apply() (currentPreconditionFingerprint() vs.
 *     the fingerprint captured in its Snapshot) — a stale-state/TOCTOU
 *     drift between Snapshot/Approval and Apply fails closed rather
 *     than applying against different state than was reviewed.
 *   - The ChangeSet's own site is bound for the duration of Snapshot/
 *     Diff/Apply/Rollback via switch_to_blog()/restore_current_blog(),
 *     so a cross-site resume can never silently mutate the wrong site.
 *
 * "Planner" (turning a free-form request into a typed ChangeSet) and
 * "User Request" are the caller's responsibility — this class starts
 * at Policy, on an already-constructed ChangeSet. No step here can be
 * skipped, reordered, or bypassed by a shortcut: submit() is the only
 * public entry point that runs an operation's apply(), and it always
 * runs Policy, Snapshot, and Diff first, unconditionally.
 *
 * Reuses existing Phase 1 infrastructure rather than parallel
 * implementations: AIOS\Security\PermissionEngine for Policy,
 * AIOS\Database\Repositories\ApprovalRepository for Approval,
 * AIOS\Audit\AuditLogger for Audit.
 *
 * Known, explicitly-scoped gap (see docs/audits/SPRINT-0.3-SECURITY-CI-REPORT.md
 * and docs/ARCHITECTURE.md §13): this class does not yet persist a
 * ChangeSet's operations across requests — the approval row is durable
 * (real Phase 1 infrastructure), but the in-memory ChangeSet/Snapshot
 * objects are not. resumeApproved() requires the caller to reconstruct
 * and pass back the same ChangeSet; the fingerprint check is exactly
 * what makes that safe (a caller cannot pass back a DIFFERENT
 * ChangeSet and have it silently accepted under someone else's
 * approval). A durable ChangeSet store is tracked as a separate,
 * explicitly-scoped follow-up.
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
	 *
	 * @param callable(string, ChangeOperationInterface, array<string,mixed>): void|null $on_event
	 *        Optional per-operation lifecycle hook — invoked with an event name
	 *        ('snapshot_captured', 'apply_started', 'apply_completed', 'apply_failed',
	 *        'verify_started', 'verify_completed', 'stale_state_detected',
	 *        'rollback_started', 'rollback_completed'), the operation, and a small
	 *        context array (e.g. ['snapshot' => Snapshot] or ['error' => string]).
	 *        Exists so a durable caller (AIOS\Mutation\DurableMutationCoordinator)
	 *        can journal each step without this class knowing anything about
	 *        persistence — never called for anything security-relevant; every
	 *        decision this class makes is made before the hook fires, not because of it.
	 */
	public function submit( ChangeSet $change_set, WP_User $acting, ?callable $on_event = null ): MutationResult {
		$policy_error = $this->checkPolicy( $change_set, $acting );
		if ( null !== $policy_error ) {
			$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_POLICY_DENIED, $policy_error );
			return MutationResult::policyDenied( $change_set->id(), $policy_error );
		}

		return $this->withSiteContext(
			$change_set,
			function () use ( $change_set, $acting, $on_event ): MutationResult {
				try {
					$snapshots = $this->captureSnapshots( $change_set, $on_event );
				} catch ( MutationException $e ) {
					$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_SNAPSHOT_FAILED, $e->getMessage() );
					return MutationResult::snapshotFailed( $change_set->id(), $e->getMessage() );
				}

				$diff        = DiffRenderer::render( $change_set, $snapshots );
				$diff_hash   = $diff->hash();
				$fingerprint = ChangeSetFingerprint::compute( $change_set, $diff_hash );

				if ( $this->permissions->requiresApproval( $change_set->riskLevel() ) ) {
					$approval_id = $this->approvals->create(
						array(
							'user_id' => $acting->ID,
							'client'  => 'mutation-engine',
							'tool'    => 'mutation.apply',
							'args'    => array(
								'change_set_id' => $change_set->id(),
								'fingerprint'   => $fingerprint,
								'diff_hash'     => $diff_hash,
								'description'   => $change_set->describe(),
								'preconditions' => $this->preconditionsOf( $snapshots ),
							),
							'risk'            => $change_set->riskLevel(),
							'reason'          => (string) ( $change_set->metadata()['reason'] ?? '' ),
							'preview'         => $this->buildPreview( $change_set, $diff ),
							'expires_minutes' => 15,
						)
					);
					$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_APPROVAL_REQUIRED, null, $approval_id );
					return MutationResult::approvalRequired( $change_set->id(), $approval_id );
				}

				// Auto-execution ceiling (mode ← WP caps ← grants ← key) —
				// a defensive re-check: Settings' mode presets never leave a
				// gap between maxAutoLevel and approvalThreshold, so
				// reaching here with an insufficient ceiling should be
				// unreachable, but this class fails closed rather than
				// assuming that invariant holds.
				$ceiling = $this->permissions->ceilingFor( $acting );
				if ( $change_set->riskLevel() > $ceiling ) {
					$error = sprintf( 'Auto-execution ceiling %d is below the required level %d.', $ceiling, $change_set->riskLevel() );
					$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_POLICY_DENIED, $error );
					return MutationResult::policyDenied( $change_set->id(), $error );
				}

				return $this->applyChangeSet( $change_set, $acting, $snapshots, null, null, $on_event );
			}
		);
	}

	/**
	 * Continue a ChangeSet that required approval, after a human
	 * decision. The caller must reconstruct the SAME ChangeSet (same
	 * id, same operations in the same order, same payloads) — the
	 * fingerprint check below is what makes handing back a DIFFERENT
	 * ChangeSet under the same approval id fail safely instead of
	 * silently applying the wrong thing.
	 */
	public function resumeApproved( int $approval_id, ChangeSet $change_set, string $decision, WP_User $deciding_user, ?callable $on_event = null ): MutationResult {
		$approval = $this->approvals->claimPending( $approval_id, $decision, $deciding_user->ID );
		if ( null === $approval ) {
			return MutationResult::rejected( $change_set->id(), 'Approval is missing, already decided, or expired.' );
		}

		$args = (array) ( $approval['args'] ?? array() );
		if ( ( $args['change_set_id'] ?? null ) !== $change_set->id() ) {
			$this->auditAttempt( $change_set, $deciding_user, MutationResult::STATUS_FINGERPRINT_MISMATCH, 'approval change_set_id does not match', $approval_id );
			return MutationResult::fingerprintMismatch( $change_set->id(), 'This approval does not belong to this ChangeSet.' );
		}

		if ( ApprovalRepository::STATUS_REJECTED === $approval['status'] ) {
			$this->auditAttempt( $change_set, $deciding_user, MutationResult::STATUS_REJECTED, null, $approval_id );
			return MutationResult::rejected( $change_set->id(), 'Approval was rejected.' );
		}

		$stored_fingerprint = (string) ( $args['fingerprint'] ?? '' );
		$stored_diff_hash   = isset( $args['diff_hash'] ) ? (string) $args['diff_hash'] : null;
		$recomputed         = ChangeSetFingerprint::compute( $change_set, $stored_diff_hash );
		if ( '' === $stored_fingerprint || ! hash_equals( $stored_fingerprint, $recomputed ) ) {
			$this->auditAttempt( $change_set, $deciding_user, MutationResult::STATUS_FINGERPRINT_MISMATCH, 'ChangeSet no longer matches what was approved', $approval_id );
			return MutationResult::fingerprintMismatch( $change_set->id(), 'This ChangeSet no longer matches what was reviewed and approved — a fresh Snapshot/Diff/Approval is required.' );
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

		/** @var array<string, ?string> $original_preconditions operation id => precondition fingerprint AT SUBMISSION TIME. */
		$original_preconditions = (array) ( $args['preconditions'] ?? array() );

		return $this->withSiteContext(
			$change_set,
			function () use ( $change_set, $deciding_user, $approval_id, $original_preconditions, $on_event ): MutationResult {
				try {
					// Re-snapshot fresh — this captures accurate CURRENT
					// state for rollback data, but the staleness check
					// below deliberately compares against the preconditions
					// captured at SUBMISSION time ($original_preconditions),
					// not against this fresh snapshot's own precondition
					// (which would trivially match itself and could never
					// detect drift that happened during the approval wait).
					$snapshots = $this->captureSnapshots( $change_set, $on_event );
				} catch ( MutationException $e ) {
					$this->auditAttempt( $change_set, $deciding_user, MutationResult::STATUS_SNAPSHOT_FAILED, $e->getMessage(), $approval_id );
					return MutationResult::snapshotFailed( $change_set->id(), $e->getMessage() );
				}
				return $this->applyChangeSet( $change_set, $deciding_user, $snapshots, $approval_id, $original_preconditions, $on_event );
			}
		);
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
	private function captureSnapshots( ChangeSet $change_set, ?callable $on_event = null ): array {
		$snapshots = array();
		foreach ( $change_set->operations() as $operation ) {
			$snapshot = $operation->captureSnapshot();
			$snapshots[ $operation->id() ] = $snapshot;
			if ( null !== $on_event ) {
				$on_event( 'snapshot_captured', $operation, array( 'snapshot' => $snapshot ) );
			}
		}
		return $snapshots;
	}

	/**
	 * @param array<string, Snapshot> $snapshots
	 * @return array<string, ?string> operation id => precondition fingerprint, for storing alongside an
	 *         approval so a later resumeApproved() can detect drift against SUBMISSION-time state.
	 */
	private function preconditionsOf( array $snapshots ): array {
		$preconditions = array();
		foreach ( $snapshots as $operation_id => $snapshot ) {
			$preconditions[ $operation_id ] = isset( $snapshot->state()['precondition'] ) ? (string) $snapshot->state()['precondition'] : null;
		}
		return $preconditions;
	}

	private function buildPreview( ChangeSet $change_set, MutationDiff $diff ): string {
		$lines = array();
		foreach ( $diff->operations as $operation_diff ) {
			$lines[] = sprintf(
				'%s: %s [%s]%s',
				$operation_diff->type,
				$operation_diff->target,
				$operation_diff->kind,
				$operation_diff->truncated ? ' (truncated)' : ''
			);
		}
		return implode( "\n", $lines );
	}

	/**
	 * Apply → Verify → Audit → Rollback-on-failure, for every operation
	 * in order. A single operation's apply() failure, or a stale-state
	 * precondition mismatch detected immediately before its apply(),
	 * rolls back every operation already applied in this ChangeSet, in
	 * reverse order.
	 *
	 * @param array<string, Snapshot>  $snapshots
	 * @param array<string, ?string>|null $expected_preconditions Operation id => precondition fingerprint to
	 *        require RIGHT NOW, before apply(). When null, each operation is checked against its own
	 *        just-captured $snapshots entry (the auto-apply path: submission and apply happen in the same
	 *        call, so this only guards against drift DURING the apply loop itself). When provided (the
	 *        resumeApproved() path), this MUST be the preconditions captured at submission time — comparing
	 *        against a freshly re-captured snapshot's own precondition would be vacuous, since it would
	 *        always match itself regardless of what changed during the approval wait.
	 */
	private function applyChangeSet( ChangeSet $change_set, WP_User $acting, array $snapshots, ?int $approval_id = null, ?array $expected_preconditions = null, ?callable $on_event = null ): MutationResult {
		$applied = array(); // operation ids applied so far, in order.

		foreach ( $change_set->operations() as $operation ) {
			$snapshot = $snapshots[ $operation->id() ] ?? null;
			$expected = null !== $expected_preconditions
				? ( $expected_preconditions[ $operation->id() ] ?? null )
				: ( $snapshot?->state()['precondition'] ?? null );
			if ( null !== $expected && ! hash_equals( (string) $expected, $operation->currentPreconditionFingerprint() ) ) {
				if ( null !== $on_event ) {
					$on_event( 'stale_state_detected', $operation, array() );
				}
				$rollbacks = $this->rollbackApplied( $applied, $snapshots, $on_event );
				$error     = sprintf( 'stale state detected for operation %s (%s: %s) — target changed since Snapshot/Approval', $operation->id(), $operation->type(), $operation->target() );
				$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_STALE_STATE, $error, $approval_id );
				return MutationResult::staleState( $change_set->id(), $error, $rollbacks );
			}

			if ( null !== $on_event ) {
				$on_event( 'apply_started', $operation, array() );
			}
			try {
				$operation->apply();
				$applied[] = $operation;
				if ( null !== $on_event ) {
					$on_event( 'apply_completed', $operation, array() );
				}
			} catch ( MutationException $e ) {
				if ( null !== $on_event ) {
					$on_event( 'apply_failed', $operation, array( 'error' => $e->getMessage() ) );
				}
				$rollbacks = $this->rollbackApplied( $applied, $snapshots, $on_event );
				$this->auditAttempt( $change_set, $acting, MutationResult::STATUS_APPLY_FAILED, $e->getMessage(), $approval_id );
				return MutationResult::applyFailed( $change_set->id(), $e->getMessage(), $rollbacks );
			}
		}

		$verifications = array();
		$all_verified  = true;
		foreach ( $change_set->operations() as $operation ) {
			if ( null !== $on_event ) {
				$on_event( 'verify_started', $operation, array() );
			}
			$result          = $operation->verify();
			$verifications[] = $result;
			if ( null !== $on_event ) {
				$on_event( 'verify_completed', $operation, array( 'verification' => $result ) );
			}
			if ( ! $result->ok() ) {
				$all_verified = false;
			}
		}

		if ( ! $all_verified ) {
			$rollbacks = $this->rollbackApplied( $applied, $snapshots, $on_event );
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
	private function rollbackApplied( array $applied, array $snapshots, ?callable $on_event = null ): array {
		$records = array();
		foreach ( array_reverse( $applied ) as $operation ) {
			$snapshot = $snapshots[ $operation->id() ] ?? null;
			if ( null === $snapshot ) {
				$records[] = RollbackRecord::failure( $operation->id(), '', 'no snapshot available for this operation' );
				continue;
			}
			if ( null !== $on_event ) {
				$on_event( 'rollback_started', $operation, array() );
			}
			try {
				$record = $operation->rollback( $snapshot );
			} catch ( \Throwable $e ) {
				$record = RollbackRecord::failure( $operation->id(), $snapshot->id(), $e->getMessage() );
			}
			$records[] = $record;
			if ( null !== $on_event ) {
				$on_event( 'rollback_completed', $operation, array( 'rollback' => $record ) );
			}
		}
		return $records;
	}

	/**
	 * Binds the ChangeSet's own site for the duration of $callback:
	 * only switches (and always restores, even on an exception) when
	 * multisite is active and the current site differs from the
	 * ChangeSet's — a plain single-site install never pays for a
	 * switch/restore pair it does not need.
	 */
	private function withSiteContext( ChangeSet $change_set, \Closure $callback ): MutationResult {
		$needs_switch = function_exists( 'is_multisite' )
			&& is_multisite()
			&& function_exists( 'get_current_blog_id' )
			&& (int) get_current_blog_id() !== $change_set->siteId();

		if ( ! $needs_switch ) {
			return $callback();
		}

		switch_to_blog( $change_set->siteId() );
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}

	private function auditAttempt( ChangeSet $change_set, WP_User $acting, string $status, ?string $error, ?int $approval_id = null ): void {
		$audit_status = match ( $status ) {
			MutationResult::STATUS_APPLIED           => AuditLogger::STATUS_OK,
			MutationResult::STATUS_APPROVAL_REQUIRED  => AuditLogger::STATUS_APPROVAL,
			MutationResult::STATUS_REJECTED           => AuditLogger::STATUS_REJECTED,
			MutationResult::STATUS_POLICY_DENIED,
			MutationResult::STATUS_FINGERPRINT_MISMATCH,
			MutationResult::STATUS_STALE_STATE        => AuditLogger::STATUS_BLOCKED,
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
