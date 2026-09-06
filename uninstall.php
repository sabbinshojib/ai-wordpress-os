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

$ai_os_settings = get_option( 'ai_os_settings', array() );
$ai_os_remove   = is_array( $ai_os_settings ) && ! empty( $ai_os_settings['remove_data_on_uninstall'] );

// Always remove: transients, cron, onboarding flag.
delete_transient( 'ai_os_site_context' );
delete_transient( 'ai_os_activation_error' );
wp_clear_scheduled_hook( 'ai_os_daily_maintenance' );

if ( ! $ai_os_remove ) {
	// Retain data: drop only runtime cache entries.
	return;
}

// Full removal path (explicit opt-in).

$ai_os_tables = array(
	$wpdb->prefix . 'ai_os_audit_logs',
	$wpdb->prefix . 'ai_os_tool_executions',
	$wpdb->prefix . 'ai_os_approvals',
	$wpdb->prefix . 'ai_os_api_keys',
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

// Rate limiter transients (hash-keyed: delete by prefix scan).
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

// Clean up site-level transients on multisite.
if ( is_multisite() ) {
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
}
