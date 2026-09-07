<?php
/**
 * Integration tests: durable per-operation recovery journal (Sprint
 * 0.3A Phase 2 final hardening, Package B). Covers create/load,
 * ordering by operation_index, encrypted recovery round-trip and
 * tamper detection, and CAS transitions via OperationJournalState.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Database\Database;
use AIOS\Mutation\MutationException;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Mutation\OperationJournalRepository;
use AIOS\Mutation\OperationJournalState;
use AIOS\Tests\TestCase;

final class OperationJournalRepositoryTest extends TestCase {

	private OperationJournalRepository $repo;

	protected function setUp(): void {
		$this->resetPlugin();
		$this->repo = Plugin::instance()->container()->get( OperationJournalRepository::class );
	}

	public function test_create_and_load_round_trip(): void {
		$op  = new OptionUpdateOperation( 'aios_journal_option', 'v1' );
		$row = $this->repo->create( 'cs_test1', 0, $op, 1 );

		$this->assertEquals( 'cs_test1', $row['change_set_id'] );
		$this->assertEquals( $op->id(), $row['operation_id'] );
		$this->assertEquals( 0, $row['operation_index'] );
		$this->assertEquals( 'option.update', $row['operation_type'] );
		$this->assertEquals( OperationJournalState::PENDING, $row['state'] );
		$this->assertEquals( 0, $row['state_version'] );

		$loaded = $this->repo->load( 'cs_test1', $op->id() );
		$this->assertEquals( $row, $loaded );
	}

	public function test_load_missing_row_returns_null(): void {
		$this->assertNull( $this->repo->load( 'cs_none', 'op_none' ) );
	}

	public function test_load_for_change_set_orders_by_operation_index(): void {
		$op_a = new OptionUpdateOperation( 'aios_journal_a', 'a' );
		$op_b = new OptionUpdateOperation( 'aios_journal_b', 'b' );
		$op_c = new OptionUpdateOperation( 'aios_journal_c', 'c' );

		// Insert deliberately out of order.
		$this->repo->create( 'cs_order', 2, $op_c, 1 );
		$this->repo->create( 'cs_order', 0, $op_a, 1 );
		$this->repo->create( 'cs_order', 1, $op_b, 1 );

		$rows = $this->repo->loadForChangeSet( 'cs_order' );
		$this->assertCount( 3, $rows );
		$this->assertEquals( $op_a->id(), $rows[0]['operation_id'] );
		$this->assertEquals( $op_b->id(), $rows[1]['operation_id'] );
		$this->assertEquals( $op_c->id(), $rows[2]['operation_id'] );
	}

	// -------------------------------------------------------------- CAS transitions

	public function test_valid_transition_succeeds_and_bumps_version(): void {
		$op = new OptionUpdateOperation( 'aios_journal_cas', 'v' );
		$this->repo->create( 'cs_cas', 0, $op, 1 );

		$ok = $this->repo->transition( 'cs_cas', $op->id(), OperationJournalState::PENDING, OperationJournalState::SNAPSHOTTED, 0 );
		$this->assertTrue( $ok );

		$row = $this->repo->load( 'cs_cas', $op->id() );
		$this->assertEquals( OperationJournalState::SNAPSHOTTED, $row['state'] );
		$this->assertEquals( 1, $row['state_version'] );
	}

	public function test_illegal_transition_throws_before_any_write(): void {
		$op = new OptionUpdateOperation( 'aios_journal_illegal', 'v' );
		$this->repo->create( 'cs_illegal', 0, $op, 1 );

		try {
			$this->repo->transition( 'cs_illegal', $op->id(), OperationJournalState::PENDING, OperationJournalState::APPLIED, 0 );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'operation_journal.illegal_transition', $e->errorCode() );
		}
		$row = $this->repo->load( 'cs_illegal', $op->id() );
		$this->assertEquals( OperationJournalState::PENDING, $row['state'] );
	}

	public function test_stale_state_version_fails_the_cas(): void {
		$op = new OptionUpdateOperation( 'aios_journal_stale', 'v' );
		$this->repo->create( 'cs_stale', 0, $op, 1 );
		$this->repo->transition( 'cs_stale', $op->id(), OperationJournalState::PENDING, OperationJournalState::SNAPSHOTTED, 0 );

		$ok = $this->repo->transition( 'cs_stale', $op->id(), OperationJournalState::PENDING, OperationJournalState::SNAPSHOTTED, 0 );
		$this->assertFalse( $ok );
	}

	public function test_two_journal_rows_for_the_same_changeset_transition_independently(): void {
		$op_a = new OptionUpdateOperation( 'aios_journal_indep_a', 'a' );
		$op_b = new OptionUpdateOperation( 'aios_journal_indep_b', 'b' );
		$this->repo->create( 'cs_indep', 0, $op_a, 1 );
		$this->repo->create( 'cs_indep', 1, $op_b, 1 );

		$this->repo->transition( 'cs_indep', $op_a->id(), OperationJournalState::PENDING, OperationJournalState::SNAPSHOTTED, 0 );

		$row_a = $this->repo->load( 'cs_indep', $op_a->id() );
		$row_b = $this->repo->load( 'cs_indep', $op_b->id() );
		$this->assertEquals( OperationJournalState::SNAPSHOTTED, $row_a['state'] );
		$this->assertEquals( OperationJournalState::PENDING, $row_b['state'], 'transitioning one operations journal row must never affect a sibling' );
	}

	// -------------------------------------------------------------- encrypted recovery

	public function test_recovery_state_round_trips_encrypted(): void {
		$op = new OptionUpdateOperation( 'aios_journal_recovery', 'v' );
		$this->repo->create( 'cs_recovery', 0, $op, 1 );

		$this->assertNull( $this->repo->loadRecovery( 'cs_recovery', $op->id() ) );

		$state = array( 'original_value' => 'secret-original-value' );
		$this->assertTrue( $this->repo->saveRecovery( 'cs_recovery', $op->id(), $state, hash( 'sha256', 'x' ) ) );

		$loaded = $this->repo->loadRecovery( 'cs_recovery', $op->id() );
		$this->assertEquals( $state, $loaded );

		$row = $this->repo->load( 'cs_recovery', $op->id() );
		$this->assertFalse( str_contains( (string) $row['recovery_ciphertext'], 'secret-original-value' ) );
		$this->assertTrue( str_starts_with( (string) $row['recovery_ciphertext'], 'aios1:' ) );
	}

	public function test_tampered_recovery_ciphertext_fails_closed(): void {
		$op = new OptionUpdateOperation( 'aios_journal_tamper', 'v' );
		$this->repo->create( 'cs_tamper', 0, $op, 1 );
		$this->repo->saveRecovery( 'cs_tamper', $op->id(), array( 'x' => 1 ), 'h' );

		global $wpdb;
		foreach ( $wpdb->tables['ai_os_operation_journal'] as $i => $row ) {
			if ( $row['change_set_id'] === 'cs_tamper' ) {
				$wpdb->tables['ai_os_operation_journal'][ $i ]['recovery_ciphertext'] = 'aios1:sodium:1:dGFtcGVyZWQ=';
			}
		}

		try {
			$this->repo->loadRecovery( 'cs_tamper', $op->id() );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'changeset.decrypt_failed', $e->errorCode() );
		}
	}

	// -------------------------------------------------------------- timestamps

	public function test_mark_timestamp_records_a_recognized_column(): void {
		$op = new OptionUpdateOperation( 'aios_journal_ts', 'v' );
		$this->repo->create( 'cs_ts', 0, $op, 1 );
		$this->repo->markTimestamp( 'cs_ts', $op->id(), 'apply_started_at' );

		$row = $this->repo->load( 'cs_ts', $op->id() );
		$this->assertNotNull( $row['apply_started_at'] );
	}

	// -------------------------------------------------------------- retention cascade

	public function test_delete_for_change_set_removes_all_rows(): void {
		$op_a = new OptionUpdateOperation( 'aios_journal_del_a', 'a' );
		$op_b = new OptionUpdateOperation( 'aios_journal_del_b', 'b' );
		$this->repo->create( 'cs_delete', 0, $op_a, 1 );
		$this->repo->create( 'cs_delete', 1, $op_b, 1 );

		$deleted = $this->repo->deleteForChangeSet( 'cs_delete' );
		$this->assertEquals( 2, $deleted );
		$this->assertEquals( array(), $this->repo->loadForChangeSet( 'cs_delete' ) );
	}
}
