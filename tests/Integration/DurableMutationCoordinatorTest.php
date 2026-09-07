<?php
/**
 * Integration tests: the durable end-to-end path (Sprint 0.3A Phase 2
 * hardening, Package 9 — "caller must NOT need to reconstruct the
 * ChangeSet manually"). DurableMutationCoordinator ties
 * ChangeSetRepository + OperationRegistry + the already-tested
 * MutationEngine together; resume() takes only a ChangeSet id.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Database\Repositories\ApprovalRepository;
use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\ChangeSetRepository;
use AIOS\Mutation\ChangeSetState;
use AIOS\Mutation\DurableMutationCoordinator;
use AIOS\Mutation\MutationResult;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Security\PermissionEngine;
use AIOS\Tests\TestCase;

final class DurableMutationCoordinatorTest extends TestCase {

	private DurableMutationCoordinator $coordinator;

	private ChangeSetRepository $repo;

	private ApprovalRepository $approvals;

	protected function setUp(): void {
		$this->resetPlugin();
		$container         = Plugin::instance()->container();
		$this->coordinator = $container->get( DurableMutationCoordinator::class );
		$this->repo        = $container->get( ChangeSetRepository::class );
		$this->approvals   = $container->get( ApprovalRepository::class );
	}

	private function lowRiskChangeSet( string $option, string $value ): ChangeSet {
		// MetadataUpdateOperation is SAFE_WRITE (auto-applies in the
		// default safe mode, no approval needed) — requires a real,
		// existing post, so create one via the shim's normal post store.
		$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'coordinator test post' ) );
		return new ChangeSet( 1, array( new \AIOS\Mutation\Operations\MetadataUpdateOperation( (int) $post_id, $option, $value ) ) );
	}

	private function sensitiveChangeSet( string $option, string $value ): ChangeSet {
		return new ChangeSet( 1, array( new OptionUpdateOperation( $option, $value ) ) );
	}

	// -------------------------------------------------------------- auto-apply

	public function test_submit_auto_applies_low_risk_and_reaches_completed_durable_state(): void {
		$admin = $this->adminUser();
		$cs    = $this->lowRiskChangeSet( 'aios_coord_meta', 'v1' );

		$result = $this->coordinator->submit( $cs, $admin );

		$this->assertTrue( $result->ok() );
		$row = $this->repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::COMPLETED, $row['state'] );
	}

	// -------------------------------------------------------------- approval + resume by id only

	public function test_resume_by_id_alone_applies_the_change_without_caller_reconstructing_it(): void {
		$admin = $this->adminUser();
		update_option( 'aios_coord_option', 'original' );
		$cs = $this->sensitiveChangeSet( 'aios_coord_option', 'new-value' );

		$submitted = $this->coordinator->submit( $cs, $admin );
		$this->assertEquals( MutationResult::STATUS_APPROVAL_REQUIRED, $submitted->status() );
		$row = $this->repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::PENDING_APPROVAL, $row['state'] );

		// The caller here supplies ONLY the ChangeSet id — no ChangeSet
		// object, no operations, nothing reconstructed by hand.
		$result = $this->coordinator->resume( $cs->id(), (int) $submitted->approvalId(), 'approved', $admin );

		$this->assertTrue( $result->ok() );
		$this->assertEquals( 'new-value', get_option( 'aios_coord_option' ) );

		$row = $this->repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::COMPLETED, $row['state'] );
	}

	// -------------------------------------------------------------- durable replay protection

	public function test_completed_change_set_is_never_reapplied_on_replay(): void {
		$admin = $this->adminUser();
		update_option( 'aios_coord_replay_option', 'original' );
		$cs = $this->sensitiveChangeSet( 'aios_coord_replay_option', 'applied-once' );

		$submitted = $this->coordinator->submit( $cs, $admin );
		$this->coordinator->resume( $cs->id(), (int) $submitted->approvalId(), 'approved', $admin );
		$this->assertEquals( 'applied-once', get_option( 'aios_coord_replay_option' ) );

		update_option( 'aios_coord_replay_option', 'changed-after-completion' );

		// Replaying resume() for the SAME (now completed) ChangeSet must
		// never reapply — the option must stay exactly as it is.
		$replay = $this->coordinator->resume( $cs->id(), (int) $submitted->approvalId(), 'approved', $admin );

		$this->assertEquals( MutationResult::STATUS_ALREADY_COMPLETED, $replay->status() );
		$this->assertEquals( 'changed-after-completion', get_option( 'aios_coord_replay_option' ), 'a replay must never touch state again' );
	}

	// -------------------------------------------------------------- idempotent submit

	public function test_idempotent_submit_returns_the_same_outcome_without_duplicating(): void {
		$admin = $this->adminUser();
		$cs1 = $this->lowRiskChangeSet( 'aios_coord_idem', 'first' );
		$result1 = $this->coordinator->submit( $cs1, $admin, 'coord-idem-key' );
		$this->assertTrue( $result1->ok() );

		$cs2 = $this->lowRiskChangeSet( 'aios_coord_idem', 'second' ); // different id, different payload
		$result2 = $this->coordinator->submit( $cs2, $admin, 'coord-idem-key' );

		$this->assertEquals( MutationResult::STATUS_ALREADY_COMPLETED, $result2->status() );
		$this->assertEquals( $cs1->id(), $result2->changeSetId(), 'the idempotent replay must reference the ORIGINAL ChangeSet, not the second one' );
		$this->assertNull( $this->repo->load( $cs2->id() ), 'the seconds own ChangeSet must never have been persisted' );
	}

	// -------------------------------------------------------------- concurrency / lease

	public function test_resume_is_rejected_while_another_executor_holds_the_lease(): void {
		$admin = $this->adminUser();
		$cs = $this->sensitiveChangeSet( 'aios_coord_lease_option', 'v' );
		$submitted = $this->coordinator->submit( $cs, $admin );

		$this->assertTrue( $this->repo->acquireLease( $cs->id(), 'some-other-executor', 300 ) );

		$result = $this->coordinator->resume( $cs->id(), (int) $submitted->approvalId(), 'approved', $admin );

		$this->assertEquals( MutationResult::STATUS_REJECTED, $result->status() );
		$row = $this->repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::PENDING_APPROVAL, $row['state'], 'a lease conflict must leave durable state untouched' );
	}

	public function test_lease_is_released_after_a_successful_resume(): void {
		$admin = $this->adminUser();
		$cs = $this->sensitiveChangeSet( 'aios_coord_lease_release_option', 'v' );
		$submitted = $this->coordinator->submit( $cs, $admin );
		$this->coordinator->resume( $cs->id(), (int) $submitted->approvalId(), 'approved', $admin );

		// Lease must be released even on a successful terminal outcome —
		// a stray held lease on a COMPLETED row would be a permanent
		// (harmless but wasteful) leak.
		$this->assertTrue( $this->repo->acquireLease( $cs->id(), 'anyone', 60 ), 'the lease must have been released after resume() completed' );
	}

	// -------------------------------------------------------------- failure reflected durably

	/**
	 * The rollback-chain MECHANICS themselves (reverse-order rollback,
	 * rollback-failure reporting, etc.) are exhaustively covered against
	 * MutationEngine directly in MutationEngineTest.php using real spy
	 * operations — constructing a genuine apply/verify failure through
	 * only the REAL six operation classes (no spies) is impractical
	 * cross-platform (would need OS-level tricks like a read-only
	 * directory). This test instead proves the narrower thing that is
	 * actually this class's own responsibility: whatever MutationEngine
	 * reports for a failure gets reflected into the correct durable
	 * terminal state (FAILED here, via a snapshot failure — one of the
	 * two entry points into FAILED in ChangeSetState's transition table).
	 */
	public function test_engine_failure_reaches_the_failed_durable_state(): void {
		$admin = $this->adminUser();
		$cs = new ChangeSet( (int) $admin->ID, array( new \AIOS\Mutation\Operations\FileDeleteOperation( 'nonexistent-file-for-coordinator-test.txt', new \AIOS\Security\PathGuard( sys_get_temp_dir() ) ) ) );

		$result = $this->coordinator->submit( $cs, $admin );
		$this->assertEquals( MutationResult::STATUS_SNAPSHOT_FAILED, $result->status() );

		$row = $this->repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::FAILED, $row['state'] );
	}

	// -------------------------------------------------------------- unknown ChangeSet

	public function test_resume_of_unknown_change_set_id_is_rejected(): void {
		$admin  = $this->adminUser();
		$result = $this->coordinator->resume( 'cs_does_not_exist', 999, 'approved', $admin );
		$this->assertEquals( MutationResult::STATUS_REJECTED, $result->status() );
	}
}
