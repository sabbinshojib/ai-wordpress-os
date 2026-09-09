<?php
/**
 * Theme inspection tools — READ-ONLY in Phase 1 (spec §4, §11, §13).
 *
 * The file WRITE engine (theme.write_file etc.) ships in Phase 2 with
 * snapshots + rollback; no fake write tools exist here.
 *
 * @package AIOS\Tools\Catalog
 */

declare( strict_types=1 );

namespace AIOS\Tools\Catalog;

use AIOS\Abilities\Ability;
use AIOS\Abilities\AbilityResult;
use AIOS\Abilities\AbilityRegistry;
use AIOS\Security\PromptHygiene;
use AIOS\Tools\Tool;
use AIOS\Tools\ToolRegistry;

final class ThemeTools implements CatalogProviderInterface {

	public static function id(): string {
			return 'theme';
	}

	public static function isActive(): bool {
			return defined( 'AI_WP_OS_DIR' ) && function_exists( 'wp_get_theme' );
	}

	public static function register( AbilityRegistry $abilities, ToolRegistry $tools ): void {
			self::registerGetInfo( $abilities, $tools );
			self::registerListFiles( $abilities, $tools );
			self::registerReadFile( $abilities, $tools );
	}

		// ------------------------------------------------------------ theme.get_info

