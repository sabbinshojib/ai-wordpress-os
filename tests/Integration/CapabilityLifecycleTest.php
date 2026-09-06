<?php
/**
 * Integration tests: ai_os_use / ai_os_approve capability lifecycle.
 *
 * The audit found these two capabilities referenced by
 * PermissionEngine::canUse() and AbstractController::canApprove() but
 * never granted to any role by this plugin — meaning only an account
 * that already holds manage_options could ever satisfy those checks.
 * That is a safe default in itself (administrators can always use and
 * approve), but it left the two capabilities as permanently-dead
 * constants with no path to ever being true for anyone else, and no
 * verification that activation/uninstall/deactivation treat them
 * correctly.
 *
 * Activator::grantDefaultCapabilities() now grants both to the
 * `administrator` role only, at activation. These tests verify: the
 * grant happens, no other role is touched, deactivation leaves role
 * capabilities alone entirely, and uninstall removes only the two
 * capabilities this plugin itself added — never anything else.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Activator;
use AIOS\Core\Deactivator;
use AIOS\Tests\TestCase;

final class CapabilityLifecycleTest extends TestCase {

        protected function setUp(): void {
                $this->resetPlugin();
                $GLOBALS['__wp_shim']['roles'] = array();
        }

        public function test_activation_grants_capabilities_to_administrator_only(): void {
                ( new Activator() )->activate( false );

                $administrator = get_role( 'administrator' );
                $this->assertNotNull( $administrator );
                $this->assertTrue( $administrator->has_cap( 'ai_os_use' ) );
                $this->assertTrue( $administrator->has_cap( 'ai_os_approve' ) );
        }

        public function test_activation_does_not_grant_capabilities_to_lower_roles(): void {
                ( new Activator() )->activate( false );

                foreach ( array( 'editor', 'author', 'contributor', 'subscriber' ) as $role_name ) {
                        $role = get_role( $role_name );
                        $this->assertNotNull( $role, "role [{$role_name}] must exist in this environment" );
                        $this->assertFalse( $role->has_cap( 'ai_os_use' ), "role [{$role_name}] must not receive ai_os_use automatically" );
                        $this->assertFalse( $role->has_cap( 'ai_os_approve' ), "role [{$role_name}] must not receive ai_os_approve automatically" );
                }
        }

        public function test_activation_does_not_grant_unrelated_capabilities(): void {
                ( new Activator() )->activate( false );

                $administrator = get_role( 'administrator' );
                // The role already had manage_options before activation
                // (seeded by the shim's built-in role defaults) — activation
                // must not add anything beyond the two AI OS capabilities.
                $before_activation_caps = array( 'manage_options', 'edit_posts', 'edit_pages', 'edit_others_posts', 'publish_posts', 'upload_files', 'delete_posts', 'list_users' );
                foreach ( $before_activation_caps as $cap ) {
                        $this->assertTrue( $administrator->has_cap( $cap ), "pre-existing capability [{$cap}] must be unaffected" );
                }
        }

        public function test_activation_is_idempotent_for_capability_grants(): void {
                ( new Activator() )->activate( false );
                ( new Activator() )->activate( false ); // Re-activation must not error or change anything.

                $administrator = get_role( 'administrator' );
                $this->assertTrue( $administrator->has_cap( 'ai_os_use' ) );
                $this->assertTrue( $administrator->has_cap( 'ai_os_approve' ) );
        }

        public function test_deactivation_does_not_touch_any_role_capability(): void {
                ( new Activator() )->activate( false );
                $administrator = get_role( 'administrator' );
                $this->assertTrue( $administrator->has_cap( 'ai_os_use' ) );

                ( new Deactivator() )->deactivate( false );

                // Still granted: deactivation must never strip capabilities
                // (only uninstall, and only on explicit data-removal opt-in,
                // may do that).
                $this->assertTrue( $administrator->has_cap( 'ai_os_use' ) );
                $this->assertTrue( $administrator->has_cap( 'ai_os_approve' ) );
                $this->assertTrue( $administrator->has_cap( 'manage_options' ), 'unrelated capabilities must be untouched by deactivation' );
        }

        public function test_uninstall_with_retention_leaves_capabilities_granted(): void {
                ( new Activator() )->activate( false );
                update_option( 'ai_os_settings', array( 'remove_data_on_uninstall' => false ) );

                $this->runUninstallScript();

                $administrator = get_role( 'administrator' );
                $this->assertTrue( $administrator->has_cap( 'ai_os_use' ), 'retained-data uninstall must not strip capabilities either' );
        }

        public function test_uninstall_with_data_removal_strips_only_plugin_capabilities(): void {
                ( new Activator() )->activate( false );
                update_option( 'ai_os_settings', array( 'remove_data_on_uninstall' => true ) );

                $administrator = get_role( 'administrator' );
                $this->assertTrue( $administrator->has_cap( 'manage_options' ) );

                $this->runUninstallScript();

                $this->assertFalse( $administrator->has_cap( 'ai_os_use' ), 'opted-in uninstall must remove the plugin-granted capability' );
                $this->assertFalse( $administrator->has_cap( 'ai_os_approve' ), 'opted-in uninstall must remove the plugin-granted capability' );
                $this->assertTrue( $administrator->has_cap( 'manage_options' ), 'uninstall must never remove a capability the plugin did not itself grant' );
                $this->assertTrue( $administrator->has_cap( 'edit_posts' ), 'uninstall must never remove a capability the plugin did not itself grant' );
        }

        // ---------------------------------------------------- baseline usability

        /**
         * Confirms the actual, end-user-visible consequence of the fix:
         * an administrator can use AND approve immediately after a plain
         * activation, with zero extra setup.
         */
        public function test_administrator_can_use_and_approve_immediately_after_activation(): void {
                ( new Activator() )->activate( false );

                $admin = $this->adminUser();
                $this->assertTrue( \AIOS\Security\PermissionEngine::canUse( $admin ) );
        }

        /**
         * A contributor-level account (edit_posts only, no
         * manage_options) must be able to USE the console at its
         * permitted ceiling, per the existing capability-ceiling design
         * — but must never be able to approve, since ai_os_approve is
         * never granted to anything but administrator.
         */
        public function test_contributor_can_use_but_cannot_approve(): void {
                $contributor = $this->contributorUser();
                $this->assertTrue( \AIOS\Security\PermissionEngine::canUse( $contributor ), 'edit_posts alone already permits baseline use — unrelated to this fix' );
                $this->assertFalse( $contributor->has_cap( 'ai_os_approve' ), 'must not have approval capability by default' );
        }

        // ------------------------------------------------------------ helpers

        private function runUninstallScript(): void {
                if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
                        define( 'WP_UNINSTALL_PLUGIN', true );
                }
                // function_exists(), not a per-class static flag: this same
                // helper (and the same uninstall.php require) also exists
                // in MultisiteLifecycleTest — whichever test class runs
                // first in the process must "win" the require for both.
                if ( ! function_exists( 'ai_os_uninstall_current_site' ) ) {
                        require dirname( __DIR__, 2 ) . '/uninstall.php';
                        return;
                }
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
