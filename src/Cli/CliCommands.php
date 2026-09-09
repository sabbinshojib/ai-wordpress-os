<?php
/**
 * WP-CLI commands (spec §25): diagnostics + migration + tool listing.
 *
 * No arbitrary shell execution. Commands are fixed and read-only
 * except `migrate` (admin intent).
 *
 * @package AIOS\Cli
 */

declare( strict_types=1 );

namespace AIOS\Cli;

use AIOS\Core\Plugin;
use AIOS\Database\Database;
use AIOS\Database\Migrator;
use AIOS\Tools\ToolRegistry;
use WP_CLI;
use WP_CLI_Command;

final class CliCommands extends WP_CLI_Command {

	/**
	 * Show plugin status: version, mode, tool counts, DB state.
	 *
	 * ## EXAMPLES
	 *     wp ai-os status
	 */
	public function status(): void {
		$plugin = Plugin::instance();
		if ( null === $plugin || ! $plugin->isBooted() ) {
			WP_CLI::error( 'AI WordPress OS is not booted (check for boot errors).' );
		}

		$container = $plugin->container();

		WP_CLI::line( 'AI WordPress OS ' . AI_WP_OS_VERSION );
		WP_CLI::line( 'Security mode:  ' . $container->get( \AIOS\Settings\Settings::class )->mode() );
		WP_CLI::line( 'MCP endpoint:   ' . rest_url( AI_WP_OS_REST_NAMESPACE . '/mcp' ) );
		WP_CLI::line( 'MCP enabled:    ' . ( $container->get( \AIOS\Settings\Settings::class )->mcpEnabled() ? 'yes' : 'no' ) );

		/** @var ToolRegistry $tools */
		$tools = $container->get( ToolRegistry::class );
		WP_CLI::line( 'Tools:          ' . $tools->countAvailable() . ' available / ' . $tools->count() . ' registered' );

		/** @var Migrator $migrator */
		$migrator = $container->get( Migrator::class );
		WP_CLI::line( 'Migrations:     ' . ( $migrator->isUpToDate() ? 'up to date' : 'PENDING' ) );

		$degraded = \AIOS\Core\EnvironmentGuard::degradedCapabilities();
		if ( array() === $degraded ) {
			WP_CLI::line( 'Environment:    all optional capabilities available' );
		} else {
			WP_CLI::line( 'Environment:    ' . count( $degraded ) . ' degraded capability/ies (never fatal):' );
			foreach ( $degraded as $message ) {
				WP_CLI::line( '  - ' . $message );
			}
		}
	}

	/**
	 * Run pending database migrations.
	 *
	 * ## EXAMPLES
	 *     wp ai-os migrate
	 */
	public function migrate(): void {
		$database = new Database();
		$migrator = new Migrator();
		$result   = $migrator->migrate( $database );

		if ( array() === $result['applied'] ) {
			WP_CLI::success( 'No pending migrations.' );
			return;
		}

		WP_CLI::line( 'Applied: ' . implode( ', ', $result['applied'] ) );

		if ( null !== $result['failed'] ) {
			WP_CLI::error( sprintf( 'Migration %s failed: %s', $result['failed']['version'], $result['failed']['error'] ) );
		}

		WP_CLI::success( 'Migrations complete.' );
	}

	/**
	 * List registered tools.
	 *
	 * ## OPTIONS
	 *
	 * [--available]
	 * : Only list tools whose availability condition passes.
	 *
	 * ## EXAMPLES
	 *     wp ai-os tools --available
	 */
	public function tools( array $args, array $assoc_args ): void {
		$plugin = Plugin::instance();
		if ( null === $plugin || ! $plugin->isBooted() ) {
			WP_CLI::error( 'AI WordPress OS is not booted.' );
		}

		$only_available = isset( $assoc_args['available'] );

		/** @var ToolRegistry $registry */
		$registry = $plugin->container()->get( ToolRegistry::class );

		$rows = array();
		foreach ( $registry->toArray( $only_available ) as $tool ) {
			$rows[] = array(
				'tool'         => $tool['name'],
				'category'     => $tool['category'],
				'risk'         => (string) $tool['riskLevel'],
				'permission'   => (string) $tool['permissionLevel'],
				'confirmation' => $tool['confirmation'],
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'tool', 'category', 'risk', 'permission', 'confirmation' ) );
	}

	/**
	 * Register the commands with WP-CLI.
	 */
	public static function register(): void {
		WP_CLI::add_command( 'ai-os', self::class );
	}
}
