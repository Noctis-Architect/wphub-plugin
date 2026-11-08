<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom analytics tables use fixed names from WPHS_Schema::table().

final class WPHS_Reports {

	public static function requested_range() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report parameter.
		$range = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 7;

		if ( ! in_array( $range, array( 1, 7, 30, 90 ), true ) ) {
			$range = 7;
		}

		return $range;
	}

	public static function requested_mode() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report parameter.
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'verified';

		return self::valid_mode( $mode );
	}

	public static function mode_options() {
		return array(
			'verified' => __( 'Verified', 'hub-pilot' ),
			'all'      => __( 'All', 'hub-pilot' ),
			'nojs'     => __( 'No-JS', 'hub-pilot' ),
			'suspect'  => __( 'Suspect', 'hub-pilot' ),
		);
	}

	public static function mode_label( $mode ) {
		$options = self::mode_options();
		$mode    = self::valid_mode( $mode );

		return isset( $options[ $mode ] ) ? $options[ $mode ] : $options['verified'];
	}

	public static function mode_hint( $mode ) {
		switch ( self::valid_mode( $mode ) ) {
			case 'all':
				return __( 'All recorded visits, including verified, No-JS, and suspect traffic.', 'hub-pilot' );
			case 'nojs':
				return __( 'The User-Agent was valid, but no browser verification (JavaScript) was received.', 'hub-pilot' );
			case 'suspect':
				return __( 'Browser signals were not verified or bot signals were detected.', 'hub-pilot' );
			default:
				return __( 'Only visits with a valid User-Agent and verified browser signals.', 'hub-pilot' );
		}
	}

	private static function valid_mode( $mode ) {
		$mode = strtolower( (string) $mode );

		return in_array( $mode, array( 'verified', 'all', 'nojs', 'suspect' ), true ) ? $mode : 'verified';
	}

	private static function grades( $mode ) {
		switch ( self::valid_mode( $mode ) ) {
			case 'all':
				return array( '', 'v', 'u', 's', 'd' );
			case 'nojs':
				return array( 'u' );
			case 'suspect':
				return array( 's', 'd' );
			default:
				return array( 'v' );
		}
	}

	private static function grade_filter( $mode, $column = 'grade' ) {
		if ( 'all' === self::valid_mode( $mode ) ) {
			return '';
		}

		$values = array();

		foreach ( self::grades( $mode ) as $grade ) {
			$values[] = "'" . $grade . "'";
		}

		return ' AND ' . $column . ' IN (' . implode( ',', $values ) . ')';
	}

	public static function start_date( $days ) {
		$today = current_time( 'Y-m-d' );

		return wp_date( 'Y-m-d', strtotime( $today . ' -' . max( 0, $days - 1 ) . ' days' ) );
	}

	public static function detail_cutoff() {
		$days = max( 7, (int) WPHS_Settings::value( 'retention_detail_days' ) );

		return wp_date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . $days . ' days' ) );
	}

	public static function detail_from( $start ) {
		$cutoff = self::detail_cutoff();

		return $start > $cutoff ? $start : $cutoff;
	}

	public static function dashboard( $days, $mode = null ) {
		$days   = max( 1, (int) $days );
		$mode   = null === $mode ? self::requested_mode() : self::valid_mode( $mode );
		$start  = self::start_date( $days );
		$today  = current_time( 'Y-m-d' );
		$detail = self::detail_from( $start );

		$totals = self::totals( $start, $today, $detail, $mode );
		$prev   = self::previous_totals( $start, $days, $mode );

		$sessions = (int) $totals['sessions'];

		return array(
			'days'             => $days,
			'mode'             => $mode,
			'mode_label'       => self::mode_label( $mode ),
			'mode_hint'        => self::mode_hint( $mode ),
			'mode_options'     => self::mode_options(),
			'start'            => $start,
			'today'            => $today,
			'totals'           => $totals,
			'prev'             => $prev,
			'changes'          => array(
				'pageviews' => self::change( $totals['pageviews'], $prev['pageviews'] ),
				'visitors'  => self::change( $totals['visitors'], $prev['visitors'] ),
				'sessions'  => self::change( $totals['sessions'], $prev['sessions'] ),
			),
			'avg_engaged'      => $sessions > 0 ? $totals['engaged'] / $sessions : 0,
			'bounce_rate'      => $sessions > 0 ? ( $totals['bounces'] / $sessions ) * 100 : 0,
			'prev_avg_engaged' => $prev['sessions'] > 0 ? $prev['engaged'] / $prev['sessions'] : 0,
			'prev_bounce_rate' => $prev['sessions'] > 0 ? ( $prev['bounces'] / $prev['sessions'] ) * 100 : 0,
			'series'           => self::series( $start, $days, $today, $mode ),
			'pages'            => self::top_pages( $detail, 10, $mode ),
			'sources'          => self::sources( $detail, 10, $mode ),
			'devices'          => self::devices( $detail, $mode ),
			'events'           => self::events( $detail, 8, $mode ),
			'quality'          => self::quality( $start, $today ),
			'detail_from'      => $detail,
			'detail_limited'   => $start < $detail,
			'has_any'          => self::has_any(),
			'spool_size'       => WPHS_Spool::size(),
			'last_run'         => (int) get_option( 'wphs_last_run', 0 ),
		);
	}

	private static function previous_totals( $start, $days, $mode ) {
		global $wpdb;

		$all   = 'all' === self::valid_mode( $mode );
		$table = WPHS_Schema::table( $all ? 'totals' : 'totals_grade' );
		$where = $all ? '' : self::grade_filter( $mode );

		$end   = wp_date( 'Y-m-d', strtotime( $start . ' -1 day' ) );
		$begin = wp_date( 'Y-m-d', strtotime( $end . ' -' . max( 0, $days - 1 ) . ' days' ) );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(pageviews),0) AS pv, COALESCE(SUM(visitors),0) AS uv, COALESCE(SUM(sessions),0) AS sessions, COALESCE(SUM(bounces),0) AS bounces, COALESCE(SUM(engaged),0) AS engaged FROM {$table} WHERE day BETWEEN %s AND %s{$where}",
				$begin,
				$end
			),
			ARRAY_A
		);

		return array(
			'pageviews' => $row ? (int) $row['pv'] : 0,
			'visitors'  => $row ? (int) $row['uv'] : 0,
			'sessions'  => $row ? (int) $row['sessions'] : 0,
			'bounces'   => $row ? (int) $row['bounces'] : 0,
			'engaged'   => $row ? (int) $row['engaged'] : 0,
		);
	}

	private static function change( $current, $previous ) {
		if ( $previous <= 0 ) {
			return null;
		}

		return ( ( $current - $previous ) / $previous ) * 100;
	}

	private static function totals( $start, $today, $detail, $mode ) {
		global $wpdb;

		$all   = 'all' === self::valid_mode( $mode );
		$table = WPHS_Schema::table( $all ? 'totals' : 'totals_grade' );
		$where = $all ? '' : self::grade_filter( $mode );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(pageviews),0) AS pv, COALESCE(SUM(visitors),0) AS uv, COALESCE(SUM(sessions),0) AS sessions, COALESCE(SUM(bounces),0) AS bounces, COALESCE(SUM(engaged),0) AS engaged FROM {$table} WHERE day >= %s{$where}",
				$start
			),
			ARRAY_A
		);

		$out = array(
			'pageviews'   => $row ? (int) $row['pv'] : 0,
			'visitors'    => $row ? (int) $row['uv'] : 0,
			'sessions'    => $row ? (int) $row['sessions'] : 0,
			'bounces'     => $row ? (int) $row['bounces'] : 0,
			'engaged'     => $row ? (int) $row['engaged'] : 0,
			'uv_approx'   => true,
			'uv_accurate' => false,
		);

		if ( $start >= $detail ) {
			$seen_day = WPHS_Schema::table( $all ? 'seen_day' : 'seen_day_grade' );
			$unique   = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT visitor_hash) FROM {$seen_day} WHERE day >= %s{$where}",
					$start
				)
			);

			$out['visitors']    = $unique;
			$out['uv_approx']   = false;
			$out['uv_accurate'] = true;
		}

		return $out;
	}

	private static function series( $start, $days, $today, $mode ) {
		global $wpdb;

		$all   = 'all' === self::valid_mode( $mode );
		$table = WPHS_Schema::table( $all ? 'totals' : 'totals_grade' );
		$where = $all ? '' : self::grade_filter( $mode );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day,SUM(pageviews) AS pageviews,SUM(visitors) AS visitors,SUM(sessions) AS sessions,SUM(engaged) AS engaged,SUM(bounces) AS bounces FROM {$table} WHERE day >= %s{$where} GROUP BY day ORDER BY day ASC",
				$start
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ $row['day'] ] = $row;
		}

		$out = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day = wp_date( 'Y-m-d', strtotime( $today . ' -' . $i . ' days' ) );
			$row = isset( $map[ $day ] ) ? $map[ $day ] : array();

			$out[] = array(
				'day'       => $day,
				'pageviews' => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
				'visitors'  => isset( $row['visitors'] ) ? (int) $row['visitors'] : 0,
				'sessions'  => isset( $row['sessions'] ) ? (int) $row['sessions'] : 0,
				'engaged'   => isset( $row['engaged'] ) ? (int) $row['engaged'] : 0,
				'bounces'   => isset( $row['bounces'] ) ? (int) $row['bounces'] : 0,
			);
		}

		return $out;
	}

	private static function top_pages( $from, $limit, $mode ) {
		global $wpdb;

		$daily = WPHS_Schema::table( 'daily' );
		$dims  = WPHS_Schema::table( 'dims' );
		$pages = WPHS_Schema::table( 'pages' );
		$where = self::grade_filter( $mode, 'dm.grade' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id AS page_id,p.path,p.title,SUM(d.pageviews) AS pv,SUM(d.engaged) AS engaged FROM {$daily} d INNER JOIN {$dims} dm ON dm.id=d.dim_id INNER JOIN {$pages} p ON p.id=dm.page_id WHERE d.day >= %s{$where} GROUP BY dm.page_id,p.id,p.path,p.title ORDER BY pv DESC LIMIT %d",
				$from,
				$limit
			),
			ARRAY_A
		);

		$uv = self::page_uv( $from, $mode );

		foreach ( $rows as &$row ) {
			$page_id   = (int) $row['page_id'];
			$pageviews = (int) $row['pv'];

			$row['uv']  = isset( $uv[ $page_id ] ) ? (int) $uv[ $page_id ] : 0;
			$row['avg'] = $pageviews > 0 ? ( (int) $row['engaged'] / $pageviews ) : 0;
		}
		unset( $row );

		return $rows;
	}

	private static function sources( $from, $limit, $mode ) {
		global $wpdb;

		$daily = WPHS_Schema::table( 'daily' );
		$dims  = WPHS_Schema::table( 'dims' );
		$where = self::grade_filter( $mode, 'dm.grade' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT dm.referrer_host AS host,dm.channel AS channel,SUM(d.pageviews) AS pv FROM {$daily} d INNER JOIN {$dims} dm ON dm.id=d.dim_id WHERE d.day >= %s AND dm.channel <> 'internal'{$where} GROUP BY dm.referrer_host,dm.channel ORDER BY pv DESC LIMIT %d",
				$from,
				$limit
			),
			ARRAY_A
		);

		$uv = self::source_uv( $from, $mode );

		foreach ( $rows as &$row ) {
			$key       = $row['host'] . '|' . $row['channel'];
			$row['uv'] = isset( $uv[ $key ] ) ? (int) $uv[ $key ] : 0;
		}
		unset( $row );

		return $rows;
	}

	private static function devices( $from, $mode ) {
		global $wpdb;

		$daily = WPHS_Schema::table( 'daily' );
		$dims  = WPHS_Schema::table( 'dims' );
		$where = self::grade_filter( $mode, 'dm.grade' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT dm.device AS device,SUM(d.pageviews) AS pv FROM {$daily} d INNER JOIN {$dims} dm ON dm.id=d.dim_id WHERE d.day >= %s{$where} GROUP BY dm.device ORDER BY pv DESC",
				$from
			),
			ARRAY_A
		);

		$uv = self::device_uv( $from, $mode );

		foreach ( $rows as &$row ) {
			$row['uv'] = isset( $uv[ $row['device'] ] ) ? (int) $uv[ $row['device'] ] : 0;
		}
		unset( $row );

		return $rows;
	}

	private static function page_uv( $from, $mode ) {
		global $wpdb;

		$seen  = WPHS_Schema::table( 'seen' );
		$dims  = WPHS_Schema::table( 'dims' );
		$where = self::grade_filter( $mode, 'dm.grade' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT dm.page_id AS page_id,COUNT(DISTINCT s.visitor_hash) AS uv FROM {$seen} s INNER JOIN {$dims} dm ON dm.id=s.dim_id WHERE s.day >= %s{$where} GROUP BY dm.page_id",
				$from
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['page_id'] ] = (int) $row['uv'];
		}

		return $out;
	}

	private static function source_uv( $from, $mode ) {
		global $wpdb;

		$seen  = WPHS_Schema::table( 'seen' );
		$dims  = WPHS_Schema::table( 'dims' );
		$where = self::grade_filter( $mode, 'dm.grade' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT dm.referrer_host AS host,dm.channel AS channel,COUNT(DISTINCT s.visitor_hash) AS uv FROM {$seen} s INNER JOIN {$dims} dm ON dm.id=s.dim_id WHERE s.day >= %s AND dm.channel <> 'internal'{$where} GROUP BY dm.referrer_host,dm.channel",
				$from
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['host'] . '|' . $row['channel'] ] = (int) $row['uv'];
		}

		return $out;
	}

	private static function device_uv( $from, $mode ) {
		global $wpdb;

		$seen  = WPHS_Schema::table( 'seen' );
		$dims  = WPHS_Schema::table( 'dims' );
		$where = self::grade_filter( $mode, 'dm.grade' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT dm.device AS device,COUNT(DISTINCT s.visitor_hash) AS uv FROM {$seen} s INNER JOIN {$dims} dm ON dm.id=s.dim_id WHERE s.day >= %s{$where} GROUP BY dm.device",
				$from
			),
			ARRAY_A
		);

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row['device'] ] = (int) $row['uv'];
		}

		return $out;
	}

	private static function events( $from, $limit, $mode ) {
		global $wpdb;

		$table = WPHS_Schema::table( 'events' );
		$dims  = WPHS_Schema::table( 'dims' );
		$where = self::grade_filter( $mode, 'dm.grade' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.event,SUM(e.count) AS total FROM {$table} e INNER JOIN {$dims} dm ON dm.id=e.dim_id WHERE e.day >= %s{$where} GROUP BY e.event ORDER BY total DESC LIMIT %d",
				$from,
				$limit
			),
			ARRAY_A
		);
	}

	private static function quality( $start, $today ) {
		global $wpdb;

		$table = WPHS_Schema::table( 'totals_grade' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT grade,SUM(pageviews) AS pv,SUM(visitors) AS uv,SUM(sessions) AS sessions,SUM(engaged) AS engaged FROM {$table} WHERE day >= %s AND day <= %s GROUP BY grade",
				$start,
				$today
			),
			ARRAY_A
		);

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row['grade'] ] = $row;
		}

		$definitions = array(
			array( 'key' => 'verified', 'grade' => 'v', 'label' => __( 'Verified', 'hub-pilot' ), 'tone' => 'is-verified' ),
			array( 'key' => 'nojs', 'grade' => 'u', 'label' => __( 'No-JS', 'hub-pilot' ), 'tone' => 'is-nojs' ),
			array( 'key' => 'suspect', 'grade' => 's', 'label' => __( 'Suspect', 'hub-pilot' ), 'tone' => 'is-suspect' ),
			array( 'key' => 'datacenter', 'grade' => 'd', 'label' => __( 'Datacenter', 'hub-pilot' ), 'tone' => 'is-datacenter' ),
			array( 'key' => 'legacy', 'grade' => '', 'label' => __( 'Before breakdown', 'hub-pilot' ), 'tone' => 'is-legacy' ),
		);

		$total = 0;

		foreach ( $map as $row ) {
			$total += (int) $row['pv'];
		}

		$groups = array();

		foreach ( $definitions as $definition ) {
			$row = isset( $map[ $definition['grade'] ] ) ? $map[ $definition['grade'] ] : null;
			$pv  = $row ? (int) $row['pv'] : 0;

			$groups[] = array(
				'key'       => $definition['key'],
				'grade'     => $definition['grade'],
				'label'     => $definition['label'],
				'tone'      => $definition['tone'],
				'pageviews' => $pv,
				'visitors'  => $row ? (int) $row['uv'] : 0,
				'sessions'  => $row ? (int) $row['sessions'] : 0,
				'engaged'   => $row ? (int) $row['engaged'] : 0,
				'share'     => $total > 0 ? ( $pv / $total ) * 100 : 0,
			);
		}

		return array(
			'groups'            => $groups,
			'total_pageviews'   => $total,
			'verified_pageviews'=> isset( $map['v'] ) ? (int) $map['v']['pv'] : 0,
			'verified_share'    => $total > 0 && isset( $map['v'] ) ? ( (int) $map['v']['pv'] / $total ) * 100 : 0,
			'has_data'          => $total > 0,
		);
	}

	private static function has_any() {
		global $wpdb;

		$totals       = WPHS_Schema::table( 'totals' );
		$totals_grade = WPHS_Schema::table( 'totals_grade' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$totals}" ) > 0
			|| (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$totals_grade}" ) > 0;
	}

	public static function int( $value ) {
		return number_format_i18n( (int) $value );
	}

	public static function day_label( $day ) {
		$day = (string) $day;

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? str_replace( '-', '/', $day ) : $day;
	}

	public static function percent( $part, $whole ) {
		if ( $whole <= 0 ) {
			return '0%';
		}

		return number_format_i18n( ( $part / $whole ) * 100, 0 ) . '%';
	}

	public static function duration( $seconds ) {
		$seconds = max( 0, (int) round( $seconds ) );

		if ( $seconds < 60 ) {
			return sprintf(
				/* translators: %s: number of seconds. */
				_n( '%s second', '%s seconds', $seconds, 'hub-pilot' ),
				number_format_i18n( $seconds )
			);
		}

		$minutes = (int) floor( $seconds / 60 );
		$rest    = $seconds % 60;

		if ( 0 === $rest ) {
			return sprintf(
				/* translators: %s: number of minutes. */
				_n( '%s minute', '%s minutes', $minutes, 'hub-pilot' ),
				number_format_i18n( $minutes )
			);
		}

		return sprintf(
			/* translators: %1$s: minutes; %2$s: seconds. */
			__( '%1$s min %2$s sec', 'hub-pilot' ),
			number_format_i18n( $minutes ),
			number_format_i18n( $rest )
		);
	}

	public static function channel_label( $channel ) {
		$labels = array(
			'direct'   => __( 'Direct', 'hub-pilot' ),
			'search'   => __( 'Search', 'hub-pilot' ),
			'social'   => __( 'Social', 'hub-pilot' ),
			'referral' => __( 'Referral', 'hub-pilot' ),
			'paid'     => __( 'Paid', 'hub-pilot' ),
			'email'    => __( 'Email', 'hub-pilot' ),
			'campaign' => __( 'Campaign', 'hub-pilot' ),
			'internal' => __( 'Internal', 'hub-pilot' ),
		);

		return isset( $labels[ $channel ] ) ? $labels[ $channel ] : $channel;
	}

	public static function device_label( $device ) {
		$labels = array(
			'desktop' => __( 'Desktop', 'hub-pilot' ),
			'mobile'  => __( 'Mobile', 'hub-pilot' ),
			'tablet'  => __( 'Tablet', 'hub-pilot' ),
		);

		return isset( $labels[ $device ] ) ? $labels[ $device ] : $device;
	}

	public static function event_label( $event ) {
		$labels = array(
			'outbound'   => __( 'Outbound click', 'hub-pilot' ),
			'download'   => __( 'File download', 'hub-pilot' ),
			'form'       => __( 'Form submission', 'hub-pilot' ),
			'cart'       => __( 'Add to cart', 'hub-pilot' ),
			'scroll_25'  => __( 'Scroll 25%', 'hub-pilot' ),
			'scroll_50'  => __( 'Scroll 50%', 'hub-pilot' ),
			'scroll_75'  => __( 'Scroll 75%', 'hub-pilot' ),
			'scroll_100' => __( 'Scroll 100%', 'hub-pilot' ),
		);

		return isset( $labels[ $event ] ) ? $labels[ $event ] : $event;
	}
}
