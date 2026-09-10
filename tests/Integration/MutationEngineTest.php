<?php
/**
 * Integration tests: Phase 2 mutation pipeline (Sprint 0.3A Phase 2
 * foundation) — MutationEngine's Policy → Snapshot → Approval →
 * Apply → Verify → Audit → Rollback ordering, the six safe operation
 * types (AIOS\Mutation\Operations\*), and negative/attack coverage.
 *
 * Not wired to any AI-facing tool/REST/MCP surface — these tests
 * exercise MutationEngine and the operation classes directly, exactly
 * as any future caller (Planner, admin UI) would.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Database\Repositories\ApprovalRepository;
use AIOS\Database\Repositories\AuditLogRepository;
use AIOS\Mutation\ChangeOperationInterface;
use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\MutationEngine;
use AIOS\Mutation\MutationException;
use AIOS\Mutation\MutationResult;
use AIOS\Mutation\Operations\FileCreateOperation;
use AIOS\Mutation\Operations\FileDeleteOperation;
use AIOS\Mutation\Operations\FilePatchOperation;
use AIOS\Mutation\Operations\MetadataUpdateOperation;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Mutation\Operations\PostContentUpdateOperation;
use AIOS\Mutation\RollbackRecord;
use AIOS\Mutation\Snapshot;
use AIOS\Mutation\VerificationResult;
use AIOS\Security\PathGuard;
use AIOS\Tests\TestCase;

final class MutationEngineTest extends TestCase {

	private MutationEngine $engine;

	private ApprovalRepository $approvals;

	private AuditLogRepository $auditRepo;

	private PathGuard $pathGuard;

	private string $tempRoot;

	protected function setUp(): void {
		$this->resetPlugin();
		$container       = Plugin::instance()->container();
		$this->engine    = $container->get( MutationEngine::class );
		$this->approvals = $container->get( ApprovalRepository::class );
		$this->auditRepo = $container->get( AuditLogRepository::class );

		$this->tempRoot = rtrim( sys_get_temp_dir(), '/\\' ) . '/aios-mutation-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tempRoot, 0777, true );
		$this->pathGuard = new PathGuard( $this->tempRoot );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->tempRoot );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	/**
	 * A real, minimal operation whose lifecycle calls are recorded
	 * into a shared, referenced call-order log — used to prove pipeline
	 * ORDERING (policy before snapshot, snapshot before apply, etc.)
	 * without depending on any one concrete operation's own semantics.
	 *
	 * @param array<int, string> $log
	 */
	private function spyOperation( array &$log, int $risk, string $label, ?\Closure $onApply = null ): ChangeOperationInterface {
		return new class( $log, $risk, $label, $onApply ) implements ChangeOperationInterface {
			private string $id;
			public function __construct( private array &$log, private int $risk, private string $label, private ?\Closure $onApply ) {
				$this->id = 'op_' . bin2hex( random_bytes( 6 ) );
			}
			public function id(): string { return $this->id; }
			public function type(): string { return 'spy.' . $this->label; }
			public function target(): string { return $this->label; }
			public function riskLevel(): int { return $this->risk; }
			public function describe(): array { return array( 'label' => $this->label ); }
			public function captureSnapshot(): Snapshot {
				$this->log[] = 'snapshot:' . $this->label;
				return new Snapshot( $this->id, $this->type(), array() );
			}
			public function apply(): void {
				$this->log[] = 'apply:' . $this->label;
				if ( null !== $this->onApply ) {
					( $this->onApply )();
				}
			}
			public function verify(): VerificationResult {
				$this->log[] = 'verify:' . $this->label;
				return VerificationResult::success( $this->id );
			}
			public function rollback( Snapshot $snapshot ): RollbackRecord {
				$this->log[] = 'rollback:' . $this->label;
				return RollbackRecord::success( $this->id, $snapshot->id() );
			}
			public function payloadFingerprint(): string { return hash( 'sha256', 'spy:' . $this->label ); }
			public function currentPreconditionFingerprint(): string { return hash( 'sha256', 'spy-precondition:' . $this->label ); }
			public function intendedValue(): mixed { return null; }
			public function toSpec(): array { return array( 'label' => $this->label ); }
		};
	}

	// ================================================================
	// Pipeline ordering (Work Package 3)
	// ================================================================

	public function test_policy_runs_before_snapshot_and_blocks_unauthorized_principal(): void {
		$log         = array();
		$contributor = $this->contributorUser(); // capability ceiling 1 (edit_posts only)
		$op          = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_DESTRUCTIVE, 'x' );
		$change_set  = new ChangeSet( (int) $contributor->ID, array( $op ) );

		$result = $this->engine->submit( $change_set, $contributor );

		$this->assertEquals( MutationResult::STATUS_POLICY_DENIED, $result->status() );
		$this->assertEquals( array(), $log, 'no lifecycle method may run when Policy denies the ChangeSet' );
	}

	public function test_snapshot_happens_before_apply(): void {
		$log   = array();
		$admin = $this->adminUser();
		$op    = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE, 'a' );
		$cs    = new ChangeSet( (int) $admin->ID, array( $op ) );

		$this->engine->submit( $cs, $admin );

		$this->assertEquals( array( 'snapshot:a', 'apply:a', 'verify:a' ), $log );
	}

	public function test_approval_is_required_for_high_risk_and_blocks_apply(): void {
		$log   = array();
		$admin = $this->adminUser();
		// Safe mode (default): approvalThreshold = 2 (SENSITIVE). DESTRUCTIVE(3) must require approval.
		$op = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_DESTRUCTIVE, 'b' );
		$cs = new ChangeSet( (int) $admin->ID, array( $op ) );

		$result = $this->engine->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_APPROVAL_REQUIRED, $result->status() );
		$this->assertNotNull( $result->approvalId() );
		$this->assertEquals( array( 'snapshot:b' ), $log, 'apply() must never run before approval is granted' );

		$pending = $this->approvals->get( (int) $result->approvalId() );
		$this->assertNotNull( $pending );
		$this->assertEquals( 'mutation.apply', $pending['tool'] );
		$this->assertEquals( ApprovalRepository::STATUS_PENDING, $pending['status'] );
	}

	public function test_low_risk_auto_applies_without_approval(): void {
		$log   = array();
		$admin = $this->adminUser();
		$op    = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE, 'c' );
		$cs    = new ChangeSet( (int) $admin->ID, array( $op ) );

		$result = $this->engine->submit( $cs, $admin );

		$this->assertTrue( $result->ok() );
		$this->assertEquals( MutationResult::STATUS_APPLIED, $result->status() );
	}

	public function test_resume_approved_applies_after_a_human_decision(): void {
		$admin = $this->adminUser();
		$log   = array();
		$op    = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_DESTRUCTIVE, 'd' );
		$cs    = new ChangeSet( (int) $admin->ID, array( $op ) );

		$submitted = $this->engine->submit( $cs, $admin );
		$this->assertEquals( MutationResult::STATUS_APPROVAL_REQUIRED, $submitted->status() );

		$result = $this->engine->resumeApproved( (int) $submitted->approvalId(), $cs, 'approved', $admin );

		$this->assertTrue( $result->ok() );
		$this->assertEquals( array( 'snapshot:d', 'snapshot:d', 'apply:d', 'verify:d' ), $log, 'resumeApproved() re-snapshots fresh rather than reusing a possibly-stale one' );
	}

	public function test_verification_failure_triggers_rollback_of_everything_applied(): void {
		$admin = $this->adminUser();
		$log   = array();
		$good  = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE, 'good' );
		$bad   = new class( $log ) implements ChangeOperationInterface {
			private string $id;
			public function __construct( private array &$log ) { $this->id = 'op_bad'; }
			public function id(): string { return $this->id; }
			public function type(): string { return 'spy.bad'; }
			public function target(): string { return 'bad'; }
			public function riskLevel(): int { return \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE; }
			public function describe(): array { return array(); }
			public function captureSnapshot(): Snapshot { $this->log[] = 'snapshot:bad'; return new Snapshot( $this->id, $this->type(), array() ); }
			public function apply(): void { $this->log[] = 'apply:bad'; }
			public function verify(): VerificationResult { $this->log[] = 'verify:bad'; return VerificationResult::failure( $this->id, 'intentional failure' ); }
			public function rollback( Snapshot $snapshot ): RollbackRecord { $this->log[] = 'rollback:bad'; return RollbackRecord::success( $this->id, $snapshot->id() ); }
			public function payloadFingerprint(): string { return hash( 'sha256', 'bad' ); }
			public function currentPreconditionFingerprint(): string { return hash( 'sha256', 'bad-precondition' ); }
			public function intendedValue(): mixed { return null; }
			public function toSpec(): array { return array(); }
		};

		$cs     = new ChangeSet( (int) $admin->ID, array( $good, $bad ) );
		$result = $this->engine->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_VERIFICATION_FAILED, $result->status() );
		$this->assertEquals(
			array( 'snapshot:good', 'snapshot:bad', 'apply:good', 'apply:bad', 'verify:good', 'verify:bad', 'rollback:bad', 'rollback:good' ),
			$log,
			'every operation that was actually applied — including the one whose verify() failed — is rolled back, in reverse order'
		);
	}

	public function test_apply_failure_rolls_back_only_previously_applied_operations_in_reverse_order(): void {
		$admin = $this->adminUser();
		$log   = array();
		$first  = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE, 'first' );
		$second = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE, 'second' );
		$third  = new class( $log ) implements ChangeOperationInterface {
			private string $id;
			public function __construct( private array &$log ) { $this->id = 'op_third'; }
			public function id(): string { return $this->id; }
			public function type(): string { return 'spy.third'; }
			public function target(): string { return 'third'; }
			public function riskLevel(): int { return \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE; }
			public function describe(): array { return array(); }
			public function captureSnapshot(): Snapshot { $this->log[] = 'snapshot:third'; return new Snapshot( $this->id, $this->type(), array() ); }
			public function apply(): void { $this->log[] = 'apply:third'; throw new MutationException( 'test.fail', 'intentional apply failure' ); }
			public function verify(): VerificationResult { return VerificationResult::success( $this->id ); }
			public function rollback( Snapshot $snapshot ): RollbackRecord { $this->log[] = 'rollback:third'; return RollbackRecord::success( $this->id, $snapshot->id() ); }
			public function payloadFingerprint(): string { return hash( 'sha256', 'third' ); }
			public function currentPreconditionFingerprint(): string { return hash( 'sha256', 'third-precondition' ); }
			public function intendedValue(): mixed { return null; }
			public function toSpec(): array { return array(); }
		};

		$cs     = new ChangeSet( (int) $admin->ID, array( $first, $second, $third ) );
		$result = $this->engine->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_APPLY_FAILED, $result->status() );
		$this->assertEquals(
			array( 'snapshot:first', 'snapshot:second', 'snapshot:third', 'apply:first', 'apply:second', 'apply:third', 'rollback:second', 'rollback:first' ),
			$log,
			'third never applied, so it is never rolled back; second and first roll back in reverse order'
		);
	}

	public function test_rollback_failure_is_captured_not_silently_swallowed(): void {
		$admin = $this->adminUser();
		$log   = array();
		$failing_rollback = new class( $log ) implements ChangeOperationInterface {
			private string $id;
			public function __construct( private array &$log ) { $this->id = 'op_fr'; }
			public function id(): string { return $this->id; }
			public function type(): string { return 'spy.failing_rollback'; }
			public function target(): string { return 'x'; }
			public function riskLevel(): int { return \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE; }
			public function describe(): array { return array(); }
			public function captureSnapshot(): Snapshot { return new Snapshot( $this->id, $this->type(), array() ); }
			public function apply(): void {}
			public function verify(): VerificationResult { return VerificationResult::failure( $this->id, 'force rollback' ); }
			public function rollback( Snapshot $snapshot ): RollbackRecord { return RollbackRecord::failure( $this->id, $snapshot->id(), 'rollback deliberately fails' ); }
			public function payloadFingerprint(): string { return hash( 'sha256', 'failing_rollback' ); }
			public function currentPreconditionFingerprint(): string { return hash( 'sha256', 'failing_rollback-precondition' ); }
			public function intendedValue(): mixed { return null; }
			public function toSpec(): array { return array(); }
		};
		$cs     = new ChangeSet( (int) $admin->ID, array( $failing_rollback ) );
		$result = $this->engine->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_VERIFICATION_FAILED, $result->status() );
		$this->assertCount( 1, $result->rollbacks() );
		$this->assertFalse( $result->rollbacks()[0]->ok(), 'a failed rollback must be reported, never silently treated as success' );
	}

	// ================================================================
	// Approval cannot be bypassed / forged / replayed (Work Package 5)
	// ================================================================

	public function test_forged_approval_id_is_rejected(): void {
		$admin = $this->adminUser();
		$log   = array();
		$op    = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE, 'e' );
		$cs    = new ChangeSet( (int) $admin->ID, array( $op ) );

		$result = $this->engine->resumeApproved( 999999, $cs, 'approved', $admin );

		$this->assertEquals( MutationResult::STATUS_REJECTED, $result->status() );
		$this->assertEquals( array(), $log, 'a forged/nonexistent approval id must never reach apply()' );
	}

	public function test_duplicate_resume_is_rejected_the_second_time(): void {
		$admin = $this->adminUser();
		$log   = array();
		$op    = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_DESTRUCTIVE, 'f' );
		$cs    = new ChangeSet( (int) $admin->ID, array( $op ) );
		$submitted = $this->engine->submit( $cs, $admin );

		$first  = $this->engine->resumeApproved( (int) $submitted->approvalId(), $cs, 'approved', $admin );
		$second = $this->engine->resumeApproved( (int) $submitted->approvalId(), $cs, 'approved', $admin );

		$this->assertTrue( $first->ok() );
		$this->assertEquals( MutationResult::STATUS_REJECTED, $second->status(), 'replaying an already-decided approval must never apply a second time' );
		$this->assertEquals( 1, substr_count( implode( '|', $log ), 'apply:f' ), 'apply() must run exactly once despite two resume attempts' );
	}

	public function test_rejected_approval_never_applies(): void {
		$admin = $this->adminUser();
		$log   = array();
		$op    = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_DESTRUCTIVE, 'g' );
		$cs    = new ChangeSet( (int) $admin->ID, array( $op ) );
		$submitted = $this->engine->submit( $cs, $admin );

		$result = $this->engine->resumeApproved( (int) $submitted->approvalId(), $cs, 'rejected', $admin );

		$this->assertEquals( MutationResult::STATUS_REJECTED, $result->status() );
		$this->assertFalse( in_array( 'apply:g', $log, true ) );
	}

	public function test_deciding_users_own_capability_ceiling_still_bounds_approval(): void {
		// A contributor cannot approve at all today (ai_os_approve gate is
		// enforced at the REST layer) — but even bypassing that layer,
		// MutationEngine itself must never let an approval exceed the
		// DECIDING user's own real WordPress capability ceiling.
		$admin       = $this->adminUser();
		$contributor = $this->contributorUser(); // capability ceiling 1
		$log         = array();
		$op          = $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_DESTRUCTIVE, 'h' );
		$cs          = new ChangeSet( (int) $admin->ID, array( $op ) );
		$submitted   = $this->engine->submit( $cs, $admin );

		$result = $this->engine->resumeApproved( (int) $submitted->approvalId(), $cs, 'approved', $contributor );

		$this->assertEquals( MutationResult::STATUS_POLICY_DENIED, $result->status() );
		$this->assertFalse( in_array( 'apply:h', $log, true ) );
	}

	// ================================================================
	// Malformed / attack input (Work Package 5)
	// ================================================================

	public function test_path_traversal_is_rejected_before_snapshot_completes(): void {
		$op = new FileCreateOperation( '../../../etc/passwd', 'x', $this->pathGuard );
		try {
			$op->captureSnapshot();
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'file_create.path_denied', $e->errorCode() );
		}
	}

	public function test_path_outside_root_is_rejected(): void {
		$op = new FileCreateOperation( 'C:/Windows/System32/evil.php', 'x', $this->pathGuard );
		try {
			$op->captureSnapshot();
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'file_create.path_denied', $e->errorCode() );
		}
	}

	public function test_unknown_operation_type_is_impossible_by_construction(): void {
		// ChangeSet's constructor type-hints ChangeOperationInterface — an
		// "unknown operation type" cannot reach the pipeline at all; PHP's
		// own type system rejects it before ChangeSet's own runtime check
		// even runs. Confirmed here for documentation, not as a new check.
		try {
			/** @phpstan-ignore-next-line intentionally wrong type for the test */
			new ChangeSet( 1, array( new \stdClass() ) );
			$this->assertTrue( false, 'expected TypeError or InvalidArgumentException' );
		} catch ( \TypeError | \InvalidArgumentException $e ) {
			$this->assertTrue( true );
		}
	}

	public function test_malformed_option_target_is_refused_at_construction(): void {
		try {
			new OptionUpdateOperation( 'ai_os_settings', array( 'mode' => 'advanced' ) );
			$this->assertTrue( false, 'expected InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( true );
		}
	}

	public function test_policy_blocks_a_changeset_targeting_protected_option_even_if_constructed(): void {
		// Belt-and-suspenders: MutationEngine's own Policy check also
		// blocks the protected-option target, independent of the
		// operation's own constructor guard.
		$admin = $this->adminUser();
		$op    = new class extends \AIOS\Mutation\Operations\AbstractOperation {
			public function type(): string { return 'option.update'; }
			public function target(): string { return 'ai_os_settings'; }
			public function riskLevel(): int { return \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE; }
			public function describe(): array { return array(); }
			public function captureSnapshot(): Snapshot { return new Snapshot( $this->id(), $this->type(), array() ); }
			public function apply(): void {}
			public function verify(): VerificationResult { return VerificationResult::success( $this->id() ); }
			public function rollback( Snapshot $snapshot ): RollbackRecord { return RollbackRecord::success( $this->id(), $snapshot->id() ); }
			public function payloadFingerprint(): string { return hash( 'sha256', 'protected-option-test' ); }
			public function currentPreconditionFingerprint(): string { return hash( 'sha256', 'protected-option-test-precondition' ); }
			public function intendedValue(): mixed { return null; }
			public function toSpec(): array { return array(); }
		};
		$cs     = new ChangeSet( (int) $admin->ID, array( $op ) );
		$result = $this->engine->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_POLICY_DENIED, $result->status() );
	}

	// ================================================================
	// Audit (Work Package 3/5)
	// ================================================================

	public function test_every_outcome_is_audited_success_and_failure(): void {
		$admin = $this->adminUser();
		$log   = array();

		$this->engine->submit( new ChangeSet( (int) $admin->ID, array( $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE, 'audit-ok' ) ) ), $admin );
		$this->engine->submit( new ChangeSet( (int) $this->contributorUser()->ID, array( $this->spyOperation( $log, \AIOS\Security\PermissionEngine::LEVEL_DESTRUCTIVE, 'audit-denied' ) ) ), $this->contributorUser() );

		$rows = $this->auditRepo->chainRows( 100 );
		$tools = array_column( $rows, 'tool' );
		$this->assertTrue( in_array( 'mutation.apply', $tools, true ) );

		$statuses = array_column( $rows, 'status' );
		$this->assertTrue( in_array( 'ok', $statuses, true ) );
		$this->assertTrue( in_array( 'blocked', $statuses, true ) );
	}

	public function test_audit_rows_never_contain_operation_payload_content(): void {
		$admin  = $this->adminUser();
		$secret = 'SECRET-VALUE-MUST-NOT-LEAK-abc123';
		file_put_contents( $this->tempRoot . '/plain.txt', 'placeholder' );
		$op = new FileCreateOperation( 'secret.txt', $secret, $this->pathGuard );
		$cs = new ChangeSet( (int) $admin->ID, array( $op ) );

		// FileCreateOperation is SENSITIVE risk -> requires approval in
		// safe mode, so this only reaches Snapshot+Audit(approval_required),
		// never Apply -- exactly what we want to check content never leaks
		// even at the approval-request stage.
		$this->engine->submit( $cs, $admin );

		$rows = $this->auditRepo->chainRows( 50 );
		foreach ( $rows as $row ) {
			$haystack = wp_json_encode( $row ) ?: '';
			$this->assertFalse( str_contains( $haystack, $secret ), 'audit row must never contain raw operation content' );
		}
	}

	// ================================================================
	// Multisite isolation (Work Package 5)
	// ================================================================

	protected function multisiteTearDown(): void {
		$GLOBALS['__wp_shim']['multisite'] = false;
		$GLOBALS['__wp_shim']['sites']     = array( 1 );
	}

	public function test_option_mutation_rollback_is_isolated_per_site(): void {
		$GLOBALS['__wp_shim']['multisite']      = true;
		$GLOBALS['__wp_shim']['sites']          = array( 1, 2 );
		$GLOBALS['__wp_shim']['current_blog_id'] = 1;

		$admin = $this->adminUser();
		update_option( 'aios_test_option', 'site1-original' );

		$op = new OptionUpdateOperation( 'aios_test_option', 'site1-new' );
		$cs = new ChangeSet( (int) $admin->ID, array( $op ) );
		// SENSITIVE risk -> approval required in safe mode; resume immediately.
		$submitted = $this->engine->submit( $cs, $admin );
		$this->engine->resumeApproved( (int) $submitted->approvalId(), $cs, 'approved', $admin );
		$this->assertEquals( 'site1-new', get_option( 'aios_test_option' ) );

		switch_to_blog( 2 );
		$this->assertFalse( get_option( 'aios_test_option', false ), 'site 2 must not see site 1s mutation' );
		restore_current_blog();

		$this->assertEquals( 'site1-new', get_option( 'aios_test_option' ), 'site 1 state must be unaffected by visiting site 2' );

		$this->multisiteTearDown();
	}

	public function test_cross_site_resume_binds_the_changesets_own_site_not_the_current_request_site(): void {
		$GLOBALS['__wp_shim']['multisite']      = true;
		$GLOBALS['__wp_shim']['sites']          = array( 1, 2 );
		$GLOBALS['__wp_shim']['current_blog_id'] = 2;
		update_option( 'aios_cross_site_option', 'site2-value' ); // set while "on" site 2

		switch_to_blog( 1 );
		update_option( 'aios_cross_site_option', 'site1-value' );
		$admin = $this->adminUser();
		$op    = new OptionUpdateOperation( 'aios_cross_site_option', 'site1-new' );
		$cs    = new ChangeSet( (int) $admin->ID, array( $op ), array(), 1 ); // explicitly bound to site 1
		$submitted = $this->engine->submit( $cs, $admin );
		restore_current_blog(); // back to site 2, as if a later request came in on a different site

		$this->assertEquals( 2, (int) get_current_blog_id(), 'sanity: current request context is site 2' );

		$result = $this->engine->resumeApproved( (int) $submitted->approvalId(), $cs, 'approved', $admin );
		$this->assertTrue( $result->ok(), 'resumeApproved() must bind to the ChangeSets OWN site (1), not whatever site the current request happens to be on' );

		switch_to_blog( 1 );
		$this->assertEquals( 'site1-new', get_option( 'aios_cross_site_option' ), 'the mutation must have landed on site 1' );
		restore_current_blog();

		$this->assertEquals( 'site2-value', get_option( 'aios_cross_site_option' ), 'site 2s own value must be completely untouched' );

		$this->multisiteTearDown();
	}

	// ================================================================
	// Stale-state / TOCTOU (Sprint 0.3A Phase 2 hardening, Package K)
	// ================================================================

	public function test_stale_state_between_snapshot_and_apply_fails_closed(): void {
		update_option( 'aios_toctou_option', 'original' );
		$admin = $this->adminUser();
		$op    = new OptionUpdateOperation( 'aios_toctou_option', 'intended-new-value' );
		$cs    = new ChangeSet( (int) $admin->ID, array( $op ) );

		// OptionUpdate is SENSITIVE -> approval required in safe mode.
		$submitted = $this->engine->submit( $cs, $admin );
		$this->assertEquals( MutationResult::STATUS_APPROVAL_REQUIRED, $submitted->status() );

		// Something else changes the option AFTER approval was requested
		// but BEFORE the approval is acted on — exactly the TOCTOU window.
		update_option( 'aios_toctou_option', 'changed-by-someone-else' );

		$result = $this->engine->resumeApproved( (int) $submitted->approvalId(), $cs, 'approved', $admin );

		$this->assertEquals( MutationResult::STATUS_STALE_STATE, $result->status() );
		$this->assertEquals( 'changed-by-someone-else', get_option( 'aios_toctou_option' ), 'the drifted value must be left exactly as it was — never overwritten by a stale-approved mutation' );
	}

	public function test_unchanged_state_passes_the_precondition_check(): void {
		update_option( 'aios_toctou_stable_option', 'stable-value' );
		$admin = $this->adminUser();
		$op    = new OptionUpdateOperation( 'aios_toctou_stable_option', 'new-value' );
		$cs    = new ChangeSet( (int) $admin->ID, array( $op ) );

		$submitted = $this->engine->submit( $cs, $admin );
		$result    = $this->engine->resumeApproved( (int) $submitted->approvalId(), $cs, 'approved', $admin );

		$this->assertTrue( $result->ok() );
		$this->assertEquals( 'new-value', get_option( 'aios_toctou_stable_option' ) );
	}

	// ================================================================
	// Fingerprint binding (Sprint 0.3A Phase 2 hardening, Packages C/D)
	// ================================================================

	public function test_resume_rejects_a_changeset_with_a_different_payload_than_what_was_approved(): void {
		update_option( 'aios_fp_option', 'v1' );
		$admin = $this->adminUser();
		$op          = new OptionUpdateOperation( 'aios_fp_option', 'approved-value' );
		$cs_reviewed = new ChangeSet( (int) $admin->ID, array( $op ) );
		$submitted   = $this->engine->submit( $cs_reviewed, $admin );
		$this->assertEquals( MutationResult::STATUS_APPROVAL_REQUIRED, $submitted->status() );

		// Caller hands back a DIFFERENT ChangeSet (same id is impossible to
		// forge since ids are random per-instance, so this models the
		// realistic attack: a caller that mismanages state and resumes
		// with the wrong in-memory ChangeSet for a given approval id).
		$tampered_op = new OptionUpdateOperation( 'aios_fp_option', 'attacker-value' );
		$tampered_cs = new ChangeSet( (int) $admin->ID, array( $tampered_op ) );

		$result = $this->engine->resumeApproved( (int) $submitted->approvalId(), $tampered_cs, 'approved', $admin );

		$this->assertEquals( MutationResult::STATUS_FINGERPRINT_MISMATCH, $result->status() );
		$this->assertEquals( 'v1', get_option( 'aios_fp_option' ), 'no mutation may occur when the resumed ChangeSet does not match what was approved' );
	}

	public function test_resume_rejects_reordered_operations_even_with_identical_payloads(): void {
		$admin = $this->adminUser();
		$op_a  = new OptionUpdateOperation( 'aios_fp_a', 'a-value' );
		$op_b  = new OptionUpdateOperation( 'aios_fp_b', 'b-value' );

		$forward   = new ChangeSet( (int) $admin->ID, array( $op_a, $op_b ) );
		$submitted = $this->engine->submit( $forward, $admin );

		$op_a2 = new OptionUpdateOperation( 'aios_fp_a', 'a-value' );
		$op_b2 = new OptionUpdateOperation( 'aios_fp_b', 'b-value' );
		$reversed = new ChangeSet( (int) $admin->ID, array( $op_b2, $op_a2 ) );

		$result = $this->engine->resumeApproved( (int) $submitted->approvalId(), $reversed, 'approved', $admin );
		$this->assertEquals( MutationResult::STATUS_FINGERPRINT_MISMATCH, $result->status() );
	}

	// ================================================================
	// wp_delete_file() void-return contract — real WordPress core's
	// wp_delete_file() returns void (a filtered @unlink() wrapper), so
	// file.delete apply() and file.create rollback() must never branch
	// on its return value. Regression coverage for a defect where both
	// were written as if it returned bool, which would make a real,
	// successful deletion report as a failure on every invocation.
	// ================================================================

	public function test_file_delete_apply_succeeds_on_real_deletion_despite_wp_delete_file_returning_void(): void {
		$path = $this->tempRoot . '/to-delete.txt';
		file_put_contents( $path, 'delete me' );

		$op = new FileDeleteOperation( 'to-delete.txt', $this->pathGuard );
		$op->captureSnapshot();
		$op->apply(); // must not throw file_delete.failed

		$this->assertFalse( file_exists( $path ), 'the file must actually be gone' );
		$this->assertTrue( $op->verify()->ok() );
	}

	public function test_file_create_rollback_reports_success_on_real_deletion_despite_wp_delete_file_returning_void(): void {
		$path = $this->tempRoot . '/created.txt';

		$op       = new FileCreateOperation( 'created.txt', 'new content', $this->pathGuard );
		$snapshot = $op->captureSnapshot();
		$op->apply();
		$this->assertTrue( file_exists( $path ), 'sanity: the file must exist before rollback' );

		$record = $op->rollback( $snapshot );

		$this->assertTrue( $record->ok(), 'rollback must report success — wp_delete_file() returning void must never be mistaken for failure' );
		$this->assertFalse( file_exists( $path ), 'the created file must actually be gone after rollback' );
	}
}
