<?php
/**
 * Developer Task State Machine.
 *
 * Defines the strict, explicit state transitions for a developer task.
 * Fails closed on any illegal transition or attempt to transition out
 * of a terminal state.
 *
 * @package AIOS\Developer\Plan
 */

declare( strict_types=1 );

namespace AIOS\Developer\Plan;

final class DeveloperTaskState {

	public const DRAFT             = 'draft';
	public const PLANNED           = 'planned';
	public const VALIDATING        = 'validating';
	public const AWAITING_APPROVAL = 'awaiting_approval';
	public const APPROVED          = 'approved';
	public const APPLYING          = 'applying';
	public const COMPLETED         = 'completed';
	public const FAILED            = 'failed';
	public const CANCELLED         = 'cancelled';

	/**
	 * Whitelist of all recognized states.
	 *
	 * @var string[]
	 */
	public const ALL = array(
		self::DRAFT,
		self::PLANNED,
		self::VALIDATING,
		self::AWAITING_APPROVAL,
		self::APPROVED,
		self::APPLYING,
		self::COMPLETED,
		self::FAILED,
		self::CANCELLED,
	);

	/**
	 * Whitelist of terminal states.
	 *
	 * @var string[]
	 */
	public const TERMINAL = array(
		self::COMPLETED,
		self::FAILED,
		self::CANCELLED,
	);

	/**
	 * Explicit graph of permitted transitions.
	 *
	 * @var array<string, string[]>
	 */
	private const TRANSITIONS = array(
		self::DRAFT             => array( self::PLANNED, self::CANCELLED ),
		self::PLANNED           => array( self::VALIDATING, self::AWAITING_APPROVAL, self::APPROVED, self::CANCELLED ),
		self::VALIDATING        => array( self::AWAITING_APPROVAL, self::APPROVED, self::FAILED, self::CANCELLED ),
		self::AWAITING_APPROVAL => array( self::APPROVED, self::CANCELLED, self::FAILED ),
		self::APPROVED          => array( self::APPLYING, self::CANCELLED ),
		self::APPLYING          => array( self::COMPLETED, self::FAILED ),
		self::COMPLETED         => array(),
		self::FAILED            => array(),
		self::CANCELLED         => array(),
	);

	/**
	 * Check whether a transition from one state to another is legal.
	 *
	 * @param string $from Source state.
	 * @param string $to   Target state.
	 * @return bool True if legal transition.
	 */
	public static function isLegal( string $from, string $to ): bool {
		if ( ! in_array( $from, self::ALL, true ) || ! in_array( $to, self::ALL, true ) ) {
			return false;
		}

		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	/**
	 * Assert that a state transition is legal, throwing on violation.
	 *
	 * @param string $from Source state.
	 * @param string $to   Target state.
	 * @throws \InvalidArgumentException If either state is unknown.
	 * @throws \DomainException If the transition is illegal or attempts to leave a terminal state.
	 */
	public static function assertLegal( string $from, string $to ): void {
		if ( ! in_array( $from, self::ALL, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown source developer task state: "%s"', $from ) );
		}
		if ( ! in_array( $to, self::ALL, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown target developer task state: "%s"', $to ) );
		}

		if ( self::isTerminal( $from ) ) {
			throw new \DomainException(
				sprintf( 'Cannot transition out of terminal state "%s" to "%s".', $from, $to )
			);
		}

		if ( ! self::isLegal( $from, $to ) ) {
			throw new \DomainException(
				sprintf( 'Illegal developer task transition from "%s" to "%s".', $from, $to )
			);
		}
	}

	/**
	 * Check whether a state is terminal (no further transitions allowed).
	 *
	 * @param string $state The state to check.
	 * @return bool True if terminal.
	 */
	public static function isTerminal( string $state ): bool {
		return in_array( $state, self::TERMINAL, true );
	}

	/**
	 * Get all defined states.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return self::ALL;
	}

	/**
	 * Get all terminal states.
	 *
	 * @return string[]
	 */
	public static function terminalStates(): array {
		return self::TERMINAL;
	}
}
