<?php
/**
 * Collects the report (schema n9c.agent.report/1).
 *
 * Principles (same as the TYPO3 extension n9c_monitor):
 * - Numbers and flags only - no user names, e-mail addresses, IPs or content.
 * - Read only, never changes anything in the installation.
 * - Every check is guarded on its own: if one fails it is reported as null
 *   ("not checked") and the rest of the report is still sent.
 *
 * @package N9C_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report collector.
 */
final class N9C_Monitor_Collector {

	const AGENT_NAME = 'n9c_monitor_wp';
	const SCHEMA     = 'n9c.agent.report/1';

	const INACTIVE_DAYS     = 90;
	const MAX_SCANNED_FILES = 200000;
	/** Time budget for the uploads scan: generous for WP-CLI/cron, short when triggered interactively. */
	const SCAN_SECONDS_FULL  = 60;
	const SCAN_SECONDS_QUICK = 10;
	const SUSPICIOUS_EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht' );
	/** Capabilities that make a default role for self-registered users dangerous. */
	const PRIVILEGED_CAPS = array( 'manage_options', 'activate_plugins', 'edit_users', 'promote_users', 'edit_plugins', 'edit_theme_options', 'edit_others_posts', 'unfiltered_html' );

	/**
	 * Errors per check, reported in cms_specific.collector_errors.
	 *
	 * @var array<string, string>
	 */
	private $errors = array();

	/**
	 * Deadline for the uploads scan.
	 *
	 * @var int
	 */
	private $scan_deadline = 0;

	/**
	 * Detected two-factor plugins.
	 *
	 * @var string[]
	 */
	private $mfa_detectors = array();

	/**
	 * Build the report.
	 *
	 * @param bool $quick Short time budget for the file scan.
	 * @return array<string, mixed>
	 */
	public function collect( $quick = false ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$this->errors        = array();
		$this->scan_deadline = time() + ( $quick ? self::SCAN_SECONDS_QUICK : self::SCAN_SECONDS_FULL );
		$this->mfa_detectors = $this->detect_mfa_plugins();

		$admins     = $this->safe( 'admins', array( $this, 'load_admins' ) );
		$suspicious = $this->safe( 'fs.php_in_upload_dir', array( $this, 'find_suspicious_upload_files' ) );
		$plugins    = $this->safe( 'packages', array( $this, 'plugin_inventory' ) );
		$tracking   = N9C_Monitor_Activity::tracking_since();

		$checks = array(
			'auth.admin_without_mfa'                   => null === $admins ? null : count( array_filter( $admins, static function ( $a ) {
				return ! $a['mfa'];
			} ) ),
			'auth.admin_count'                         => null === $admins ? null : count( $admins ),
			'auth.default_admin_username'              => $this->safe( 'auth.default_admin_username', array( $this, 'has_default_admin_username' ) ),
			// Without own login bookkeeping for at least 90 days the answer would be a guess.
			'auth.inactive_admins_90d'                 => ( null === $admins || null === $tracking || time() - $tracking < self::INACTIVE_DAYS * DAY_IN_SECONDS ) ? null : count( array_filter( $admins, static function ( $a ) {
				return $a['inactive'];
			} ) ),
			'auth.failed_logins_24h'                   => null === $tracking ? null : $this->safe( 'auth.failed_logins_24h', array( 'N9C_Monitor_Activity', 'failed_logins_24h' ) ),
			'config.debug_output_enabled'              => $this->safe( 'config.debug_output_enabled', array( $this, 'is_debug_output_enabled' ) ),
			'config.backend_https_enforced'            => $this->safe( 'config.backend_https_enforced', array( $this, 'is_backend_https_enforced' ) ),
			'fs.php_in_upload_dir'                     => null === $suspicious ? null : count( $suspicious ),
			'ops.scheduler_last_run_hours'             => $this->safe( 'ops.scheduler_last_run_hours', array( $this, 'cron_overdue_hours' ) ),
			'cms.wordpress.file_editor_enabled'        => $this->safe( 'cms.wordpress.file_editor_enabled', array( $this, 'is_file_editor_enabled' ) ),
			'cms.wordpress.open_registration_privileged' => $this->safe( 'cms.wordpress.open_registration_privileged', array( $this, 'is_open_registration_privileged' ) ),
			'cms.wordpress.debug_log_public'           => $this->safe( 'cms.wordpress.debug_log_public', array( $this, 'is_debug_log_public' ) ),
			'cms.wordpress.inactive_plugins'           => null === $plugins ? null : $plugins['inactive'],
			'cms.wordpress.core_auto_updates_disabled' => $this->safe( 'cms.wordpress.core_auto_updates_disabled', array( $this, 'are_core_auto_updates_disabled' ) ),
		);

		$packages = null === $plugins ? array() : $plugins['packages'];
		$themes   = $this->safe( 'packages.themes', array( $this, 'collect_themes' ) );
		if ( is_array( $themes ) ) {
			$packages = array_merge( $packages, $themes );
		}

		$report = array(
			'schema'       => self::SCHEMA,
			'agent'        => array(
				'name'    => self::AGENT_NAME,
				'version' => N9C_MONITOR_VERSION,
			),
			'cms'          => array(
				'type'    => 'wordpress',
				'version' => $this->wordpress_version(),
				'context' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : null,
			),
			'runtime'      => array(
				'php' => PHP_VERSION,
				'db'  => $this->safe( 'runtime.db', array( $this, 'database_version' ) ),
				'os'  => PHP_OS_FAMILY,
			),
			'packages'     => $packages,
			'checks'       => array(),
			'sites'        => $this->safe( 'sites', array( $this, 'collect_sites' ) ),
			'cms_specific' => array(
				'multisite'               => is_multisite(),
				'mfa_plugins'             => $this->mfa_detectors,
				// Relative paths (max. 20) of suspicious files - for display only.
				'suspicious_upload_files' => array_slice( null === $suspicious ? array() : $suspicious, 0, 20 ),
				'updates_available'       => $this->safe( 'updates_available', array( $this, 'updates_available' ) ),
			),
		);
		if ( null === $report['sites'] ) {
			$report['sites'] = array();
		}
		foreach ( $checks as $id => $value ) {
			$report['checks'][] = array(
				'id'    => $id,
				'value' => $value,
			);
		}
		if ( array() !== $this->errors ) {
			$report['cms_specific']['collector_errors'] = $this->errors;
		}
		return $report;
	}

