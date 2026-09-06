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
 *     Windows-style hosts);
 *   - denies access to a configurable protected list (wp-config.php,
 *     .env, .htaccess, php.ini, logs, uploads-sibling secrets…);
 *   - denies access to the AI OS plugin's own directory (self-tamper
 *     protection: the agent must never edit its own code).
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

        public function __construct( ?string $root = null ) {
                // Normalize: guard roots always carry a trailing slash so that
                // prefix-confinement checks cannot be bypassed by sibling
                // directories sharing a name prefix.
                $raw_root   = $root ?? self::defaultRoot();
                $this->root = rtrim( $raw_root, '/' ) . '/';

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
         */
        public function isInsideRoot( string $path ): bool {
                $absolute = $this->toAbsolute( $path );
                $root     = $this->normalizeSlashes( $this->root );

                return str_starts_with( $this->normalizeSlashes( $absolute ), $root );
        }

        /**
         * Whether a resolved absolute path is on the protected list.
         */
        public function isProtected( string $absolute ): bool {
                $normalized = $this->normalizeSlashes( $absolute );
                $root       = $this->normalizeSlashes( $this->root );

                foreach ( array_merge( self::DEFAULT_PROTECTED, $this->extraProtected ) as $protected ) {
                        $protected = $this->normalizeSlashes( $protected );

                        // Root-relative match (e.g. "wp-config.php").
                        if ( $root . ltrim( $protected, '/' ) === $normalized ) {
                                return true;
                        }
                        // Absolute match.
                        if ( $protected === $normalized ) {
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
                        if ( str_starts_with( $normalized, $plugin_dir ) ) {
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

                // Post-realpath re-check: symlinks can pivot outside the root.
                $real_normalized = $this->normalizeSlashes( $real );
                $root            = $this->normalizeSlashes( $this->root );
                if ( ! str_starts_with( $real_normalized, $root ) ) {
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

                // Windows drive letters (C:/...) and UNC paths are not ours.
                if ( preg_match( '/^[a-z]:/i', $normalized ) || str_starts_with( $normalized, '//' ) ) {
                        throw new PathGuardException( 'Invalid path.', PathGuardException::E_INVALID );
                }

                if ( str_starts_with( $normalized, '/' ) ) {
                        $absolute = $normalized;
                } else {
                        $absolute = $this->normalizeSlashes( $this->root ) . $normalized;
                }

                return rtrim( $absolute, '/' );
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
}
