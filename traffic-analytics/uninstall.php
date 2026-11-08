<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'wphs_aggregate' );
wp_clear_scheduled_hook( 'wphs_cloud_sync' );

$settings = get_option( 'wphs_settings', array() );
$delete   = is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] );

if ( $delete ) {
	global $wpdb;

	$tables = array(
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

	foreach ( $tables as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $table );
	}
}

$key = get_option( 'wphs_spool_key', '' );
if ( is_string( $key ) && preg_match( '/^[a-z0-9]{8,20}$/', $key ) ) {
	$dir = trailingslashit( WP_CONTENT_DIR ) . 'wphub-analytics-' . $key;

	foreach ( (array) glob( $dir . '/*' ) as $file ) {
		if ( is_file( $file ) ) {
			@unlink( $file );
		}
	}

	@rmdir( $dir );
}

delete_option( 'wphs_settings' );
delete_option( 'wphs_db_version' );
delete_option( 'wphs_spool_key' );
delete_option( 'wphs_last_cleanup' );
delete_option( 'wphs_last_run' );