	/**
	 * Run one check; on failure remember the error and return null.
	 *
	 * @param string   $name Check name.
	 * @param callable $fn   Callback.
	 * @return mixed
	 */
	private function safe( $name, $fn ) {
		try {
			return call_user_func( $fn );
		} catch ( Throwable $e ) {
			$this->errors[ $name ] = get_class( $e ) . ': ' . mb_substr( $e->getMessage(), 0, 200 );
			return null;
		}
	}

	/**
	 * WordPress version (global set from wp-includes/version.php, not filterable).
	 *
	 * @return string
	 */
	private function wordpress_version() {
		global $wp_version;
		return (string) $wp_version;
	}

	// --- Accounts -------------------------------------------------------------

	/**
	 * Known two-factor plugins that are active.
	 *
	 * @return string[]
	 */
	private function detect_mfa_plugins() {
		$found = array();
		if ( class_exists( 'Two_Factor_Core' ) ) {
			$found[] = 'two-factor';
		}
		if ( class_exists( '\\WordfenceLS\\Controller_Users' ) ) {
			$found[] = 'wordfence-login-security';
		}
		if ( defined( 'WP_2FA_VERSION' ) || class_exists( '\\WP2FA\\WP2FA' ) ) {
			$found[] = 'wp-2fa';
		}
		if ( class_exists( 'ITSEC_Core' ) ) {
			$found[] = 'solid-security';
		}
		return $found;
	}

	/**
	 * Administrators (site administrators plus super admins on multisite).
	 * Only flags leave this method, never names.
	 *
	 * @return list<array{mfa: bool, inactive: bool}>
	 */
	public function load_admins() {
		$ids = get_users(
			array(
				'role'    => 'administrator',
				'fields'  => 'ID',
				'number'  => 1000,
				'blog_id' => get_main_site_id(),
			)
		);
		$ids = array_map( 'intval', (array) $ids );
		if ( is_multisite() ) {
			foreach ( get_super_admins() as $login ) {
				$user = get_user_by( 'login', $login );
				if ( $user ) {
					$ids[] = (int) $user->ID;
				}
			}
		}
		$cutoff = time() - self::INACTIVE_DAYS * DAY_IN_SECONDS;
		$admins = array();
		foreach ( array_unique( $ids ) as $id ) {
			$last     = N9C_Monitor_Activity::last_login( $id );
			$admins[] = array(
				'mfa'      => $this->user_has_mfa( $id ),
				'inactive' => null === $last || $last < $cutoff,
			);
		}
		return $admins;
	}

