<?php
/**
 * Portable string helpers (spec BUG-004).
 *
 * `mbstring` is not a hard requirement of PHP itself — a host can
 * ship without it. The plugin previously called `mb_strlen()`/
 * `mb_substr()` directly in a dozen places; on a host missing the
 * extension every one of those call sites fataled with an uncaught
 * `Error: Call to undefined function`, confirmed for real by running
 * the test suite under a bare `php -n` (no ini) environment.
 *
 * These helpers prefer the multi-byte-safe functions when available
 * and fall back to byte-based equivalents otherwise. The fallback is
 * an accepted, documented approximation, never a security control:
 *
 *   - `length()`'s fallback (byte count) is always >= the true
 *     character count for UTF-8 text, so a `maxLength` schema check
 *     built on it can only be stricter than intended (reject slightly
 *     early), never more permissive.
 *   - `truncate()`'s fallback trims any trailing incomplete UTF-8
 *     continuation bytes after a byte-based cut, so it cannot leave a
 *     broken multi-byte sequence at the stored value's boundary —
 *     it can still cut one character earlier than mb_substr() would
 *     on a host without mbstring, which is an acceptable degradation
 *     for a field-length cap, not a correctness-critical guarantee.
 *
 * @package AIOS\Support
 */

declare( strict_types=1 );

namespace AIOS\Support;

final class Strings {

        /**
         * Character-safe length when `mbstring` is loaded; a portable
         * byte-length fallback otherwise.
         */
        public static function length( string $text ): int {
                return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : self::lengthFallback( $text );
        }

        /**
         * The portable fallback on its own, exposed so it can be tested
         * deterministically regardless of whether `mbstring` actually
         * happens to be loaded in the environment running the test.
         */
        public static function lengthFallback( string $text ): int {
                return strlen( $text );
        }

        /**
         * Character-safe truncation to at most `$max_chars` characters
         * when `mbstring` is loaded; a portable byte-based fallback
         * otherwise.
         */
        public static function truncate( string $text, int $max_chars ): string {
                return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max_chars ) : self::truncateFallback( $text, $max_chars );
        }

        /**
         * The portable fallback on its own (see `truncate()`), exposed
         * for deterministic testing. `$max_bytes` is treated as a byte
         * budget in this path (mbstring-absent hosts have no cheap way
         * to count characters), then backed off from any trailing
         * incomplete UTF-8 continuation byte (0b10xxxxxx) so the result
         * is never a malformed byte sequence.
         */
        public static function truncateFallback( string $text, int $max_bytes ): string {
                if ( strlen( $text ) <= $max_bytes ) {
                        return $text;
                }

                $truncated = substr( $text, 0, $max_bytes );
                $length    = strlen( $truncated );

                // Walk back (at most 4 bytes — the longest a UTF-8 sequence
                // can be) to find the lead byte of the LAST sequence in the
                // truncated string. A continuation byte matches 10xxxxxx;
                // anything else starts a sequence (1 byte for plain ASCII,
                // 2-4 for a multi-byte lead byte).
                for ( $back = 0; $back < 4 && $back < $length; $back++ ) {
                        $byte = ord( $truncated[ $length - 1 - $back ] );
                        if ( 0x80 === ( $byte & 0xC0 ) ) {
                                continue; // Still inside a continuation run.
                        }

                        $expected_length = match ( true ) {
                                0xF0 === ( $byte & 0xF8 ) => 4,
                                0xE0 === ( $byte & 0xF0 ) => 3,
                                0xC0 === ( $byte & 0xE0 ) => 2,
                                default                   => 1, // ASCII lead byte.
                        };

                        // The sequence starting at this lead byte needs
                        // $expected_length bytes total; only $back + 1 are
                        // actually present before the cut. Drop the whole
                        // (incomplete) sequence rather than keep a partial
                        // one.
                        if ( $expected_length > $back + 1 ) {
                                return substr( $truncated, 0, $length - $back - 1 );
                        }
                        break;
                }

                return $truncated;
        }
}
