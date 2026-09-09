<?php
/**
 * Content + menu snapshot inspector (spec §10).
 *
 * @package AIOS\Context\Inspectors
 */

declare( strict_types=1 );

namespace AIOS\Context\Inspectors;

final class ContentInspector {

		/**
		 * @return array<string, mixed>
		 */
	public function snapshot(): array {
			$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
				$counts       = wp_count_posts( $type->name );
				$post_types[] = array(
					'name'      => (string) $type->name,
					'label'     => (string) $type->label,
					'public'    => (bool) $type->public,
					'count'     => (int) ( $counts->publish ?? 0 ),
					'gutenberg' => function_exists( 'use_block_editor_for_post_type' ) ? (bool) use_block_editor_for_post_type( $type->name ) : true,
				);
		}

			$taxonomies = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
				$taxonomy_counts = wp_count_terms(
					array(
						'taxonomy'   => $taxonomy->name,
						'hide_empty' => false,
					)
				);
				$taxonomies[]    = array(
					'name'         => (string) $taxonomy->name,
					'label'        => (string) $taxonomy->label,
					'count'        => is_wp_error( $taxonomy_counts ) ? null : (int) $taxonomy_counts,
					'hierarchical' => (bool) $taxonomy->hierarchical,
				);
		}

			return array(
				'post_types' => $post_types,
				'taxonomies' => $taxonomies,
				'library'    => array(
					'posts' => (int) ( wp_count_posts()->publish ?? 0 ),
					'pages' => (int) ( wp_count_posts( 'page' )->publish ?? 0 ),
					'media' => (int) ( wp_count_posts( 'attachment' )->inherit ?? 0 ),
				),
			);
	}

		/**
		 * @return array<int, array{term_id: int, name: string, locations: string[]}>
		 */
	public function menus(): array {
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
					'term_id'   => (int) $menu->term_id,
					'name'      => (string) $menu->name,
					'locations' => $assigned,
				);
		}
			return $items;
	}
}
