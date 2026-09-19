<?php
/**
 * Repository Scanner — read-only, PathGuard-bounded filesystem inspection.
 *
 * Implements DeveloperCapability::INSPECT_REPO (P3-B, T-110). Enumerates
 * files/directories under a scope path, confined entirely by PathGuard.
 * Never writes, never executes, never reads outside the guarded root.
 *
 * @package AIOS\Developer\Repository
 */

declare( strict_types=1 );

namespace AIOS\Developer\Repository;

use AIOS\Security\PathGuard;
use AIOS\Security\PathGuardException;

final class RepositoryScanner {

	/**
	 * Hard ceiling on entries returned by a single scan, regardless of
	 * caller-supplied max, to keep scans bounded.
	 */
	private const HARD_MAX_ENTRIES = 5000;

	/**
	 * Directory basenames never descended into (noise/heavy, not
	 * security-relevant — PathGuard already denies the plugin's own
	 * directory and protected files independently of this list).
	 *
	 * @var string[]
	 */
	private const SKIPPED_DIRECTORIES = array( '.git', 'node_modules', 'vendor' );

	private PathGuard $pathGuard;

	public function __construct( ?PathGuard $path_guard = null ) {
		$this->pathGuard = $path_guard ?? new PathGuard();
	}

	/**
	 * Scan a scope path (file or directory) for metadata.
	 *
	 * @param string $scope_path  Root-relative path (no leading `/`, no `..`).
	 * @param int    $max_entries Soft cap on returned entries (clamped to HARD_MAX_ENTRIES).
	 * @throws PathGuardException When the scope is invalid, escaping, protected, or missing.
	 */
	public function scan( string $scope_path, int $max_entries = 500 ): RepositoryScanResult {
		$clean = trim( $scope_path );
		if ( '' === $clean ) {
			throw new PathGuardException( 'Empty scope path.', PathGuardException::E_INVALID );
		}

		$this->assertNoEscapeShape( $clean );

		$root     = rtrim( $this->normalizeSlashes( $this->pathGuard->root() ), '/' ) . '/';
		$relative = ltrim( $this->normalizeSlashes( $clean ), '/' );
		$absolute = $root . $relative;

		if ( ! $this->pathGuard->isInsideRoot( $absolute ) ) {
			throw new PathGuardException( 'Scope path is outside the WordPress installation.', PathGuardException::E_OUTSIDE_ROOT );
		}

		if ( ! file_exists( $absolute ) ) {
			throw new PathGuardException( 'Scope path does not exist.', PathGuardException::E_NOT_FOUND );
		}

		$real = realpath( $absolute );
		if ( false === $real ) {
			throw new PathGuardException( 'Scope path could not be resolved.', PathGuardException::E_NOT_FOUND );
		}
		$real_normalized = $this->normalizeSlashes( $real );
		if ( ! str_starts_with( $real_normalized, rtrim( $this->normalizeSlashes( $root ), '/' ) ) ) {
			throw new PathGuardException( 'Resolved scope path escapes the WordPress installation.', PathGuardException::E_OUTSIDE_ROOT );
		}
		if ( $this->pathGuard->isProtected( $real ) ) {
			throw new PathGuardException( 'Scope path is protected by the AI OS security policy.', PathGuardException::E_PROTECTED );
		}

		$cap = max( 1, min( $max_entries, self::HARD_MAX_ENTRIES ) );

		$entries   = array();
		$truncated = false;

		if ( is_dir( $real ) ) {
			$this->collectDirectory( $real, $root, $cap, $entries, $truncated );
		} else {
			$entry = $this->buildEntry( $real, $root );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return new RepositoryScanResult( $clean, $entries, $truncated, time() );
	}

	/**
	 * Reject shapes PathGuard would ultimately reject anyway, before any
	 * filesystem call, so the failure reason is precise and no directory
	 * listing is attempted against a hostile-looking input.
	 */
	private function assertNoEscapeShape( string $path ): void {
		if ( str_contains( $path, "\0" ) ) {
			throw new PathGuardException( 'Invalid scope path.', PathGuardException::E_INVALID );
		}

		$normalized = $this->normalizeSlashes( $path );

		foreach ( explode( '/', $normalized ) as $segment ) {
			if ( '..' === $segment ) {
				throw new PathGuardException( 'Path traversal is not allowed.', PathGuardException::E_TRAVERSAL );
			}
		}

		// RepositoryScanner only accepts root-relative scope paths (see
		// class docblock). An absolute-looking input (POSIX, Windows
		// drive-letter, or UNC) is never silently reinterpreted as
		// relative-to-root — that would change its meaning instead of
		// rejecting the escape attempt.
		if ( str_starts_with( $normalized, '/' ) || 1 === preg_match( '#^[a-z]:/#i', $normalized ) ) {
			throw new PathGuardException( 'Scope path must be root-relative, not absolute.', PathGuardException::E_OUTSIDE_ROOT );
		}
	}

	/**
	 * @param RepositoryScanEntry[] $entries Accumulator (by reference).
	 */
	private function collectDirectory( string $absolute_dir, string $root, int $cap, array &$entries, bool &$truncated ): void {
		$filtered = new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator( $absolute_dir, \FilesystemIterator::SKIP_DOTS ),
			static function ( \SplFileInfo $current ): bool {
				// Symlinks are never followed for enumeration purposes —
				// their target is not guaranteed to still be inside root.
				if ( $current->isLink() ) {
					return false;
				}
				if ( $current->isDir() && in_array( $current->getFilename(), self::SKIPPED_DIRECTORIES, true ) ) {
					return false;
				}
				return true;
			}
		);

		$iterator = new \RecursiveIteratorIterator( $filtered, \RecursiveIteratorIterator::SELF_FIRST );

		/** @var \SplFileInfo $item */
		foreach ( $iterator as $item ) {
			if ( count( $entries ) >= $cap ) {
				$truncated = true;
				break;
			}

			$entry = $this->buildEntry( $item->getPathname(), $root );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}
	}

