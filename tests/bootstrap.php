<?php
/**
 * Test bootstrap: loads the WP shim, then the plugin bootstrap, then
 * fires the boot hooks so the kernel initializes headlessly.
 *
 * @package AIOS\Tests
 */

declare( strict_types=1 );

require_once __DIR__ . '/shim/wp-functions.php';

// Load the plugin bootstrap (defines constants + autoloader + kernel).
require_once dirname( __DIR__ ) . '/ai-wordpress-os.php';

// Every AIOS\Tests\*Test class extends this base — required here (not
// just by tests/run.php) so real PHPUnit can also resolve the class
// when it is used from phpunit.xml.dist (BUG-003: PHPUnit previously
// fataled with "Class AIOS\Tests\TestCase not found" because only the
// bundled runner, not this shared bootstrap, loaded it).
require_once __DIR__ . '/TestCase.php';

// Deterministic test collaborators for repository fault-injection
// coverage (Sprint 0.3A Phase 2 exit-gate closure) — required here for
// the same reason as TestCase.php above: the AIOS\ autoloader only
// knows about src/, not tests/.
require_once __DIR__ . '/Support/FaultInjectingDatabase.php';
require_once __DIR__ . '/Support/FaultInjectingCrypto.php';

// Boot the kernel like WordPress would on plugins_loaded.
__run_hook( 'plugins_loaded' );