	private static function registerGetInfo( AbilityRegistry $abilities, ToolRegistry $tools ): void {
			$abilities->register(
				Ability::make(
					array(
						'name'            => 'theme.get_info',
						'description'     => 'Get active theme details: name, version, author, template list, supported features, text domain.',
						'inputSchema'     => array(
							'type'                 => 'object',
							'properties'           => (object) array(),
							'additionalProperties' => false,
						),
						'level'           => 0,
						'executeCallback' => static function ( array $args, $user ): AbilityResult {
									$theme = wp_get_theme();

									$templates = array();
							foreach ( (array) $theme->get_files( 'php', 1 ) as $file => $path ) {
								$templates[] = $file;
							}

									$supported = array();
							foreach ( array( 'post-thumbnails', 'custom-logo', 'custom-header', 'custom-background', 'automatic-feed-links', 'html5', 'title-tag', 'responsive-embeds', 'align-wide', 'editor-styles', 'woocommerce', 'wp-block-styles' ) as $feature ) {
								if ( current_theme_supports( $feature ) ) {
									$supported[] = $feature;
								}
							}

									return AbilityResult::success(
										array(
											'name'        => $theme->get( 'Name' ),
											'slug'        => $theme->get_stylesheet(),
											'template'    => $theme->get_template(),
											'version'     => $theme->get( 'Version' ),
											'author'      => $theme->get( 'Author' ),
											'text_domain' => $theme->get( 'TextDomain' ),
											'is_child'    => is_child_theme(),
											'parent'      => is_child_theme() ? wp_get_theme( $theme->get_template() )->get( 'Name' ) : null,
											'supports'    => $supported,
											'templates'   => array_slice( $templates, 0, 100 ),
										)
									);
						},
					)
				)
			);

			$tools->register(
				Tool::make(
					array(
						'name'            => 'theme.get_info',
						'description'     => 'Get active theme details: name, version, author, parent, template list. Read-only.',
						'category'        => 'theme',
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

		// --------------------------------------------------------- theme.list_files

	private static function registerListFiles( AbilityRegistry $abilities, ToolRegistry $tools ): void {
			$abilities->register(
				Ability::make(
					array(
						'name'               => 'theme.list_files',
						'description'        => 'List inspectable files of the active (or specified) theme: templates, parts, CSS and JS assets. Write engine arrives in Phase 2.',
						'inputSchema'        => array(
							'type'                 => 'object',
							'properties'           => array(
								'theme' => array(
									'type'        => 'string',
									'maxLength'   => 100,
									'description' => 'Theme slug. Defaults to the active theme.',
								),
								'path'  => array(
									'type'        => 'string',
									'maxLength'   => 200,
									'description' => 'Optional subdirectory, e.g. "template-parts".',
								),
							),
							'additionalProperties' => false,
						),
						'level'              => 0,
						'permissionCallback' => static fn( $user, array $args ): bool => $user->has_cap( 'switch_themes' ) || $user->has_cap( 'edit_theme_options' ) || $user->has_cap( 'manage_options' ),
						'executeCallback'    => static function ( array $args, $user ): AbilityResult {
									$slug = isset( $args['theme'] ) ? sanitize_key( (string) $args['theme'] ) : get_stylesheet();
									$theme = wp_get_theme( $slug );
							if ( ! $theme->exists() ) {
									return AbilityResult::error( 'theme.not_found', "Theme [{$slug}] does not exist.", 'not_found' );
							}

									$root = trailingslashit( get_theme_root( $slug ) ) . $slug;

									$relative_sub = '';
							if ( ! empty( $args['path'] ) ) {
									$relative_sub = sanitize_file_name( (string) $args['path'] );
							}

									$guard = new \AIOS\Security\PathGuard( get_theme_root() );
									$files = self::scan( $root . ( '' !== $relative_sub ? '/' . $relative_sub : '' ), $guard, $root );

									return AbilityResult::success(
										array(
											'theme' => $slug,
											'root'  => 'theme directory: ' . $slug . ( '' !== $relative_sub ? '/' . $relative_sub : '' ),
											'files' => $files,
											'count' => count( $files ),
											'note'  => 'Theme file writing is not available in Phase 1 (arrives with snapshots + rollback in Phase 2).',
										)
									);
						},
					)
				)
			);

			$tools->register(
				Tool::make(
					array(
						'name'            => 'theme.list_files',
						'description'     => 'List inspectable files of a theme (templates, parts, assets). Read-only; file writes arrive in Phase 2.',
						'category'        => 'theme',
						'inputSchema'     => array(
							'type'                 => 'object',
							'properties'           => array(
								'theme' => array(
									'type'      => 'string',
									'maxLength' => 100,
								),
								'path'  => array(
									'type'      => 'string',
									'maxLength' => 200,
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

		// ---------------------------------------------------------- theme.read_file

	private static function registerReadFile( AbilityRegistry $abilities, ToolRegistry $tools ): void {
			$abilities->register(
				Ability::make(
					array(
						'name'               => 'theme.read_file',
						'description'        => 'Read a file from a theme (PHP, CSS, JS, JSON, templates). Content is secret-redacted and wrapped as untrusted. Max 512KB. Read-only.',
						'inputSchema'        => array(
							'type'                 => 'object',
							'properties'           => array(
								'file'  => array(
									'type'        => 'string',
									'maxLength'   => 300,
									'description' => 'Theme-relative path, e.g. functions.php or template-parts/header.php',
								),
								'theme' => array(
									'type'        => 'string',
									'maxLength'   => 100,
									'description' => 'Theme slug. Defaults to active theme.',
								),
							),
							'required'             => array( 'file' ),
							'additionalProperties' => false,
						),
						'level'              => 0,
						'permissionCallback' => static fn( $user, array $args ): bool => $user->has_cap( 'edit_theme_options' ) || $user->has_cap( 'manage_options' ) || $user->has_cap( 'switch_themes' ),
						'executeCallback'    => static function ( array $args, $user ): AbilityResult {
									$slug  = isset( $args['theme'] ) ? sanitize_key( (string) $args['theme'] ) : get_stylesheet();
									$theme = wp_get_theme( $slug );
							if ( ! $theme->exists() ) {
									return AbilityResult::error( 'theme.not_found', "Theme [{$slug}] does not exist.", 'not_found' );
							}

									$relative = str_replace( '\\', '/', (string) ( $args['file'] ?? '' ) );
									$relative = ltrim( $relative, '/' );

									$reader = \AIOS\Files\FileReader::forTheme( $slug );
									$read   = $reader->read( $relative );

							if ( $read->error() !== null ) {
									return AbilityResult::fromError( $read->error() );
							}

									$payload = array(
										'file'    => $relative,
										'theme'   => $slug,
										'size'    => $read->size(),
										'lines'   => $read->lineCount(),
										'content' => PromptHygiene::wrap( $read->redactedContent() ),
									);

									$result = AbilityResult::success( $payload );
									$result->affected( 'theme_file', $slug . ':' . $relative );
									return $result;
						},
					)
				)
			);

			$tools->register(
				Tool::make(
					array(
						'name'            => 'theme.read_file',
						'description'     => 'Read a theme file (PHP/CSS/JS/templates). Secret-redacted, wrapped as untrusted content. Read-only in Phase 1.',
						'category'        => 'theme',
						'inputSchema'     => array(
							'type'                 => 'object',
							'properties'           => array(
								'file'  => array(
									'type'      => 'string',
									'maxLength' => 300,
								),
								'theme' => array(
									'type'      => 'string',
									'maxLength' => 100,
								),
							),
							'required'             => array( 'file' ),
							'additionalProperties' => false,
						),
						'riskLevel'       => 0,
						'permissionLevel' => 0,
						'confirmation'    => 'never',
					)
				)
			);
	}

		// ---------------------------------------------------------------- helpers

		/**
		 * Recursive scan bounded by depth + count, filtered through
		 * PathGuard's extension allowlist and the protected list.
		 *
		 * @return array<int, array{path: string, size: int}>
		 */
	private static function scan( string $dir, \AIOS\Security\PathGuard $guard, string $root ): array {
			$files = array();
		if ( ! is_dir( $dir ) ) {
				return $files;
		}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			$iterator->setMaxDepth( 4 );

			$count = 0;
		foreach ( $iterator as $file_info ) {
			if ( ! $file_info instanceof \SplFileInfo ) {
				continue;
			}
			if ( ++$count > 500 ) {
					break; // Bound the walk.
			}

				$path = $file_info->getPathname();
			if ( ! $guard->isAllowedExtension( $path ) || $guard->isProtected( $path ) ) {
					continue;
			}

				$files[] = array(
					'path' => ltrim( str_replace( $root, '', str_replace( '\\', '/', $path ) ), '/' ),
					'size' => (int) $file_info->getSize(),
				);
		}

			usort( $files, static fn( array $a, array $b ): int => strcmp( $a['path'], $b['path'] ) );
			return $files;
	}
}
