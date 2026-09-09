<?php
/**
 * Context tools: the structured site map (spec §10).
 *
 * @package AIOS\Tools\Catalog
 */

declare( strict_types=1 );

namespace AIOS\Tools\Catalog;

use AIOS\Abilities\Ability;
use AIOS\Abilities\AbilityResult;
use AIOS\Abilities\AbilityRegistry;
use AIOS\Context\ContextEngine;
use AIOS\Tools\Tool;
use AIOS\Tools\ToolRegistry;

final class ContextTools implements CatalogProviderInterface {

	public static function id(): string {
		return 'context';
	}

	public static function isActive(): bool {
		return true;
	}

	public static function register( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register(
			Ability::make(
				array(
					'name'            => 'context.get_site_map',
					'description'     => 'Get the structured site knowledge tree: theme, plugins, post types, taxonomies, content counts, menus, REST routes, shortcodes — the architectural overview an agent needs before planning changes.',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => array(
							'refresh' => array(
								'type'        => 'boolean',
								'description' => 'Rebuild the context cache (slower) instead of using the cached tree.',
							),
						),
						'additionalProperties' => false,
					),
					'level'           => 0,
					'executeCallback' => static function ( array $args, $user ): AbilityResult {
						$engine = \AIOS\Core\Plugin::instance()?->container()?->get( ContextEngine::class );
						if ( null === $engine ) {
							return AbilityResult::error( 'context.unavailable', 'The context engine is not available in this context.', 'unavailable' );
						}

						$tree = $engine->siteMap( (bool) ( $args['refresh'] ?? false ) );
						return AbilityResult::success( $tree );
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'context.get_site_map',
					'description'     => 'Get the structured site knowledge tree (theme, plugins, types, taxonomies, menus, REST routes). Read-only.',
					'category'        => 'context',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => array( 'refresh' => array( 'type' => 'boolean' ) ),
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
