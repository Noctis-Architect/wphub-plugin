<?php
defined( 'ABSPATH' ) || exit;

final class WPHS_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_wphs_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_wphs_cloud_test', array( __CLASS__, 'cloud_test' ) );
		add_action( 'admin_post_wphs_cloud_sync', array( __CLASS__, 'cloud_sync' ) );
		add_action( 'admin_post_wphs_purge_local', array( __CLASS__, 'purge_local' ) );
		add_action( 'admin_post_wphs_security_recheck', array( __CLASS__, 'security_recheck' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'in_admin_header', array( __CLASS__, 'clear_notices' ), 0 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'clean_admin_bar' ), 9999 );
		add_action( 'admin_head', array( __CLASS__, 'menu_icon_css' ) );
	}

	public static function menu_icon_css() {
		$url = WPHS_URL . 'assets/menu-icon.png';

		echo '<style id="wphs-menu-icon">';
		echo '#toplevel_page_hub-pilot .wp-menu-image{background-image:url("' . esc_url( $url ) . '") !important;background-size:20px 20px !important;background-position:center !important;background-repeat:no-repeat !important;}';
		echo '#toplevel_page_hub-pilot .wp-menu-image:before{content:none !important;}';
		echo '</style>';
	}

	public static function clean_admin_bar( $bar ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( (string) $screen->id, 'hub-pilot' ) ) {
			return;
		}

		if ( ! WPHS_Settings::value( 'hide_notices' ) ) {
			return;
		}

		$keep = array( 'menu-toggle', 'site-name', 'my-account', 'top-secondary' );

		foreach ( $bar->get_nodes() as $id => $node ) {
			if ( in_array( $id, $keep, true ) ) {
				continue;
			}

			$parent = $node->parent;

			while ( $parent ) {
				if ( 'my-account' === $parent ) {
					continue 2;
				}

				$parent_node = $bar->get_node( $parent );
				$parent      = $parent_node ? $parent_node->parent : false;
			}

			$bar->remove_node( $id );
		}
	}

	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && false !== strpos( (string) $screen->id, 'hub-pilot' ) ) {
			$classes .= ' wphs-admin';

			if ( WPHS_Settings::value( 'hide_notices' ) ) {
				$classes .= ' wphs-hide-notices';
			}
		}

		return $classes;
	}

	public static function clear_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( (string) $screen->id, 'hub-pilot' ) ) {
			return;
		}

		if ( ! WPHS_Settings::value( 'hide_notices' ) ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	public static function menu() {
		$cap = 'manage_options';

		add_menu_page(
			__( 'HubPilot', 'hub-pilot' ),
			__( 'HubPilot', 'hub-pilot' ),
			$cap,
			'hub-pilot',
			array( __CLASS__, 'render_dashboard' ),
			self::menu_icon(),
			3
		);

		add_submenu_page(
			'hub-pilot',
			__( 'Dashboard', 'hub-pilot' ),
			__( 'Dashboard', 'hub-pilot' ),
			$cap,
			'hub-pilot',
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			'hub-pilot',
			__( 'Settings', 'hub-pilot' ),
			__( 'Settings', 'hub-pilot' ),
			$cap,
			'hub-pilot-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'hub-pilot' ) ) {
			return;
		}

		wp_enqueue_style(
			'wphs-admin',
			WPHS_URL . 'admin/assets/admin.css',
			array(),
			WPHS_VERSION
		);
	}

	private static function menu_icon() {
		return 'dashicons-chart-bar';
	}

	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hub-pilot' ) );
		}

		$token_set      = WPHS_Cloud::has_token();
		$overview       = null;
		$overview_error = '';
		$overview_stale = false;

		if ( $token_set ) {
			$result = WPHS_Cloud::overview();

			if ( $result['ok'] ) {
				$overview       = $result['data'];
				$overview_stale = ! empty( $result['stale'] );
			} else {
				$overview_error = (string) $result['message'];
			}
		}

		if ( 'cloud' === WPHS_Settings::mode() ) {
			$status = WPHS_Cloud::status();

			require WPHS_DIR . 'admin/views/cloud.php';

			return;
		}

		$spool_size = WPHS_Spool::size();

		if ( $spool_size > 0 && $spool_size < 5 * MB_IN_BYTES && ( time() - (int) get_option( 'wphs_last_run', 0 ) ) > 30 ) {
			WPHS_Aggregator::run();
		}

		$data = WPHS_Reports::dashboard( WPHS_Reports::requested_range() );

		require WPHS_DIR . 'admin/views/dashboard.php';
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hub-pilot' ) );
		}

		$settings = WPHS_Settings::all();

		require WPHS_DIR . 'admin/views/settings.php';
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hub-pilot' ) );
		}

		check_admin_referer( 'wphs_save_settings' );

		WPHS_Settings::save( $_POST );
		delete_option( 'wphs_last_cleanup' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'hub-pilot-settings',
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function cloud_test() {
		self::guard( 'wphs_cloud_test' );

		$result = WPHS_Cloud::ping();

		self::cloud_notice( $result[0] ? 'success' : 'error', $result[1] );
		self::back();
	}

	public static function cloud_sync() {
		self::guard( 'wphs_cloud_sync' );

		$result = WPHS_Cloud::run( true );

		if ( $result['ok'] && 'sent' === $result['message'] ) {
			$message = sprintf(
				/* translators: %1$s: number of sent events; %2$s: number of queued events. */
				__( '%1$s events were sent to the console; %2$s events remain queued.', 'hub-pilot' ),
				number_format_i18n( $result['sent'] ),
				number_format_i18n( $result['pending'] )
			);
		} elseif ( $result['ok'] ) {
			$message = __( 'The queue was empty; nothing to send.', 'hub-pilot' );
		} else {
			$map = array(
				'not_cloud' => __( 'Cloud mode is not active.', 'hub-pilot' ),
				'no_token'  => __( 'The site connection key is not set.', 'hub-pilot' ),
				'backoff'   => __( 'The next attempt is not allowed yet; try again later.', 'hub-pilot' ),
				'locked'    => __( 'Another process is already running.', 'hub-pilot' ),
			);

			$message = isset( $map[ $result['message'] ] ) ? $map[ $result['message'] ] : __( 'Sync failed: ', 'hub-pilot' ) . $result['message'];
		}

		self::cloud_notice( $result['ok'] ? 'success' : 'error', $message );
		self::back();
	}

	public static function purge_local() {
		self::guard( 'wphs_purge_local' );

		WPHS_Cloud::purge_local_data();
		self::cloud_notice( 'success', __( 'Local analytics data was purged.', 'hub-pilot' ) );
		self::back();
	}

	public static function security_recheck() {
		self::guard( 'wphs_security_recheck' );

		delete_transient( 'wphs_security_check' );

		$back = wp_get_referer();

		if ( ! $back || false === strpos( $back, 'hub-pilot' ) ) {
			$back = admin_url( 'admin.php?page=hub-pilot' );
		}

		wp_safe_redirect( $back );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hub-pilot' ) );
		}

		check_admin_referer( $action );
	}

	private static function cloud_notice( $type, $message ) {
		set_transient(
			'wphs_cloud_notice',
			array(
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => (string) $message,
			),
			120
		);
	}

	private static function back() {
		$back = wp_get_referer();

		if ( ! $back || false === strpos( $back, 'hub-pilot' ) ) {
			$back = admin_url( 'admin.php?page=hub-pilot-settings' );
		}

		wp_safe_redirect( $back );
		exit;
	}
}
