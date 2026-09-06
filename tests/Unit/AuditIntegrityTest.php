<?php
/**
 * Unit tests: tamper-evident audit chain primitives (SEC-M4).
 *
 * Pure unit level — no DB, no container — exercising AuditIntegrity's
 * hashing/linking/verification logic directly against synthetic rows,
 * so every chain-integrity scenario in the spec can be constructed
 * exactly (including ones a real auto-increment table could not
 * reproduce, like an attacker rewriting a stored hash).
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Audit\AuditIntegrity;
use AIOS\Tests\TestCase;

final class AuditIntegrityTest extends TestCase {

	private AuditIntegrity $integrity;

	protected function setUp(): void {
		// Fixed key: deterministic expectations, and proves the class
		// never depends on any global WP state to do its job.
		$this->integrity = new AuditIntegrity( 'unit-test-fixed-key' );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function content( array $overrides = array() ): array {
		return array_merge(
			array(
				'occurred_at'      => '2026-01-01 00:00:00',
				'user_id'          => 1,
				'client'           => 'test',
				'principal_type'   => 'user',
				'tool'             => 'site.get_info',
				'action'           => 'execute',
				'args_hash'        => hash( 'sha256', '{}' ),
				'risk'             => 0,
				'status'           => 'ok',
				'error'            => null,
				'affected_objects' => null,
				'affected_files'   => null,
				'approval_id'      => null,
				'rollback_id'      => null,
				'duration_ms'      => 5,
			),
			$overrides
		);
	}

	/**
	 * Build a chain of $n synthetic, fully-linked rows the way
	 * AuditLogRepository::insert() would, using an id 1..n.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function buildChain( int $n, int $siteId = 1 ): array {
		$rows     = array();
		$previous = null;
		for ( $i = 1; $i <= $n; $i++ ) {
			$content = $this->content( array( 'action' => "execute-{$i}" ) );
			$link    = $this->integrity->nextLink( $content, $previous, $siteId );
			$row     = array_merge( array( 'id' => $i ), $content, $link );
			$rows[]  = $row;
			$previous = $row;
		}
		return $rows;
	}

	// ------------------------------------------------------------ canonicalization

	public function test_canonicalize_is_order_independent(): void {
		$a = AuditIntegrity::canonicalize( array( 'b' => 1, 'a' => 2 ) );
		$b = AuditIntegrity::canonicalize( array( 'a' => 2, 'b' => 1 ) );
		$this->assertEquals( $a, $b );
	}

	// ------------------------------------------------------------ the happy paths

	public function test_valid_multi_entry_chain_verifies_clean(): void {
		$rows   = $this->buildChain( 5 );
		$report = $this->integrity->verifyChain( $rows, 1 );

		$this->assertEquals( AuditIntegrity::STATUS_VALID, $report['overall'] );
		$this->assertEquals( 5, $report['checked'] );
		$this->assertEquals( 0, $report['legacy'] );
		$this->assertCount( 0, $report['issues'] );
	}

	public function test_clean_verification_after_normal_inserts_of_varied_content(): void {
		$rows = array();
		$prev = null;
		foreach ( array( 'ok', 'error', 'blocked', 'ok' ) as $i => $status ) {
			$content = $this->content( array( 'status' => $status, 'risk' => $i ) );
			$link    = $this->integrity->nextLink( $content, $prev, 1 );
			$row     = array_merge( array( 'id' => $i + 1 ), $content, $link );
			$rows[]  = $row;
			$prev    = $row;
		}

		$report = $this->integrity->verifyChain( $rows, 1 );
		$this->assertEquals( AuditIntegrity::STATUS_VALID, $report['overall'] );
		$this->assertCount( 0, $report['issues'] );
	}

	// ------------------------------------------------------------ tamper detection

	public function test_modified_payload_fails(): void {
		$rows = $this->buildChain( 3 );
		$rows[1]['duration_ms'] = 999999; // Content field, hash left stale.

		$report = $this->integrity->verifyChain( $rows, 1 );

		$this->assertEquals( AuditIntegrity::STATUS_BROKEN_CHAIN, $report['overall'] );
		$this->assertEquals( AuditIntegrity::STATUS_TAMPERED, $report['issues'][0]['status'] );
		$this->assertEquals( 2, $report['issues'][0]['id'] );
	}

	public function test_modified_actor_fails(): void {
		$rows = $this->buildChain( 3 );
		$rows[1]['user_id'] = 999; // Actor change, hash left stale.

		$report = $this->integrity->verifyChain( $rows, 1 );

		$this->assertEquals( AuditIntegrity::STATUS_TAMPERED, $report['issues'][0]['status'] );
	}

	public function test_modified_action_and_tool_fail(): void {
		$rows = $this->buildChain( 3 );
		$rows[2]['tool'] = 'system.dangerous_tool';

		$report = $this->integrity->verifyChain( $rows, 1 );

		$this->assertEquals( AuditIntegrity::STATUS_TAMPERED, $report['issues'][0]['status'] );
		$this->assertEquals( 3, $report['issues'][0]['id'] );
	}

	public function test_reordered_records_fail(): void {
		$rows = $this->buildChain( 3 );
		// Swap rows 2 and 3 (index 1, 2) — same objects, wrong order.
		[ $rows[1], $rows[2] ] = array( $rows[2], $rows[1] );

		$report = $this->integrity->verifyChain( $rows, 1 );

		$this->assertNotEquals( AuditIntegrity::STATUS_VALID, $report['overall'] );
		$this->assertGreaterThan( 0.0, (float) count( $report['issues'] ) );
	}

	public function test_modified_previous_hash_reference_fails(): void {
		$rows = $this->buildChain( 3 );
		$rows[2]['prev_hash'] = str_repeat( 'a', 64 ); // Well-formed hex, but wrong.

		$report = $this->integrity->verifyChain( $rows, 1 );

		$this->assertEquals( AuditIntegrity::STATUS_BROKEN_CHAIN, $report['issues'][0]['status'] );
	}

	public function test_malformed_hash_fails(): void {
		$rows = $this->buildChain( 3 );
		$rows[1]['record_hash'] = 'not-a-hash';

		$report = $this->integrity->verifyChain( $rows, 1 );

		$this->assertEquals( AuditIntegrity::STATUS_MALFORMED, $report['issues'][0]['status'] );
	}

	public function test_malformed_sequence_fails(): void {
		$rows = $this->buildChain( 3 );
		$rows[1]['chain_seq'] = 0; // Below the valid range (>= 1).

		$report = $this->integrity->verifyChain( $rows, 1 );

		$this->assertEquals( AuditIntegrity::STATUS_MALFORMED, $report['issues'][0]['status'] );
	}

	public function test_missing_middle_record_is_detected(): void {
		$rows = $this->buildChain( 3 );
		unset( $rows[1] ); // Drop the middle row, as if it were deleted.
		$rows = array_values( $rows );

		$report = $this->integrity->verifyChain( $rows, 1 );

		$this->assertEquals( AuditIntegrity::STATUS_MISSING_PREDECESSOR, $report['issues'][0]['status'] );
		$this->assertEquals( 3, $report['issues'][0]['id'] );
	}

	// ------------------------------------------------------------ legacy rows

	public function test_legacy_rows_are_explicit_and_never_reported_as_verified(): void {
		$legacyOnly = array(
			array( 'id' => 1 ) + $this->content(),
			array( 'id' => 2 ) + $this->content(),
		);

		$report = $this->integrity->verifyChain( $legacyOnly, 1 );

		$this->assertEquals( AuditIntegrity::STATUS_LEGACY, $report['overall'], 'legacy-only history must not be reported as a verified chain' );
		$this->assertEquals( 0, $report['checked'] );
		$this->assertEquals( 2, $report['legacy'] );
		$this->assertCount( 0, $report['issues'], 'legacy rows themselves are not "issues" — they are an explicit distinct state' );
	}

	public function test_chain_rows_following_legacy_history_verify_as_a_fresh_segment(): void {
		$legacy = array( 'id' => 1 ) + $this->content();

		// First chain row after legacy history: no cryptographic
		// predecessor exists, so it must declare GENESIS_PREV itself.
		$content = $this->content( array( 'action' => 'first-chained' ) );
		$link    = $this->integrity->nextLink( $content, null, 1 );
		$chained1 = array_merge( array( 'id' => 2 ), $content, $link );

		$content2 = $this->content( array( 'action' => 'second-chained' ) );
		$link2    = $this->integrity->nextLink( $content2, $chained1, 1 );
		$chained2 = array_merge( array( 'id' => 3 ), $content2, $link2 );

		$report = $this->integrity->verifyChain( array( $legacy, $chained1, $chained2 ), 1 );

		$this->assertEquals( AuditIntegrity::STATUS_VALID, $report['overall'] );
		$this->assertEquals( 2, $report['checked'] );
		$this->assertEquals( 1, $report['legacy'] );
		$this->assertEquals( AuditIntegrity::GENESIS_PREV, $chained1['prev_hash'] );
	}

	// ------------------------------------------------------------ multisite isolation

	public function test_multisite_chain_isolation_via_site_id(): void {
		$content = $this->content();

		$linkSiteA = $this->integrity->nextLink( $content, null, 1 );
		$linkSiteB = $this->integrity->nextLink( $content, null, 2 );

		$this->assertNotEquals(
			$linkSiteA['record_hash'],
			$linkSiteB['record_hash'],
			'identical content on two different sites must hash differently'
		);

		// A row genuinely written for site 1 must fail verification if
		// checked against site 2's chain (cross-site replay attempt).
		$rowSiteA = array_merge( array( 'id' => 1 ), $content, $linkSiteA );
		$reportUnderWrongSite = $this->integrity->verifyChain( array( $rowSiteA ), 2 );

		$this->assertEquals( AuditIntegrity::STATUS_TAMPERED, $reportUnderWrongSite['issues'][0]['status'] );

		$reportUnderRightSite = $this->integrity->verifyChain( array( $rowSiteA ), 1 );
		$this->assertEquals( AuditIntegrity::STATUS_VALID, $reportUnderRightSite['overall'] );
	}

	// ------------------------------------------------------------ redaction boundary / no leakage

	public function test_hash_never_embeds_raw_args_only_their_hash(): void {
		$secret_looking_json = wp_json_encode( array( 'api_key' => 'sk-should-never-appear-in-hash-material' ) );
		$content             = $this->content( array( 'args_hash' => hash( 'sha256', (string) $secret_looking_json ) ) );

		$link = $this->integrity->nextLink( $content, null, 1 );

		$this->assertStringNotContains( 'sk-should-never-appear-in-hash-material', $link['record_hash'] );
		$this->assertStringNotContains( 'sk-should-never-appear-in-hash-material', $link['prev_hash'] );
	}
}
