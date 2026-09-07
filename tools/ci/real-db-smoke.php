<?php
/**
 * Real WordPress + real MySQL smoke script (Sprint 0.3A Phase 2 final
 * hardening, Package P). Run only via `wp eval-file` from the CI
 * workflow's "test-real-wp-mysql" job — see .github/workflows/ci.yml
 * for the job that installs a throwaway WordPress + MySQL and
 * activates this plugin before invoking this file.
 *
 * Deliberately NOT part of the plugin's own test suite (tests/run.php
 * runs entirely against the WP-like shim in tests/shim/, by design —
 * see docs/ARCHITECTURE.md). This script is the one thing in the
 * repository that ever proves the real dbDelta migrations, real
 * wpdb-backed CRUD, and a real MySQL compare-and-swap all actually
 * work outside that shim's in-memory fake SQL engine.
 *
 * THIS SCRIPT HAS NEVER BEEN EXECUTED — authored with no GitHub
 * Actions runner and no local MySQL/WordPress install available (see
 * the job's own docblock in ci.yml). Exits non-zero on the first
 * failure so the CI step fails loudly rather than a silent pass.
 *
 * @package AIOS\Tools\Ci
 */

declare( strict_types=1 );

use AIOS\Core\Plugin;
use AIOS\Database\Database;
use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\ChangeSetFingerprint;
use AIOS\Mutation\ChangeSetRepository;
use AIOS\Mutation\ChangeSetState;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Mutation\OperationJournalRepository;

$failures = array();

function aios_smoke_check( string $label, bool $ok, array &$failures ): void {
	echo ( $ok ? '[PASS] ' : '[FAIL] ' ) . $label . "\n";
	if ( ! $ok ) {
		$failures[] = $label;
	}
}

$plugin = Plugin::instance();
aios_smoke_check( 'plugin booted (Plugin::instance() is not null)', null !== $plugin, $failures );
if ( null === $plugin ) {
	fwrite( STDERR, "Plugin never booted — cannot continue.\n" );
	exit( 1 );
}

$container = $plugin->container();

// ---------------------------------------------------------------- table existence + columns

global $wpdb;
$db = $container->get( Database::class );

$expected_columns = array(
	Database::TABLE_CHANGE_SETS       => array( 'id', 'change_set_id', 'state', 'state_version', 'payload_ciphertext', 'payload_hash', 'lease_owner', 'lease_expires_at' ),
	Database::TABLE_OPERATION_JOURNAL => array( 'id', 'change_set_id', 'operation_index', 'state', 'state_version', 'recovery_ciphertext', 'recovery_hash' ),
);

foreach ( Database::ALL_TABLES as $short_name ) {
	$table  = $db->table( $short_name );
	$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	aios_smoke_check( "table exists: {$table}", $exists, $failures );

	if ( $exists && isset( $expected_columns[ $short_name ] ) ) {
		$columns = array_column( $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only, not user input.
		foreach ( $expected_columns[ $short_name ] as $expected_column ) {
			aios_smoke_check( "{$table}.{$expected_column} column exists", in_array( $expected_column, $columns, true ), $failures );
		}
	}
}

// ---------------------------------------------------------------- real CRUD + encryption round-trip

$change_set_repo = $container->get( ChangeSetRepository::class );
$journal_repo     = $container->get( OperationJournalRepository::class );

$secret_value = 'ci-smoke-secret-' . bin2hex( random_bytes( 8 ) );
$cs           = new ChangeSet( 1, array( new OptionUpdateOperation( 'aios_ci_smoke_option', $secret_value ) ) );
$fingerprint  = ChangeSetFingerprint::compute( $cs );

$row = $change_set_repo->create( $cs, $fingerprint );
aios_smoke_check( 'ChangeSetRepository::create() persisted a row with the ChangeSet\'s own id', $row['change_set_id'] === $cs->id(), $failures );
aios_smoke_check( 'new row starts PLANNED', ChangeSetState::PLANNED === $row['state'], $failures );

$loaded = $change_set_repo->load( $cs->id() );
aios_smoke_check( 'ChangeSetRepository::load() round-trips against real MySQL', null !== $loaded && $loaded['change_set_id'] === $cs->id(), $failures );
aios_smoke_check( 'payload_ciphertext never contains the plaintext secret', ! str_contains( (string) ( $loaded['payload_ciphertext'] ?? '' ), $secret_value ), $failures );

$journal_row = $journal_repo->create( $cs->id(), 0, $cs->operations()[0], 1 );
aios_smoke_check( 'OperationJournalRepository::create() persisted against real MySQL', $journal_row['change_set_id'] === $cs->id() && 0 === $journal_row['operation_index'], $failures );

// ---------------------------------------------------------------- real MySQL compare-and-swap

$cas_ok = $change_set_repo->transition( $cs->id(), ChangeSetState::PLANNED, ChangeSetState::SNAPSHOTTED, 0 );
aios_smoke_check( 'first CAS transition (PLANNED -> SNAPSHOTTED, version 0) succeeds', $cas_ok, $failures );

$cas_stale = $change_set_repo->transition( $cs->id(), ChangeSetState::PLANNED, ChangeSetState::SNAPSHOTTED, 0 );
aios_smoke_check( 'repeating the same CAS with the now-stale expected version fails', false === $cas_stale, $failures );

// ---------------------------------------------------------------- summary

echo str_repeat( '-', 68 ) . "\n";
if ( array() === $failures ) {
	echo "REAL DB SMOKE: all checks passed.\n";
	exit( 0 );
}

echo 'REAL DB SMOKE: ' . count( $failures ) . " check(s) failed:\n";
foreach ( $failures as $failure ) {
	echo "  - {$failure}\n";
}
exit( 1 );
