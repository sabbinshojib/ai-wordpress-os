<?php
/**
 * Tools endpoints: catalog + direct execution.
 *
 * @package AIOS\Rest\Controllers
 */

declare( strict_types=1 );

namespace AIOS\Rest\Controllers;

use AIOS\Security\Authenticator;
use AIOS\Database\Repositories\ApiKeyRepository;
use AIOS\Settings\Settings;
use AIOS\Tools\ToolExecutor;
use AIOS\Tools\ToolRegistry;
use WP_REST_Request;
use WP_REST_Response;

final class ToolsController extends AbstractController {

	public function register( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/tools',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'listTools' ),
				'permission_callback' => array( $this, 'canRead' ),
				'args'                => array(
					'available' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => static fn( $v ): bool => (bool) $v,
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/tools/execute',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'canRead' ),
				'args'                => array(
					'tool'      => array(
						'type'              => 'string',
						'required'          => true,
						'maxLength'         => 190,
						'sanitize_callback' => 'sanitize_key',
					),
					'arguments' => array(
						'type'     => 'object',
						'default'  => array(),
					),
				),
			)
		);
	}

	/**
	 * GET /tools — full catalog.
	 */
	public function listTools( WP_REST_Request $request ): WP_REST_Response {
		/** @var ToolRegistry $registry */
		$registry = $this->container->get( ToolRegistry::class );

		$only_available = (bool) $request->get_param( 'available' );

		return $this->json(
			array(
				'tools' => $registry->toArray( $only_available ),
				'count' => $only_available ? $registry->countAvailable() : $registry->count(),
			)
		);
	}

	/**
	 * POST /tools/execute — run a tool through the executor pipeline.
	 */
	public function execute( WP_REST_Request $request ): WP_REST_Response {
		/** @var ToolExecutor $executor */
		$executor = $this->container->get( ToolExecutor::class );

		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return $this->json(
				array( 'error' => array( 'code' => 'ai_os_unauthenticated', 'message' => 'Authentication required.' ) ),
				401
			);
		}

		$tool_name = (string) $request->get_param( 'tool' );
		$arguments = $request->get_param( 'arguments' );
		if ( ! is_array( $arguments ) ) {
			$arguments = array();
		}

		// API-key principal context (for key ceilings).
		$auth = null;
		$keys = $this->container->get( ApiKeyRepository::class );
		$settings = $this->container->get( Settings::class );
		if ( $keys instanceof ApiKeyRepository && $settings instanceof Settings ) {
			$auth = new Authenticator( $keys, $settings, 'rest' );
			$auth->resolve( $user );
		}

		$result = $executor->execute( $tool_name, $arguments, $user, 'rest', $auth );

		return $this->json( $result->toArray() );
	}
}
