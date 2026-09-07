<?php
/**
 * Integration tests: per-operation crash-recovery journal wired into
 * the live durable apply path (Sprint 0.3A Phase 2 final hardening,
 * Packages D/E/F/G/N). Covers:
 *
 *   - the journal actually advances through real lifecycle states
 *     during a genuine submit()/resume() run (not just in isolation
 *     against OperationJournalRepository directly, as
 *     OperationJournalRepositoryTest.php already covers);
 *   - DurableMutationCoordinator::recover()'s crash classification:
 *     the two unambiguous edge cases skip escalation, everything else
 *     fails closed to ChangeSetState::MANUAL_RECOVERY_REQUIRED;
 *   - a ChangeSet escalated to MANUAL_RECOVERY_REQUIRED is never
 *     auto-purged by retention, and the purge cascade removes a
 *     purged ChangeSet's journal rows with it.
 *
 * A real process crash cannot be induced from inside a single PHP
 * test process, so "mid-flight" is modeled the same way the rest of
 * this test suite models it elsewhere in this file (e.g.
 * ChangeSetRepositoryTest's expired-lease test): by directly forcing
 * one journal row into an in-flight state via the repository's own
 * CAS transition() — exactly the durable fact a real crash would
 * leave behind — then asking recover() to classify it.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\ChangeSetRepository;
use AIOS\Mutation\ChangeSetState;
use AIOS\Mutation\DurableMutationCoordinator;
use AIOS\Mutation\MutationResult;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Mutation\OperationJournalRepository;
use AIOS\Mutation\OperationJournalState;
use AIOS\Tests\TestCase;

final class CrashRecoveryTest extends TestCase {

	private DurableMutationCoordinator $coordinator;

	private ChangeSetRepository $repo;

	private OperationJournalRepository $journal;

	protected function setUp(): void {
		$this->resetPlugin();
		$container         = Plugin::instance()->container();
		$this->coordinator = $container->get( DurableMutationCoordinator::class );
		$this->repo        = $container->get( ChangeSetRepository::class );
		$this->journal     = $container->get( OperationJournalRepository::class );
	}

	private function lowRiskChangeSet( string $option, string $value ): ChangeSet {
		$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'crash recovery test post' ) );
		return new ChangeSet( 1, array( new \AIOS\Mutation\Operations\MetadataUpdateOperation( (int) $post_id, $option, $value ) ) );
	}

	private function sensitiveChangeSet( string $option, string $value ): ChangeSet {
		return new ChangeSet( 1, array( new OptionUpdateOperation( $option, $value ) ) );
	}

	// -------------------------------------------------------------- journal advances on the real auto-apply path

	public function test_journal_row_reaches_verified_after_a_successful_auto_apply(): void {
		$admin = $this->adminUser();
		$cs    = $this->lowRiskChangeSet( 'aios_crash_meta_1', 'v1' );

		$result = $this->coordinator->submit( $cs, $admin );
		$this->assertTrue( $result->ok() );

		$row = $this->journal->load( $cs->id(), 0 );
		$this->assertNotNull( $row );
		$this->assertEquals( OperationJournalState::VERIFIED, $row['state'] );
		$this->assertNotNull( $row['apply_started_at'] );
		$this->assertNotNull( $row['apply_completed_at'] );
		$this->assertNotNull( $row['verify_started_at'] );
		$this->assertNotNull( $row['verify_completed_at'] );

		$recovery = $this->journal->loadRecovery( $cs->id(), 0 );
		$this->assertNotNull( $recovery, 'the pre-apply snapshot state must have been journaled before apply ran' );
	}

	public function test_journal_row_reaches_verified_after_resume_approved(): void {
		$admin = $this->adminUser();
		update_option( 'aios_crash_option_1', 'original' );
		$cs    = $this->sensitiveChangeSet( 'aios_crash_option_1', 'new-value' );

		$submitted = $this->coordinator->submit( $cs, $admin );
		$this->assertEquals( MutationResult::STATUS_APPROVAL_REQUIRED, $submitted->status() );

		// Submission already ran captureSnapshots() once — the journal row
		// must be SNAPSHOTTED, not stuck at PENDING, while awaiting approval.
		$row = $this->journal->load( $cs->id(), 0 );
		$this->assertEquals( OperationJournalState::SNAPSHOTTED, $row['state'] );

		$result = $this->coordinator->resume( $cs->id(), (int) $submitted->approvalId(), 'approved', $admin );
		$this->assertTrue( $result->ok() );

		// resumeApproved() re-snapshots a SECOND time (fresh precondition
		// capture) — this must not throw on the already-SNAPSHOTTED row,
		// and must still end up VERIFIED, not stuck.
		$row = $this->journal->load( $cs->id(), 0 );
		$this->assertEquals( OperationJournalState::VERIFIED, $row['state'] );
	}

	// -------------------------------------------------------------- recover(): unambiguous edge cases

	public function test_recover_of_unknown_change_set_is_rejected(): void {
		$result = $this->coordinator->recover( 'cs_does_not_exist' );
		$this->assertEquals( MutationResult::STATUS_REJECTED, $result->status() );
	}

	public function test_recover_of_a_completed_change_set_needs_no_recovery(): void {
		$admin = $this->adminUser();
		$cs    = $this->lowRiskChangeSet( 'aios_crash_meta_2', 'v1' );
		$this->coordinator->submit( $cs, $admin );

		$result = $this->coordinator->recover( $cs->id() );
		$this->assertEquals( MutationResult::STATUS_RECOVERY_NOT_NEEDED, $result->status() );
		$this->assertEquals( ChangeSetState::COMPLETED, $this->repo->load( $cs->id() )['state'], 'recover() must never touch an already-terminal row' );
	}

	public function test_recover_of_a_change_set_still_genuinely_awaiting_approval_needs_no_recovery(): void {
		$admin = $this->adminUser();
		$cs    = $this->sensitiveChangeSet( 'aios_crash_option_2', 'v' );
		$this->coordinator->submit( $cs, $admin );

		// Nothing has crashed here — this is the ordinary, expected
		// "waiting for a human" state. recover() must not escalate it.
		$result = $this->coordinator->recover( $cs->id() );
		$this->assertEquals( MutationResult::STATUS_RECOVERY_NOT_NEEDED, $result->status() );
		$this->assertEquals( ChangeSetState::PENDING_APPROVAL, $this->repo->load( $cs->id() )['state'] );
	}

	// -------------------------------------------------------------- recover(): ambiguous mid-flight state fails closed

	public function test_recover_escalates_to_manual_recovery_when_a_journal_row_is_stuck_mid_apply(): void {
		$admin = $this->adminUser();
		$cs    = $this->sensitiveChangeSet( 'aios_crash_option_3', 'v' );
		$this->coordinator->submit( $cs, $admin ); // stops at PENDING_APPROVAL, journal row SNAPSHOTTED.

		// Model a crash: an executor's resume() began applying this
		// operation and the process died before apply_completed ever
		// fired — the durable fact left behind is a journal row stuck
		// at APPLYING, exactly what transition() below reproduces.
		$before = $this->journal->load( $cs->id(), 0 );
		$this->journal->transition( $cs->id(), 0, OperationJournalState::SNAPSHOTTED, OperationJournalState::APPLYING, (int) $before['state_version'] );

		$result = $this->coordinator->recover( $cs->id() );

		$this->assertEquals( MutationResult::STATUS_MANUAL_RECOVERY_REQUIRED, $result->status() );
		$this->assertNotNull( $result->error() );
		$this->assertEquals( ChangeSetState::MANUAL_RECOVERY_REQUIRED, $this->repo->load( $cs->id() )['state'] );
	}

	public function test_recover_escalates_when_only_some_operations_in_a_multi_op_change_set_show_progress(): void {
		$admin = $this->adminUser();
		update_option( 'aios_crash_multi_a', 'x' );
		update_option( 'aios_crash_multi_b', 'x' );
		$cs = new ChangeSet(
			1,
			array(
				new OptionUpdateOperation( 'aios_crash_multi_a', 'new-a' ),
				new OptionUpdateOperation( 'aios_crash_multi_b', 'new-b' ),
			)
		);
		$this->coordinator->submit( $cs, $admin ); // both journal rows SNAPSHOTTED.

		// Simulate: operation A (index 0) applied cleanly, operation B
		// (index 1) never even started — a crash squarely between the two.
		$row_a = $this->journal->load( $cs->id(), 0 );
		$this->journal->transition( $cs->id(), 0, OperationJournalState::SNAPSHOTTED, OperationJournalState::APPLYING, (int) $row_a['state_version'] );
		$row_a = $this->journal->load( $cs->id(), 0 );
		$this->journal->transition( $cs->id(), 0, OperationJournalState::APPLYING, OperationJournalState::APPLIED, (int) $row_a['state_version'] );

		$result = $this->coordinator->recover( $cs->id() );

		$this->assertEquals( MutationResult::STATUS_MANUAL_RECOVERY_REQUIRED, $result->status() );
	}

	// -------------------------------------------------------------- retention

	public function test_manual_recovery_required_change_set_is_never_purged(): void {
		$admin = $this->adminUser();
		$cs    = $this->sensitiveChangeSet( 'aios_crash_option_4', 'v' );
		$this->coordinator->submit( $cs, $admin );

		$before = $this->journal->load( $cs->id(), 0 );
		$this->journal->transition( $cs->id(), 0, OperationJournalState::SNAPSHOTTED, OperationJournalState::APPLYING, (int) $before['state_version'] );
		$this->coordinator->recover( $cs->id() );
		$this->assertEquals( ChangeSetState::MANUAL_RECOVERY_REQUIRED, $this->repo->load( $cs->id() )['state'] );

		global $wpdb;
		foreach ( $wpdb->tables['ai_os_change_sets'] as $i => $row ) {
			if ( $row['change_set_id'] === $cs->id() ) {
				$wpdb->tables['ai_os_change_sets'][ $i ]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - 999 * DAY_IN_SECONDS );
			}
		}

		$purged = $this->repo->purgeTerminalOlderThan( 0 );
		$this->assertEquals( 0, $purged, 'MANUAL_RECOVERY_REQUIRED rows must never be auto-purged' );
		$this->assertNotNull( $this->repo->load( $cs->id() ) );
		$this->assertNotNull( $this->journal->load( $cs->id(), 0 ), 'journal rows for an unpurged ChangeSet must remain too' );
	}

	public function test_purge_cascades_to_delete_the_journal_rows_of_a_purged_change_set(): void {
		$admin = $this->adminUser();
		$cs    = $this->lowRiskChangeSet( 'aios_crash_meta_3', 'v1' );
		$this->coordinator->submit( $cs, $admin ); // COMPLETED, journal row VERIFIED.

		$this->assertNotNull( $this->journal->load( $cs->id(), 0 ) );

		global $wpdb;
		foreach ( $wpdb->tables['ai_os_change_sets'] as $i => $row ) {
			if ( $row['change_set_id'] === $cs->id() ) {
				$wpdb->tables['ai_os_change_sets'][ $i ]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - 999 * DAY_IN_SECONDS );
			}
		}

		$purged = $this->repo->purgeTerminalOlderThan( 0 );
		$this->assertEquals( 1, $purged );
		$this->assertNull( $this->repo->load( $cs->id() ) );
		$this->assertNull( $this->journal->load( $cs->id(), 0 ), 'the journal row must be cascade-deleted with its ChangeSet' );
	}
}
