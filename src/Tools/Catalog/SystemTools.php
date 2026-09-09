<?php
/**
 * System tools: cron inspection, cache flush, safe options subset
 * (spec §4, §25).
 *
 * No shell / WP-CLI execution exists in Phase 1 (spec §25: never
 * expose unrestricted shell execution; allowlists arrive Phase 2).
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

final class SystemTools implements CatalogProviderInterface {

	/**
	 * Option keys considered SAFE to expose to level-0 readers.
	 *
	 * Allowlist — never an arbitrary get_option tool.
	 *
	 * @var string[]
	 */
	private const SAFE_OPTIONS = array(
		'blogname',
		'blogdescription',
		'blog_public',
		'siteurl',
		'home',
		'posts_per_page',
		'date_format',
		'time_format',
		'timezone_string',
		'start_of_week',
		'permalink_structure',
		'category_base',
		'tag_base',
		'use_smilies',
		'default_comment_status',
		'comment_moderation',
		'thumbnail_size_w',
		'thumbnail_size_h',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
		'default_pingback_flag',
	);

	public static function id(): string {
		return 'system';
	}

	public static function isActive(): bool {
		return true;
	}

	public static function register( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		self::registerCronList( $abilities, $tools );
		self::registerCacheFlush( $abilities, $tools );
		self::registerOptionsSubset( $abilities, $tools );
	}

	// -------------------------------------------------------- system.cron.list

	private static function registerCronList( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register(
			Ability::make(
				array(
					'name'            => 'system.cron.list',
					'description'     => 'List scheduled WP-Cron events: hook, next run (UTC), schedule, args count.',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => (object) array(),
						'additionalProperties' => false,
					),
					'level'           => 0,
					'executeCallback' => static function ( array $args, $user ): AbilityResult {
						$crons = _get_cron_array();
						if ( ! is_array( $crons ) ) {
							return AbilityResult::error( 'system.cron_unavailable', 'The cron array is not available in this context.', 'unavailable' );
						}

						$events = array();
						$count  = 0;
						foreach ( $crons as $timestamp => $hooks ) {
							foreach ( (array) $hooks as $hook => $groups ) {
								foreach ( (array) $groups as $signature => $data ) {
									if ( ++$count > 300 ) {
										break 3;
									}
									$events[] = array(
										'hook'     => (string) $hook,
										'next_run' => gmdate( 'c', (int) $timestamp ),
										'schedule' => isset( $data['schedule'] ) ? (string) $data['schedule'] : 'single',
										'args'     => array_key_exists( 'args', (array) $data )
											? \AIOS\Support\Sanitize::redact( (array) $data['args'] ) : array(),
									);
								}
							}
						}

						usort( $events, static fn( array $a, array $b ): int => strcmp( (string) $a['next_run'], (string) $b['next_run'] ) );

						return AbilityResult::success(
							array(
								'events' => $events,
								'total'  => $count,
							)
						);
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'system.cron.list',
					'description'     => 'List scheduled WP-Cron events (hook, next run, schedule). Read-only.',
					'category'        => 'system',
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

	// ------------------------------------------------------ system.cache.flush

	private static function registerCacheFlush( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register(
			Ability::make(
				array(
					'name'               => 'system.cache.flush',
					'description'        => 'Flush the WordPress object cache and clear all AI OS transients. Level 2 (sensitive).',
					'inputSchema'        => array(
						'type'                 => 'object',
						'properties'           => (object) array(),
						'additionalProperties' => false,
					),
					'level'              => 2,
					'permissionCallback' => static fn( $user, array $args ): bool => $user->has_cap( 'manage_options' ),
					'executeCallback'    => static function ( array $args, $user ): AbilityResult {
						wp_cache_flush();
						delete_transient( \AIOS\Context\ContextEngine::CACHE_KEY );

						$result = AbilityResult::success(
							array(
								'object_cache_flushed'     => true,
								'ai_os_transients_cleared' => true,
							)
						);
						$result->note( 'Flush the object cache and AI OS context cache.' );
						return $result;
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'system.cache.flush',
					'description'     => 'Flush the object cache and AI OS transients (sensitive; approval-gated in safe mode).',
					'category'        => 'system',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => (object) array(),
						'additionalProperties' => false,
					),
					'riskLevel'       => 2,
					'permissionLevel' => 2,
					'confirmation'    => 'approval',
				)
			)
		);
	}

	// -------------------------------------------- system.get_options_subset

	private static function registerOptionsSubset( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register(
			Ability::make(
				array(
					'name'            => 'system.get_options_subset',
					'description'     => 'Read a fixed allowlist of public WordPress options (site identity, permalinks, discussion and media sizing). No secrets.',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => (object) array(),
						'additionalProperties' => false,
					),
					'level'           => 0,
					'executeCallback' => static function ( array $args, $user ): AbilityResult {
						$options = array();
						foreach ( self::SAFE_OPTIONS as $key ) {
							$options[ $key ] = get_option( $key );
						}
						return AbilityResult::success( array( 'options' => $options ) );
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'system.get_options_subset',
					'description'     => 'Read an allowlist of public WordPress options (identity, permalinks, sizing). Read-only.',
					'category'        => 'system',
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
}
