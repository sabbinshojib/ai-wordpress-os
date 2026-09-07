<?php
/**
 * Contract every Phase 2 mutation operation must implement.
 *
 * A ChangeOperation is the only unit of mutation the pipeline knows
 * how to execute — there is no "run this shell command" or "run this
 * SQL" escape hatch anywhere in this contract. Every concrete
 * implementation (AIOS\Mutation\Operations\*) declares a fixed,
 * narrow `type()` and can only do what that type's own apply()/
 * rollback() logic does.
 *
 * Lifecycle, enforced by AIOS\Mutation\MutationEngine, never by the
 * operation itself:
 *
 *   captureSnapshot() → [Policy already checked] → apply() → verify()
 *                                                       │
 *                                            on failure ▼
 *                                                  rollback(snapshot)
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

interface ChangeOperationInterface {

	/**
	 * Stable per-instance id (set at construction, e.g. "op_<hex>").
	 */
	public function id(): string;

	/**
	 * Fixed, narrow operation type id, e.g. "file.create",
	 * "option.update". Never derived from user input — each concrete
	 * class returns its own literal constant.
	 */
	public function type(): string;

	/**
	 * Human-readable identifier of what this operation touches (a
	 * path, an option name, a post id) — safe to audit/display, never
	 * a secret.
	 */
	public function target(): string;

	/**
	 * PermissionEngine::LEVEL_* this operation requires.
	 */
	public function riskLevel(): int;

	/**
	 * Safe, non-secret summary for Diff/audit/approval-preview
	 * surfaces. Must never include full secret values (API keys,
	 * passwords) even if they happen to be part of the target content.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(): array;

	/**
	 * Capture whatever pre-state is needed to reverse this operation.
	 * MUST NOT mutate anything. Called before Policy/Approval have
	 * necessarily passed for OTHER operations in the same ChangeSet,
	 * but always after Policy has passed for THIS one.
	 *
	 * @throws MutationException When the pre-state cannot be captured
	 *                           (e.g. target does not exist for an
	 *                           operation that requires it to).
	 */
	public function captureSnapshot(): Snapshot;

	/**
	 * Perform the mutation. Only ever called after captureSnapshot()
	 * succeeded for this operation and Policy (and Approval, if
	 * required) passed for the whole ChangeSet.
	 *
	 * @throws MutationException On failure. Must leave no more than a
	 *                           single partial write behind — never a
	 *                           half-written multi-file/multi-record
	 *                           state a single rollback() cannot undo.
	 */
	public function apply(): void;

	/**
	 * Confirm the mutation had its intended — and only its intended —
	 * effect. Called immediately after a successful apply().
	 */
	public function verify(): VerificationResult;

	/**
	 * Restore the pre-state captured in $snapshot. Must be safe to
	 * call even when apply() never ran or failed partway through.
	 */
	public function rollback( Snapshot $snapshot ): RollbackRecord;
}
