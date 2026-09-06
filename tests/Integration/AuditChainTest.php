<?php
/**
 * Integration tests: the tamper-evident audit chain (SEC-M4) wired
 * through the real container — AuditLogger → AuditLogRepository →
 * AuditIntegrity — rather than synthetic rows (see
 * tests/Unit/AuditIntegrityTest.php for the exhaustive per-scenario
 * unit coverage of the hashing/verification rules themselves).
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Audit\AuditIntegrity;
use AIOS\Audit\AuditLogger;
use AIOS\Core\Plugin;
use AIOS\Database\Repositories\AuditLogRepository;
use AIOS\Tests\TestCase;

final class AuditChainTest extends TestCase {

	private AuditLogger $logger;

	private AuditLogRepository $repo;

	protected function setUp(): void {
		$this->resetPlugin();
		$this->logger = Plugin::instance()->container()->get( AuditLogger::class );
		$this->repo   = Plugin::instance()->container()->get( AuditLogRepository::class );
	}

	/**
	 * Direct access to the shim's backing store for a table, for
	 * simulating DB-layer tampering (an attacker/bug editing a row
	 * without going through the application).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function &rawTable(): array {
		global $wpdb;
		if ( ! isset( $wpdb->tables['ai_os_audit_logs'] ) ) {
			$wpdb->tables['ai_os_audit_logs'] = array();
		}
		return $wpdb->tables['ai_os_audit_logs'];
	}

	private function logSomething( string $action = 'execute', array $args = array() ): int {
		return $this->logger->log( array(
			'user'   => $this->adminUser(),
			'client' => 'test',
			'tool'   => 'site.get_info',
			'action' => $action,
			'args'   => $args,
			'status' => AuditLogger::STATUS_OK,
		) );
	}

	// ------------------------------------------------------------ end-to-end happy path

	public function test_valid_chain_after_normal_inserts(): void {
		$this->logSomething( 'one' );
		$this->logSomething( 'two' );
		$this->logSomething( 'three' );

		$report = $this->logger->verifyIntegrity();

		$this->assertEquals( AuditIntegrity::STATUS_VALID, $report['overall'] );
		$this->assertEquals( 3, $report['checked'] );
		$this->assertEquals( 0, $report['legacy'] );
		$this->assertCount( 0, $report['issues'] );
	}

	public function test_chain_seq_and_prev_hash_link_consecutive_rows(): void {
		$id1 = $this->logSomething( 'one' );
		$id2 = $this->logSomething( 'two' );

		$row1 = $this->repo->get( $id1 );
		$row2 = $this->repo->get( $id2 );

		$this->assertEquals( 1, $row1['chain_seq'] );
		$this->assertEquals( 2, $row2['chain_seq'] );
		$this->assertEquals( AuditIntegrity::GENESIS_PREV, $row1['prev_hash'] );

		// prev_hash is only exposed raw via chainRows(); get() hydrates
		// the same underlying columns, so read record_hash off the raw
		// chain to confirm the link.
		$raw = $this->repo->chainRows();
		$this->assertEquals( $raw[0]['record_hash'], $raw[1]['prev_hash'] );
	}

	// ------------------------------------------------------------ DB-layer tamper detection

	public function test_modified_row_in_storage_is_detected(): void {
		$this->logSomething( 'one' );
		$this->logSomething( 'two' );
		$this->logSomething( 'three' );

		$table = &$this->rawTable();
		foreach ( $table as $i => $row ) {
			if ( 'two' === $row['action'] ) {
				$table[ $i ]['action'] = 'something-else-entirely';
			}
		}

		$report = $this->logger->verifyIntegrity();

		$this->assertNotEquals( AuditIntegrity::STATUS_VALID, $report['overall'] );
		$this->assert( in_array( AuditIntegrity::STATUS_TAMPERED, array_column( $report['issues'], 'status' ), true ) );
	}

	public function test_deleted_middle_row_is_detected_as_missing_predecessor(): void {
		$this->logSomething( 'one' );
		$this->logSomething( 'two' );
		$this->logSomething( 'three' );

		$table = &$this->rawTable();
		foreach ( $table as $i => $row ) {
			if ( 'two' === $row['action'] ) {
				unset( $table[ $i ] );
			}
		}
		$table = array_values( $table );

		$report = $this->logger->verifyIntegrity();

		$this->assert( in_array( AuditIntegrity::STATUS_MISSING_PREDECESSOR, array_column( $report['issues'], 'status' ), true ) );
	}

	// ------------------------------------------------------------ legacy rows through the real stack

	public function test_pre_existing_legacy_row_is_reported_explicitly(): void {
		// Simulate a row written before SEC-M4 shipped: no integrity
		// columns at all, inserted directly (bypassing the repository,
		// exactly as a genuinely old row in the real table would be).
		$table   = &$this->rawTable();
		$table[] = array(
			'id'          => 1,
			'occurred_at' => '2020-01-01 00:00:00',
			'user_id'     => 1,
			'client'      => 'legacy',
			'tool'        => 'legacy.tool',
			'action'      => 'legacy-action',
			'args_hash'   => '',
			'risk'        => 0,
			'status'      => 'ok',
			'duration_ms' => 0,
		);

		$this->logSomething( 'first-chained-row' );

		$report = $this->logger->verifyIntegrity();

		$this->assertEquals( AuditIntegrity::STATUS_VALID, $report['overall'], 'a valid new chain segment after legacy history is still a valid report' );
		$this->assertEquals( 1, $report['legacy'] );
		$this->assertEquals( 1, $report['checked'] );
	}

	// ------------------------------------------------------------ redaction boundary / no leakage

	public function test_redaction_happens_before_integrity_calculation(): void {
		$secret = 'sk-live-totally-real-secret-abcdef1234567890';
		$this->logSomething( 'call-with-secret', array( 'api_key' => $secret ) );

		$raw = $this->repo->chainRows();
		$this->assertCount( 1, $raw );

		// The secret must not survive anywhere in the stored row —
		// including inside whatever the hash was computed over.
		$serialized_row = wp_json_encode( $raw[0] );
		$this->assertStringNotContains( $secret, (string) $serialized_row );

		// And the chain must still verify: the hash was computed over
		// the REDACTED args_hash, not raw args, so redaction never
		// breaks the chain it participates in.
		$report = $this->logger->verifyIntegrity();
		$this->assertEquals( AuditIntegrity::STATUS_VALID, $report['overall'] );
	}

	public function test_no_secret_or_key_material_leaks_from_verification_report(): void {
		$secret = 'sk-another-secret-that-must-never-appear-anywhere';
		$this->logSomething( 'call-with-secret', array( 'token' => $secret ) );

		$report = $this->logger->verifyIntegrity();

		$this->assertStringNotContains( $secret, (string) wp_json_encode( $report ) );
	}
}
