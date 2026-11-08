<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Request headers are analytics inputs and are normalized by dedicated methods.

final class WPHS_Collector {

	const REST_NS = 'wphub-analytics/v1';

	private const SEARCH_HOSTS = array(
		'bing.com',
		'duckduckgo.com',
		'yahoo.com',
		'yandex.ru',
		'yandex.com',
		'baidu.com',
		'ecosia.org',
		'brave.com',
		'startpage.com',
		'qwant.com',
		'naver.com',
		'seznam.cz',
		'sogou.com',
		'marginalia.nu',
		'search.marginalia.nu',
	);

	private const SOCIAL_HOSTS = array(
		'facebook.com',
		'fb.com',
		'instagram.com',
		't.co',
		'twitter.com',
		'x.com',
		'linkedin.com',
		'lnkd.in',
		'pinterest.com',
		'reddit.com',
		'youtube.com',
		'youtu.be',
		'tiktok.com',
		'telegram.org',
		'telegram.me',
		't.me',
		'whatsapp.com',
		'wa.me',
		'discord.com',
		'threads.net',
		'bsky.app',
	);

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_capture' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function maybe_capture() {
		if ( self::skip_request() ) {
			return;
		}

		$hit_id = self::random_id();
		$path   = self::normalize_path( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );

		if ( self::blocked_path( $path ) ) {
			return;
		}

		$query    = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : '';
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		$ref      = self::referrer_data( $referrer, $query );

		$context = array(
			'eid' => self::random_id(),
			'h'   => $hit_id,
			'c'   => '',
			'src' => 'server',
			'u'   => $path,
			'ti'  => self::page_title(),
			'r'   => $ref['host'],
			'ch'  => $ref['channel'],
			'd'   => self::device(),
			'b'   => self::browser(),
			'o'   => self::os(),
			'cc'  => self::country(),
			'v'   => self::visitor_hash(),
			's'   => self::session_hash(),
			'pid' => self::page_id(),
			'k'   => 'pv',
			'g'   => 'u',
			't'   => time(),
		);

		$ip = self::cloud_ip();

		if ( '' !== $ip ) {
			$context['ip'] = $ip;
		}

		if ( ! WPHS_Spool::append( $context ) ) {
			return;
		}

		wp_enqueue_script(
			'wphs-tracker',
			WPHS_URL . 'assets/js/tracker.js',
			array(),
			WPHS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$inline = array(
			'e' => esc_url_raw( rest_url( self::REST_NS . '/collect' ) ),
			'h' => $hit_id,
			'p' => $path,
			's' => self::signature( $hit_id, $path ),
			'd' => WPHS_Settings::value( 'respect_dnt' ) ? 1 : 0,
		);

		wp_add_inline_script( 'wphs-tracker', 'window.wphs=' . wp_json_encode( $inline ) . ';', 'before' );
	}

	private static function skip_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== strtoupper( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
			return true;
		}

		if ( is_feed() || is_robots() || is_favicon() || is_trackback() || is_preview() || is_customize_preview() ) {
			return true;
		}

		if ( WPHS_Settings::value( 'exclude_404' ) && is_404() ) {
			return true;
		}

		if ( is_user_logged_in() && ! WPHS_Settings::value( 'track_logged_in' ) ) {
			return true;
		}

		if ( WPHS_Settings::value( 'respect_dnt' ) && self::is_dnt() ) {
			return true;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( WPHS_Bot_Filter::is_bot( $ua ) ) {
			return true;
		}

		$purpose = isset( $_SERVER['HTTP_SEC_PURPOSE'] ) ? strtolower( wp_unslash( $_SERVER['HTTP_SEC_PURPOSE'] ) ) : '';
		if ( false !== strpos( $purpose, 'prefetch' ) || false !== strpos( $purpose, 'prerender' ) ) {
			return true;
		}

		return false;
	}

