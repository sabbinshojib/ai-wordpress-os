<?php
/**
 * AI OS capability grant/revoke endpoints (admin only).
 *
 * Conservative, admin-controlled layer over `ai_os_use` /
 * `ai_os_approve` — see AIOS\Security\CapabilityManager for the
 * business rules (whitelist-only, no self-escalation, audited).
 *
 * @package AIOS\Rest\Controllers
 */

declare( strict_types=1 );

namespace AIOS\Rest\Controllers;

use AIOS\Security\CapabilityManager;
use AIOS\Support\StructuredError;
use WP_REST_Request;
use WP_REST_Response;

final class CapabilitiesController extends AbstractController {

	public function register( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/capabilities/(?P<user_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'state' ),
				'permission_callback' => array( $this, 'canManage' ),
				'args'                => array(
					'user_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/capabilities/grant',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'grant' ),
				'permission_callback' => array( $this, 'canManage' ),
				'args'                => $this->mutationArgs(),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/capabilities/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => array( $this, 'canManage' ),
				'args'                => $this->mutationArgs(),
			)
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function mutationArgs(): array {
		return array(
			'user_id'    => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'capability' => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => CapabilityManager::GRANTABLE,
			),
		);
	}

	/**
	 * GET /capabilities/{user_id} — current grant state for both
	 * managed capabilities.
	 */
	public function state( WP_REST_Request $request ): WP_REST_Response {
		/** @var CapabilityManager $manager */
		$manager = $this->container->get( CapabilityManager::class );
		$result  = $manager->state( (int) $request->get_param( 'user_id' ) );

		if ( $result instanceof StructuredError ) {
			return $this->errorResponse( $result );
		}
		return $this->json( array( 'capabilities' => $result ) );
	}

	/**
	 * POST /capabilities/grant.
	 */
	public function grant( WP_REST_Request $request ): WP_REST_Response {
		return $this->mutate( $request, 'grant' );
	}

	/**
	 * POST /capabilities/revoke.
	 */
	public function revoke( WP_REST_Request $request ): WP_REST_Response {
		return $this->mutate( $request, 'revoke' );
	}

	private function mutate( WP_REST_Request $request, string $action ): WP_REST_Response {
		if ( ! $this->verifyNonce( $request ) ) {
			return $this->json(
				array(
					'error' => array(
						'code'    => 'ai_os_nonce',
						'message' => 'Nonce verification failed.',
					),
				),
				403
			);
		}

		$acting = wp_get_current_user();
		if ( ! $acting->exists() ) {
			return $this->json(
				array(
					'error' => array(
						'code'    => 'ai_os_unauthenticated',
						'message' => 'Authentication required.',
					),
				),
				401
			);
		}

		$target_user_id = (int) $request->get_param( 'user_id' );
		$capability     = (string) $request->get_param( 'capability' );

		/** @var CapabilityManager $manager */
		$manager = $this->container->get( CapabilityManager::class );
		$result  = 'grant' === $action
			? $manager->grant( $acting, $target_user_id, $capability )
			: $manager->revoke( $acting, $target_user_id, $capability );

		if ( $result instanceof StructuredError ) {
			return $this->errorResponse( $result );
		}

		return $this->json(
			array(
				'ok'         => true,
				'action'     => $action,
				'user_id'    => $target_user_id,
				'capability' => $capability,
			)
		);
	}

	private function errorResponse( StructuredError $error ): WP_REST_Response {
		$status = match ( $error->type() ) {
			StructuredError::TYPE_PERMISSION => 403,
			StructuredError::TYPE_NOT_FOUND  => 404,
			StructuredError::TYPE_VALIDATION => 422,
			default                          => 400,
		};
		return $this->json(
			array(
				'error' => array(
					'code'    => $error->code(),
					'message' => $error->message(),
				),
			),
			$status
		);
	}
}
