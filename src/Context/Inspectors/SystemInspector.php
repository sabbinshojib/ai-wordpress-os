<?php
/**
 * System snapshot inspector: REST routes, shortcodes, constants,
 * blocks (spec §10).
 *
 * @package AIOS\Context\Inspectors
 */

declare( strict_types=1 );

namespace AIOS\Context\Inspectors;

final class SystemInspector {

	/**
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		global $wp_rest_server, $shortcode_tags;

		// REST route inventory (bounded, names only).
		$rest_routes = array();
		if ( null === $wp_rest_server && class_exists( 'WP_REST_Server' ) ) {
			$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- standard bootstrap pattern
			if ( function_exists( 'rest_get_server' ) ) {
				$wp_rest_server = rest_get_server();
			}
		}
		if ( $wp_rest_server instanceof \WP_REST_Server ) {
			$namespaces = $wp_rest_server->get_namespaces();
			sort( $namespaces );
			$rest_routes = array_slice( $namespaces, 0, 60 );
		}

		// Shortcode inventory.
		$shortcodes = array();
		if ( isset( $shortcode_tags ) && is_array( $shortcode_tags ) ) {
			$shortcodes = array_slice( array_keys( $shortcode_tags ), 0, 80 );
			sort( $shortcodes );
		}

		return array(
			'rest_namespaces' => $rest_routes,
			'rest_count'      => count( $rest_routes ),
			'shortcodes'      => $shortcodes,
			'block_registry'  => self::blockRegistry(),
			'cron_enabled'    => defined( 'DISABLE_WP_CRON' ) ? ! DISABLE_WP_CRON : true,
			'object_cache'    => wp_using_ext_object_cache() ? 'external' : 'default',
			'debug'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
		);
	}

	/**
	 * Registered block types (when the block registry is bootable in
	 * this context — it usually only exists once blocks register).
	 *
	 * @return array<string, mixed>
	 */
	private static function blockRegistry(): array {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return array( 'available' => false );
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		$blocks   = array_keys( $registry->get_all_registered() );

		return array(
			'available' => true,
			'count'     => count( $blocks ),
			'names'     => array_slice( $blocks, 0, 120 ),
		);
	}
}
