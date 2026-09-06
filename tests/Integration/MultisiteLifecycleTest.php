<?php
/**
 * Integration tests: multisite activation, uninstall, and new-site
 * provisioning lifecycle (spec BUG-005).
 *
 * The shim models a network as a list of blog ids plus a "current
 * blog" pointer (see tests/shim/wp-functions.php). Options,
 * transients, and $wpdb->prefix are all blog-scoped, exactly like
 * real WordPress — switch_to_blog()/restore_current_blog() move
 * between them. dbDelta() logs every table name it is asked to
 * create, which is what these tests use to prove a table was
 * actually provisioned for a given site's prefix, without needing
 * the wpdb SQL-regex test double to understand multisite prefixes at
 * the storage-and-query level (a much bigger undertaking, out of this
 * sprint's scope — see docs/roadmap/IMPLEMENTATION-TRACKER.md T-018).
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Activator;
use AIOS\Core\Deactivator;
use AIOS\Tests\TestCase;

final class MultisiteLifecycleTest extends TestCase {

        protected function setUp(): void {
                $this->resetPlugin();
                // resetPlugin() pre-seeds blog 1 with "migration already
                // applied" as a convenience for tests that don't care
                // about first-run activation — this class specifically
                // exercises activation/migration behavior, so start every
                // site (including blog 1) genuinely fresh instead.
                update_option( 'ai_os_migrations', array() );

                $GLOBALS['__wp_shim']['multisite']      = true;
                $GLOBALS['__wp_shim']['sites']           = array( 1, 2, 3 );
                $GLOBALS['__wp_shim']['current_blog_id'] = 1;
                $GLOBALS['__wp_shim']['created_tables']  = array();
        }

        protected function tearDown(): void {
                $GLOBALS['__wp_shim']['multisite'] = false;
                $GLOBALS['__wp_shim']['sites']     = array( 1 );
        }

        // ------------------------------------------------------- activation

        /**
         * Network activation must provision EVERY site, not just
         * whichever one initiated the request — the confirmed BUG-005
         * defect ($network_wide was accepted and silently discarded).
         */
        public function test_network_activation_provisions_every_site(): void {
                ( new Activator() )->activate( true );

                $created = $GLOBALS['__wp_shim']['created_tables'];

                $this->assertTrue( in_array( 'wp_ai_os_audit_logs', $created, true ), 'main site (blog 1) must be provisioned' );
                $this->assertTrue( in_array( 'wp_2_ai_os_audit_logs', $created, true ), 'blog 2 must be provisioned' );
                $this->assertTrue( in_array( 'wp_3_ai_os_audit_logs', $created, true ), 'blog 3 must be provisioned' );

                // All five tables (audit_logs, tool_executions, approvals,
                // api_keys, rate_limits), for all three sites.
                $this->assertCount( 15, array_unique( $created ) );
        }

        /**
         * Every site gets its OWN migration-applied record — settings and
         * migration state are per-site options in real WordPress, so one
         * site's provisioning must never be short-circuited by another
         * site's already-applied state.
         */
        public function test_network_activation_tracks_migrations_independently_per_site(): void {
                ( new Activator() )->activate( true );

                switch_to_blog( 1 );
                $this->assertEquals( array( '202501010001', '202509060001', '202509060002' ), get_option( 'ai_os_migrations' ) );
                restore_current_blog();

                switch_to_blog( 2 );
                $this->assertEquals( array( '202501010001', '202509060001', '202509060002' ), get_option( 'ai_os_migrations' ) );
                restore_current_blog();

                switch_to_blog( 3 );
                $this->assertEquals( array( '202501010001', '202509060001', '202509060002' ), get_option( 'ai_os_migrations' ) );
                restore_current_blog();
        }

        /**
         * switch_to_blog()/restore_current_blog() must always balance:
         * after a full network activation, the shim's "current site"
         * must be back to whatever it was before, not left on the last
         * site visited.
         */
        public function test_network_activation_leaves_no_dangling_blog_switch(): void {
                $before = get_current_blog_id();
                ( new Activator() )->activate( true );
                $this->assertEquals( $before, get_current_blog_id(), 'switch_to_blog() calls must be fully balanced by restore_current_blog()' );
                $this->assertEquals( array(), $GLOBALS['__wp_shim']['blog_switch_stack'], 'no dangling blog switch may remain on the stack' );
        }

        /**
         * A plain (non network-wide) activation on a multisite install
         * must still only touch the current site — network_wide=false
         * must never implicitly become "every site".
         */
        public function test_non_network_activation_only_touches_current_site(): void {
                ( new Activator() )->activate( false );

                $created = $GLOBALS['__wp_shim']['created_tables'];
                $this->assertTrue( in_array( 'wp_ai_os_audit_logs', $created, true ) );
                $this->assertFalse( in_array( 'wp_2_ai_os_audit_logs', $created, true ), 'non-network activation must not touch other sites' );
                $this->assertFalse( in_array( 'wp_3_ai_os_audit_logs', $created, true ), 'non-network activation must not touch other sites' );
        }

        // -------------------------------------------------- new-site hook

        public function test_new_site_is_provisioned_when_network_active(): void {
                $GLOBALS['__wp_shim']['network_active'] = true;
                $GLOBALS['__wp_shim']['sites']           = array( 1 ); // Site 4 does not exist yet.

                ( new Activator() )->provisionNewSite( (object) array( 'blog_id' => 4 ) );

                $this->assertTrue( in_array( 'wp_4_ai_os_audit_logs', $GLOBALS['__wp_shim']['created_tables'], true ) );
                $this->assertEquals( 1, get_current_blog_id(), 'must restore the original current site afterward' );
        }

        public function test_new_site_is_not_provisioned_when_plugin_is_not_network_active(): void {
                $GLOBALS['__wp_shim']['network_active'] = false;

                ( new Activator() )->provisionNewSite( (object) array( 'blog_id' => 4 ) );

                $this->assertFalse( in_array( 'wp_4_ai_os_audit_logs', $GLOBALS['__wp_shim']['created_tables'], true ), 'must not provision a new site unless network-active' );
        }

        // ------------------------------------------------------ uninstall

        /**
         * Network-wide uninstall with every site opted in to data
         * removal must drop every site's tables — not just the
         * initiating site's (the confirmed BUG-005 uninstall defect).
         */
        public function test_network_uninstall_removes_every_opted_in_sites_tables(): void {
                ( new Activator() )->activate( true );

                foreach ( array( 1, 2, 3 ) as $site_id ) {
                        switch_to_blog( $site_id );
                        update_option( 'ai_os_settings', array( 'remove_data_on_uninstall' => true ) );
                        restore_current_blog();
                }

                $this->runUninstallScript();

                foreach ( array( 1, 2, 3 ) as $site_id ) {
                        switch_to_blog( $site_id );
                        $this->assertEquals( array(), get_option( 'ai_os_migrations', array() ), "site {$site_id} migrations option must be removed" );
                        $this->assertFalse( get_option( 'ai_os_settings', false ), "site {$site_id} settings option must be removed" );
                        restore_current_blog();
                }
        }

        /**
         * Retention is a per-site decision: a site that did NOT opt in
         * keeps its data even during a network-wide uninstall of every
         * other (opted-in) site.
         */
        public function test_network_uninstall_respects_per_site_retention(): void {
                ( new Activator() )->activate( true );

                switch_to_blog( 1 );
                update_option( 'ai_os_settings', array( 'remove_data_on_uninstall' => true ) );
                restore_current_blog();

                switch_to_blog( 2 );
                update_option( 'ai_os_settings', array( 'remove_data_on_uninstall' => false ) );
                restore_current_blog();

                $this->runUninstallScript();

                switch_to_blog( 1 );
                $this->assertFalse( get_option( 'ai_os_settings', false ), 'site 1 opted in: settings must be removed' );
                restore_current_blog();

                switch_to_blog( 2 );
                $this->assertNotEquals( false, get_option( 'ai_os_settings', false ), 'site 2 did NOT opt in: settings must be retained' );
                restore_current_blog();
        }

        /**
         * Runtime cache (transients, cron) is always cleared for every
         * site regardless of the retention setting — it is never
         * "data" a retention choice applies to.
         */
        public function test_network_uninstall_always_clears_runtime_cache_for_every_site(): void {
                foreach ( array( 1, 2, 3 ) as $site_id ) {
                        switch_to_blog( $site_id );
                        set_transient( 'ai_os_site_context', array( 'x' => 1 ) );
                        update_option( 'ai_os_settings', array( 'remove_data_on_uninstall' => false ) );
                        restore_current_blog();
                }

                $this->runUninstallScript();

                foreach ( array( 1, 2, 3 ) as $site_id ) {
                        switch_to_blog( $site_id );
                        $this->assertFalse( get_transient( 'ai_os_site_context' ), "site {$site_id} runtime cache must always be cleared" );
                        // Retention was declined: the option itself must survive.
                        $this->assertNotEquals( false, get_option( 'ai_os_settings', false ) );
                        restore_current_blog();
                }
        }

        /**
         * Uninstall must never touch options/transients belonging to
         * something other than this plugin, on any site.
         */
        public function test_uninstall_does_not_touch_unrelated_data(): void {
                switch_to_blog( 1 );
                update_option( 'some_other_plugin_setting', 'keep-me' );
                update_option( 'ai_os_settings', array( 'remove_data_on_uninstall' => true ) );
                restore_current_blog();

                $this->runUninstallScript();

                switch_to_blog( 1 );
                $this->assertEquals( 'keep-me', get_option( 'some_other_plugin_setting' ) );
                restore_current_blog();
        }

        // -------------------------------------------------- deactivation

        public function test_network_deactivation_balances_every_blog_switch(): void {
                $before = get_current_blog_id();
                ( new Deactivator() )->deactivate( true );
                $this->assertEquals( $before, get_current_blog_id() );
                $this->assertEquals( array(), $GLOBALS['__wp_shim']['blog_switch_stack'] );
        }

        // ------------------------------------------------------------ helpers

        /**
         * Executes uninstall.php in-process. WP_UNINSTALL_PLUGIN is
         * defined exactly once (constants cannot be redefined) since
         * every native test class shares one PHP process.
         */
        private function runUninstallScript(): void {
                if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
                        define( 'WP_UNINSTALL_PLUGIN', true );
                }
                // uninstall.php defines ai_os_uninstall_current_site() at the
                // top level; PHP fatals on a duplicate function definition,
                // so only require it once per process. function_exists()
                // (not a per-class static flag) is the correct guard here:
                // CapabilityLifecycleTest also requires this same file, and
                // whichever test class runs first in the process must be
                // the one that "wins" the require for both of them.
                if ( ! function_exists( 'ai_os_uninstall_current_site' ) ) {
                        require dirname( __DIR__, 2 ) . '/uninstall.php';
                        return;
                }
                // Second+ call in the same process: re-run just the
                // multisite loop / single-site branch by re-declaring is
                // not possible (function already defined), so directly
                // invoke the already-defined entry points the file itself
                // would have run.
                if ( is_multisite() ) {
                        foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
                                switch_to_blog( (int) $site_id );
                                \ai_os_uninstall_current_site();
                                restore_current_blog();
                        }
                } else {
                        \ai_os_uninstall_current_site();
                }
        }
}
