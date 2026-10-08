<?php
/**
 * Credentials and endpoints of this installation.
 *
 * Lookup order for instance ID, secret and endpoint:
 * 1. Constants in wp-config.php: N9C_MONITOR_INSTANCE, N9C_MONITOR_SECRET,
 *    N9C_MONITOR_ENDPOINT (recommended for deployments).
 * 2. Environment variables with the same names.
 * 3. Network option "n9c_monitor_credentials" (not autoloaded), written when
 *    connecting from the admin page or via WP-CLI.
 *
 * Copies of a site (staging, local clones) share the database and would
 * report as the same instance. Stored credentials therefore remember the
 * URL they were issued for; if the URL changes, automatic reports pause
 * until an administrator confirms the move or disconnects the copy.
 *
 * @package N9C_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Credential store.
 */
final class N9C_Monitor_Config {

	const DEFAULT_ENDPOINT  = 'https://api.n9c.io';
	const DEFAULT_DASHBOARD = 'https://dashboard.n9c.io';
	const OPTION            = 'n9c_monitor_credentials';

	/**
	 * Current configuration.
	 *
	 * @return array{instance_id: ?string, secret: ?string, endpoint: string, source: string, bound_url: ?string}
	 */
	public static function load() {
		$stored = get_site_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$instance = self::external( 'N9C_MONITOR_INSTANCE' );
		$secret   = self::external( 'N9C_MONITOR_SECRET' );
		$endpoint = self::external( 'N9C_MONITOR_ENDPOINT' );

		if ( null !== $instance ) {
			$source = defined( 'N9C_MONITOR_INSTANCE' ) ? 'constant' : 'env';
		} elseif ( ! empty( $stored['instance_id'] ) ) {
			$source = 'option';
		} else {
			$source = 'none';
		}

		if ( null === $endpoint ) {
			$endpoint = ! empty( $stored['endpoint'] ) ? (string) $stored['endpoint'] : self::DEFAULT_ENDPOINT;
		}

		return array(
			'instance_id' => null !== $instance ? $instance : ( isset( $stored['instance_id'] ) ? (string) $stored['instance_id'] : null ),
			'secret'      => null !== $secret ? $secret : ( isset( $stored['secret'] ) ? (string) $stored['secret'] : null ),
			'endpoint'    => untrailingslashit( $endpoint ),
			'source'      => $source,
			'bound_url'   => isset( $stored['bound_url'] ) ? (string) $stored['bound_url'] : null,
		);
	}

	/**
	 * Whether instance ID and secret are available.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$config = self::load();
		return ! empty( $config['instance_id'] ) && ! empty( $config['secret'] );
	}

	/**
	 * Store credentials received from the enroll endpoint.
	 *
	 * @param string $instance_id Instance ID.
	 * @param string $secret      HMAC secret.
	 * @param string $endpoint    API base URL.
	 * @return void
	 */
	public static function save( $instance_id, $secret, $endpoint ) {
		$value = array(
			'instance_id' => (string) $instance_id,
			'secret'      => (string) $secret,
			'endpoint'    => untrailingslashit( (string) $endpoint ),
			'bound_url'   => self::fingerprint(),
			'saved_at'    => time(),
		);
		if ( false === get_site_option( self::OPTION, false ) ) {
			add_site_option( self::OPTION, $value );
		} else {
			update_site_option( self::OPTION, $value );
		}
	}

	/**
	 * Forget stored credentials (does not affect constants or env variables).
	 *
	 * @return void
	 */
	public static function clear() {
		delete_site_option( self::OPTION );
	}

	/**
	 * Normalised URL of this installation (main site on multisite).
	 *
	 * @return string e.g. "www.example.com/blog"
	 */
	public static function fingerprint() {
		$url = untrailingslashit( network_home_url() );
		return strtolower( (string) preg_replace( '#^https?://#i', '', $url ) );
	}

	/**
	 * True if stored credentials were issued for a different URL - i.e. this
	 * is probably a copy of the connected site, or the site has moved.
	 *
	 * @return bool
	 */
	public static function has_moved() {
		$config = self::load();
		if ( 'option' !== $config['source'] || empty( $config['bound_url'] ) ) {
			return false;
		}
		return $config['bound_url'] !== self::fingerprint();
	}

	/**
	 * Accept the current URL as the new home of the stored credentials.
	 *
	 * @return void
	 */
	public static function confirm_move() {
		$stored = get_site_option( self::OPTION, array() );
		if ( is_array( $stored ) && ! empty( $stored['instance_id'] ) ) {
			$stored['bound_url'] = self::fingerprint();
			update_site_option( self::OPTION, $stored );
		}
	}

	/**
	 * Base URL of the N9C dashboard (account, connection codes, results).
	 *
	 * @return string
	 */
	public static function dashboard_url() {
		$url = self::external( 'N9C_MONITOR_DASHBOARD' );
		return untrailingslashit( null !== $url ? $url : self::DEFAULT_DASHBOARD );
	}

	/**
	 * Links into the dashboard.
	 *
	 * @return array<string, ?string>
	 */
	public static function dashboard_links() {
		$base   = self::dashboard_url();
		$config = self::load();
		return array(
			'register' => $base . '/dashboard/register',
			'login'    => $base . '/dashboard/',
			'new_code' => $base . '/dashboard/tokens/new',
			'billing'  => $base . '/dashboard/billing',
			'instance' => ! empty( $config['instance_id'] ) ? $base . '/dashboard/instances/' . rawurlencode( (string) $config['instance_id'] ) : null,
		);
	}

	/**
	 * Value from a constant or, failing that, an environment variable.
	 *
	 * @param string $name Constant / variable name.
	 * @return string|null
	 */
	private static function external( $name ) {
		if ( defined( $name ) ) {
			$value = (string) constant( $name );
			return '' !== $value ? $value : null;
		}
		$value = getenv( $name );
		return ( false !== $value && '' !== $value ) ? (string) $value : null;
	}
}
