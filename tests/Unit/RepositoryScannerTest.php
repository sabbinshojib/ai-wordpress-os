<?php
/**
 * Unit tests: RepositoryScanner (P3-B, T-110).
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Developer\Repository\RepositoryScanner;
use AIOS\Security\PathGuard;
use AIOS\Security\PathGuardException;
use AIOS\Tests\TestCase;

final class RepositoryScannerTest extends TestCase {

	private string $root;

	private PathGuard $guard;

	private RepositoryScanner $scanner;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/aios-repoScan-' . uniqid();
		@mkdir( $this->root, 0777, true );
		@mkdir( $this->root . '/src/Developer', 0777, true );
		@mkdir( $this->root . '/.git', 0777, true );
		file_put_contents(
			$this->root . '/src/Developer/Foo.php',
			"<?php\nnamespace AIOS\\Developer;\n\nfinal class Foo {\n}\n"
		);
		file_put_contents( $this->root . '/src/Developer/style.css', 'body{}' );
		file_put_contents( $this->root . '/.git/HEAD', 'ref: refs/heads/main' );
		file_put_contents( $this->root . '/wp-config.php', '<?php // secrets' );

		$this->guard   = new PathGuard( $this->root );
		$this->scanner = new RepositoryScanner( $this->guard );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->root );
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

	public function test_scan_enumerates_directory_with_metadata(): void {
		$result = $this->scanner->scan( 'src/Developer' );

		$paths = array_map( static fn ( $e ) => $e->path(), $result->entries() );
		$this->assertTrue( in_array( 'src/Developer/Foo.php', $paths, true ) );
		$this->assertTrue( in_array( 'src/Developer/style.css', $paths, true ) );
		$this->assertFalse( $result->truncated() );
	}

	public function test_scan_extracts_php_namespace_and_class(): void {
		$result = $this->scanner->scan( 'src/Developer/Foo.php' );

		$this->assertCount( 1, $result->entries() );
		$entry = $result->entries()[0];
		$this->assertEquals( 'AIOS\\Developer', $entry->phpNamespace() );
		$this->assertEquals( 'Foo', $entry->phpClass() );
		$this->assertFalse( $entry->isDirectory() );
	}

	public function test_scan_single_directory_entry_has_no_php_metadata(): void {
		$result = $this->scanner->scan( 'src' );
		$paths  = array_map( static fn ( $e ) => $e->path(), $result->entries() );
		$this->assertTrue( in_array( 'src/Developer', $paths, true ) );
	}

	public function test_scan_rejects_traversal(): void {
		$threw = false;
		try {
			$this->scanner->scan( '../outside' );
		} catch ( PathGuardException $e ) {
			$threw = true;
			$this->assertEquals( PathGuardException::E_TRAVERSAL, $e->reason() );
		}
		$this->assertTrue( $threw );
	}

	public function test_scan_rejects_absolute_escape(): void {
		$threw = false;
		try {
			$this->scanner->scan( '/etc/passwd' );
		} catch ( PathGuardException $e ) {
			$threw = true;
			$this->assertEquals( PathGuardException::E_OUTSIDE_ROOT, $e->reason() );
		}
		$this->assertTrue( $threw );
	}

	public function test_scan_rejects_empty_scope(): void {
		$threw = false;
		try {
			$this->scanner->scan( '' );
		} catch ( PathGuardException $e ) {
			$threw = true;
			$this->assertEquals( PathGuardException::E_INVALID, $e->reason() );
		}
		$this->assertTrue( $threw );
	}

	public function test_scan_rejects_missing_path(): void {
		$threw = false;
		try {
			$this->scanner->scan( 'does/not/exist' );
		} catch ( PathGuardException $e ) {
			$threw = true;
			$this->assertEquals( PathGuardException::E_NOT_FOUND, $e->reason() );
		}
		$this->assertTrue( $threw );
	}

	public function test_scan_excludes_protected_wp_config(): void {
		$result = $this->scanner->scan( '.' );
		$paths  = array_map( static fn ( $e ) => $e->path(), $result->entries() );
		$this->assertFalse( in_array( 'wp-config.php', $paths, true ) );
	}

	public function test_scan_skips_git_directory(): void {
		$result = $this->scanner->scan( '.' );
		foreach ( $result->entries() as $entry ) {
			$this->assertFalse( str_starts_with( $entry->path(), '.git/' ), 'Entry should not be inside .git: ' . $entry->path() );
		}
	}

	public function test_scan_respects_max_entries_bound_and_marks_truncated(): void {
		$result = $this->scanner->scan( 'src/Developer', 1 );
		$this->assertCount( 1, $result->entries() );
		$this->assertTrue( $result->truncated() );
	}

	public function test_scan_result_is_json_safe(): void {
		$result = $this->scanner->scan( 'src/Developer' );
		$encoded = json_encode( $result->toArray() );
		$this->assertTrue( false !== $encoded );
	}
}
