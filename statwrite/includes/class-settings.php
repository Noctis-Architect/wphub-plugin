<?php
defined( 'ABSPATH' ) || exit;

final class WPHS_Settings {

	const OPTION = 'wphs_settings';

	private static $cache = null;

	public static function defaults() {
		return array(
			'storage_mode'            => 'local',
			'console_token'           => '',
			'track_logged_in'         => false,
			'respect_dnt'             => true,
			'exclude_404'             => true,
			'bot_js_check'            => true,
			'retention_detail_days'   => 30,
			'retention_daily_days'    => 180,
			'retention_totals_months' => 24,
			'delete_on_uninstall'     => false,
		);
	}

	public static function install_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', 'yes' );
			self::$cache = null;

			return;
		}

		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( self::OPTION, 'yes' );
		}

		self::$cache = null;
	}

	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		self::$cache = array_merge( self::defaults(), $saved );

		return self::$cache;
	}

	public static function value( $key ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	public static function mode() {
		return 'cloud' === self::value( 'storage_mode' ) ? 'cloud' : 'local';
	}

	/**
	 * The console endpoint is fixed in the plugin. Self-hosted/dev installs
	 * may override it with a constant in wp-config.php; it is never editable
	 * from the admin panel.
	 */
	public static function console_url() {
		$override = defined( 'WPHS_CONSOLE_URL' ) ? trim( (string) WPHS_CONSOLE_URL ) : '';

		if ( '' !== $override ) {
			return untrailingslashit( $override );
		}

		return untrailingslashit( WPHS_PRODUCTION_CONSOLE_URL );
	}

	public static function sanitize( $input ) {
		$out = self::defaults();

		$mode = isset( $input['storage_mode'] ) ? sanitize_key( (string) $input['storage_mode'] ) : 'local';
		$out['storage_mode'] = in_array( $mode, array( 'local', 'cloud' ), true ) ? $mode : 'local';

		$token = isset( $input['console_token'] ) ? trim( (string) $input['console_token'] ) : '';
		$out['console_token'] = preg_match( '/^[A-Za-z0-9._\-]{16,200}$/', $token ) ? $token : '';

		$out['track_logged_in']     = ! empty( $input['track_logged_in'] );
		$out['respect_dnt']         = ! empty( $input['respect_dnt'] );
		$out['exclude_404']         = ! empty( $input['exclude_404'] );
		$out['bot_js_check']        = ! empty( $input['bot_js_check'] );
		$out['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );

		$detail = isset( $input['retention_detail_days'] ) ? absint( $input['retention_detail_days'] ) : 30;
		if ( ! in_array( $detail, array( 7, 14, 30, 60, 90 ), true ) ) {
			$detail = 30;
		}
		$out['retention_detail_days'] = $detail;

		$daily = isset( $input['retention_daily_days'] ) ? absint( $input['retention_daily_days'] ) : 180;
		if ( ! in_array( $daily, array( 30, 90, 180, 365 ), true ) ) {
			$daily = 180;
		}
		$out['retention_daily_days'] = $daily;

		$months = isset( $input['retention_totals_months'] ) ? absint( $input['retention_totals_months'] ) : 24;
		if ( ! in_array( $months, array( 0, 6, 12, 24, 36, 60 ), true ) ) {
			$months = 24;
		}
		$out['retention_totals_months'] = $months;

		return $out;
	}

	/**
	 * Stores only the console site key (used by the AI Writer onboarding).
	 *
	 * @param string $token Site key, or an empty string to remove it.
	 */
	public static function set_token( $token ) {
		$current                  = self::all();
		$current['console_token'] = (string) $token;
		$current['remove_token']  = '' === (string) $token;

		self::save( $current );
	}

	public static function save( $input ) {
		$old = self::all();

		// The saved key is never printed back into the form: an empty field keeps it.
		if ( isset( $input['console_token'] ) && '' === trim( (string) $input['console_token'] ) && empty( $input['remove_token'] ) ) {
			$input['console_token'] = $old['console_token'];
		}

		$value = self::sanitize( $input );

		update_option( self::OPTION, $value, true );
		self::$cache = $value;

		if ( '' === $value['console_token'] && '' !== $old['console_token'] && class_exists( 'WPHS_Content_API' ) ) {
			// Disconnecting the console also revokes its permission to write articles here.
			WPHS_Content_API::update(
				array(
					'secret'    => '',
					'linked_at' => 0,
				)
			);
		}

		if ( 'cloud' === $value['storage_mode'] && 'cloud' !== $old['storage_mode'] ) {
			// The owner chose cloud again: start the outage clock from scratch.
			delete_option( 'wphs_cloud_fallback' );
			delete_option( 'wphs_cloud_down_since' );
		}

		if ( $old['console_token'] !== $value['console_token'] ) {
			delete_option( 'wphs_cloud_ping_ok' );
			delete_option( 'wphs_cloud_ping_at' );
			delete_option( 'wphs_cloud_failures' );
			delete_option( 'wphs_cloud_next_attempt' );
			delete_option( 'wphs_cloud_last_error' );
			delete_transient( 'wphs_site_overview' );
			delete_option( 'wphs_site_overview_last' );
			delete_option( 'wphs_site_overview_at' );
		}
	}
}
