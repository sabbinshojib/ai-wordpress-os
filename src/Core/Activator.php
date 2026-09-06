<?php
/**
 * Activation routine (spec §58: activation must run safe checks).
 *
 * @package AIOS\Core
 */

declare( strict_types=1 );

namespace AIOS\Core;

use AIOS\Database\Database;
use AIOS\Database\Migrator;
use AIOS\Settings\Settings;

final class Activator {

	/**
	 * @param bool $network_wide Whether the plugin was network-activated
	 *                           across every site (spec BUG-005).
	 */
	public function activate( bool $network_wide = false ): void {
		// Environment was already checked in the bootstrap guard.

		if ( $network_wide && is_multisite() ) {
			// Every table, option, and cron schedule this plugin owns is
			// site-scoped in WordPress (they live in each site's own
			// wp_options/cron), so a network activation must provision
			// each site individually — not just whichever site happened
			// to initiate the request (BUG-005: this branch previously
			// did not exist at all, silently discarding $network_wide).
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				$this->activateSingleSite();
				restore_current_blog();
			}
			return;
		}

		$this->activateSingleSite();
	}

	/**
	 * Provision the CURRENT site only (whatever `switch_to_blog()` last
	 * left active, or the only site there is on a non-multisite
	 * install). Also the entry point for provisioning a single new site
	 * added to an already network-active install — see
	 * CoreServiceProvider::provisionNewSite().
	 */
	public function activateSingleSite(): void {
		$database = new Database();
		$migrator = new Migrator();

		$result = $migrator->migrate( $database );

		// First-run onboarding flag.
		if ( false === get_option( 'ai_os_onboarded', false ) ) {
			update_option( 'ai_os_onboarded', false, true ); // explicitly not yet onboarded
		}

		// Settings defaults (Settings sanitizes + persists on construct).
		new Settings();

		// Schedule maintenance.
		if ( ! wp_next_scheduled( 'ai_os_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ai_os_daily_maintenance' );
		}

		// Surface migration failure to the admin on next load.
		if ( null !== $result['failed'] ) {
			set_transient( 'ai_os_activation_error', $result['failed'], 10 * MINUTE_IN_SECONDS );
		}

		// Surface any degraded (never blocking) optional capability so
		// an administrator knows before it matters — e.g. before a
		// future feature that needs AIOS\Support\Crypto's encryption
		// backend actually runs on this host.
		$degraded = EnvironmentGuard::degradedCapabilities();
		if ( array() !== $degraded ) {
			set_transient( 'ai_os_environment_warnings', $degraded, WEEK_IN_SECONDS );
		} else {
			delete_transient( 'ai_os_environment_warnings' );
		}
	}

	/**
	 * Provision a single new site added to the network AFTER the plugin
	 * was already network-activated (spec BUG-005: "handle new sites
	 * created after network activation"). Hooked to `wp_insert_site` by
	 * CoreServiceProvider::boot() on every request, so it only ever
	 * fires when WordPress itself reports a new site being created —
	 * never a bulk operation, so no loop or explicit ceiling is needed
	 * here the way there is in activate().
	 *
	 * @param object{blog_id: int} $new_site
	 */
	public function provisionNewSite( object $new_site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) && defined( 'ABSPATH' ) ) {
			$admin_includes = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_file( $admin_includes ) ) {
				require_once $admin_includes;
			}
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( AI_WP_OS_BASENAME ) ) {
			return; // Not network-active: this site gets provisioned the normal, single-site way if/when it activates the plugin itself.
		}

		switch_to_blog( (int) $new_site->blog_id );
		$this->activateSingleSite();
		restore_current_blog();
	}
}
