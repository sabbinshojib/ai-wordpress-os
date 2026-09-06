<?php
/**
 * PathGuard — filesystem path confinement.
 *
 * All file access in AI WordPress OS (read today, write in Phase 2)
 * goes through PathGuard. It:
 *
 *   - resolves relative paths against ABSPATH;
 *   - canonicalizes with realpath();
 *   - rejects `..` segments outright, before any FS call;
 *   - confines the final path inside ABSPATH (case-insensitive on
 *     Windows-style hosts, case-sensitive elsewhere);
 *   - denies access to a configurable protected list (wp-config.php,
 *     .env, .htaccess, php.ini, logs, uploads-sibling secrets…);
 *   - denies access to the AI OS plugin's own directory (self-tamper
 *     protection: the agent must never edit its own code).
 *
 * Windows notes (see docs/audits/BUG-GAP-REGISTER.md BUG-001): a guard
 * root may itself be a drive-letter path (e.g. "C:/xampp/htdocs/").
 * Any absolute path — POSIX-style, drive-letter, or UNC — is accepted
 * as *input* and then subjected to the same root-confinement check as
 * every other path; only paths that actually resolve inside the guard
 * root are ever allowed through. UNC paths (`\\server\share\...`) can
 * never satisfy a drive-letter or POSIX root and are therefore always
 * rejected as outside-root — there is no dedicated UNC root support.
 *
 * @package AIOS\Security
 */

declare( strict_types=1 );

namespace AIOS\Security;

final class PathGuard {

        /**
         * Default protected files/dirs (relative to ABSPATH or absolute).
         *
         * @var string[]
         */
        private const DEFAULT_PROTECTED = array(
                'wp-config.php',
                '.env',
                '.htaccess',
                'php.ini',
                '.user.ini',
                'wp-config-sample.php', // informational but may hold real prefixes
        );

        /**
         * Globally denied basenames anywhere in the tree.
         *
         * @var string[]
         */
        private const DENIED_BASENAMES = array(
                'wp-config.php',
                '.env',
                '.htaccess',
                'php.ini',
                '.user.ini',
                '.htpasswd',
        );

        /**
         * Readable text-ish extensions (spec §11). PHP read is allowed
         * only for theme/plugin inspection tools and is risk-gated by the
         * tool itself; execution is never automatic.
         *
         * @var string[]
         */
        private const ALLOWED_EXTENSIONS = array(
                'php', 'css', 'js', 'json', 'xml', 'html', 'svg', 'md', 'txt',
                'scss', 'sass', 'less', 'twig', 'mustache', 'yml', 'yaml', 'csv', 'map',
        );

        /**
         * Where inspection is rooted. Defaults to ABSPATH.
         */
        private string $root;

        /**
         * Additional protected entries from the `ai_os_protected_files` filter.
         *
         * @var string[]
         */
        private array $extraProtected;

        /**
         * Whether the guard root lives on a case-insensitive filesystem
         * (Windows). Cached once per instance; drives every path
         * comparison so confinement/protection checks cannot be bypassed
         * by case variation, without weakening case-sensitive behavior
         * on POSIX hosts.
         */
        private bool $caseInsensitive;

        public function __construct( ?string $root = null ) {
                // Normalize: guard roots always carry a trailing slash so that
                // prefix-confinement checks cannot be bypassed by sibling
                // directories sharing a name prefix.
                $raw_root   = $root ?? self::defaultRoot();
                $this->root = rtrim( $raw_root, '/' ) . '/';

                $this->caseInsensitive = self::isWindowsFilesystem();

                $filtered = array();
                /** @var string[]|mixed $extra */
                $extra = apply_filters( 'ai_os_protected_files', array() );
                if ( is_array( $extra ) ) {
                        foreach ( $extra as $entry ) {
                                if ( is_string( $entry ) && '' !== trim( $entry ) ) {
                                        $filtered[] = $this->normalizeSlashes( trim( $entry ) );
                                }
                        }
                }
                $this->extraProtected = $filtered;
        }

        private static function defaultRoot(): string {
                if ( defined( 'ABSPATH' ) ) {
                        return rtrim( (string) ABSPATH, '/' ) . '/';
                }
                return getcwd() ? rtrim( getcwd(), '/' ) . '/' : '/';
        }

