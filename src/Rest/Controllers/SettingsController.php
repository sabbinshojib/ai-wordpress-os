<?php
/**
 * Settings endpoints (admin only).
 *
 * @package AIOS\Rest\Controllers
 */

declare( strict_types=1 );

namespace AIOS\Rest\Controllers;

use AIOS\Audit\AuditLogger;
use AIOS\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsController extends AbstractController {

	public function register( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);

		register_rest_route(
			$namespace,
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);
	}

	/**
	 * GET /settings.
	 */
	public function get( /* WP_REST_Request $request */ ): WP_REST_Response {
		/** @var Settings $settings */
		$settings = $this->container->get( Settings::class );

		return $this->json(
			array(
				'settings'      => $settings->toArray(),
				'mode_presets'  => array(
					Settings::MODE_SAFE     => Settings::MODE_PRESETS[ Settings::MODE_SAFE ],
					Settings::MODE_BALANCED => Settings::MODE_PRESETS[ Settings::MODE_BALANCED ],
					Settings::MODE_ADVANCED => Settings::MODE_PRESETS[ Settings::MODE_ADVANCED ],
				),
				'mcp_endpoint'  => rest_url( AI_WP_OS_REST_NAMESPACE . '/mcp' ),
				'rest_base'     => rest_url( AI_WP_OS_REST_NAMESPACE ),
			)
		);
	}

	/**
	 * POST /settings.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->verifyNonce( $request ) ) {
			return $this->json( array( 'error' => array( 'code' => 'ai_os_nonce', 'message' => 'Nonce verification failed.' ) ), 403 );
		}

		$user = wp_get_current_user();
		if ( ! $user->exists() || ! $user->has_cap( 'manage_options' ) ) {
			return $this->json( array( 'error' => array( 'code' => 'ai_os_forbidden', 'message' => 'Administrator capability required.' ) ), 403 );
		}

		/** @var Settings $settings */
		$settings = $this->container->get( Settings::class );

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		// Security invariants: mode is validated by Settings::sanitize().
		$settings->update( $payload );

		// Audit the change (settings are security-relevant).
		/** @var AuditLogger $audit */
		$audit = $this->container->get( AuditLogger::class );
		$audit->log(
			array(
				'user'   => $user,
				'client' => 'rest',
				'tool'   => 'settings.update',
				'action' => 'settings.updated',
				'risk'   => 2,
				'status' => AuditLogger::STATUS_OK,
			)
		);

		// Context cache mode display may change.
		\AIOS\Context\ContextEngine::invalidate();

		return $this->json( array( 'settings' => $settings->toArray() ) );
	}
}
