<?php
/**
 * Regression coverage for SEC-M3 (Sprint 0.3, T-011): `/tools/execute`
 * is a mutating REST endpoint and must carry the same defense-in-depth
 * nonce check every other mutating AI OS endpoint already enforces
 * (ApprovalsController, SettingsController, KeysController) — core's
 * own `rest_cookie_check_errors()` nonce enforcement is the primary
 * defense; this is the belt-and-suspenders layer inside the plugin.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Rest\Controllers\ToolsController;
use AIOS\Tests\TestCase;
use WP_REST_Request;

final class ToolsControllerNonceTest extends TestCase {

	private ToolsController $controller;

	/** @var string[] $_SERVER keys this suite may set, cleaned up after each test. */
	private const TOUCHED_SERVER_KEYS = array( 'PHP_AUTH_USER', 'HTTP_X_AI_OS_KEY' );

	protected function setUp(): void {
		$this->resetPlugin();
		$this->controller = new ToolsController( Plugin::instance()->container() );
	}

	protected function tearDown(): void {
		foreach ( self::TOUCHED_SERVER_KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
	}

	private function executeRequest( ?string $nonce = null ): WP_REST_Request {
		$request = new WP_REST_Request();
		$request->set_param( 'tool', 'site.get_info' );
		$request->set_param( 'arguments', array() );
		if ( null !== $nonce ) {
			$request->set_param( '__header_x-wp-nonce', $nonce );
		}
		return $request;
	}

	private function asCookieAuthenticatedAdmin(): void {
		$GLOBALS['__wp_shim']['current_user'] = $this->adminUser();
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['HTTP_X_AI_OS_KEY'] );
	}

	// ------------------------------------------------------------ cookie auth requires a nonce

	public function test_cookie_authenticated_execute_without_a_nonce_is_rejected(): void {
		$this->asCookieAuthenticatedAdmin();

		$response = $this->controller->execute( $this->executeRequest() );

		$this->assertEquals( 403, $response->status );
		$this->assertEquals( 'ai_os_nonce', $response->data['error']['code'] );
	}

	public function test_cookie_authenticated_execute_with_an_invalid_nonce_is_rejected(): void {
		$this->asCookieAuthenticatedAdmin();

		$response = $this->controller->execute( $this->executeRequest( 'not-a-real-nonce' ) );

		$this->assertEquals( 403, $response->status );
		$this->assertEquals( 'ai_os_nonce', $response->data['error']['code'] );
	}

	public function test_cookie_authenticated_execute_with_a_valid_nonce_succeeds(): void {
		$this->asCookieAuthenticatedAdmin();
		$nonce = wp_create_nonce( 'wp_rest' );

		$response = $this->controller->execute( $this->executeRequest( $nonce ) );

		$this->assertEquals( 200, $response->status );
		$this->assertTrue( $response->data['ok'] );
	}

	// ------------------------------------------------------------ non-cookie auth is exempt (unchanged behavior)

	public function test_application_password_authenticated_execute_needs_no_nonce(): void {
		$GLOBALS['__wp_shim']['current_user'] = $this->adminUser();
		$_SERVER['PHP_AUTH_USER']             = 'admin';

		$response = $this->controller->execute( $this->executeRequest() );

		$this->assertEquals( 200, $response->status );
		$this->assertTrue( $response->data['ok'] );
	}

	public function test_api_key_authenticated_execute_needs_no_nonce(): void {
		$GLOBALS['__wp_shim']['current_user'] = null; // No cookie session at all.

		$manager = Plugin::instance()->container()->get( \AIOS\Security\ApiKeyManager::class );
		$issued  = $manager->issue( array( 'label' => 'nonce-regression-key', 'user_id' => 1, 'max_level' => 1 ) );
		$_SERVER['HTTP_X_AI_OS_KEY'] = $issued['key'];

		// resolveUser() equivalent for the REST controller: ToolsController
		// itself reads wp_get_current_user(), so the API-key principal must
		// also be reflected there for this endpoint (core REST resolves
		// this before dispatch in real WordPress).
		$GLOBALS['__wp_shim']['current_user'] = $this->adminUser();

		$response = $this->controller->execute( $this->executeRequest() );

		$this->assertEquals( 200, $response->status );
		$this->assertTrue( $response->data['ok'] );
	}

	// ------------------------------------------------------------ unauthenticated behavior is unchanged

	public function test_unauthenticated_execute_is_still_rejected(): void {
		$GLOBALS['__wp_shim']['current_user'] = null;

		$response = $this->controller->execute( $this->executeRequest() );

		$this->assertEquals( 401, $response->status );
		$this->assertEquals( 'ai_os_unauthenticated', $response->data['error']['code'] );
	}
}