        /**
         * Whether the current OS has a case-insensitive filesystem. PHP's
         * own `DIRECTORY_SEPARATOR` is the standard, dependency-free way
         * to detect Windows without touching `PHP_OS` string parsing.
         */
        private static function isWindowsFilesystem(): bool {
                return '\\' === DIRECTORY_SEPARATOR;
        }

        // ---------------------------------------------------------------- guards

        /**
         * Validate and resolve a path for READ access.
         *
         * @return string       Absolute canonical path.
         *
         * @throws PathGuardException When the path is invalid, escaping,
         *                            protected, or the extension is not allowed.
         */
        public function resolveRead( string $path ): string {
                $absolute = $this->toAbsolute( $path );
                return $this->assertReadable( $absolute );
        }

        /**
         * Validate a path is inside the guarded root (no FS call).
         *
         * Accepts either a root-relative path or an already-absolute one
         * (POSIX, Windows drive-letter, or UNC) — `toAbsolute()` treats
         * every absolute shape as "use as-is" and this method then does a
         * pure prefix comparison, so calling it twice on the same
         * already-resolved absolute path is always safe and idempotent.
         */
        public function isInsideRoot( string $path ): bool {
                $absolute = $this->toAbsolute( $path );
                return $this->pathStartsWith( $absolute, $this->root );
        }

        /**
         * Whether a resolved absolute path is on the protected list.
         */
        public function isProtected( string $absolute ): bool {
                $normalized = $this->normalizeSlashes( $absolute );
                $root       = $this->normalizeSlashes( $this->root );

                foreach ( array_merge( self::DEFAULT_PROTECTED, $this->extraProtected ) as $protected ) {
                        $protected = $this->normalizeSlashes( $protected );

                        // Root-relative match (e.g. "wp-config.php"). Compared
                        // case-insensitively on Windows, where the filesystem
                        // itself is case-insensitive — a case-sensitive
                        // compare here would let a differently-cased request
                        // slip past a directory-qualified protected entry
                        // (bare basenames are already caught below regardless
                        // of case, on every OS).
                        if ( $this->pathsEqual( $root . ltrim( $protected, '/' ), $normalized ) ) {
                                return true;
                        }
                        // Absolute match.
                        if ( $this->pathsEqual( $protected, $normalized ) ) {
                                return true;
                        }
                }

                // Denied basenames anywhere.
                $basename = strtolower( basename( $normalized ) );
                if ( in_array( $basename, self::DENIED_BASENAMES, true ) ) {
                        return true;
                }

                // Never allow reading anything inside the AI OS plugin itself.
                if ( defined( 'AI_WP_OS_DIR' ) ) {
                        $plugin_dir = $this->normalizeSlashes( rtrim( AI_WP_OS_DIR, '/' ) . '/' );
                        if ( $this->pathStartsWith( $normalized, $plugin_dir ) ) {
                                return true;
                        }
                }

                return false;
        }

        /**
         * Extension allowlist check (spec §11).
         */
        public function isAllowedExtension( string $path ): bool {
                $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
                return in_array( $extension, self::ALLOWED_EXTENSIONS, true );
        }

        /**
         * The guarded root (normalized, trailing slash).
         */
        public function root(): string {
                return $this->root;
        }

        // ---------------------------------------------------------------- internals

        private function assertReadable( string $absolute ): string {
                if ( $this->looksLikeTraversal( $absolute ) ) {
                        throw new PathGuardException( 'Path traversal is not allowed.', PathGuardException::E_TRAVERSAL );
                }

                if ( ! $this->isInsideRoot( $absolute ) ) {
                        throw new PathGuardException( 'Path is outside the WordPress installation.', PathGuardException::E_OUTSIDE_ROOT );
                }

                if ( $this->isProtected( $absolute ) ) {
                        throw new PathGuardException( 'This path is protected by the AI OS security policy.', PathGuardException::E_PROTECTED );
                }

                if ( ! $this->isAllowedExtension( $absolute ) ) {
                        throw new PathGuardException( 'File type is not allowed for inspection.', PathGuardException::E_EXTENSION );
                }

                $real = realpath( $absolute );
                if ( false === $real ) {
                        throw new PathGuardException( 'File does not exist or is not readable.', PathGuardException::E_NOT_FOUND );
                }

                // Post-realpath re-check: symlinks/junctions can pivot
                // outside the root even when the pre-resolution path looked
                // safe. `realpath()` follows them, so comparing the
                // resolved target against root here is what actually stops
                // a symlink-based escape, not the earlier string check.
                $real_normalized = $this->normalizeSlashes( $real );
                if ( ! $this->pathStartsWith( $real_normalized, $this->root ) ) {
                        throw new PathGuardException( 'Resolved path escapes the WordPress installation.', PathGuardException::E_OUTSIDE_ROOT );
                }
                if ( $this->isProtected( $real ) ) {
                        throw new PathGuardException( 'This path is protected by the AI OS security policy.', PathGuardException::E_PROTECTED );
                }

                return $real;
        }

