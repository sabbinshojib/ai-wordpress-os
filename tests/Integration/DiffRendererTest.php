<?php
/**
 * Integration tests: real reviewable Diff engine (Sprint 0.3A Phase 2
 * hardening, Package B). DiffRenderer + MutationDiff + OperationDiff
 * replace the earlier one-line-per-operation preview with an actual
 * before/after representation, redacted for secret-shaped/binary/
 * oversized content. Uses real filesystem/option state, hence
 * Integration rather than Unit.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\DiffRenderer;
use AIOS\Mutation\Operations\FileCreateOperation;
use AIOS\Mutation\Operations\FileDeleteOperation;
use AIOS\Mutation\Operations\FilePatchOperation;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Mutation\OperationDiff;
use AIOS\Mutation\Snapshot;
use AIOS\Security\PathGuard;
use AIOS\Tests\TestCase;

final class DiffRendererTest extends TestCase {

	private string $tempRoot;

	private PathGuard $pathGuard;

	protected function setUp(): void {
		$this->resetPlugin();
		$this->tempRoot = rtrim( sys_get_temp_dir(), '/\\' ) . '/aios-diff-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tempRoot, 0777, true );
		$this->pathGuard = new PathGuard( $this->tempRoot );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->tempRoot . '/*' ) ?: array() as $file ) {
			@unlink( $file );
		}
		@rmdir( $this->tempRoot );
	}

	/**
	 * @return array<string, Snapshot>
	 */
	private function snapshotsFor( ChangeSet $change_set ): array {
		$snapshots = array();
		foreach ( $change_set->operations() as $operation ) {
			$snapshots[ $operation->id() ] = $operation->captureSnapshot();
		}
		return $snapshots;
	}

	public function test_file_create_renders_as_pure_addition(): void {
		$op = new FileCreateOperation( 'new.txt', "line one\nline two", $this->pathGuard );
		$cs = new ChangeSet( 1, array( $op ) );
		$diff = DiffRenderer::render( $cs, $this->snapshotsFor( $cs ) );

		$this->assertCount( 1, $diff->operations );
		$od = $diff->operations[0];
		$this->assertEquals( OperationDiff::KIND_TEXT, $od->kind );
		$this->assertEquals( 2, $od->summary['added'] );
		$this->assertEquals( 0, $od->summary['removed'] );
		$this->assertNull( $od->beforeHash );
		$this->assertNotNull( $od->afterHash );
	}

	public function test_file_patch_renders_before_after_diff(): void {
		file_put_contents( $this->tempRoot . '/existing.txt', "old line\nkeep line" );
		$op = new FilePatchOperation( 'existing.txt', "new line\nkeep line", $this->pathGuard );
		$cs = new ChangeSet( 1, array( $op ) );
		$diff = DiffRenderer::render( $cs, $this->snapshotsFor( $cs ) );

		$od = $diff->operations[0];
		$this->assertEquals( OperationDiff::KIND_TEXT, $od->kind );
		$this->assertEquals( 1, $od->summary['added'] );
		$this->assertEquals( 1, $od->summary['removed'] );
		$this->assertNotNull( $od->unified );
		$this->assertTrue( str_contains( $od->unified, '-old line' ) );
		$this->assertTrue( str_contains( $od->unified, '+new line' ) );
	}

	public function test_file_delete_renders_as_pure_removal(): void {
		file_put_contents( $this->tempRoot . '/gone.txt', "bye\n" );
		$op = new FileDeleteOperation( 'gone.txt', $this->pathGuard );
		$cs = new ChangeSet( 1, array( $op ) );
		$diff = DiffRenderer::render( $cs, $this->snapshotsFor( $cs ) );

		$od = $diff->operations[0];
		$this->assertEquals( 1, $od->summary['removed'] );
		$this->assertEquals( 0, $od->summary['added'] );
		$this->assertNotNull( $od->beforeHash );
		$this->assertNull( $od->afterHash );
	}

	public function test_option_update_renders_as_value_kind(): void {
		update_option( 'aios_diff_test_option', array( 'x' => 1 ) );
		$op = new OptionUpdateOperation( 'aios_diff_test_option', array( 'x' => 2 ) );
		$cs = new ChangeSet( 1, array( $op ) );
		$diff = DiffRenderer::render( $cs, $this->snapshotsFor( $cs ) );

		$od = $diff->operations[0];
		$this->assertEquals( OperationDiff::KIND_VALUE, $od->kind );
		$this->assertNotEquals( $od->beforeHash, $od->afterHash );
	}

	public function test_secret_shaped_value_is_redacted_not_diffed(): void {
		$secret = 'sk-abcdefghijklmnopqrstuvwxyz123456';
		update_option( 'aios_diff_secret_option', 'placeholder' );
		$op = new OptionUpdateOperation( 'aios_diff_secret_option', $secret );
		$cs = new ChangeSet( 1, array( $op ) );
		$diff = DiffRenderer::render( $cs, $this->snapshotsFor( $cs ) );

		$od = $diff->operations[0];
		$this->assertEquals( OperationDiff::KIND_SECRET, $od->kind );
		$this->assertNull( $od->unified );
		// The diff's own array/JSON representation must never contain the raw secret.
		$this->assertFalse( str_contains( wp_json_encode( $od->toArray() ) ?: '', $secret ) );
	}

	public function test_binary_content_is_redacted_not_diffed(): void {
		file_put_contents( $this->tempRoot . '/bin.txt', "abc\0def" );
		$op = new FilePatchOperation( 'bin.txt', "abc\0xyz", $this->pathGuard );
		$cs = new ChangeSet( 1, array( $op ) );
		$diff = DiffRenderer::render( $cs, $this->snapshotsFor( $cs ) );

		$od = $diff->operations[0];
		$this->assertEquals( OperationDiff::KIND_BINARY, $od->kind );
		$this->assertNull( $od->unified );
	}

	public function test_oversized_content_is_truncated_with_hashes_retained(): void {
		$big = str_repeat( 'x', 300000 ); // > 256 KiB bound
		$op  = new FileCreateOperation( 'big.txt', $big, $this->pathGuard );
		$cs  = new ChangeSet( 1, array( $op ) );
		$diff = DiffRenderer::render( $cs, $this->snapshotsFor( $cs ) );

		$od = $diff->operations[0];
		$this->assertTrue( $od->truncated );
		$this->assertNull( $od->unified );
		$this->assertNotNull( $od->afterHash );
	}

	public function test_mutation_diff_hash_is_deterministic_for_identical_diffs(): void {
		$op1 = new FileCreateOperation( 'a.txt', 'hello', $this->pathGuard );
		$cs1 = new ChangeSet( 1, array( $op1 ) );
		$diff1 = DiffRenderer::render( $cs1, $this->snapshotsFor( $cs1 ) );

		$this->assertEquals( $diff1->hash(), $diff1->hash() );
	}

	public function test_mutation_diff_hash_changes_when_content_differs(): void {
		$op1 = new FileCreateOperation( 'a.txt', 'hello', $this->pathGuard );
		$cs1 = new ChangeSet( 1, array( $op1 ) );
		$diff1 = DiffRenderer::render( $cs1, $this->snapshotsFor( $cs1 ) );

		$op2 = new FileCreateOperation( 'b.txt', 'goodbye', $this->pathGuard );
		$cs2 = new ChangeSet( 1, array( $op2 ) );
		$diff2 = DiffRenderer::render( $cs2, $this->snapshotsFor( $cs2 ) );

		$this->assertNotEquals( $diff1->hash(), $diff2->hash() );
	}
}
