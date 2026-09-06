<?php
/**
 * Content tools: posts & pages CRUD through WP APIs (spec §4).
 *
 * Business logic lives in the abilities; permission callbacks add
 * per-object capability checks on top of the level gate.
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
use WP_Error;

final class ContentTools implements CatalogProviderInterface {

	public static function id(): string {
		return 'content';
	}

	public static function isActive(): bool {
		return true;
	}

	public static function register( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		// ------------------------------------------------------------ posts.
		self::registerList( 'posts', 'post', 'content.list_posts', 'List posts with filtering, search, ordering and pagination.', $abilities, $tools );
		self::registerGet( 'posts', 'post', 'content.get_post', 'Get a single post by id, including content, excerpt, status, terms and metadata.', $abilities, $tools );
		self::registerCreate( 'posts', 'post', 'content.create_post', 'Create a new post. Level 1 (safe write).', array( 'post' ), $abilities, $tools );
		self::registerUpdate( 'posts', 'post', 'content.update_post', 'Update an existing post (title, content, status, excerpt...). Level 1 (safe write).', $abilities, $tools );
		self::registerDelete( 'posts', 'post', 'content.delete_post', 'Move a post to trash. Level 2 (sensitive) — approval-gated in safe mode.', $abilities, $tools );

		// ------------------------------------------------------------ pages.
		self::registerList( 'pages', 'page', 'content.list_pages', 'List pages with filtering, search, ordering and pagination.', $abilities, $tools );
		self::registerGet( 'pages', 'page', 'content.get_page', 'Get a single page by id, including content, template, status and metadata.', $abilities, $tools );
		self::registerCreate( 'pages', 'page', 'content.create_page', 'Create a new page. Level 1 (safe write).', array( 'page' ), $abilities, $tools );
		self::registerUpdate( 'pages', 'page', 'content.update_page', 'Update an existing page (title, content, template, status...). Level 1 (safe write).', $abilities, $tools );
	}

	// ------------------------------------------------------------------- list

	private static function registerList( string $plural, string $type, string $name, string $description, AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register( Ability::make(
			array(
				'name'        => $name,
				'description' => $description,
				'inputSchema' => array(
					'type'                 => 'object',
					'properties'           => array(
						'status'   => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ), 'description' => 'Post status filter. Defaults to publish.' ),
						'search'   => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'Search term matched against title and content.' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Results per page (max 50).' ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1, 'description' => '1-based page number.' ),
						'orderby'  => array( 'type' => 'string', 'enum' => array( 'date', 'title', 'modified', 'ID' ) ),
						'order'    => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ) ),
					),
					'additionalProperties' => false,
				),
				'level'              => 0,
				'permissionCallback' => static function ( $user, array $args ) use ( $type ): bool {
					return $user->has_cap( 'edit_posts' ) || $user->has_cap( 'edit_pages' );
				},
				'executeCallback'    => static function ( array $args, $user ) use ( $type ): AbilityResult {
					$query_args = array(
						'post_type'           => $type,
						'post_status'         => $args['status'] ?? 'publish',
						'posts_per_page'      => (int) ( $args['per_page'] ?? 20 ),
						'paged'               => (int) ( $args['page'] ?? 1 ),
						'orderby'             => $args['orderby'] ?? 'date',
						'order'               => $args['order'] ?? 'DESC',
						'ignore_sticky_posts' => true,
						'no_found_rows'       => false,
					);
					if ( ! empty( $args['search'] ) ) {
						$query_args['s'] = sanitize_text_field( (string) $args['search'] );
					}

					$query = new \WP_Query( $query_args );
					$items = array();
					foreach ( $query->posts as $post ) {
						$items[] = self::summary( $post );
					}

					$result = AbilityResult::success(
						array(
							'items'    => $items,
							'total'    => (int) $query->found_posts,
							'pages'    => (int) $query->max_num_pages,
							'per_page' => (int) $query_args['posts_per_page'],
						)
					);
					return $result;
				},
			)
		) );

		$tools->register( Tool::make(
			array(
				'name'            => $name,
				'description'     => $description . ' Read-only.',
				'category'        => 'content',
				'inputSchema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'status'   => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ) ),
						'search'   => array( 'type' => 'string', 'maxLength' => 200 ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
						'orderby'  => array( 'type' => 'string', 'enum' => array( 'date', 'title', 'modified', 'ID' ) ),
						'order'    => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ) ),
					),
					'additionalProperties' => false,
				),
				'riskLevel'       => 0,
				'permissionLevel' => 0,
				'confirmation'    => 'never',
			)
		) );
	}

	// -------------------------------------------------------------------- get

	private static function registerGet( string $plural, string $type, string $name, string $description, AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register( Ability::make(
			array(
				'name'        => $name,
				'description' => $description,
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Post id.' ),
					),
					'required'            => array( 'id' ),
					'additionalProperties' => false,
				),
				'level'              => 0,
				'permissionCallback' => static function ( $user, array $args ): bool {
					$post = get_post( (int) ( $args['id'] ?? 0 ) );
					return $post instanceof \WP_Post && ( $user->has_cap( 'edit_post', $post->ID ) || 'publish' === $post->post_status );
				},
				'executeCallback'    => static function ( array $args, $user ) use ( $type, $name ): AbilityResult {
					$post = get_post( (int) ( $args['id'] ?? 0 ) );
					if ( ! $post instanceof \WP_Post || $post->post_type !== $type ) {
						return AbilityResult::error( 'content.not_found', "No {$type} found with that id.", 'not_found' );
					}

					$payload = self::detail( $post );
					// Untrusted content fields are wrapped for the client model.
					$payload = PromptHygiene::wrapFields( $payload, array( 'content', 'excerpt' ) );

					$result = AbilityResult::success( $payload );
					$result->affected( $type, $post->ID );
					return $result;
				},
			)
		) );

		$tools->register( Tool::make(
			array(
				'name'            => $name,
				'description'     => $description . ' Read-only. Content is wrapped in untrusted-content markers.',
				'category'        => 'content',
				'inputSchema'     => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					'required'   => array( 'id' ),
					'additionalProperties' => false,
				),
				'riskLevel'       => 0,
				'permissionLevel' => 0,
				'confirmation'    => 'never',
			)
		) );
	}

	// ----------------------------------------------------------------- create

	private static function registerCreate( string $plural, string $type, string $name, string $description, array $statuses, AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register( Ability::make(
			array(
				'name'        => $name,
				'description' => $description,
				'inputSchema' => self::writeSchema( $type, true ),
				'level'              => 1,
				'permissionCallback' => static function ( $user, array $args ): bool {
					return 'page' === ( $args['post_type'] ?? '' )
						? $user->has_cap( 'edit_pages' )
						: $user->has_cap( 'edit_posts' );
				},
				'executeCallback'    => static function ( array $args, $user ) use ( $type ): AbilityResult {
					$postarr = array(
						'post_type'   => $type,
						'post_status' => in_array( $args['status'] ?? 'draft', array( 'draft', 'pending', 'publish', 'private' ), true )
							? (string) ( $args['status'] ?? 'draft' ) : 'draft',
						'post_title'  => sanitize_text_field( (string) ( $args['title'] ?? '' ) ),
						'post_name'   => isset( $args['slug'] ) ? sanitize_title( (string) $args['slug'] ) : '',
						'post_author' => (int) $user->ID,
					);
					if ( isset( $args['content'] ) ) {
						$postarr['post_content'] = (string) $args['content'];
					}
					if ( isset( $args['excerpt'] ) ) {
						$postarr['post_excerpt'] = (string) $args['excerpt'];
					}
					if ( 'page' === $type && isset( $args['template'] ) ) {
						$postarr['page_template'] = sanitize_file_name( (string) $args['template'] );
					}

					// Publishing requires the publish cap; silently degrade
					// to pending otherwise (never a silent privilege bypass).
					if ( 'publish' === $postarr['post_status'] && ! $user->has_cap( 'publish_posts' ) && ! $user->has_cap( 'publish_pages' ) ) {
						$postarr['post_status'] = 'pending';
					}

					$post_id = wp_insert_post( $postarr, true );
					if ( $post_id instanceof WP_Error ) {
						return AbilityResult::error( 'content.create_failed', $post_id->get_error_message(), 'execution', array( 'code' => $post_id->get_error_code() ) );
					}

					$post = get_post( $post_id );
					$result = AbilityResult::success(
						array(
							'id'     => (int) $post_id,
							'status' => $post?->post_status ?? 'draft',
							'link'   => get_permalink( $post_id ) ?: null,
							'slug'   => $post?->post_name ?? '',
						)
					);
					$result->affected( $type, $post_id );
					$result->note( sprintf( 'Create %s "%s" (%s).', $type, $postarr['post_title'], $postarr['post_status'] ) );
					return $result;
				},
			)
		) );

		$tools->register( Tool::make(
			array(
				'name'            => $name,
				'description'     => $description,
				'category'        => 'content',
				'inputSchema'     => self::writeSchema( $type, true ),
				'riskLevel'       => 1,
				'permissionLevel' => 1,
				'confirmation'    => 'never',
			)
		) );
	}

	// ----------------------------------------------------------------- update

	private static function registerUpdate( string $plural, string $type, string $name, string $description, AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register( Ability::make(
			array(
				'name'        => $name,
				'description' => $description,
				'inputSchema' => self::writeSchema( $type, false ),
				'level'              => 1,
				'permissionCallback' => static function ( $user, array $args ): bool {
					$post = get_post( (int) ( $args['id'] ?? 0 ) );
					return $post instanceof \WP_Post && $user->has_cap( 'edit_post', $post->ID );
				},
				'executeCallback'    => static function ( array $args, $user ) use ( $type ): AbilityResult {
					$post = get_post( (int) ( $args['id'] ?? 0 ) );
					if ( ! $post instanceof \WP_Post || $post->post_type !== $type ) {
						return AbilityResult::error( 'content.not_found', "No {$type} found with that id.", 'not_found' );
					}

					$postarr = array( 'ID' => $post->ID );
					if ( array_key_exists( 'title', $args ) ) {
						$postarr['post_title'] = sanitize_text_field( (string) $args['title'] );
					}
					if ( array_key_exists( 'content', $args ) ) {
						$postarr['post_content'] = (string) $args['content'];
					}
					if ( array_key_exists( 'excerpt', $args ) ) {
						$postarr['post_excerpt'] = (string) $args['excerpt'];
					}
					if ( array_key_exists( 'slug', $args ) ) {
						$postarr['post_name'] = sanitize_title( (string) $args['slug'] );
					}
					if ( array_key_exists( 'status', $args ) && in_array( $args['status'], array( 'draft', 'pending', 'publish', 'private' ), true ) ) {
						if ( 'publish' === $args['status'] && 'publish' !== $post->post_status
							&& ! $user->has_cap( 'publish_posts' ) && ! $user->has_cap( 'publish_pages' ) ) {
							return AbilityResult::error( 'permission.status_denied', 'You are not allowed to publish this item.', 'permission' );
						}
						$postarr['post_status'] = (string) $args['status'];
					}
					if ( 'page' === $type && array_key_exists( 'template', $args ) ) {
						$postarr['page_template'] = sanitize_file_name( (string) $args['template'] );
					}

					$updated = wp_update_post( $postarr, true );
					if ( $updated instanceof WP_Error ) {
						return AbilityResult::error( 'content.update_failed', $updated->get_error_message(), 'execution', array( 'code' => $updated->get_error_code() ) );
					}

					$fresh = get_post( $updated );
					$result = AbilityResult::success(
						array(
							'id'     => (int) $updated,
							'status' => $fresh?->post_status ?? '',
							'link'   => get_permalink( $updated ) ?: null,
							'slug'   => $fresh?->post_name ?? '',
						)
					);
					$result->affected( $type, $updated );
					$result->note( sprintf( 'Update %s "%s".', $type, $postarr['post_title'] ?? $post->post_title ) );
					return $result;
				},
			)
		) );

		$tools->register( Tool::make(
			array(
				'name'            => $name,
				'description'     => $description . ' Only provided fields change.',
				'category'        => 'content',
				'inputSchema'     => self::writeSchema( $type, false ),
				'riskLevel'       => 1,
				'permissionLevel' => 1,
				'confirmation'    => 'never',
			)
		) );
	}

	// ----------------------------------------------------------------- delete

	private static function registerDelete( string $plural, string $type, string $name, string $description, AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register( Ability::make(
			array(
				'name'        => $name,
				'description' => $description . ' Permanent deletion is not offered in Phase 1.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					'required'   => array( 'id' ),
					'additionalProperties' => false,
				),
				'level'              => 2,
				'permissionCallback' => static function ( $user, array $args ): bool {
					$post = get_post( (int) ( $args['id'] ?? 0 ) );
					return $post instanceof \WP_Post && $user->has_cap( 'delete_post', $post->ID );
				},
				'executeCallback'    => static function ( array $args, $user ) use ( $type ): AbilityResult {
					$post = get_post( (int) ( $args['id'] ?? 0 ) );
					if ( ! $post instanceof \WP_Post || $post->post_type !== $type ) {
						return AbilityResult::error( 'content.not_found', "No {$type} found with that id.", 'not_found' );
					}

					// No force: always to trash. Untrash via update (status).
					$deleted = wp_trash_post( $post->ID );
					if ( false === $deleted || null === $deleted ) {
						return AbilityResult::error( 'content.delete_failed', "Could not move the {$type} to trash.", 'execution' );
					}

					$result = AbilityResult::success(
						array( 'id' => $post->ID, 'status' => 'trash', 'restorable' => true )
					);
					$result->affected( $type, $post->ID );
					$result->note( sprintf( 'Move %s "%s" to trash.', $type, $post->post_title ) );
					return $result;
				},
			)
		) );

		$tools->register( Tool::make(
			array(
				'name'            => $name,
				'description'     => $description . ' Sends to trash (restorable).',
				'category'        => 'content',
				'inputSchema'     => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					'required'   => array( 'id' ),
					'additionalProperties' => false,
				),
				'riskLevel'       => 2,
				'permissionLevel' => 2,
				'confirmation'    => 'approval',
			)
		) );
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * Shared write schema for create/update.
	 *
	 * @return array<string, mixed>
	 */
	private static function writeSchema( string $type, bool $is_create ): array {
		$properties = array(
			'title'   => array( 'type' => 'string', 'maxLength' => 500, 'description' => 'Title (plain text).' ),
			'content' => array( 'type' => 'string', 'maxLength' => 500000, 'description' => 'Content (HTML or block markup). Treated as untrusted site content by the client.' ),
			'excerpt' => array( 'type' => 'string', 'maxLength' => 5000 ),
			'slug'    => array( 'type' => 'string', 'maxLength' => 200 ),
			'status'  => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish', 'private' ) ),
		);
		if ( 'page' === $type ) {
			$properties['template'] = array( 'type' => 'string', 'maxLength' => 200, 'description' => 'Page template file name, e.g. page-full-width.php' );
		}

		if ( $is_create ) {
			$required = array( 'title' );
			$properties['title']['minLength'] = 1;
			return array(
				'type'       => 'object',
				'properties' => $properties,
				'required'   => $required,
				'additionalProperties' => false,
			);
		}

		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Id of the item to update.' ) ),
				$properties
			),
			'required'   => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function summary( \WP_Post $post ): array {
		return array(
			'id'        => (int) $post->ID,
			'title'     => get_the_title( $post ),
			'status'    => $post->post_status,
			'slug'      => $post->post_name,
			'type'      => $post->post_type,
			'date'      => $post->post_date_gmt,
			'modified'  => $post->post_modified_gmt,
			'author'    => (int) $post->post_author,
			'link'      => get_permalink( $post ) ?: null,
			'excerpt'   => wp_trim_words( wp_strip_all_tags( (string) get_the_excerpt( $post ) ), 30 ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function detail( \WP_Post $post ): array {
		$payload = self::summary( $post );
		$payload['content'] = (string) $post->post_content;
		$payload['excerpt'] = (string) $post->post_excerpt;

		$terms = array();
		$taxonomies = get_object_taxonomies( $post, 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			$names = wp_get_post_terms( $post->ID, $taxonomy->name, array( 'fields' => 'names' ) );
			if ( ! empty( $names ) && ! is_wp_error( $names ) ) {
				$terms[ $taxonomy->name ] = $names;
			}
		}
		$payload['terms'] = $terms;

		$meta = get_post_meta( $post->ID );
		$clean_meta = array();
		foreach ( $meta as $key => $values ) {
			// Skip huge/serialized/protected meta from output.
			if ( str_starts_with( (string) $key, '_' ) || count( $values ) > 5 ) {
				continue;
			}
			$value = $values[0];
			if ( is_serialized( $value ) || mb_strlen( (string) $value ) > 2000 ) {
				$value = '(complex value)';
			}
			$clean_meta[ $key ] = $value;
		}
		$payload['meta'] = $clean_meta;

		return $payload;
	}
}
