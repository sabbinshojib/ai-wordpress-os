<?php
/**
 * Update a single post-meta key. The lowest-risk operation type in
 * this foundation — reversible, single-key, no filesystem or option
 * access.
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

final class MetadataUpdateOperation extends AbstractOperation {

	public const TYPE = 'post.metadata_update';

	public function __construct(
		private readonly int $postId,
		private readonly string $metaKey,
		private readonly mixed $newValue
	) {
		parent::__construct();
		if ( $postId <= 0 ) {
			throw new \InvalidArgumentException( 'A valid postId is required.' );
		}
		if ( '' === trim( $metaKey ) ) {
			throw new \InvalidArgumentException( 'A meta key is required.' );
		}
	}

	public function type(): string {
		return self::TYPE;
	}

	public function target(): string {
		return sprintf( 'post:%d/%s', $this->postId, $this->metaKey );
	}

	public function riskLevel(): int {
		return PermissionEngine::LEVEL_SAFE_WRITE;
	}

	public function describe(): array {
		return array( 'post_id' => $this->postId, 'meta_key' => $this->metaKey );
	}

	public function captureSnapshot(): Snapshot {
		if ( null === get_post( $this->postId ) ) {
			throw new MutationException( 'metadata_update.not_found', 'The target post does not exist.' );
		}
		$existing = get_post_meta( $this->postId, $this->metaKey );
		$existed  = array() !== $existing;
		$original = $existed ? ( $existing[0] ?? null ) : null;
		return new Snapshot(
			$this->id,
			self::TYPE,
			array(
				'post_id'       => $this->postId,
				'meta_key'      => $this->metaKey,
				'existed'       => $existed,
				'original_value' => $original,
				'precondition'  => $existed ? self::fingerprintOf( $original ) : self::absentFingerprint(),
			)
		);
	}

	public function apply(): void {
		if ( ! update_post_meta( $this->postId, $this->metaKey, $this->newValue ) ) {
			throw new MutationException( 'metadata_update.failed', 'Failed to update the post meta value.' );
		}
	}

	public function verify(): VerificationResult {
		$actual = get_post_meta( $this->postId, $this->metaKey, true );
		if ( $actual !== $this->newValue ) {
			return VerificationResult::failure( $this->id, 'meta value does not match the intended value after apply()' );
		}
		return VerificationResult::success( $this->id );
	}

	public function rollback( Snapshot $snapshot ): RollbackRecord {
		$state    = $snapshot->state();
		$post_id  = (int) ( $state['post_id'] ?? $this->postId );
		$meta_key = (string) ( $state['meta_key'] ?? $this->metaKey );
		if ( true === ( $state['existed'] ?? false ) ) {
			if ( ! update_post_meta( $post_id, $meta_key, $state['original_value'] ) ) {
				return RollbackRecord::failure( $this->id, $snapshot->id(), 'failed to restore the original meta value during rollback' );
			}
		} elseif ( ! delete_post_meta( $post_id, $meta_key ) ) {
			return RollbackRecord::failure( $this->id, $snapshot->id(), 'failed to remove the newly-created meta value during rollback' );
		}
		return RollbackRecord::success( $this->id, $snapshot->id() );
	}

	public function payloadFingerprint(): string {
		return self::fingerprintOf( array( 'post_id' => $this->postId, 'meta_key' => $this->metaKey, 'value' => $this->newValue ) );
	}

	public function currentPreconditionFingerprint(): string {
		if ( null === get_post( $this->postId ) ) {
			return self::absentFingerprint();
		}
		$existing = get_post_meta( $this->postId, $this->metaKey );
		if ( array() === $existing ) {
			return self::absentFingerprint();
		}
		return self::fingerprintOf( $existing[0] ?? null );
	}

	public function intendedValue(): mixed {
		return $this->newValue;
	}
}
