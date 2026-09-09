<?php
/**
 * Site + status endpoints.
 *
 * @package AIOS\Rest\Controllers
 */

declare( strict_types=1 );

namespace AIOS\Rest\Controllers;

use AIOS\Audit\AuditLogger;
use AIOS\Database\Repositories\ApprovalRepository;
use AIOS\Database\Repositories\ToolExecutionRepository;
use AIOS\Settings\Settings;
use AIOS\Tools\ToolRegistry;

final class SiteController extends AbstractController {

	public function register( string $rest_namespace ): void {
			register_rest_route(
				$rest_namespace,
				'/site',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'site' ),
					'permission_callback' => array( $this, 'canRead' ),
				)
			);

			register_rest_route(
				$rest_namespace,
				'/status',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'canRead' ),
				)
			);
	}

		/**
		 * GET /site — condensed site card.
		 *
		 * @return \WP_REST_Response
		 */
	public function site( /* WP_REST_Request $request */ ): \WP_REST_Response {
			/** @var \AIOS\Context\ContextEngine $engine */
			$engine = $this->container->get( \AIOS\Context\ContextEngine::class );
			$map    = $engine->siteMap();

			$card = array(
				'name'        => $map['site']['name'] ?? '',
				'description' => $map['site']['description'] ?? '',
				'url'         => $map['site']['url'] ?? '',
				'wordpress'   => $map['wordpress'] ?? array(),
				'theme'       => array(
					'name'     => $map['theme']['name'] ?? '',
					'slug'     => $map['theme']['slug'] ?? '',
					'version'  => $map['theme']['version'] ?? '',
					'is_child' => $map['theme']['is_child'] ?? false,
				),
				'plugins'     => array(
					'installed' => $map['plugins']['installed'] ?? 0,
					'active'    => $map['plugins']['active'] ?? 0,
				),
				'content'     => $map['content']['library'] ?? array(),
			);

			return $this->json( $card );
	}

		/**
		 * GET /status — dashboard health card.
		 *
		 * @return \WP_REST_Response
		 */
	public function status( /* WP_REST_Request $request */ ): \WP_REST_Response {
			/** @var ToolRegistry $tools */
			$tools = $this->container->get( ToolRegistry::class );
			/** @var \AIOS\Database\Repositories\ToolExecutionRepository $execs */
			$execs = $this->container->get( ToolExecutionRepository::class );
			/** @var \AIOS\Database\Repositories\ApprovalRepository $approvals */
			$approvals = $this->container->get( ApprovalRepository::class );
			/** @var AuditLogger $audit */
			$audit = $this->container->get( AuditLogger::class );
			/** @var Settings $settings */
			$settings = $this->container->get( Settings::class );

			$health    = $execs->health( 7 );
			$audit_24h = $audit->repository()->stats();

			return $this->json(
				array(
					'ai_os'       => array(
						'version'       => AI_WP_OS_VERSION,
						'mode'          => $settings->mode(),
						'mcp_enabled'   => $settings->mcpEnabled(),
						'api_keys'      => $settings->apiKeysEnabled(),
						'file_read'     => $settings->fileReadEnabled(),
						'db_up_to_date' => $this->container->get( \AIOS\Database\Migrator::class )->isUpToDate(),
					),
					'environment' => array(
						'degraded_capabilities' => \AIOS\Core\EnvironmentGuard::degradedCapabilities(),
					),
					'tools'       => array(
						'registered' => $tools->count(),
						'available'  => $tools->countAvailable(),
					),
					'activity_7d' => $health,
					'audit_24h'   => $audit_24h,
					'approvals'   => array(
						'pending' => $approvals->pendingCount(),
					),
					'mcp'         => array(
						'endpoint' => rest_url( AI_WP_OS_REST_NAMESPACE . '/mcp' ),
						'protocol' => AI_WP_OS_MCP_PROTOCOL_VERSION,
					),
				)
			);
	}
}
