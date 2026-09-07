<?php
/**
 * Integration tests: per-operation journal multisite isolation
 * (Sprint 0.3A Phase 2 exit-gate closure, Section 6). Covers
 * OperationJournalRepository specifically — parent-ChangeSet
 * multisite isolation is already covered by ChangeSetRepositoryTest::
 * test_change_set_rows_are_isolated_per_site and by
 * MultisiteLifecycleTest's provisioning/uninstall coverage (which
 * already asserts 21 = 7 tables × 3 sites, including
 * ai_os_operation_journal).
 *
 * SHIM LIMITATION FOUND THIS PASS (Section 17 — shim-vs-real-WordPress
 * parity review), not fixed: every test in this codebase that claims
 * multisite table isolation — this file's own first attempt at a
 * cross-site test included — only ever WRITES on the default site
 * (blog_id 1) and switches sites purely to perform a READ-based
 * absence check afterward. That is not incidental: tests/shim/
 * wp-functions.php's fake SQL engine extracts a table's short name via
 * regexes hardcoded as `wp_([a-z_]+)` (no digit allowed) — real
 * WordPress's own multisite prefix for any non-primary site is
 * `wp_{blog_id}_...`, which this pattern cannot match at all. Two
 * consequences discovered while first drafting this file's tests:
 *   1. An INSERT attempted while switched to a non-primary site fails
 *      to parse, so wpdb::insert() genuinely returns false — which,
 *      after this pass's fail-closed fix to ChangeSetRepository::
 *      create()/OperationJournalRepository::create(), now throws
 *      loudly instead of being silently swallowed. That symptom is
 *      what surfaced this finding.
 *   2. The shim's in-memory row storage ($wpdb->tables) is keyed by
 *      the SAME short table name regardless of site (see e.g.
 *      $wpdb->tables['ai_os_change_sets'] used directly, with no
 *      prefix, by several existing tests) — it does not model genuine
 *      per-site physical table separation at all. A SELECT on a
 *      non-primary site currently "looks" isolated only because the
 *      same broken regex fails to match there too, and getRow()/
 *      getResults() fall back to "nothing found" — not because of any
 *      real separate storage.
 * This is a pre-existing, plugin-wide limitation (affects every
 * repository, not something introduced by the journal), and a
 * correct fix (keying storage by the fully-prefixed table name) would
 * require updating every existing test that reaches into
 * $wpdb->tables['short_name'] directly across several files — judged
 * out of scope for this pass to attempt safely. Real per-site
 * WRITE isolation for this table therefore remains provable only via
 * the real WordPress/MySQL CI job (CI-CONFIGURED-NOT-RUN), NOT via
 * this shim. The tests below are deliberately scoped to what the shim
 * CAN honestly prove: correct per-site table-name resolution
 * (Database::table()) and read-side absence-after-switch, matching
 * every other multisite test in this codebase's own established,
 * safe convention.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Database\Database;
use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\ChangeSetRepository;
use AIOS\Mutation\DurableMutationCoordinator;
use AIOS\Mutation\Operations\MetadataUpdateOperation;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Mutation\OperationJournalRepository;
use AIOS\Tests\TestCase;

final class JournalMultisiteIsolationTest extends TestCase {

	private OperationJournalRepository $journal;

	private ChangeSetRepository $repo;

	private DurableMutationCoordinator $coordinator;

	protected function setUp(): void {
		$this->resetPlugin();
		$container         = Plugin::instance()->container();
		$this->journal     = $container->get( OperationJournalRepository::class );
		$this->repo        = $container->get( ChangeSetRepository::class );
		$this->coordinator = $container->get( DurableMutationCoordinator::class );
	}

	protected function multisiteTearDown(): void {
		$GLOBALS['__wp_shim']['multisite'] = false;
		$GLOBALS['__wp_shim']['sites']     = array( 1 );
	}

	private function enableMultisite(): void {
		$GLOBALS['__wp_shim']['multisite']       = true;
		$GLOBALS['__wp_shim']['sites']           = array( 1, 2 );
		$GLOBALS['__wp_shim']['current_blog_id'] = 1;
	}

	// -------------------------------------------------------------- table-name resolution (genuinely per-site, provable directly)

	public function test_journal_table_name_resolves_per_site(): void {
		$db = new Database();
		$this->assertEquals( 'wp_ai_os_operation_journal', $db->table( Database::TABLE_OPERATION_JOURNAL ) );

		$this->enableMultisite();
		switch_to_blog( 2 );
		$this->assertEquals( 'wp_2_ai_os_operation_journal', $db->table( Database::TABLE_OPERATION_JOURNAL ) );
		restore_current_blog();
		$this->multisiteTearDown();
	}

	// -------------------------------------------------------------- repository-level isolation (write on site 1, read-check on site 2)

	public function test_journal_row_created_on_site_1_is_invisible_from_site_2(): void {
		$this->enableMultisite();

		$op = new OptionUpdateOperation( 'aios_ms_journal_option', 'v1' );
		$this->journal->create( 'cs_ms_isolation', 0, $op, 1 );
		$this->assertNotNull( $this->journal->load( 'cs_ms_isolation', 0 ), 'must be visible on the site it was created on' );

		switch_to_blog( 2 );
		$this->assertNull( $this->journal->load( 'cs_ms_isolation', 0 ), "site 2 must not resolve site 1's row" );
		$this->assertEquals( array(), $this->journal->loadForChangeSet( 'cs_ms_isolation' ), 'loadForChangeSet must also see nothing on the wrong site' );
		restore_current_blog();

		$this->assertNotNull( $this->journal->load( 'cs_ms_isolation', 0 ), "site 1's row must still be there after visiting site 2" );
		$this->multisiteTearDown();
	}

	public function test_recovery_state_invisible_from_site_2(): void {
		$this->enableMultisite();

		$op = new OptionUpdateOperation( 'aios_ms_recovery', 'v' );
		$this->journal->create( 'cs_ms_recovery', 0, $op, 1 );
		$this->journal->saveRecovery( 'cs_ms_recovery', 0, array( 'site' => 'one' ), hash( 'sha256', 'one' ) );

		switch_to_blog( 2 );
		$this->assertNull( $this->journal->loadRecovery( 'cs_ms_recovery', 0 ), "site 2 must not resolve site 1's recovery row, even under the same change_set_id/index" );
		restore_current_blog();

		$this->assertEquals( array( 'site' => 'one' ), $this->journal->loadRecovery( 'cs_ms_recovery', 0 ) );
		$this->multisiteTearDown();
	}

	// -------------------------------------------------------------- coordinator-level (real submit()) isolation

	public function test_a_real_submit_is_invisible_from_a_different_site(): void {
		$this->enableMultisite();

		$post_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'ms journal test' ) );
		$cs      = new ChangeSet( 1, array( new MetadataUpdateOperation( (int) $post_id, 'aios_ms_submit_meta', 'v1' ) ) );

		$admin  = $this->adminUser();
		$result = $this->coordinator->submit( $cs, $admin );
		$this->assertTrue( $result->ok() );

		$row = $this->journal->load( $cs->id(), 0 );
		$this->assertNotNull( $row );
		$this->assertEquals( 1, $row['site_id'] );

		switch_to_blog( 2 );
		$this->assertNull( $this->journal->load( $cs->id(), 0 ), "site 2 must never resolve a journal row for a ChangeSet that only ever existed on site 1" );
		$this->assertNull( $this->repo->load( $cs->id() ), "site 2 must never resolve the parent ChangeSet row either" );
		restore_current_blog();

		$this->multisiteTearDown();
	}
}
