<?php
/**
 * Plugin Name: HubPilot
 * Plugin URI:  https://wp-hub.org
 * Description: Connects WordPress to the WP-Hub console for cloud analytics, speed, security, and uptime monitoring, with optional local analytics and basic security checks.
 * Version:     0.4.2
 * Author:      WP-Hub
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hub-pilot
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WPHS_VERSION', '0.4.2' );
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
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Bundled translations are loaded for non-WordPress.org installs.
		load_plugin_textdomain( 'hub-pilot', false, dirname( plugin_basename( WPHS_FILE ) ) . '/languages' );
		WPHS_Schema::maybe_upgrade();
		WPHS_Collector::init();
		WPHS_Aggregator::init();
		WPHS_Cloud::init();
		WPHS_Credit::init();
		WPHS_Admin::init();
	}
);
