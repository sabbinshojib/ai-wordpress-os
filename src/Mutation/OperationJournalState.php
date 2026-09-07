<?php
/**
 * Per-operation lifecycle state machine (Sprint 0.3A Phase 2 final
 * hardening, Package D). Distinct from ChangeSetState (the whole
 * ChangeSet's lifecycle) — this tracks each individual operation
 * within a ChangeSet, which is what makes crash recovery for a
 * multi-operation ChangeSet possible: "operation 2 of 4 was APPLIED
 * when the process died" is not representable at ChangeSet
 * granularity alone.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

final class OperationJournalState {

	public const PENDING                  = 'pending';
	public const SNAPSHOTTED              = 'snapshotted';
	public const APPLYING                 = 'applying';
	public const APPLIED                  = 'applied';
	public const VERIFYING                = 'verifying';
	public const VERIFIED                 = 'verified';
	public const ROLLBACK_REQUIRED        = 'rollback_required';
	public const ROLLING_BACK             = 'rolling_back';
	public const ROLLED_BACK              = 'rolled_back';
	public const FAILED                   = 'failed';
	public const ROLLBACK_FAILED          = 'rollback_failed';
	public const MANUAL_RECOVERY_REQUIRED = 'manual_recovery_required';

	/**
	 * @var array<string, string[]>
	 */
	private const TRANSITIONS = array(
		self::PENDING           => array( self::SNAPSHOTTED, self::FAILED ),
		self::SNAPSHOTTED       => array( self::APPLYING, self::FAILED ),
		self::APPLYING          => array( self::APPLIED, self::FAILED ),
		// An operation that itself already succeeded (APPLIED/VERIFIED)
		// can still be told to roll back — a LATER sibling operation in
		// the same ChangeSet failing is what drives that, not anything
		// wrong with this operation itself.
		self::APPLIED           => array( self::VERIFYING, self::ROLLBACK_REQUIRED ),
		self::VERIFYING         => array( self::VERIFIED, self::ROLLBACK_REQUIRED ),
		self::VERIFIED          => array( self::ROLLBACK_REQUIRED ),
		self::ROLLBACK_REQUIRED => array( self::ROLLING_BACK ),
		self::ROLLING_BACK      => array( self::ROLLED_BACK, self::ROLLBACK_FAILED ),
		self::ROLLBACK_FAILED   => array( self::MANUAL_RECOVERY_REQUIRED ),
	);

	public static function assertTransition( string $from, string $to ): void {
		if ( ! self::isValidTransition( $from, $to ) ) {
			throw new MutationException(
				'operation_journal.illegal_transition',
				sprintf( 'Illegal operation journal state transition: "%s" -> "%s".', $from, $to )
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
	 * Whether an operation in this state was ever actually applied —
	 * the deciding fact for whether it needs a rollback attempt at all.
	 */
	public static function wasApplied( string $state ): bool {
		return in_array( $state, array( self::APPLIED, self::VERIFYING, self::VERIFIED, self::ROLLBACK_REQUIRED, self::ROLLING_BACK ), true );
	}

	/**
	 * @return string[]
	 */
	public static function all(): array {
		$states = array_keys( self::TRANSITIONS );
		foreach ( self::TRANSITIONS as $targets ) {
			$states = array_merge( $states, $targets );
		}
		return array_values( array_unique( $states ) );
	}
}
