<?php
/**
 * FileReader — the ONLY sanctioned file reading path (spec §11).
 *
 * Phase 1: read-only. Every read:
 *   1. goes through PathGuard (canonicalization, traversal + escape
 *      prevention, protected list, extension allowlist);
 *   2. respects the file_read_enabled setting and a size cap;
 *   3. uses the WP Filesystem API when available (works on hosts
 *      where direct file_get_contents is restricted);
 *   4. returns a redacted copy of the content.
 *
 * Scopes: theme (per slug) and root (ABSPATH, restricted to Level 0
 * inspection of safe directories — wp-content and known doc paths).
 *
 * @package AIOS\Files
 */

declare( strict_types=1 );

namespace AIOS\Files;

use AIOS\Security\PathGuard;
use AIOS\Security\PathGuardException;
use AIOS\Settings\Settings;
use AIOS\Support\StructuredError;

final class FileReader {

        private PathGuard $guard;

        private Settings $settings;

        /**
         * Guarded root for this reader (e.g. the theme directory).
         */
        private string $scopeRoot;

        /**
         * Theme-slug-relative label for error messages.
         */
        private string $scopeLabel;

        private function __construct( PathGuard $guard, Settings $settings, string $scope_root, string $scope_label ) {
                $this->guard      = $guard;
                $this->settings   = $settings;
                $this->scopeRoot  = $scope_root;
                $this->scopeLabel = $scope_label;
        }

        /**
         * Reader scoped to a theme directory. The guard root IS the theme
         * directory, so theme-relative paths like
         * "template-parts/header.php" resolve inside it.
         */
        public static function forTheme( string $theme_slug ): self {
                $theme_dir = untrailingslashit( get_theme_root( $theme_slug ) ) . '/' . ltrim( $theme_slug, '/' );
                $guard     = new PathGuard( $theme_dir );
                return new self(
                        $guard,
                        self::settings(),
                        $guard->root(),
                        'theme:' . $theme_slug
                );
        }

        /**
         * Reader scoped to the WordPress installation root (Level 0
         * inspection). PathGuard's protected list applies.
         */
        public static function forRoot(): self {
                $guard = new PathGuard();
                return new self( $guard, self::settings(), $guard->root(), 'wordpress_root' );
        }

        /**
         * Expose the guarded root (used by inspection helpers).
         */
        public function root(): string {
                return $this->scopeRoot;
        }

        private static function settings(): Settings {
                $plugin = \AIOS\Core\Plugin::instance();
                if ( null !== $plugin && null !== $plugin->container() ) {
                        $settings = $plugin->container()->get( Settings::class );
                        if ( $settings instanceof Settings ) {
                                return $settings;
                        }
                }
                return new Settings();
        }

        /**
         * Read a file relative to the reader's scope.
         */
        public function read( string $relative_path ): FileReadResult {
                if ( ! $this->settings->fileReadEnabled() ) {
                        return FileReadResult::failed(
                                StructuredError::security( 'files.read_disabled', 'File inspection is disabled in the AI OS security settings.' )
                        );
                }

                try {
                        $absolute = $this->guard->resolveRead( $relative_path );
                } catch ( PathGuardException $e ) {
                        return FileReadResult::failed( $this->toError( $e ) );
                }

                // Size cap before reading anything.
                $max = $this->settings->fileReadMaxBytes();
                $size = @filesize( $absolute );
                if ( false !== $size && $size > $max ) {
                        return FileReadResult::failed(
                                StructuredError::validation(
                                        'files.too_large',
                                        sprintf( 'File is larger than the %d KB inspection limit.', (int) ( $max / 1024 ) )
                                )
                        );
                }

                $content = $this->readViaWpFilesystem( $absolute );
                if ( null === $content ) {
                        return FileReadResult::failed(
                                StructuredError::execution( 'files.read_failed', 'The file could not be read on this host.', array(), true )
                        );
                }

                if ( strlen( $content ) > $max ) {
                        $content = substr( $content, 0, $max ) . "\n… (truncated at inspection limit)";
                }

                return FileReadResult::ok( $absolute, $content );
        }

        /**
         * WP Filesystem API with graceful direct-read fallback.
         */
        private function readViaWpFilesystem( string $absolute ): ?string {
                if ( function_exists( 'WP_Filesystem' ) ) {
                        global $wp_filesystem;
                        if ( ( $wp_filesystem instanceof \WP_Filesystem_Base ) || WP_Filesystem() ) {
                                if ( $wp_filesystem->exists( $absolute ) && $wp_filesystem->is_readable( $absolute ) ) {
                                        $content = $wp_filesystem->get_contents( $absolute );
                                        if ( is_string( $content ) ) {
                                                return $content;
                                        }
                                }
                                return null;
                        }
                }

                // Direct fallback (test shims / unusual hosts).
                if ( is_readable( $absolute ) ) {
                        $content = @file_get_contents( $absolute ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fallback path only
                        return false === $content ? null : $content;
                }

                return null;
        }

        private function toError( PathGuardException $e ): StructuredError {
                return match ( $e->reason() ) {
                        PathGuardException::E_TRAVERSAL, PathGuardException::E_OUTSIDE_ROOT => StructuredError::security(
                                'files.path_blocked',
                                'This path is not within the allowed inspection scope (' . $this->scopeLabel . ').'
                        ),
                        PathGuardException::E_PROTECTED => StructuredError::security(
                                'files.protected',
                                $e->getMessage()
                        ),
                        PathGuardException::E_EXTENSION => StructuredError::validation(
                                'files.extension_not_allowed',
                                $e->getMessage()
                        ),
                        PathGuardException::E_NOT_FOUND => StructuredError::notFound(
                                'files.not_found',
                                $e->getMessage()
                        ),
                        default => StructuredError::validation( 'files.invalid_path', $e->getMessage() ),
                };
        }
}
