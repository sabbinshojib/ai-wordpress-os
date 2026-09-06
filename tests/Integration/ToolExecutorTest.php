<?php
/**
 * Integration tests: registry boot, tool executor pipeline, content
 * tools, audit, approval gating — the full Phase 1 vertical slice
 * against the WP shim.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Tests\TestCase;

final class ToolExecutorTest extends TestCase {

        private function executor(): \AIOS\Tools\ToolExecutor {
                return Plugin::instance()->container()->get( \AIOS\Tools\ToolExecutor::class );
        }

        protected function setUp(): void {
                $this->resetPlugin();
        }

        public function test_kernel_boots_and_registers_tools(): void {
                $plugin  = Plugin::instance();
                $tools   = $plugin->container()->get( \AIOS\Tools\ToolRegistry::class );
                $abilities = $plugin->container()->get( \AIOS\Abilities\AbilityRegistry::class );

                $this->assertTrue( $plugin->isBooted(), 'kernel must boot under the shim' );
                $this->assertGreaterThan( 20, $tools->count(), 'expected the full Phase 1 catalog' );
                $this->assertEquals( $tools->count(), $abilities->count(), 'every tool is backed by an ability' );

                foreach ( $tools->all() as $tool ) {
                        $this->assertNotNull(
                                $plugin->container()->get( \AIOS\Abilities\AbilityRegistry::class )->get( $tool->abilityName() ),
                                "tool {$tool->name()} must resolve its backing ability"
                        );
                }
        }

        public function test_every_tool_has_complete_metadata(): void {
                $tools = Plugin::instance()->container()->get( \AIOS\Tools\ToolRegistry::class );

                foreach ( $tools->all() as $tool ) {
                        $meta = $tool->toArray();
                        $this->assertArrayHasKey( 'riskLevel', $meta );
                        $this->assertArrayHasKey( 'permissionLevel', $meta );
                        $this->assertArrayHasKey( 'inputSchema', $meta );
                        $this->assertArrayHasKey( 'confirmation', $meta );
                        $this->assert( $tool->riskLevel() >= 0 && $tool->riskLevel() <= 4 );
                        $this->assert( $tool->permissionLevel() >= 0 && $tool->permissionLevel() <= 4 );
                        $this->assert( '' !== $tool->description(), "tool {$tool->name()} needs a description" );
                }
        }

        /**
         * BUG-002 regression: every real, registered tool name must
         * conform to the canonical grammar (Tool::NAME_PATTERN) AND must
         * survive REST identifier sanitization completely unchanged in
         * meaning — proving the fixed /tools/execute sanitize_callback
         * cannot mangle any of the 31 tools the catalog actually ships.
         */
        public function test_all_registered_tools_survive_rest_identifier_sanitization(): void {
                $tools      = Plugin::instance()->container()->get( \AIOS\Tools\ToolRegistry::class );
                $controller = new \AIOS\Rest\Controllers\ToolsController( Plugin::instance()->container() );

                $this->assertGreaterThan( 0, $tools->count(), 'the catalog must have registered tools to check' );

                foreach ( $tools->all() as $tool ) {
                        $name = $tool->name();

                        $this->assertEquals(
                                1,
                                preg_match( \AIOS\Tools\Tool::NAME_PATTERN, $name ),
                                "registered tool [{$name}] must match the canonical dot-notation grammar"
                        );

                        $this->assertTrue(
                                $controller->validateToolIdentifier( $name ),
                                "registered tool [{$name}] must pass REST validation unchanged"
                        );

                        $sanitized = $controller->sanitizeToolIdentifier( $name );
                        $this->assertEquals(
                                $name,
                                $sanitized,
                                "registered tool [{$name}] must be a no-op under sanitization (already canonical lowercase)"
                        );

                        // The sanitized identifier must still resolve to the
                        // SAME tool in the registry — the entire point of
                        // BUG-002.
                        $this->assertSame( $tool, $tools->get( $sanitized ) );
                }
        }

        /**
         * BUG-002 regression: every registered tool must remain
         * discoverable and invokable through the executor pipeline using
         * its REST-sanitized identifier. A tool may still fail with a
         * validation or permission error (missing required arguments,
         * insufficient capability) — that is expected and correct — but
         * it must never fail with "tool.unknown" or "tool.ability_missing",
         * which is exactly the failure mode the sanitize_key() defect
         * produced for every tool, unconditionally.
         */
        public function test_every_registered_tool_is_discoverable_and_invokable_after_sanitization(): void {
                $tools      = Plugin::instance()->container()->get( \AIOS\Tools\ToolRegistry::class );
                $controller = new \AIOS\Rest\Controllers\ToolsController( Plugin::instance()->container() );

                foreach ( $tools->all() as $tool ) {
                        $sanitized = $controller->sanitizeToolIdentifier( $tool->name() );
                        $result    = $this->executor()->execute( $sanitized, array(), $this->adminUser(), 'test' );

                        if ( $result->isError() ) {
                                $code = $result->error()?->code();
                                $this->assertNotEquals( 'tool.unknown', $code, "tool [{$tool->name()}] must be discoverable after sanitization" );
                                $this->assertNotEquals( 'tool.ability_missing', $code, "tool [{$tool->name()}] must resolve its ability after sanitization" );
                        }
                }
        }

        public function test_unknown_tool_is_structured_not_found(): void {
                $result = $this->executor()->execute( 'does.not_exist', array(), $this->adminUser(), 'test' );
                $this->assertTrue( $result->isError() );
                $this->assertEquals( 'tool.unknown', $result->error()?->code() );
                $this->assertEquals( 'not_found', $result->error()?->type() );
        }

        public function test_invalid_arguments_are_rejected(): void {
                $result = $this->executor()->execute(
                        'content.create_post',
                        array( 'title' => '', 'status' => 'weird-status' ),
                        $this->adminUser(),
                        'test'
                );
                // Empty title fails minLength OR status fails enum — both are
                // validation errors; with an invalid enum the schema returns
                // at least one violation.
                $this->assertTrue( $result->isError() );
                $context = $result->error()?->context() ?? array();
                $this->assertArrayHasKey( 'violations', $context );
        }

        public function test_anonymous_user_is_denied(): void {
                $result = $this->executor()->execute( 'site.get_info', array(), $this->anonymousUser(), 'test' );
                $this->assertTrue( $result->isError() );
                $this->assertEquals( 'permission.no_ai_os_access', $result->error()?->code() );
        }

        public function test_site_info_works_for_admin(): void {
                $result = $this->executor()->execute( 'site.get_info', array(), $this->adminUser(), 'test' );
                $this->assertTrue( $result->ok(), (string) $result->error() );
                $data = $result->data();
                $this->assertArrayHasKey( 'wordpress', $data );
                $this->assertArrayHasKey( 'theme', $data );
                $this->assertArrayHasKey( 'counts', $data );
        }

        public function test_site_environment_never_leaks_secrets(): void {
                $result = $this->executor()->execute( 'site.get_environment', array(), $this->adminUser(), 'test' );
                $this->assertTrue( $result->ok() );
                $json = wp_json_encode( $result->data() );
                $this->assertStringNotContains( 'password', strtolower( (string) $json ) );
        }

        public function test_content_create_and_get_roundtrip(): void {
                $created = $this->executor()->execute(
                        'content.create_post',
                        array( 'title' => 'Hello from AI', 'content' => '<p>Body copy</p>', 'status' => 'draft' ),
                        $this->adminUser(),
                        'test'
                );
                $this->assertTrue( $created->ok(), (string) $created->error() );
                $id = $created->data()['id'] ?? 0;
                $this->assertGreaterThan( 0, $id );

                $fetched = $this->executor()->execute( 'content.get_post', array( 'id' => $id ), $this->adminUser(), 'test' );
                $this->assertTrue( $fetched->ok() );
                $this->assertEquals( 'Hello from AI', $fetched->data()['title'] );
                $this->assertStringContains( 'AI_OS_UNTRUSTED_SITE_CONTENT', (string) $fetched->data()['content'], 'untrusted content wrapping (prompt injection defense)' );
        }

        public function test_content_update_partial(): void {
                $created = $this->executor()->execute(
                        'content.create_post',
                        array( 'title' => 'Before', 'content' => 'x', 'status' => 'draft' ),
                        $this->adminUser(),
                        'test'
                );
                $id = $created->data()['id'];

                $updated = $this->executor()->execute(
                        'content.update_post',
                        array( 'id' => $id, 'title' => 'After' ),
                        $this->adminUser(),
                        'test'
                );
                $this->assertTrue( $updated->ok(), (string) $updated->error() );

                $fetched = $this->executor()->execute( 'content.get_post', array( 'id' => $id ), $this->adminUser(), 'test' );
                $this->assertEquals( 'After', $fetched->data()['title'] );
                $this->assertStringContains( 'x', (string) $fetched->data()['content'], 'unspecified fields must not change' );
        }

        public function test_content_list_search(): void {
                $this->executor()->execute( 'content.create_post', array( 'title' => 'Alpha Zebra', 'status' => 'draft' ), $this->adminUser(), 'test' );
                $this->executor()->execute( 'content.create_post', array( 'title' => 'Beta Monkey', 'status' => 'draft' ), $this->adminUser(), 'test' );

                $result = $this->executor()->execute(
                        'content.list_posts',
                        array( 'search' => 'Zebra', 'per_page' => 10, 'status' => 'draft' ),
                        $this->adminUser(),
                        'test'
                );
                $this->assertTrue( $result->ok() );
                $items = $result->data()['items'];
                $this->assertCount( 1, $items );
                $this->assertEquals( 'Alpha Zebra', $items[0]['title'] );
        }

        public function test_deletion_goes_to_trash_not_permanent(): void {
                $created = $this->executor()->execute(
                        'content.create_post',
                        array( 'title' => 'Doomed', 'status' => 'draft' ),
                        $this->adminUser(),
                        'test'
                );
                $id = $created->data()['id'];

                // Safe mode → level 2 requires approval first.
                $blocked = $this->executor()->execute( 'content.delete_post', array( 'id' => $id ), $this->adminUser(), 'test' );
                $this->assertTrue( $blocked->isApprovalRequest(), 'level 2 must queue an approval in safe mode' );

                // Approve it through the approval engine.
                $approvals = Plugin::instance()->container()->get( \AIOS\Database\Repositories\ApprovalRepository::class );
                $approval  = $approvals->claimPending( $blocked->approvalId(), 'approved', 1 );
                $this->assertNotNull( $approval );

                $tools = Plugin::instance()->container()->get( \AIOS\Tools\ToolRegistry::class );
                $tool  = $tools->get( 'content.delete_post' );
                $executed = $this->executor()->executeApproved( $tool, array( 'id' => $id ), $this->adminUser(), $blocked->approvalId() );
                $this->assertTrue( $executed->ok(), (string) $executed->error() );

                // Post moved to trash (restorable), not destroyed.
                $post = get_post( $id );
                $this->assertNotNull( $post );
                $this->assertEquals( 'trash', $post->post_status );
        }

        public function test_contributor_cannot_delete_others_posts(): void {
                $created = $this->executor()->execute(
                        'content.create_post',
                        array( 'title' => 'Admin post', 'status' => 'draft' ),
                        $this->adminUser(),
                        'test'
                );
                $id = $created->data()['id'];

                $result = $this->executor()->execute( 'content.delete_post', array( 'id' => $id ), $this->contributorUser(), 'test' );
                $this->assertTrue( $result->isError() );
                $this->assertEquals( 'permission', $result->error()?->type() );
        }

        public function test_media_upload_rejects_disallowed_extension(): void {
                $result = $this->executor()->execute(
                        'media.upload',
                        array(
                                'filename' => 'malicious.exe',
                                'content'  => base64_encode( 'MZ...' ),
                        ),
                        $this->adminUser(),
                        'test'
                );
                $this->assertTrue( $result->isError() );
                $this->assertEquals( 'media.extension_not_allowed', $result->error()?->code() );
        }

        public function test_media_upload_rejects_invalid_base64(): void {
                $result = $this->executor()->execute(
                        'media.upload',
                        array( 'filename' => 'ok.png', 'content' => '!!!not-base64!!!' ),
                        $this->adminUser(),
                        'test'
                );
                $this->assertTrue( $result->isError() );
                $this->assertEquals( 'media.invalid_content', $result->error()?->code() );
        }

        public function test_media_upload_base64_works(): void {
                $result = $this->executor()->execute(
                        'media.upload',
                        array(
                                'filename' => 'photo.png',
                                'content'  => base64_encode( 'fakepngbytes' ),
                                'alt'      => 'A test image',
                        ),
                        $this->adminUser(),
                        'test'
                );
                $this->assertTrue( $result->ok(), (string) $result->error() );
                $this->assertGreaterThan( 0, $result->data()['id'] );
                $this->assertStringContains( 'photo.png', (string) $result->data()['url'] );
        }

        public function test_media_delete_is_approval_gated_in_safe_mode(): void {
                $uploaded = $this->executor()->execute(
                        'media.upload',
                        array( 'filename' => 'gone.png', 'content' => base64_encode( 'x' ) ),
                        $this->adminUser(),
                        'test'
                );
                $id = $uploaded->data()['id'];

                $result = $this->executor()->execute( 'media.delete', array( 'id' => $id ), $this->adminUser(), 'test' );
                // Level 3 destructive: in safe mode must be approval-gated.
                $this->assertTrue( $result->isApprovalRequest() );
        }

        public function test_theme_read_file_rejects_traversal(): void {
                $result = $this->executor()->execute(
                        'theme.read_file',
                        array( 'file' => '../../../../wp-config.php' ),
                        $this->adminUser(),
                        'test'
                );
                $this->assertTrue( $result->isError() );
                $code = $result->error()?->code() ?? '';
                $this->assert( str_contains( $code, 'blocked' ) || str_contains( $code, 'protected' ), "expected security rejection, got [{$code}]" );
        }

        public function test_theme_read_file_works_for_real_files(): void {
                // Arrange a real theme file in the shim theme root.
                $theme_root = get_theme_root() . '/' . get_stylesheet();
                if ( ! is_dir( $theme_root ) ) {
                        mkdir( $theme_root, 0777, true );
                }
                file_put_contents( $theme_root . '/functions.php', "<?php\nadd_action('init','x');" );

                $result = $this->executor()->execute(
                        'theme.read_file',
                        array( 'file' => 'functions.php' ),
                        $this->adminUser(),
                        'test'
                );
                $this->assertTrue( $result->ok(), (string) $result->error() );
                $this->assertStringContains( 'add_action', (string) $result->data()['content'] );

                @unlink( $theme_root . '/functions.php' );
        }

        public function test_escalation_blocklist_refuses_security_state_changes(): void {
                // Even in advanced mode with an admin, the executor blocklist
                // must refuse settings/options mutation attempts.
                update_option( 'ai_os_settings', array( 'mode' => 'advanced' ) );

                $result = $this->executor()->execute(
                        'options.update',
                        array( 'option' => 'ai_os_settings', 'value' => 'hacked' ),
                        $this->adminUser(),
                        'test'
                );
                // Either unknown tool (no such tool exists — by design) or
                // blocked by policy; never executed.
                $this->assertTrue( $result->isError() );
        }

        public function test_approval_queue_flow_end_to_end(): void {
                // Level 2 tool in safe mode.
                $result = $this->executor()->execute( 'system.cache.flush', array(), $this->adminUser(), 'test' );
                $this->assertTrue( $result->isApprovalRequest() );

                $approvals = Plugin::instance()->container()->get( \AIOS\Database\Repositories\ApprovalRepository::class );
                $this->assertEquals( 1, $approvals->pendingCount() );

                // Reject path.
                $rejected = $approvals->claimPending( $result->approvalId(), 'rejected', 1 );
                $this->assertNotNull( $rejected );
                $this->assertEquals( 'rejected', $rejected['status'] );
                $this->assertEquals( 0, $approvals->pendingCount() );

                // Double-decision must fail (claimed already).
                $this->assertNull( $approvals->claimPending( $result->approvalId(), 'approved', 1 ) );
        }

        public function test_bulk_approve_safe_only_touches_allowed_risk(): void {
                // Level 2 + level 3 requests.
                $this->executor()->execute( 'system.cache.flush', array(), $this->adminUser(), 'test' );
                $this->executor()->execute( 'content.create_post', array( 'title' => 'Keep' ), $this->adminUser(), 'test' ); // level 1: no approval

                $approvals = Plugin::instance()->container()->get( \AIOS\Database\Repositories\ApprovalRepository::class );
                $this->assertEquals( 1, $approvals->pendingCount() );

                $ids = $approvals->pendingIdsAtOrBelow( 1 );
                $this->assertCount( 0, $ids, 'level 2 request must not appear under a threshold of 1' );

                $ids = $approvals->pendingIdsAtOrBelow( 2 );
                $this->assertCount( 1, $ids );
        }

        public function test_audit_log_records_every_execution(): void {
                $this->executor()->execute( 'site.get_info', array(), $this->adminUser(), 'test' );
                $this->executor()->execute( 'nope.nope', array(), $this->adminUser(), 'test' );

                $logs = Plugin::instance()->container()->get( \AIOS\Audit\AuditLogger::class );
                $rows = $logs->repository()->query( array( 'tool' => 'site.get_info' ) );
                $this->assertGreaterThan( 0, count( $rows ) );

                $row = $rows[0];
                $this->assertEquals( 'ok', $row['status'] );
                $this->assertEquals( 0, $row['risk'] );
                $this->assertEquals( 1, $row['user_id'] );
                $this->assertEquals( 'test', $row['client'] );
        }

        public function test_audit_log_redacts_secret_arguments(): void {
                $this->executor()->execute(
                        'content.create_post',
                        array( 'title' => 'Post', 'content' => 'api_key=sk-abcdefghijklmnop12345', 'status' => 'draft' ),
                        $this->adminUser(),
                        'test'
                );

                $logs = Plugin::instance()->container()->get( \AIOS\Audit\AuditLogger::class );
                $rows = $logs->repository()->query( array( 'tool' => 'content.create_post' ) );
                $this->assertCount( 1, $rows );
                $stored = wp_json_encode( $rows[0]['args_decoded'] );
                $this->assertStringNotContains( 'sk-abcdefghijklmnop12345', (string) $stored, 'secrets must never persist in the audit log' );
        }

        public function test_approval_result_marks_not_executed(): void {
                $result = $this->executor()->execute( 'system.cache.flush', array(), $this->adminUser(), 'test' );
                $data = $result->data();

                $this->assertArrayHasKey( 'next_step', $data );
                $this->assertStringContains( 'NOT been executed', (string) $data['next_step'] );
                $this->assertArrayHasKey( 'approval_id', $data );
                $this->assertArrayHasKey( 'expires_at', $data );
        }

        public function test_rate_limiter_blocks_flood(): void {
                $limiter = new \AIOS\Security\RateLimiter( 3, 60 );

                $this->assertTrue( $limiter->allow( 'user:1', 'test' ) );
                $this->assertTrue( $limiter->allow( 'user:1', 'test' ) );
                $this->assertTrue( $limiter->allow( 'user:1', 'test' ) );
                $this->assertFalse( $limiter->allow( 'user:1', 'test' ), '4th event in window must be blocked' );

                $this->assertTrue( $limiter->allow( 'user:2', 'test' ), 'limit is per principal' );

                $meta = $limiter->meta( 'user:1', 'test' );
                $this->assertEquals( 3, $meta['limit'] );
                $this->assertGreaterThan( 0, $meta['retry_after'] );
        }

        public function test_editor_ceiling_blocks_sensitive_tools_even_balanced(): void {
                update_option( 'ai_os_settings', array( 'mode' => 'balanced' ) );
                $this->executor()->execute( 'content.create_post', array( 'title' => 'x' ), $this->editorUser(), 'test' );

                // Editor (no edit_others_posts) ceiling stays at 1.
                $result = $this->executor()->execute( 'system.cache.flush', array(), $this->editorUser(), 'test' );
                $this->assertTrue( $result->isError() );
                $this->assertEquals( 'permission.level_denied', $result->error()?->code() );
        }
}
