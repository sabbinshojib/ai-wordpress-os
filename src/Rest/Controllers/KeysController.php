<?php
/**
 * API key lifecycle endpoints (admin only).
 *
 * @package AIOS\Rest\Controllers
 */

declare( strict_types=1 );

namespace AIOS\Rest\Controllers;

use AIOS\Audit\AuditLogger;
use AIOS\Security\ApiKeyManager;
use WP_REST_Request;
use WP_REST_Response;

final class KeysController extends AbstractController {

	public function register( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/keys',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);

		register_rest_route(
			$namespace,
			'/keys',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'canManage' ),
				'args'                => array(
					'label'      => array( 'type' => 'string', 'required' => true, 'maxLength' => 190, 'sanitize_callback' => 'sanitize_text_field' ),
					'user_id'    => array( 'type' => 'integer', 'required' => true, 'minimum' => 1, 'sanitize_callback' => 'absint' ),
					'max_level'  => array( 'type' => 'integer', 'default' => 1, 'minimum' => 0, 'maximum' => 4 ),
					'expires_at' => array( 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/keys/(?P<id>\d+)/revoke',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => array( $this, 'canManage' ),
				'args'                => array( 'id' => array( 'type' => 'integer', 'required' => true ) ),
			)
		);
	}

	/**
	 * GET /keys.
	 */
	public function list( /* WP_REST_Request $request */ ): WP_REST_Response {
		/** @var ApiKeyManager $manager */
		$manager = $this->container->get( ApiKeyManager::class );
		return $this->json( array( 'keys' => $manager->list() ) );
	}

	/**
	 * POST /keys — issue a key. The raw secret is shown exactly once.
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->verifyNonce( $request ) ) {
			return $this->json( array( 'error' => array( 'code' => 'ai_os_nonce', 'message' => 'Nonce verification failed.' ) ), 403 );
		}

		$acting = wp_get_current_user();
		if ( ! $acting->exists() || ! $acting->has_cap( 'manage_options' ) ) {
			return $this->json( array( 'error' => array( 'code' => 'ai_os_forbidden', 'message' => 'Administrator capability required.' ) ), 403 );
		}

		$user_id = (int) $request->get_param( 'user_id' );
		$target  = get_userdata( $user_id );
		if ( ! $target instanceof \WP_User || ! $target->exists() ) {
			return $this->json( array( 'error' => array( 'code' => 'users.not_found', 'message' => 'Target user not found.' ) ), 404 );
		}

		// Key ceiling cannot exceed the target user's WP capability ceiling.
		$granted = max( 0, min( 4, (int) $request->get_param( 'max_level' ) ) );
		$ceiling = \AIOS\Security\PermissionEngine::LEVEL_DEPLOYMENT;
		if ( ! $target->has_cap( 'manage_options' ) ) {
			$ceiling = $target->has_cap( 'edit_others_posts' )
				? \AIOS\Security\PermissionEngine::LEVEL_SENSITIVE
				: \AIOS\Security\PermissionEngine::LEVEL_SAFE_WRITE;
		}
		if ( $granted > $ceiling ) {
			return $this->json(
				array(
					'error' => array(
						'code'    => 'keys.ceiling_exceeded',
						'message' => "max_level {$granted} exceeds the target user's WordPress capability ceiling ({$ceiling}).",
					),
				),
				422
			);
		}

		/** @var ApiKeyManager $manager */
		$manager = $this->container->get( ApiKeyManager::class );

		try {
			$issued = $manager->issue(
				array(
					'label'      => (string) $request->get_param( 'label' ),
					'user_id'    => $user_id,
					'max_level'  => $granted,
					'expires_at' => $request->get_param( 'expires_at' ),
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			return $this->json( array( 'error' => array( 'code' => 'keys.invalid', 'message' => $e->getMessage() ) ), 422 );
		}

		/** @var AuditLogger $audit */
		$audit = $this->container->get( AuditLogger::class );
		$audit->log(
			array(
				'user'   => $acting,
				'client' => 'rest',
				'tool'   => 'keys.create',
				'action' => sprintf( 'issue api key for user #%d (level %d)', $user_id, $granted ),
				'risk'   => 2,
				'status' => AuditLogger::STATUS_OK,
			)
		);

		// Raw key is returned exactly once, never stored.
		return $this->json( array( 'key' => $issued ) );
	}

	/**
	 * POST /keys/{id}/revoke.
	 */
	public function revoke( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->verifyNonce( $request ) ) {
			return $this->json( array( 'error' => array( 'code' => 'ai_os_nonce', 'message' => 'Nonce verification failed.' ) ), 403 );
		}

		$acting = wp_get_current_user();
		if ( ! $acting->exists() || ! $acting->has_cap( 'manage_options' ) ) {
			return $this->json( array( 'error' => array( 'code' => 'ai_os_forbidden', 'message' => 'Administrator capability required.' ) ), 403 );
		}

		$id = (int) $request->get_param( 'id' );

		/** @var ApiKeyManager $manager */
		$manager = $this->container->get( ApiKeyManager::class );
		$ok      = $manager->revoke( $id );

		if ( ! $ok ) {
			return $this->json( array( 'error' => array( 'code' => 'keys.not_found', 'message' => 'Key not found or already revoked.' ) ), 404 );
		}

		/** @var AuditLogger $audit */
		$audit = $this->container->get( AuditLogger::class );
		$audit->log(
			array(
				'user'   => $acting,
				'client' => 'rest',
				'tool'   => 'keys.revoke',
				'action' => sprintf( 'revoke api key #%d', $id ),
				'risk'   => 2,
				'status' => AuditLogger::STATUS_OK,
			)
		);

		return $this->json( array( 'revoked' => true ) );
	}
}
