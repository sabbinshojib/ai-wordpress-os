<?php
/**
 * Replace the full content of an EXISTING file with new content.
 *
 * Scope note: this is a whole-file atomic replace, not a unified-
 * diff/patch-format applier. Whatever produced the ChangeSet (a
 * future Planner, or an admin UI) is responsible for computing the
 * new full content — AIOS\Support\Diff is available for rendering a
 * human-reviewable before/after at the Diff pipeline step. A true
 * line-based patch-application algorithm is out of scope for this
 * foundation; naming it "Patch" reflects intent (the operation type
 * the pipeline exposes), not a partial-file-edit implementation.
 *
 * Confined by PathGuard::resolveWrite(); the write itself is atomic
 * (write to a sibling temp file, then rename — POSIX rename() is
 * atomic on the same filesystem, and Windows' rename() over an
 * existing destination is handled by first removing the destination,
 * matching this project's existing single-process, non-concurrent-
 * write assumption elsewhere in the codebase).
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

final class FilePatchOperation extends AbstractOperation {

	public const TYPE = 'file.patch';

	private string $resolvedPath;

	public function __construct(
		private readonly string $path,
		private readonly string $newContent,
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
		return array( 'path' => $this->path, 'new_bytes' => strlen( $this->newContent ) );
	}

	public function captureSnapshot(): Snapshot {
		try {
			$this->resolvedPath = $this->pathGuard->resolveWrite( $this->path );
		} catch ( PathGuardException $e ) {
			throw new MutationException( 'file_patch.path_denied', $e->getMessage(), $e );
		}
		if ( ! file_exists( $this->resolvedPath ) ) {
			throw new MutationException( 'file_patch.not_found', 'The target file does not exist; use FileCreateOperation to create it.' );
		}
		$original = file_get_contents( $this->resolvedPath );
		if ( false === $original ) {
			throw new MutationException( 'file_patch.read_failed', 'Failed to read the existing file content for snapshot.' );
		}
		return new Snapshot( $this->id, self::TYPE, array( 'path' => $this->resolvedPath, 'original_content' => $original ) );
	}

	public function apply(): void {
		$temp = $this->resolvedPath . '.aios-tmp-' . bin2hex( random_bytes( 6 ) );
		if ( false === file_put_contents( $temp, $this->newContent, LOCK_EX ) ) {
			throw new MutationException( 'file_patch.write_failed', 'Failed to write the replacement content.' );
		}
		if ( ! @rename( $temp, $this->resolvedPath ) ) {
			@unlink( $temp );
			throw new MutationException( 'file_patch.rename_failed', 'Failed to atomically replace the file.' );
		}
	}

	public function verify(): VerificationResult {
		$actual = file_get_contents( $this->resolvedPath );
		if ( false === $actual || $actual !== $this->newContent ) {
			return VerificationResult::failure( $this->id, 'file content does not match the intended content after apply()' );
		}
		return VerificationResult::success( $this->id );
	}

	public function rollback( Snapshot $snapshot ): RollbackRecord {
		$path     = (string) ( $snapshot->state()['path'] ?? $this->resolvedPath );
		$original = (string) ( $snapshot->state()['original_content'] ?? '' );
		if ( false === file_put_contents( $path, $original, LOCK_EX ) ) {
			return RollbackRecord::failure( $this->id, $snapshot->id(), 'failed to restore the original file content during rollback' );
		}
		return RollbackRecord::success( $this->id, $snapshot->id() );
	}
}
