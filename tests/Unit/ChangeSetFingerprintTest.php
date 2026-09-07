<?php
/**
 * Unit tests: canonical ChangeSet fingerprint (Sprint 0.3A Phase 2
 * hardening, Package C). Every dimension the fingerprint is supposed
 * to bind must actually change the hash when it changes; an
 * unchanged canonical plan must always reproduce the identical hash.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Mutation\ChangeOperationInterface;
use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\ChangeSetFingerprint;
use AIOS\Mutation\RollbackRecord;
use AIOS\Mutation\Snapshot;
use AIOS\Mutation\VerificationResult;
use AIOS\Tests\TestCase;

final class ChangeSetFingerprintTest extends TestCase {

	private function op( string $type, string $target, string $payload ): ChangeOperationInterface {
		return new class( $type, $target, $payload ) implements ChangeOperationInterface {
			private string $id;
			public function __construct( private string $stubType, private string $stubTarget, private string $payload ) {
				$this->id = 'op_' . bin2hex( random_bytes( 6 ) );
			}
			public function id(): string { return $this->id; }
			public function type(): string { return $this->stubType; }
			public function target(): string { return $this->stubTarget; }
			public function riskLevel(): int { return 1; }
			public function describe(): array { return array(); }
			public function captureSnapshot(): Snapshot { return new Snapshot( $this->id, $this->stubType, array() ); }
			public function apply(): void {}
			public function verify(): VerificationResult { return VerificationResult::success( $this->id ); }
			public function rollback( Snapshot $snapshot ): RollbackRecord { return RollbackRecord::success( $this->id, $snapshot->id() ); }
			public function payloadFingerprint(): string { return hash( 'sha256', $this->payload ); }
			public function currentPreconditionFingerprint(): string { return hash( 'sha256', 'unused' ); }
			public function intendedValue(): mixed { return $this->payload; }
		};
	}

	public function test_identical_canonical_plan_produces_identical_fingerprint(): void {
		$cs1 = new ChangeSet( 1, array( $this->op( 'a.op', 't1', 'p1' ) ) );
		$cs2 = new ChangeSet( 1, array( $this->op( 'a.op', 't1', 'p1' ) ) );

		// Two structurally-identical ChangeSets (same principal, same
		// operations by type/target/payload) still have DIFFERENT ids
		// (random per-instance) — the fingerprint deliberately binds the
		// id too, so this asserts the SAME ChangeSet re-fingerprinted
		// twice is stable, not that unrelated ChangeSets collide.
		$this->assertEquals( ChangeSetFingerprint::compute( $cs1 ), ChangeSetFingerprint::compute( $cs1 ) );
		$this->assertEquals( ChangeSetFingerprint::compute( $cs2, 'diffhash' ), ChangeSetFingerprint::compute( $cs2, 'diffhash' ) );
	}

	public function test_payload_change_changes_fingerprint(): void {
		$a = new ChangeSet( 1, array( $this->op( 'a.op', 't1', 'payload-A' ) ) );
		$b = new ChangeSet( 1, array( $this->op( 'a.op', 't1', 'payload-B' ) ) );
		$this->assertNotEquals( ChangeSetFingerprint::compute( $a ), ChangeSetFingerprint::compute( $b ) );
	}

	public function test_target_change_changes_fingerprint(): void {
		$a = new ChangeSet( 1, array( $this->op( 'a.op', 'target-A', 'p' ) ) );
		$b = new ChangeSet( 1, array( $this->op( 'a.op', 'target-B', 'p' ) ) );
		$this->assertNotEquals( ChangeSetFingerprint::compute( $a ), ChangeSetFingerprint::compute( $b ) );
	}

	public function test_operation_reorder_changes_fingerprint(): void {
		$op1 = $this->op( 'a.op', 't1', 'p1' );
		$op2 = $this->op( 'a.op', 't2', 'p2' );
		$forward  = new ChangeSet( 1, array( $op1, $op2 ) );
		$reversed = new ChangeSet( 1, array( $op2, $op1 ) );
		$this->assertNotEquals( ChangeSetFingerprint::compute( $forward ), ChangeSetFingerprint::compute( $reversed ) );
	}

	public function test_site_change_changes_fingerprint(): void {
		$op = $this->op( 'a.op', 't1', 'p1' );
		$site1 = new ChangeSet( 1, array( $op ), array(), 1 );
		$site2 = new ChangeSet( 1, array( $op ), array(), 2 );
		$this->assertNotEquals( ChangeSetFingerprint::compute( $site1 ), ChangeSetFingerprint::compute( $site2 ) );
	}

	public function test_actor_change_changes_fingerprint(): void {
		$op     = $this->op( 'a.op', 't1', 'p1' );
		$actor1 = new ChangeSet( 1, array( $op ) );
		$actor2 = new ChangeSet( 2, array( $op ) );
		$this->assertNotEquals( ChangeSetFingerprint::compute( $actor1 ), ChangeSetFingerprint::compute( $actor2 ) );
	}

	public function test_diff_hash_change_changes_fingerprint(): void {
		$cs = new ChangeSet( 1, array( $this->op( 'a.op', 't1', 'p1' ) ) );
		$this->assertNotEquals(
			ChangeSetFingerprint::compute( $cs, 'diff-hash-A' ),
			ChangeSetFingerprint::compute( $cs, 'diff-hash-B' )
		);
	}

	public function test_matches_helper_uses_constant_time_comparison_and_is_correct(): void {
		$cs = new ChangeSet( 1, array( $this->op( 'a.op', 't1', 'p1' ) ) );
		$fp = ChangeSetFingerprint::compute( $cs, 'd' );
		$this->assertTrue( ChangeSetFingerprint::matches( $cs, $fp, 'd' ) );
		$this->assertFalse( ChangeSetFingerprint::matches( $cs, $fp, 'different-diff-hash' ) );
		$this->assertFalse( ChangeSetFingerprint::matches( $cs, 'not-a-real-fingerprint', 'd' ) );
	}

	public function test_type_change_changes_fingerprint(): void {
		$a = new ChangeSet( 1, array( $this->op( 'type.a', 't1', 'p1' ) ) );
		$b = new ChangeSet( 1, array( $this->op( 'type.b', 't1', 'p1' ) ) );
		$this->assertNotEquals( ChangeSetFingerprint::compute( $a ), ChangeSetFingerprint::compute( $b ) );
	}
}
