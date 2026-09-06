<?php
/**
 * Unit tests: PermissionEngine (spec §7/§49).
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Security\PermissionEngine;
use AIOS\Settings\Settings;
use AIOS\Tests\TestCase;

final class PermissionEngineTest extends TestCase {

	private function engine( array $settings = array(), ?array $grants = null ): PermissionEngine {
		$settings_object = new Settings();
		$settings_object->update( array_merge( array( 'mode' => 'safe' ), $settings ) );
		return new PermissionEngine( $settings_object, $grants );
	}

	public function test_level_names_exist(): void {
		$this->assertCount( 5, PermissionEngine::LEVEL_NAMES );
		$this->assertEquals( 'read', PermissionEngine::LEVEL_NAMES[0] );
		$this->assertEquals( 'deployment', PermissionEngine::LEVEL_NAMES[4] );
	}

	public function test_safe_mode_ceiling_for_admin(): void {
		$engine = $this->engine();
		$this->assertEquals( 1, $engine->ceilingFor( $this->adminUser() ) );
	}

	public function test_balanced_mode_ceiling(): void {
		$engine = $this->engine( array( 'mode' => 'balanced' ) );
		$this->assertEquals( 2, $engine->ceilingFor( $this->adminUser() ) );
	}

	public function test_advanced_mode_ceiling(): void {
		$engine = $this->engine( array( 'mode' => 'advanced' ) );
		$this->assertEquals( 3, $engine->ceilingFor( $this->adminUser() ) );
	}

	public function test_editor_cannot_exceed_level_1_even_in_advanced_mode(): void {
		$engine = $this->engine( array( 'mode' => 'advanced' ) );
		// Editor lacks edit_others_posts → caps ceiling is 1.
		$this->assertEquals( 1, $engine->ceilingFor( $this->editorUser() ) );
	}

	public function test_anonymous_has_no_access(): void {
		$engine = $this->engine();
		$this->assertEquals( -1, $engine->ceilingFor( $this->anonymousUser() ) );
		$this->assertFalse( $engine->can( $this->anonymousUser(), 0 ) );
	}

	public function test_can_uses_min_of_mode_and_caps(): void {
		$safe = $this->engine();
		$this->assertTrue( $safe->can( $this->adminUser(), 0 ) );
		$this->assertTrue( $safe->can( $this->adminUser(), 1 ) );
		$this->assertFalse( $safe->can( $this->adminUser(), 2 ), 'level 2 blocked in safe mode' );

		$advanced = $this->engine( array( 'mode' => 'advanced' ) );
		$this->assertTrue( $advanced->can( $this->adminUser(), 3 ) );
		$this->assertFalse( $advanced->can( $this->adminUser(), 4 ), 'level 4 never auto-allowed' );
	}

	public function test_grants_only_lower_ceilings(): void {
		// Admin ceiling in advanced mode is 3; a grant of 1 must lower it.
		$engine = $this->engine( array( 'mode' => 'advanced' ), array( '1' => 1 ) );
		$this->assertEquals( 1, $engine->ceilingFor( $this->adminUser() ) );

		// A grant of 4 must NOT raise an editor's cap ceiling of 1.
		$engine = $this->engine( array( 'mode' => 'advanced' ), array( '2' => 4 ) );
		$this->assertEquals( 1, $engine->ceilingFor( $this->editorUser() ), 'grants can never raise a ceiling' );
	}

	public function test_approval_thresholds_by_mode(): void {
		$this->assertTrue( $this->engine( array( 'mode' => 'safe' ) )->requiresApproval( 2 ) );
		$this->assertFalse( $this->engine( array( 'mode' => 'safe' ) )->requiresApproval( 1 ) );
		$this->assertTrue( $this->engine( array( 'mode' => 'balanced' ) )->requiresApproval( 3 ) );
		$this->assertFalse( $this->engine( array( 'mode' => 'balanced' ) )->requiresApproval( 2 ) );
		$this->assertTrue( $this->engine( array( 'mode' => 'advanced' ) )->requiresApproval( 4 ) );
		$this->assertFalse( $this->engine( array( 'mode' => 'advanced' ) )->requiresApproval( 3 ) );
	}

	public function test_denied_error_shape(): void {
		$engine = $this->engine();
		$error  = $engine->denied( $this->adminUser(), 'content.delete_post', 2 );
		$shape  = $error->toArray();
		$this->assertEquals( 'permission', $shape['type'] );
		$this->assertEquals( 'permission.level_denied', $shape['code'] );
		$this->assertStringContains( 'level 2', $shape['message'] );
	}

	public function test_canuse_requires_relevant_caps(): void {
		$this->assertTrue( PermissionEngine::canUse( $this->adminUser() ) );
		$this->assertTrue( PermissionEngine::canUse( $this->editorUser() ) );
		$this->assertFalse( PermissionEngine::canUse( $this->anonymousUser() ) );
	}

	public function test_describe_levels(): void {
		$levels = $this->engine( array( 'mode' => 'safe' ) )->describeLevels();
		$this->assertCount( 5, $levels );
		$this->assertEquals( 'not_required', $levels[1]['approval'] );
		$this->assertEquals( 'required', $levels[2]['approval'] );
	}
}
