<?php
/**
 * Removes everything the plugin stored. The instance in the N9C dashboard is
 * not touched; delete it there if it is no longer needed.
 *
 * @package N9C_Monitor
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'n9c_monitor_credentials', 'n9c_monitor_last_result', 'n9c_monitor_failed_logins', 'n9c_monitor_tracking_since' ) as $n9c_monitor_option ) {
	delete_site_option( $n9c_monitor_option );
}
delete_metadata( 'user', 0, 'n9c_monitor_last_login', '', true );
wp_clear_scheduled_hook( 'n9c_monitor_report' );
wp_clear_scheduled_hook( 'n9c_monitor_retry' );
