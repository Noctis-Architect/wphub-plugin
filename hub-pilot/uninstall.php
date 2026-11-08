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

$wphs_key = get_option( 'wphs_spool_key', '' );
if ( is_string( $wphs_key ) && preg_match( '/^[a-z0-9]{8,20}$/', $wphs_key ) ) {
	$wphs_dir = trailingslashit( WP_CONTENT_DIR ) . 'hub-pilot-' . $wphs_key;

	foreach ( (array) glob( $wphs_dir . '/*' ) as $wphs_file ) {
		if ( is_file( $wphs_file ) ) {
			@unlink( $wphs_file );
		}
	}

	@rmdir( $wphs_dir );
}

delete_option( 'wphs_settings' );
delete_option( 'wphs_db_version' );
delete_option( 'wphs_spool_key' );
delete_option( 'wphs_last_cleanup' );
delete_option( 'wphs_last_run' );
