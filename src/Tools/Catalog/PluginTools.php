<?php
/**
 * Plugin inventory tool — read-only (spec §4).
 *
 * plugin.activate / plugin.install / plugin.update require very
 * careful hosting-level handling (filesystem access, ZIP handling
 * from wordpress.org, possible shell access) and ship in Phase 2
 * behind explicit risk 2-3 gates. Not faked here.
 *
 * @package AIOS\Tools\Catalog
 */

declare( strict_types=1 );

namespace AIOS\Tools\Catalog;

use AIOS\Abilities\Ability;
use AIOS\Abilities\AbilityResult;
use AIOS\Abilities\AbilityRegistry;
use AIOS\Tools\Tool;
use AIOS\Tools\ToolRegistry;

final class PluginTools implements CatalogProviderInterface {

	public static function id(): string {
		return 'plugin';
	}

	public static function isActive(): bool {
		return function_exists( 'get_plugins' ) || ( defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) );
	}

	public static function register( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register(
			Ability::make(
				array(
					'name'               => 'plugin.list',
					'description'        => 'List installed plugins: name, version, author, status (active/inactive), whether network-activated. Read-only.',
					'inputSchema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'status' => array(
								'type'        => 'string',
								'enum'        => array( 'all', 'active', 'inactive' ),
								'description' => 'Filter by status. Defaults to all.',
							),
						),
						'additionalProperties' => false,
					),
					'level'              => 0,
					'permissionCallback' => static fn( $user, array $args ): bool => $user->has_cap( 'manage_options' ) || $user->has_cap( 'activate_plugins' ),
					'executeCallback'    => static function ( array $args, $user ): AbilityResult {
						if ( ! function_exists( 'get_plugins' ) ) {
							require_once ABSPATH . 'wp-admin/includes/plugin.php';
						}

						$plugins     = get_plugins();
						$active      = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : array();
						$active_site = get_option( 'active_plugins', array() );

						$filter = $args['status'] ?? 'all';
						$items  = array();
						foreach ( $plugins as $file => $data ) {
							$is_active = in_array( $file, (array) $active_site, true ) || array_key_exists( $file, (array) $active );
							if ( 'active' === $filter && ! $is_active ) {
								continue;
							}
							if ( 'inactive' === $filter && $is_active ) {
								continue;
							}

							$items[] = array(
								'file'    => $file,
								'name'    => (string) ( $data['Name'] ?? '' ),
								'version' => (string) ( $data['Version'] ?? '' ),
								'author'  => (string) ( $data['Author'] ?? '' ),
								'slug'    => dirname( (string) $file ),
								'status'  => $is_active ? 'active' : 'inactive',
								'network' => array_key_exists( $file, (array) $active ),
							);
						}

						return AbilityResult::success(
							array(
								'items' => $items,
								'total' => count( $items ),
								'note'  => 'Plugin activation/installation tools ship in Phase 2 (risk-gated).',
							)
						);
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'plugin.list',
					'description'     => 'List installed plugins with status and versions. Read-only.',
					'category'        => 'plugin',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => array(
							'status' => array(
								'type' => 'string',
								'enum' => array( 'all', 'active', 'inactive' ),
							),
						),
						'additionalProperties' => false,
					),
					'riskLevel'       => 0,
					'permissionLevel' => 0,
					'confirmation'    => 'never',
				)
			)
		);
	}
}
