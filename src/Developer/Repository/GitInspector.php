<?php
/**
 * Git Inspector — read-only git adapter with a fixed argv whitelist.
 *
 * Implements DeveloperCapability::INSPECT_GIT (P3-B, T-111). Every git
 * invocation is a hardcoded argv array passed to proc_open() — never a
 * shell string, never user input concatenated into a command line.
 * Only read-only subcommands exist as methods; there is no generic
 * passthrough, so a write subcommand (commit/push/merge/reset/clean/
 * checkout) is structurally unreachable through this class.
 *
 * @package AIOS\Developer\Repository
 */

declare( strict_types=1 );

namespace AIOS\Developer\Repository;

final class GitInspector {

	/**
	 * Directory git commands are executed in (the repository working tree).
	 */
	private string $repoRoot;

	/**
	 * Path to the git binary.
	 */
	private string $gitBinary;

	public function __construct( string $repo_root, string $git_binary = 'git' ) {
		$clean_root = rtrim( trim( $repo_root ), '/' );
		if ( '' === $clean_root || ! is_dir( $clean_root ) ) {
			throw new \InvalidArgumentException( 'GitInspector repo_root must be an existing directory.' );
		}

		$this->repoRoot  = $clean_root;
		$this->gitBinary = $git_binary;
	}

	/**
	 * Current branch, HEAD SHA, and working-tree status.
	 */
	public function inspect(): GitInspectionResult {
		$branch_run = $this->run( array( 'rev-parse', '--abbrev-ref', 'HEAD' ) );
		if ( 0 !== $branch_run['exit_code'] ) {
			return new GitInspectionResult( false, null, null, true, array(), $this->firstErrorLine( $branch_run ) );
		}

		$head_run = $this->run( array( 'rev-parse', 'HEAD' ) );
		if ( 0 !== $head_run['exit_code'] ) {
			return new GitInspectionResult( false, null, null, true, array(), $this->firstErrorLine( $head_run ) );
		}

		$status_run = $this->run( array( 'status', '--porcelain=v1' ) );
		if ( 0 !== $status_run['exit_code'] ) {
			return new GitInspectionResult( false, null, null, true, array(), $this->firstErrorLine( $status_run ) );
		}

		$changed = $this->parsePorcelainStatus( $status_run['stdout'] );

		return new GitInspectionResult(
			true,
			trim( $branch_run['stdout'] ),
			trim( $head_run['stdout'] ),
			array() === $changed,
			$changed
		);
	}

	/**
	 * Bounded commit log (most recent first).
	 *
	 * @return array{available: bool, entries: array<int, array{sha: string, author: string, date: string, subject: string}>, error: ?string}
	 */
	public function log( int $limit = 20 ): array {
		$bounded = max( 1, min( $limit, 100 ) );

		$run = $this->run(
			array(
				'log',
				'-n',
				(string) $bounded,
				'--pretty=format:%H%x1f%an%x1f%ad%x1f%s%x1e',
				'--date=iso-strict',
			)
		);

		if ( 0 !== $run['exit_code'] ) {
			return array(
				'available' => false,
				'entries'   => array(),
				'error'     => $this->firstErrorLine( $run ),
			);
		}

		$entries = array();
		foreach ( array_filter( explode( "\x1e", $run['stdout'] ) ) as $record ) {
			$fields = explode( "\x1f", trim( $record ) );
			if ( 4 !== count( $fields ) ) {
				continue;
			}
			$entries[] = array(
				'sha'     => $fields[0],
				'author'  => $fields[1],
				'date'    => $fields[2],
				'subject' => $fields[3],
			);
		}

		return array(
			'available' => true,
			'entries'   => $entries,
			'error'     => null,
		);
	}

	/**
	 * Bounded diffstat for a strictly validated ref or ref range. Rejects
	 * anything that is not a plain ref/ref-range shape (no flags, no
	 * whitespace, no shell metacharacters) before it ever reaches git.
	 *
	 * @return array{available: bool, stat: string, error: ?string}
	 */
	public function diffStat( string $ref_range ): array {
		$clean = trim( $ref_range );
		if ( '' === $clean || 1 !== preg_match( '/^[A-Za-z0-9._\/-]{1,200}(\.{2,3}[A-Za-z0-9._\/-]{1,200})?$/', $clean ) || str_starts_with( $clean, '-' ) ) {
			return array(
				'available' => false,
				'stat'      => '',
				'error'     => 'Rejected ref_range: must be a plain ref or ref..ref/ref...ref shape.',
			);
		}

		$run = $this->run( array( 'diff', '--stat', '--', $clean ) );
		// Note: the ref/range argument is passed as its own argv element,
		// never interpolated into a string, and validated above to
		// structurally rule out flag injection (e.g. a value starting
		// with "-").
		if ( 0 !== $run['exit_code'] ) {
			return array(
				'available' => false,
				'stat'      => '',
				'error'     => $this->firstErrorLine( $run ),
			);
		}

		return array(
			'available' => true,
			'stat'      => $run['stdout'],
			'error'     => null,
		);
	}

	/**
	 * @return array<int, array{status: string, path: string}>
	 */
	private function parsePorcelainStatus( string $stdout ): array {
		$changed = array();
		$lines   = preg_split( '/\R/', $stdout );
		$lines   = false === $lines ? array() : $lines;
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			if ( strlen( $line ) < 4 ) {
				continue;
			}
			$status = substr( $line, 0, 2 );
			$path   = trim( substr( $line, 3 ) );
			if ( '' === $path ) {
				continue;
			}
			$changed[] = array(
				'status' => $status,
				'path'   => $path,
			);
		}
		return $changed;
	}

	/**
	 * @param string[] $argv Subcommand + args (never includes the binary itself).
	 * @return array{exit_code: int, stdout: string, stderr: string}
	 */
	private function run( array $argv ): array {
		$command = array_merge( array( $this->gitBinary ), $argv );

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.PHP.NoSilencedErrors.Discouraged -- this IS the read-only git adapter: a fixed argv array (never a shell string), no user input concatenated into the command line; failure to start is handled explicitly below rather than fataling.
		$process = @proc_open( $command, $descriptors, $pipes, $this->repoRoot );
		if ( ! is_resource( $process ) ) {
			return array(
				'exit_code' => 127,
				'stdout'    => '',
				'stderr'    => 'Unable to start git process.',
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open() pipe handles, not filesystem files; WP_Filesystem has no equivalent API.
		fclose( $pipes[0] );
		$stdout_raw = stream_get_contents( $pipes[1] );
		$stderr_raw = stream_get_contents( $pipes[2] );
		$stdout     = false === $stdout_raw ? '' : $stdout_raw;
		$stderr     = false === $stderr_raw ? '' : $stderr_raw;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open() pipe handles, not filesystem files; WP_Filesystem has no equivalent API.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- proc_open() pipe handles, not filesystem files; WP_Filesystem has no equivalent API.
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		return array(
			'exit_code' => $exit_code,
			'stdout'    => $stdout,
			'stderr'    => $stderr,
		);
	}

	/**
	 * @param array{exit_code: int, stdout: string, stderr: string} $run Run result.
	 */
	private function firstErrorLine( array $run ): string {
		$lines = preg_split( '/\R/', trim( $run['stderr'] ) );
		$lines = false === $lines ? array() : $lines;
		return $lines[0] ?? sprintf( 'git exited with status %d', $run['exit_code'] );
	}
}
