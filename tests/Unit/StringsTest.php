<?php
/**
 * Unit tests: AIOS\Support\Strings — the portable mbstring fallback
 * introduced for BUG-004.
 *
 * The *Fallback() methods are tested directly (not by trying to
 * "unload" mbstring, which PHP cannot do at runtime) so the portable
 * code path is verified deterministically regardless of whether
 * mbstring actually happens to be loaded wherever this test runs.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Support\Strings;
use AIOS\Tests\TestCase;

final class StringsTest extends TestCase {

        // -------------------------------------------------------------- length()

        public function test_length_matches_mbstring_and_fallback_for_ascii(): void {
                $this->assertEquals( 5, Strings::length( 'hello' ) );
                $this->assertEquals( 5, Strings::lengthFallback( 'hello' ) );
        }

        public function test_length_dispatches_to_mbstring_when_available(): void {
                // "café" is 4 characters but 5 bytes in UTF-8 (é = 2 bytes).
                // When mbstring IS loaded, length() must report the
                // character count, not the byte count.
                if ( ! function_exists( 'mb_strlen' ) ) {
                        $this->assert( true, 'mbstring not loaded in this environment; nothing to assert here' );
                        return;
                }
                $this->assertEquals( 4, Strings::length( "caf\xc3\xa9" ) );
        }

        public function test_length_fallback_counts_bytes_not_characters(): void {
                // Documented, accepted approximation: the fallback counts
                // bytes, which is >= the true character count for UTF-8.
                $this->assertEquals( 5, Strings::lengthFallback( "caf\xc3\xa9" ) );
        }

        // ------------------------------------------------------------ truncate()

        public function test_truncate_matches_for_pure_ascii(): void {
                $this->assertEquals( 'hello', Strings::truncate( 'hello world', 5 ) );
                $this->assertEquals( 'hello', Strings::truncateFallback( 'hello world', 5 ) );
        }

        public function test_truncate_returns_short_strings_unchanged(): void {
                $this->assertEquals( 'hi', Strings::truncate( 'hi', 10 ) );
                $this->assertEquals( 'hi', Strings::truncateFallback( 'hi', 10 ) );
        }

        public function test_truncate_fallback_never_leaves_a_broken_utf8_tail(): void {
                // "café" = 'c' 'a' 'f' then 0xC3 0xA9 (2-byte 'é'). Cutting at
                // byte 4 would land INSIDE that 2-byte sequence (after the
                // 0xC3 lead byte); the fallback must back off to byte 3
                // instead of returning a malformed sequence.
                $text      = "caf\xc3\xa9";
                $truncated = Strings::truncateFallback( $text, 4 );
                $this->assertEquals( 'caf', $truncated );

                // The result must be valid UTF-8 on its own.
                $this->assertTrue( 1 === preg_match( '//u', $truncated ), 'fallback result must be valid UTF-8' );
        }

        public function test_truncate_fallback_keeps_a_complete_multibyte_character_when_it_fits(): void {
                $text      = "caf\xc3\xa9"; // 5 bytes total.
                $truncated = Strings::truncateFallback( $text, 5 );
                $this->assertEquals( $text, $truncated );
        }

        public function test_truncate_dispatches_to_mbstring_when_available(): void {
                if ( ! function_exists( 'mb_substr' ) ) {
                        $this->assert( true, 'mbstring not loaded in this environment; nothing to assert here' );
                        return;
                }
                // mb_substr() counts CHARACTERS: truncating "café" to 4
                // characters must return the whole word unchanged.
                $this->assertEquals( "caf\xc3\xa9", Strings::truncate( "caf\xc3\xa9", 4 ) );
        }
}
