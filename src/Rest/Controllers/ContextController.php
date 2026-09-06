<?php
/**
 * Context endpoint — the site knowledge tree.
 *
 * @package AIOS\Rest\Controllers
 */

declare( strict_types=1 );

namespace AIOS\Rest\Controllers;

use AIOS\Context\ContextEngine;
use WP_REST_Request;
use WP_REST_Response;

final class ContextController extends AbstractController {

	public function register( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/context',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'context' ),
				'permission_callback' => array( $this, 'canRead' ),
				'args'                => array(
					'refresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * GET /context.
	 */
	public function context( WP_REST_Request $request ): WP_REST_Response {
		/** @var ContextEngine $engine */
		$engine = $this->container->get( ContextEngine::class );

		$refresh = (bool) $request->get_param( 'refresh' );
		$map     = $engine->siteMap( $refresh );

		// Fill the live tool count for the dashboard.
		/** @var \AIOS\Tools\ToolRegistry $tools */
		$tools = $this->container->get( \AIOS\Tools\ToolRegistry::class );
		$map['ai_os']['tools'] = $tools->countAvailable();

		return $this->json( $map );
	}
}
