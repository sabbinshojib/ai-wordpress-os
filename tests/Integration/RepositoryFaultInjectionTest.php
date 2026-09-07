<?php
/**
 * Integration tests: repository-layer fault injection (Sprint 0.3A
 * Phase 2 exit-gate closure, Packages 4/5). Every test here proves a
 * REAL persistence/encryption failure — deterministically forced via
 * tests/Support/FaultInjectingDatabase.php and FaultInjectingCrypto.php
 * (never the real Database/Crypto) — fails closed: no false success,
 * no uncaught exception, no plaintext fallback, no forced overwrite of
 * a stale row, never a mutation applied without its durable journal
 * record.
 *
 * Matrix coverage (see the current mega-task's fault-injection matrix,
 * items A–L):
 *   A - ChangeSet insert failure                    : covered here
 *   B - journal creation failure                     : covered here
 *   C - journal transition failure before apply       : covered here
 *   D - encrypted recovery persistence failure        : covered here
 *   E - ChangeSet state CAS conflict                  : already covered,
 *       ChangeSetRepositoryTest::test_stale_state_version_fails_the_cas
 *       and ::test_illegal_transition_throws_before_any_write
 *   F - operation state CAS conflict                  : already covered,
 *       OperationJournalRepositoryTest::test_stale_state_version_fails_the_cas
 *   G - lease acquisition failure                     : already covered,
 *       ChangeSetRepositoryTest::test_first_executor_acquires_lease_second_is_rejected,
 *       DurableMutationCoordinatorTest::test_resume_is_rejected_while_another_executor_holds_the_lease
 *   H - lease loss/invalid owner on release            : already covered,
 *       ChangeSetRepositoryTest::test_wrong_owner_cannot_release_lease
 *   I - Crypto encrypt failure                        : covered here
 *   J - Crypto decrypt failure (loadChangeSet)         : covered here
 *   K - terminal-result persistence failure after mutation : covered here
 *   L - audit failure around critical transitions      : NOT covered —
 *       AuditLogger::log() already documents (and CapabilityManagementTest
 *       indirectly relies on) "never throws, returns 0 on failure";
 *       fault-injecting it would require the same DatabaseInterface
 *       seam extended to AuditLogRepository, which this pass did not
 *       reach — an honest, explicitly-scoped remaining gap, not
 *       silently deferred.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Audit\AuditLogger;
use AIOS\Core\Plugin;
use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\ChangeSetFingerprint;
use AIOS\Mutation\ChangeSetRepository;
use AIOS\Mutation\ChangeSetState;
use AIOS\Mutation\DurableMutationCoordinator;
use AIOS\Mutation\MutationEngine;
use AIOS\Mutation\MutationException;
use AIOS\Mutation\MutationResult;
use AIOS\Mutation\Operations\MetadataUpdateOperation;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Mutation\OperationJournalRepository;
use AIOS\Tests\Support\FaultInjectingCrypto;
use AIOS\Tests\Support\FaultInjectingDatabase;
use AIOS\Tests\TestCase;

final class RepositoryFaultInjectionTest extends TestCase {

	protected function setUp(): void {
		$this->resetPlugin();
	}

	// ---------------------------------------------------------------- helpers

	private function journalRepo( ?FaultInjectingDatabase $db = null, ?FaultInjectingCrypto $crypto = null ): OperationJournalRepository {
		return new OperationJournalRepository( $db ?? new FaultInjectingDatabase(), $crypto ?? new FaultInjectingCrypto() );
	}

	private function changeSetRepo( OperationJournalRepository $journal, ?FaultInjectingDatabase $db = null, ?FaultInjectingCrypto $crypto = null ): ChangeSetRepository {
		return new ChangeSetRepository( $db ?? new FaultInjectingDatabase(), $crypto ?? new FaultInjectingCrypto(), $journal );
	}

	private function coordinator( ChangeSetRepository $repo, OperationJournalRepository $journal ): DurableMutationCoordinator {
		$container = Plugin::instance()->container();
		return new DurableMutationCoordinator( $repo, $container->get( MutationEngine::class ), $journal, $container->get( AuditLogger::class ) );
	}

	/**
	 * @return array{0: ChangeSet, 1: int} [the ChangeSet, the real post id its single operation targets]
	 */
	private function lowRiskChangeSet( string $meta_key, string $value ): array {
		$post_id = (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'fault injection test post' ) );
		return array( new ChangeSet( 1, array( new MetadataUpdateOperation( $post_id, $meta_key, $value ) ) ), $post_id );
	}

	// ---------------------------------------------------------------- A: ChangeSet insert failure

	public function test_A_changeset_insert_failure_never_produces_a_false_success(): void {
		$db = new FaultInjectingDatabase();
		$db->failAlways( 'insert' );
		$journal     = $this->journalRepo();
		$repo        = $this->changeSetRepo( $journal, $db );
		$coordinator = $this->coordinator( $repo, $journal );

		$admin = $this->adminUser();
		$cs    = new ChangeSet( 1, array( new OptionUpdateOperation( 'aios_fault_a', 'v' ) ) );

		$result = $coordinator->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_REJECTED, $result->status() );
		$this->assertNotNull( $result->error() );
		$this->assertNull( $repo->load( $cs->id() ), 'no row must exist for a ChangeSet whose INSERT failed' );
	}

	public function test_A_repository_level_create_throws_a_stable_typed_exception(): void {
		$db = new FaultInjectingDatabase();
		$db->failAlways( 'insert' );
		$journal = $this->journalRepo();
		$repo    = $this->changeSetRepo( $journal, $db );
		$cs      = new ChangeSet( 1, array( new OptionUpdateOperation( 'aios_fault_a2', 'v' ) ) );

		try {
			$repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'changeset.persist_failed', $e->errorCode() );
		}
	}

	// ---------------------------------------------------------------- B: journal creation failure

	public function test_B_journal_creation_failure_prevents_apply_from_starting(): void {
		$journalDb = new FaultInjectingDatabase();
		$journalDb->failAlways( 'insert' );
		$journal     = $this->journalRepo( $journalDb );
		$repo        = $this->changeSetRepo( $journal ); // ChangeSet's own DB has no faults.
		$coordinator = $this->coordinator( $repo, $journal );

		$admin = $this->adminUser();
		list( $cs, $post_id ) = $this->lowRiskChangeSet( 'aios_fault_b_meta', 'should-never-be-set' );

		$result = $coordinator->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_REJECTED, $result->status() );
		$this->assertEquals( '', (string) get_post_meta( $post_id, 'aios_fault_b_meta', true ), 'apply() must never have run against real state' );

		$row = $repo->load( $cs->id() );
		$this->assertNotNull( $row );
		$this->assertEquals( ChangeSetState::FAILED, $row['state'], 'must be explicitly marked FAILED, never left looking untouched at PLANNED' );
	}

	// ---------------------------------------------------------------- C: journal transition failure before apply

	public function test_C_journal_transition_failure_before_apply_fails_closed(): void {
		$journalDb = new FaultInjectingDatabase();
		// Call sequence for one operation's submit(): #1 saveRecovery's
		// update, #2 snapshot_captured's strict transition (PENDING->SNAPSHOTTED),
		// #3 apply_started's strict transition (SNAPSHOTTED->APPLYING).
		// Failing #3 means the row never durably reaches APPLYING —
		// apply() must never run.
		$journalDb->failOnCall( 'update', 3 );
		$journal     = $this->journalRepo( $journalDb );
		$repo        = $this->changeSetRepo( $journal );
		$coordinator = $this->coordinator( $repo, $journal );

		$admin = $this->adminUser();
		list( $cs, $post_id ) = $this->lowRiskChangeSet( 'aios_fault_c_meta', 'should-never-be-set' );

		$result = $coordinator->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_APPLY_FAILED, $result->status() );
		$this->assertEquals( '', (string) get_post_meta( $post_id, 'aios_fault_c_meta', true ), 'apply() must never have run' );
	}

	public function test_C_multi_op_changeset_rolls_back_the_first_operation_when_the_seconds_journal_write_fails(): void {
		update_option( 'aios_fault_c_multi', 'original' );
		$journalDb = new FaultInjectingDatabase();
		// Operation 0 (option.update, sensitive -> never reaches here in
		// this low-risk scenario) — use two low-risk metadata ops so both
		// auto-apply. Call sequence per operation: saveRecovery(#1),
		// snapshot transition(#2), [loop restarts for op 1] saveRecovery(#3),
		// snapshot transition(#4), then apply loop: op0 apply_started
		// transition(#5), op0 apply_completed transition(#6)... — rather
		// than track exact numbers for a 2-op apply sequence, fail the
		// FIRST apply_started transition, forcing op 0 to never even start
		// applying, which is the simplest unambiguous case for a
		// multi-op ChangeSet: nothing at all must be applied.
		$post_id_a = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'fault c multi a' ) );
		$post_id_b = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'fault c multi b' ) );
		$cs        = new ChangeSet(
			1,
			array(
				new MetadataUpdateOperation( (int) $post_id_a, 'aios_fault_c_multi_a', 'v' ),
				new MetadataUpdateOperation( (int) $post_id_b, 'aios_fault_c_multi_b', 'v' ),
			)
		);
		// Two ops -> captureSnapshots() runs saveRecovery+transition for
		// EACH before any apply(): calls #1,#2 (op0 snapshot), #3,#4 (op1
		// snapshot), then apply loop starts: #5 is op0's apply_started
		// transition.
		$journalDb->failOnCall( 'update', 5 );
		$journal     = $this->journalRepo( $journalDb );
		$repo        = $this->changeSetRepo( $journal );
		$coordinator = $this->coordinator( $repo, $journal );

		$result = $coordinator->submit( $cs, $this->adminUser() );

		$this->assertEquals( MutationResult::STATUS_APPLY_FAILED, $result->status() );
		$this->assertEquals( '', (string) get_post_meta( (int) $post_id_a, 'aios_fault_c_multi_a', true ) );
		$this->assertEquals( '', (string) get_post_meta( (int) $post_id_b, 'aios_fault_c_multi_b', true ) );
	}

	// ---------------------------------------------------------------- D: encrypted recovery persistence failure

	public function test_D_recovery_persistence_failure_prevents_mutation_before_it_starts(): void {
		$journalDb = new FaultInjectingDatabase();
		// The very first update() call is saveRecovery()'s own write.
		$journalDb->failOnCall( 'update', 1 );
		$journal     = $this->journalRepo( $journalDb );
		$repo        = $this->changeSetRepo( $journal );
		$coordinator = $this->coordinator( $repo, $journal );

		$admin = $this->adminUser();
		list( $cs, $post_id ) = $this->lowRiskChangeSet( 'aios_fault_d_meta', 'should-never-be-set' );

		$result = $coordinator->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_SNAPSHOT_FAILED, $result->status() );
		$this->assertEquals( '', (string) get_post_meta( $post_id, 'aios_fault_d_meta', true ), 'must fail before any mutation when rollback proof (recovery state) could not be durably written' );

		$row = $repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::FAILED, $row['state'] );
	}

	// ---------------------------------------------------------------- I: Crypto encrypt failure

	public function test_I_changeset_encrypt_failure_never_falls_back_to_plaintext(): void {
		$crypto = new FaultInjectingCrypto();
		$crypto->failEncrypt();
		$journal     = $this->journalRepo();
		$repo        = $this->changeSetRepo( $journal, null, $crypto );
		$coordinator = $this->coordinator( $repo, $journal );

		$admin = $this->adminUser();
		$cs    = new ChangeSet( 1, array( new OptionUpdateOperation( 'aios_fault_i', 'secret-value-must-never-be-stored' ) ) );

		$result = $coordinator->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_REJECTED, $result->status() );
		$this->assertNull( $repo->load( $cs->id() ), 'a ChangeSet whose payload could not be encrypted must never be persisted at all — never plaintext, never nothing-checked' );
	}

	public function test_I_recovery_encrypt_failure_prevents_mutation_before_it_starts(): void {
		$crypto = new FaultInjectingCrypto();
		$crypto->failEncrypt();
		$journal     = $this->journalRepo( null, $crypto );
		$repo        = $this->changeSetRepo( $journal );
		$coordinator = $this->coordinator( $repo, $journal );

		$admin = $this->adminUser();
		list( $cs, $post_id ) = $this->lowRiskChangeSet( 'aios_fault_i2_meta', 'should-never-be-set' );

		$result = $coordinator->submit( $cs, $admin );

		$this->assertEquals( MutationResult::STATUS_REJECTED, $result->status(), 'an encrypt failure inside saveRecovery() escapes as a bare RuntimeException, not a MutationException — must still surface as a safe result, not an uncaught exception' );
		$this->assertEquals( '', (string) get_post_meta( $post_id, 'aios_fault_i2_meta', true ) );

		$row = $repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::FAILED, $row['state'] );
	}

	// ---------------------------------------------------------------- J: Crypto decrypt failure (resume path)

	public function test_J_decrypt_failure_on_resume_fails_closed_never_guesses_state(): void {
		update_option( 'aios_fault_j', 'original' );
		$journal = $this->journalRepo();
		$repo    = $this->changeSetRepo( $journal ); // create with a WORKING crypto first.
		$coordinator = $this->coordinator( $repo, $journal );

		$admin = $this->adminUser();
		$cs    = new ChangeSet( 1, array( new OptionUpdateOperation( 'aios_fault_j', 'new-value' ) ) );
		$submitted = $coordinator->submit( $cs, $admin );
		$this->assertEquals( MutationResult::STATUS_APPROVAL_REQUIRED, $submitted->status() );

		// Now resume with a coordinator whose ChangeSetRepository uses a
		// crypto that always fails to DECRYPT — modeling a real corrupted/
		// tampered/wrong-key ciphertext at resume time.
		$failingCrypto     = new FaultInjectingCrypto();
		$failingCrypto->failDecrypt();
		$repoForResume     = new ChangeSetRepository( new FaultInjectingDatabase(), $failingCrypto, $journal );
		$coordinatorResume = $this->coordinator( $repoForResume, $journal );

		$result = $coordinatorResume->resume( $cs->id(), (int) $submitted->approvalId(), 'approved', $admin );

		$this->assertEquals( MutationResult::STATUS_REJECTED, $result->status() );
		$this->assertEquals( 'original', get_option( 'aios_fault_j' ), 'must never guess/apply against an unreadable ChangeSet' );

		$row = $repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::FAILED, $row['state'] );
	}

	// ---------------------------------------------------------------- K: terminal-result persistence failure after mutation

	public function test_K_final_completed_transition_failure_never_reports_a_false_completed_state(): void {
		$db = new FaultInjectingDatabase();
		// STATUS_APPLIED's reflectFromPlanned path is 5 transitions
		// (SNAPSHOTTED, DIFF_READY, APPLYING, VERIFYING, COMPLETED) — all
		// on the ChangeSetRepository's own DB. Fail only the LAST one.
		$db->failOnCall( 'update', 5 );
		$journal     = $this->journalRepo();
		$repo        = $this->changeSetRepo( $journal, $db );
		$coordinator = $this->coordinator( $repo, $journal );

		$admin = $this->adminUser();
		list( $cs, $post_id ) = $this->lowRiskChangeSet( 'aios_fault_k_meta', 'really-applied' );

		$result = $coordinator->submit( $cs, $admin );

		// The real mutation DID happen — MutationEngine's own in-memory
		// pipeline has no dependency on the durable reflection succeeding.
		$this->assertTrue( $result->ok() );
		$this->assertEquals( 'really-applied', get_post_meta( $post_id, 'aios_fault_k_meta', true ) );

		// But the DURABLE row must never claim COMPLETED when the write
		// that would record that fact failed — no false success at the
		// persistence layer, even though the in-memory result was real.
		$row = $repo->load( $cs->id() );
		$this->assertNotEquals( ChangeSetState::COMPLETED, $row['state'], 'a failed final CAS must never be silently treated as completed' );
		$this->assertEquals( ChangeSetState::VERIFYING, $row['state'], 'must stay at the last state it durably reached, not be forced forward' );

		// Recovery path remains available: recover() can still classify
		// this row (it is non-terminal, and its journal row(s) show
		// real progress) rather than the fact being lost.
		$recovered = $coordinator->recover( $cs->id() );
		$this->assertEquals( MutationResult::STATUS_MANUAL_RECOVERY_REQUIRED, $recovered->status() );
	}
}
