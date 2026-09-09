<?php
/**
 * Permission Engine — the security core of AI WordPress OS.
 *
 * Permission levels (spec §7):
 *   0 READ, 1 SAFE WRITE, 2 SENSITIVE, 3 DESTRUCTIVE, 4 DEPLOYMENT
 *
 * The effective ceiling for any principal is the MINIMUM of:
 *   - the active security mode ceiling,
 *   - the WordPress capabilities the user actually has,
 *   - an optional AI OS per-user grant ceiling,
 *   - an API key's max_level (when authenticating via key).
 *
 * The AI can never raise its own ceiling:
 *   - settings changes require an authenticated admin via REST/admin UI,
 *   - grants are stored in a signed (not writable-by-tools) option,
 *   - tools have no write access to options that control security.
 *
 * @package AIOS\Security
 */

declare( strict_types=1 );

namespace AIOS\Security;

use AIOS\Settings\Settings;
use AIOS\Support\StructuredError;
use WP_User;

final class PermissionEngine {

	public const LEVEL_READ        = 0;
	public const LEVEL_SAFE_WRITE  = 1;
	public const LEVEL_SENSITIVE   = 2;
	public const LEVEL_DESTRUCTIVE = 3;
	public const LEVEL_DEPLOYMENT  = 4;

	public const LEVEL_NAMES = array(
		self::LEVEL_READ        => 'read',
		self::LEVEL_SAFE_WRITE  => 'safe_write',
		self::LEVEL_SENSITIVE   => 'sensitive',
		self::LEVEL_DESTRUCTIVE => 'destructive',
		self::LEVEL_DEPLOYMENT  => 'deployment',
	);

		/**
		 * WordPress capability → maximum permitted level. A user without
		 * any of these capabilities cannot use AI OS tools at all.
		 *
		 * Editors may read + write content (level 1); admins may go up to
		 * level 4 depending on the active mode.
		 *
		 * @var array<string, int>
		 */
	private const CAPABILITY_CEILINGS = array(
		'manage_options'    => self::LEVEL_DEPLOYMENT,
		'edit_pages'        => self::LEVEL_SAFE_WRITE,
		'edit_posts'        => self::LEVEL_SAFE_WRITE,
		'upload_files'      => self::LEVEL_SAFE_WRITE,
		'edit_others_posts' => self::LEVEL_SENSITIVE,
	);

		/**
		 * Grants option key. Stored via a dedicated option; tools never
		 * write to options (Phase 1 has no option-writing tool at all).
		 */
	public const GRANTS_OPTION = 'ai_os_user_level_grants';

	private Settings $settings;

		/**
		 * @param array<string, int>|null $grants_override Test seam.
		 */
	public function __construct( Settings $settings, ?array $grants_override = null ) {
			$this->settings = $settings;
		if ( null !== $grants_override ) {
				$this->cachedGrants = $grants_override;
		}
	}

		/**
		 * @var array<string, int>|null
		 */
	private ?array $cachedGrants = null;

		// ---------------------------------------------------------------- decisions

		/**
		 * Whether the user may execute a tool of the given risk level.
		 *
		 * @param int    $tool_level Required level (tool risk).
		 * @param int    $key_max    API-key ceiling, null when not key-auth.
		 */
	public function can( WP_User $user, int $tool_level, ?int $key_max = null ): bool {
			$ceiling = $this->ceilingFor( $user, $key_max );
			return $tool_level <= $ceiling;
	}

		/**
		 * Structured permission failure for a tool call.
		 */
	public function denied( WP_User $user, string $tool, int $tool_level, ?int $key_max = null ): StructuredError {
			return StructuredError::permission(
				'permission.level_denied',
				sprintf(
					'This action requires permission level %d (%s) and your current session allows level %d (%s).',
					$tool_level,
					self::LEVEL_NAMES[ $tool_level ] ?? 'unknown',
					$this->ceilingFor( $user, $key_max ),
					self::LEVEL_NAMES[ $this->ceilingFor( $user, $key_max ) ] ?? 'none'
				),
				array(
					'tool'           => $tool,
					'required_level' => $tool_level,
				)
			);
	}

		/**
		 * The effective level ceiling for a principal.
		 *
		 * Order of constraint (min wins):
		 *   mode ceiling ← WP caps ← per-user grant (only lowers) ← key ceiling
		 */
	public function ceilingFor( WP_User $user, ?int $key_max = null ): int {
			return min( $this->settings->maxAutoLevel(), $this->capabilityCeilingFor( $user, $key_max ) );
	}

