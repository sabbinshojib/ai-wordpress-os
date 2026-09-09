<?php
/**
 * Audit log endpoints (spec §9: filterable Activity feed).
 *
 * @package AIOS\Rest\Controllers
 */

declare( strict_types=1 );

namespace AIOS\Rest\Controllers;

use AIOS\Audit\AuditLogger;
use WP_REST_Request;
use WP_REST_Response;

final class LogsController extends AbstractController {

	public function register( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'canApprove' ),
				'args'                => array(
					'user_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'tool'    => array(
						'type'              => 'string',
						'maxLength'         => 190,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'  => array(
						'type' => 'string',
						'enum' => array( 'ok', 'error', 'blocked', 'rejected', 'approval_required' ),
					),
					'risk'    => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 4,
					),
					'since'   => array(
						'type'    => 'string',
						'pattern' => '^\d{4}-\d{2}-\d{2}',
					),
					'search'  => array(
						'type'              => 'string',
						'maxLength'         => 100,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'   => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
					'page'    => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
						'maximum' => 1000,
					),
				),
			)
		);
	}

	/**
	 * GET /logs.
	 */
	public function list( WP_REST_Request $request ): WP_REST_Response {
		/** @var AuditLogger $audit */
		$audit = $this->container->get( AuditLogger::class );

		$filters = array();
		foreach ( array( 'user_id', 'tool', 'status', 'risk', 'since', 'search' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$filters[ $key ] = $value;
			}
		}

		$limit   = (int) $request->get_param( 'limit' );
		$page    = (int) $request->get_param( 'page' );
		$filters = \AIOS\Support\Sanitize::redact( $filters );

		$rows = $audit->repository()->query( $filters, $limit, ( max( 1, $page ) - 1 ) * $limit );

		$items = array();
		foreach ( $rows as $row ) {
			unset( $row['args_json'] );
			$items[] = $row;
		}

		return $this->json(
			array(
				'items' => $items,
				'count' => count( $items ),
				'stats' => $audit->repository()->stats(),
			)
		);
	}
}
