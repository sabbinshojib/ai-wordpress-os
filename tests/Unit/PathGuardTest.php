<?php
/**
 * Unit tests: PathGuard (spec §11 — traversal, escapes, protected).
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Security\PathGuard;
use AIOS\Security\PathGuardException;
use AIOS\Tests\TestCase;

final class PathGuardTest extends TestCase {

        private string $root;

        private PathGuard $guard;

        protected function setUp(): void {
                // Build a realistic tree in a temp dir.
                $this->root = sys_get_temp_dir() . '/aios-pathguard-' . uniqid();
                @mkdir( $this->root, 0777, true );
                @mkdir( $this->root . '/wp-content/themes/testtheme', 0777, true );
                file_put_contents( $this->root . '/wp-config.php', '<?php // secrets' );
                file_put_contents( $this->root . '/readme.html', 'readme' );
                file_put_contents( $this->root . '/wp-content/themes/testtheme/functions.php', '<?php' );
                file_put_contents( $this->root . '/wp-content/themes/testtheme/style.css', 'body{}' );
                file_put_contents( $this->root . '/.env', 'SECRET=1' );

                $this->guard = new PathGuard( $this->root );
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

        private function assertRejected( string $path, string $reason_class = '' ): void {
                $threw = null;
                try {
                        $this->guard->resolveRead( $path );
                } catch ( PathGuardException $e ) {
                        $threw = $e;
                }
                $this->assertNotNull( $threw, "path [{$path}] must be rejected" );
                if ( '' !== $reason_class ) {
                        $this->assertEquals( $reason_class, $threw->reason(), "path [{$path}] rejected for wrong reason" );
                }
        }

        public function test_parent_traversal_is_rejected(): void {
                $this->assertRejected( '../../etc/passwd', PathGuardException::E_TRAVERSAL );
                $this->assertRejected( 'wp-content/themes/../../../etc/passwd', PathGuardException::E_TRAVERSAL );
        }

        public function test_absolute_escape_is_rejected(): void {
                $this->assertRejected( '/etc/passwd', PathGuardException::E_OUTSIDE_ROOT );
                $this->assertRejected( '/home/other/secret.php', PathGuardException::E_OUTSIDE_ROOT );
        }

        public function test_null_byte_injection_is_rejected(): void {
                $this->assertRejected( "readme.html\0.php", PathGuardException::E_INVALID );
        }

        public function test_wp_config_is_protected(): void {
                $this->assertRejected( 'wp-config.php', PathGuardException::E_PROTECTED );
                $this->assertRejected( $this->root . '/wp-config.php', PathGuardException::E_PROTECTED );
        }

        public function test_env_is_protected(): void {
                $this->assertRejected( '.env', PathGuardException::E_PROTECTED );
        }

        public function test_plugin_own_directory_is_protected(): void {
                // AI_WP_OS_DIR is defined by the bootstrap; PathGuard refuses
                // reads inside the plugin itself. Depending on guard root the
                // rejection fires as PROTECTED (root-relative) or OUTSIDE_ROOT
                // (different root) — either way it must never resolve.
                $own = str_replace( '\\', '/', AI_WP_OS_DIR ) . 'src/Core/Plugin.php';
                $threw = null;
                try {
                        $this->guard->resolveRead( $own );
                } catch ( PathGuardException $e ) {
                        $threw = $e;
                }
                $this->assertNotNull( $threw, 'plugin-internal path must be rejected' );
                $this->assert(
                        PathGuardException::E_PROTECTED === $threw->reason() || PathGuardException::E_OUTSIDE_ROOT === $threw->reason(),
                        'unexpected reason: ' . $threw->reason()
                );
        }

        public function test_disallowed_extension_is_rejected(): void {
                $this->assertRejected( 'readme.html.zip', PathGuardException::E_EXTENSION );
        }

        public function test_missing_file_is_not_found(): void {
                $this->assertRejected( 'wp-content/themes/testtheme/nope.php', PathGuardException::E_NOT_FOUND );
        }

        public function test_valid_read_resolves_to_real_path(): void {
                $resolved = $this->guard->resolveRead( 'wp-content/themes/testtheme/functions.php' );
                $this->assertStringContains( 'functions.php', $resolved );
                $this->assert( str_starts_with( str_replace( '\\', '/', $resolved ), str_replace( '\\', '/', $this->root ) ), 'resolved path stays inside root' );
        }

        public function test_insideroot_is_false_for_outside_paths(): void {
                $this->assertFalse( $this->guard->isInsideRoot( '/etc/passwd' ) );
                $this->assertTrue( $this->guard->isInsideRoot( 'wp-content/themes/testtheme/style.css' ) );
        }

        public function test_extension_allowlist(): void {
                $this->assertTrue( $this->guard->isAllowedExtension( 'style.css' ) );
                $this->assertTrue( $this->guard->isAllowedExtension( 'functions.php' ) );
                $this->assertTrue( $this->guard->isAllowedExtension( 'data.json' ) );
                $this->assertFalse( $this->guard->isAllowedExtension( 'image.png' ) );
                $this->assertFalse( $this->guard->isAllowedExtension( 'backup.sql' ) );
        }
}
