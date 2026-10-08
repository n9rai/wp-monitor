<?php
/**
 * Local login bookkeeping that WordPress does not do by itself.
 *
 * - Last login time of administrators (user meta "n9c_monitor_last_login"),
 *   used for the "inactive administrators" check.
 * - Number of failed logins per hour for the last 24 hours (network option
 *   "n9c_monitor_failed_logins"), no user names or IP addresses.
 *
 * Both stay in this database; only the resulting counts are reported.
 *
 * @package N9C_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Login bookkeeping.
 */
final class N9C_Monitor_Activity {

	const LAST_LOGIN_META = 'n9c_monitor_last_login';
	const FAILED_OPTION   = 'n9c_monitor_failed_logins';
	const SINCE_OPTION    = 'n9c_monitor_tracking_since';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ), 10, 0 );
	}

	/**
	 * Remember when bookkeeping started (plugin activation).
	 *
	 * @return void
	 */
	public static function start_tracking() {
		if ( false === get_site_option( self::SINCE_OPTION, false ) ) {
			add_site_option( self::SINCE_OPTION, time() );
		}
	}

	/**
	 * Timestamp since when logins are tracked.
	 *
	 * @return int|null
	 */
	public static function tracking_since() {
		$since = get_site_option( self::SINCE_OPTION, false );
		return false === $since ? null : (int) $since;
	}

	/**
	 * Successful login.
	 *
	 * @param string       $user_login Login name (unused, never stored).
	 * @param WP_User|null $user       User object.
	 * @return void
	 */
	public static function on_login( $user_login, $user = null ) {
		unset( $user_login );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		if ( user_can( $user, 'manage_options' ) || is_super_admin( $user->ID ) ) {
			update_user_meta( $user->ID, self::LAST_LOGIN_META, time() );
		}
	}

	/**
	 * Failed login (user name and IP are not stored).
	 *
	 * @return void
	 */
	public static function on_login_failed() {
		$hour    = (int) floor( time() / HOUR_IN_SECONDS );
		$buckets = self::buckets();
		$count   = isset( $buckets[ $hour ] ) ? (int) $buckets[ $hour ] : 0;
		if ( $count >= 1000000 ) {
			return;
		}
		$buckets[ $hour ] = $count + 1;
		foreach ( array_keys( $buckets ) as $key ) {
			if ( (int) $key < $hour - 24 ) {
				unset( $buckets[ $key ] );
			}
		}
		if ( false === get_site_option( self::FAILED_OPTION, false ) ) {
			add_site_option( self::FAILED_OPTION, $buckets );
		} else {
			update_site_option( self::FAILED_OPTION, $buckets );
		}
	}

	/**
	 * Failed logins in the last 24 hours (24 full hourly buckets incl. the current one).
	 *
	 * @return int
	 */
	public static function failed_logins_24h() {
		$hour  = (int) floor( time() / HOUR_IN_SECONDS );
		$total = 0;
		foreach ( self::buckets() as $key => $count ) {
			if ( (int) $key > $hour - 24 ) {
				$total += (int) $count;
			}
		}
		return $total;
	}

	/**
	 * Most recent known login of a user: own bookkeeping or an active session.
	 *
	 * @param int $user_id User ID.
	 * @return int|null
	 */
	public static function last_login( $user_id ) {
		$last = (int) get_user_meta( $user_id, self::LAST_LOGIN_META, true );
		$sessions = get_user_meta( $user_id, 'session_tokens', true );
		if ( is_array( $sessions ) ) {
			foreach ( $sessions as $session ) {
				if ( is_array( $session ) && isset( $session['login'] ) ) {
					$last = max( $last, (int) $session['login'] );
				}
			}
		}
		return $last > 0 ? $last : null;
	}

	/**
	 * Hourly buckets.
	 *
	 * @return array<int, int>
	 */
	private static function buckets() {
		$buckets = get_site_option( self::FAILED_OPTION, array() );
		return is_array( $buckets ) ? $buckets : array();
	}
}
