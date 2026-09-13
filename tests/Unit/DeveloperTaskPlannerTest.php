<?php
/**
 * Unit tests: DeveloperTaskPlanner and task request contracts.
 *
 * Verifies that high-level requests and internal task inputs are planned
 * deterministically with derived capabilities, accurate risk levels, and
 * proper approval thresholds.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Developer\Capability\DeveloperCapability;
use AIOS\Developer\Plan\DeveloperTaskPlanner;
use AIOS\Developer\Plan\DeveloperTaskRequest;
use AIOS\Developer\Plan\Internal\InternalTaskPlanInput;
use AIOS\Developer\Support\DeveloperTestIdentifier;
use AIOS\Mutation\OperationSpecification;
use AIOS\Mutation\Operations\FileDeleteOperation;
use AIOS\Mutation\Operations\FilePatchOperation;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Security\PermissionEngine;
use AIOS\Settings\Settings;
use AIOS\Tests\TestCase;

final class DeveloperTaskPlannerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->resetPlugin();
	}

	public function test_request_validation_rejects_empty_objective(): void {
		$threw = false;
		try {
			new DeveloperTaskRequest( '   ' );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'objective cannot be empty', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}

	public function test_request_validation_rejects_invalid_principal(): void {
		$threw = false;
		try {
			new DeveloperTaskRequest( 'Audit repository', array(), array(), 0 );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'principal_user_id must be greater than zero', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}

	public function test_request_serializes_and_deserializes(): void {
		$request = new DeveloperTaskRequest(
			'Audit repository',
			array( 'src/' ),
			array( 'no_vendor_changes' ),
			1,
			2,
			array( 'tool' => 'repo.inspect' )
		);

		$data = $request->toArray();
		$this->assertEquals( 'Audit repository', $data['objective'] );
		$this->assertEquals( array( 'src/' ), $data['scope'] );
		$this->assertEquals( array( 'no_vendor_changes' ), $data['constraints'] );
		$this->assertEquals( 1, $data['principal_user_id'] );
		$this->assertEquals( 2, $data['site_id'] );
		$this->assertEquals( 'repo.inspect', $data['metadata']['tool'] );

		$reconstituted = DeveloperTaskRequest::fromArray( $data );
		$this->assertEquals( $request->objective(), $reconstituted->objective() );
		$this->assertEquals( $request->scope(), $reconstituted->scope() );
		$this->assertEquals( $request->siteId(), $reconstituted->siteId() );
	}

	public function test_planner_creates_read_only_plan_from_high_level_request(): void {
		$request = new DeveloperTaskRequest(
			'Inspect codebase for PHP 8.3 compatibility',
			array( 'src/' ),
			array( 'read_only' ),
			1,
			1
		);

		$planner = new DeveloperTaskPlanner();
		$plan    = $planner->plan( $request );

		$this->assertStringContains( 'dtp-', $plan->taskId() );
		$this->assertEquals( 'Inspect codebase for PHP 8.3 compatibility', $plan->objective() );
		$this->assertEquals( array( 'src/' ), $plan->scope() );
		$this->assertCount( 0, $plan->operations() );
		$this->assertEquals( PermissionEngine::LEVEL_READ, $plan->riskLevel() );
		$this->assertFalse( $plan->requiresApproval() );

		$caps = $plan->requiredCapabilities();
		$this->assertTrue( in_array( DeveloperCapability::CREATE_PLAN, $caps, true ) );
		$this->assertTrue( in_array( DeveloperCapability::INSPECT_REPO, $caps, true ) );
	}

	public function test_planner_derives_patch_file_and_sensitive_risk_from_internal_input(): void {
		$request = new DeveloperTaskRequest(
			'Apply security patch to auth controller',
			array( 'src/Auth.php' ),
			array(),
			1
		);

		$op = new OperationSpecification(
			FilePatchOperation::TYPE,
			array(
				'path'    => 'src/Auth.php',
				'content' => '<?php // fixed auth',
			)
		);

		$input = new InternalTaskPlanInput(
			$request,
			array( $op ),
			array( DeveloperTestIdentifier::PHP82_NATIVE, DeveloperTestIdentifier::PHP83_NATIVE )
		);

		$planner = new DeveloperTaskPlanner();
		$plan    = $planner->planFromInternal( $input );

		$this->assertCount( 1, $plan->operations() );
		$this->assertEquals( PermissionEngine::LEVEL_SENSITIVE, $plan->riskLevel() );
		$this->assertTrue( $plan->requiresApproval() );

		$caps = $plan->requiredCapabilities();
		$this->assertTrue( in_array( DeveloperCapability::CREATE_PLAN, $caps, true ) );
		$this->assertTrue( in_array( DeveloperCapability::PATCH_FILE, $caps, true ) );
		$this->assertTrue( in_array( DeveloperCapability::RUN_TEST, $caps, true ) );

		$tests = $plan->testStrategies();
		$this->assertCount( 2, $tests );
		$this->assertTrue( in_array( DeveloperTestIdentifier::PHP82_NATIVE, $tests, true ) );
		$this->assertTrue( in_array( DeveloperTestIdentifier::PHP83_NATIVE, $tests, true ) );
	}

	public function test_planner_derives_destructive_risk_for_file_delete(): void {
		$request = new DeveloperTaskRequest(
			'Remove obsolete config',
			array( 'config.old.php' ),
			array(),
			1
		);

		$op = new OperationSpecification(
			FileDeleteOperation::TYPE,
			array(
				'path' => 'config.old.php',
			)
		);

		$input   = new InternalTaskPlanInput( $request, array( $op ) );
		$planner = new DeveloperTaskPlanner();
		$plan    = $planner->planFromInternal( $input );

		$this->assertEquals( PermissionEngine::LEVEL_DESTRUCTIVE, $plan->riskLevel() );
		$this->assertTrue( $plan->requiresApproval() );
	}

	public function test_internal_task_plan_input_rejects_empty_operations(): void {
		$request = new DeveloperTaskRequest( 'Do something' );

		$threw = false;
		try {
			new InternalTaskPlanInput( $request, array() );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'requires at least one OperationSpecification', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}

	public function test_internal_task_plan_input_rejects_invalid_test_strategies(): void {
		$request = new DeveloperTaskRequest( 'Do something' );
		$op      = new OperationSpecification( OptionUpdateOperation::TYPE, array( 'option' => 'foo', 'value' => 'bar' ) );

		$threw = false;
		try {
			new InternalTaskPlanInput( $request, array( $op ), array( 'illegal_cmd; rm -rf /' ) );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'Invalid or unapproved test strategy', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}
}
