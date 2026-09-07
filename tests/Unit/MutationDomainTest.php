<?php
/**
 * Unit tests: Phase 2 mutation domain model (Sprint 0.3A, Phase 2
 * foundation). Covers ChangeSet validation, MutationResult factory
 * shapes, and the small value objects (Snapshot, VerificationResult,
 * RollbackRecord) in isolation from any real operation or the
 * pipeline itself — see tests/Integration/MutationEngineTest.php for
 * pipeline-ordering and real-operation coverage.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Mutation\ChangeOperationInterface;
use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\MutationResult;
use AIOS\Mutation\RollbackRecord;
use AIOS\Mutation\Snapshot;
use AIOS\Mutation\VerificationResult;
use AIOS\Security\PermissionEngine;
use AIOS\Tests\TestCase;

final class MutationDomainTest extends TestCase {

	/**
	 * Minimal, real (non-mock-framework) stub implementing the
	 * interface, for domain-model tests that only need "some
	 * operation," not any real filesystem/WP behavior.
	 */
	private function stubOperation( int $risk = PermissionEngine::LEVEL_SAFE_WRITE, string $type = 'stub.op' ): ChangeOperationInterface {
		return new class( $risk, $type ) implements ChangeOperationInterface {
			private string $id;
			public function __construct( private int $risk, private string $stubType ) {
				$this->id = 'op_' . bin2hex( random_bytes( 6 ) );
			}
			public function id(): string { return $this->id; }
			public function type(): string { return $this->stubType; }
			public function target(): string { return 'stub-target'; }
			public function riskLevel(): int { return $this->risk; }
			public function describe(): array { return array(); }
			public function captureSnapshot(): Snapshot { return new Snapshot( $this->id, $this->stubType, array() ); }
			public function apply(): void {}
			public function verify(): VerificationResult { return VerificationResult::success( $this->id ); }
			public function rollback( Snapshot $snapshot ): RollbackRecord { return RollbackRecord::success( $this->id, $snapshot->id() ); }
		};
	}

	// -------------------------------------------------------------- ChangeSet

	public function test_changeset_requires_at_least_one_operation(): void {
		try {
			new ChangeSet( 1, array() );
			$this->assertTrue( false, 'expected InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( true );
		}
	}

	public function test_changeset_rejects_non_operation_items(): void {
		try {
			/** @phpstan-ignore-next-line intentionally wrong type for the test */
			new ChangeSet( 1, array( 'not-an-operation' ) );
			$this->assertTrue( false, 'expected InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( true );
		}
	}

	public function test_changeset_requires_a_valid_principal(): void {
		try {
			new ChangeSet( 0, array( $this->stubOperation() ) );
			$this->assertTrue( false, 'expected InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( true );
		}
	}

	public function test_changeset_risk_level_is_the_maximum_across_operations(): void {
		$cs = new ChangeSet( 1, array(
			$this->stubOperation( PermissionEngine::LEVEL_SAFE_WRITE ),
			$this->stubOperation( PermissionEngine::LEVEL_DESTRUCTIVE ),
			$this->stubOperation( PermissionEngine::LEVEL_READ ),
		) );
		$this->assertEquals( PermissionEngine::LEVEL_DESTRUCTIVE, $cs->riskLevel() );
	}

	public function test_changeset_has_a_stable_generated_id_and_metadata(): void {
		$cs = new ChangeSet( 5, array( $this->stubOperation() ), array( 'reason' => 'test' ), 2, 'user' );
		$this->assertTrue( str_starts_with( $cs->id(), 'cs_' ) );
		$this->assertEquals( 5, $cs->principalUserId() );
		$this->assertEquals( 2, $cs->siteId() );
		$this->assertEquals( 'user', $cs->principalType() );
		$this->assertEquals( array( 'reason' => 'test' ), $cs->metadata() );
	}

	public function test_changeset_describe_lists_every_operation(): void {
		$cs = new ChangeSet( 1, array( $this->stubOperation( PermissionEngine::LEVEL_SAFE_WRITE, 'stub.a' ), $this->stubOperation( PermissionEngine::LEVEL_SAFE_WRITE, 'stub.b' ) ) );
		$described = $cs->describe();
		$this->assertCount( 2, $described['operations'] );
		$this->assertEquals( 'stub.a', $described['operations'][0]['type'] );
		$this->assertEquals( 'stub.b', $described['operations'][1]['type'] );
	}

	// -------------------------------------------------------------- MutationResult

	public function test_mutation_result_applied_is_ok(): void {
		$result = MutationResult::applied( 'cs_1', array() );
		$this->assertTrue( $result->ok() );
		$this->assertEquals( MutationResult::STATUS_APPLIED, $result->status() );
	}

	public function test_mutation_result_non_applied_statuses_are_not_ok(): void {
		foreach ( array(
			MutationResult::approvalRequired( 'cs_1', 42 ),
			MutationResult::policyDenied( 'cs_1', 'x' ),
			MutationResult::rejected( 'cs_1', 'x' ),
			MutationResult::snapshotFailed( 'cs_1', 'x' ),
			MutationResult::applyFailed( 'cs_1', 'x', array() ),
			MutationResult::verificationFailed( 'cs_1', array(), array() ),
			MutationResult::rolledBack( 'cs_1', array(), array() ),
			MutationResult::rollbackFailed( 'cs_1', array(), array(), 'x' ),
		) as $result ) {
			$this->assertFalse( $result->ok(), $result->status() . ' must not be ok()' );
		}
	}

	public function test_mutation_result_approval_required_carries_the_approval_id(): void {
		$result = MutationResult::approvalRequired( 'cs_1', 99 );
		$this->assertEquals( 99, $result->approvalId() );
	}

	public function test_mutation_result_to_array_shape(): void {
		$array = MutationResult::applied( 'cs_1', array( VerificationResult::success( 'op_1' ) ) )->toArray();
		$this->assertArrayHasKey( 'status', $array );
		$this->assertArrayHasKey( 'verifications', $array );
		$this->assertCount( 1, $array['verifications'] );
	}

	// -------------------------------------------------------------- value objects

	public function test_snapshot_accessors(): void {
		$snapshot = new Snapshot( 'op_1', 'file.create', array( 'a' => 1 ) );
		$this->assertTrue( str_starts_with( $snapshot->id(), 'snap_' ) );
		$this->assertEquals( 'op_1', $snapshot->operationId() );
		$this->assertEquals( 'file.create', $snapshot->operationType() );
		$this->assertEquals( array( 'a' => 1 ), $snapshot->state() );
	}

	public function test_verification_result_success_and_failure(): void {
		$ok = VerificationResult::success( 'op_1', 'all good' );
		$this->assertTrue( $ok->ok() );
		$this->assertEquals( 'all good', $ok->message() );

		$fail = VerificationResult::failure( 'op_1', 'mismatch', array( 'expected' => 'a' ) );
		$this->assertFalse( $fail->ok() );
		$this->assertEquals( array( 'expected' => 'a' ), $fail->context() );
	}

	public function test_rollback_record_success_and_failure(): void {
		$ok = RollbackRecord::success( 'op_1', 'snap_1' );
		$this->assertTrue( $ok->ok() );

		$fail = RollbackRecord::failure( 'op_1', 'snap_1', 'could not restore' );
		$this->assertFalse( $fail->ok() );
		$this->assertEquals( 'could not restore', $fail->message() );
	}
}
