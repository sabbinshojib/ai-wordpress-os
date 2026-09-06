<?php
/**
 * ContextEngine — structured site knowledge (spec §10).
 *
 * Builds the site tree by composing the inspectors, then caches it
 * in a transient (spec §38). Cache invalidation hooks: option saves,
 * theme switch, plugin activation changes.
 *
 * @package AIOS\Context
 */

declare( strict_types=1 );

namespace AIOS\Context;

use AIOS\Context\Inspectors\ContentInspector;
use AIOS\Context\Inspectors\PluginInspector;
use AIOS\Context\Inspectors\SystemInspector;
use AIOS\Context\Inspectors\ThemeInspector;
use AIOS\Settings\Settings;

final class ContextEngine {

	public const CACHE_KEY = 'ai_os_site_context';

	private Settings $settings;

	private ThemeInspector $theme;

	private PluginInspector $plugins;

	private ContentInspector $content;

	private SystemInspector $system;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->theme    = new ThemeInspector();
		$this->plugins  = new PluginInspector();
		$this->content  = new ContentInspector();
		$this->system   = new SystemInspector();
	}

	/**
	 * Full site map (cached unless refresh).
	 *
	 * @return array<string, mixed>
	 */
	public function siteMap( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$map = $this->build();

		set_transient( self::CACHE_KEY, $map, $this->settings->contextCacheMinutes() * MINUTE_IN_SECONDS );

		return $map;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build(): array {
		return array(
			'generated_at' => gmdate( 'c' ),
			'wordpress'    => array(
				'version'   => get_bloginfo( 'version' ),
				'language'  => get_bloginfo( 'language' ),
				'multisite' => is_multisite(),
				'timezone'  => wp_timezone_string(),
				'https'     => is_ssl(),
				'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
			),
			'site'         => array(
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'url'         => home_url( '/' ),
			),
			'theme'        => $this->theme->snapshot(),
			'plugins'      => $this->plugins->snapshot(),
			'content'      => $this->content->snapshot(),
			'menus'        => $this->content->menus(),
			'system'       => $this->system->snapshot(),
			'integrations' => $this->plugins->integrations(),
			'ai_os'        => array(
				'version'   => AI_WP_OS_VERSION,
				'tools'     => 0, // Filled by the REST layer when serving.
				'mode'      => $this->settings->mode(),
			),
		);
	}

	/**
	 * Invalidate the cached tree (hooked to change events).
	 */
	public static function invalidate(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Hook cache invalidation to relevant change events.
	 */
	public static function hookInvalidation(): void {
		add_action( 'activated_plugin', array( self::class, 'invalidate' ) );
		add_action( 'deactivated_plugin', array( self::class, 'invalidate' ) );
		add_action( 'switch_theme', array( self::class, 'invalidate' ) );
		add_action( 'updated_option', static function ( string $option ): void {
			// Site-shaping options only; not every option update.
			if ( in_array( $option, array( 'blogname', 'blogdescription', 'permalink_structure', 'active_plugins', 'users_can_register' ), true ) ) {
				self::invalidate();
			}
		}, 10, 1 );
	}
}
