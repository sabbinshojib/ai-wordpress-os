<?php
/**
 * Immutable pre-state capture for one ChangeOperation, taken before
 * Apply. `state` is an opaque payload — only the operation type that
 * produced it knows how to interpret it for rollback(); the pipeline
 * itself never inspects or mutates it.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

final class Snapshot {

	private string $id;

	private string $operationId;

	private string $operationType;

	/** @var array<string, mixed> */
	private array $state;

	private string $capturedAt;

	/**
	 * @param array<string, mixed> $state
	 */
	public function __construct( string $operation_id, string $operation_type, array $state ) {
		$this->id            = 'snap_' . bin2hex( random_bytes( 12 ) );
		$this->operationId   = $operation_id;
		$this->operationType = $operation_type;
		$this->state         = $state;
		$this->capturedAt    = function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	}

	public function id(): string {
		return $this->id;
	}

	public function operationId(): string {
		return $this->operationId;
	}

	public function operationType(): string {
		return $this->operationType;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function state(): array {
		return $this->state;
	}

	public function capturedAt(): string {
		return $this->capturedAt;
	}
}
