<?php
/**
 * Plugin Name:       AI WordPress OS
 * Plugin URI:        https://example.com/ai-wordpress-os
 * Description:       An AI-native operating system for WordPress: expose your site to ChatGPT, Claude, Claude Code, Cursor and every MCP-compatible client through a secure, permission-gated, fully audited capability layer.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            AI WordPress OS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-wordpress-os
 * Domain Path:       /languages
 *
 * @package AIOS
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/*
 * =========================================================================
 * AI WordPress OS — bootstrap
 * =========================================================================
 *
 * This file intentionally stays tiny. All it does:
 *
 *   1. Guard the environment (PHP + WordPress versions).
 *   2. Register a PSR-4 autoloader for the AIOS\ namespace (src/).
 *   3. Boot the kernel, which lazily initialises every subsystem.
 *
 * No business logic lives here, and nothing heavy runs on frontend
 * requests: the kernel only registers hook callbacks and defers all
 * real work to admin / REST / CLI contexts.
 * -------------------------------------------------------------------------
 */

define( 'AI_WP_OS_VERSION', '1.0.0' );
define( 'AI_WP_OS_DB_VERSION', '1' );
define( 'AI_WP_OS_FILE', __FILE__ );
define( 'AI_WP_OS_DIR', plugin_dir_path( __FILE__ ) );        // trailing slash
define( 'AI_WP_OS_URL', plugin_dir_url( __FILE__ ) );          // trailing slash
define( 'AI_WP_OS_BASENAME', plugin_basename( __FILE__ ) );
define( 'AI_WP_OS_SLUG', 'ai-wordpress-os' );
define( 'AI_WP_OS_REST_NAMESPACE', 'ai-os/v1' );
define( 'AI_WP_OS_MCP_PROTOCOL_VERSION', '2025-06-18' );
define( 'AI_WP_OS_SUPPORTED_MCP_VERSIONS', array( '2025-06-18', '2025-03-26', '2024-11-05' ) );

/**
 * Minimum requirements guard.
 *
 * Runs before anything else so a site on an old stack never fatals.
 * The admin notice closure is only registered when the guard fails.
 */
function ai_wp_os_environment_failed(): bool {
	if ( version_compare( PHP_VERSION, '8.2.0', '<' ) ) {
		return true;
	}
	if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
		return true;
	}
	return false;
}

if ( ai_wp_os_environment_failed() ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'AI WordPress OS could not start.', 'ai-wordpress-os' ),
				esc_html__( 'It requires PHP 8.2+ and WordPress 6.9+.', 'ai-wordpress-os' )
			);
		}
	);
	return;
}

/**
 * PSR-4 autoloader for the AIOS\ namespace.
 *
 * A hand-rolled autoloader keeps the plugin dependency-free on hosts
 * that have no Composer. Composer's autoloader, if the site provides
 * one for this plugin (dev environments), takes precedence naturally
 * because it is registered earlier by the time WordPress loads us.
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'AIOS\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'AIOS\\' ) );
		$path     = AI_WP_OS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Activation: environment re-check, migrations, defaults, onboarding flag.
 * Deactivation: cron cleanup. Both are idempotent.
 */
register_activation_hook(
	__FILE__,
	static function ( bool $network_wide = false ): void {
		if ( ai_wp_os_environment_failed() ) {
			// Deactivate immediately on an incompatible stack.
			deactivate_plugins( AI_WP_OS_BASENAME );
			wp_die(
				esc_html__( 'AI WordPress OS requires PHP 8.2+ and WordPress 6.9+.', 'ai-wordpress-os' )
			);
		}

		$activator = new AIOS\Core\Activator();
		$activator->activate( $network_wide );
	}
);

register_deactivation_hook(
	__FILE__,
	static function ( bool $network_wide = false ): void {
		$deactivator = new AIOS\Core\Deactivator();
		$deactivator->deactivate( $network_wide );
	}
);

/**
 * Boot the kernel.
 *
 * The kernel registers hooks only; subsystems (REST, MCP, admin UI,
 * tool catalog) are constructed lazily inside their hook callbacks so
 * that ordinary frontend requests never touch them.
 */
global $ai_wp_os;

try {
	$ai_wp_os = new AIOS\Core\Plugin();
	$ai_wp_os->boot();
} catch ( Throwable $boot_failure ) {
	// Never take a whole site down because of an internal boot error.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[AI WordPress OS] boot failure: ' . $boot_failure->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	add_action(
		'admin_notices',
		static function () use ( $boot_failure ): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p><strong>AI WordPress OS:</strong> %s</p></div>',
				esc_html( $boot_failure->getMessage() )
			);
		}
	);
}

/**
 * Public accessor for the kernel / service container.
 *
 * @internal Use AIOS classes directly where possible; this helper
 *           exists for edge cases (uninstall, CLI, integrations).
 */
function ai_wp_os(): ?AIOS\Core\Plugin {
	global $ai_wp_os;
	return $ai_wp_os instanceof AIOS\Core\Plugin ? $ai_wp_os : null;
}
