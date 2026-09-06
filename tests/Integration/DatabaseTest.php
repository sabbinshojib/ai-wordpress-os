<?php
/**
 * Integration tests: migrations + audit/repository layer.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Database\Database;
use AIOS\Database\Migrator;
use AIOS\Database\Repositories\ApprovalRepository;
use AIOS\Database\Repositories\AuditLogRepository;
use AIOS\Database\Repositories\ToolExecutionRepository;
use AIOS\Tests\TestCase;

final class DatabaseTest extends TestCase {

        protected function setUp(): void {
                $this->resetPlugin();
        }

        public function test_migrator_marks_version_applied(): void {
                $migrator = new Migrator();
                $this->assert( $migrator->isUpToDate(), 'fresh state should be pending or applied consistently' );
        }

        public function test_migration_generates_all_four_tables(): void {
                update_option( 'ai_os_migrations', array() );
                $db       = new Database();
                $migrator = new Migrator();
                $result   = $migrator->migrate( $db );

                $this->assert( null === $result['failed'], 'migration must not fail: ' . wp_json_encode( $result['failed'] ) );
                $this->assertEquals( array( '202501010001' ), $result['applied'] );
                $this->assertEquals( array( '202501010001' ), $migrator->appliedVersions() );
                $this->assertTrue( $migrator->isUpToDate() );
        }

        public function test_migration_is_idempotent(): void {
                update_option( 'ai_os_migrations', array( '202501010001' ) );
                $migrator = new Migrator();
                $result   = $migrator->migrate( new Database() );
                $this->assertEquals( array(), $result['applied'], 'second run must be a no-op' );
        }

        public function test_audit_repository_query_filters(): void {
                $logs = Plugin::instance()->container()->get( AuditLogRepository::class );

                $logs->insert( array( 'user_id' => 1, 'tool' => 'site.get_info', 'status' => 'ok', 'risk' => 0, 'client' => 'test' ) );
                $logs->insert( array( 'user_id' => 2, 'tool' => 'system.cache.flush', 'status' => 'error', 'risk' => 2, 'client' => 'test' ) );
                $logs->insert( array( 'user_id' => 1, 'tool' => 'system.cache.flush', 'status' => 'blocked', 'risk' => 3, 'client' => 'test' ) );

                $this->assertCount( 3, $logs->query( array() ) );
                $this->assertCount( 2, $logs->query( array( 'user_id' => 1 ) ) );
                $this->assertCount( 2, $logs->query( array( 'tool' => 'system.cache.flush' ) ) );
                $this->assertCount( 1, $logs->query( array( 'status' => 'blocked' ) ) );
                $this->assertCount( 1, $logs->query( array( 'risk' => 0 ) ) );
        }

        public function test_audit_repository_hydrates_types(): void {
                $logs = Plugin::instance()->container()->get( AuditLogRepository::class );
                $id   = $logs->insert( array(
                        'user_id'   => 1,
                        'tool'      => 'content.create_post',
                        'status'    => 'ok',
                        'args_json' => wp_json_encode( array( 'title' => 'X' ) ),
                        'affected_objects' => array( array( 'type' => 'post', 'id' => '1000' ) ),
                ) );

                $row = $logs->get( $id );
                $this->assertNotNull( $row );
                $this->assert( is_array( $row['affected_objects'] ) );
                $this->assertEquals( array( 'title' => 'X' ), $row['args_decoded'] );
        }

        public function test_approval_repository_ttl_expiry(): void {
                $approvals = Plugin::instance()->container()->get( ApprovalRepository::class );

                $id = $approvals->create( array(
                        'user_id'         => 1,
                        'tool'            => 'system.cache.flush',
                        'args'            => array(),
                        'risk'            => 2,
                        'reason'          => 'test',
                        'expires_minutes' => 1,
                ) );
                $this->assertGreaterThan( 0, $id );
                $this->assertEquals( 1, $approvals->pendingCount() );

                // Backdate expiry directly, then claim: must be rejected.
                global $wpdb;
                foreach ( $wpdb->tables['ai_os_approvals'] ?? array() as $index => $row ) {
                        if ( (int) $row['id'] === $id ) {
                                $wpdb->tables['ai_os_approvals'][ $index ]['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
                        }
                }

                $this->assertNull( $approvals->claimPending( $id, 'approved', 1 ), 'expired approvals must not be claimable' );
        }

        public function test_tool_execution_repository_stats(): void {
                $execs = Plugin::instance()->container()->get( ToolExecutionRepository::class );
                $execs->insert( 'site.get_info', 1, 'test', true, 12 );
                $execs->insert( 'site.get_info', 1, 'test', false, 20, 'tool.input_invalid' );

                $health = $execs->health( 1 );
                $this->assertEquals( 2, $health['calls'] );
                $this->assertEquals( 1, $health['errors'] );
        }

        public function test_audit_stats_counters(): void {
                $logs = Plugin::instance()->container()->get( AuditLogRepository::class );
                $logs->insert( array( 'user_id' => 1, 'tool' => 'a.b', 'status' => 'ok' ) );
                $logs->insert( array( 'user_id' => 1, 'tool' => 'c.d', 'status' => 'blocked' ) );

                $stats = $logs->stats();
                $this->assertEquals( 2, $stats['total_24h'] );
                $this->assertEquals( 1, $stats['blocked_24h'] );
                $this->assertEquals( 0, $stats['errors_24h'] );
        }
}
