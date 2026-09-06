<?php
/**
 * Deactivation: cron cleanup only. Data is preserved.
 *
 * @package AIOS\Core
 */

declare( strict_types=1 );

namespace AIOS\Core;

final class Deactivator {

	/**
	 * @param bool $network_wide Whether the plugin was network-deactivated
	 *                           across every site (spec BUG-005).
	 */
	public function deactivate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			// WP-Cron events and transients are both site-scoped; clear
			// them on every site, not just whichever one WordPress
			// happened to run the deactivation hook in.
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				$this->deactivateSingleSite();
				restore_current_blog();
			}
			return;
		}

		$this->deactivateSingleSite();
	}

	private function deactivateSingleSite(): void {
		wp_clear_scheduled_hook( 'ai_os_daily_maintenance' );

		// Clear volatile caches.
		delete_transient( \AIOS\Context\ContextEngine::CACHE_KEY );
	}
}