	private static function is_dnt() {
		$dnt = isset( $_SERVER['HTTP_DNT'] ) ? trim( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '';
		$gpc = isset( $_SERVER['HTTP_SEC_GPC'] ) ? trim( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '';

		return '1' === $dnt || '1' === $gpc;
	}

	public static function blocked_path( $path ) {
		$blocked = array(
			'/wp-admin',
			'/wp-login.php',
			'/wp-json',
			'/wp-content',
			'/wp-includes',
			'/xmlrpc.php',
			'/favicon.ico',
			'/robots.txt',
			'/sitemap',
			'/.well-known',
		);

		foreach ( $blocked as $prefix ) {
			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				return true;
			}
		}

		if ( '/feed' === $path || 0 === strpos( $path, '/feed/' ) ) {
			return true;
		}

		if ( preg_match( '/\.xml$/i', $path ) ) {
			return true;
		}

		return (bool) apply_filters( 'wphs_blocked_path', false, $path );
	}

	public static function normalize_path( $uri ) {
		$path = wp_parse_url( (string) $uri, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}

		$path = rawurldecode( $path );
		$path = preg_replace( '/[\x00-\x1F\x7F]/u', '', $path );
		$path = preg_replace( '#/+#', '/', $path );

		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( is_string( $home ) && '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $home ) ), '/' );
		}

		if ( strlen( $path ) > 1 ) {
			$path = untrailingslashit( $path );
		}

		if ( '' === $path ) {
			$path = '/';
		}

		return substr( $path, 0, 255 );
	}

	public static function signature( $hit_id, $path ) {
		return substr( hash_hmac( 'sha256', $hit_id . '|' . $path, wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function random_id() {
		return bin2hex( random_bytes( 16 ) );
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		if ( ! empty( $_SERVER['HTTP_CF_RAY'] ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		}

		$ip = trim( (string) apply_filters( 'wphs_visitor_ip', $ip ) );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private static function cloud_ip() {
		if ( 'cloud' !== WPHS_Settings::mode() || ! WPHS_Settings::value( 'send_visitor_ip' ) ) {
			return '';
		}

		return self::client_ip();
	}

	public static function visitor_hash() {
		$ip = self::client_ip();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

		return substr( hash_hmac( 'sha256', $ip . '|' . $ua, wp_salt( 'auth' ) . '|' . gmdate( 'Y-m-d' ) ), 0, 32 );
	}

	public static function session_hash() {
		return substr( hash_hmac( 'sha256', self::visitor_hash() . '|' . (int) floor( time() / 1800 ), wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function device() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( preg_match( '/ipad|tablet|kindle|silk|playbook/', $ua ) ) {
			return 'tablet';
		}

		if ( preg_match( '/mobile|iphone|ipod|android.*mobile|windows phone|blackberry|opera mini/', $ua ) ) {
			return 'mobile';
		}

		return 'desktop';
	}

	public static function browser() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( preg_match( '/edg\//', $ua ) ) {
			return 'edge';
		}
		if ( preg_match( '/opr\/|opera/', $ua ) ) {
			return 'opera';
		}
		if ( preg_match( '/samsungbrowser/', $ua ) ) {
			return 'samsung';
		}
		if ( preg_match( '/firefox\//', $ua ) ) {
			return 'firefox';
		}
		if ( preg_match( '/chrome\/|crios/', $ua ) ) {
			return 'chrome';
		}
		if ( preg_match( '/safari\//', $ua ) ) {
			return 'safari';
		}
		if ( preg_match( '/msie|trident/', $ua ) ) {
			return 'ie';
		}

		return 'other';
	}

	public static function os() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( preg_match( '/iphone|ipad|ipod|ios/', $ua ) ) {
			return 'ios';
		}
		if ( preg_match( '/android/', $ua ) ) {
			return 'android';
		}
		if ( preg_match( '/windows/', $ua ) ) {
			return 'windows';
		}
		if ( preg_match( '/mac os|macintosh/', $ua ) ) {
			return 'macos';
		}
		if ( preg_match( '/linux/', $ua ) ) {
			return 'linux';
		}

		return 'other';
	}

	public static function country() {
		$code = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ) : '';

		if ( '' === $code && isset( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) {
			$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['GEOIP_COUNTRY_CODE'] ) ) );
		}

		$code = (string) apply_filters( 'wphs_country', $code );

		return preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';
	}

	public static function referrer_data( $url, $query = '' ) {
		$host = self::host_from_url( $url );
		$self = self::host_from_url( home_url( '/' ) );

		$utm_source = '';
		$utm_medium = '';

		if ( is_string( $query ) && '' !== $query ) {
			$query = substr( ltrim( $query, '?' ), 0, 500 );
			$args  = array();
			parse_str( $query, $args );

			if ( isset( $args['utm_source'] ) && is_string( $args['utm_source'] ) ) {
				$utm_source = strtolower( sanitize_text_field( wp_unslash( $args['utm_source'] ) ) );
			}
			if ( isset( $args['utm_medium'] ) && is_string( $args['utm_medium'] ) ) {
				$utm_medium = strtolower( sanitize_text_field( wp_unslash( $args['utm_medium'] ) ) );
			}
		}

		$internal = ( '' !== $host && '' !== $self && $host === $self );

		if ( '' !== $utm_source || '' !== $utm_medium ) {
			return array(
				'host'    => $internal ? '' : $host,
				'channel' => self::utm_channel( $utm_source, $utm_medium ),
			);
		}

		if ( $internal ) {
			return array(
				'host'    => '',
				'channel' => 'internal',
			);
		}

		if ( '' === $host ) {
			return array(
				'host'    => '',
				'channel' => 'direct',
			);
		}

		return array(
			'host'    => $host,
			'channel' => self::host_channel( $host ),
		);
	}

	private static function utm_channel( $source, $medium ) {
		$value = $medium . ' ' . $source;

		if ( preg_match( '/cpc|ppc|paid|display|ads?/', $value ) ) {
			return 'paid';
		}
		if ( preg_match( '/email|newsletter|mail/', $value ) ) {
			return 'email';
		}
		if ( preg_match( '/social/', $value ) ) {
			return 'social';
		}

		return 'campaign';
	}

	private static function host_channel( $host ) {
		if ( 0 === strpos( $host, 'google.' ) || false !== strpos( $host, '.google.' ) ) {
			return 'search';
		}

		if ( self::host_in( $host, self::SEARCH_HOSTS ) ) {
			return 'search';
		}

		if ( self::host_in( $host, self::SOCIAL_HOSTS ) ) {
			return 'social';
		}

		return 'referral';
	}

	private static function host_in( $host, array $list ) {
		foreach ( $list as $domain ) {
			if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {
				return true;
			}
		}

		return false;
	}

	private static function host_from_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		$host = strtolower( $host );
		$host = preg_replace( '/^www\./', '', $host );
		$host = preg_replace( '/[^a-z0-9.\-]/', '', $host );
		$host = trim( $host, '.' );

		return substr( $host, 0, 100 );
	}

	private static function page_title() {
		$title = '';

		if ( is_singular() ) {
			$title = get_the_title( get_queried_object_id() );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$title = single_term_title( '', false );
		} elseif ( is_post_type_archive() ) {
			$title = post_type_archive_title( '', false );
		} elseif ( is_search() ) {
			$title = __( 'Search:', 'traffic-analytics' ) . ' ' . get_search_query();
		} elseif ( is_home() || is_front_page() ) {
			$title = get_bloginfo( 'name' );
		}

		$title = wp_strip_all_tags( (string) $title );
		$title = sanitize_text_field( $title );

		return substr( $title, 0, 120 );
	}

	private static function page_id() {
		return is_singular() ? (int) get_queried_object_id() : 0;
	}

	public static function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_collect' ),
				'permission_callback' => '__return_true',
				'show_in_index'       => false,
			)
		);
	}

	public static function rest_collect( WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > 2048 ) {
			return new WP_Error( 'wphs_too_large', '', array( 'status' => 413 ) );
		}

		if ( WPHS_Settings::value( 'respect_dnt' ) && self::is_dnt() ) {
			return new WP_REST_Response( null, 204 );
		}

		if ( is_user_logged_in() && ! WPHS_Settings::value( 'track_logged_in' ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

		if ( WPHS_Bot_Filter::is_bot( $ua ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error( 'wphs_bad_request', '', array( 'status' => 400 ) );
		}

		$type = isset( $params['t'] ) ? (string) $params['t'] : '';
		$id   = isset( $params['id'] ) ? strtolower( (string) $params['id'] ) : '';
		$hit  = isset( $params['h'] ) ? strtolower( (string) $params['h'] ) : '';
		$path = isset( $params['p'] ) ? self::normalize_path( (string) $params['p'] ) : '';
		$sig  = isset( $params['sg'] ) ? strtolower( (string) $params['sg'] ) : '';

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) || ! preg_match( '/^[a-f0-9]{32}$/', $hit ) ) {
			return new WP_Error( 'wphs_bad_request', '', array( 'status' => 400 ) );
		}

		if ( '' === $path || ! hash_equals( self::signature( $hit, $path ), $sig ) ) {
			return new WP_Error( 'wphs_forbidden', '', array( 'status' => 403 ) );
		}

		if ( 'pv' === $type ) {
			$referrer = isset( $params['r'] ) ? (string) $params['r'] : '';
			$query    = isset( $params['q'] ) ? (string) $params['q'] : '';
			$title    = isset( $params['ti'] ) ? (string) $params['ti'] : '';
			$signals  = isset( $params['bs'] ) && is_array( $params['bs'] ) ? $params['bs'] : null;

			$ref    = self::referrer_data( $referrer, $query );
			$grade  = 'v';

			if ( WPHS_Settings::value( 'bot_js_check' ) ) {
				$verdict = WPHS_Bot_Filter::evaluate_js( $signals, $ua );
				$grade   = $verdict['verified'] ? 'v' : 's';
			}

			WPHS_Spool::append(
				array(
					'eid' => self::random_id(),
					'k'   => 'pv',
					'h'   => $hit,
					'c'   => $id,
					'src' => 'js',
					'u'   => $path,
					'ti'  => substr( sanitize_text_field( wp_strip_all_tags( $title ) ), 0, 120 ),
					'r'   => $ref['host'],
					'ch'  => $ref['channel'],
					'd'   => self::device(),
					'b'   => self::browser(),
					'o'   => self::os(),
					'cc'  => self::country(),
					'v'   => self::visitor_hash(),
					's'   => self::session_hash(),
					'pid' => 0,
					'g'   => $grade,
					't'   => time(),
				)
			);

			return new WP_REST_Response( null, 204 );
		}

		if ( 'eng' === $type ) {
			$seconds = isset( $params['s'] ) ? absint( $params['s'] ) : 0;
			$seconds = min( $seconds, 300 );

			$scroll = array();
			if ( isset( $params['sc'] ) && is_array( $params['sc'] ) ) {
				foreach ( $params['sc'] as $mark ) {
					$mark = absint( $mark );
					if ( in_array( $mark, array( 25, 50, 75, 100 ), true ) ) {
						$scroll[ $mark ] = 1;
					}
				}
			}

			$events = array();
			if ( isset( $params['ev'] ) && is_array( $params['ev'] ) ) {
				foreach ( array( 'outbound', 'download', 'form', 'cart' ) as $name ) {
					if ( isset( $params['ev'][ $name ] ) ) {
						$count = absint( $params['ev'][ $name ] );
						if ( $count > 0 ) {
							$events[ $name ] = min( $count, 50 );
						}
					}
				}
			}

			if ( 0 === $seconds && empty( $scroll ) && empty( $events ) ) {
				return new WP_REST_Response( null, 204 );
			}

			WPHS_Spool::append(
				array(
					'eid' => self::random_id(),
					'k'   => 'eng',
					'c'   => $id,
					'sq'  => isset( $params['sq'] ) ? absint( $params['sq'] ) : 0,
					'sec' => $seconds,
					'sc'  => array_keys( $scroll ),
					'ev'  => $events,
					't'   => time(),
				)
			);

			return new WP_REST_Response( null, 204 );
		}

		return new WP_Error( 'wphs_bad_request', '', array( 'status' => 400 ) );
	}
}
