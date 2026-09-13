<?php
/**
 * Unit tests: DeveloperPlanChangeSetAdapter.
 *
 * Verifies that the adapter cleanly bridges the data-oriented DeveloperTaskPlan
 * to an executable ChangeSet using TypedChangeSetBuilder, preserving metadata,
 * operations, principal, and site context.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Developer\Capability\DeveloperCapability;
use AIOS\Developer\Plan\DeveloperPlanChangeSetAdapter;
use AIOS\Developer\Plan\DeveloperTaskPlan;
use AIOS\Developer\Support\DeveloperTestIdentifier;
use AIOS\Mutation\Operations\FileCreateOperation;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Security\PathGuard;
use AIOS\Security\PermissionEngine;
use AIOS\Tests\TestCase;

final class DeveloperPlanChangeSetAdapterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->resetPlugin();
	}

	public function test_adapter_converts_plan_to_changeset(): void {
		$plan = new DeveloperTaskPlan(
			'dtp-test1234',
			'Configure AI settings',
			array( 'wp-config.php' ),
			array(
				array(
					'type' => OptionUpdateOperation::TYPE,
					'spec' => array(
						'option' => 'custom_setting',
						'value'  => 'enabled',
					),
				),
			),
			array( DeveloperTestIdentifier::PHP82_NATIVE ),
			array( DeveloperCapability::CREATE_PLAN, DeveloperCapability::RUN_TEST ),
			PermissionEngine::LEVEL_SAFE_WRITE,
			false,
			42,
			3,
			1700000000,
			array( 'reason' => 'system optimization' )
		);

		$adapter   = new DeveloperPlanChangeSetAdapter();
		$changeset = $adapter->toChangeSet( $plan );

		$this->assertEquals( 42, $changeset->principalUserId() );
		$this->assertEquals( 3, $changeset->siteId() );
		$this->assertCount( 1, $changeset->operations() );
		$this->assertEquals( OptionUpdateOperation::TYPE, $changeset->operations()[0]->type() );
		$this->assertEquals( 'custom_setting', $changeset->operations()[0]->target() );

		// Metadata bridge checks.
		$meta = $changeset->metadata();
		$this->assertEquals( 'system optimization', $meta['reason'] );
		$this->assertEquals( 'dtp-test1234', $meta['developer_task_id'] );
		$this->assertEquals( $plan->fingerprint(), $meta['developer_task_fp'] );
		$this->assertEquals( 'Configure AI settings', $meta['developer_objective'] );
	}

	public function test_adapter_rejects_plan_with_no_operations(): void {
		$plan = new DeveloperTaskPlan(
			'dtp-empty123',
			'Inspection only',
			array( 'src/' ),
			array(), // zero operations
			array(),
			array( DeveloperCapability::CREATE_PLAN, DeveloperCapability::INSPECT_REPO ),
			PermissionEngine::LEVEL_READ,
			false,
			1,
			null,
			1700000000
		);

		$adapter = new DeveloperPlanChangeSetAdapter();

		$threw = false;
		try {
			$adapter->toChangeSet( $plan );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
			$this->assertStringContains( 'has no operations', $e->getMessage() );
		}
		$this->assertTrue( $threw );
	}

	public function test_adapter_works_with_path_guard_for_file_operations(): void {
		$temp = rtrim( sys_get_temp_dir(), '/\\' ) . '/aios-dev-adapter-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $temp, 0777, true );

		try {
			$path_guard = new PathGuard( $temp );
			$plan       = new DeveloperTaskPlan(
				'dtp-file-1234',
				'Create readme',
				array( 'README.txt' ),
				array(
					array(
						'type' => FileCreateOperation::TYPE,
						'spec' => array(
							'path'    => 'README.txt',
							'content' => 'Hello Developer Engine',
						),
					),
				),
				array( DeveloperTestIdentifier::PHP82_NATIVE ),
				array( DeveloperCapability::CREATE_PLAN, DeveloperCapability::PATCH_FILE, DeveloperCapability::RUN_TEST ),
				PermissionEngine::LEVEL_SENSITIVE,
				true,
				1,
				null,
				1700000000
			);

			$adapter   = new DeveloperPlanChangeSetAdapter();
			$changeset = $adapter->toChangeSet( $plan, $path_guard );

			$this->assertCount( 1, $changeset->operations() );
			$this->assertEquals( FileCreateOperation::TYPE, $changeset->operations()[0]->type() );
			$this->assertEquals( 'README.txt', $changeset->operations()[0]->target() );
			$this->assertEquals( PermissionEngine::LEVEL_SENSITIVE, $changeset->riskLevel() );
		} finally {
			@unlink( $temp . '/README.txt' );
			@rmdir( $temp );
		}
	}
}
