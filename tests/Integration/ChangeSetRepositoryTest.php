<?php
/**
 * Integration tests: durable ChangeSet persistence (Sprint 0.3A Phase
 * 2 hardening — Packages 1/2/3/6/7/8). Covers create/load, encrypted
 * payload round-trip and tamper/wrong-key detection, idempotent
 * create, compare-and-swap state transitions (including illegal and
 * stale-version rejection), the DB-backed execution lease, and
 * terminal-only purge.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Database\Database;
use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\ChangeSetFingerprint;
use AIOS\Mutation\ChangeSetRepository;
use AIOS\Mutation\ChangeSetState;
use AIOS\Mutation\MutationException;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Support\Crypto;
use AIOS\Tests\TestCase;

final class ChangeSetRepositoryTest extends TestCase {

	private ChangeSetRepository $repo;

	protected function setUp(): void {
		$this->resetPlugin();
		$this->repo = Plugin::instance()->container()->get( ChangeSetRepository::class );
	}

	private function makeChangeSet( string $option = 'aios_repo_test_option', string $value = 'v1' ): ChangeSet {
		return new ChangeSet( 1, array( new OptionUpdateOperation( $option, $value ) ) );
	}

	// -------------------------------------------------------------- create/load

	public function test_create_and_load_round_trip(): void {
		$cs  = $this->makeChangeSet();
		$fp  = ChangeSetFingerprint::compute( $cs );
		$row = $this->repo->create( $cs, $fp );

		$this->assertEquals( $cs->id(), $row['change_set_id'] );
		$this->assertEquals( ChangeSetState::PLANNED, $row['state'] );
		$this->assertEquals( 0, $row['state_version'] );
		$this->assertEquals( $fp, $row['fingerprint'] );

		$loaded = $this->repo->load( $cs->id() );
		$this->assertNotNull( $loaded );
		$this->assertEquals( $row, $loaded );
	}

	public function test_load_missing_row_returns_null(): void {
		$this->assertNull( $this->repo->load( 'cs_does_not_exist' ) );
	}

	public function test_loaded_change_set_matches_original_fingerprint(): void {
		$cs = $this->makeChangeSet();
		$fp = ChangeSetFingerprint::compute( $cs );
		$this->repo->create( $cs, $fp );

		$rehydrated = $this->repo->loadChangeSet( $cs->id() );
		$this->assertNotNull( $rehydrated );
		$this->assertEquals( $fp, ChangeSetFingerprint::compute( $rehydrated ) );
	}

	// -------------------------------------------------------------- encryption at rest

	public function test_payload_ciphertext_never_contains_plaintext_option_value(): void {
		$secret_value = 'sk-abcdefghijklmnopqrstuvwxyz123456';
		$cs = $this->makeChangeSet( 'aios_repo_secret_option', $secret_value );
		$row = $this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );

		$this->assertFalse( str_contains( (string) $row['payload_ciphertext'], $secret_value ) );
		$this->assertTrue( str_starts_with( (string) $row['payload_ciphertext'], 'aios1:' ), 'must use the real versioned Crypto envelope, not plaintext' );
	}

	public function test_decrypt_failure_on_tampered_ciphertext_is_explicit(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );

		global $wpdb;
		$table = ( new Database() )->table( Database::TABLE_CHANGE_SETS );
		foreach ( $wpdb->tables['ai_os_change_sets'] as $i => $row ) {
			if ( $row['change_set_id'] === $cs->id() ) {
				$wpdb->tables['ai_os_change_sets'][ $i ]['payload_ciphertext'] = 'aios1:sodium:1:dGFtcGVyZWQ=';
			}
		}

		try {
			$this->repo->loadChangeSet( $cs->id() );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'changeset.decrypt_failed', $e->errorCode() );
		}
	}

	public function test_wrong_key_cannot_decrypt_persisted_payload(): void {
		$cs = $this->makeChangeSet();
		$row = $this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );

		$wrong_key_crypto = new Crypto( hash( 'sha256', 'a-totally-different-key', true ) );
		$this->assertNull( $wrong_key_crypto->decrypt( (string) $row['payload_ciphertext'] ), 'a key other than the environment-derived one used at write time must never decrypt the payload' );
	}

	public function test_integrity_hash_mismatch_after_manual_row_edit_is_detected(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );

		global $wpdb;
		foreach ( $wpdb->tables['ai_os_change_sets'] as $i => $row ) {
			if ( $row['change_set_id'] === $cs->id() ) {
				$wpdb->tables['ai_os_change_sets'][ $i ]['payload_hash'] = str_repeat( '0', 64 );
			}
		}

		try {
			$this->repo->loadChangeSet( $cs->id() );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'changeset.integrity_failed', $e->errorCode() );
		}
	}

	// -------------------------------------------------------------- idempotency

	public function test_create_with_same_idempotency_key_returns_existing_row(): void {
		$cs1 = $this->makeChangeSet( 'aios_idem_option', 'first' );
		$row1 = $this->repo->create( $cs1, ChangeSetFingerprint::compute( $cs1 ), 'idem-key-A' );

		$cs2 = $this->makeChangeSet( 'aios_idem_option', 'second' ); // different payload
		$row2 = $this->repo->create( $cs2, ChangeSetFingerprint::compute( $cs2 ), 'idem-key-A' );

		$this->assertEquals( $row1['change_set_id'], $row2['change_set_id'], 'the second create() with the same idempotency key must return the FIRST row, not create a duplicate' );
		$this->assertNull( $this->repo->load( $cs2->id() ), 'the second ChangeSets own id must never have been persisted' );
	}

	public function test_different_idempotency_keys_create_separate_rows(): void {
		$cs1 = $this->makeChangeSet( 'aios_idem_option_b', 'a' );
		$row1 = $this->repo->create( $cs1, ChangeSetFingerprint::compute( $cs1 ), 'idem-key-B1' );

		$cs2 = $this->makeChangeSet( 'aios_idem_option_c', 'b' );
		$row2 = $this->repo->create( $cs2, ChangeSetFingerprint::compute( $cs2 ), 'idem-key-B2' );

		$this->assertNotEquals( $row1['change_set_id'], $row2['change_set_id'] );
	}

	// -------------------------------------------------------------- CAS transitions

	public function test_valid_transition_succeeds_and_bumps_version(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );

		$ok = $this->repo->transition( $cs->id(), ChangeSetState::PLANNED, ChangeSetState::SNAPSHOTTED, 0 );
		$this->assertTrue( $ok );

		$row = $this->repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::SNAPSHOTTED, $row['state'] );
		$this->assertEquals( 1, $row['state_version'] );
	}

	public function test_illegal_transition_throws_before_any_write(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );

		try {
			$this->repo->transition( $cs->id(), ChangeSetState::PLANNED, ChangeSetState::COMPLETED, 0 );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'changeset.illegal_transition', $e->errorCode() );
		}

		$row = $this->repo->load( $cs->id() );
		$this->assertEquals( ChangeSetState::PLANNED, $row['state'], 'a rejected transition must never have touched the row' );
	}

	public function test_stale_state_version_fails_the_cas(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );
		$this->repo->transition( $cs->id(), ChangeSetState::PLANNED, ChangeSetState::SNAPSHOTTED, 0 ); // now at version 1

		// Attempting the SAME transition again with the stale expected version (0) must fail.
		$ok = $this->repo->transition( $cs->id(), ChangeSetState::PLANNED, ChangeSetState::SNAPSHOTTED, 0 );
		$this->assertFalse( $ok );

		$row = $this->repo->load( $cs->id() );
		$this->assertEquals( 1, $row['state_version'], 'a failed CAS must not have changed anything' );
	}

	public function test_terminal_state_cannot_be_left(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );
		$this->repo->transition( $cs->id(), ChangeSetState::PLANNED, ChangeSetState::SNAPSHOTTED, 0 );
		$this->repo->transition( $cs->id(), ChangeSetState::SNAPSHOTTED, ChangeSetState::DIFF_READY, 1 );
		$this->repo->transition( $cs->id(), ChangeSetState::DIFF_READY, ChangeSetState::APPLYING, 2 );
		$this->repo->transition( $cs->id(), ChangeSetState::APPLYING, ChangeSetState::VERIFYING, 3 );
		$this->repo->transition( $cs->id(), ChangeSetState::VERIFYING, ChangeSetState::COMPLETED, 4 );

		try {
			$this->repo->transition( $cs->id(), ChangeSetState::COMPLETED, ChangeSetState::APPLYING, 5 );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'changeset.illegal_transition', $e->errorCode() );
		}
	}

	// -------------------------------------------------------------- lease

	public function test_first_executor_acquires_lease_second_is_rejected(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );

		$this->assertTrue( $this->repo->acquireLease( $cs->id(), 'owner-A', 300 ) );
		$this->assertFalse( $this->repo->acquireLease( $cs->id(), 'owner-B', 300 ), 'a second executor must not acquire an unexpired lease' );
	}

	public function test_expired_lease_can_be_taken_over(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );
		$this->assertTrue( $this->repo->acquireLease( $cs->id(), 'owner-A', 300 ) );

		// Simulate time passing: the TTL clamp (production code correctly
		// refuses to accept a non-positive TTL at acquisition) means the
		// only way to model "this lease has since expired" is to move its
		// stored expiry into the past directly, same technique the purge
		// tests use for "this row is old."
		global $wpdb;
		foreach ( $wpdb->tables['ai_os_change_sets'] as $i => $row ) {
			if ( $row['change_set_id'] === $cs->id() ) {
				$wpdb->tables['ai_os_change_sets'][ $i ]['lease_expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 10 );
			}
		}

		$this->assertTrue( $this->repo->acquireLease( $cs->id(), 'owner-B', 300 ), 'an expired lease must be takeable by another executor' );
	}

	public function test_release_lease_allows_reacquisition(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );
		$this->repo->acquireLease( $cs->id(), 'owner-A', 300 );

		$this->assertTrue( $this->repo->releaseLease( $cs->id(), 'owner-A' ) );
		$this->assertTrue( $this->repo->acquireLease( $cs->id(), 'owner-B', 300 ) );
	}

	public function test_wrong_owner_cannot_release_lease(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );
		$this->repo->acquireLease( $cs->id(), 'owner-A', 300 );

		$this->assertFalse( $this->repo->releaseLease( $cs->id(), 'owner-B' ) );
		$this->assertFalse( $this->repo->acquireLease( $cs->id(), 'owner-C', 300 ), 'the lease must still be held by owner-A' );
	}

	// -------------------------------------------------------------- recovery journal

	public function test_recovery_state_round_trips_encrypted(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );

		$this->assertNull( $this->repo->loadRecovery( $cs->id() ) );

		$journal = array( 'operations' => array( array( 'id' => 'op_1', 'status' => 'applied' ) ) );
		$this->assertTrue( $this->repo->saveRecovery( $cs->id(), $journal ) );

		$loaded = $this->repo->loadRecovery( $cs->id() );
		$this->assertEquals( $journal, $loaded );

		$row = $this->repo->load( $cs->id() );
		$this->assertFalse( str_contains( (string) $row['recovery_ciphertext'], 'op_1' ), 'recovery ciphertext must not contain plaintext journal content' );
	}

	// -------------------------------------------------------------- multisite

	protected function multisiteTearDown(): void {
		$GLOBALS['__wp_shim']['multisite'] = false;
		$GLOBALS['__wp_shim']['sites']     = array( 1 );
	}

	public function test_change_set_rows_are_isolated_per_site(): void {
		$GLOBALS['__wp_shim']['multisite']      = true;
		$GLOBALS['__wp_shim']['sites']          = array( 1, 2 );
		$GLOBALS['__wp_shim']['current_blog_id'] = 1;

		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );
		$this->assertNotNull( $this->repo->load( $cs->id() ) );

		switch_to_blog( 2 );
		$this->assertNull( $this->repo->load( $cs->id() ), 'site 2s change_sets table must not see site 1s row (separate table prefix)' );
		restore_current_blog();

		$this->assertNotNull( $this->repo->load( $cs->id() ), 'site 1s row must still be there after visiting site 2' );
		$this->multisiteTearDown();
	}

	// -------------------------------------------------------------- purge

	public function test_purge_never_removes_non_terminal_rows(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );
		// Still PLANNED (non-terminal). Force it to look old.
		global $wpdb;
		foreach ( $wpdb->tables['ai_os_change_sets'] as $i => $row ) {
			if ( $row['change_set_id'] === $cs->id() ) {
				$wpdb->tables['ai_os_change_sets'][ $i ]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - 999 * DAY_IN_SECONDS );
			}
		}

		$purged = $this->repo->purgeTerminalOlderThan( 0 );
		$this->assertEquals( 0, $purged );
		$this->assertNotNull( $this->repo->load( $cs->id() ) );
	}

	public function test_purge_removes_old_terminal_rows(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );
		$this->repo->transition( $cs->id(), ChangeSetState::PLANNED, ChangeSetState::POLICY_REJECTED, 0 );

		global $wpdb;
		foreach ( $wpdb->tables['ai_os_change_sets'] as $i => $row ) {
			if ( $row['change_set_id'] === $cs->id() ) {
				$wpdb->tables['ai_os_change_sets'][ $i ]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - 999 * DAY_IN_SECONDS );
			}
		}

		$purged = $this->repo->purgeTerminalOlderThan( 30 );
		$this->assertEquals( 1, $purged );
		$this->assertNull( $this->repo->load( $cs->id() ) );
	}

	public function test_purge_never_removes_rollback_failed_rows(): void {
		$cs = $this->makeChangeSet();
		$this->repo->create( $cs, ChangeSetFingerprint::compute( $cs ) );
		$this->repo->transition( $cs->id(), ChangeSetState::PLANNED, ChangeSetState::SNAPSHOTTED, 0 );
		$this->repo->transition( $cs->id(), ChangeSetState::SNAPSHOTTED, ChangeSetState::DIFF_READY, 1 );
		$this->repo->transition( $cs->id(), ChangeSetState::DIFF_READY, ChangeSetState::APPLYING, 2 );
		$this->repo->transition( $cs->id(), ChangeSetState::APPLYING, ChangeSetState::VERIFYING, 3 );
		$this->repo->transition( $cs->id(), ChangeSetState::VERIFYING, ChangeSetState::ROLLBACK_REQUIRED, 4 );
		$this->repo->transition( $cs->id(), ChangeSetState::ROLLBACK_REQUIRED, ChangeSetState::ROLLING_BACK, 5 );
		$this->repo->transition( $cs->id(), ChangeSetState::ROLLING_BACK, ChangeSetState::ROLLBACK_FAILED, 6 );

		global $wpdb;
		foreach ( $wpdb->tables['ai_os_change_sets'] as $i => $row ) {
			if ( $row['change_set_id'] === $cs->id() ) {
				$wpdb->tables['ai_os_change_sets'][ $i ]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - 999 * DAY_IN_SECONDS );
			}
		}

		$purged = $this->repo->purgeTerminalOlderThan( 0 );
		$this->assertEquals( 0, $purged, 'ROLLBACK_FAILED (manual recovery required) must never be auto-purged' );
		$this->assertNotNull( $this->repo->load( $cs->id() ) );
	}
}