	/**
	 * Whether a user has a second factor configured in one of the known plugins.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function user_has_mfa( $user_id ) {
		if ( in_array( 'two-factor', $this->mfa_detectors, true ) && method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' ) ) {
			if ( Two_Factor_Core::is_user_using_two_factor( $user_id ) ) {
				return true;
			}
		}
		if ( in_array( 'wordfence-login-security', $this->mfa_detectors, true ) ) {
			$controller = '\\WordfenceLS\\Controller_Users';
			if ( method_exists( $controller, 'shared' ) ) {
				$shared = call_user_func( array( $controller, 'shared' ) );
				$user   = get_user_by( 'id', $user_id );
				if ( $user && is_object( $shared ) && method_exists( $shared, 'has_2fa_active' ) && $shared->has_2fa_active( $user ) ) {
					return true;
				}
			}
		}
		if ( in_array( 'wp-2fa', $this->mfa_detectors, true ) ) {
			$method = get_user_meta( $user_id, 'wp_2fa_enabled_methods', true );
			if ( ! empty( $method ) ) {
				return true;
			}
		}
		if ( array() !== $this->mfa_detectors ) {
			// Two Factor and Solid Security store enabled providers under this key.
			$providers = get_user_meta( $user_id, '_two_factor_enabled_providers', true );
			if ( is_array( $providers ) && array() !== $providers ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an account named "admin" exists.
	 *
	 * @return bool
	 */
	public function has_default_admin_username() {
		return (bool) username_exists( 'admin' );
	}

	// --- Configuration --------------------------------------------------------