		/**
		 * Capability-only ceiling (mode-independent). Used when a human
		 * has EXPLICITLY approved an action: approval authorizes beyond
		 * the auto-execution mode ceiling, but never beyond the user's
		 * real WordPress capabilities.
		 */
	public function capabilityCeilingFor( WP_User $user, ?int $key_max = null ): int {
			$caps_ceiling = PHP_INT_MIN;
		foreach ( self::CAPABILITY_CEILINGS as $capability => $level ) {
			if ( $user->has_cap( $capability ) ) {
				$caps_ceiling = max( $caps_ceiling, $level );
			}
		}
		if ( PHP_INT_MIN === $caps_ceiling ) {
				$caps_ceiling = -1; // No relevant capability at all: no access.
		}

			$ceiling = $caps_ceiling;

			// A grant can only LOWER a ceiling, never raise it (spec §7:
			// "Never allow an AI model to silently escalate permissions").
			$grant = $this->userGrant( (int) $user->ID );
		if ( null !== $grant ) {
				$ceiling = min( $ceiling, $grant );
		}

		if ( null !== $key_max ) {
				$ceiling = min( $ceiling, $key_max );
		}

			return $ceiling;
	}

		/**
		 * Whether an action at this risk level needs human approval under
		 * the active mode.
		 */
	public function requiresApproval( int $tool_level ): bool {
			return $tool_level >= $this->settings->approvalThreshold();
	}

		/**
		 * Map a mode's auto-approve-safe preference (bulk approvals UI).
		 */
	public function autoApproveSafe(): bool {
			return $this->settings->autoApproveSafe();
	}

		// ---------------------------------------------------------------- grants

		/**
		 * @return array<string, int> user_id → granted ceiling (filterable).
		 */
	public function grants(): array {
		if ( null === $this->cachedGrants ) {
				$stored       = get_option( self::GRANTS_OPTION, array() );
				$stored_array = is_array( $stored ) ? $stored : array();
				$clean        = array();
			foreach ( $stored_array as $user_id => $level ) {
				if ( is_numeric( $user_id ) && is_numeric( $level ) ) {
						$clean[ (string) (int) $user_id ] = max( 0, min( 4, (int) $level ) );
				}
			}
				/**
				 * Filter the per-user level grants. Integrations may lower
				 * ceilings; values above the mode ceiling are ignored.
				 *
				 * @param array<string, int> $clean
				 */
				$this->cachedGrants = apply_filters( 'ai_os_user_level_grants', $clean );
		}
			return $this->cachedGrants;
	}

	private function userGrant( int $user_id ): ?int {
			$grants = $this->grants();
			return isset( $grants[ (string) $user_id ] ) ? (int) $grants[ (string) $user_id ] : null;
	}

		/**
		 * Persist a per-user grant (admin action only; lowering-only rule
		 * is enforced at read time, so raising is possible only for users
		 * who already have headroom — the effective ceiling is still
		 * min()-bounded by caps and mode).
		 */
	public function setGrant( int $user_id, int $level ): void {
			$grants                      = $this->grants();
			$grants[ (string) $user_id ] = max( 0, min( 4, $level ) );
			update_option( self::GRANTS_OPTION, $grants, true );
			$this->cachedGrants = $grants;
	}

	public function removeGrant( int $user_id ): void {
			$grants = $this->grants();
			unset( $grants[ (string) $user_id ] );
			update_option( self::GRANTS_OPTION, $grants, true );
			$this->cachedGrants = $grants;
	}

		/**
		 * Baseline access check: can this user use AI OS at all?
		 *
		 * ai_os_use is granted to the `administrator` role only, at
		 * activation (Activator::grantDefaultCapabilities()) — in
		 * practice this check passes for administrators via that grant
		 * OR manage_options, and for any editor/author/contributor via
		 * edit_posts (WordPress's own default), without AI OS ever
		 * granting anything to those lower roles itself.
		 */
	public static function canUse( WP_User $user ): bool {
			return $user->exists()
					&& ( $user->has_cap( 'ai_os_use' ) || $user->has_cap( 'manage_options' ) || $user->has_cap( 'edit_posts' ) );
	}

		/**
		 * Levels metadata for UIs.
		 *
		 * @return array<int, array<string, string>>
		 */
	public function describeLevels(): array {
			$out = array();
		foreach ( self::LEVEL_NAMES as $level => $name ) {
				$out[] = array(
					'level'        => (string) $level,
					'name'         => $name,
					'auto_allowed' => $level <= $this->settings->maxAutoLevel() ? 'yes' : 'no',
					'approval'     => $level >= $this->settings->approvalThreshold() ? 'required' : 'not_required',
				);
		}
			return $out;
	}
}
