<?php
/**
 * Shared REST controller behavior.
 *
 * @package AIOS\Rest\Controllers
 */

declare( strict_types=1 );

namespace AIOS\Rest\Controllers;

use AIOS\Core\Container;
use AIOS\Security\PermissionEngine;
use WP_REST_Request;
use WP_REST_Response;

abstract class AbstractController {

	protected Container $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	abstract public function register( string $namespace ): void;

	/**
	 * Standard JSON response with CORS-friendly headers.
	 *
	 * @param array<string, mixed> $data
	 */
	protected function json( array $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'X-AI-OS-Version', AI_WP_OS_VERSION );
		return $response;
	}

	/**
	 * Whether the current user may read AI OS surfaces. Used as a
	 * permission callback for read endpoints.
	 */
	public function canRead(): bool {
		$user = wp_get_current_user();
		return $user->exists() && PermissionEngine::canUse( $user );
	}

	/**
	 * Permission callback requiring manage_options (settings, keys).
	 */
	public function canManage(): bool {
		$user = wp_get_current_user();
		return $user->exists() && $user->has_cap( 'manage_options' );
	}

	/**
	 * Permission callback for approval decisions.
	 */
	public function canApprove(): bool {
		$user = wp_get_current_user();
		return $user->exists()
			&& ( $user->has_cap( 'manage_options' ) || $user->has_cap( 'ai_os_approve' ) );
	}

	/**
	 * Cookie-based state-changing endpoints require the REST nonce
	 * (core handles this when X-WP-Nonce is present; application
	 * password / key auth is exempt by nature).
	 */
	protected function verifyNonce( WP_REST_Request $request ): bool {
		// Application passwords and API keys are not CSRF-exposed;
		// core REST already validates X-WP-Nonce for cookie auth via
		// rest_cookie_check_errors(). This check exists for
		// defense in depth on cookie-authenticated admin calls.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( null === $nonce || '' === $nonce ) {
			// No nonce: allowed only when the request is not
			// cookie-authenticated (Basic/key auth carries its own proof).
			return ! $this->isCookieAuthenticated();
		}
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	private function isCookieAuthenticated(): bool {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return false;
		}
		// If a logged-in cookie session is active AND no Authorization
		// header is present, this is cookie authentication.
		return empty( $_SERVER['PHP_AUTH_USER'] ) && empty( $_SERVER['HTTP_X_AI_OS_KEY'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Client label for audit rows.
	 */
	protected function clientLabel(): string {
		return 'rest';
	}
}
