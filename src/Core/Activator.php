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
	}
}
