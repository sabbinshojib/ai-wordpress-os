<?php
/**
 * Outcome of restoring a Snapshot for one ChangeOperation.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

final class RollbackRecord {

	private function __construct(
		private readonly bool $ok,
		private readonly string $operationId,
		private readonly string $snapshotId,
		private readonly string $message,
		private readonly string $restoredAt
	) {}

	public static function success( string $operation_id, string $snapshot_id, string $message = 'rolled back' ): self {
		return new self( true, $operation_id, $snapshot_id, $message, self::now() );
	}

	public static function failure( string $operation_id, string $snapshot_id, string $message ): self {
		return new self( false, $operation_id, $snapshot_id, $message, self::now() );
	}

	private static function now(): string {
		return function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	}

	public function ok(): bool {
		return $this->ok;
	}

	public function operationId(): string {
		return $this->operationId;
	}

	public function snapshotId(): string {
		return $this->snapshotId;
	}

	public function message(): string {
		return $this->message;
	}

	public function restoredAt(): string {
		return $this->restoredAt;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'ok'           => $this->ok,
			'operation_id' => $this->operationId,
			'snapshot_id'  => $this->snapshotId,
			'message'      => $this->message,
			'restored_at'  => $this->restoredAt,
		);
	}
}
