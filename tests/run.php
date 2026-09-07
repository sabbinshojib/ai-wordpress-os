<?php
/**
 * Standalone test runner: discovers tests/Unit + tests/Integration,
 * runs each TestCase, prints a report.
 *
 * Usage: php tests/run.php [ClassNameSubstring]
 *
 * The optional argument narrows the run to test classes whose short
 * name contains it (case-sensitive substring match) — e.g.
 * `php tests/run.php CrashRecoveryTest` — so a single suspect class
 * can be inspected without the full-suite output being large enough
 * to require external truncation/redirection.
 *
 * @package AIOS\Tests
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/TestCase.php';

$filter = $argv[1] ?? null;

// Discover test classes.
$classes = array();
foreach ( array( 'Unit', 'Integration' ) as $suite ) {
        $namespace = 'AIOS\\Tests\\' . $suite;
        $directory = __DIR__ . '/' . $suite;
        foreach ( glob( $directory . '/*Test.php' ) ?: array() as $file ) {
                require_once $file;
                $classname = $namespace . '\\' . basename( $file, '.php' );
                if ( ! class_exists( $classname ) ) {
                        fwrite( STDERR, "warning: no class [{$classname}] in {$file}\n" );
                        continue;
                }
                if ( null !== $filter && ! str_contains( basename( $file, '.php' ), $filter ) ) {
                        continue;
                }
                $classes[] = $classname;
        }
}

$total = 0;
$failed = 0;
$start = microtime( true );

echo "AI WordPress OS — test suite\n";
echo str_repeat( '=', 68 ) . "\n";

foreach ( $classes as $class ) {
        $suite = new $class();
        $results = $suite->run();
        $short = substr( $class, strrpos( $class, '\\' ) + 1 );

        foreach ( $results as $result ) {
                $total++;
                $status = $result['passed'] ? 'PASS' : 'FAIL';
                $line   = sprintf( "[%s] %-52s", $status, $short . '::' . $result['test'] );
                echo $line . "\n";
                if ( ! $result['passed'] ) {
                        $failed++;
                        echo "       └─ " . str_replace( "\n", "\n       ", $result['message'] ) . "\n";
                }
        }
}

$duration = (int) ( ( microtime( true ) - $start ) * 1000 );
echo str_repeat( '=', 68 ) . "\n";
printf( "%d tests, %d failures (%d ms)\n", $total, $failed, $duration );
exit( $failed > 0 ? 1 : 0 );
