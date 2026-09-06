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
use AIOS\Tools\Tool;
use AIOS\Tools\ToolExecutor;
use AIOS\Tools\ToolRegistry;
use WP_Error;
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
						'sanitize_callback' => array( $this, 'sanitizeToolIdentifier' ),
						'validate_callback' => array( $this, 'validateToolIdentifier' ),
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
	 * REST sanitize_callback for the `tool` argument (spec BUG-002 fix).
	 *
	 * Every registered tool identifier is dot-notation and exclusively
	 * lowercase (enforced by every catalog provider and by
	 * `Tool::NAME_PATTERN`). Lowercasing a client-supplied identifier is
	 * a meaning-preserving normalization — "Content.Get_Post" and
	 * "content.get_post" name the same tool — unlike the previous
	 * `sanitize_key()` callback, which stripped the `.` every tool name
	 * requires and silently turned every call into a lookup for a
	 * different (nonexistent) identifier. This callback never removes
	 * or alters any character beyond trimming and case-folding, so a
	 * well-formed identifier always survives sanitization intact and
	 * ready to match `ToolRegistry`'s (lowercase) keys.
	 *
	 * @param mixed $value Raw request value.
	 */
	public function sanitizeToolIdentifier( $value ): string {
		return is_string( $value ) ? strtolower( trim( $value ) ) : '';
	}

	/**
	 * REST validate_callback for the `tool` argument. Runs on the RAW
	 * (pre-sanitize) value per the REST API's own validate-then-sanitize
	 * order, so it accepts the same case range `Tool::NAME_PATTERN`
	 * allows. Rejects anything that is not a well-formed dot-notation
	 * tool identifier — control characters, path-like input,
	 * injection-shaped strings, and empty/whitespace-only values all
	 * fail this grammar check before ever reaching the executor.
	 *
	 * @param mixed $value Raw request value.
	 */
	public function validateToolIdentifier( $value ): bool|WP_Error {
		// Control characters (including a null byte) are rejected
		// outright, BEFORE any trimming — PHP's trim() silently strips
		// "\0"/"\n"/"\r"/"\t" from its default charlist, which would
		// otherwise let a control-character-laden value quietly turn
		// into a well-formed one instead of being refused.
		$has_control_chars = is_string( $value ) && 1 === preg_match( '/[\x00-\x1F\x7F]/', $value );

		if ( ! is_string( $value ) || $has_control_chars || 1 !== preg_match( Tool::NAME_PATTERN, trim( $value ) ) ) {
			return new WP_Error(
				'ai_os_invalid_tool_identifier',
				'The "tool" parameter must be a dot-notation tool identifier, e.g. "content.get_post".',
				array( 'status' => 400 )
			);
		}
		return true;
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
		if ( ! $this->verifyNonce( $request ) ) {
			return $this->json( array( 'error' => array( 'code' => 'ai_os_nonce', 'message' => 'Nonce verification failed.' ) ), 403 );
		}

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
