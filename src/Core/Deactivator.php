<?php
/**
 * Deactivation: cron cleanup only. Data is preserved.
 *
 * @package AIOS\Core
 */

declare( strict_types=1 );

namespace AIOS\Core;

final class Deactivator {

	public function deactivate( bool $network_wide = false ): void {
		wp_clear_scheduled_hook( 'ai_os_daily_maintenance' );

		// Clear volatile caches.
		delete_transient( \AIOS\Context\ContextEngine::CACHE_KEY );
	}
}
