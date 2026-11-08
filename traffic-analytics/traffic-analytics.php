<?php
/**
 * Plugin Name: Traffic Analytics by WP-Hub
 * Plugin URI:  https://wp-hub.org
 * Description: Lightweight pageview, source, and engagement analytics with no extra load on the site or database.
 * Version:     0.4.0
 * Author:      WP-Hub
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: traffic-analytics
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WPHS_VERSION', '0.4.0' );
define( 'WPHS_PRODUCTION_CONSOLE_URL', 'https://console.wp-hub.org' );
define( 'WPHS_FILE', __FILE__ );
define( 'WPHS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPHS_URL', plugin_dir_url( __FILE__ ) );

require_once WPHS_DIR . 'includes/class-schema.php';
require_once WPHS_DIR . 'includes/class-settings.php';
require_once WPHS_DIR . 'includes/class-spool.php';
require_once WPHS_DIR . 'includes/class-bot-filter.php';
require_once WPHS_DIR . 'includes/class-collector.php';
require_once WPHS_DIR . 'includes/class-aggregator.php';
require_once WPHS_DIR . 'includes/class-cloud.php';
require_once WPHS_DIR . 'includes/class-reports.php';
require_once WPHS_DIR . 'includes/class-credit.php';
require_once WPHS_DIR . 'includes/class-security-check.php';
require_once WPHS_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'WPHS_Schema', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPHS_Aggregator', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'WPHS_Cloud', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'traffic-analytics', false, dirname( plugin_basename( WPHS_FILE ) ) . '/languages' );
		WPHS_Schema::maybe_upgrade();
		WPHS_Collector::init();
		WPHS_Aggregator::init();
		WPHS_Cloud::init();
		WPHS_Credit::init();
		WPHS_Admin::init();
	}
);
