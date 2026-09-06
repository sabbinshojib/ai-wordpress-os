<?php
/**
 * Bridges the project's hand-rolled test suite into real PHPUnit
 * (spec BUG-003).
 *
 * Every AIOS\Tests\Unit\*Test / AIOS\Tests\Integration\*Test class
 * extends this project's own AIOS\Tests\TestCase (tests/TestCase.php)
 * — a small, dependency-free base with its own reflection-driven
 * `run()` method and its own `assert*()` helpers that ACCUMULATE
 * failures into an array rather than throwing, so `tests/run.php` can
 * print a full report without stopping at the first failure.
 *
 * That execution model is fundamentally different from PHPUnit's own
 * (each PHPUnit assertion throws on failure; PHPUnit drives execution
 * via PHPUnit\Framework\TestCase::runBare()). Making the shared base
 * class extend PHPUnit\Framework\TestCase while keeping its own
 * non-throwing assert*() methods would be actively dangerous: under
 * real PHPUnit's execution model a recorded-but-not-thrown failure
 * would be silently reported as a PASS — the "manufactured green
 * result" this sprint is explicitly required to avoid. Reimplementing
 * every existing *Test.php as a second, parallel PHPUnit-native class
 * would fix that but means duplicating ~150 test methods and letting
 * the two copies drift.
 *
 * This bridge instead keeps tests/run.php as the one, unduplicated
 * source of truth for every test's logic, and asks PHPUnit to treat
 * each native class's ENTIRE run() as a single real PHPUnit
 * assertion: if any of that class's test_* methods failed, the
 * bridge test fails and PHPUnit prints every native failure message
 * verbatim (method name + message), so nothing is hidden and a
 * regression is exactly as visible under `vendor/bin/phpunit` as it
 * is under `php tests/run.php`.
 *
 * tests/Unit and tests/Integration intentionally stay OUT of
 * phpunit.xml.dist's <testsuites> — pointing PHPUnit directly at
 * classes that do not extend PHPUnit\Framework\TestCase produces only
 * "does not extend PHPUnit\Framework\TestCase" warnings and zero
 * executed tests (confirmed while diagnosing BUG-003), which is worse
 * than not listing them at all.
 *
 * @package AIOS\Tests\PHPUnit
 */

declare( strict_types=1 );

namespace AIOS\Tests\PHPUnit;

use PHPUnit\Framework\TestCase;

final class NativeSuiteBridgeTest extends TestCase {

        /**
         * Discovers every native AIOS\Tests\{Unit,Integration}\*Test
         * class exactly the way tests/run.php does, so a new test file
         * dropped into either directory is picked up automatically with
         * no bridge-side bookkeeping.
         *
         * @return array<string, array{0: class-string, 1: string}>
         */
        public static function nativeTestClasses(): array {
                $cases = array();

                foreach ( array( 'Unit', 'Integration' ) as $suite ) {
                        $directory = dirname( __DIR__ ) . '/' . $suite;
                        foreach ( glob( $directory . '/*Test.php' ) ?: array() as $file ) {
                                require_once $file;
                                $classname = 'AIOS\\Tests\\' . $suite . '\\' . basename( $file, '.php' );
                                if ( class_exists( $classname ) ) {
                                        // Data set keys become part of the PHPUnit test
                                        // name, e.g. "…::test_native_suite_is_fully_green
                                        // with data set 'Unit\PathGuardTest'".
                                        $cases[ $suite . '\\' . basename( $file, '.php' ) ] = array( $classname, $suite );
                                }
                        }
                }

                return $cases;
        }

        /**
         * @dataProvider nativeTestClasses
         *
         * @param class-string $class
         */
        public function test_native_suite_is_fully_green( string $class, string $suite ): void {
                self::assertTrue(
                        is_subclass_of( $class, \AIOS\Tests\TestCase::class ),
                        "{$class} must extend the native AIOS\\Tests\\TestCase base"
                );

                /** @var \AIOS\Tests\TestCase $instance */
                $instance = new $class();
                $results  = $instance->run();

                self::assertNotEmpty( $results, "{$class} declared no test_* methods — an empty native class hides coverage, not proves it" );

                $failures = array();
                foreach ( $results as $result ) {
                        if ( ! $result['passed'] ) {
                                $failures[] = sprintf( "%s::%s\n    %s", $class, $result['test'], str_replace( "\n", "\n    ", $result['message'] ) );
                        }
                }

                self::assertSame(
                        array(),
                        $failures,
                        sprintf(
                                "%d of %d native test method(s) failed in %s (%s suite):\n\n%s",
                                count( $failures ),
                                count( $results ),
                                $class,
                                $suite,
                                implode( "\n\n", $failures )
                        )
                );
        }
}
