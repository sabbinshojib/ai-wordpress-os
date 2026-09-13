<?php
/**
 * Developer Policy — authorization and approval boundary for developer operations.
 *
 * Integrates directly with PermissionEngine and WordPress capability infrastructure.
 * Enforces that developer actions require authenticated principals with ai_os_use,
 * approval of sensitive/destructive plans requires ai_os_approve, and capability
 * levels map directly to PermissionEngine levels.
 *
 * @package AIOS\Developer\Capability
 */

declare( strict_types=1 );

namespace AIOS\Developer\Capability;

use AIOS\Developer\Plan\DeveloperTaskPlan;
use AIOS\Security\PermissionEngine;
use WP_User;

final class DeveloperPolicy {

	/**
	 * PermissionEngine collaborator.
	 */
	private PermissionEngine $permissionEngine;

	/**
	 * Constructor.
	 *
	 * @param PermissionEngine $permission_engine The core permission engine.
	 */
	public function __construct( PermissionEngine $permission_engine ) {
		$this->permissionEngine = $permission_engine;
	}

	/**
	 * Check whether a user is authorized to execute a specific developer action.
	 *
	 * Fails closed if:
	 * - User does not exist or is not authenticated.
	 * - User lacks ai_os_use (or manage_options).
	 * - Capability is not in DeveloperCapability::ALL or contains dangerous substrings.
	 * - Required permission level exceeds user's effective ceiling.
	 *
	 * @param WP_User  $user                 The acting WordPress user.
	 * @param string   $developer_capability The developer capability identifier.
	 * @param int|null $key_max              Optional API key maximum level ceiling.
	 * @return bool True if permitted, false otherwise.
	 */
	public function canExecuteDeveloperAction( WP_User $user, string $developer_capability, ?int $key_max = null ): bool {
		if ( ! $user->exists() ) {
			return false;
		}

		// User must hold ai_os_use or manage_options.
		if ( ! $user->has_cap( 'ai_os_use' ) && ! $user->has_cap( 'manage_options' ) ) {
			return false;
		}

		if ( ! DeveloperCapability::isSupported( $developer_capability ) ) {
			return false;
		}

		$required_level = DeveloperCapability::levelFor( $developer_capability );

		return $this->permissionEngine->can( $user, $required_level, $key_max );
	}

	/**
	 * Check whether a user's role/capability ceiling allows a developer capability,
	 * independent of the active auto-execution mode ceiling.
	 *
	 * Used to verify if a user has the fundamental capability to perform or authorize
	 * an action, even when the active mode requires manual approval.
	 *
	 * @param WP_User  $user                 The acting WordPress user.
	 * @param string   $developer_capability The developer capability identifier.
	 * @param int|null $key_max              Optional API key maximum level ceiling.
	 * @return bool True if the user's capability ceiling meets the required level.
	 */
	public function isCapable( WP_User $user, string $developer_capability, ?int $key_max = null ): bool {
		if ( ! $user->exists() ) {
			return false;
		}

		if ( ! $user->has_cap( 'ai_os_use' ) && ! $user->has_cap( 'manage_options' ) ) {
			return false;
		}

		if ( ! DeveloperCapability::isSupported( $developer_capability ) ) {
			return false;
		}

		$required_level = DeveloperCapability::levelFor( $developer_capability );

		return $this->permissionEngine->capabilityCeilingFor( $user, $key_max ) >= $required_level;
	}

	/**
	 * Check whether a user can approve a developer plan at a given risk level.
	 *
	 * Requires the user to hold ai_os_approve (or manage_options) and have
	 * a capability ceiling equal to or greater than the plan's risk level.
	 *
	 * @param WP_User  $user            The deciding user.
	 * @param int      $plan_risk_level The risk level of the plan.
	 * @param int|null $key_max         Optional API key maximum level ceiling.
	 * @return bool True if authorized to approve.
	 */
	public function canApproveDeveloperPlan( WP_User $user, int $plan_risk_level, ?int $key_max = null ): bool {
		if ( ! $user->exists() ) {
			return false;
		}

		if ( ! $user->has_cap( 'ai_os_approve' ) && ! $user->has_cap( 'manage_options' ) ) {
			return false;
		}

		$ceiling = $this->permissionEngine->capabilityCeilingFor( $user, $key_max );

		return $ceiling >= $plan_risk_level;
	}

	/**
	 * Check whether a developer task plan requires human approval.
	 *
	 * @param int $risk_level The risk level to evaluate.
	 * @return bool True if approval is required.
	 */
	public function requiresApproval( int $risk_level ): bool {
		return $this->permissionEngine->requiresApproval( $risk_level );
	}

	/**
	 * Check whether a user can execute an entire developer task plan.
	 *
	 * Validates all required capabilities of the plan, checks that the user's
	 * ceiling accommodates the plan's risk level, and checks approval state.
	 *
	 * @param WP_User           $user     The acting user.
	 * @param DeveloperTaskPlan $plan     The plan to evaluate.
	 * @param int|null          $key_max  Optional API key maximum level ceiling.
	 * @return bool True if all capabilities and risk checks pass.
	 */
	public function canExecutePlan( WP_User $user, DeveloperTaskPlan $plan, ?int $key_max = null ): bool {
		if ( ! $user->exists() ) {
			return false;
		}

		if ( ! $user->has_cap( 'ai_os_use' ) && ! $user->has_cap( 'manage_options' ) ) {
			return false;
		}

		foreach ( $plan->requiredCapabilities() as $cap ) {
			if ( ! $this->canExecuteDeveloperAction( $user, $cap, $key_max ) ) {
				return false;
			}
		}

		return $this->permissionEngine->can( $user, $plan->riskLevel(), $key_max );
	}

	/**
	 * Explain why a developer capability was denied.
	 *
	 * @param WP_User  $user                 The acting user.
	 * @param string   $developer_capability The requested capability.
	 * @param int|null $key_max              Optional key maximum ceiling.
	 * @return string Human-readable denial reason.
	 */
	public function denialReason( WP_User $user, string $developer_capability, ?int $key_max = null ): string {
		if ( ! $user->exists() ) {
			return 'Authentication required: principal does not exist.';
		}

		if ( ! $user->has_cap( 'ai_os_use' ) && ! $user->has_cap( 'manage_options' ) ) {
			return 'Principal lacks ai_os_use or manage_options capability.';
		}

		if ( ! DeveloperCapability::isSupported( $developer_capability ) ) {
			return sprintf( 'Developer capability "%s" is not supported or denied.', $developer_capability );
		}

		$required_level = DeveloperCapability::levelFor( $developer_capability );
		$ceiling        = $this->permissionEngine->ceilingFor( $user, $key_max );

		if ( $required_level > $ceiling ) {
			return sprintf(
				'Capability "%s" requires permission level %d (%s), but current session ceiling is %d (%s).',
				$developer_capability,
				$required_level,
				PermissionEngine::LEVEL_NAMES[ $required_level ] ?? 'unknown',
				$ceiling,
				PermissionEngine::LEVEL_NAMES[ $ceiling ] ?? 'unknown'
			);
		}

		return 'Action permitted.';
	}
}
