<?php
/**
 * Overall outcome of running a ChangeSet through MutationEngine.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

final class MutationResult {

	public const STATUS_APPLIED             = 'applied';
	public const STATUS_APPROVAL_REQUIRED   = 'approval_required';
	public const STATUS_POLICY_DENIED       = 'policy_denied';
	public const STATUS_SNAPSHOT_FAILED     = 'snapshot_failed';
	public const STATUS_APPLY_FAILED        = 'apply_failed';
	public const STATUS_VERIFICATION_FAILED = 'verification_failed';
	public const STATUS_ROLLED_BACK         = 'rolled_back';
	public const STATUS_ROLLBACK_FAILED     = 'rollback_failed';
	public const STATUS_REJECTED            = 'rejected';
	public const STATUS_STALE_STATE         = 'stale_state';
	public const STATUS_FINGERPRINT_MISMATCH = 'fingerprint_mismatch';
	public const STATUS_ALREADY_COMPLETED   = 'already_completed';

	/**
	 * @param VerificationResult[] $verifications
	 * @param RollbackRecord[]     $rollbacks
	 */
	private function __construct(
		private readonly string $status,
		private readonly string $changeSetId,
		private readonly ?int $approvalId,
		private readonly array $verifications,
		private readonly array $rollbacks,
		private readonly ?string $error
	) {}

	public static function applied( string $change_set_id, array $verifications ): self {
		return new self( self::STATUS_APPLIED, $change_set_id, null, $verifications, array(), null );
	}

	public static function approvalRequired( string $change_set_id, int $approval_id ): self {
		return new self( self::STATUS_APPROVAL_REQUIRED, $change_set_id, $approval_id, array(), array(), null );
	}

	public static function policyDenied( string $change_set_id, string $error ): self {
		return new self( self::STATUS_POLICY_DENIED, $change_set_id, null, array(), array(), $error );
	}

	public static function rejected( string $change_set_id, string $error ): self {
		return new self( self::STATUS_REJECTED, $change_set_id, null, array(), array(), $error );
	}

	public static function snapshotFailed( string $change_set_id, string $error ): self {
		return new self( self::STATUS_SNAPSHOT_FAILED, $change_set_id, null, array(), array(), $error );
	}

	public static function applyFailed( string $change_set_id, string $error, array $rollbacks ): self {
		return new self( self::STATUS_APPLY_FAILED, $change_set_id, null, array(), $rollbacks, $error );
	}

	public static function verificationFailed( string $change_set_id, array $verifications, array $rollbacks ): self {
		return new self( self::STATUS_VERIFICATION_FAILED, $change_set_id, null, $verifications, $rollbacks, 'one or more operations failed verification' );
	}

	public static function rolledBack( string $change_set_id, array $verifications, array $rollbacks ): self {
		return new self( self::STATUS_ROLLED_BACK, $change_set_id, null, $verifications, $rollbacks, null );
	}

	public static function rollbackFailed( string $change_set_id, array $verifications, array $rollbacks, string $error ): self {
		return new self( self::STATUS_ROLLBACK_FAILED, $change_set_id, null, $verifications, $rollbacks, $error );
	}

	public static function staleState( string $change_set_id, string $error, array $rollbacks = array() ): self {
		return new self( self::STATUS_STALE_STATE, $change_set_id, null, array(), $rollbacks, $error );
	}

	public static function fingerprintMismatch( string $change_set_id, string $error ): self {
		return new self( self::STATUS_FINGERPRINT_MISMATCH, $change_set_id, null, array(), array(), $error );
	}

	public static function alreadyCompleted( string $change_set_id ): self {
		return new self( self::STATUS_ALREADY_COMPLETED, $change_set_id, null, array(), array(), null );
	}

	public function ok(): bool {
		return self::STATUS_APPLIED === $this->status;
	}

	public function status(): string {
		return $this->status;
	}

	public function changeSetId(): string {
		return $this->changeSetId;
	}

	public function approvalId(): ?int {
		return $this->approvalId;
	}

	/**
	 * @return VerificationResult[]
	 */
	public function verifications(): array {
		return $this->verifications;
	}

	/**
	 * @return RollbackRecord[]
	 */
	public function rollbacks(): array {
		return $this->rollbacks;
	}

	public function error(): ?string {
		return $this->error;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'status'        => $this->status,
			'change_set_id' => $this->changeSetId,
			'approval_id'   => $this->approvalId,
			'verifications' => array_map( static fn( VerificationResult $v ): array => $v->toArray(), $this->verifications ),
			'rollbacks'     => array_map( static fn( RollbackRecord $r ): array => $r->toArray(), $this->rollbacks ),
			'error'         => $this->error,
		);
	}
}
