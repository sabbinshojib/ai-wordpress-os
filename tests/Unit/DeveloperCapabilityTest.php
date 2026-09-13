<?php
/**
 * Unit tests: DeveloperCapability and DeveloperPolicy.
 *
 * Verifies that developer capabilities form a strict, closed whitelist
 * without wildcards, shell, SQL, or eval capabilities, and that DeveloperPolicy
 * integrates seamlessly with PermissionEngine and WordPress capabilities.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Developer\Capability\DeveloperCapability;
use AIOS\Developer\Capability\DeveloperPolicy;
use AIOS\Security\PermissionEngine;
use AIOS\Settings\Settings;
use AIOS\Tests\TestCase;
use WP_User;

final class DeveloperCapabilityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->resetPlugin();
	}

	public function test_all_capabilities_are_supported(): void {
		$this->assertCount( 10, DeveloperCapability::ALL );
		foreach ( DeveloperCapability::ALL as $capability ) {
			$this->assertTrue( DeveloperCapability::isSupported( $capability ), "Capability {$capability} must be supported" );
			DeveloperCapability::assertSupported( $capability );
			$level = DeveloperCapability::levelFor( $capability );
			$this->assertGreaterThan( -1.0, (float) $level );
		}
	}

	public function test_wildcards_and_shell_capabilities_are_strictly_rejected(): void {
		$dangerous = array(
			'developer.*',
			'*',
			'developer.shell.exec',
			'developer.exec',
			'developer.eval.code',
			'developer.sql.query',
			'arbitrary.command',
			'developer.patch_file', // must match exact string with dot
		);

		foreach ( $dangerous as $bad ) {
			$this->assertFalse( DeveloperCapability::isSupported( $bad ), "Dangerous capability {$bad} must not be supported" );
			$threw = false;
			try {
				DeveloperCapability::assertSupported( $bad );
			} catch ( \InvalidArgumentException ) {
				$threw = true;
			}
			$this->assertTrue( $threw, "DeveloperCapability::assertSupported must throw for {$bad}" );
		}
	}

	public function test_level_mappings_are_correct(): void {
		$this->assertEquals( PermissionEngine::LEVEL_READ, DeveloperCapability::levelFor( DeveloperCapability::INSPECT_REPO ) );
		$this->assertEquals( PermissionEngine::LEVEL_READ, DeveloperCapability::levelFor( DeveloperCapability::CREATE_PLAN ) );
		$this->assertEquals( PermissionEngine::LEVEL_READ, DeveloperCapability::levelFor( DeveloperCapability::GENERATE_DIFF ) );
		$this->assertEquals( PermissionEngine::LEVEL_SAFE_WRITE, DeveloperCapability::levelFor( DeveloperCapability::RUN_TEST ) );
		$this->assertEquals( PermissionEngine::LEVEL_READ, DeveloperCapability::levelFor( DeveloperCapability::DIAGNOSE_FAILURE ) );
		$this->assertEquals( PermissionEngine::LEVEL_READ, DeveloperCapability::levelFor( DeveloperCapability::PROPOSE_REPAIR ) );
		$this->assertEquals( PermissionEngine::LEVEL_READ, DeveloperCapability::levelFor( DeveloperCapability::INSPECT_GIT ) );
		$this->assertEquals( PermissionEngine::LEVEL_SENSITIVE, DeveloperCapability::levelFor( DeveloperCapability::PATCH_FILE ) );
		$this->assertEquals( PermissionEngine::LEVEL_SENSITIVE, DeveloperCapability::levelFor( DeveloperCapability::COMMIT_GIT ) );
		$this->assertEquals( PermissionEngine::LEVEL_DEPLOYMENT, DeveloperCapability::levelFor( DeveloperCapability::PUSH_GIT ) );
	}

	public function test_policy_rejects_unauthenticated_or_nonexistent_user(): void {
		$settings = new Settings();
		$engine   = new PermissionEngine( $settings );
		$policy   = new DeveloperPolicy( $engine );

		$anon = new WP_User( 0 ); // doesn't exist
		$this->assertFalse( $policy->canExecuteDeveloperAction( $anon, DeveloperCapability::CREATE_PLAN ) );
		$this->assertFalse( $policy->canApproveDeveloperPlan( $anon, PermissionEngine::LEVEL_SENSITIVE ) );
	}

	public function test_policy_requires_ai_os_use_capability(): void {
		$settings = new Settings();
		$engine   = new PermissionEngine( $settings );
		$policy   = new DeveloperPolicy( $engine );

		$user_without_cap = new WP_User( 10, array( 'read' => true ) );
		$this->assertFalse( $policy->canExecuteDeveloperAction( $user_without_cap, DeveloperCapability::CREATE_PLAN ) );
		$this->assertStringContains( 'ai_os_use', $policy->denialReason( $user_without_cap, DeveloperCapability::CREATE_PLAN ) );
	}

	public function test_policy_allows_admin_to_execute_read_and_sensitive_actions(): void {
		$settings = new Settings();
		$engine   = new PermissionEngine( $settings );
		$policy   = new DeveloperPolicy( $engine );

		$admin = $this->adminUser();
		// In default safe mode, read and safe-write (level 0 & 1) are auto-executable.
		$this->assertTrue( $policy->canExecuteDeveloperAction( $admin, DeveloperCapability::CREATE_PLAN ) );
		$this->assertTrue( $policy->canExecuteDeveloperAction( $admin, DeveloperCapability::INSPECT_REPO ) );
		$this->assertTrue( $policy->canExecuteDeveloperAction( $admin, DeveloperCapability::RUN_TEST ) );

		// In safe mode, level 2 (PATCH_FILE) requires approval so auto-exec is false, but admin is capable!
		$this->assertFalse( $policy->canExecuteDeveloperAction( $admin, DeveloperCapability::PATCH_FILE ) );
		$this->assertTrue( $policy->isCapable( $admin, DeveloperCapability::PATCH_FILE ) );

		// In balanced mode, level 2 is auto-executable.
		update_option( Settings::OPTION_KEY, array( 'mode' => Settings::MODE_BALANCED ) );
		$balanced_settings = new Settings();
		$balanced_engine   = new PermissionEngine( $balanced_settings );
		$balanced_policy   = new DeveloperPolicy( $balanced_engine );
		$this->assertTrue( $balanced_policy->canExecuteDeveloperAction( $admin, DeveloperCapability::PATCH_FILE ) );
	}

	public function test_policy_approval_requires_ai_os_approve_or_manage_options(): void {
		$settings = new Settings();
		$engine   = new PermissionEngine( $settings );
		$policy   = new DeveloperPolicy( $engine );

		$editor = $this->editorUser(); // has ai_os_use, but NOT ai_os_approve and NOT manage_options
		$this->assertFalse( $policy->canApproveDeveloperPlan( $editor, PermissionEngine::LEVEL_SENSITIVE ) );

		$approver = new WP_User( 11, array(
			'manage_options' => true,
			'ai_os_approve'  => true,
		) );
		$this->assertTrue( $policy->canApproveDeveloperPlan( $approver, PermissionEngine::LEVEL_SENSITIVE ) );
	}
}
