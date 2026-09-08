<?php
/**
 * Integration tests: AuditLogger fault injection (Sprint 0.3A Phase 2
 * exit-gate closure). Closes the gap RepositoryFaultInjectionTest.php's
 * own docblock explicitly flagged as NOT covered ("matrix item L —
 * audit failure around critical transitions"): a REAL, deterministically
 * forced failure of the audit log's own underlying persistence
 * (tests/Support/FaultInjectingDatabase.php, never the real Database),
 * proven never to:
 *
 *   - block, delay, or change the outcome of the mutation it is
 *     auditing (AuditLogger::log() is deliberately fire-and-forget —
 *     every ChangeSetState/MutationResult decision is made BEFORE the
 *     audit call, exactly like DurableMutationCoordinator's own
 *     journalEventHook() "never makes a security decision" contract);
 *   - fabricate a false "audit succeeded" signal (no row is ever
 *     silently half-written; log() returns 0, and nothing is ever
 *     found in the real table for that attempt);
 *   - prevent MANUAL_RECOVERY_REQUIRED escalation (DurableMutationCoordinator::
 *     recover() durably transitions state BEFORE it audits the
 *     escalation — see its own auditRecoveryEscalation() docblock);
 *   - leak secret-shaped argument material even when the write that
 *     would have persisted its (already-redacted) hash fails.
 *
 * Policy this class exists to prove (see also docs/ARCHITECTURE.md §13
 * and docs/audits/BUG-GAP-REGISTER.md's SEC-M4 scope note): audit
 * logging in this codebase is intentionally FAIL-OPEN with respect to
 * the mutation being audited (a broken audit log must never turn a
 * real, policy-approved mutation into a denial-of-service) and
 * FAIL-CLOSED with respect to never fabricating a record that did not
 * really happen (a failed write is honestly absent, never a corrupted
 * or partial row, and never silently treated as "logged").
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Audit\AuditIntegrity;
use AIOS\Audit\AuditLogger;
use AIOS\Core\Plugin;
use AIOS\Database\Repositories\AuditLogRepository;
use AIOS\Mutation\ChangeOperationInterface;
use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\ChangeSetRepository;
use AIOS\Mutation\ChangeSetState;
use AIOS\Mutation\DurableMutationCoordinator;
use AIOS\Mutation\MutationEngine;
use AIOS\Mutation\MutationException;
use AIOS\Mutation\MutationResult;
use AIOS\Mutation\Operations\MetadataUpdateOperation;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Mutation\OperationJournalRepository;
use AIOS\Mutation\OperationJournalState;
use AIOS\Mutation\RollbackRecord;
use AIOS\Mutation\Snapshot;
use AIOS\Mutation\VerificationResult;
use AIOS\Security\PermissionEngine;
use AIOS\Settings\Settings;
use AIOS\Tests\Support\FaultInjectingDatabase;
use AIOS\Tests\TestCase;

final class AuditFaultInjectionTest extends TestCase {

	protected function setUp(): void {
		$this->resetPlugin();
	}

	/**
	 * An AuditLogger whose OWN underlying table write always fails —
	 * built on the exact same FaultInjectingDatabase used for the rest
	 * of the repository fault-injection matrix, never a hand-rolled
	 * double, so the failure mode matches a real wpdb::insert()
	 * returning false exactly.
	 */
	private function faultyAuditLogger(): AuditLogger {
		$db = new FaultInjectingDatabase();
		$db->failAlways( 'insert' );
		$container = Plugin::instance()->container();
		$repo      = new AuditLogRepository( $db, $container->get( AuditIntegrity::class ) );
		return new AuditLogger( $repo, $container->get( Settings::class ), $container->get( AuditIntegrity::class ) );
	}

	/**
	 * A real 'option.update' operation targeting a protected AI OS
	 * security option, WITHOUT going through OptionUpdateOperation's own
	 * constructor guard (which refuses to even construct such a thing) —
	 * this models an operation that reaches MutationEngine::checkPolicy()'s
	 * own independent protected-target check, exactly like
	 * MutationEngineTest::test_option_update_targeting_a_protected_option_is_policy_denied()
	 * does.
	 */
	private function protectedOptionOperation(): ChangeOperationInterface {
		return new class() implements ChangeOperationInterface {
			private string $opId;
			public function __construct() { $this->opId = 'op_protected_' . bin2hex( random_bytes( 4 ) ); }
			public function id(): string { return $this->opId; }
			public function type(): string { return 'option.update'; }
			public function target(): string { return 'ai_os_settings'; }
			public function riskLevel(): int { return PermissionEngine::LEVEL_SENSITIVE; }
			public function describe(): array { return array(); }
			public function captureSnapshot(): Snapshot { return new Snapshot( $this->opId, $this->type(), array() ); }
			public function apply(): void { throw new MutationException( 'spy.unreachable', 'must never be called — policy must deny this before apply()' ); }
			public function verify(): VerificationResult { return VerificationResult::success( $this->opId ); }
			public function rollback( Snapshot $snapshot ): RollbackRecord { return RollbackRecord::success( $this->opId, $snapshot->id() ); }
			public function payloadFingerprint(): string { return hash( 'sha256', 'protected-option-test' ); }
			public function currentPreconditionFingerprint(): string { return hash( 'sha256', 'protected-option-test-precondition' ); }
			public function intendedValue(): mixed { return null; }
			public function toSpec(): array { return array(); }
		};
	}

	private function throwingOperation( string $id ): ChangeOperationInterface {
		return new class( $id ) implements ChangeOperationInterface {
			public function __construct( private string $opId ) {}
			public function id(): string { return $this->opId; }
			public function type(): string { return 'spy.throws_on_apply'; }
			public function target(): string { return $this->opId; }
			public function riskLevel(): int { return PermissionEngine::LEVEL_SAFE_WRITE; }
			public function describe(): array { return array(); }
			public function captureSnapshot(): Snapshot { return new Snapshot( $this->opId, $this->type(), array() ); }
			public function apply(): void { throw new MutationException( 'spy.forced_failure', 'deliberate apply() failure for audit fault-injection test' ); }
			public function verify(): VerificationResult { return VerificationResult::success( $this->opId ); }
			public function rollback( Snapshot $snapshot ): RollbackRecord { return RollbackRecord::success( $this->opId, $snapshot->id() ); }
			public function payloadFingerprint(): string { return hash( 'sha256', 'throws:' . $this->opId ); }
			public function currentPreconditionFingerprint(): string { return hash( 'sha256', 'throws-precondition:' . $this->opId ); }
			public function intendedValue(): mixed { return null; }
			public function toSpec(): array { return array(); }
		};
	}

	// ---------------------------------------------------------------- A: audit failure before mutation begins (policy denial)

	public function test_A_audit_failure_on_policy_denial_still_reports_policy_denied_and_touches_nothing(): void {
		$container = Plugin::instance()->container();
		$engine    = new MutationEngine( $container->get( PermissionEngine::class ), $container->get( \AIOS\Database\Repositories\ApprovalRepository::class ), $this->faultyAuditLogger() );

		$admin = $this->adminUser();
		// Targets a protected AI OS security option — checkPolicy() denies
		// this before ANY snapshot/apply ever runs, i.e. genuinely "before
		// the mutation begins."
		$cs = new ChangeSet( (int) $admin->ID, array( $this->protectedOptionOperation() ) );

		$result = $engine->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_POLICY_DENIED, $result->status() );
		$this->assertNotNull( $result->error() );
	}

	public function test_A_audit_failure_never_produces_a_false_success_row_for_the_denied_attempt(): void {
		$container      = Plugin::instance()->container();
		$faultyAudit    = $this->faultyAuditLogger();
		$engine         = new MutationEngine( $container->get( PermissionEngine::class ), $container->get( \AIOS\Database\Repositories\ApprovalRepository::class ), $faultyAudit );

		$admin = $this->adminUser();
		$cs    = new ChangeSet( (int) $admin->ID, array( $this->protectedOptionOperation() ) );

		$logId = null;
		// Call the faulty logger directly the same way MutationEngine does,
		// to assert on log()'s own return value (0 = failed, never throws).
		$logId = $faultyAudit->log(
			array(
				'user'   => $admin,
				'tool'   => 'mutation.apply',
				'action' => sprintf( 'changeset %s: %s', $cs->id(), MutationResult::STATUS_POLICY_DENIED ),
				'status' => AuditLogger::STATUS_BLOCKED,
			)
		);

		$this->assertSame( 0, $logId, 'a failed audit write must return the documented 0 sentinel, never a fabricated id' );

		$real = $container->get( AuditLogRepository::class );
		$rows = $real->query( array( 'tool' => 'mutation.apply' ) );
		foreach ( $rows as $row ) {
			$this->assertStringNotContainsString( $cs->id(), (string) $row['action'], 'the faulty logger must never have actually reached the real table' );
		}
	}

	// ---------------------------------------------------------------- B: audit failure after apply, before terminal reflection

	public function test_B_audit_failure_after_a_real_apply_never_masks_or_fabricates_the_durable_outcome(): void {
		$container   = Plugin::instance()->container();
		$repo        = $container->get( ChangeSetRepository::class );
		$journal     = $container->get( OperationJournalRepository::class );
		$engine      = new MutationEngine( $container->get( PermissionEngine::class ), $container->get( \AIOS\Database\Repositories\ApprovalRepository::class ), $this->faultyAuditLogger() );
		$coordinator = new DurableMutationCoordinator( $repo, $engine, $journal, $this->faultyAuditLogger() );

		$admin   = $this->adminUser();
		$post_id = (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'audit fault b' ) );
		$cs      = new ChangeSet( (int) $admin->ID, array( new MetadataUpdateOperation( $post_id, 'aios_audit_fault_b', 'real-value' ) ) );

		$result = $coordinator->submit( $cs, $admin );

		// The real mutation happened for real — MutationEngine's in-memory
		// pipeline has no dependency on the audit write succeeding.
		$this->assertTrue( $result->ok(), 'a real, policy-approved mutation must never be blocked by an unrelated audit-log failure' );
		$this->assertEquals( 'real-value', get_post_meta( $post_id, 'aios_audit_fault_b', true ) );

		// The durable ChangeSet row reflects the SAME real outcome — its
		// own state machine is driven by ChangeSetRepository's CAS
		// transitions, never by whether the audit write succeeded.
		$row = $repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::COMPLETED, $row['state'], 'durable completion must not be gated on (or corrupted by) an unrelated audit failure' );

		// And, honestly, no row was fabricated for the failed audit
		// attempt either — no false "it was logged" signal.
		$real = $container->get( AuditLogRepository::class );
		$rows = $real->query( array( 'tool' => 'mutation.apply' ) );
		foreach ( $rows as $logged ) {
			$this->assertStringNotContainsString( $cs->id(), (string) $logged['action'] );
		}
	}

	// ---------------------------------------------------------------- C: audit failure during a real rollback

	public function test_C_audit_failure_during_rollback_never_corrupts_the_real_rollback_or_its_report(): void {
		$container = Plugin::instance()->container();
		$repo      = $container->get( ChangeSetRepository::class );
		$journal   = $container->get( OperationJournalRepository::class );
		$engine    = new MutationEngine( $container->get( PermissionEngine::class ), $container->get( \AIOS\Database\Repositories\ApprovalRepository::class ), $this->faultyAuditLogger() );
		$coordinator = new DurableMutationCoordinator( $repo, $engine, $journal, $this->faultyAuditLogger() );

		$admin   = $this->adminUser();
		$post_id = (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'audit fault c' ) );
		$good    = new MetadataUpdateOperation( $post_id, 'aios_audit_fault_c', 'should-be-rolled-back' );
		$bad     = $this->throwingOperation( 'op_audit_fault_c_bad' );

		$cs = new ChangeSet( (int) $admin->ID, array( $good, $bad ) );

		$result = $coordinator->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_APPLY_FAILED, $result->status() );
		$this->assertCount( 1, $result->rollbacks(), 'only the operation that actually applied ("good") is rolled back' );
		$this->assertTrue( $result->rollbacks()[0]->ok(), 'the real rollback itself must succeed independent of the audit write failing' );
		$this->assertSame( '', (string) get_post_meta( $post_id, 'aios_audit_fault_c', true ), 'rollback must genuinely have restored the pre-apply (absent) state' );

		$row = $repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::ROLLED_BACK, $row['state'], 'durable reflection of a real rollback must not be affected by an unrelated audit failure' );

		$jrow = $journal->load( $cs->id(), 0 );
		$this->assertEquals( OperationJournalState::ROLLED_BACK, $jrow['state'] );
	}

	// ---------------------------------------------------------------- D: audit failure while escalating to MANUAL_RECOVERY_REQUIRED

	public function test_D_audit_failure_during_manual_recovery_escalation_still_leaves_state_conservative(): void {
		$container       = Plugin::instance()->container();
		$repo            = $container->get( ChangeSetRepository::class );
		$journal         = $container->get( OperationJournalRepository::class );
		$workingCoordinator = $container->get( DurableMutationCoordinator::class );

		$admin = $this->adminUser();
		// A sensitive-risk ChangeSet stops at PENDING_APPROVAL with its
		// journal row SNAPSHOTTED — then force it into APPLYING to model
		// the durable fact a real crash mid-apply would leave behind,
		// exactly like CrashRecoveryTest does.
		$cs = new ChangeSet( (int) $admin->ID, array( new OptionUpdateOperation( 'aios_audit_fault_d', 'v' ) ) );
		$workingCoordinator->submit( $cs, $admin );

		$before = $journal->load( $cs->id(), 0 );
		$journal->transition( $cs->id(), 0, OperationJournalState::SNAPSHOTTED, OperationJournalState::APPLYING, (int) $before['state_version'] );

		// Recover with a coordinator whose audit logger is the ONLY thing
		// that fails — the escalation's own state transition must not
		// depend on it.
		$engine              = $container->get( MutationEngine::class );
		$recoveryCoordinator = new DurableMutationCoordinator( $repo, $engine, $journal, $this->faultyAuditLogger() );

		$result = $recoveryCoordinator->recover( $cs->id() );

		$this->assertEquals( MutationResult::STATUS_MANUAL_RECOVERY_REQUIRED, $result->status(), 'escalation must succeed (fail closed to manual recovery) even though its own audit trail write failed' );
		$this->assertNotNull( $result->error() );

		$row = $repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::MANUAL_RECOVERY_REQUIRED, $row['state'], 'the durable transition to MANUAL_RECOVERY_REQUIRED must happen before, and independent of, the audit write' );
	}

	// ---------------------------------------------------------------- E: no secret leakage on audit failure

	public function test_E_audit_failure_never_leaks_secret_argument_material_and_never_throws(): void {
		$faultyAudit = $this->faultyAuditLogger();

		$secret = 'super-secret-value-must-never-be-stored-anywhere';
		$logId  = $faultyAudit->log(
			array(
				'client'         => 'audit-fault-test',
				'principal_type' => 'system',
				'tool'           => 'mutation.apply',
				'action'         => 'audit fault e',
				'args'           => array( 'password' => $secret, 'note' => 'contains secret-shaped content' ),
				'status'         => AuditLogger::STATUS_ERROR,
			)
		);

		$this->assertSame( 0, $logId, 'log() must never throw, and must return the documented failure sentinel' );

		$container = Plugin::instance()->container();
		$real      = $container->get( AuditLogRepository::class );
		$rows      = $real->query( array( 'tool' => 'mutation.apply' ), 200 );
		foreach ( $rows as $row ) {
			$this->assertStringNotContainsString( $secret, (string) ( $row['args_json'] ?? '' ), 'nothing from a failed write can leak the raw secret — it was never persisted at all' );
			$this->assertStringNotContainsString( $secret, (string) ( $row['action'] ?? '' ) );
			$this->assertStringNotContainsString( $secret, (string) ( $row['error'] ?? '' ) );
		}
	}
}