	/**
	 * PHP errors shown to visitors (WP_DEBUG with WP_DEBUG_DISPLAY).
	 *
	 * @return bool
	 */
	public function is_debug_output_enabled() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return false;
		}
		if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
			return true;
		}
		if ( null === WP_DEBUG_DISPLAY ) {
			// WordPress leaves display_errors to php.ini.
			$ini = strtolower( (string) ini_get( 'display_errors' ) );
			return in_array( $ini, array( '1', 'on', 'true', 'yes', 'stdout' ), true );
		}
		return (bool) WP_DEBUG_DISPLAY;
	}

	/**
	 * Admin and login only over HTTPS (FORCE_SSL_ADMIN or an https site URL).
	 *
	 * @return bool
	 */
	public function is_backend_https_enforced() {
		if ( force_ssl_admin() ) {
			return true;
		}
		return 'https' === wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_SCHEME );
	}

	/**
	 * Theme and plugin file editor in the admin available.
	 *
	 * @return bool
	 */
	public function is_file_editor_enabled() {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return false;
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return false;
		}
		return true;
	}

	/**
	 * Anyone can register and gets a privileged default role.
	 *
	 * @return bool
	 */
	public function is_open_registration_privileged() {
		if ( is_multisite() ) {
			$open = in_array( get_site_option( 'registration' ), array( 'user', 'all' ), true );
		} else {
			$open = (bool) get_option( 'users_can_register' );
		}
		if ( ! $open ) {
			return false;
		}
		$role = get_role( (string) get_option( 'default_role', 'subscriber' ) );
		if ( ! $role ) {
			return false;
		}
		foreach ( self::PRIVILEGED_CAPS as $cap ) {
			if ( $role->has_cap( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A debug.log exists inside the web root (typically downloadable).
	 *
	 * @return bool
	 */
	public function is_debug_log_public() {
		$candidates = array( WP_CONTENT_DIR . '/debug.log' );
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			$candidates[] = WP_DEBUG_LOG;
		}
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		foreach ( array_unique( $candidates ) as $file ) {
			$real = realpath( $file );
			if ( false === $real || ! is_file( $real ) || 0 === (int) filesize( $real ) ) {
				continue;
			}
			if ( 0 === strpos( wp_normalize_path( $real ), $root . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Automatic (security) core updates switched off.
	 *
	 * @return bool
	 */
	public function are_core_auto_updates_disabled() {
		if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
			return true;
		}
		if ( defined( 'WP_AUTO_UPDATE_CORE' ) && false === WP_AUTO_UPDATE_CORE ) {
			return true;
		}
		/** This filter is documented in wp-admin/includes/class-wp-automatic-updater.php */
		if ( apply_filters( 'automatic_updater_disabled', false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
			return true;
		}
		/** This filter is documented in wp-admin/includes/class-core-upgrader.php */
		return ! apply_filters( 'allow_minor_auto_core_updates', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
	}

	// --- File system ----------------------------------------------------------

	/**
	 * Executable PHP files in the uploads directory.
	 *
	 * @return list<string> paths relative to wp-content/
	 */
	public function find_suspicious_upload_files() {
		$uploads = wp_upload_dir( null, false );
		$root    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( '' === $root || ! is_dir( $root ) ) {
			return array();
		}
		$root    = untrailingslashit( wp_normalize_path( $root ) );
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$prefix  = 0 === strpos( $root, $content . '/' ) ? 'wp-content/' . substr( $root, strlen( $content ) + 1 ) : basename( $root );

		$found    = array();
		$scanned  = 0;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ( $iterator as $file ) {
			if ( ++$scanned > self::MAX_SCANNED_FILES ) {
				$this->errors['fs.php_in_upload_dir'] = 'Scan stopped after ' . self::MAX_SCANNED_FILES . ' files';
				break;
			}
			if ( 0 === $scanned % 500 && time() > $this->scan_deadline ) {
				$this->errors['fs.php_in_upload_dir'] = 'Scan stopped by time budget (' . $scanned . ' files checked)';
				break;
			}
			/** @var SplFileInfo $file */
			if ( $file->isLink() || ! $file->isFile() ) {
				continue;
			}
			if ( ! in_array( strtolower( $file->getExtension() ), self::SUSPICIOUS_EXTENSIONS, true ) ) {
				continue;
			}
			if ( $this->is_placeholder_index( $file ) ) {
				continue;
			}
			$found[] = $prefix . '/' . ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $root ) ), '/' );
		}
		return $found;
	}

	/**
	 * "Silence is golden" index.php files that many plugins put into their
	 * upload folders to prevent directory listings - harmless.
	 *
	 * @param SplFileInfo $file File.
	 * @return bool
	 */
	private function is_placeholder_index( $file ) {
		if ( 'index.php' !== strtolower( $file->getFilename() ) || $file->getSize() > 512 ) {
			return false;
		}
		$code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, read only.
		$code = (string) preg_replace( '#/\*.*?\*/#s', '', $code );
		$code = (string) preg_replace( '#(//|\#)[^\n]*#', '', $code );
		$code = str_replace( array( '<?php', '?>' ), '', $code );
		return '' === trim( $code );
	}

	// --- Operations -----------------------------------------------------------

	/**
	 * Hours the oldest scheduled WP-Cron event is overdue (0 = cron runs).
	 *
	 * @return int|null
	 */
	public function cron_overdue_hours() {
		$crons = _get_cron_array();
		if ( ! is_array( $crons ) || array() === $crons ) {
			return null;
		}
		$oldest  = (int) min( array_keys( $crons ) );
		$overdue = time() - $oldest;
		return $overdue > 0 ? (int) floor( $overdue / HOUR_IN_SECONDS ) : 0;
	}

	/**
	 * Database server and version, e.g. "mariadb 10.11.6".
	 *
	 * @return string|null
	 */
	public function database_version() {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return null;
		}
		$info    = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		$version = method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '';
		if ( false !== stripos( $info, 'mariadb' ) ) {
			$product = 'mariadb';
			if ( preg_match( '/(\d+\.\d+\.\d+)-MariaDB/i', $info, $m ) ) {
				$version = $m[1];
			}
		} elseif ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) {
			$product = 'sqlite';
			$version = class_exists( 'SQLite3' ) ? (string) SQLite3::version()['versionString'] : $version;
		} else {
			$product = 'mysql';
		}
		return mb_substr( trim( $product . ' ' . $version ), 0, 60 );
	}

	// --- Packages & sites -----------------------------------------------------

	/**
	 * Installed plugins (incl. must-use plugins) with version and status.
	 *
	 * @return array{packages: list<array<string, mixed>>, inactive: int}
	 */
	public function plugin_inventory() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$packages = array();
		$inactive = 0;
		foreach ( get_plugins() as $file => $data ) {
			$is_active = in_array( $file, $active, true );
			if ( ! $is_active ) {
				++$inactive;
			}
			$version = isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';
			if ( '' === $version ) {
				continue;
			}
			$packages[] = array(
				'ecosystem' => 'wordpress-plugin',
				'name'      => self::plugin_slug( $file ),
				'version'   => mb_substr( ltrim( $version, 'vV' ), 0, 80 ),
				'active'    => $is_active,
			);
		}
		foreach ( get_mu_plugins() as $file => $data ) {
			$version = isset( $data['Version'] ) ? trim( (string) $data['Version'] ) : '';
			if ( '' === $version ) {
				continue;
			}
			$packages[] = array(
				'ecosystem' => 'wordpress-plugin',
				'name'      => self::plugin_slug( $file ),
				'version'   => mb_substr( ltrim( $version, 'vV' ), 0, 80 ),
				'active'    => true,
				'mu'        => true,
			);
		}
		return array(
			'packages' => $packages,
			'inactive' => $inactive,
		);
	}

	/**
	 * Slug as used on wordpress.org: folder name, or file name for single-file plugins.
	 *
	 * @param string $file Plugin file relative to the plugins directory.
	 * @return string
	 */
	public static function plugin_slug( $file ) {
		$file = wp_normalize_path( $file );
		if ( 'hello.php' === $file ) {
			return 'hello-dolly';
		}
		$dir = dirname( $file );
		$slug = '.' === $dir ? basename( $file, '.php' ) : strtok( $dir, '/' );
		return strtolower( (string) $slug );
	}

	/**
	 * Installed themes.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function collect_themes() {
		$active   = array_unique( array( get_stylesheet(), get_template() ) );
		$packages = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$version = trim( (string) $theme->get( 'Version' ) );
			if ( '' === $version ) {
				continue;
			}
			$packages[] = array(
				'ecosystem' => 'wordpress-theme',
				'name'      => strtolower( (string) $stylesheet ),
				'version'   => mb_substr( ltrim( $version, 'vV' ), 0, 80 ),
				'active'    => in_array( $stylesheet, $active, true ),
			);
		}
		return $packages;
	}

	/**
	 * Updates WordPress itself already knows about (incl. commercial plugins
	 * with their own update server). Slugs and versions only.
	 *
	 * @return array<string, mixed>
	 */
	public function updates_available() {
		$result = array(
			'core'    => null,
			'plugins' => array(),
			'themes'  => array(),
		);
		$core = get_site_transient( 'update_core' );
		if ( is_object( $core ) && ! empty( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $update ) {
				if ( is_object( $update ) && isset( $update->response, $update->current ) && 'upgrade' === $update->response ) {
					$result['core'] = (string) $update->current;
					break;
				}
			}
		}
		$plugins = get_site_transient( 'update_plugins' );
		if ( is_object( $plugins ) && ! empty( $plugins->response ) && is_array( $plugins->response ) ) {
			foreach ( $plugins->response as $file => $update ) {
				if ( is_object( $update ) && isset( $update->new_version ) ) {
					$result['plugins'][ self::plugin_slug( (string) $file ) ] = (string) $update->new_version;
				}
			}
		}
		$themes = get_site_transient( 'update_themes' );
		if ( is_object( $themes ) && ! empty( $themes->response ) && is_array( $themes->response ) ) {
			foreach ( $themes->response as $stylesheet => $update ) {
				if ( is_array( $update ) && isset( $update['new_version'] ) ) {
					$result['themes'][ strtolower( (string) $stylesheet ) ] = (string) $update['new_version'];
				}
			}
		}
		$checked = is_object( $plugins ) && isset( $plugins->last_checked ) ? (int) $plugins->last_checked : 0;
		$result['checked_at'] = $checked > 0 ? gmdate( 'c', $checked ) : null;
		return $result;
	}

	/**
	 * Public URLs of this installation (all sites on multisite, max. 200).
	 *
	 * @return list<string>
	 */
	public function collect_sites() {
		$urls = array( home_url( '/' ), site_url( '/' ) );
		if ( is_multisite() ) {
			$ids = get_sites(
				array(
					'fields'   => 'ids',
					'number'   => 200,
					'archived' => 0,
					'deleted'  => 0,
					'spam'     => 0,
				)
			);
			foreach ( $ids as $id ) {
				$urls[] = get_home_url( (int) $id, '/' );
			}
		}
		$sites = array();
		$seen  = array();
		foreach ( $urls as $url ) {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( isset( $parts['scheme'] ) ? $parts['scheme'] : '', array( 'http', 'https' ), true ) ) {
				continue;
			}
			// One entry per origin is enough for the outside scans.
			$origin = $parts['scheme'] . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
			if ( isset( $seen[ $origin ] ) ) {
				continue;
			}
			$seen[ $origin ] = true;
			$sites[]         = mb_substr( trailingslashit( $url ), 0, 255 );
		}
		return array_slice( $sites, 0, 200 );
	}
}
