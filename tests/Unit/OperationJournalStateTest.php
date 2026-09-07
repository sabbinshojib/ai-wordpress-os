<?php
/**
 * Unit tests: per-operation lifecycle state machine (Sprint 0.3A
 * Phase 2 final hardening, Package D).
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Mutation\MutationException;
use AIOS\Mutation\OperationJournalState;
use AIOS\Tests\TestCase;

final class OperationJournalStateTest extends TestCase {

	public function test_legal_success_path_is_allowed(): void {
		$path = array(
			OperationJournalState::PENDING,
			OperationJournalState::SNAPSHOTTED,
			OperationJournalState::APPLYING,
			OperationJournalState::APPLIED,
			OperationJournalState::VERIFYING,
			OperationJournalState::VERIFIED,
		);
		for ( $i = 0; $i < count( $path ) - 1; $i++ ) {
			$this->assertTrue( OperationJournalState::isValidTransition( $path[ $i ], $path[ $i + 1 ] ), "{$path[$i]} -> {$path[$i+1]} must be legal" );
		}
	}

	public function test_pending_to_verified_is_illegal(): void {
		$this->assertFalse( OperationJournalState::isValidTransition( OperationJournalState::PENDING, OperationJournalState::VERIFIED ) );
	}

	public function test_verified_to_applying_is_illegal(): void {
		$this->assertFalse( OperationJournalState::isValidTransition( OperationJournalState::VERIFIED, OperationJournalState::APPLYING ) );
	}

	public function test_rolled_back_to_applying_is_illegal(): void {
		$this->assertFalse( OperationJournalState::isValidTransition( OperationJournalState::ROLLED_BACK, OperationJournalState::APPLYING ) );
	}

	public function test_rollback_failed_to_applying_is_illegal(): void {
		$this->assertFalse( OperationJournalState::isValidTransition( OperationJournalState::ROLLBACK_FAILED, OperationJournalState::APPLYING ) );
	}

	public function test_an_already_applied_operation_can_still_be_told_to_roll_back(): void {
		// A sibling operation failing later in the same ChangeSet is
		// what drives this, not anything wrong with this operation.
		$this->assertTrue( OperationJournalState::isValidTransition( OperationJournalState::APPLIED, OperationJournalState::ROLLBACK_REQUIRED ) );
		$this->assertTrue( OperationJournalState::isValidTransition( OperationJournalState::VERIFIED, OperationJournalState::ROLLBACK_REQUIRED ) );
	}

	public function test_failed_apply_never_needs_rollback(): void {
		// An operation whose OWN apply() failed never actually mutated
		// anything, so FAILED is terminal for it — there is nothing to
		// undo for this specific operation.
		$this->assertTrue( OperationJournalState::isTerminal( OperationJournalState::FAILED ) );
	}

	public function test_rollback_failed_leads_only_to_manual_recovery(): void {
		$this->assertTrue( OperationJournalState::isValidTransition( OperationJournalState::ROLLBACK_FAILED, OperationJournalState::MANUAL_RECOVERY_REQUIRED ) );
		$this->assertTrue( OperationJournalState::isTerminal( OperationJournalState::MANUAL_RECOVERY_REQUIRED ) );
	}

	public function test_was_applied_reflects_whether_a_rollback_could_ever_be_needed(): void {
		$this->assertFalse( OperationJournalState::wasApplied( OperationJournalState::PENDING ) );
		$this->assertFalse( OperationJournalState::wasApplied( OperationJournalState::SNAPSHOTTED ) );
		$this->assertFalse( OperationJournalState::wasApplied( OperationJournalState::APPLYING ) );
		$this->assertFalse( OperationJournalState::wasApplied( OperationJournalState::FAILED ) );
		$this->assertTrue( OperationJournalState::wasApplied( OperationJournalState::APPLIED ) );
		$this->assertTrue( OperationJournalState::wasApplied( OperationJournalState::VERIFIED ) );
	}

	public function test_assert_transition_throws_on_illegal_transition(): void {
		try {
			OperationJournalState::assertTransition( OperationJournalState::PENDING, OperationJournalState::APPLIED );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'operation_journal.illegal_transition', $e->errorCode() );
		}
	}
}
