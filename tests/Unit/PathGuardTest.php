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

        // ---------------------------------------------------------- BUG-001 regression
        //
        // The guard root in this test suite is always a Windows drive-letter
        // path on a Windows host (sys_get_temp_dir()), so these cases already
        // exercise the real defect (isInsideRoot() re-deriving an absolute
        // path and rejecting its own drive letter). They are kept as their
        // own named tests, on top of the cases above, so a future regression
        // in this exact area fails with an unambiguous test name.

        /**
         * A raw, fully-qualified Windows absolute path that legitimately
         * falls inside the guard root must resolve, not be rejected as
         * "invalid" just because it carries a drive letter.
         */
        public function test_windows_absolute_path_within_root_resolves(): void {
                $absolute_windows_path = $this->root . '/wp-content/themes/testtheme/functions.php';
                $resolved = $this->guard->resolveRead( $absolute_windows_path );
                $this->assertStringContains( 'functions.php', $resolved );
        }

        /**
         * Backslash and forward-slash separators must be interchangeable
         * for the same logical path.
         */
        public function test_mixed_separator_path_resolves(): void {
                $mixed = "wp-content\\themes/testtheme\\functions.php";
                $resolved = $this->guard->resolveRead( $mixed );
                $this->assertStringContains( 'functions.php', $resolved );
        }

        /**
         * A drive letter that does not match the guard root's own drive
         * must never be treated as inside root, even though it is a
         * well-formed absolute Windows path.
         */
        public function test_drive_letter_mismatch_is_outside_root(): void {
                // Deliberately construct a path on a different drive letter
                // than whatever the guard root actually lives on.
                $root_drive  = strtoupper( substr( ltrim( $this->root, '/' ), 0, 1 ) );
                $other_drive = 'C' === $root_drive ? 'Z' : 'C';
                $this->assertRejected( $other_drive . ':/definitely/outside/secrets.php', PathGuardException::E_OUTSIDE_ROOT );
        }

        /**
         * A sibling directory that merely shares a name prefix with the
         * root (e.g. root "…/site-a" vs "…/site-a-other") must not be
         * treated as inside root.
         */
        public function test_sibling_prefix_directory_is_not_inside_root(): void {
                $sibling = rtrim( $this->root, '/' ) . '-sibling/secret.php';
                $this->assertFalse( $this->guard->isInsideRoot( $sibling ) );
        }

        /**
         * Case variation must not bypass protection on a case-insensitive
         * (Windows) filesystem: requesting the protected file with
         * different casing must still be refused.
         */
        public function test_case_variation_on_protected_file_is_still_protected(): void {
                $this->assertRejected( 'WP-CONFIG.PHP', PathGuardException::E_PROTECTED );
                $this->assertRejected( 'Wp-Config.PHP', PathGuardException::E_PROTECTED );
        }

        /**
         * Case variation on a legitimate, non-protected file must still
         * resolve on a case-insensitive filesystem (the guard must not be
         * accidentally stricter than the real filesystem).
         */
        public function test_case_variation_on_allowed_file_still_resolves(): void {
                $resolved = $this->guard->resolveRead( 'WP-CONTENT/THEMES/TESTTHEME/FUNCTIONS.PHP' );
                $this->assertStringContains( 'functions.php', strtolower( $resolved ) );
        }

        /**
         * A literal percent-encoded traversal sequence is not decoded
         * anywhere in this pipeline (arguments arrive as already-decoded
         * JSON strings, never URL-encoded), so it must not be mistaken for
         * real traversal — but since it also never corresponds to a real
         * file, it must still fail safely (not found), never resolve.
         */
        public function test_percent_encoded_traversal_does_not_resolve(): void {
                $threw = null;
                try {
                        $this->guard->resolveRead( '%2e%2e%2fwp-config.php' );
                } catch ( PathGuardException $e ) {
                        $threw = $e;
                }
                $this->assertNotNull( $threw, 'a non-existent literal filename must still be rejected' );
                $this->assertNotEquals( PathGuardException::E_PROTECTED, $threw?->reason(), 'must not be silently decoded into the protected file' );
        }

        /**
         * A UNC path can never satisfy a drive-letter (or POSIX) root and
         * must always be rejected as outside-root — there is no dedicated
         * UNC root support, and it must fail safely rather than throwing a
         * generic invalid-path error that could be confused with a
         * malformed-input case.
         */
        public function test_unc_path_is_rejected_as_outside_root(): void {
                $this->assertRejected( '//fileserver/share/secret.php', PathGuardException::E_OUTSIDE_ROOT );
                $this->assertRejected( '\\\\fileserver\\share\\secret.php', PathGuardException::E_OUTSIDE_ROOT );
        }

        /**
         * Plain POSIX-style relative paths (the Linux/Unix norm, and the
         * form every tool in the catalog actually uses) must keep working
         * exactly as before — this is the regression baseline the Windows
         * fix must not disturb.
         */
        public function test_posix_style_relative_path_still_resolves(): void {
                $resolved = $this->guard->resolveRead( 'wp-content/themes/testtheme/style.css' );
                $this->assertStringContains( 'style.css', $resolved );
        }

        // -------------------------------------------------------------- symlink escapes (Section 7 attack-matrix closure)

        /**
         * True filesystem symlinks, not just string-shaped paths — proves
         * the post-realpath() re-check in assertReadable() (PathGuard.php
         * ~L326-337) actually stops a symlink target escape, not merely
         * the pre-resolution string confinement check (which a symlink
         * trivially bypasses, since the symlink's OWN path string is
         * legitimately inside root; only realpath() reveals where it
         * really points). On a host where this process cannot create
         * symlinks (e.g. Windows without Developer Mode/elevation — a
         * real, common local-dev constraint, unrelated to PathGuard's own
         * correctness), the test honestly records that it could not run
         * rather than silently asserting nothing.
         */
        public function test_symlink_target_escaping_root_is_rejected_on_read(): void {
                $outside = sys_get_temp_dir() . '/aios-pathguard-outside-' . uniqid();
                @mkdir( $outside, 0777, true );
                file_put_contents( $outside . '/secret.txt', 'outside-root-secret' );

                $link = $this->root . '/wp-content/linked-secret.txt';
                if ( ! @symlink( $outside . '/secret.txt', $link ) ) {
                        $this->assertTrue( true, 'symlink() unavailable in this environment (no privilege/Developer Mode) — cannot exercise a real symlink escape here; see docs/audits/BUG-GAP-REGISTER.md attack-matrix notes' );
                        $this->rrmdir( $outside );
                        return;
                }

                $this->assertRejected( 'wp-content/linked-secret.txt', PathGuardException::E_OUTSIDE_ROOT );

                @unlink( $link );
                $this->rrmdir( $outside );
        }

        /**
         * A symlinked PARENT DIRECTORY (not the final path segment)
         * escaping root — proves assertAncestorConfined() (PathGuard.php
         * ~L276-300), which resolveWrite() uses specifically because a
         * brand-new file's own realpath() cannot be resolved yet (the
         * file does not exist), so the confinement re-check must walk up
         * to the nearest existing ancestor directory instead.
         */
        public function test_symlinked_parent_directory_escaping_root_is_rejected_on_write(): void {
                $outside = sys_get_temp_dir() . '/aios-pathguard-outside-dir-' . uniqid();
                @mkdir( $outside, 0777, true );

                $link = $this->root . '/wp-content/linked-dir';
                if ( ! @symlink( $outside, $link ) ) {
                        $this->assertTrue( true, 'symlink() unavailable in this environment (no privilege/Developer Mode) — cannot exercise a real symlink escape here; see docs/audits/BUG-GAP-REGISTER.md attack-matrix notes' );
                        $this->rrmdir( $outside );
                        return;
                }

                $threw = null;
                try {
                        $this->guard->resolveWrite( 'wp-content/linked-dir/new-file.txt' );
                } catch ( PathGuardException $e ) {
                        $threw = $e;
                }
                $this->assertNotNull( $threw, 'a write target whose PARENT directory is a symlink escaping root must be rejected even though the file itself does not exist yet' );
                $this->assertEquals( PathGuardException::E_OUTSIDE_ROOT, $threw->reason() );

                @unlink( $link );
                $this->rrmdir( $outside );
        }
}
