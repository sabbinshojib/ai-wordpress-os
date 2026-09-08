<?php
/**
 * Unit tests: Diff engine.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Support\Diff;
use AIOS\Tests\TestCase;

final class DiffTest extends TestCase {

	public function test_identical_content_has_no_changes(): void {
		$result = Diff::compute( "a\nb\nc", "a\nb\nc" );
		$this->assertEquals( 0, $result['summary']['added'] );
		$this->assertEquals( 0, $result['summary']['removed'] );
	}

	public function test_pure_addition(): void {
		$result = Diff::compute( "a\nb", "a\nb\nc" );
		$this->assertEquals( 1, $result['summary']['added'] );
		$this->assertEquals( 0, $result['summary']['removed'] );
	}

	public function test_pure_removal(): void {
		$result = Diff::compute( "a\nb\nc", "a" );
		$this->assertEquals( 0, $result['summary']['added'] );
		$this->assertEquals( 2, $result['summary']['removed'] );
	}

	public function test_modification_is_add_plus_remove(): void {
		$result = Diff::compute( "one\ntwo\nthree", "one\nTWO\nthree" );
		$this->assertEquals( 1, $result['summary']['added'] );
		$this->assertEquals( 1, $result['summary']['removed'] );
	}

	public function test_unified_output_contains_hunks(): void {
		$result = Diff::compute( "a\nb\nc\nd\ne", "a\nX\nc\nd\ne" );
		$this->assertStringContains( '@@', $result['unified'] );
		$this->assertStringContains( '-b', $result['unified'] );
		$this->assertStringContains( '+X', $result['unified'] );
	}

	public function test_empty_inputs(): void {
		$this->assertEquals( array( 'added' => 0, 'removed' => 0 ), Diff::summary( '', '' ) );
		$this->assertEquals( array( 'added' => 1, 'removed' => 0 ), Diff::summary( '', 'new line' ) );
	}

	/**
	 * Two edits far enough apart (with context_lines=0) must render as
	 * two independent hunks whose own "@@ -old_start,..." coordinates
	 * reflect each hunk's real position in the old file — never a
	 * leftover start line copied from the previous hunk.
	 */
	public function test_unified_diff_hunks_have_independent_old_start_positions(): void {
		$old = "1\n2\n3\n4\n5\n6\n7\n8\n9\n10";
		$new = "1\nX\n3\n4\n5\n6\n7\nY\n8\n9\n10";

		$result = Diff::compute( $old, $new, 0 );

		preg_match_all( '/^@@ -(\d+),\d+ \+\d+,\d+ @@$/m', $result['unified'], $matches );
		$old_starts = array_map( 'intval', $matches[1] );

		$this->assertCount( 2, $old_starts, 'two far-apart edits must render as two independent hunks' );
		$this->assertGreaterThan(
			(float) $old_starts[0],
			(float) $old_starts[1],
			"the second hunk must report its own old-file position ({$old_starts[1]}), not reuse the first hunk's start line ({$old_starts[0]})"
		);
	}
}
