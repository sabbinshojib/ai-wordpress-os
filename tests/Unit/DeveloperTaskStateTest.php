<?php
/**
 * Unit tests: DeveloperTaskState lifecycle state machine.
 *
 * Verifies strict, explicit state transitions, fail-closed enforcement of
 * illegal transitions, and immutable terminal state locking.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Developer\Plan\DeveloperTaskState;
use AIOS\Tests\TestCase;

final class DeveloperTaskStateTest extends TestCase {

	public function test_all_expected_states_exist(): void {
		$expected = array(
			'draft',
			'planned',
			'validating',
			'awaiting_approval',
			'approved',
			'applying',
			'completed',
			'failed',
			'cancelled',
		);
		$this->assertEquals( $expected, DeveloperTaskState::all() );
	}

	public function test_legal_standard_pipeline_path(): void {
		$pipeline = array(
			DeveloperTaskState::DRAFT,
			DeveloperTaskState::PLANNED,
			DeveloperTaskState::VALIDATING,
			DeveloperTaskState::AWAITING_APPROVAL,
			DeveloperTaskState::APPROVED,
			DeveloperTaskState::APPLYING,
			DeveloperTaskState::COMPLETED,
		);

		for ( $i = 0; $i < count( $pipeline ) - 1; $i++ ) {
			$from = $pipeline[ $i ];
			$to   = $pipeline[ $i + 1 ];
			$this->assertTrue( DeveloperTaskState::isLegal( $from, $to ), "{$from} -> {$to} must be legal" );
			DeveloperTaskState::assertLegal( $from, $to );
		}
	}

	public function test_legal_auto_approve_path(): void {
		// Planned directly to approved when no approval is required.
		$this->assertTrue( DeveloperTaskState::isLegal( DeveloperTaskState::PLANNED, DeveloperTaskState::APPROVED ) );
		DeveloperTaskState::assertLegal( DeveloperTaskState::PLANNED, DeveloperTaskState::APPROVED );

		// Validating directly to approved.
		$this->assertTrue( DeveloperTaskState::isLegal( DeveloperTaskState::VALIDATING, DeveloperTaskState::APPROVED ) );
		DeveloperTaskState::assertLegal( DeveloperTaskState::VALIDATING, DeveloperTaskState::APPROVED );
	}

	public function test_legal_cancellation_paths(): void {
		$cancellable = array(
			DeveloperTaskState::DRAFT,
			DeveloperTaskState::PLANNED,
			DeveloperTaskState::VALIDATING,
			DeveloperTaskState::AWAITING_APPROVAL,
			DeveloperTaskState::APPROVED,
		);

		foreach ( $cancellable as $state ) {
			$this->assertTrue( DeveloperTaskState::isLegal( $state, DeveloperTaskState::CANCELLED ), "{$state} -> cancelled must be legal" );
			DeveloperTaskState::assertLegal( $state, DeveloperTaskState::CANCELLED );
		}
	}

	public function test_legal_failure_paths(): void {
		$failable = array(
			DeveloperTaskState::VALIDATING,
			DeveloperTaskState::AWAITING_APPROVAL,
			DeveloperTaskState::APPLYING,
		);

		foreach ( $failable as $state ) {
			$this->assertTrue( DeveloperTaskState::isLegal( $state, DeveloperTaskState::FAILED ), "{$state} -> failed must be legal" );
			DeveloperTaskState::assertLegal( $state, DeveloperTaskState::FAILED );
		}
	}

	public function test_illegal_shortcut_transitions_fail_closed(): void {
		$illegal_pairs = array(
			array( DeveloperTaskState::DRAFT, DeveloperTaskState::COMPLETED ),
			array( DeveloperTaskState::DRAFT, DeveloperTaskState::APPLYING ),
			array( DeveloperTaskState::PLANNED, DeveloperTaskState::COMPLETED ),
			array( DeveloperTaskState::AWAITING_APPROVAL, DeveloperTaskState::APPLYING ),
			array( DeveloperTaskState::APPROVED, DeveloperTaskState::COMPLETED ),
		);

		foreach ( $illegal_pairs as $pair ) {
			list( $from, $to ) = $pair;
			$this->assertFalse( DeveloperTaskState::isLegal( $from, $to ), "{$from} -> {$to} must be illegal" );

			$threw = false;
			try {
				DeveloperTaskState::assertLegal( $from, $to );
			} catch ( \DomainException ) {
				$threw = true;
			}
			$this->assertTrue( $threw, "assertLegal must throw DomainException for {$from} -> {$to}" );
		}
	}

	public function test_terminal_states_cannot_transition(): void {
		$terminals = DeveloperTaskState::terminalStates();
		$this->assertEquals(
			array( DeveloperTaskState::COMPLETED, DeveloperTaskState::FAILED, DeveloperTaskState::CANCELLED ),
			$terminals
		);

		foreach ( $terminals as $terminal ) {
			$this->assertTrue( DeveloperTaskState::isTerminal( $terminal ) );
			foreach ( DeveloperTaskState::all() as $target ) {
				$this->assertFalse( DeveloperTaskState::isLegal( $terminal, $target ), "Terminal {$terminal} -> {$target} must not be legal" );

				$threw = false;
				try {
					DeveloperTaskState::assertLegal( $terminal, $target );
				} catch ( \DomainException ) {
					$threw = true;
				}
				$this->assertTrue( $threw, "assertLegal must throw DomainException when transitioning out of terminal {$terminal}" );
			}
		}
	}

	public function test_unknown_states_throw_invalid_argument_exception(): void {
		$threw_source = false;
		try {
			DeveloperTaskState::assertLegal( 'bogus_state', DeveloperTaskState::PLANNED );
		} catch ( \InvalidArgumentException ) {
			$threw_source = true;
		}
		$this->assertTrue( $threw_source );

		$threw_target = false;
		try {
			DeveloperTaskState::assertLegal( DeveloperTaskState::DRAFT, 'bogus_state' );
		} catch ( \InvalidArgumentException ) {
			$threw_target = true;
		}
		$this->assertTrue( $threw_target );
	}
}
