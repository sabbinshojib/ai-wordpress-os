<?php
/**
 * Integration tests: admin-controlled ai_os_use / ai_os_approve
 * grant/revoke layer (Sprint 0.3A, Part F).
 *
 * Activator::grantDefaultCapabilities() (see CapabilityLifecycleTest)
 * only ever grants these two capabilities to the `administrator`
 * role, at activation. This suite covers the separate, conservative
 * mechanism a site owner uses AFTER activation to grant either
 * capability to one specific non-administrator user: AIOS\Security\
 * CapabilityManager and its REST surface, AIOS\Rest\Controllers\
 * CapabilitiesController.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Database\Repositories\AuditLogRepository;
use AIOS\Rest\Controllers\ApprovalsController;
use AIOS\Rest\Controllers\CapabilitiesController;
use AIOS\Security\CapabilityManager;
use AIOS\Support\StructuredError;
use AIOS\Tests\TestCase;
use WP_REST_Request;

final class CapabilityManagementTest extends TestCase {

	private CapabilityManager $manager;

	private AuditLogRepository $audit;

	private CapabilitiesController $controller;

	protected function setUp(): void {
		$this->resetPlugin();
		$container        = Plugin::instance()->container();
		$this->manager    = $container->get( CapabilityManager::class );
		$this->audit      = $container->get( AuditLogRepository::class );
		$this->controller = new CapabilitiesController( $container );
	}

	// ---------------------------------------------------- grant / revoke

	public function test_administrator_can_grant_and_revoke_ai_os_use(): void {
		// User 4 (the "anonymous" fixture id) starts with zero
		// capabilities at all, unlike user 3 whose fixture already
		// includes ai_os_use — this is the case that actually proves
		// the grant does something.
		$admin = $this->adminUser();
		$this->assertFalse( get_userdata( 4 )->has_cap( 'ai_os_use' ) );

		$result = $this->manager->grant( $admin, 4, 'ai_os_use' );
		$this->assertTrue( true === $result );
		$this->assertTrue( get_userdata( 4 )->has_cap( 'ai_os_use' ) );

		$result = $this->manager->revoke( $admin, 4, 'ai_os_use' );
		$this->assertTrue( true === $result );
		$this->assertFalse( get_userdata( 4 )->has_cap( 'ai_os_use' ) );
	}

	public function test_administrator_can_grant_and_revoke_ai_os_approve(): void {
		$admin = $this->adminUser();
		$this->assertFalse( get_userdata( 3 )->has_cap( 'ai_os_approve' ) );

		$this->manager->grant( $admin, 3, 'ai_os_approve' );
		$this->assertTrue( get_userdata( 3 )->has_cap( 'ai_os_approve' ) );

		$this->manager->revoke( $admin, 3, 'ai_os_approve' );
		$this->assertFalse( get_userdata( 3 )->has_cap( 'ai_os_approve' ) );
	}

	public function test_grant_is_idempotent_and_does_not_affect_other_capabilities(): void {
		$admin = $this->adminUser();
		$before = get_userdata( 3 );
		$this->assertTrue( $before->has_cap( 'edit_posts' ) );

		$this->manager->grant( $admin, 3, 'ai_os_use' );
		$this->manager->grant( $admin, 3, 'ai_os_use' ); // second grant must not error

		$after = get_userdata( 3 );
		$this->assertTrue( $after->has_cap( 'ai_os_use' ) );
		$this->assertTrue( $after->has_cap( 'edit_posts' ), 'unrelated capabilities must be untouched' );
		$this->assertFalse( $after->has_cap( 'ai_os_approve' ), 'granting one capability must not grant the other' );
	}

	public function test_grant_rejects_capabilities_outside_the_whitelist(): void {
		$admin  = $this->adminUser();
		$result = $this->manager->grant( $admin, 3, 'manage_options' );

		$this->assertInstanceOf( StructuredError::class, $result );
		$this->assertEquals( 'capabilities.unsupported_capability', $result->code() );
		$this->assertFalse( get_userdata( 3 )->has_cap( 'manage_options' ), 'the whitelist must be enforced, not just documented' );
	}

	public function test_grant_target_not_found(): void {
		$admin  = $this->adminUser();
		$result = $this->manager->grant( $admin, 999999, 'ai_os_use' );

		$this->assertInstanceOf( StructuredError::class, $result );
		$this->assertEquals( 'capabilities.user_not_found', $result->code() );
	}

	// ---------------------------------------------------- no self-escalation

	public function test_no_self_escalation_on_grant(): void {
		$admin  = $this->adminUser();
		$result = $this->manager->grant( $admin, (int) $admin->ID, 'ai_os_approve' );

		$this->assertInstanceOf( StructuredError::class, $result );
		$this->assertEquals( 'capabilities.self_escalation_denied', $result->code() );
	}

	public function test_no_self_escalation_on_revoke(): void {
		$admin  = $this->adminUser();
		$result = $this->manager->revoke( $admin, (int) $admin->ID, 'ai_os_approve' );

		$this->assertInstanceOf( StructuredError::class, $result );
		$this->assertEquals( 'capabilities.self_escalation_denied', $result->code() );
	}

	// ---------------------------------------------------- unauthorized callers

	public function test_unauthorized_user_cannot_grant_capabilities(): void {
		$contributor = $this->contributorUser(); // no manage_options
		$result      = $this->manager->grant( $contributor, 2, 'ai_os_approve' );

		$this->assertInstanceOf( StructuredError::class, $result );
		$this->assertEquals( 'capabilities.forbidden', $result->code() );
		$this->assertFalse( get_userdata( 2 )->has_cap( 'ai_os_approve' ) );
	}

	public function test_unauthorized_user_cannot_revoke_capabilities(): void {
		$admin = $this->adminUser();
		$this->manager->grant( $admin, 2, 'ai_os_approve' );

		$contributor = $this->contributorUser();
		$result      = $this->manager->revoke( $contributor, 2, 'ai_os_approve' );

		$this->assertInstanceOf( StructuredError::class, $result );
		$this->assertEquals( 'capabilities.forbidden', $result->code() );
		$this->assertTrue( get_userdata( 2 )->has_cap( 'ai_os_approve' ), 'unauthorized caller must not be able to revoke either' );
	}

	public function test_anonymous_user_cannot_manage_capabilities(): void {
		$anonymous = $this->anonymousUser();
		$result    = $this->manager->grant( $anonymous, 2, 'ai_os_use' );

		$this->assertInstanceOf( StructuredError::class, $result );
		$this->assertEquals( 'capabilities.forbidden', $result->code() );
	}

	// ---------------------------------------------------- approval gate consequence

	/**
	 * The actual, end-user-visible point of this whole layer: a
	 * non-admin user explicitly granted ai_os_approve passes the real
	 * approval permission gate (ApprovalsController::canApprove()),
	 * and an equivalent user who was never granted it does not.
	 */
	public function test_explicitly_granted_non_admin_approver_can_pass_approval_gate(): void {
		$admin = $this->adminUser();
		$this->manager->grant( $admin, 3, 'ai_os_approve' );

		$approvals = new ApprovalsController( Plugin::instance()->container() );

		$GLOBALS['__wp_shim']['current_user'] = get_userdata( 3 );
		$this->assertTrue( $approvals->canApprove(), 'user explicitly granted ai_os_approve must pass the approval gate' );
	}

	public function test_equivalent_ungranted_user_cannot_pass_approval_gate(): void {
		$approvals = new ApprovalsController( Plugin::instance()->container() );

		// user 2: editor-ish, has ai_os_use, was never granted ai_os_approve.
		$GLOBALS['__wp_shim']['current_user'] = get_userdata( 2 );
		$this->assertFalse( $approvals->canApprove(), 'a user with only ai_os_use must not pass the approval gate' );
	}

	// ---------------------------------------------------- audit

	public function test_grant_and_revoke_are_audited(): void {
		$admin = $this->adminUser();

		$this->manager->grant( $admin, 3, 'ai_os_approve' );
		$rows = $this->audit->chainRows( 100 );
		$grant_rows = array_values( array_filter( $rows, static fn( array $r ): bool => 'capabilities.grant' === $r['tool'] ) );
		$this->assertCount( 1, $grant_rows );
		$this->assertEquals( 1, (int) $grant_rows[0]['user_id'] );

		$this->manager->revoke( $admin, 3, 'ai_os_approve' );
		$rows = $this->audit->chainRows( 100 );
		$revoke_rows = array_values( array_filter( $rows, static fn( array $r ): bool => 'capabilities.revoke' === $r['tool'] ) );
		$this->assertCount( 1, $revoke_rows );
		$this->assertEquals( 1, (int) $revoke_rows[0]['user_id'] );
	}

	public function test_rejected_attempts_are_not_falsely_audited_as_successful_grants(): void {
		$contributor = $this->contributorUser();
		$this->manager->grant( $contributor, 2, 'ai_os_approve' ); // forbidden: not an admin

		$rows = $this->audit->chainRows( 100 );
		$grant_rows = array_filter( $rows, static fn( array $r ): bool => 'capabilities.grant' === $r['tool'] );
		$this->assertCount( 0, $grant_rows, 'a rejected grant attempt must not produce an audited grant row' );
	}

	// ---------------------------------------------------- state()

	public function test_state_reports_current_grants_for_both_capabilities(): void {
		// User 3's fixture already includes ai_os_use=true (mirrors
		// TestCase::contributorUser()); state() must reflect that
		// baseline accurately, not assume a blank slate.
		$admin = $this->adminUser();

		$state = $this->manager->state( 3 );
		$this->assertTrue( $state['ai_os_use'] );
		$this->assertFalse( $state['ai_os_approve'] );

		$this->manager->grant( $admin, 3, 'ai_os_approve' );

		$state = $this->manager->state( 3 );
		$this->assertTrue( $state['ai_os_use'] );
		$this->assertTrue( $state['ai_os_approve'] );
	}

	public function test_state_for_unknown_user_is_not_found(): void {
		$result = $this->manager->state( 999999 );
		$this->assertInstanceOf( StructuredError::class, $result );
		$this->assertEquals( 'capabilities.user_not_found', $result->code() );
	}

	// ---------------------------------------------------- multisite

	protected function tearDown(): void {
		$GLOBALS['__wp_shim']['multisite'] = false;
		$GLOBALS['__wp_shim']['sites']     = array( 1 );
	}

	public function test_capability_grants_are_isolated_per_site(): void {
		$GLOBALS['__wp_shim']['multisite']      = true;
		$GLOBALS['__wp_shim']['sites']          = array( 1, 2 );
		$GLOBALS['__wp_shim']['current_blog_id'] = 1;

		$admin = $this->adminUser();
		$this->manager->grant( $admin, 3, 'ai_os_approve' );
		$this->assertTrue( get_userdata( 3 )->has_cap( 'ai_os_approve' ), 'grant must apply on the site it was made on' );

		switch_to_blog( 2 );
		$this->assertFalse( get_userdata( 3 )->has_cap( 'ai_os_approve' ), 'grant on site 1 must not leak into site 2 (per-site capability storage)' );

		// Grant independently on site 2, confirm it does not affect site 1's state.
		$this->manager->grant( $this->adminUser(), 3, 'ai_os_approve' );
		$this->assertTrue( get_userdata( 3 )->has_cap( 'ai_os_approve' ) );
		restore_current_blog();

		$this->assertTrue( get_userdata( 3 )->has_cap( 'ai_os_approve' ), 'site 1 grant must still be intact after visiting site 2' );
	}

	// ---------------------------------------------------- REST surface

	private function nonceRequest( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( $params );
		$request->set_param( '__header_x-wp-nonce', wp_create_nonce( 'wp_rest' ) );
		return $request;
	}

	public function test_rest_grant_requires_nonce_for_cookie_auth(): void {
		$GLOBALS['__wp_shim']['current_user'] = $this->adminUser();
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_X_AI_OS_KEY'] );

		$request = new WP_REST_Request( array( 'user_id' => 3, 'capability' => 'ai_os_use' ) );
		$response = $this->controller->grant( $request );

		$this->assertEquals( 403, $response->status );
		$this->assertEquals( 'ai_os_nonce', $response->data['error']['code'] );
	}

	public function test_rest_grant_and_revoke_end_to_end(): void {
		$GLOBALS['__wp_shim']['current_user'] = $this->adminUser();
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_X_AI_OS_KEY'] );

		$grant_response = $this->controller->grant( $this->nonceRequest( array( 'user_id' => 3, 'capability' => 'ai_os_approve' ) ) );
		$this->assertEquals( 200, $grant_response->status );
		$this->assertTrue( $grant_response->data['ok'] );
		$this->assertTrue( get_userdata( 3 )->has_cap( 'ai_os_approve' ) );

		$state_response = $this->controller->state( $this->nonceRequest( array( 'user_id' => 3 ) ) );
		$this->assertTrue( $state_response->data['capabilities']['ai_os_approve'] );

		$revoke_response = $this->controller->revoke( $this->nonceRequest( array( 'user_id' => 3, 'capability' => 'ai_os_approve' ) ) );
		$this->assertEquals( 200, $revoke_response->status );
		$this->assertFalse( get_userdata( 3 )->has_cap( 'ai_os_approve' ) );
	}

	public function test_rest_grant_rejects_self_escalation_with_403(): void {
		$admin = $this->adminUser();
		$GLOBALS['__wp_shim']['current_user'] = $admin;
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_X_AI_OS_KEY'] );

		$response = $this->controller->grant( $this->nonceRequest( array( 'user_id' => (int) $admin->ID, 'capability' => 'ai_os_approve' ) ) );

		$this->assertEquals( 403, $response->status );
		$this->assertEquals( 'capabilities.self_escalation_denied', $response->data['error']['code'] );
	}

	public function test_rest_capabilities_endpoints_reject_non_admin_via_permission_callback(): void {
		$GLOBALS['__wp_shim']['current_user'] = $this->contributorUser();
		$this->assertFalse( $this->controller->canManage(), 'permission_callback must refuse a non-admin caller before the handler even runs' );
	}
}
