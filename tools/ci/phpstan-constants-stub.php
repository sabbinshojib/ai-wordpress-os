<?php
/**
 * PHPStan-only constant stub.
 *
 * ai-wordpress-os.php defines the AI_WP_OS_* runtime constants, but it
 * also guards on ABSPATH and boots the real plugin kernel — it cannot
 * be loaded by PHPStan's bootstrapFiles. `paths` in phpstan.neon.dist
 * only covers src/, so those constants are otherwise invisible to
 * static analysis of files that reference them (e.g. AdminPages.php).
 *
 * This file exists only to give PHPStan the *types* of those
 * constants; it is never loaded at runtime and its values are
 * deliberately dummy placeholders, not copies of the real ones in
 * ai-wordpress-os.php, so there is a single source of truth for the
 * actual values.
 *
 * @package AIOS
 */

define( 'AI_WP_OS_VERSION', '0.0.0' );
define( 'AI_WP_OS_DB_VERSION', '0' );
define( 'AI_WP_OS_FILE', __FILE__ );
define( 'AI_WP_OS_DIR', '' );
define( 'AI_WP_OS_URL', '' );
define( 'AI_WP_OS_BASENAME', '' );
define( 'AI_WP_OS_SLUG', '' );
define( 'AI_WP_OS_REST_NAMESPACE', '' );
define( 'AI_WP_OS_MCP_PROTOCOL_VERSION', '' );
define( 'AI_WP_OS_SUPPORTED_MCP_VERSIONS', array() );
