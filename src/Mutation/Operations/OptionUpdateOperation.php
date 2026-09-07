<?php
/**
 * Update (or create) a single WordPress option.
 *
 * Refuses, on construction, any option name AIOS\Mutation\MutationEngine
 * itself also blocklists at Policy time (`ai_os_settings`, the
 * PermissionEngine grants option) — defense in depth: this operation
 * is safe to construct and run even outside MutationEngine's own
 * Policy check.
 *
 * @package AIOS\Mutation\Operations
 */

declare( strict_types=1 );

namespace AIOS\Mutation\Operations;

use AIOS\Mutation\MutationException;
use AIOS\Mutation\RollbackRecord;
use AIOS\Mutation\Snapshot;
use AIOS\Mutation\VerificationResult;
use AIOS\Security\PermissionEngine;

final class OptionUpdateOperation extends AbstractOperation {

	public const TYPE = 'option.update';

	/**
	 * @var string[]
	 */
	private const PROTECTED_OPTIONS = array(
		'ai_os_settings',
	);

	public function __construct(
		private readonly string $optionName,
		private readonly mixed $newValue
	) {
		parent::__construct();
		if ( in_array( $optionName, self::PROTECTED_OPTIONS, true ) || PermissionEngine::GRANTS_OPTION === $optionName ) {
			throw new \InvalidArgumentException( 'This option is protected AI OS security state and cannot be targeted by a mutation operation.' );
		}
		if ( '' === trim( $optionName ) ) {
			throw new \InvalidArgumentException( 'An option name is required.' );
		}
	}

	public function type(): string {
		return self::TYPE;
	}

	public function target(): string {
		return $this->optionName;
	}

	public function riskLevel(): int {
		return PermissionEngine::LEVEL_SENSITIVE;
	}

	public function describe(): array {
		return array( 'option' => $this->optionName );
	}

	public function captureSnapshot(): Snapshot {
		$sentinel = "\0ai-os-mutation-option-absent\0";
		$existing = get_option( $this->optionName, $sentinel );
		$existed  = $sentinel !== $existing;
		return new Snapshot(
			$this->id,
			self::TYPE,
			array(
				'option'        => $this->optionName,
				'existed'       => $existed,
				'original_value' => $existed ? $existing : null,
				'precondition'  => $existed ? self::fingerprintOf( $existing ) : self::absentFingerprint(),
			)
		);
	}

	public function apply(): void {
		if ( ! update_option( $this->optionName, $this->newValue ) ) {
			throw new MutationException( 'option_update.failed', 'Failed to update the option.' );
		}
	}

	public function verify(): VerificationResult {
		$actual = get_option( $this->optionName );
		if ( $actual !== $this->newValue ) {
			return VerificationResult::failure( $this->id, 'option value does not match the intended value after apply()' );
		}
		return VerificationResult::success( $this->id );
	}

	public function rollback( Snapshot $snapshot ): RollbackRecord {
		$state = $snapshot->state();
		if ( true === ( $state['existed'] ?? false ) ) {
			if ( ! update_option( $this->optionName, $state['original_value'] ) ) {
				return RollbackRecord::failure( $this->id, $snapshot->id(), 'failed to restore the original option value during rollback' );
			}
		} elseif ( ! delete_option( $this->optionName ) ) {
			return RollbackRecord::failure( $this->id, $snapshot->id(), 'failed to remove the newly-created option during rollback' );
		}
		return RollbackRecord::success( $this->id, $snapshot->id() );
	}

	public function payloadFingerprint(): string {
		return self::fingerprintOf( array( 'option' => $this->optionName, 'value' => $this->newValue ) );
	}

	public function currentPreconditionFingerprint(): string {
		$sentinel = "\0ai-os-mutation-option-absent\0";
		$current  = get_option( $this->optionName, $sentinel );
		return $sentinel === $current ? self::absentFingerprint() : self::fingerprintOf( $current );
	}

	public function intendedValue(): mixed {
		return $this->newValue;
	}
}
