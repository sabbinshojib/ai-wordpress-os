<?php
/**
 * Menu inspection tools (spec §4).
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

final class MenuTools implements CatalogProviderInterface {

	public static function id(): string {
		return 'menus';
	}

	public static function isActive(): bool {
		return function_exists( 'wp_get_nav_menus' );
	}

	public static function register( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register(
			Ability::make(
				array(
					'name'            => 'menus.list',
					'description'     => 'List navigation menus: id, name, slug, item count, locations.',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => (object) array(),
						'additionalProperties' => false,
					),
					'level'           => 0,
					'executeCallback' => static function ( array $args, $user ): AbilityResult {
						$menus     = wp_get_nav_menus();
						$locations = get_nav_menu_locations();

						$items = array();
						foreach ( (array) $menus as $menu ) {
							if ( ! is_object( $menu ) ) {
								continue;
							}
							$assigned = array();
							foreach ( $locations as $location => $menu_id ) {
								if ( (int) $menu_id === (int) $menu->term_id ) {
									$assigned[] = (string) $location;
								}
							}

							$items[] = array(
								'id'        => (int) $menu->term_id,
								'name'      => (string) $menu->name,
								'slug'      => (string) $menu->slug,
								'count'     => (int) $menu->count,
								'locations' => $assigned,
							);
						}

						return AbilityResult::success(
							array(
								'items'     => $items,
								'locations' => $locations,
							)
						);
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'menus.list',
					'description'     => 'List navigation menus with locations and item counts. Read-only.',
					'category'        => 'menus',
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

		$abilities->register(
			Ability::make(
				array(
					'name'            => 'menus.get',
					'description'     => 'Get one menu with its full item tree (title, url, parent, object type, order).',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => array(
							'id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'Menu term id (see menus.list).',
							),
						),
						'required'             => array( 'id' ),
						'additionalProperties' => false,
					),
					'level'           => 0,
					'executeCallback' => static function ( array $args, $user ): AbilityResult {
						$menu_id = (int) ( $args['id'] ?? 0 );
						$menu    = wp_get_nav_menu_object( $menu_id );
						if ( ! is_object( $menu ) ) {
							return AbilityResult::error( 'menus.not_found', 'No menu found with that id.', 'not_found' );
						}

						$items = wp_get_nav_menu_items( $menu_id );
						$flat  = array();
						foreach ( (array) $items as $item ) {
							if ( ! is_object( $item ) ) {
								continue;
							}
							$flat[] = array(
								'id'        => (int) $item->ID,
								'parent'    => (int) $item->menu_item_parent,
								'title'     => (string) $item->title,
								'url'       => (string) $item->url,
								'object'    => (string) $item->object,
								'object_id' => (string) $item->object_id,
								'type'      => (string) $item->type,
								'order'     => (int) $item->menu_order,
							);
						}

						$result = AbilityResult::success(
							array(
								'id'    => (int) $menu->term_id,
								'name'  => (string) $menu->name,
								'items' => $flat,
							)
						);
						$result->affected( 'menu', $menu_id );
						return $result;
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'menus.get',
					'description'     => 'Get one menu with its full item tree. Read-only.',
					'category'        => 'menus',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => array(
							'id' => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						'required'             => array( 'id' ),
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
