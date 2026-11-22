<?php
/**
 * Plugin Name: Hubpulse – Analytics, AI Writer & Console Connector
 * Plugin URI:  https://wp-hub.org
 * Description: Cookie-free visitor analytics and an AI article writer (WP-Hub console or your own AI provider), with optional speed, security and uptime reports from the WP-Hub console.
 * Version:     0.5.0
 * Author:      WP-Hub
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hubpulse
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WPHS_VERSION', '0.5.0' );
define( 'WPHS_PRODUCTION_CONSOLE_URL', 'https://console.wp-hub.org' );
define( 'WPHS_SLUG', 'hubpulse' );
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
require_once WPHS_DIR . 'includes/class-content-seo.php';
require_once WPHS_DIR . 'includes/class-content-api.php';
require_once WPHS_DIR . 'includes/class-ai-provider.php';
require_once WPHS_DIR . 'includes/class-writer.php';
require_once WPHS_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'WPHS_Schema', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPHS_Aggregator', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( 'WPHS_Cloud', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		WPHS_Schema::maybe_upgrade();
		WPHS_Collector::init();
		WPHS_Aggregator::init();
		WPHS_Cloud::init();
		WPHS_Content_API::init();

		if ( is_admin() ) {
			WPHS_Writer::init();
		}

		WPHS_Admin::init();
	}
);
