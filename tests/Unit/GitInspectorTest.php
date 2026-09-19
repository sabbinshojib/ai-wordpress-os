<?php
/**
 * Unit tests: GitInspector (P3-B, T-111).
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Developer\Repository\GitInspector;
use AIOS\Tests\TestCase;

final class GitInspectorTest extends TestCase {

	private string $repo;

	protected function setUp(): void {
		$this->repo = sys_get_temp_dir() . '/aios-gitInspect-' . uniqid();
		@mkdir( $this->repo, 0777, true );

		$this->git( array( 'init', '-q', '-b', 'main' ) );
		$this->git( array( 'config', 'user.email', 'aios-test@example.com' ) );
		$this->git( array( 'config', 'user.name', 'AIOS Test' ) );

		file_put_contents( $this->repo . '/README.md', "# Test repo\n" );
		$this->git( array( 'add', '.' ) );
		$this->git( array( 'commit', '-q', '-m', 'initial commit' ) );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->repo );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: array() as $entry ) {
			if ( in_array( $entry, array( '.', '..' ), true ) ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	/**
	 * Out-of-band git invocation used only to build fixtures, not the
	 * class under test.
	 *
	 * @param string[] $argv Git subcommand and arguments.
	 */
	private function git( array $argv ): string {
		$command     = array_merge( array( 'git' ), $argv );
		$descriptors = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$process     = proc_open( $command, $descriptors, $pipes, $this->repo );
		$out         = stream_get_contents( $pipes[1] ) ?: '';
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );
		return trim( $out );
	}

	public function test_constructor_rejects_nonexistent_root(): void {
		$threw = false;
		try {
			new GitInspector( $this->repo . '/does-not-exist' );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw );
	}

	public function test_inspect_matches_out_of_band_branch_and_head(): void {
		$expected_branch = $this->git( array( 'rev-parse', '--abbrev-ref', 'HEAD' ) );
		$expected_sha    = $this->git( array( 'rev-parse', 'HEAD' ) );

		$inspector = new GitInspector( $this->repo );
		$result    = $inspector->inspect();

		$this->assertTrue( $result->available() );
		$this->assertEquals( $expected_branch, $result->branch() );
		$this->assertEquals( $expected_sha, $result->headSha() );
		$this->assertTrue( $result->clean() );
		$this->assertCount( 0, $result->changedFiles() );
	}

	public function test_inspect_reports_dirty_working_tree(): void {
		file_put_contents( $this->repo . '/README.md', "# Test repo\n\nchanged\n" );
		file_put_contents( $this->repo . '/new-file.txt', 'new' );

		$inspector = new GitInspector( $this->repo );
		$result    = $inspector->inspect();

		$this->assertFalse( $result->clean() );
		$paths = array_map( static fn ( $c ) => $c['path'], $result->changedFiles() );
		$this->assertTrue( in_array( 'README.md', $paths, true ) );
		$this->assertTrue( in_array( 'new-file.txt', $paths, true ) );
	}

	public function test_log_returns_bounded_entries_matching_out_of_band(): void {
		$this->git( array( 'commit', '--allow-empty', '-q', '-m', 'second commit' ) );

		$inspector = new GitInspector( $this->repo );
		$log       = $inspector->log( 1 );

		$this->assertTrue( $log['available'] );
		$this->assertCount( 1, $log['entries'] );
		$this->assertEquals( 'second commit', $log['entries'][0]['subject'] );
	}

	public function test_log_clamps_limit_to_hard_bound(): void {
		$inspector = new GitInspector( $this->repo );
		$log       = $inspector->log( 99999 );
		$this->assertTrue( $log['available'] );
		// Only one commit exists; clamp behavior is exercised via no
		// exception/timeout on a large requested limit.
		$this->assertCount( 1, $log['entries'] );
	}

	public function test_diff_stat_rejects_flag_injection_shaped_input(): void {
		$inspector = new GitInspector( $this->repo );
		$result    = $inspector->diffStat( '--upload-pack=touch /tmp/pwned' );

		$this->assertFalse( $result['available'] );
		$this->assertStringContains( 'Rejected ref_range', (string) $result['error'] );
	}

	public function test_diff_stat_rejects_whitespace_shaped_input(): void {
		$inspector = new GitInspector( $this->repo );
		$result    = $inspector->diffStat( 'main; rm -rf /' );

		$this->assertFalse( $result['available'] );
	}

	public function test_diff_stat_accepts_valid_single_ref(): void {
		$inspector = new GitInspector( $this->repo );
		$result    = $inspector->diffStat( 'HEAD' );

		$this->assertTrue( $result['available'] );
	}

	public function test_inspect_reports_unavailable_for_non_git_directory(): void {
		$plain_dir = sys_get_temp_dir() . '/aios-notgit-' . uniqid();
		@mkdir( $plain_dir, 0777, true );

		$inspector = new GitInspector( $plain_dir );
		$result    = $inspector->inspect();

		$this->assertFalse( $result->available() );
		$this->assertNotNull( $result->error() );

		$this->rrmdir( $plain_dir );
	}
}
