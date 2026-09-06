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

// Boot the kernel like WordPress would on plugins_loaded.
__run_hook( 'plugins_loaded' );
