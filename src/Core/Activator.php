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

	public function activate( bool $network_wide = false ): void {
		// Environment was already checked in the bootstrap guard.

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
}
