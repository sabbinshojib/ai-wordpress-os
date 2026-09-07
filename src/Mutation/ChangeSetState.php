<?php
/**
 * ChangeSet lifecycle state machine. This is the single authority for
 * what state transition is legal — nothing outside this class may
 * decide that; MutationEngine and (once durable persistence exists)
 * ChangeSetRepository both call assertTransition() before writing a
 * new status anywhere, and fail closed (MutationException) on an
 * illegal one rather than silently accepting it.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

final class ChangeSetState {

	public const PLANNED           = 'planned';
	public const POLICY_REJECTED   = 'policy_rejected';
	public const SNAPSHOTTED       = 'snapshotted';
	public const DIFF_READY        = 'diff_ready';
	public const PENDING_APPROVAL  = 'pending_approval';
	public const APPROVED          = 'approved';
	public const APPLYING          = 'applying';
	public const VERIFYING         = 'verifying';
	public const COMPLETED         = 'completed';
	public const FAILED            = 'failed';
	public const STALE             = 'stale';
	public const ROLLBACK_REQUIRED = 'rollback_required';
	public const ROLLING_BACK      = 'rolling_back';
	public const ROLLED_BACK       = 'rolled_back';
	public const ROLLBACK_FAILED   = 'rollback_failed';
	public const EXPIRED           = 'expired';
	public const CANCELLED         = 'cancelled';
	public const REJECTED          = 'rejected';

	/**
	 * from => [allowed to...]. A state absent from this map (or mapped
	 * to an empty list) is TERMINAL: no further transition is ever legal.
	 *
	 * @var array<string, string[]>
	 */
	private const TRANSITIONS = array(
		self::PLANNED           => array( self::POLICY_REJECTED, self::SNAPSHOTTED ),
		self::SNAPSHOTTED       => array( self::DIFF_READY, self::FAILED ),
		self::DIFF_READY        => array( self::PENDING_APPROVAL, self::APPLYING ),
		self::PENDING_APPROVAL  => array( self::APPROVED, self::REJECTED, self::CANCELLED, self::EXPIRED ),
		self::APPROVED          => array( self::APPLYING, self::STALE ),
		self::APPLYING          => array( self::VERIFYING, self::FAILED ),
		self::VERIFYING         => array( self::COMPLETED, self::ROLLBACK_REQUIRED ),
		self::FAILED            => array( self::ROLLBACK_REQUIRED ),
		self::STALE             => array(),
		self::ROLLBACK_REQUIRED => array( self::ROLLING_BACK ),
		self::ROLLING_BACK      => array( self::ROLLED_BACK, self::ROLLBACK_FAILED ),
	);

	/**
	 * @throws MutationException With code "changeset.illegal_transition" when $from -> $to is not permitted.
	 */
	public static function assertTransition( string $from, string $to ): void {
		if ( ! self::isValidTransition( $from, $to ) ) {
			throw new MutationException(
				'changeset.illegal_transition',
				sprintf( 'Illegal ChangeSet state transition: "%s" -> "%s".', $from, $to )
			);
		}
	}

	public static function isValidTransition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	public static function isTerminal( string $state ): bool {
		return array() === ( self::TRANSITIONS[ $state ] ?? array() );
	}

	/**
	 * @return string[] Every recognized state.
	 */
	public static function all(): array {
		$states = array_keys( self::TRANSITIONS );
		foreach ( self::TRANSITIONS as $targets ) {
			$states = array_merge( $states, $targets );
		}
		return array_values( array_unique( $states ) );
	}
}
