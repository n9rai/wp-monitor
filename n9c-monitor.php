<?php
/**
 * Plugin Name:       N9C Inside Monitor
 * Plugin URI:        https://n9c.io/monitoring
 * Description:       Security monitoring from the inside: reports versions, plugins, configuration and admin accounts without two-factor login to the N9C Inside Monitor. Numbers and flags only – no user names, e-mail addresses or content.
 * Version:           0.1.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            N9C
 * Author URI:        https://n9c.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       n9c-monitor
 * Domain Path:       /languages
 * Network:           true
 *
 * @package N9C_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'N9C_MONITOR_VERSION', '0.1.0' );
define( 'N9C_MONITOR_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-n9c-monitor-config.php';
require_once __DIR__ . '/includes/class-n9c-monitor-client.php';
require_once __DIR__ . '/includes/class-n9c-monitor-activity.php';
require_once __DIR__ . '/includes/class-n9c-monitor-collector.php';
require_once __DIR__ . '/includes/class-n9c-monitor-reporter.php';

/**
 * Activation: start login bookkeeping. Nothing is sent before connecting.
 *
 * @return void
 */
function n9c_monitor_activate() {
	N9C_Monitor_Activity::start_tracking();
}

/**
 * Deactivation: stop scheduled reports (credentials are kept).
 *
 * @return void
 */
function n9c_monitor_deactivate() {
	N9C_Monitor_Reporter::unschedule();
}

register_activation_hook( __FILE__, 'n9c_monitor_activate' );
register_deactivation_hook( __FILE__, 'n9c_monitor_deactivate' );

/**
 * Bundled translations (German) until language packs from
 * translate.wordpress.org are available; those take precedence.
 *
 * @return void
 */
function n9c_monitor_load_textdomain() {
	load_plugin_textdomain( 'n9c-monitor', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- bundled German translation.
}
add_action( 'init', 'n9c_monitor_load_textdomain' );

N9C_Monitor_Activity::init();
N9C_Monitor_Reporter::init();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-n9c-monitor-admin.php';
	N9C_Monitor_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-n9c-monitor-cli.php';
	WP_CLI::add_command( 'n9c-monitor', 'N9C_Monitor_CLI' );
}
