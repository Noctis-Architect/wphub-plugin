<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables and the file-based spool require direct queries and file I/O; every table identifier is a fixed internal wphs_* name, never user input.

final class WPHS_Aggregator {

	const EVENT          = 'wphs_aggregate';
	const FINALIZE_DELAY = 600;

	private static $lock    = null;
	private static $pages   = array();
	private static $dims    = array();
	private static $running = false;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::EVENT, array( __CLASS__, 'run' ) );
		add_action( 'admin_post_wphs_flush', array( __CLASS__, 'manual_flush' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedules( $schedules ) {
		$schedules['wphs_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'HubPilot (5 min)',
		);

		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + 120, 'wphs_five_minutes', self::EVENT );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::EVENT );
	}

	public static function run() {
		if ( 'cloud' === WPHS_Settings::mode() ) {
			return;
		}

		if ( ! self::lock() ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Extend the execution time for queue processing.
			@set_time_limit( 120 );
		}

		self::$pages = array();
		self::$dims  = array();

		try {
			self::process_spool();
			self::finalize_hits();
			self::maybe_cleanup();
			update_option( 'wphs_last_run', time(), false );
		} finally {
			self::unlock();
		}
	}

	private static function lock() {
		$dir = WPHS_Spool::dir();

		if ( ! is_dir( $dir ) && ! WPHS_Spool::ensure_dir() ) {
			return false;
		}

		$path   = $dir . '/.lock';
		$handle = file_exists( $path ) ? @fopen( $path, 'r' ) : @fopen( $path, 'c' );

		if ( ! $handle ) {
			return false;
		}

		@chmod( $path, 0666 );

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return false;
		}

		self::$lock = $handle;

		return true;
	}

	private static function unlock() {
		if ( self::$lock ) {
			flock( self::$lock, LOCK_UN );
			fclose( self::$lock );
			self::$lock = null;
		}
	}

	private static function process_spool() {
		foreach ( WPHS_Spool::files() as $file ) {
			$target = $file;

			if ( '.processing' !== substr( $file, -11 ) ) {
				$target = WPHS_Spool::claim( $file );
				if ( ! $target ) {
					continue;
				}
			}

			$handle = @fopen( $target, 'rb' );
			if ( ! $handle ) {
				continue;
			}

			while ( ( $line = fgets( $handle ) ) !== false ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}

				$event = json_decode( $line, true );
				if ( ! is_array( $event ) || empty( $event['k'] ) ) {
					continue;
				}

				if ( 'pv' === $event['k'] ) {
					self::handle_pageview( $event );
				} elseif ( 'eng' === $event['k'] ) {
					self::handle_engagement( $event );
				}
			}

			fclose( $handle );
			WPHS_Spool::drop( $target );
		}
	}

	private static function handle_pageview( array $event ) {
		global $wpdb;

		$hit    = isset( $event['h'] ) ? strtolower( (string) $event['h'] ) : '';
		$client = isset( $event['c'] ) ? strtolower( (string) $event['c'] ) : '';

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $hit ) ) {
			return;
		}

		$is_js = (bool) preg_match( '/^[a-f0-9]{32}$/', $client );
		$hits  = WPHS_Schema::table( 'hits' );
		$now   = time();
		$stamp = isset( $event['t'] ) ? absint( $event['t'] ) : $now;
		$stamp = max( 1, min( $now + 300, $stamp ) );

		if ( ! $is_js ) {
			$session = self::hash_value( isset( $event['s'] ) ? $event['s'] : '' );
			$visitor = self::hash_value( isset( $event['v'] ) ? $event['v'] : '' );
			$grade   = self::valid_grade( isset( $event['g'] ) ? $event['g'] : '', 'u' );

			if ( ! $session || ! $visitor ) {
				return;
			}

			list( $dim_id, $day, $hour ) = self::resolve( $event, $grade );

			if ( ! $dim_id || ! $day || ! $hour ) {
				return;
			}

			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$hits} (hit_id,client_id,session_hash,visitor_hash,dim_id,day,hour,source,counted,eng_seq,grade,created) VALUES (%s,'',%s,%s,%d,%s,%s,'server',0,0,%s,%d)",
					$hit,
					$session,
					$visitor,
					$dim_id,
					$day,
					$hour,
					$grade,
					$stamp
				)
			);

			return;
		}

		$grade = self::valid_grade( isset( $event['g'] ) ? $event['g'] : '', 'v' );

		// The server hit was stored before the browser verdict was known, so
		// re-resolve its dimension row with the final grade.
		list( $dim_id ) = self::resolve( $event, $grade );

		if ( ! $dim_id ) {
			return;
		}

		$attached = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$hits} SET client_id=%s, source='js', counted=1, grade=%s, dim_id=%d WHERE hit_id=%s AND client_id='' AND counted=0 AND created >= %d",
				$client,
				$grade,
				$dim_id,
				$hit,
				$stamp - 600
			)
		);

		if ( 1 === $attached ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT dim_id,day,hour,session_hash,visitor_hash,created FROM {$hits} WHERE hit_id=%s",
					$hit
				)
			);

			if ( $row ) {
				self::count_pageview( $dim_id, $row->day, $row->hour, $row->visitor_hash, $row->session_hash, (int) $row->created, $hit, $grade );
			}

			return;
		}

		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$hits} WHERE client_id=%s", $client ) );

		if ( $exists ) {
			return;
		}

		$session = self::hash_value( isset( $event['s'] ) ? $event['s'] : '' );
		$visitor = self::hash_value( isset( $event['v'] ) ? $event['v'] : '' );

		if ( ! $session || ! $visitor ) {
			return;
		}

		list( $dim_id, $day, $hour ) = self::resolve( $event, $grade );

		if ( ! $dim_id || ! $day || ! $hour ) {
			return;
		}

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$hits} (hit_id,client_id,session_hash,visitor_hash,dim_id,day,hour,source,counted,eng_seq,grade,created) VALUES (%s,%s,%s,%s,%d,%s,%s,'js',1,0,%s,%d)",
				$client,
				$client,
				$session,
				$visitor,
				$dim_id,
				$day,
				$hour,
				$grade,
				$stamp
			)
		);

		if ( 1 === $inserted ) {
			self::count_pageview( $dim_id, $day, $hour, $visitor, $session, $stamp, $client, $grade );
		}
	}

	private static function count_pageview( $dim_id, $day, $hour, $visitor, $session, $ts = 0, $hit_id = '', $grade = 'u' ) {
		global $wpdb;

		$visitor = self::hash_value( $visitor );
		$grade   = self::valid_grade( $grade, 'u' );

		if ( ! $dim_id || ! $visitor ) {
			return;
		}

		$ts = $ts > 0 ? (int) $ts : time();
		$ts = max( 1, min( time() + 300, $ts ) );

		$session = self::resolve_session( $visitor, $grade, $ts );

		if ( ! self::hash_value( $session ) ) {
			$session = WPHS_Collector::random_id();
		}

		$seen            = WPHS_Schema::table( 'seen' );
		$seen_day        = WPHS_Schema::table( 'seen_day' );
		$seen_day_grade  = WPHS_Schema::table( 'seen_day_grade' );
		$daily           = WPHS_Schema::table( 'daily' );
		$hourly          = WPHS_Schema::table( 'hourly' );
		$totals          = WPHS_Schema::table( 'totals' );
		$totals_grade    = WPHS_Schema::table( 'totals_grade' );
		$sessions        = WPHS_Schema::table( 'sessions' );
		$hits            = WPHS_Schema::table( 'hits' );

		$new_dim_visitor = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$seen} (day,dim_id,visitor_hash) VALUES (%s,%d,%s)",
				$day,
				$dim_id,
				$visitor
			)
		);

		$new_day_visitor = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$seen_day} (day,visitor_hash) VALUES (%s,%s)",
				$day,
				$visitor
			)
		);

		$new_grade_visitor = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$seen_day_grade} (day,grade,visitor_hash) VALUES (%s,%s,%s)",
				$day,
				$grade,
				$visitor
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$daily} (day,dim_id,pageviews,visitors,engaged) VALUES (%s,%d,1,%d,0) ON DUPLICATE KEY UPDATE pageviews=pageviews+1, visitors=visitors+VALUES(visitors)",
				$day,
				$dim_id,
				$new_dim_visitor
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$hourly} (hour,dim_id,pageviews,visitors,engaged) VALUES (%s,%d,1,%d,0) ON DUPLICATE KEY UPDATE pageviews=pageviews+1, visitors=visitors+VALUES(visitors)",
				$hour,
				$dim_id,
				$new_dim_visitor
			)
		);

		$new_sess = (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$sessions} (session_hash,visitor_hash,day,dim_id,pageviews,engaged,is_bounce,first_ts,last_ts,grade) VALUES (%s,%s,%s,%d,1,0,1,%d,%d,%s)",
				$session,
				$visitor,
				$day,
				$dim_id,
				$ts,
				$ts,
				$grade
			)
		);

		$bounce_delta = 0;

		if ( $new_sess ) {
			$bounce_delta = 1;
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT day,pageviews,is_bounce FROM {$sessions} WHERE session_hash=%s",
					$session
				)
			);

			$pageviews_after = $row ? ( (int) $row->pageviews + 1 ) : 1;
			$is_bounce       = $row ? (int) $row->is_bounce : 0;

			if ( $is_bounce && $pageviews_after >= 2 ) {
				$is_bounce = 0;
				self::remove_bounce( $row->day, $grade );
			}

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$sessions} SET pageviews=pageviews+1, is_bounce=%d, last_ts=GREATEST(last_ts,%d) WHERE session_hash=%s",
					$is_bounce,
					$ts,
					$session
				)
			);
		}

		if ( $hit_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$hits} SET session_hash=%s WHERE hit_id=%s",
					$session,
					$hit_id
				)
			);
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$totals} (day,pageviews,visitors,sessions,bounces,engaged) VALUES (%s,1,%d,%d,%d,0) ON DUPLICATE KEY UPDATE pageviews=pageviews+1, visitors=visitors+VALUES(visitors), sessions=sessions+VALUES(sessions), bounces=GREATEST(0,bounces+VALUES(bounces))",
				$day,
				$new_day_visitor,
				$new_sess,
				$bounce_delta
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$totals_grade} (day,grade,pageviews,visitors,sessions,bounces,engaged) VALUES (%s,%s,1,%d,%d,%d,0) ON DUPLICATE KEY UPDATE pageviews=pageviews+1, visitors=visitors+VALUES(visitors), sessions=sessions+VALUES(sessions), bounces=GREATEST(0,bounces+VALUES(bounces))",
				$day,
				$grade,
				$new_grade_visitor,
				$new_sess,
				$bounce_delta
			)
		);
	}

	private static function resolve_session( $visitor, $grade, $ts ) {
		global $wpdb;

		$sessions = WPHS_Schema::table( 'sessions' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT session_hash FROM {$sessions} WHERE visitor_hash=%s AND grade=%s AND last_ts >= %d ORDER BY last_ts DESC LIMIT 1",
				$visitor,
				$grade,
				$ts - 1800
			)
		);

		if ( $row && self::hash_value( $row->session_hash ) ) {
			return $row->session_hash;
		}

		return WPHS_Collector::random_id();
	}

	private static function remove_bounce( $day, $grade = 'u' ) {
		global $wpdb;

		$totals       = WPHS_Schema::table( 'totals' );
		$totals_grade = WPHS_Schema::table( 'totals_grade' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$totals} SET bounces=GREATEST(0,bounces-1) WHERE day=%s",
				$day
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$totals_grade} SET bounces=GREATEST(0,bounces-1) WHERE day=%s AND grade=%s",
				$day,
				self::valid_grade( $grade, 'u' )
			)
		);
	}

	private static function handle_engagement( array $event ) {
		global $wpdb;

		$client = isset( $event['c'] ) ? strtolower( (string) $event['c'] ) : '';

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $client ) ) {
			return;
		}

		$hits = WPHS_Schema::table( 'hits' );

		$hit = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT hit_id,dim_id,day,hour,session_hash,created,eng_seq,grade FROM {$hits} WHERE client_id=%s LIMIT 1",
				$client
			)
		);

		if ( ! $hit ) {
			return;
		}

		if ( (int) $hit->created < time() - ( 7 * DAY_IN_SECONDS ) ) {
			return;
		}

		$seq = isset( $event['sq'] ) ? absint( $event['sq'] ) : 0;

		if ( $seq > 0 ) {
			$applied = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$hits} SET eng_seq=%d WHERE hit_id=%s AND eng_seq < %d",
					$seq,
					$hit->hit_id,
					$seq
				)
			);

			if ( 1 !== $applied ) {
				return;
			}
		}

		$seconds = isset( $event['sec'] ) ? min( 300, absint( $event['sec'] ) ) : 0;
		$scroll  = array();

		if ( isset( $event['sc'] ) && is_array( $event['sc'] ) ) {
			foreach ( $event['sc'] as $mark ) {
				$mark = absint( $mark );
				if ( in_array( $mark, array( 25, 50, 75, 100 ), true ) ) {
					$scroll[ 'scroll_' . $mark ] = 1;
				}
			}
		}

		$events = array();

		if ( isset( $event['ev'] ) && is_array( $event['ev'] ) ) {
			foreach ( array( 'outbound', 'download', 'form', 'cart' ) as $name ) {
				if ( ! empty( $event['ev'][ $name ] ) ) {
					$events[ $name ] = min( 50, absint( $event['ev'][ $name ] ) );
				}
			}
		}

		if ( $seconds > 0 ) {
			self::add_engaged( (int) $hit->dim_id, $hit->day, $hit->hour, $hit->session_hash, $seconds, (string) $hit->grade );
		}

		$counts = array_merge( $scroll, $events );

		foreach ( $counts as $name => $count ) {
			self::add_event( $hit->day, (int) $hit->dim_id, $name, (int) $count );
		}
	}

	private static function add_engaged( $dim_id, $day, $hour, $session, $seconds, $grade = 'u' ) {
		global $wpdb;

		$grade        = self::valid_grade( $grade, 'u' );
		$daily        = WPHS_Schema::table( 'daily' );
		$hourly       = WPHS_Schema::table( 'hourly' );
		$totals       = WPHS_Schema::table( 'totals' );
		$totals_grade = WPHS_Schema::table( 'totals_grade' );
		$sessions     = WPHS_Schema::table( 'sessions' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$daily} (day,dim_id,pageviews,visitors,engaged) VALUES (%s,%d,0,0,%d) ON DUPLICATE KEY UPDATE engaged=engaged+VALUES(engaged)",
				$day,
				$dim_id,
				$seconds
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$hourly} (hour,dim_id,pageviews,visitors,engaged) VALUES (%s,%d,0,0,%d) ON DUPLICATE KEY UPDATE engaged=engaged+VALUES(engaged)",
				$hour,
				$dim_id,
				$seconds
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$totals} (day,pageviews,visitors,sessions,bounces,engaged) VALUES (%s,0,0,0,0,%d) ON DUPLICATE KEY UPDATE engaged=engaged+VALUES(engaged)",
				$day,
				$seconds
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$totals_grade} (day,grade,pageviews,visitors,sessions,bounces,engaged) VALUES (%s,%s,0,0,0,0,%d) ON DUPLICATE KEY UPDATE engaged=engaged+VALUES(engaged)",
				$day,
				$grade,
				$seconds
			)
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT day,pageviews,engaged,is_bounce FROM {$sessions} WHERE session_hash=%s",
				$session
			)
		);

		if ( ! $row ) {
			return;
		}

		$new_engaged = (int) $row->engaged + $seconds;
		$is_bounce   = (int) $row->is_bounce;

		if ( $is_bounce && ( $new_engaged >= 10 || (int) $row->pageviews >= 2 ) ) {
			$is_bounce = 0;
			self::remove_bounce( $row->day, $grade );
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$sessions} SET engaged=engaged+%d, is_bounce=%d, last_ts=GREATEST(last_ts,%d) WHERE session_hash=%s",
				$seconds,
				$is_bounce,
				time(),
				$session
			)
		);
	}

	private static function add_event( $day, $dim_id, $name, $count ) {
		global $wpdb;

		if ( $count < 1 ) {
			return;
		}

		$events = WPHS_Schema::table( 'events' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$events} (day,dim_id,event,count) VALUES (%s,%d,%s,%d) ON DUPLICATE KEY UPDATE count=count+VALUES(count)",
				$day,
				$dim_id,
				substr( $name, 0, 32 ),
				$count
			)
		);
	}

	private static function resolve( array $event, $grade = 'u' ) {
		global $wpdb;

		$grade = self::valid_grade( $grade, 'u' );
		$ts    = isset( $event['t'] ) ? absint( $event['t'] ) : time();
		$ts    = max( 1, min( time() + 300, $ts ) );

		$day  = wp_date( 'Y-m-d', $ts );
		$hour = wp_date( 'Y-m-d H:00:00', $ts );

		$path  = WPHS_Collector::normalize_path( isset( $event['u'] ) ? $event['u'] : '/' );
		$title = isset( $event['ti'] ) ? substr( sanitize_text_field( wp_strip_all_tags( (string) $event['ti'] ) ), 0, 120 ) : '';
		$pid   = isset( $event['pid'] ) ? absint( $event['pid'] ) : 0;

		$pages = WPHS_Schema::table( 'pages' );
		$key   = md5( $path );

		if ( isset( self::$pages[ $key ] ) ) {
			$page_id    = (int) self::$pages[ $key ]['id'];
			$page_title = (string) self::$pages[ $key ]['title'];

			if ( '' === $page_title && '' !== $title ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$pages} SET title=%s WHERE id=%d",
						$title,
						$page_id
					)
				);

				self::$pages[ $key ]['title'] = $title;
			}
		} else {
			$page = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id,title FROM {$pages} WHERE page_key=%s",
					$key
				)
			);

			if ( $page ) {
				$page_id    = (int) $page->id;
				$page_title = (string) $page->title;

				if ( '' === $page_title && '' !== $title ) {
					$wpdb->query(
						$wpdb->prepare(
							"UPDATE {$pages} SET title=%s WHERE id=%d",
							$title,
							$page_id
						)
					);

					$page_title = $title;
				}
			} else {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$pages} (page_key,path,title,post_id) VALUES (%s,%s,%s,%d)",
						$key,
						$path,
						$title,
						$pid
					)
				);

				$page_id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pages} WHERE page_key=%s", $key ) );
				$page_title = $title;
			}

			self::$pages[ $key ] = array(
				'id'    => $page_id,
				'title' => $page_title,
			);
		}

		if ( ! $page_id ) {
			return array( 0, '', '' );
		}

		$referrer = strtolower( preg_replace( '/[^a-z0-9.\-]/', '', (string) ( isset( $event['r'] ) ? $event['r'] : '' ) ) );
		$referrer = substr( $referrer, 0, 100 );

		$channel = isset( $event['ch'] ) ? (string) $event['ch'] : 'direct';
		if ( ! in_array( $channel, array( 'direct', 'internal', 'search', 'social', 'referral', 'paid', 'email', 'campaign' ), true ) ) {
			$channel = 'direct';
		}

		$device = isset( $event['d'] ) ? (string) $event['d'] : 'desktop';
		if ( ! in_array( $device, array( 'desktop', 'mobile', 'tablet' ), true ) ) {
			$device = 'desktop';
		}

		$browser = strtolower( preg_replace( '/[^a-z]/', '', (string) ( isset( $event['b'] ) ? $event['b'] : '' ) ) );
		$browser = '' === $browser ? 'other' : substr( $browser, 0, 30 );

		$country = strtoupper( (string) ( isset( $event['cc'] ) ? $event['cc'] : '' ) );
		$country = preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';

		$dim_hash = md5( $page_id . '|' . $referrer . '|' . $channel . '|' . $device . '|' . $browser . '|' . $country . '|' . $grade );

		if ( isset( self::$dims[ $dim_hash ] ) ) {
			$dim_id = self::$dims[ $dim_hash ];
		} else {
			$dims = WPHS_Schema::table( 'dims' );
			$dim_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$dims} WHERE dim_hash=%s", $dim_hash ) );

			if ( ! $dim_id ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$dims} (dim_hash,page_id,referrer_host,channel,device,browser,country,grade) VALUES (%s,%d,%s,%s,%s,%s,%s,%s)",
						$dim_hash,
						$page_id,
						$referrer,
						$channel,
						$device,
						$browser,
						$country,
						$grade
					)
				);

				$dim_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$dims} WHERE dim_hash=%s", $dim_hash ) );
			}

			self::$dims[ $dim_hash ] = $dim_id;
		}

		return array( $dim_id, $day, $hour );
	}

	private static function hash_value( $value ) {
		$value = strtolower( (string) $value );

		return preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : '';
	}

	private static function valid_grade( $value, $fallback = 'u' ) {
		$value = strtolower( (string) $value );

		return in_array( $value, array( 'v', 'u', 's', 'd' ), true ) ? $value : $fallback;
	}

	private static function finalize_hits() {
		global $wpdb;

		$hits = WPHS_Schema::table( 'hits' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT hit_id,dim_id,day,hour,session_hash,visitor_hash,grade FROM {$hits} WHERE source='server' AND counted=0 AND created < %d ORDER BY created ASC LIMIT 500",
				time() - self::FINALIZE_DELAY
			)
		);

		foreach ( (array) $rows as $row ) {
			self::count_pageview( (int) $row->dim_id, $row->day, $row->hour, $row->visitor_hash, $row->session_hash, (int) $row->created, $row->hit_id, (string) $row->grade );

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$hits} SET counted=1 WHERE hit_id=%s AND counted=0",
					$row->hit_id
				)
			);
		}
	}

	private static function maybe_cleanup() {
		$last = get_option( 'wphs_last_cleanup', '' );

		if ( current_time( 'Y-m-d' ) === $last ) {
			return;
		}

		self::cleanup();
		update_option( 'wphs_last_cleanup', current_time( 'Y-m-d' ), false );
	}

	private static function cleanup() {
		global $wpdb;

		$detail_days = max( 7, (int) WPHS_Settings::value( 'retention_detail_days' ) );
		$daily_days  = max( 30, (int) WPHS_Settings::value( 'retention_daily_days' ) );
		$months      = max( 0, (int) WPHS_Settings::value( 'retention_totals_months' ) );

		$now = current_time( 'timestamp' );

		$detail_cutoff = gmdate( 'Y-m-d', strtotime( '-' . $detail_days . ' days', $now ) );
		$daily_cutoff  = gmdate( 'Y-m-d', strtotime( '-' . $daily_days . ' days', $now ) );

		$hourly         = WPHS_Schema::table( 'hourly' );
		$events         = WPHS_Schema::table( 'events' );
		$seen           = WPHS_Schema::table( 'seen' );
		$seen_day       = WPHS_Schema::table( 'seen_day' );
		$seen_day_grade = WPHS_Schema::table( 'seen_day_grade' );
		$sessions       = WPHS_Schema::table( 'sessions' );
		$hits           = WPHS_Schema::table( 'hits' );
		$daily          = WPHS_Schema::table( 'daily' );
		$totals         = WPHS_Schema::table( 'totals' );
		$totals_grade   = WPHS_Schema::table( 'totals_grade' );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$hourly} WHERE hour < %s", $detail_cutoff . ' 00:00:00' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE day < %s", $detail_cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$seen} WHERE day < %s", $detail_cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$seen_day} WHERE day < %s", $detail_cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$seen_day_grade} WHERE day < %s", $detail_cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$sessions} WHERE day < %s", $detail_cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$hits} WHERE created < %d", time() - ( 7 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE day < %s", $daily_cutoff ) );

		if ( $months > 0 ) {
			$totals_cutoff = gmdate( 'Y-m-d', strtotime( '-' . $months . ' months', $now ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$totals} WHERE day < %s", $totals_cutoff ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$totals_grade} WHERE day < %s", $totals_cutoff ) );
		}

		WPHS_Spool::cleanup_old( 3 );
	}

	public static function manual_flush() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hub-pilot' ) );
		}

		check_admin_referer( 'wphs_flush' );

		self::run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'hub-pilot-settings',
					'flushed' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
