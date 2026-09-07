<?php
/**
 * Unit tests: ChangeSet lifecycle state machine (Sprint 0.3A Phase 2
 * hardening, Package H). ChangeSetState is the single authority for
 * legal transitions — nothing else in the codebase may decide that.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Mutation\ChangeSetState;
use AIOS\Mutation\MutationException;
use AIOS\Tests\TestCase;

final class ChangeSetStateTest extends TestCase {

	public function test_legal_forward_path_is_allowed(): void {
		$path = array(
			ChangeSetState::PLANNED,
			ChangeSetState::SNAPSHOTTED,
			ChangeSetState::DIFF_READY,
			ChangeSetState::PENDING_APPROVAL,
			ChangeSetState::APPROVED,
			ChangeSetState::APPLYING,
			ChangeSetState::VERIFYING,
			ChangeSetState::COMPLETED,
		);
		for ( $i = 0; $i < count( $path ) - 1; $i++ ) {
			$this->assertTrue( ChangeSetState::isValidTransition( $path[ $i ], $path[ $i + 1 ] ), "{$path[$i]} -> {$path[$i+1]} must be legal" );
		}
	}

	public function test_auto_apply_path_skips_pending_approval(): void {
		$this->assertTrue( ChangeSetState::isValidTransition( ChangeSetState::DIFF_READY, ChangeSetState::APPLYING ) );
	}

	public function test_pending_approval_to_completed_is_illegal(): void {
		$this->assertFalse( ChangeSetState::isValidTransition( ChangeSetState::PENDING_APPROVAL, ChangeSetState::COMPLETED ) );
	}

	public function test_failed_to_applying_is_illegal(): void {
		$this->assertFalse( ChangeSetState::isValidTransition( ChangeSetState::FAILED, ChangeSetState::APPLYING ) );
	}

	public function test_completed_is_terminal_no_further_transition(): void {
		$this->assertTrue( ChangeSetState::isTerminal( ChangeSetState::COMPLETED ) );
		foreach ( ChangeSetState::all() as $state ) {
			$this->assertFalse( ChangeSetState::isValidTransition( ChangeSetState::COMPLETED, $state ), "COMPLETED -> {$state} must never be legal (replay must not re-apply)" );
		}
	}

	public function test_rollback_path_is_legal(): void {
		$this->assertTrue( ChangeSetState::isValidTransition( ChangeSetState::VERIFYING, ChangeSetState::ROLLBACK_REQUIRED ) );
		$this->assertTrue( ChangeSetState::isValidTransition( ChangeSetState::ROLLBACK_REQUIRED, ChangeSetState::ROLLING_BACK ) );
		$this->assertTrue( ChangeSetState::isValidTransition( ChangeSetState::ROLLING_BACK, ChangeSetState::ROLLED_BACK ) );
		$this->assertTrue( ChangeSetState::isValidTransition( ChangeSetState::ROLLING_BACK, ChangeSetState::ROLLBACK_FAILED ) );
	}

	public function test_rollback_failed_can_only_escalate_to_manual_recovery(): void {
		$this->assertFalse( ChangeSetState::isTerminal( ChangeSetState::ROLLBACK_FAILED ) );
		$this->assertTrue( ChangeSetState::isValidTransition( ChangeSetState::ROLLBACK_FAILED, ChangeSetState::MANUAL_RECOVERY_REQUIRED ) );
		foreach ( ChangeSetState::all() as $state ) {
			if ( ChangeSetState::MANUAL_RECOVERY_REQUIRED === $state ) {
				continue;
			}
			$this->assertFalse( ChangeSetState::isValidTransition( ChangeSetState::ROLLBACK_FAILED, $state ), "ROLLBACK_FAILED -> {$state} must never be legal" );
		}
	}

	public function test_manual_recovery_required_is_reachable_from_every_non_terminal_state_and_is_itself_terminal(): void {
		foreach ( ChangeSetState::all() as $state ) {
			if ( ChangeSetState::MANUAL_RECOVERY_REQUIRED === $state || ChangeSetState::isTerminal( $state ) ) {
				continue;
			}
			$this->assertTrue(
				ChangeSetState::isValidTransition( $state, ChangeSetState::MANUAL_RECOVERY_REQUIRED ),
				"{$state} -> MANUAL_RECOVERY_REQUIRED must be legal (crash recovery must be able to fail closed from any live state)"
			);
		}
		$this->assertTrue( ChangeSetState::isTerminal( ChangeSetState::MANUAL_RECOVERY_REQUIRED ) );
	}

	public function test_assert_transition_throws_on_illegal_transition(): void {
		try {
			ChangeSetState::assertTransition( ChangeSetState::COMPLETED, ChangeSetState::APPLYING );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'changeset.illegal_transition', $e->errorCode() );
		}
	}

	public function test_assert_transition_succeeds_silently_on_legal_transition(): void {
		ChangeSetState::assertTransition( ChangeSetState::PLANNED, ChangeSetState::SNAPSHOTTED );
		$this->assertTrue( true );
	}

	public function test_unknown_state_has_no_legal_outgoing_transitions(): void {
		$this->assertTrue( ChangeSetState::isTerminal( 'not_a_real_state' ) );
	}
}
