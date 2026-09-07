<?php
/**
 * Read-only validator for .claude/settings.local.json. Performs no
 * writes. Exits non-zero on a parse error.
 */

declare( strict_types=1 );

$root = dirname( __DIR__, 2 );
$path = $root . '/.claude/settings.local.json';

if ( ! is_file( $path ) ) {
	fwrite( STDERR, "settings file not found: {$path}\n" );
	exit( 1 );
}

$contents = file_get_contents( $path );
if ( false === $contents ) {
	fwrite( STDERR, "failed to read: {$path}\n" );
	exit( 1 );
}

json_decode( $contents, true );
if ( JSON_ERROR_NONE !== json_last_error() ) {
	fwrite( STDERR, 'INVALID JSON: ' . json_last_error_msg() . "\n" );
	exit( 1 );
}

echo "VALID JSON\n";
exit( 0 );
