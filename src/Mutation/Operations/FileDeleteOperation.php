<?php
/**
 * Delete an existing file. Treated as the highest of this
 * foundation's operation risk levels (DESTRUCTIVE): unlike create/
 * patch, the pre-state (full file content) is captured specifically
 * so rollback can genuinely recreate the file, not merely note that
 * it once existed.
 *
 * Confined by PathGuard::resolveWrite().
 *
 * @package AIOS\Mutation\Operations
 */

declare( strict_types=1 );

namespace AIOS\Mutation\Operations;

use AIOS\Mutation\MutationException;
use AIOS\Mutation\RollbackRecord;
use AIOS\Mutation\Snapshot;
use AIOS\Mutation\VerificationResult;
use AIOS\Security\PathGuard;
use AIOS\Security\PathGuardException;
use AIOS\Security\PermissionEngine;

final class FileDeleteOperation extends AbstractOperation {

	public const TYPE = 'file.delete';

	private string $resolvedPath;

	public function __construct(
		private readonly string $path,
		private readonly PathGuard $pathGuard
	) {
		parent::__construct();
	}

	public function type(): string {
		return self::TYPE;
	}

	public function target(): string {
		return $this->path;
	}

	public function riskLevel(): int {
		return PermissionEngine::LEVEL_DESTRUCTIVE;
	}

	public function describe(): array {
		return array( 'path' => $this->path );
	}

	public function captureSnapshot(): Snapshot {
		try {
			$this->resolvedPath = $this->pathGuard->resolveWrite( $this->path );
		} catch ( PathGuardException $e ) {
			throw new MutationException( 'file_delete.path_denied', $e->getMessage(), $e );
		}
		if ( ! file_exists( $this->resolvedPath ) ) {
			throw new MutationException( 'file_delete.not_found', 'The target file does not exist.' );
		}
		$original = file_get_contents( $this->resolvedPath );
		if ( false === $original ) {
			throw new MutationException( 'file_delete.read_failed', 'Failed to read the file content before deletion.' );
		}
		return new Snapshot(
			$this->id,
			self::TYPE,
			array(
				'path'             => $this->resolvedPath,
				'original_content' => $original,
				'precondition'     => self::fingerprintOf( $original ),
			)
		);
	}

	public function apply(): void {
		if ( ! wp_delete_file( $this->resolvedPath ) ) {
			throw new MutationException( 'file_delete.failed', 'Failed to delete the file.' );
		}
	}

	public function verify(): VerificationResult {
		if ( file_exists( $this->resolvedPath ) ) {
			return VerificationResult::failure( $this->id, 'file still exists after apply()' );
		}
		return VerificationResult::success( $this->id );
	}

	public function rollback( Snapshot $snapshot ): RollbackRecord {
		$path     = (string) ( $snapshot->state()['path'] ?? $this->resolvedPath );
		$original = (string) ( $snapshot->state()['original_content'] ?? '' );
		if ( false === file_put_contents( $path, $original, LOCK_EX ) ) {
			return RollbackRecord::failure( $this->id, $snapshot->id(), 'failed to recreate the deleted file during rollback' );
		}
		return RollbackRecord::success( $this->id, $snapshot->id() );
	}

	public function payloadFingerprint(): string {
		return self::fingerprintOf(
			array(
				'path'   => $this->path,
				'action' => 'delete',
			)
		);
	}

	public function currentPreconditionFingerprint(): string {
		$path = $this->resolvedPath ?? $this->path;
		if ( ! file_exists( $path ) ) {
			return self::absentFingerprint();
		}
		$content = file_get_contents( $path );
		return self::fingerprintOf( false === $content ? null : $content );
	}

	public function intendedValue(): null {
		return null; // Deletion has no "new content" — the diff represents this as pure removal.
	}

	public function toSpec(): array {
		return array( 'path' => $this->path );
	}
}
