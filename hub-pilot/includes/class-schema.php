<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables use fixed internal wphs_* names from WPHS_Schema::table(), never user input.

final class WPHS_Schema {

	const DB_VERSION = '3';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wphs_' . $name;
	}

	public static function activate() {
		self::install();
		WPHS_Settings::install_defaults();
		WPHS_Spool::ensure_dir();
		WPHS_Aggregator::schedule();
		WPHS_Cloud::schedule();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'wphs_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$previous = (string) get_option( 'wphs_db_version', '' );
		$charset  = $wpdb->get_charset_collate();
		$p        = $wpdb->prefix;

		$tables = array(
			"CREATE TABLE {$p}wphs_pages (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				page_key char(32) NOT NULL,
				path varchar(255) NOT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY page_key (page_key)
			) {$charset};",

			"CREATE TABLE {$p}wphs_dims (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				dim_hash char(32) NOT NULL,
				page_id bigint(20) unsigned NOT NULL DEFAULT 0,
				referrer_host varchar(100) NOT NULL DEFAULT '',
				channel varchar(20) NOT NULL DEFAULT '',
				device varchar(10) NOT NULL DEFAULT '',
				browser varchar(30) NOT NULL DEFAULT '',
				country char(2) NOT NULL DEFAULT '',
				grade char(1) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY dim_hash (dim_hash),
				KEY page_id (page_id),
				KEY grade (grade)
			) {$charset};",

			"CREATE TABLE {$p}wphs_daily (
				day date NOT NULL,
				dim_id bigint(20) unsigned NOT NULL,
				pageviews int(10) unsigned NOT NULL DEFAULT 0,
				visitors int(10) unsigned NOT NULL DEFAULT 0,
				engaged int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day,dim_id),
				KEY dim_id (dim_id)
			) {$charset};",

			"CREATE TABLE {$p}wphs_hourly (
				hour datetime NOT NULL,
				dim_id bigint(20) unsigned NOT NULL,
				pageviews int(10) unsigned NOT NULL DEFAULT 0,
				visitors int(10) unsigned NOT NULL DEFAULT 0,
				engaged int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (hour,dim_id),
				KEY dim_id (dim_id)
			) {$charset};",

			"CREATE TABLE {$p}wphs_totals (
				day date NOT NULL,
				pageviews bigint(20) unsigned NOT NULL DEFAULT 0,
				visitors int(10) unsigned NOT NULL DEFAULT 0,
				sessions int(10) unsigned NOT NULL DEFAULT 0,
				bounces int(10) NOT NULL DEFAULT 0,
				engaged bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day)
			) {$charset};",

			"CREATE TABLE {$p}wphs_totals_grade (
				day date NOT NULL,
				grade char(1) NOT NULL DEFAULT '',
				pageviews bigint(20) unsigned NOT NULL DEFAULT 0,
				visitors int(10) unsigned NOT NULL DEFAULT 0,
				sessions int(10) unsigned NOT NULL DEFAULT 0,
				bounces int(10) NOT NULL DEFAULT 0,
				engaged bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day,grade)
			) {$charset};",

			"CREATE TABLE {$p}wphs_events (
				day date NOT NULL,
				dim_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event varchar(32) NOT NULL DEFAULT '',
				count int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day,dim_id,event)
			) {$charset};",

			"CREATE TABLE {$p}wphs_seen (
				day date NOT NULL,
				dim_id bigint(20) unsigned NOT NULL DEFAULT 0,
				visitor_hash char(32) NOT NULL DEFAULT '',
				PRIMARY KEY  (day,dim_id,visitor_hash)
			) {$charset};",

			"CREATE TABLE {$p}wphs_seen_day (
				day date NOT NULL,
				visitor_hash char(32) NOT NULL DEFAULT '',
				PRIMARY KEY  (day,visitor_hash)
			) {$charset};",

			"CREATE TABLE {$p}wphs_seen_day_grade (
				day date NOT NULL,
				grade char(1) NOT NULL DEFAULT '',
				visitor_hash char(32) NOT NULL DEFAULT '',
				PRIMARY KEY  (day,grade,visitor_hash)
			) {$charset};",

			"CREATE TABLE {$p}wphs_sessions (
				session_hash char(32) NOT NULL,
				visitor_hash char(32) NOT NULL DEFAULT '',
				day date NOT NULL,
				dim_id bigint(20) unsigned NOT NULL DEFAULT 0,
				pageviews int(10) unsigned NOT NULL DEFAULT 0,
				engaged int(10) unsigned NOT NULL DEFAULT 0,
				is_bounce tinyint(1) NOT NULL DEFAULT 1,
				first_ts int(10) unsigned NOT NULL DEFAULT 0,
				last_ts int(10) unsigned NOT NULL DEFAULT 0,
				grade char(1) NOT NULL DEFAULT '',
				PRIMARY KEY  (session_hash),
				KEY day (day),
				KEY visitor_last (visitor_hash,last_ts),
				KEY visitor_grade_last (visitor_hash,grade,last_ts)
			) {$charset};",

			"CREATE TABLE {$p}wphs_hits (
				hit_id char(32) NOT NULL,
				client_id char(32) NOT NULL DEFAULT '',
				session_hash char(32) NOT NULL DEFAULT '',
				visitor_hash char(32) NOT NULL DEFAULT '',
				dim_id bigint(20) unsigned NOT NULL DEFAULT 0,
				day date NOT NULL,
				hour datetime NOT NULL,
				source varchar(6) NOT NULL DEFAULT 'server',
				counted tinyint(1) NOT NULL DEFAULT 0,
				eng_seq int(10) unsigned NOT NULL DEFAULT 0,
				grade char(1) NOT NULL DEFAULT '',
				created int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (hit_id),
				KEY client_id (client_id),
				KEY created (created),
				KEY day (day),
				KEY grade (grade)
			) {$charset};",
		);

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		if ( version_compare( $previous, '3', '<' ) ) {
			self::backfill_grade_tables();
		}

		update_option( 'wphs_db_version', self::DB_VERSION, true );
	}

	private static function backfill_grade_tables() {
		global $wpdb;

		$totals       = self::table( 'totals' );
		$totals_grade = self::table( 'totals_grade' );
		$seen_day     = self::table( 'seen_day' );
		$seen_grade   = self::table( 'seen_day_grade' );

		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$totals_grade}" ) ) {
			$wpdb->query(
				"INSERT IGNORE INTO {$totals_grade} (day,grade,pageviews,visitors,sessions,bounces,engaged)
				SELECT day,'' AS grade,pageviews,visitors,sessions,bounces,engaged FROM {$totals}"
			);
		}

		if ( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$seen_grade}" ) ) {
			$wpdb->query(
				"INSERT IGNORE INTO {$seen_grade} (day,grade,visitor_hash)
				SELECT day,'' AS grade,visitor_hash FROM {$seen_day}"
			);
		}
	}
}
