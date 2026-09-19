<?php
/**
 * Unit tests: FailureDiagnoser (P3-B, T-112).
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Developer\Repository\FailureDiagnoser;
use AIOS\Developer\Repository\FailureDiagnosis;
use AIOS\Tests\TestCase;

final class FailureDiagnoserTest extends TestCase {

	private FailureDiagnoser $diagnoser;

	protected function setUp(): void {
		$this->diagnoser = new FailureDiagnoser();
	}

	public function test_parses_real_phpunit_failure_format(): void {
		// Representative of this project's own historical PHPUnit output
		// shape (default text reporter), as also used by
		// tests/Unit/DeveloperTaskPlannerTest.php-style suites.
		$payload = <<<TXT
PHPUnit 10.5.0 by Sebastian Bergmann and contributors.

F                                                                   1 / 1 (100%)

Time: 00:00.012, Memory: 6.00 MB

There was 1 failure:

1) AIOS\Tests\Unit\DeveloperTaskPlannerTest::test_planner_creates_read_only_plan_from_high_level_request
Failed asserting that 2 matches expected 0.

/home/shojib/Projects/ai-wordpress-os/tests/Unit/DeveloperTaskPlannerTest.php:97

FAILURES!
Tests: 1, Assertions: 2, Failures: 1.
TXT;

		$diagnoses = $this->diagnoser->diagnose( $payload, FailureDiagnoser::TOOL_PHPUNIT );

		$this->assertCount( 1, $diagnoses );
		$diagnosis = $diagnoses[0];
		$this->assertEquals( FailureDiagnosis::CATEGORY_PHPUNIT_FAILURE, $diagnosis->category() );
		$this->assertEquals( '/home/shojib/Projects/ai-wordpress-os/tests/Unit/DeveloperTaskPlannerTest.php', $diagnosis->file() );
		$this->assertEquals( 97, $diagnosis->line() );
		$this->assertStringContains( 'test_planner_creates_read_only_plan_from_high_level_request', $diagnosis->message() );
	}

	public function test_parses_multiple_phpunit_failures(): void {
		$payload = <<<TXT
1) AIOS\Tests\Unit\FooTest::test_one
Failed asserting that false is true.

/path/FooTest.php:10

2) AIOS\Tests\Unit\BarTest::test_two
Failed asserting that true is false.

/path/BarTest.php:20
TXT;

		$diagnoses = $this->diagnoser->diagnose( $payload, FailureDiagnoser::TOOL_PHPUNIT );
		$this->assertCount( 2, $diagnoses );
		$this->assertEquals( 10, $diagnoses[0]->line() );
		$this->assertEquals( 20, $diagnoses[1]->line() );
	}

	public function test_parses_phpstan_raw_format(): void {
		$payload = "src/Developer/Plan/DeveloperTaskPlanner.php:46:Parameter #1 \$request expects DeveloperTaskRequest, string given.\n";

		$diagnoses = $this->diagnoser->diagnose( $payload, FailureDiagnoser::TOOL_PHPSTAN );

		$this->assertCount( 1, $diagnoses );
		$this->assertEquals( FailureDiagnosis::CATEGORY_PHPSTAN_ERROR, $diagnoses[0]->category() );
		$this->assertEquals( 'src/Developer/Plan/DeveloperTaskPlanner.php', $diagnoses[0]->file() );
		$this->assertEquals( 46, $diagnoses[0]->line() );
	}

	public function test_parses_phpcs_default_report_format(): void {
		$payload = <<<TXT
FILE: src/Developer/Plan/DeveloperTaskPlanner.php
----------------------------------------------------------------------
FOUND 1 ERROR AFFECTING 1 LINE
----------------------------------------------------------------------
 46 | ERROR | [x] Missing file doc comment
----------------------------------------------------------------------
TXT;

		$diagnoses = $this->diagnoser->diagnose( $payload, FailureDiagnoser::TOOL_PHPCS );

		$this->assertCount( 1, $diagnoses );
		$this->assertEquals( FailureDiagnosis::CATEGORY_PHPCS_VIOLATION, $diagnoses[0]->category() );
		$this->assertEquals( 'src/Developer/Plan/DeveloperTaskPlanner.php', $diagnoses[0]->file() );
		$this->assertEquals( 46, $diagnoses[0]->line() );
	}

	public function test_auto_mode_detects_phpunit_without_explicit_tool(): void {
		$payload = "1) AIOS\\Tests\\Unit\\FooTest::test_one\nFailed asserting that false is true.\n\n/path/FooTest.php:10\n";
		$diagnoses = $this->diagnoser->diagnose( $payload );
		$this->assertEquals( FailureDiagnosis::CATEGORY_PHPUNIT_FAILURE, $diagnoses[0]->category() );
	}

	public function test_malformed_payload_is_marked_unparseable(): void {
		$diagnoses = $this->diagnoser->diagnose( "some random unrelated log output\nwith no recognizable pattern\n" );

		$this->assertCount( 1, $diagnoses );
		$this->assertTrue( $diagnoses[0]->isUnparseable() );
		$this->assertNull( $diagnoses[0]->file() );
		$this->assertNull( $diagnoses[0]->line() );
	}

	public function test_empty_payload_is_marked_unparseable(): void {
		$diagnoses = $this->diagnoser->diagnose( '' );
		$this->assertCount( 1, $diagnoses );
		$this->assertTrue( $diagnoses[0]->isUnparseable() );
	}

	public function test_diagnosis_rejects_unsupported_category(): void {
		$threw = false;
		try {
			new FailureDiagnosis( 'not_a_real_category', null, null, 'msg', 'phpunit' );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw );
	}

	public function test_diagnosis_rejects_non_positive_line(): void {
		$threw = false;
		try {
			new FailureDiagnosis( FailureDiagnosis::CATEGORY_PHPUNIT_FAILURE, 'x.php', 0, 'msg', 'phpunit' );
		} catch ( \InvalidArgumentException $e ) {
			$threw = true;
		}
		$this->assertTrue( $threw );
	}
}
