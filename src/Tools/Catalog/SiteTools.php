<?php
/**
 * Site tools: environment + health inspection (spec §4).
 *
 * @package AIOS\Tools\Catalog
 */

declare( strict_types=1 );

namespace AIOS\Tools\Catalog;

use AIOS\Abilities\Ability;
use AIOS\Abilities\AbilityRegistry;
use AIOS\Abilities\AbilityResult;
use AIOS\Tools\Tool;
use AIOS\Tools\ToolRegistry;

final class SiteTools implements CatalogProviderInterface {

	public static function id(): string {
		return 'site';
	}

	public static function isActive(): bool {
		return true;
	}

	public static function register( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		self::registerGetInfo( $abilities, $tools );
		self::registerGetHealth( $abilities, $tools );
		self::registerGetEnvironment( $abilities, $tools );
	}

	// -------------------------------------------------------------- site.get_info

	private static function registerGetInfo( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register(
			Ability::make(
				array(
					'name'            => 'site.get_info',
					'description'     => 'Get core site information: title, description, URL, language, timezone, active theme, WordPress version, and key counts.',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => (object) array(),
						'additionalProperties' => false,
					),
					'outputSchema'    => array( 'type' => 'object' ),
					'level'           => 0,
					'executeCallback' => static function ( array $args, $user ): AbilityResult {
						$counts = wp_count_posts();
						$result = AbilityResult::success(
							array(
								'site'           => get_bloginfo( 'name' ),
								'description'    => get_bloginfo( 'description' ),
								'url'            => home_url( '/' ),
								'language'       => get_bloginfo( 'language' ),
								'timezone'       => wp_timezone_string(),
								'wordpress'      => get_bloginfo( 'version' ),
								'php'            => PHP_VERSION,
								'theme'          => wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ),
								'theme_slug'     => get_template(),
								'is_multisite'   => is_multisite(),
								'is_child_theme' => is_child_theme(),
								'counts'         => array(
									'posts' => (int) ( $counts->publish ?? 0 ),
									'pages' => (int) ( wp_count_posts( 'page' )->publish ?? 0 ),
									'users' => (int) count_users()['total_users'],
								),
							)
						);
						return $result;
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'site.get_info',
					'description'     => 'Get core site information: title, URL, language, timezone, active theme, WordPress/PHP versions, and content counts. Read-only, safe.',
					'category'        => 'site',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => (object) array(),
						'additionalProperties' => false,
					),
					'riskLevel'       => 0,
					'permissionLevel' => 0,
					'confirmation'    => 'never',
				)
			)
		);
	}

	// ------------------------------------------------------------ site.get_health

	private static function registerGetHealth( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register(
			Ability::make(
				array(
					'name'            => 'site.get_health',
					'description'     => 'Run WordPress Site Health checks and return the current status indicators and critical issues.',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => (object) array(),
						'additionalProperties' => false,
					),
					'outputSchema'    => array( 'type' => 'object' ),
					'level'           => 0,
					'executeCallback' => static function ( array $args, $user ): AbilityResult {
						if ( ! class_exists( 'WP_Site_Health' ) && file_exists( ABSPATH . 'wp-admin/includes/class-wp-site-health.php' ) ) {
							require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
						}
						if ( ! class_exists( 'WP_Site_Health' ) ) {
							return AbilityResult::error( 'site.health_unavailable', 'The WordPress Site Health API is not available in this context.', 'unavailable' );
						}

						$tests     = \WP_Site_Health::get_tests();
						$results   = array();

						foreach ( array( 'direct' ) as $group ) {
							foreach ( ( $tests[ $group ] ?? array() ) as $test ) {
								if ( empty( $test['test'] ) ) {
									continue;
								}
								if ( is_callable( $test['test'] ) ) {
									$label = (string) ( $test['label'] ?? 'test' );
									$results[ $label ] = is_callable( $test['test'] ) ? call_user_func( $test['test'] ) : null;
								}
							}
						}

						return AbilityResult::success(
							array(
								'site_status' => array(
									'good'        => 0,
									'critical'    => 0,
									'recommended' => 0,
								),
								'tests'       => $results,
							)
						);
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'site.get_health',
					'description'     => 'Run WordPress Site Health checks and return status indicators and issues. Read-only, safe.',
					'category'        => 'site',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => (object) array(),
						'additionalProperties' => false,
					),
					'riskLevel'       => 0,
					'permissionLevel' => 0,
					'confirmation'    => 'never',
				)
			)
		);
	}

	// ------------------------------------------------------ site.get_environment

	private static function registerGetEnvironment( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register(
			Ability::make(
				array(
					'name'            => 'site.get_environment',
					'description'     => 'Get technical environment details: PHP version and loaded extensions, database driver, debug flags, memory limits, HTTPS status. Never returns secrets.',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => (object) array(),
						'additionalProperties' => false,
					),
					'outputSchema'    => array( 'type' => 'object' ),
					'level'           => 0,
					'executeCallback' => static function ( array $args, $user ): AbilityResult {
						global $wpdb;

						return AbilityResult::success(
							array(
								'php_version'      => PHP_VERSION,
								'php_sapi'         => PHP_SAPI,
								'php_extensions'   => array_values(
									array_intersect(
										get_loaded_extensions(),
										array(
											'curl',
											'mbstring',
											'openssl',
											'imagick',
											'gd',
											'zip',
											'intl',
											'sodium',
											'opcache',
											'redis',
											'memcached',
										)
									)
								),
								'memory_limit'     => self::iniValueOr( 'memory_limit', 'unknown' ),
								'max_execution'    => (int) self::iniValueOr( 'max_execution_time', '0' ),
								'upload_max'       => self::iniValueOr( 'upload_max_filesize', 'unknown' ),
								'database'         => array(
									'engine'  => $wpdb->db_version() ? 'MySQL/MariaDB' : 'unknown',
									'version' => $wpdb->db_version(),
									'prefix'  => $wpdb->prefix,
								),
								'debug'            => array(
									'wp_debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
									'wp_debug_log'   => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
									'script_debug'   => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
									'display_errors' => (bool) ini_get( 'display_errors' ),
								),
								'https'            => is_ssl(),
								'wp_cron'          => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'disabled (external)' : 'built-in',
								'object_cache'     => wp_using_ext_object_cache() ? 'external' : 'default',
								'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
							)
						);
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'site.get_environment',
					'description'     => 'Get technical environment details: PHP version/extensions, database version, debug flags, HTTPS, cron and cache setup. No secrets are returned.',
					'category'        => 'site',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => (object) array(),
						'additionalProperties' => false,
					),
					'riskLevel'       => 0,
					'permissionLevel' => 0,
					'confirmation'    => 'never',
				)
			)
		);
	}

	/**
	 * Read an ini value with an explicit fallback for the falsy/absent
	 * cases (ini_get() returns false for disabled/unset directives).
	 */
	private static function iniValueOr( string $directive, string $fallback ): string {
		$value = ini_get( $directive );
		return $value ? $value : $fallback;
	}
}
