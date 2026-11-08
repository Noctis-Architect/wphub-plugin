<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom analytics tables and the file-based spool require direct queries and file I/O; every table identifier is a fixed internal wphs_* name, never user input.

final class WPHS_Cloud {

	const CRON                 = 'wphs_cloud_sync';
	const BATCH_SIZE           = 250;
	const MAX_BATCHES_PER_RUN  = 3;
	const SPOOL_CAP_MB         = 25;

	private static $lock = null;

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 180, 'wphs_five_minutes', self::CRON );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function status() {
		return array(
			'mode'          => WPHS_Settings::mode(),
			'console_url'   => WPHS_Settings::console_url(),
			'token_set'     => '' !== self::token(),
			'pending'       => WPHS_Spool::pending_count(),
			'spool_size'    => WPHS_Spool::size(),
			'files'         => count( WPHS_Spool::files() ),
			'last_attempt'  => (int) get_option( 'wphs_cloud_last_attempt', 0 ),
			'last_success'  => (int) get_option( 'wphs_cloud_last_success', 0 ),
			'last_error'    => (string) get_option( 'wphs_cloud_last_error', '' ),
			'last_count'    => (int) get_option( 'wphs_cloud_last_count', 0 ),
			'last_http'     => (int) get_option( 'wphs_cloud_last_http', 0 ),
			'failures'      => (int) get_option( 'wphs_cloud_failures', 0 ),
			'next_attempt'  => (int) get_option( 'wphs_cloud_next_attempt', 0 ),
			'ping_at'       => (int) get_option( 'wphs_cloud_ping_at', 0 ),
			'ping_ok'       => (int) get_option( 'wphs_cloud_ping_ok', 0 ),
			'ping_message'  => (string) get_option( 'wphs_cloud_ping_message', '' ),
			'cron_next'     => (int) wp_next_scheduled( self::CRON ),
			'local_rows'    => self::local_rows(),
		);
	}

	public static function has_token() {
		return '' !== self::token();
	}

	public static function overview( $force = false ) {
		$token = self::token();

		if ( '' === $token ) {
			return array(
				'ok'      => false,
				'data'    => null,
				'message' => 'no_token',
				'stale'   => false,
				'http'    => 0,
			);
		}

		if ( ! $force ) {
			$cached = get_transient( 'wphs_site_overview' );

			if ( is_array( $cached ) ) {
				return array(
					'ok'      => true,
					'data'    => $cached,
					'message' => 'cached',
					'stale'   => false,
					'http'    => 200,
				);
			}
		}

		$response = wp_remote_get(
			self::endpoint( 'api/v1/site/overview' ),
			array(
				'timeout'     => 4,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
					'User-Agent'    => 'WP-Hub-Analytics/' . WPHS_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::overview_fallback( substr( $response->get_error_message(), 0, 300 ), 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 && is_array( $data ) && ( ! isset( $data['success'] ) || $data['success'] ) ) {
			$payload = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;

			unset( $payload['success'] );

			set_transient( 'wphs_site_overview', $payload, 10 * MINUTE_IN_SECONDS );
			update_option(
				'wphs_site_overview_last',
				array(
					'at'   => time(),
					'data' => $payload,
				),
				false
			);
			update_option( 'wphs_site_overview_at', time(), false );

			return array(
				'ok'      => true,
				'data'    => $payload,
				'message' => 'ok',
				'stale'   => false,
				'http'    => $code,
			);
		}

		/* translators: %d: HTTP status code. */
		$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : sprintf( __( 'Could not fetch site status from the console (HTTP %d).', 'hub-pilot' ), $code );

		return self::overview_fallback( substr( wp_strip_all_tags( (string) $message ), 0, 300 ), $code );
	}

	private static function overview_fallback( $message, $http ) {
		$last = get_option( 'wphs_site_overview_last', array() );

		if ( is_array( $last ) && ! empty( $last['data'] ) && is_array( $last['data'] ) ) {
			return array(
				'ok'      => true,
				'data'    => $last['data'],
				'message' => (string) $message,
				'stale'   => true,
				'http'    => (int) $http,
			);
		}

		return array(
			'ok'      => false,
			'data'    => null,
			'message' => (string) $message,
			'stale'   => false,
			'http'    => (int) $http,
		);
	}

	public static function ping() {
		$token = self::token();

		if ( '' === $token ) {
			return array( false, __( 'The site connection key is not set.', 'hub-pilot' ), 0 );
		}

		$response = wp_remote_get(
			self::endpoint( 'api/v1/analytics/ping' ),
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
					'User-Agent'    => 'WP-Hub-Analytics/' . WPHS_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = substr( $response->get_error_message(), 0, 300 );
			self::save_ping( false, $message );

			return array( false, $message, 0 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		$ok   = $code >= 200 && $code < 300;
		/* translators: %d: HTTP status code. */
		$msg  = $ok ? __( 'Connected to the console.', 'hub-pilot' ) : ( is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : sprintf( __( 'Invalid response from the console (HTTP %d)', 'hub-pilot' ), $code ) );
		$msg  = substr( wp_strip_all_tags( (string) $msg ), 0, 300 );

		self::save_ping( $ok, $msg );

		return array( $ok, $msg, $code );
	}

	public static function run( $force = false ) {
		if ( 'cloud' !== WPHS_Settings::mode() ) {
			return self::result( false, 'not_cloud', 0, WPHS_Spool::pending_count(), 0 );
		}

		$token = self::token();

		if ( '' === $token ) {
			return self::result( false, 'no_token', 0, WPHS_Spool::pending_count(), 0 );
		}

		if ( ! $force ) {
			$next = (int) get_option( 'wphs_cloud_next_attempt', 0 );

			if ( $next > time() ) {
				return self::result( false, 'backoff', 0, WPHS_Spool::pending_count(), 0 );
			}
		}

		if ( ! self::lock() ) {
			return self::result( false, 'locked', 0, WPHS_Spool::pending_count(), 0 );
		}

		update_option( 'wphs_cloud_last_attempt', time(), false );
		WPHS_Spool::prune_cloud( self::SPOOL_CAP_MB );

		$sent       = 0;
		$batches    = 0;
		$last_http  = 0;
		$last_error = '';
		$files      = WPHS_Spool::files();

		try {
			foreach ( $files as $file ) {
				if ( $batches >= self::MAX_BATCHES_PER_RUN ) {
					break;
				}

				$target = $file;

				if ( '.processing' !== substr( $file, -11 ) ) {
					$target = WPHS_Spool::claim( $file );

					if ( ! $target ) {
						continue;
					}
				}

				$lines = @file( $target, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

				if ( ! is_array( $lines ) || ! $lines ) {
					WPHS_Spool::drop( $target );
					continue;
				}

				$batch_lines = array_slice( $lines, 0, self::BATCH_SIZE );
				$remaining   = array_slice( $lines, self::BATCH_SIZE );
				$events      = array();

				foreach ( $batch_lines as $line ) {
					$event = json_decode( $line, true );

					if ( ! is_array( $event ) ) {
						continue;
					}

					if ( empty( $event['eid'] ) ) {
						$event['eid'] = substr( md5( $line ), 0, 32 );
					}

					$events[] = $event;
				}

				if ( ! $events ) {
					if ( $remaining ) {
						self::write_remaining( $target, $remaining );
					}

					WPHS_Spool::drop( $target );
					continue;
				}

				$send      = self::send( $events, $token );
				$last_http = (int) $send['http'];

				if ( ! $send['ok'] ) {
					$last_error = (string) $send['message'];
					self::mark_failure( $last_http, $last_error );
					break;
				}

				$sent   += count( $events );
				$batches++;

				$keep = false;

				if ( $remaining ) {
					$keep = ! self::write_remaining( $target, $remaining );
				}

				if ( ! $keep ) {
					WPHS_Spool::drop( $target );
				}

				self::mark_success( count( $events ), $last_http );
			}
		} finally {
			self::unlock();
		}

		$pending = WPHS_Spool::pending_count();

		if ( $last_error ) {
			return self::result( false, $last_error, $sent, $pending, $last_http );
		}

		if ( $sent > 0 ) {
			return self::result( true, 'sent', $sent, $pending, $last_http );
		}

		return self::result( true, 'empty', 0, $pending, 0 );
	}

	public static function purge_local_data() {
		global $wpdb;

		$tables = array( 'pages', 'dims', 'daily', 'hourly', 'totals', 'totals_grade', 'events', 'seen', 'seen_day', 'seen_day_grade', 'sessions', 'hits' );

		foreach ( $tables as $table ) {
			$wpdb->query( 'TRUNCATE TABLE ' . WPHS_Schema::table( $table ) );
		}

		delete_option( 'wphs_last_run' );
		delete_option( 'wphs_last_cleanup' );
	}

	private static function send( array $events, $token ) {
		$site_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$payload = array(
			'schema'         => 1,
			'batch_id'       => wp_generate_uuid4(),
			'site'           => home_url( '/' ),
			'site_host'      => $site_host,
			'plugin_version' => WPHS_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'timezone'       => wp_timezone_string(),
			'sent_at'        => time(),
			'events'         => array_values( $events ),
		);

		$json = wp_json_encode( $payload );

		if ( ! is_string( $json ) || '' === $json ) {
			return array( 'ok' => false, 'http' => 0, 'message' => 'payload_encode_failed' );
		}

		$body      = function_exists( 'gzencode' ) ? gzencode( $json, 6 ) : false;
		$compressed = is_string( $body ) && '' !== $body;

		if ( ! $compressed ) {
			$body = $json;
		}

		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $token );

		$response = wp_remote_post(
			self::endpoint( 'api/v1/analytics/ingest' ),
			array(
				'timeout'     => 12,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Authorization'    => 'Bearer ' . $token,
					'Accept'           => 'application/json',
					'Content-Type'     => 'application/json',
					'Content-Encoding' => $compressed ? 'gzip' : 'identity',
					'X-WPHS-Timestamp' => $timestamp,
					'X-WPHS-Signature' => 'sha256=' . $signature,
					'X-WPHS-Site'      => $site_host,
					'User-Agent'       => 'WP-Hub-Analytics/' . WPHS_VERSION,
				),
				'body'        => $body,
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'http' => 0, 'message' => substr( $response->get_error_message(), 0, 300 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 ) {
			return array( 'ok' => true, 'http' => $code, 'message' => 'ok' );
		}

		$message = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : wp_strip_all_tags( $raw );

		return array( 'ok' => false, 'http' => $code, 'message' => substr( (string) $message, 0, 300 ) );
	}

	private static function mark_success( $count, $http ) {
		update_option( 'wphs_cloud_last_success', time(), false );
		update_option( 'wphs_cloud_failures', 0, false );
		update_option( 'wphs_cloud_next_attempt', 0, false );
		update_option( 'wphs_cloud_last_error', '', false );
		update_option( 'wphs_cloud_last_count', (int) $count, false );
		update_option( 'wphs_cloud_last_http', (int) $http, false );
	}

	private static function mark_failure( $http, $message ) {
		$failures = min( 10, (int) get_option( 'wphs_cloud_failures', 0 ) + 1 );
		$delay    = min( 3600, 60 * ( 2 ** min( 5, $failures - 1 ) ) );

		if ( 401 === $http || 403 === $http ) {
			$delay = 3600;
		} elseif ( 429 === $http ) {
			$delay = 900;
		}

		update_option( 'wphs_cloud_failures', $failures, false );
		update_option( 'wphs_cloud_next_attempt', time() + $delay, false );
		update_option( 'wphs_cloud_last_error', substr( (string) $message, 0, 300 ), false );
		update_option( 'wphs_cloud_last_http', (int) $http, false );
	}

	private static function save_ping( $ok, $message ) {
		update_option( 'wphs_cloud_ping_at', time(), false );
		update_option( 'wphs_cloud_ping_ok', $ok ? 1 : 0, false );
		update_option( 'wphs_cloud_ping_message', substr( (string) $message, 0, 300 ), false );
	}

	private static function write_remaining( $target, array $lines ) {
		$file = dirname( $target ) . '/spool-' . current_time( 'Ymd' ) . '-rest-' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.log';
		$data = implode( "\n", $lines ) . "\n";

		return false !== @file_put_contents( $file, $data, LOCK_EX );
	}

	private static function lock() {
		$dir = WPHS_Spool::dir();

		if ( ! is_dir( $dir ) && ! WPHS_Spool::ensure_dir() ) {
			return false;
		}

		$path   = $dir . '/.cloud.lock';
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

	private static function endpoint( $path ) {
		return trailingslashit( WPHS_Settings::console_url() ) . ltrim( $path, '/' );
	}

	private static function token() {
		return (string) WPHS_Settings::value( 'console_token' );
	}

	private static function local_rows() {
		global $wpdb;

		$table = WPHS_Schema::table( 'totals' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	private static function result( $ok, $message, $sent, $pending, $http ) {
		return array(
			'ok'      => (bool) $ok,
			'message' => (string) $message,
			'sent'    => (int) $sent,
			'pending' => (int) $pending,
			'http'    => (int) $http,
		);
	}
}
