<?php
/**
 * Plugin inventory inspector + known-integration detection (spec §31).
 *
 * @package AIOS\Context\Inspectors
 */

declare( strict_types=1 );

namespace AIOS\Context\Inspectors;

final class PluginInspector {

	/**
	 * Integration slugs we know how to describe in Phase 1 context
	 * (adapters themselves arrive Phase 3).
	 *
	 * @var array<string, string>  constant/function probe → label
	 */
	private const INTEGRATION_PROBES = array(
		'elementor/elementor.php'        => 'Elementor',
		'woocommerce/woocommerce.php'    => 'WooCommerce',
		'advanced-custom-fields/acf.php' => 'ACF',
		'acf-pro/acf.php'                => 'ACF Pro',
		'wordpress-seo/wp-seo.php'       => 'Yoast SEO',
		'seo-by-rank-math/rank-math.php' => 'Rank Math',
		'wpforms/wpforms.php'            => 'WPForms',
		'gravityforms/gravityforms.php'  => 'Gravity Forms',
		'contact-form-7/wp-contact-form-7.php' => 'Contact Form 7',
		'bricks/bricks.php'              => 'Bricks Builder',
		'divi/divi.php'                  => 'Divi',
		'breakdance/plugin.php'          => 'Breakdance',
		'beaver-builder-lite-version/fl-builder.php' => 'Beaver Builder',
		'oxygen/functions.php'           => 'Oxygen',
		'jetpack/jetpack.php'            => 'Jetpack',
		'wp-super-cache/wp-cache.php'    => 'WP Super Cache',
		'autoptimize/autoptimize.php'    => 'Autoptimize',
	);

	/**
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			$admin_includes = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_file( $admin_includes ) ) {
				require_once $admin_includes;
			}
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return array( 'available' => false );
		}

		$plugins     = get_plugins();
		$active      = (array) get_option( 'active_plugins', array() );
		$network     = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		$active_ids  = array_merge( $active, array_keys( $network ) );

		$items = array();
		foreach ( $plugins as $file => $data ) {
			$items[] = array(
				'name'    => (string) ( $data['Name'] ?? '' ),
				'slug'    => dirname( (string) $file ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'active'  => in_array( (string) $file, $active_ids, true ),
			);
		}

		return array(
			'available'   => true,
			'installed'   => count( $items ),
			'active'      => count( array_filter( $items, static fn( array $p ): bool => $p['active'] ) ),
			'plugins'     => array_slice( $items, 0, 100 ),
		);
	}

	/**
	 * Detected, active, known integrations (Phase 3 adapter targets).
	 *
	 * @return array<int, array{slug: string, name: string, version: ?string}>
	 */
	public function integrations(): array {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			$admin_includes = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_file( $admin_includes ) ) {
				require_once $admin_includes;
			}
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}

		$plugins = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );

		$found = array();
		foreach ( $this::INTEGRATION_PROBES as $file => $label ) {
			if ( isset( $plugins[ $file ] ) ) {
				$is_active = in_array( $file, $active, true )
					|| ( is_multisite() && array_key_exists( $file, (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
				$found[] = array(
					'slug'    => dirname( $file ),
					'name'    => $label,
					'version' => (string) ( $plugins[ $file ]['Version'] ?? '' ),
					'active'  => $is_active,
				);
			}
		}

		return $found;
	}
}
