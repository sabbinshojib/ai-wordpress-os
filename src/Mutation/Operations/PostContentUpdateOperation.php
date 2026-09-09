<?php
/**
 * Update one or more content fields (title/content/excerpt) of an
 * existing post via wp_update_post() — the same WordPress API Phase
 * 1's content.update_post tool already uses; this operation type
 * exists so a post-content change can also be expressed as a Phase 2
 * ChangeSet operation (e.g. as one step alongside a file change in
 * the same reviewable unit), not as a replacement for the Phase 1
 * tool.
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

final class PostContentUpdateOperation extends AbstractOperation {

	public const TYPE = 'post.content_update';

	/**
	 * @var string[]
	 */
	private const ALLOWED_FIELDS = array( 'post_title', 'post_content', 'post_excerpt' );

	/**
	 * @param array<string, string> $fields One or more of post_title/post_content/post_excerpt.
	 */
	public function __construct(
		private readonly int $postId,
		private readonly array $fields
	) {
		parent::__construct();
		if ( $postId <= 0 ) {
			throw new \InvalidArgumentException( 'A valid postId is required.' );
		}
		if ( array() === $fields ) {
			throw new \InvalidArgumentException( 'At least one field must be provided.' );
		}
		foreach ( array_keys( $fields ) as $field ) {
			if ( ! in_array( $field, self::ALLOWED_FIELDS, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Field "%s" is not one of the allowed post-content fields.', (string) $field ) );
			}
		}
	}

	public function type(): string {
		return self::TYPE;
	}

	public function target(): string {
		return (string) $this->postId;
	}

	public function riskLevel(): int {
		return PermissionEngine::LEVEL_SAFE_WRITE;
	}

	public function describe(): array {
		return array(
			'post_id' => $this->postId,
			'fields'  => array_keys( $this->fields ),
		);
	}

	public function captureSnapshot(): Snapshot {
		$post = get_post( $this->postId );
		if ( null === $post ) {
			throw new MutationException( 'post_content_update.not_found', 'The target post does not exist.' );
		}
		$original = array();
		foreach ( array_keys( $this->fields ) as $field ) {
			$original[ $field ] = $post->{$field} ?? '';
		}
		return new Snapshot(
			$this->id,
			self::TYPE,
			array(
				'post_id'      => $this->postId,
				'original'     => $original,
				'precondition' => self::fingerprintOf( $original ),
			)
		);
	}

	public function apply(): void {
		/** @var array{ID: int, post_title?: string, post_content?: string, post_excerpt?: string} $postarr */
		$postarr = array_merge( array( 'ID' => $this->postId ), $this->fields );
		$result  = wp_update_post( $postarr, true );
		if ( $result instanceof \WP_Error ) {
			throw new MutationException( 'post_content_update.failed', 'Failed to update the post.' );
		}
	}

	public function verify(): VerificationResult {
		$post = get_post( $this->postId );
		if ( null === $post ) {
			return VerificationResult::failure( $this->id, 'post no longer exists after apply()' );
		}
		foreach ( $this->fields as $field => $expected ) {
			if ( ( $post->{$field} ?? null ) !== $expected ) {
				return VerificationResult::failure( $this->id, sprintf( 'field "%s" does not match the intended value after apply()', $field ) );
			}
		}
		return VerificationResult::success( $this->id );
	}

	public function rollback( Snapshot $snapshot ): RollbackRecord {
		$state    = $snapshot->state();
		$post_id  = (int) ( $state['post_id'] ?? $this->postId );
		$original = (array) ( $state['original'] ?? array() );
		/** @var array{ID: int, post_title?: string, post_content?: string, post_excerpt?: string} $postarr */
		$postarr = array_merge( array( 'ID' => $post_id ), $original );
		$result  = wp_update_post( $postarr, true );
		if ( $result instanceof \WP_Error ) {
			return RollbackRecord::failure( $this->id, $snapshot->id(), 'failed to restore the original post fields during rollback' );
		}
		return RollbackRecord::success( $this->id, $snapshot->id() );
	}

	public function payloadFingerprint(): string {
		return self::fingerprintOf(
			array(
				'post_id' => $this->postId,
				'fields'  => $this->fields,
			)
		);
	}

	public function currentPreconditionFingerprint(): string {
		$post = get_post( $this->postId );
		if ( null === $post ) {
			return self::absentFingerprint();
		}
		$current = array();
		foreach ( array_keys( $this->fields ) as $field ) {
			$current[ $field ] = $post->{$field} ?? '';
		}
		return self::fingerprintOf( $current );
	}

	/**
	 * @return array<string, string>
	 */
	public function intendedValue(): array {
		return $this->fields;
	}

	public function toSpec(): array {
		return array(
			'post_id' => $this->postId,
			'fields'  => $this->fields,
		);
	}
}