	private function buildEntry( string $absolute, string $root ): ?RepositoryScanEntry {
		$normalized = $this->normalizeSlashes( $absolute );
		$root_trim  = rtrim( $this->normalizeSlashes( $root ), '/' );

		if ( ! str_starts_with( $normalized, $root_trim ) ) {
			return null; // Defense in depth; should be unreachable given the caller's confinement.
		}

		if ( $this->pathGuard->isProtected( $absolute ) ) {
			return null;
		}

		$relative_path = ltrim( substr( $normalized, strlen( $root_trim ) ), '/' );
		if ( '' === $relative_path ) {
			$relative_path = basename( $normalized );
		}

		$is_dir = is_dir( $absolute );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- read-only metadata probe on a path already PathGuard/isProtected-cleared; a race (deleted between listing and stat) degrades to 0, never a fatal.
		$size_raw = $is_dir ? 0 : @filesize( $absolute );
		$size     = false === $size_raw ? 0 : (int) $size_raw;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- same read-only metadata probe as above.
		$mtime_raw = @filemtime( $absolute );
		$mtime     = false === $mtime_raw ? 0 : (int) $mtime_raw;

		$namespace = null;
		$class     = null;
		if ( ! $is_dir && 'php' === strtolower( pathinfo( $absolute, PATHINFO_EXTENSION ) ) && $this->pathGuard->isAllowedExtension( $absolute ) ) {
			[$namespace, $class] = $this->parsePhpHeader( $absolute );
		}

		return new RepositoryScanEntry( $relative_path, $is_dir, $size, $mtime, $namespace, $class );
	}

	/**
	 * Bounded, read-only extraction of a PHP file's namespace and primary
	 * class/interface/trait/enum name from its first bytes. Never returns
	 * or stores file content — only the two derived identifiers.
	 *
	 * @return array{0: ?string, 1: ?string}
	 */
	private function parsePhpHeader( string $absolute ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- bounded, read-only header probe via PathGuard-cleared path; WP_Filesystem cannot be assumed initialized in this headless/CLI-callable context (matches FilePatchOperation's existing rationale for direct filesystem calls).
		$handle = @fopen( $absolute, 'rb' );
		if ( false === $handle ) {
			return array( null, null );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- same bounded, read-only header probe as above.
		$header = (string) fread( $handle, 8192 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- same bounded, read-only header probe as above.
		fclose( $handle );

		$namespace = null;
		if ( preg_match( '/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $header, $matches ) ) {
			$namespace = $matches[1];
		}

		$class = null;
		if ( preg_match( '/\b(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/', $header, $matches ) ) {
			$class = $matches[1];
		}

		return array( $namespace, $class );
	}

	private function normalizeSlashes( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}
