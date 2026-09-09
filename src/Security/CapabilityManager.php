<?php
/**
 * Conservative, admin-controlled grant/revoke layer for the two AI OS
 * capabilities (`ai_os_use`, `ai_os_approve`).
 *
 * This is deliberately NOT a general-purpose RBAC system: it only ever
 * manages these two specific, already-whitelisted WordPress
 * capabilities, on individual users, via the real WP_User
 * add_cap()/remove_cap() mechanism (the same mechanism
 * Activator::grantDefaultCapabilities() already uses at the role
 * level) — so it inherits WordPress's own per-site (multisite-safe)
 * capability storage and every existing `has_cap('ai_os_use')` /
 * `has_cap('ai_os_approve')` check picks up the change with zero
 * other code changes.
 *
 * Every mutation is:
 *   - restricted to an acting user who already holds manage_options,
 *   - restricted to the two whitelisted capability names,
 *   - refused when the acting user targets themselves (no
 *     self-escalation — and no self-demotion surprises either; an
 *     administrator managing their own grants through this mechanism
 *     is never necessary, since manage_options already satisfies both
 *     gates unconditionally),
 *   - audited.
 *
 * @package AIOS\Security
 */

declare( strict_types=1 );

namespace AIOS\Security;

use AIOS\Audit\AuditLogger;
use AIOS\Support\StructuredError;
use WP_User;

final class CapabilityManager {

		/**
		 * The only capabilities this layer will ever grant or revoke.
		 * Any other capability name (including WordPress's own, like
		 * manage_options) is refused — this is intentionally not a
		 * generic "manage any capability" tool.
		 */
	public const GRANTABLE = array( 'ai_os_use', 'ai_os_approve' );

	private AuditLogger $audit;

	public function __construct( AuditLogger $audit ) {
			$this->audit = $audit;
	}

		/**
		 * Grant one of the two AI OS capabilities to a specific user.
		 *
		 * @return true|StructuredError
		 */
	public function grant( WP_User $acting, int $target_user_id, string $capability ): bool|StructuredError {
			$target = $this->authorizeAndResolve( $acting, $target_user_id, $capability );
		if ( $target instanceof StructuredError ) {
				return $target;
		}

			$already_granted = $target->has_cap( $capability );
			$target->add_cap( $capability );

			$this->recordAudit( $acting, 'grant', $capability, $target_user_id, $already_granted );

			return true;
	}

		/**
		 * Revoke one of the two AI OS capabilities from a specific user.
		 *
		 * @return true|StructuredError
		 */
	public function revoke( WP_User $acting, int $target_user_id, string $capability ): bool|StructuredError {
			$target = $this->authorizeAndResolve( $acting, $target_user_id, $capability );
		if ( $target instanceof StructuredError ) {
				return $target;
		}

			$was_granted = $target->has_cap( $capability );
			$target->remove_cap( $capability );

			$this->recordAudit( $acting, 'revoke', $capability, $target_user_id, $was_granted );

			return true;
	}

		/**
		 * Current grant state for a user, across both managed
		 * capabilities — for a simple admin UI/API to read before
		 * offering a grant/revoke action.
		 *
		 * @return array<string, bool>|StructuredError
		 */
	public function state( int $target_user_id ): array|StructuredError {
			$target = get_userdata( $target_user_id );
		if ( ! $target instanceof WP_User || ! $target->exists() ) {
				return StructuredError::notFound( 'capabilities.user_not_found', 'Target user not found.' );
		}

			$state = array();
		foreach ( self::GRANTABLE as $capability ) {
				$state[ $capability ] = $target->has_cap( $capability );
		}
			return $state;
	}

		/**
		 * Shared validation for grant()/revoke(): capability whitelist,
		 * acting-user authorization, no self-escalation, target exists.
		 *
		 * @return WP_User|StructuredError
		 */
	private function authorizeAndResolve( WP_User $acting, int $target_user_id, string $capability ): WP_User|StructuredError {
		if ( ! in_array( $capability, self::GRANTABLE, true ) ) {
				return StructuredError::validation(
					'capabilities.unsupported_capability',
					sprintf( 'Only these capabilities can be managed here: %s.', implode( ', ', self::GRANTABLE ) )
				);
		}

		if ( ! $acting->exists() || ! $acting->has_cap( 'manage_options' ) ) {
				return StructuredError::permission( 'capabilities.forbidden', 'Administrator capability required to manage AI OS capabilities.' );
		}

		if ( $target_user_id === (int) $acting->ID ) {
				return StructuredError::permission( 'capabilities.self_escalation_denied', 'You cannot change your own AI OS capabilities through this endpoint.' );
		}

			$target = get_userdata( $target_user_id );
		if ( ! $target instanceof WP_User || ! $target->exists() ) {
				return StructuredError::notFound( 'capabilities.user_not_found', 'Target user not found.' );
		}

			return $target;
	}

	private function recordAudit( WP_User $acting, string $action, string $capability, int $target_user_id, bool $previous_state ): void {
			$this->audit->log(
				array(
					'user'             => $acting,
					'client'           => 'rest',
					'tool'             => 'capabilities.' . $action,
					'action'           => sprintf( '%s capability [%s] for user #%d (was %s)', $action, $capability, $target_user_id, $previous_state ? 'granted' : 'not granted' ),
					'risk'             => 3,
					'status'           => AuditLogger::STATUS_OK,
					'affected_objects' => array(
						array(
							'type'       => 'user',
							'id'         => $target_user_id,
							'capability' => $capability,
						),
					),
				)
			);
	}
}
