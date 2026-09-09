<?php
/**
 * User inspection tools — read-only (spec §4).
 *
 * No user create/update/delete tools in Phase 1. The executor's
 * escalation blocklist additionally refuses any attempt to reach
 * users.create / users.update (role changes) so that even future
 * third-party registrations aimed there are stopped centrally.
 *
 * Emails are only exposed to principals who hold the WordPress
 * list_users capability. Password hashes are NEVER exposed.
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

final class UserTools implements CatalogProviderInterface {

	public static function id(): string {
		return 'users';
	}

	public static function isActive(): bool {
		return true;
	}

	public static function register( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		self::registerList( $abilities, $tools );
		self::registerGet( $abilities, $tools );
	}

	// -------------------------------------------------------------- users.list

	private static function registerList( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register(
			Ability::make(
				array(
					'name'               => 'users.list',
					'description'        => 'List user accounts: id, login, display name, roles. Emails require the WordPress list_users capability. Password hashes are never exposed.',
					'inputSchema'        => array(
						'type'                 => 'object',
						'properties'           => array(
							'role'     => array(
								'type'        => 'string',
								'maxLength'   => 60,
								'description' => 'Filter by role slug, e.g. administrator.',
							),
							'per_page' => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 50,
							),
							'page'     => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
						),
						'additionalProperties' => false,
					),
					'level'              => 0,
					'permissionCallback' => static fn( $user, array $args ): bool => $user->has_cap( 'list_users' ),
					'executeCallback'    => static function ( array $args, $user ): AbilityResult {
						$query_args = array(
							'number' => (int) ( $args['per_page'] ?? 20 ),
							'paged'  => (int) ( $args['page'] ?? 1 ),
							'fields' => array( 'ID', 'user_login', 'display_name', 'user_email', 'roles' ),
						);
						if ( ! empty( $args['role'] ) ) {
							$query_args['role__in'] = array( sanitize_key( (string) $args['role'] ) );
						}

						$query = new \WP_User_Query( $query_args );
						$items = array();
						foreach ( $query->get_results() as $account ) {
							$items[] = self::shape( $account, $user );
						}

						return AbilityResult::success(
							array(
								'items'    => $items,
								'total'    => (int) $query->get_total(),
								'per_page' => $query_args['number'],
							)
						);
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'users.list',
					'description'     => 'List user accounts (id, login, name, roles; emails for list_users holders only). Read-only.',
					'category'        => 'users',
					'inputSchema'     => array(
						'type'                 => 'object',
						'properties'           => array(
							'role'     => array(
								'type'      => 'string',
								'maxLength' => 60,
							),
							'per_page' => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 50,
							),
							'page'     => array(
								'type'    => 'integer',
								'minimum' => 1,
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

	// --------------------------------------------------------------- users.get

	private static function registerGet( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register(
			Ability::make(
				array(
					'name'               => 'users.get',
					'description'        => 'Get one user account: id, login, display name, roles, capabilities count, registration date. Emails require the list_users capability.',
					'inputSchema'        => array(
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
					'level'              => 0,
					'permissionCallback' => static function ( $user, array $args ): bool {
						return $user->has_cap( 'list_users' ) || (int) ( $args['id'] ?? 0 ) === (int) $user->ID;
					},
					'executeCallback'    => static function ( array $args, $user ): AbilityResult {
						$account = get_userdata( (int) ( $args['id'] ?? 0 ) );
						if ( ! $account instanceof \WP_User || ! $account->exists() ) {
							return AbilityResult::error( 'users.not_found', 'No user found with that id.', 'not_found' );
						}

						$payload = self::shape( $account, $user );
						$payload['registered'] = $account->user_registered;
						$payload['url']        = $account->user_url;

						$result = AbilityResult::success( $payload );
						$result->affected( 'user', $account->ID );
						return $result;
					},
				)
			)
		);

		$tools->register(
			Tool::make(
				array(
					'name'            => 'users.get',
					'description'     => 'Get one user account. Read-only; secrets never exposed.',
					'category'        => 'users',
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

	/**
	 * @return array<string, mixed>
	 */
	private static function shape( \WP_User $account, $viewer ): array {
		$payload = array(
			'id'    => (int) $account->ID,
			'login' => $account->user_login,
			'name'  => $account->display_name,
			'roles' => array_values( (array) $account->roles ),
		);
		// Only list_users holders see emails (PII minimization).
		if ( $viewer instanceof \WP_User && $viewer->has_cap( 'list_users' ) ) {
			$payload['email'] = $account->user_email;
		}
		return $payload;
	}
}
