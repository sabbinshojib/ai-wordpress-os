<?php
/**
 * Unit tests: RepairProposer / RepairProposal (P3-B, T-113).
 *
 * Verifies the non-binding, non-executable boundary: a RepairProposal must
 * never become, wrap, or reference an AIOS\Mutation\OperationSpecification.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Developer\Repository\FailureDiagnosis;
use AIOS\Developer\Repository\RepairProposal;
use AIOS\Developer\Repository\RepairProposer;
use AIOS\Tests\TestCase;

final class RepairProposerTest extends TestCase {

	private RepairProposer $proposer;

	protected function setUp(): void {
		$this->proposer = new RepairProposer();
	}

	public function test_proposes_actionable_repair_for_located_phpunit_diagnosis(): void {
		$diagnosis = new FailureDiagnosis(
			FailureDiagnosis::CATEGORY_PHPUNIT_FAILURE,
			'tests/Unit/FooTest.php',
			42,
			'FooTest::test_bar — Failed asserting that false is true.',
			'phpunit'
		);

		$proposal = $this->proposer->propose( $diagnosis );

		$this->assertTrue( $proposal instanceof RepairProposal );
		$this->assertTrue( $proposal->actionable() );
		$this->assertEquals( 'tests/Unit/FooTest.php', $proposal->targetFile() );
		$this->assertEquals( 42, $proposal->targetLine() );
		$this->assertEquals( RepairProposal::CONFIDENCE_MEDIUM, $proposal->confidence() );
		$this->assertStringContains( 'PHPUnit test failure', $proposal->summary() );
	}

	public function test_proposes_non_actionable_repair_for_unparseable_diagnosis(): void {
		$diagnosis = new FailureDiagnosis( FailureDiagnosis::CATEGORY_UNPARSEABLE, null, null, 'no pattern', 'auto' );

		$proposal = $this->proposer->propose( $diagnosis );

		$this->assertFalse( $proposal->actionable() );
		$this->assertNull( $proposal->targetFile() );
		$this->assertNull( $proposal->targetLine() );
		$this->assertEquals( RepairProposal::CONFIDENCE_LOW, $proposal->confidence() );
	}

	public function test_proposal_low_confidence_without_location(): void {
		$diagnosis = new FailureDiagnosis( FailureDiagnosis::CATEGORY_PHPSTAN_ERROR, null, null, 'generic error', 'phpstan' );
		$proposal  = $this->proposer->propose( $diagnosis );

		$this->assertFalse( $proposal->actionable() );
		$this->assertEquals( RepairProposal::CONFIDENCE_LOW, $proposal->confidence() );
	}

	public function test_repair_proposal_rejects_empty_summary(): void {
		$threw = false;
		try {
			new RepairProposal( '', null, null, 'x', RepairProposal::CONFIDENCE_LOW, 'r', false );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw );
	}

	public function test_repair_proposal_rejects_unsupported_confidence(): void {
		$threw = false;
		try {
			new RepairProposal( 'summary', null, null, 'x', 'critical', 'r', false );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw );
	}

	public function test_repair_proposal_rejects_non_positive_target_line(): void {
		$threw = false;
		try {
			new RepairProposal( 'summary', 'x.php', 0, 'x', RepairProposal::CONFIDENCE_LOW, 'r', false );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw );
	}

	/**
	 * Structural (source-level) boundary check: neither RepairProposal
	 * nor RepairProposer may `use` (import) AIOS\Mutation\OperationSpecification
	 * or type-hint a method against it — a repair proposal is descriptive
	 * data, never an executable operation. Prose in comments explaining
	 * that boundary is fine; an actual import or type-hint is not.
	 */
	public function test_repair_proposal_never_imports_or_type_hints_operation_specification(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Developer/Repository/RepairProposal.php' );
		$this->assertFalse( str_contains( $source, 'use AIOS\\Mutation\\OperationSpecification' ) );
		$this->assertFalse( 1 === preg_match( '/:\s*\??OperationSpecification/', $source ) );
	}

	public function test_repair_proposer_never_imports_or_type_hints_operation_specification(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Developer/Repository/RepairProposer.php' );
		$this->assertFalse( str_contains( $source, 'use AIOS\\Mutation\\OperationSpecification' ) );
		$this->assertFalse( 1 === preg_match( '/:\s*\??OperationSpecification/', $source ) );
	}

	public function test_repair_proposal_to_array_is_json_safe(): void {
		$diagnosis = new FailureDiagnosis( FailureDiagnosis::CATEGORY_PHPCS_VIOLATION, 'x.php', 3, 'msg', 'phpcs' );
		$proposal  = $this->proposer->propose( $diagnosis );
		$encoded   = json_encode( $proposal->toArray() );
		$this->assertTrue( false !== $encoded );
	}
}
