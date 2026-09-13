<?php
/**
 * Unit tests: DeveloperTaskPlan immutable value object.
 *
 * Verifies deterministic content fingerprinting, JSON-safety, operation order
 * sensitivity, associative map key canonicalization, and fail-closed tampering
 * detection during deserialization.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Developer\Capability\DeveloperCapability;
use AIOS\Developer\Plan\DeveloperTaskPlan;
use AIOS\Developer\Support\DeveloperTestIdentifier;
use AIOS\Security\PermissionEngine;
use AIOS\Tests\TestCase;

final class DeveloperTaskPlanTest extends TestCase {

	/**
	 * Create a valid baseline task plan for testing.
	 *
	 * @param array<string, mixed> $overrides Property overrides.
	 * @return DeveloperTaskPlan
	 */
	private function makePlan( array $overrides = array() ): DeveloperTaskPlan {
		$defaults = array(
			'task_id'               => 'dtp-abc12345',
			'objective'             => 'Refactor database query optimization',
			'scope'                 => array( 'src/Database/Query.php' ),
			'operations'            => array(
				array(
					'type' => 'file.patch',
					'spec' => array(
						'path'    => 'src/Database/Query.php',
						'content' => '<?php // patched content',
					),
				),
			),
			'test_strategies'       => array( DeveloperTestIdentifier::PHP82_NATIVE ),
			'required_capabilities' => array(
				DeveloperCapability::CREATE_PLAN,
				DeveloperCapability::INSPECT_REPO,
				DeveloperCapability::PATCH_FILE,
				DeveloperCapability::RUN_TEST,
			),
			'risk_level'            => PermissionEngine::LEVEL_SENSITIVE,
			'requires_approval'     => true,
			'principal_user_id'     => 1,
			'site_id'               => 1,
			'created_at'            => 1700000000,
			'metadata'              => array( 'author' => 'DeveloperBot', 'priority' => 'high' ),
		);

		$props = array_merge( $defaults, $overrides );

		return new DeveloperTaskPlan(
			$props['task_id'],
			$props['objective'],
			$props['scope'],
			$props['operations'],
			$props['test_strategies'],
			$props['required_capabilities'],
			$props['risk_level'],
			$props['requires_approval'],
			$props['principal_user_id'],
			$props['site_id'],
			$props['created_at'],
			$props['metadata']
		);
	}

	public function test_getters_return_expected_values(): void {
		$plan = $this->makePlan();

		$this->assertEquals( 'dtp-abc12345', $plan->taskId() );
		$this->assertEquals( 'Refactor database query optimization', $plan->objective() );
		$this->assertEquals( array( 'src/Database/Query.php' ), $plan->scope() );
		$this->assertCount( 1, $plan->operations() );
		$this->assertEquals( array( DeveloperTestIdentifier::PHP82_NATIVE ), $plan->testStrategies() );
		$this->assertEquals( PermissionEngine::LEVEL_SENSITIVE, $plan->riskLevel() );
		$this->assertTrue( $plan->requiresApproval() );
		$this->assertEquals( 1, $plan->principalUserId() );
		$this->assertEquals( 1, $plan->siteId() );
		$this->assertEquals( 1700000000, $plan->createdAt() );
		$this->assertEquals( 'DeveloperBot', $plan->metadata()['author'] );
	}

	public function test_fingerprint_is_deterministic_and_content_addressed(): void {
		$plan1 = $this->makePlan();
		$plan2 = $this->makePlan();

		$this->assertEquals( $plan1->fingerprint(), $plan2->fingerprint() );
		$this->assertEquals( 64, strlen( $plan1->fingerprint() ) );

		// Changing task_id (instance identity) or created_at does NOT alter content fingerprint.
		$plan_diff_instance = $this->makePlan( array(
			'task_id'    => 'dtp-99999999',
			'created_at' => 1799999999,
		) );
		$this->assertEquals( $plan1->fingerprint(), $plan_diff_instance->fingerprint() );
	}

	public function test_swapping_associative_map_keys_preserves_fingerprint(): void {
		$plan1 = $this->makePlan( array(
			'metadata' => array( 'alpha' => '1', 'beta' => '2' ),
		) );
		$plan2 = $this->makePlan( array(
			'metadata' => array( 'beta' => '2', 'alpha' => '1' ),
		) );

		$this->assertEquals( $plan1->fingerprint(), $plan2->fingerprint() );
	}

	public function test_swapping_operation_sequence_changes_fingerprint(): void {
		$op_a = array(
			'type' => 'file.patch',
			'spec' => array( 'path' => 'file_a.php', 'content' => 'A' ),
		);
		$op_b = array(
			'type' => 'file.patch',
			'spec' => array( 'path' => 'file_b.php', 'content' => 'B' ),
		);

		$plan_a_first = $this->makePlan( array( 'operations' => array( $op_a, $op_b ) ) );
		$plan_b_first = $this->makePlan( array( 'operations' => array( $op_b, $op_a ) ) );

		$this->assertNotEquals( $plan_a_first->fingerprint(), $plan_b_first->fingerprint() );
	}

	public function test_to_array_and_from_array_round_trip(): void {
		$plan = $this->makePlan();
		$data = $plan->toArray();

		$this->assertArrayHasKey( 'task_id', $data );
		$this->assertArrayHasKey( 'fingerprint', $data );
		$this->assertEquals( $plan->fingerprint(), $data['fingerprint'] );

		$reconstituted = DeveloperTaskPlan::fromArray( $data );
		$this->assertEquals( $plan->taskId(), $reconstituted->taskId() );
		$this->assertEquals( $plan->fingerprint(), $reconstituted->fingerprint() );
		$this->assertEquals( $plan->objective(), $reconstituted->objective() );
		$this->assertEquals( $plan->riskLevel(), $reconstituted->riskLevel() );
	}

	public function test_from_array_rejects_tampered_risk_level(): void {
		$plan = $this->makePlan();
		$data = $plan->toArray();

		// Downgrade sensitive risk level 2 to safe write 1.
		$data['risk_level'] = PermissionEngine::LEVEL_SAFE_WRITE;

		$threw = false;
		try {
			DeveloperTaskPlan::fromArray( $data );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'downgraded risk_level', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}

	public function test_from_array_rejects_tampered_requires_approval(): void {
		$plan = $this->makePlan();
		$data = $plan->toArray();

		// Illegally declare requires_approval = false on a sensitive plan.
		$data['requires_approval'] = false;

		$threw = false;
		try {
			DeveloperTaskPlan::fromArray( $data );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'Tampered requires_approval', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}

	public function test_from_array_rejects_missing_required_capability(): void {
		$plan = $this->makePlan();
		$data = $plan->toArray();

		// Remove PATCH_FILE capability while keeping file.patch operation.
		$data['required_capabilities'] = array( DeveloperCapability::CREATE_PLAN );

		$threw = false;
		try {
			DeveloperTaskPlan::fromArray( $data );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'insufficient required_capabilities', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}

	public function test_from_array_rejects_tampered_fingerprint(): void {
		$plan = $this->makePlan();
		$data = $plan->toArray();

		// Fake fingerprint string.
		$data['fingerprint'] = hash( 'sha256', 'tampered_content' );

		$threw = false;
		try {
			DeveloperTaskPlan::fromArray( $data );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'Plan fingerprint mismatch', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}

	public function test_from_array_rejects_unsupported_operation_type(): void {
		$plan = $this->makePlan();
		$data = $plan->toArray();

		$data['operations'] = array(
			array(
				'type' => 'malicious.shell_exec',
				'spec' => array( 'cmd' => 'rm -rf' ),
			),
		);

		$threw = false;
		try {
			DeveloperTaskPlan::fromArray( $data );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'not in the supported whitelist', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}

	public function test_rejects_non_json_safe_metadata(): void {
		$threw = false;
		try {
			$this->makePlan( array(
				'metadata' => array( 'closure' => static fn() => 'nope' ),
			) );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'unsupported type', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}

	public function test_rejects_unapproved_test_strategy(): void {
		$threw = false;
		try {
			$this->makePlan( array(
				'test_strategies' => array( 'bash -c "echo hacked"' ),
			) );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'Invalid or unapproved test strategy', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}
}