        /**
         * Turn a user-supplied path (absolute or root-relative) into an
         * absolute path WITHOUT resolving symlinks (realpath happens later).
         *
         * Any absolute-looking input — POSIX (`/etc/passwd`), Windows
         * drive-letter (`C:/Windows/...`), or UNC (`//server/share/...`)
         * — is normalized and returned as-is; it is NOT rejected here.
         * Whether it is actually allowed is decided uniformly downstream
         * by the root-prefix confinement check, exactly like a relative
         * path resolved against root. This is what makes the guard work
         * correctly when its own root is itself a drive-letter path: an
         * internally-reconstructed absolute path (root + relative input)
         * must be able to round-trip through this method — including via
         * `isInsideRoot()`'s own re-derivation — without being mistaken
         * for hostile external input. Genuine escapes (a different drive,
         * a UNC share, an unrelated POSIX path) still fail, just later,
         * at the confinement check, with a precise OUTSIDE_ROOT reason
         * instead of a blanket rejection.
         */
        private function toAbsolute( string $path ): string {
                $path = trim( $path );
                if ( '' === $path ) {
                        throw new PathGuardException( 'Empty path.', PathGuardException::E_INVALID );
                }

                // Strip null bytes — a classic trick to bypass suffix checks.
                if ( str_contains( $path, "\0" ) ) {
                        throw new PathGuardException( 'Invalid path.', PathGuardException::E_INVALID );
                }

                $normalized = $this->normalizeSlashes( $path );

                if ( $this->looksAbsolute( $normalized ) ) {
                        $absolute = $normalized;
                } else {
                        $absolute = $this->normalizeSlashes( $this->root ) . $normalized;
                }

                return rtrim( $absolute, '/' );
        }

        /**
         * Whether a slash-normalized path is already absolute in ANY
         * recognized form (POSIX, Windows drive-letter, or UNC). Does not
         * judge whether the path is inside root — only whether it should
         * be treated as a complete path rather than one to prepend the
         * root to.
         */
        private function looksAbsolute( string $normalized ): bool {
                // POSIX absolute, and also the shared prefix of a
                // normalized UNC path ("//server/share/...").
                if ( str_starts_with( $normalized, '/' ) ) {
                        return true;
                }
                // Windows drive-letter absolute, e.g. "C:/Windows/System32".
                if ( preg_match( '#^[a-z]:/#i', $normalized ) ) {
                        return true;
                }
                return false;
        }

        /**
         * Detect traversal segments BEFORE canonicalization. Even though
         * prefix confinement would already catch escapes, refusing `..`
         * explicitly keeps audit messages meaningful.
         */
        private function looksLikeTraversal( string $absolute ): bool {
                foreach ( explode( '/', $absolute ) as $segment ) {
                        if ( '..' === $segment ) {
                                return true;
                        }
                }
                return false;
        }

        private function normalizeSlashes( string $path ): string {
                return str_replace( '\\', '/', $path );
        }

        /**
         * Case-aware equality: case-insensitive on Windows (matching real
         * filesystem semantics there), case-sensitive everywhere else.
         * Both inputs are slash-normalized first so callers never need to
         * pre-normalize.
         */
        private function pathsEqual( string $a, string $b ): bool {
                $a = $this->normalizeSlashes( $a );
                $b = $this->normalizeSlashes( $b );
                return $this->caseInsensitive ? 0 === strcasecmp( $a, $b ) : $a === $b;
        }

        /**
         * Case-aware prefix check: case-insensitive on Windows,
         * case-sensitive everywhere else. Both inputs are slash-normalized
         * first so callers never need to pre-normalize.
         */
        private function pathStartsWith( string $haystack, string $prefix ): bool {
                $haystack = $this->normalizeSlashes( $haystack );
                $prefix   = $this->normalizeSlashes( $prefix );

                if ( $this->caseInsensitive ) {
                        return 0 === strncasecmp( $haystack, $prefix, strlen( $prefix ) );
                }
                return str_starts_with( $haystack, $prefix );
        }
}
