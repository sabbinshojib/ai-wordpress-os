<?php
/**
 * Closed whitelist of Phase 3 developer capabilities.
 *
 * Explicitly defines the permitted developer capability identifiers and
 * their baseline permission levels. Arbitrary shell, SQL, eval, or
 * wildcard capabilities are structurally rejected.
 *
 * @package AIOS\Developer\Capability
 */

declare( strict_types=1 );

namespace AIOS\Developer\Capability;

use AIOS\Security\PermissionEngine;

final class DeveloperCapability {

	public const INSPECT_REPO     = 'developer.repo.inspect';
	public const CREATE_PLAN      = 'developer.plan.create';
	public const GENERATE_DIFF    = 'developer.diff.generate';
	public const RUN_TEST         = 'developer.test.run';
	public const DIAGNOSE_FAILURE = 'developer.failure.diagnose';
	public const PROPOSE_REPAIR   = 'developer.repair.propose';
	public const INSPECT_GIT      = 'developer.git.inspect';
	public const PATCH_FILE       = 'developer.file.patch';
	public const COMMIT_GIT       = 'developer.git.commit';
	public const PUSH_GIT         = 'developer.git.push';

	/**
	 * Whitelist of all permitted developer capabilities.
	 *
	 * @var string[]
	 */
	public const ALL = array(
		self::INSPECT_REPO,
		self::CREATE_PLAN,
		self::GENERATE_DIFF,
		self::RUN_TEST,
		self::DIAGNOSE_FAILURE,
		self::PROPOSE_REPAIR,
		self::INSPECT_GIT,
		self::PATCH_FILE,
		self::COMMIT_GIT,
		self::PUSH_GIT,
	);

	/**
	 * Mapping of developer capability to required PermissionEngine level.
	 *
	 * @var array<string, int>
	 */
	public const LEVEL_MAP = array(
		self::INSPECT_REPO     => PermissionEngine::LEVEL_READ,
		self::CREATE_PLAN      => PermissionEngine::LEVEL_READ,
		self::GENERATE_DIFF    => PermissionEngine::LEVEL_READ,
		self::RUN_TEST         => PermissionEngine::LEVEL_SAFE_WRITE,
		self::DIAGNOSE_FAILURE => PermissionEngine::LEVEL_READ,
		self::PROPOSE_REPAIR   => PermissionEngine::LEVEL_READ,
		self::INSPECT_GIT      => PermissionEngine::LEVEL_READ,
		self::PATCH_FILE       => PermissionEngine::LEVEL_SENSITIVE,
		self::COMMIT_GIT       => PermissionEngine::LEVEL_SENSITIVE,
		self::PUSH_GIT         => PermissionEngine::LEVEL_DEPLOYMENT,
	);

	/**
	 * Check whether a capability identifier is supported.
	 * Wildcards, shell execution, and unrecognized strings return false.
	 *
	 * @param string $capability The capability to check.
	 * @return bool True if recognized and approved.
	 */
	public static function isSupported( string $capability ): bool {
		// Reject wildcards and dangerous patterns explicitly.
		if ( str_contains( $capability, '*' ) || str_contains( $capability, 'shell' ) || str_contains( $capability, 'eval' ) || str_contains( $capability, 'sql' ) ) {
			return false;
		}

		return in_array( $capability, self::ALL, true );
	}

	/**
	 * Assert that a capability is valid and supported.
	 *
	 * @param string $capability The capability identifier.
	 * @throws \InvalidArgumentException If capability is unsupported or denied.
	 */
	public static function assertSupported( string $capability ): void {
		if ( ! self::isSupported( $capability ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Unsupported or denied developer capability: %s', $capability )
			);
		}
	}

	/**
	 * Retrieve the permission level required for a developer capability.
	 *
	 * @param string $capability The capability identifier.
	 * @return int The required PermissionEngine level.
	 * @throws \InvalidArgumentException If capability is unknown.
	 */
	public static function levelFor( string $capability ): int {
		self::assertSupported( $capability );

		return self::LEVEL_MAP[ $capability ];
	}
}
