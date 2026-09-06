<?php
/**
 * Uninstall cleanup (spec §39/§58).
 *
 * Runs when the plugin is DELETED from the plugins screen (not on
 * deactivation). Honors the remove_data_on_uninstall setting: audit
 * logs, approvals, API keys and settings are only removed when the
 * administrator explicitly opted in. Otherwise tables persist for a
 * future reinstall (they contain no secrets: keys are hashes,
 * arguments are redacted).
 *
 * @package AIOS
 */

declare( strict_types=1 );

// WordPress loads uninstall.php only in the plugin deletion context.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

/**
 * Clean up whichever site is CURRENT when this runs (spec BUG-005).
 *
 * Every table, option, and transient this plugin owns is site-scoped
 * in WordPress. Retention ("remove_data_on_uninstall") is read from
 * that site's OWN settings, so a site that opted in keeps that choice
 * even inside a network-wide uninstall — retention is exactly as
 * per-site as every other AI OS setting, never a global override.
 */
function ai_os_uninstall_current_site(): void {
	global $wpdb;

	$ai_os_settings = get_option( 'ai_os_settings', array() );
	$ai_os_remove   = is_array( $ai_os_settings ) && ! empty( $ai_os_settings['remove_data_on_uninstall'] );

	// Always remove: transients, cron (pure runtime cache — never
	// meaningful "data" a retention choice would apply to).
	delete_transient( 'ai_os_site_context' );
	delete_transient( 'ai_os_activation_error' );
	delete_transient( 'ai_os_environment_warnings' );
	wp_clear_scheduled_hook( 'ai_os_daily_maintenance' );

	// Legacy rate-limiter transients (Sprint 0.1 and earlier stored
	// rate limits as transients, hash-keyed; Sprint 0.3A moved this to
	// the ai_os_rate_limits table, cleaned up below with the other
	// tables). Kept here so a site upgrading from that era still gets
	// its stale transients cleaned up on uninstall.
	$ai_os_rl = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			'_transient_ai_os_rl_%'
		)
	);
	foreach ( (array) $ai_os_rl as $ai_os_transient_name ) {
		$ai_os_key = str_replace( '_transient_', '', (string) $ai_os_transient_name );
		delete_transient( $ai_os_key );
	}

	if ( ! $ai_os_remove ) {
		// Retain data: drop only runtime cache entries, above.
		return;
	}

	// Full removal path (explicit opt-in, this site's own setting).

	$ai_os_tables = array(
		$wpdb->prefix . 'ai_os_audit_logs',
		$wpdb->prefix . 'ai_os_tool_executions',
		$wpdb->prefix . 'ai_os_approvals',
		$wpdb->prefix . 'ai_os_api_keys',
		$wpdb->prefix . 'ai_os_rate_limits',
	);

	foreach ( $ai_os_tables as $ai_os_table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$ai_os_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	$ai_os_options = array(
		'ai_os_settings',
		'ai_os_migrations',
		'ai_os_onboarded',
		'ai_os_user_level_grants',
	);

	foreach ( $ai_os_options as $ai_os_option ) {
		delete_option( $ai_os_option );
	}

	// Remove only the two capabilities this plugin itself grants
	// (Activator::grantDefaultCapabilities(), administrator role only)
	// — never touch any other capability or role. Roles are stored
	// per-site in WordPress, exactly like the options above, so this
	// belongs inside the per-site cleanup, not the network-wide branch.
	$ai_os_administrator_role = get_role( 'administrator' );
	if ( null !== $ai_os_administrator_role ) {
		$ai_os_administrator_role->remove_cap( 'ai_os_use' );
		$ai_os_administrator_role->remove_cap( 'ai_os_approve' );
	}
}

if ( is_multisite() ) {
	// WordPress loads uninstall.php exactly ONCE regardless of how many
	// sites exist in the network — it does NOT iterate this file per
	// site on its own. Without this loop, only the single site whose
	// admin triggered the deletion request was ever cleaned up; every
	// other site's tables/options/transients were silently orphaned
	// forever (BUG-005). switch_to_blog()/restore_current_blog() make
	// every WordPress option/transient/table-prefix call above operate
	// on one site at a time, exactly as they do for a normal request.
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $ai_os_site_id ) {
		switch_to_blog( (int) $ai_os_site_id );
		ai_os_uninstall_current_site();
		restore_current_blog();
	}

	// Network-wide (sitemeta-level) rate-limiter transient cleanup —
	// this one lives outside any single site's options table already.
	$ai_os_site_rl = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
			'_site_transient_ai_os_rl_%'
		)
	);
	foreach ( (array) $ai_os_site_rl as $ai_os_transient_name ) {
		$ai_os_key = str_replace( '_site_transient_', '', (string) $ai_os_transient_name );
		delete_site_transient( $ai_os_key );
	}
} else {
	ai_os_uninstall_current_site();
}
