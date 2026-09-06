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
}
