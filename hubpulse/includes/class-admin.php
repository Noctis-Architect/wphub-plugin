<?php
defined( 'ABSPATH' ) || exit;

final class WPHS_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_wphs_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_wphs_cloud_test', array( __CLASS__, 'cloud_test' ) );
		add_action( 'admin_post_wphs_disconnect', array( __CLASS__, 'disconnect' ) );
		add_action( 'admin_post_wphs_cloud_sync', array( __CLASS__, 'cloud_sync' ) );
		add_action( 'admin_post_wphs_purge_local', array( __CLASS__, 'purge_local' ) );
		add_action( 'admin_post_wphs_cloud_restore', array( __CLASS__, 'cloud_restore' ) );
		add_action( 'admin_post_wphs_cloud_stay_local', array( __CLASS__, 'cloud_stay_local' ) );
		add_action( 'admin_post_wphs_dismiss_national_net', array( __CLASS__, 'dismiss_national_net' ) );
		add_action( 'admin_notices', array( __CLASS__, 'connection_notices' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'menu_icon_css' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPHS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu_icon_css() {
		wp_enqueue_style( 'wphs-menu', WPHS_URL . 'admin/assets/menu.css', array(), WPHS_VERSION );
	}

	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && false !== strpos( (string) $screen->id, 'hubpulse' ) ) {
			$classes .= ' wphs-admin';
		}

		return $classes;
	}

	public static function menu() {
		$cap = 'manage_options';

		add_menu_page(
			__( 'Hubpulse', 'hubpulse' ),
			__( 'Hubpulse', 'hubpulse' ),
			$cap,
			'hubpulse',
			array( __CLASS__, 'render_dashboard' ),
			self::menu_icon(),
			3 // Right below Dashboard: visitor analytics is the first thing site owners check each day.
		);

		add_submenu_page(
			'hubpulse',
			__( 'Dashboard', 'hubpulse' ),
			__( 'Dashboard', 'hubpulse' ),
			$cap,
			'hubpulse',
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			'hubpulse',
			__( 'AI Writer', 'hubpulse' ),
			__( 'AI Writer', 'hubpulse' ),
			'publish_posts',
			'hubpulse-writer',
			array( __CLASS__, 'render_writer' )
		);

		add_submenu_page(
			'hubpulse',
			__( 'Settings', 'hubpulse' ),
			__( 'Settings', 'hubpulse' ),
			$cap,
			'hubpulse-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'hubpulse' ) ) {
			return;
		}

		wp_enqueue_style(
			'wphs-admin',
			WPHS_URL . 'admin/assets/admin.css',
			array(),
			WPHS_VERSION
		);

		wp_enqueue_script(
			'wphs-admin',
			WPHS_URL . 'admin/assets/admin.js',
			array(),
			WPHS_VERSION,
			true
		);

		$presets = array();

		foreach ( WPHS_AI_Provider::presets() as $id => $preset ) {
			$presets[ $id ] = array(
				'base'   => $preset['base'],
				'model'  => $preset['model'],
				'signup' => $preset['signup'],
			);
		}

		wp_localize_script(
			'wphs-admin',
			'wphsAdmin',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wphs_writer' ),
				'presets' => $presets,
				'prompt'  => WPHS_Writer::default_system_prompt(),
				'i18n'    => array(
					'working'      => __( 'Working…', 'hubpulse' ),
					'testing'      => __( 'Testing the connection…', 'hubpulse' ),
					'writing'      => __( 'Writing the article. This can take one to three minutes; keep this page open.', 'hubpulse' ),
					'queued'       => __( 'The console is writing the article. You can stay here or come back later.', 'hubpulse' ),
					'failed'       => __( 'Request failed. Try again.', 'hubpulse' ),
					'confirmReset' => __( 'Replace the system prompt with the default text?', 'hubpulse' ),
					'confirmPurge' => __( 'Purge local analytics data? This cannot be undone.', 'hubpulse' ),
					'confirmOff'   => __( 'Remove the site key and disconnect from the console?', 'hubpulse' ),
					'noModels'     => __( 'The provider did not list models; type the model name.', 'hubpulse' ),
					'models'       => __( 'models loaded — pick one from the list.', 'hubpulse' ),
					'preview'      => __( 'Preview', 'hubpulse' ),
					'edit'         => __( 'Edit', 'hubpulse' ),
					'sendDraft'    => __( 'Send as draft', 'hubpulse' ),
					'publish'      => __( 'Publish now', 'hubpulse' ),
					'view'         => __( 'View post', 'hubpulse' ),
					'empty'        => __( 'No articles yet. Write your first one above.', 'hubpulse' ),
					'words'        => __( 'words', 'hubpulse' ),
					'close'        => __( 'Close', 'hubpulse' ),
					/* translators: Used as "3 of 10" for the remaining article quota. */
					'of'           => __( 'of', 'hubpulse' ),
					'conn'         => array(
						'connected' => __( 'Connected', 'hubpulse' ),
						'error'     => __( 'Error', 'hubpulse' ),
						'pending'   => __( 'Pending', 'hubpulse' ),
					),
					'status'       => array(
						'queued'         => __( 'Queued', 'hubpulse' ),
						'generating'     => __( 'Writing…', 'hubpulse' ),
						'ready'          => __( 'Ready to send', 'hubpulse' ),
						'published'      => __( 'Sent to site', 'hubpulse' ),
						'publish_failed' => __( 'Sending failed', 'hubpulse' ),
						'failed'         => __( 'Failed', 'hubpulse' ),
					),
				),
			)
		);
	}

	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=hubpulse-settings' ) ) . '">' . esc_html__( 'Settings', 'hubpulse' ) . '</a>'
		);

		return $links;
	}

	public static function render_writer() {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hubpulse' ) );
		}

		require WPHS_DIR . 'admin/views/writer.php';
	}

	private static function menu_icon() {
		return 'dashicons-chart-bar';
	}

	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hubpulse' ) );
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hubpulse' ) );
		}

		$settings = WPHS_Settings::all();

		require WPHS_DIR . 'admin/views/settings.php';
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hubpulse' ) );
		}

		check_admin_referer( 'wphs_save_settings' );

		$old_token = WPHS_Settings::value( 'console_token' );

		WPHS_Settings::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every field is sanitized in WPHS_Settings::sanitize().
		delete_option( 'wphs_last_cleanup' );

		// A new site key connects everything at once, article writing included.
		$new_token = WPHS_Settings::value( 'console_token' );

		if ( '' !== $new_token && $new_token !== $old_token && class_exists( 'WPHS_Writer' ) ) {
			WPHS_Writer::link_console();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'hubpulse-settings',
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

	public static function disconnect() {
		self::guard( 'wphs_disconnect' );

		WPHS_Settings::set_token( '' );

		self::cloud_notice( 'success', __( 'The site key was removed and the console was disconnected.', 'hubpulse' ) );
		self::back();
	}

	public static function cloud_sync() {
		self::guard( 'wphs_cloud_sync' );

		$result = WPHS_Cloud::run( true );

		if ( $result['ok'] && 'sent' === $result['message'] ) {
			$message = sprintf(
				/* translators: %1$s: number of sent events; %2$s: number of queued events. */
				__( '%1$s events were sent to the console; %2$s events remain queued.', 'hubpulse' ),
				number_format_i18n( $result['sent'] ),
				number_format_i18n( $result['pending'] )
			);
		} elseif ( $result['ok'] ) {
			$message = __( 'The queue was empty; nothing to send.', 'hubpulse' );
		} else {
			$map = array(
				'not_cloud' => __( 'Cloud mode is not active.', 'hubpulse' ),
				'no_token'  => __( 'The site connection key is not set.', 'hubpulse' ),
				'backoff'   => __( 'The next attempt is not allowed yet; try again later.', 'hubpulse' ),
				'locked'    => __( 'Another process is already running.', 'hubpulse' ),
			);

			$message = isset( $map[ $result['message'] ] ) ? $map[ $result['message'] ] : __( 'Sync failed: ', 'hubpulse' ) . $result['message'];
		}

		self::cloud_notice( $result['ok'] ? 'success' : 'error', $message );
		self::back();
	}

	/**
	 * Outage fallback and national-internet notices.
	 *
	 * The fallback notice is shown on Hubpulse screens and the WordPress dashboard until the owner
	 * decides; the national-internet warning is shown on Hubpulse screens while the site is
	 * connected to the console.
	 */
	public static function connection_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		$ours   = false !== strpos( $id, 'hubpulse' );

		$fallback = WPHS_Cloud::fallback_state();

		if ( $fallback && ( $ours || 'dashboard' === $id ) ) {
			$hours = max( 6, (int) round( ( (int) $fallback['at'] - (int) $fallback['since'] ) / HOUR_IN_SECONDS ) );
			echo '<div class="notice notice-error wphs-fallback-notice"><p><strong>' . esc_html__( 'Hubpulse: the WP-Hub console did not answer, so analytics switched to local mode.', 'hubpulse' ) . '</strong></p><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: hours without an answer, 2: date and time of the switch. */
					__( 'There was no answer from the console for %1$s hours, so on %2$s visits started being stored on this host instead. Nothing was lost: queued visits were counted locally. This usually happens when international internet is cut (national internet) and hosts in Iran cannot reach the console.', 'hubpulse' ),
					self::local_digits( number_format_i18n( $hours ) ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $fallback['at'] )
				)
			);
			if ( ! empty( $fallback['error'] ) ) {
				echo '<br><small>' . esc_html__( 'Last error:', 'hubpulse' ) . ' ' . esc_html( $fallback['error'] ) . '</small>';
			}
			echo '</p><p>';
			echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wphs_cloud_restore' ), 'wphs_cloud_restore' ) ) . '">' . esc_html__( 'Test and return to cloud mode', 'hubpulse' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wphs_cloud_stay_local' ), 'wphs_cloud_stay_local' ) ) . '">' . esc_html__( 'Stay in local mode', 'hubpulse' ) . '</a>';
			echo '</p></div>';
		}

		if ( $ours && WPHS_Cloud::has_token() && self::is_iran_site() && ! get_user_meta( get_current_user_id(), 'wphs_dismissed_national_net', true ) ) {
			$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=wphs_dismiss_national_net' ), 'wphs_dismiss_national_net' );
			echo '<div class="notice notice-warning wphs-national-net-notice"><p><strong>' . esc_html__( 'National internet warning:', 'hubpulse' ) . '</strong> ';
			echo esc_html__( 'If international internet is cut (national internet), hosts inside Iran lose access to the WP-Hub console and there is nothing we can do about it from our side. In that case Hubpulse switches analytics to local storage on this host after 6 hours without an answer, so no visits are lost; writing and publishing articles through the console stays unavailable until the connection returns.', 'hubpulse' );
			echo ' <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Got it, hide this', 'hubpulse' ) . '</a>';
			echo '</p></div>';
		}
	}

	/**
	 * Persian/Arabic-script locales read Latin digits inside RTL text awkwardly.
	 */
	private static function local_digits( $text ) {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		if ( 0 !== strpos( (string) $locale, 'fa' ) ) {
			return (string) $text;
		}

		// Array form: the two-string form of strtr() is byte-based and would corrupt UTF-8.
		return strtr( (string) $text, array_combine( str_split( '0123456789' ), array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ) ) );
	}

	/**
	 * The national-internet warning only concerns sites hosted for Iran: Persian admin language
	 * or the Tehran timezone. Other sites never see it.
	 */
	private static function is_iran_site() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		return 0 === strpos( (string) $locale, 'fa' ) || 'Asia/Tehran' === wp_timezone_string();
	}

	public static function dismiss_national_net() {
		self::guard( 'wphs_dismiss_national_net' );

		update_user_meta( get_current_user_id(), 'wphs_dismissed_national_net', 1 );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=hubpulse' ) );
		exit;
	}

	public static function cloud_restore() {
		self::guard( 'wphs_cloud_restore' );

		$result = WPHS_Cloud::restore_cloud();

		self::cloud_notice(
			$result[0] ? 'success' : 'error',
			$result[0]
				? __( 'The console answered; analytics are sent to the cloud again.', 'hubpulse' )
				/* translators: %s: error message. */
				: sprintf( __( 'The console still does not answer, so local mode stays on: %s', 'hubpulse' ), $result[1] )
		);
		self::back_to( 'hubpulse-settings' );
	}

	public static function cloud_stay_local() {
		self::guard( 'wphs_cloud_stay_local' );

		WPHS_Cloud::dismiss_fallback();
		self::cloud_notice( 'success', __( 'Analytics stay in local mode. You can switch back to cloud mode in the settings at any time.', 'hubpulse' ) );
		self::back_to( 'hubpulse-settings' );
	}

	private static function back_to( $page ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	public static function purge_local() {
		self::guard( 'wphs_purge_local' );

		WPHS_Cloud::purge_local_data();
		self::cloud_notice( 'success', __( 'Local analytics data was purged.', 'hubpulse' ) );
		self::back();
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hubpulse' ) );
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

		if ( ! $back || false === strpos( $back, 'hubpulse' ) ) {
			$back = admin_url( 'admin.php?page=hubpulse-settings' );
		}

		wp_safe_redirect( $back );
		exit;
	}
}
