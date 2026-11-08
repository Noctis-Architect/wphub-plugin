<?php
defined( 'ABSPATH' ) || exit;

final class WPHS_Security_Check {

	const TRANSIENT = 'wphs_security_check';
	const TTL       = 12 * HOUR_IN_SECONDS;

	public static function results( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_array( $cached ) && isset( $cached['checked_at'], $cached['failures'], $cached['unknown'] ) ) {
				return $cached;
			}
		}

		$result = self::run();

		set_transient( self::TRANSIENT, $result, self::TTL );

		return $result;
	}

	private static function run() {
		$failures = array();
		$unknown  = array();

		$directory = self::directory_listing_status();

		if ( 'fail' === $directory ) {
			$failures[] = array(
				'key'   => 'directory_listing',
				'label' => __( 'Directory listing is enabled', 'hub-pilot' ),
			);
		} elseif ( 'unknown' === $directory ) {
			$unknown[] = 'directory_listing';
		}

		$rest = self::rest_users_status();

		if ( 'fail' === $rest ) {
			$failures[] = array(
				'key'   => 'rest_users',
				'label' => __( 'REST users endpoint is public', 'hub-pilot' ),
			);
		} elseif ( 'unknown' === $rest ) {
			$unknown[] = 'rest_users';
		}

		$admin = self::admin_status();

		if ( 'fail' === $admin ) {
			$failures[] = array(
				'key'   => 'wp_admin',
				'label' => __( 'wp-admin is directly accessible', 'hub-pilot' ),
			);
		} elseif ( 'unknown' === $admin ) {
			$unknown[] = 'wp_admin';
		}

		if ( ! self::has_security_plugin() ) {
			$failures[] = array(
				'key'   => 'security_plugin',
				'label' => __( 'No security plugin detected', 'hub-pilot' ),
			);
		}

		if ( ! self::file_editing_disabled() ) {
			$failures[] = array(
				'key'   => 'file_edit',
				'label' => __( 'File editing is enabled', 'hub-pilot' ),
			);
		}

		if ( self::debug_display_enabled() ) {
			$failures[] = array(
				'key'   => 'debug_display',
				'label' => __( 'Debug display is enabled', 'hub-pilot' ),
			);
		}

		return array(
			'checked_at' => time(),
			'failures'   => $failures,
			'unknown'    => $unknown,
		);
	}

	private static function directory_listing_status() {
		$unknown = false;

		foreach ( array( '/wp-content/uploads/', '/wp-content/plugins/' ) as $path ) {
			$response = wp_remote_get(
				home_url( $path ),
				array(
					'timeout'     => 2,
					'redirection' => 0,
					'sslverify'   => true,
					'headers'     => array(
						'User-Agent' => 'Traffic-Analytics-Security-Check/' . WPHS_VERSION,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$unknown = true;
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 === $code ) {
				$body = (string) wp_remote_retrieve_body( $response );

				if ( preg_match( '/<title>\s*Index of\b/i', $body ) || false !== stripos( $body, 'Index of /' ) ) {
					return 'fail';
				}
			} elseif ( ! in_array( $code, array( 401, 403, 404 ), true ) ) {
				$unknown = true;
			}
		}

		return $unknown ? 'unknown' : 'pass';
	}

	private static function rest_users_status() {
		if ( ! function_exists( 'rest_do_request' ) || ! class_exists( 'WP_REST_Request' ) ) {
			return 'unknown';
		}

		$current = get_current_user_id();
		wp_set_current_user( 0 );

		try {
			$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
			$request->set_query_params( array( 'per_page' => 1 ) );
			$response = rest_do_request( $request );
		} finally {
			wp_set_current_user( $current );
		}

		$code = (int) $response->get_status();

		if ( 200 === $code ) {
			$data = $response->get_data();

			if ( is_array( $data ) && ! empty( $data ) && isset( $data[0] ) && is_array( $data[0] ) && ( isset( $data[0]['id'] ) || isset( $data[0]['slug'] ) ) ) {
				return 'fail';
			}

			return 'pass';
		}

		if ( in_array( $code, array( 401, 403, 404 ), true ) ) {
			return 'pass';
		}

		return 'unknown';
	}

	private static function admin_status() {
		$response = wp_remote_get(
			admin_url(),
			array(
				'timeout'     => 2,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'User-Agent' => 'Traffic-Analytics-Security-Check/' . WPHS_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );

		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			return false !== strpos( $location, 'wp-login.php' ) ? 'pass' : 'unknown';
		}

		if ( in_array( $code, array( 401, 403, 404 ), true ) ) {
			return 'pass';
		}

		if ( 200 === $code ) {
			$body = (string) wp_remote_retrieve_body( $response );

			if ( false !== stripos( $body, 'id="wpadminbar"' ) || false !== stripos( $body, 'wpbody-content' ) ) {
				return 'fail';
			}

			if ( false !== stripos( $body, 'loginform' ) || false !== stripos( $body, 'user_login' ) ) {
				return 'pass';
			}

			return 'unknown';
		}

		return 'unknown';
	}

	private static function has_security_plugin() {
		$known = array(
			'wordfence/wordfence.php',
			'sucuri-scanner/sucuri.php',
			'better-wp-security/better-wp-security.php',
			'all-in-one-wp-security-and-firewall/wp-security.php',
			'defender-security/wp-defender.php',
			'wp-cerber/wp-cerber.php',
			'patchstack/patchstack.php',
			'malcare-security/malcare.php',
			'wp-simple-firewall/wp-simple-firewall.php',
			'ninjafirewall/ninjafirewall.php',
			'bulletproof-security/bulletproof-security.php',
			'ithemes-security/ithemes-security.php',
		);

		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $known as $plugin ) {
			if ( in_array( $plugin, $active, true ) ) {
				return true;
			}
		}

		return false;
	}

	private static function file_editing_disabled() {
		return defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
	}

	private static function debug_display_enabled() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return false;
		}

		return ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY;
	}
}
