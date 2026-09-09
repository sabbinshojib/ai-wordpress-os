<?php
/**
 * Theme snapshot inspector.
 *
 * @package AIOS\Context\Inspectors
 */

declare( strict_types=1 );

namespace AIOS\Context\Inspectors;

final class ThemeInspector {

	/**
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		$theme = wp_get_theme();

		$templates = array();
		foreach ( (array) $theme->get_files( 'php', 1 ) as $file => $path ) {
			$templates[] = (string) $file;
		}

		$parts     = array();
		$parts_dir = get_theme_root( get_stylesheet() ) . '/' . get_stylesheet() . '/template-parts';
		if ( is_dir( $parts_dir ) ) {
			$entries = scandir( $parts_dir );
			if ( false === $entries ) {
				$entries = array();
			}
			foreach ( $entries as $entry ) {
				if ( str_ends_with( (string) $entry, '.php' ) ) {
					$parts[] = 'template-parts/' . $entry;
				}
			}
		}

		return array(
			'name'           => $theme->get( 'Name' ),
			'slug'           => get_stylesheet(),
			'version'        => $theme->get( 'Version' ),
			'author'         => $theme->get( 'Author' ),
			'text_domain'    => $theme->get( 'TextDomain' ),
			'is_child'       => is_child_theme(),
			'parent'         => is_child_theme() ? wp_get_theme( $theme->get_template() )->get( 'Name' ) : null,
			'templates'      => array_slice( $templates, 0, 80 ),
			'template_parts' => array_slice( $parts, 0, 40 ),
			'supports'       => array_keys(
				array_filter(
					array(
						'post-thumbnails' => current_theme_supports( 'post-thumbnails' ),
						'custom-logo'     => current_theme_supports( 'custom-logo' ),
						'html5'           => current_theme_supports( 'html5' ),
						'title-tag'       => current_theme_supports( 'title-tag' ),
						'align-wide'      => current_theme_supports( 'align-wide' ),
						'editor-styles'   => current_theme_supports( 'editor-styles' ),
					)
				)
			),
		);
	}
}
