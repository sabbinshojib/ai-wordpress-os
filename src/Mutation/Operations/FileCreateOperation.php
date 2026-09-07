<?php
/**
 * Create a NEW file that must not already exist. Confined by
 * PathGuard::resolveWrite() (root confinement, protected-file list,
 * extension allowlist, traversal/symlink checks) — the same class
 * that gates every Phase 1 file read.
 *
 * Does not create missing parent directories: the parent directory
 * must already exist. This is a deliberate scope limit for this
 * foundation, not an oversight.
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

final class FileCreateOperation extends AbstractOperation {

	public const TYPE = 'file.create';

	private string $resolvedPath;

	public function __construct(
		private readonly string $path,
		private readonly string $content,
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
		return PermissionEngine::LEVEL_SENSITIVE;
	}

	public function describe(): array {
		return array( 'path' => $this->path, 'bytes' => strlen( $this->content ) );
	}

	public function captureSnapshot(): Snapshot {
		try {
			$this->resolvedPath = $this->pathGuard->resolveWrite( $this->path );
		} catch ( PathGuardException $e ) {
			throw new MutationException( 'file_create.path_denied', $e->getMessage(), $e );
		}
		if ( file_exists( $this->resolvedPath ) ) {
			throw new MutationException( 'file_create.already_exists', 'A file already exists at this path; use FilePatchOperation to modify it.' );
		}
		// Nothing to back up — rollback is "delete the file this operation created".
		return new Snapshot( $this->id, self::TYPE, array( 'existed' => false, 'path' => $this->resolvedPath, 'precondition' => $this->currentPreconditionFingerprint() ) );
	}

	public function apply(): void {
		$dir = dirname( $this->resolvedPath );
		if ( ! is_dir( $dir ) ) {
			throw new MutationException( 'file_create.parent_missing', 'The parent directory does not exist.' );
		}
		$written = file_put_contents( $this->resolvedPath, $this->content, LOCK_EX );
		if ( false === $written ) {
			throw new MutationException( 'file_create.write_failed', 'Failed to write the new file.' );
		}
	}

	public function verify(): VerificationResult {
		if ( ! file_exists( $this->resolvedPath ) ) {
			return VerificationResult::failure( $this->id, 'file does not exist after apply()' );
		}
		$actual = file_get_contents( $this->resolvedPath );
		if ( false === $actual || $actual !== $this->content ) {
			return VerificationResult::failure( $this->id, 'file content does not match the intended content' );
		}
		return VerificationResult::success( $this->id );
	}

	public function rollback( Snapshot $snapshot ): RollbackRecord {
		$path = (string) ( $snapshot->state()['path'] ?? $this->resolvedPath );
		if ( file_exists( $path ) && ! @unlink( $path ) ) {
			return RollbackRecord::failure( $this->id, $snapshot->id(), 'failed to delete the created file during rollback' );
		}
		return RollbackRecord::success( $this->id, $snapshot->id() );
	}

	public function payloadFingerprint(): string {
		return self::fingerprintOf( array( 'path' => $this->path, 'content' => $this->content ) );
	}

	/**
	 * FileCreate's precondition is "nothing exists at this path yet" —
	 * the absent sentinel when true, a content fingerprint (proving
	 * something now DOES exist) otherwise, so any change from absent to
	 * present between Snapshot and Apply is detected as stale state.
	 */
	public function currentPreconditionFingerprint(): string {
		$path = $this->resolvedPath ?? $this->path;
		if ( ! file_exists( $path ) ) {
			return self::absentFingerprint();
		}
		$content = file_get_contents( $path );
		return self::fingerprintOf( false === $content ? null : $content );
	}

	public function intendedValue(): string {
		return $this->content;
	}
}
