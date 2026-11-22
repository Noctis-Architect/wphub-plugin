<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- One-time cleanup of fixed plugin tables and the plugin spool directory; dropping the plugin's own tables is intentional on uninstall.

wp_clear_scheduled_hook( 'wphs_aggregate' );
wp_clear_scheduled_hook( 'wphs_cloud_sync' );

$wphs_settings = get_option( 'wphs_settings', array() );
$wphs_delete   = is_array( $wphs_settings ) && ! empty( $wphs_settings['delete_on_uninstall'] );

if ( $wphs_delete ) {
	global $wpdb;

	$wphs_tables = array(
		'wphs_pages',
		'wphs_dims',
		'wphs_daily',
		'wphs_hourly',
		'wphs_totals',
		'wphs_totals_grade',
		'wphs_events',
		'wphs_seen',
		'wphs_seen_day',
		'wphs_seen_day_grade',
		'wphs_sessions',
		'wphs_hits',
	);

	foreach ( $wphs_tables as $wphs_table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $wphs_table );
	}
}

$wphs_uploads = wp_upload_dir( null, false );
$wphs_base    = untrailingslashit( $wphs_uploads['basedir'] ) . '/hubpulse';

foreach ( is_dir( $wphs_base ) ? (array) scandir( $wphs_base ) : array() as $wphs_entry ) {
	$wphs_dir = $wphs_base . '/' . $wphs_entry;

	if ( 0 !== strpos( (string) $wphs_entry, 'queue-' ) || ! is_dir( $wphs_dir ) ) {
		continue;
	}

	foreach ( (array) scandir( $wphs_dir ) as $wphs_file ) {
		if ( is_file( $wphs_dir . '/' . $wphs_file ) ) {
			wp_delete_file( $wphs_dir . '/' . $wphs_file );
		}
	}

	@rmdir( $wphs_dir );
}

if ( is_file( $wphs_base . '/index.php' ) ) {
	wp_delete_file( $wphs_base . '/index.php' );
}

@rmdir( $wphs_base );

delete_option( 'wphs_settings' );
delete_option( 'wphs_db_version' );
delete_option( 'wphs_spool_key' );
delete_option( 'wphs_last_cleanup' );
delete_option( 'wphs_last_run' );

// Keys and AI provider credentials are always removed, whatever the data setting.
delete_option( 'wphs_writer' );
delete_option( 'wphs_content' );
delete_option( 'wphs_security_edge' );
delete_transient( 'wphs_ai_tested' );

// Cloud sync state, the outage fallback record and per-user notice choices.
foreach ( array( 'last_attempt', 'last_success', 'last_error', 'last_count', 'last_http', 'failures', 'next_attempt', 'ping_at', 'ping_ok', 'ping_message', 'down_since', 'fallback' ) as $wphs_suffix ) {
	delete_option( 'wphs_cloud_' . $wphs_suffix );
}
delete_option( 'wphs_site_overview_last' );
delete_option( 'wphs_site_overview_at' );
delete_transient( 'wphs_site_overview' );
delete_transient( 'wphs_cloud_notice' );
delete_transient( 'wphs_cn_reject_logged' );
delete_metadata( 'user', 0, 'wphs_dismissed_national_net', '', true );
