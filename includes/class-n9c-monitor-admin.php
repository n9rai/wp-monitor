<?php
/**
 * Admin page: status, last result with open findings, data preview,
 * connect / reconnect / disconnect, send now.
 *
 * Single site: Tools > N9C Inside Monitor. Multisite: Network Admin >
 * Settings > N9C Inside Monitor (one connection per network).
 *
 * @package N9C_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI.
 */
final class N9C_Monitor_Admin {

	const PAGE   = 'n9c-monitor';
	const ACTION = 'n9c_monitor';
	const NONCE  = 'n9c_monitor_action';

	/**
	 * Hook suffix of the page.
	 *
	 * @var string|false
	 */
	private static $hook = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( 'N9C_Monitor_Reporter', 'ensure_scheduled' ) );
		$file = plugin_basename( N9C_MONITOR_FILE );
		add_filter( 'plugin_action_links_' . $file, array( __CLASS__, 'action_links' ) );
		add_filter( 'network_admin_plugin_action_links_' . $file, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Required capability.
	 *
	 * @return string
	 */
	public static function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * URL of the page.
	 *
	 * @param array<string, string> $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'tools.php' );
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), $base );
	}

	/**
	 * Add the menu entry.
	 *
	 * @return void
	 */
	public static function menu() {
		$title = __( 'N9C Inside Monitor', 'n9c-monitor' );
		if ( is_multisite() ) {
			self::$hook = add_submenu_page( 'settings.php', $title, $title, self::capability(), self::PAGE, array( __CLASS__, 'render' ) );
		} else {
			self::$hook = add_management_page( $title, $title, self::capability(), self::PAGE, array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * Stylesheet for the page only.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === self::$hook || $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'n9c-monitor-admin', plugins_url( 'assets/admin.css', N9C_MONITOR_FILE ), array(), N9C_MONITOR_VERSION );
	}

	/**
	 * "Settings" link in the plugin list.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'n9c-monitor' ) . '</a>' );
		return $links;
	}

	/**
	 * Form submissions (admin-post.php).
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'n9c-monitor' ), 403 );
		}
		check_admin_referer( self::NONCE );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		switch ( $do ) {
			case 'connect':
				$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
				$force  = ! empty( $_POST['force'] );
				$result = N9C_Monitor_Reporter::connect( $code, $force );
				self::notice( $result['ok'] ? 'success' : 'error', $result['message'] );
				break;

			case 'send':
				if ( N9C_Monitor_Config::has_moved() ) {
					self::notice( 'error', __( 'Please resolve the address change first (see below).', 'n9c-monitor' ) );
					break;
				}
				$result = N9C_Monitor_Reporter::send( 'manual', true );
				if ( is_wp_error( $result ) ) {
					self::notice( 'error', $result->get_error_message() );
				} elseif ( 200 === (int) $result['status'] ) {
					self::notice( 'success', __( 'Report sent and evaluated.', 'n9c-monitor' ) );
				} elseif ( in_array( (int) $result['status'], array( 409, 429 ), true ) ) {
					self::notice( 'error', __( 'A report was sent less than a minute ago. Please try again shortly.', 'n9c-monitor' ) );
				} else {
					/* translators: %d: HTTP status code */
					self::notice( 'error', sprintf( __( 'The report was not accepted (HTTP %d).', 'n9c-monitor' ), (int) $result['status'] ) );
				}
				break;

			case 'confirm_move':
				N9C_Monitor_Config::confirm_move();
				N9C_Monitor_Reporter::ensure_scheduled();
				self::notice( 'success', __( 'Move confirmed. This installation keeps reporting as the same instance.', 'n9c-monitor' ) );
				break;

			case 'disconnect':
				N9C_Monitor_Reporter::disconnect();
				self::notice( 'success', __( 'Disconnected. The local credentials have been removed; the instance remains in your N9C dashboard.', 'n9c-monitor' ) );
				break;
		}

		// Own admin URL (not user input); wp_safe_redirect() would reject it when
		// the home and site URL hosts differ, e.g. right after a site move.
		wp_redirect( self::url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Queue a notice for the next page view of the current user.
	 *
	 * @param string $type    success | error.
	 * @param string $message Text.
	 * @return void
	 */
	private static function notice( $type, $message ) {
		set_transient(
			'n9c_monitor_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			120
		);
	}

	/**
	 * Hidden fields + nonce for a form.
	 *
	 * @param string $do Action.
	 * @return void
	 */
	private static function form_fields( $do ) {
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( self::NONCE );
	}

	/**
	 * Format a timestamp in site time.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private static function date( $timestamp ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.

		echo '<div class="wrap n9c-monitor">';
		echo '<h1>' . esc_html__( 'N9C Inside Monitor', 'n9c-monitor' ) . '</h1>';

		$notice = get_transient( 'n9c_monitor_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'n9c_monitor_notice_' . get_current_user_id() );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( 'success' === $notice['type'] ? 'success' : 'error' ),
				esc_html( (string) $notice['message'] )
			);
		}

		if ( 'preview' === $view ) {
			self::render_preview();
		} else {
			self::render_main();
		}
		echo '</div>';
	}

	/**
	 * Data preview: exactly the JSON that is sent.
	 *
	 * @return void
	 */
	private static function render_preview() {
		$report                            = N9C_Monitor_Reporter::build_report( true );
		$report['cms_specific']['trigger'] = 'manual';
		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'Back', 'n9c-monitor' ) . '</a></p>';
		echo '<h2>' . esc_html__( 'Data preview', 'n9c-monitor' ) . '</h2>';
		echo '<p>' . esc_html__( 'This is exactly what is sent to the N9C Inside Monitor with every report. Nothing has been sent by opening this page.', 'n9c-monitor' ) . '</p>';
		echo '<pre class="n9c-monitor-json">' . esc_html( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}

	/**
	 * Main view.
	 *
	 * @return void
	 */
	private static function render_main() {
		$config    = N9C_Monitor_Config::load();
		$connected = ! empty( $config['instance_id'] ) && ! empty( $config['secret'] );
		$links     = N9C_Monitor_Config::dashboard_links();
		$last      = N9C_Monitor_Reporter::last_result();

		echo '<p class="description">';
		esc_html_e( 'Reports security-relevant metrics of this installation to the N9C monitoring service – numbers and flags only, no names, e-mail addresses or content.', 'n9c-monitor' );
		echo ' <a href="' . esc_url( self::url( array( 'view' => 'preview' ) ) ) . '">' . esc_html__( 'See exactly what is sent →', 'n9c-monitor' ) . '</a></p>';

		if ( ! $connected ) {
			self::render_connect( $links );
			return;
		}

		if ( N9C_Monitor_Config::has_moved() ) {
			self::render_moved( $config );
		}

		$plan = is_array( $last ) && is_array( $last['plan'] ) ? $last['plan'] : null;
		echo '<p class="n9c-monitor-actions">';
		if ( $links['instance'] ) {
			echo '<a class="button" href="' . esc_url( $links['instance'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open in the N9C dashboard', 'n9c-monitor' ) . '</a> ';
		}
		if ( $plan && ! empty( $plan['label'] ) ) {
			echo '<span>' . esc_html__( 'Plan:', 'n9c-monitor' ) . ' <strong>' . esc_html( (string) $plan['label'] ) . '</strong>';
			if ( ! empty( $plan['until'] ) ) {
				$until = strtotime( (string) $plan['until'] );
				if ( $until ) {
					/* translators: %s: date */
					echo ' ' . esc_html( sprintf( __( 'until %s', 'n9c-monitor' ), wp_date( get_option( 'date_format' ), $until ) ) );
				}
			}
			echo '</span>';
			if ( empty( $plan['full'] ) ) {
				echo ' &middot; <a href="' . esc_url( $links['billing'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'History, e-mail alerts and outside scans with the subscription', 'n9c-monitor' ) . '</a>';
			}
		}
		echo '</p>';

		if ( is_array( $last ) && 402 === (int) $last['status'] ) {
			echo '<div class="notice notice-warning inline"><p>';
			esc_html_e( 'This installation is paused because the quota of your N9C account is used up.', 'n9c-monitor' );
			echo ' <a href="' . esc_url( $links['billing'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Add installations in the dashboard →', 'n9c-monitor' ) . '</a></p></div>';
		}

		self::render_result( $last );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="n9c-monitor-send">';
		self::form_fields( 'send' );
		submit_button( __( 'Send report now', 'n9c-monitor' ), 'primary', 'submit', false );
		echo '</form>';

		self::render_connection( $config );
	}

	/**
	 * Score, counts and open findings of the last report.
	 *
	 * @param array<string, mixed>|null $last Last result.
	 * @return void
	 */
	private static function render_result( $last ) {
		echo '<h2>' . esc_html__( 'Latest evaluation', 'n9c-monitor' ) . '</h2>';
		if ( ! is_array( $last ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No report sent yet.', 'n9c-monitor' ) . '</p></div>';
			return;
		}
		$status = (int) $last['status'];
		if ( 200 === $status ) {
			$counts = is_array( $last['counts'] ) ? $last['counts'] : array();
			$tiles  = array(
				array( (string) $last['score'], __( 'Score', 'n9c-monitor' ), '' ),
				array( isset( $counts['critical'] ) ? (string) $counts['critical'] : '–', __( 'critical', 'n9c-monitor' ), 'critical' ),
				array( isset( $counts['medium'] ) ? (string) $counts['medium'] : '–', __( 'medium', 'n9c-monitor' ), 'medium' ),
				array( isset( $counts['ok'] ) ? (string) $counts['ok'] : '–', __( 'passed', 'n9c-monitor' ), 'ok' ),
			);
			echo '<div class="n9c-monitor-tiles">';
			foreach ( $tiles as $tile ) {
				printf(
					'<div class="n9c-monitor-tile %1$s"><div class="n9c-monitor-value">%2$s</div><div>%3$s</div></div>',
					esc_attr( $tile[2] ),
					esc_html( $tile[0] ),
					esc_html( $tile[1] )
				);
			}
			echo '</div>';
		} elseif ( 402 !== $status ) {
			echo '<div class="notice notice-error inline"><p>';
			if ( 0 === $status ) {
				esc_html_e( 'The N9C service could not be reached:', 'n9c-monitor' );
			} else {
				/* translators: %d: HTTP status code */
				echo esc_html( sprintf( __( 'The last report was not accepted (HTTP %d):', 'n9c-monitor' ), $status ) );
			}
			echo ' ' . esc_html( (string) $last['detail'] );
			if ( 401 === $status ) {
				echo '<br>' . esc_html__( 'Please check the connection and the server time (NTP).', 'n9c-monitor' );
			}
			echo '</p></div>';
		}

		$triggers = array(
			'auto'    => __( 'automatic', 'n9c-monitor' ),
			'manual'  => __( 'manual', 'n9c-monitor' ),
			'cli'     => __( 'WP-CLI', 'n9c-monitor' ),
			'connect' => __( 'on connecting', 'n9c-monitor' ),
		);
		$trigger = isset( $triggers[ $last['trigger'] ] ) ? $triggers[ $last['trigger'] ] : (string) $last['trigger'];
		echo '<p>';
		/* translators: 1: date and time, 2: trigger */
		echo esc_html( sprintf( __( 'As of %1$s (%2$s)', 'n9c-monitor' ), self::date( (int) $last['time'] ), $trigger ) );
		if ( is_array( $last['delta'] ) && isset( $last['delta']['new'], $last['delta']['resolved'] ) ) {
			/* translators: 1: number of new findings, 2: number of resolved findings */
			echo ' &middot; ' . esc_html( sprintf( __( 'since the previous report: %1$d new, %2$d resolved', 'n9c-monitor' ), (int) $last['delta']['new'], (int) $last['delta']['resolved'] ) );
		}
		echo '</p>';

		if ( 200 !== $status || ! is_array( $last['findings'] ) ) {
			return;
		}
		if ( array() === $last['findings'] ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'No open findings.', 'n9c-monitor' ) . '</p></div>';
			return;
		}
		echo '<h2>' . esc_html__( 'Open findings', 'n9c-monitor' ) . '</h2>';
		echo '<table class="widefat striped n9c-monitor-findings"><thead><tr>';
		echo '<th>' . esc_html__( 'Severity', 'n9c-monitor' ) . '</th><th>' . esc_html__( 'Finding', 'n9c-monitor' ) . '</th><th>' . esc_html__( 'Recommendation', 'n9c-monitor' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $last['findings'] as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$critical = isset( $finding['severity'] ) && 'critical' === $finding['severity'];
			echo '<tr><td><span class="n9c-monitor-badge ' . esc_attr( $critical ? 'critical' : 'medium' ) . '">';
			echo esc_html( $critical ? __( 'critical', 'n9c-monitor' ) : __( 'medium', 'n9c-monitor' ) );
			echo '</span></td><td><strong>' . esc_html( isset( $finding['problem'] ) ? (string) $finding['problem'] : '' ) . '</strong>';
			if ( ! empty( $finding['risk'] ) ) {
				echo '<br><span class="description">' . esc_html( (string) $finding['risk'] ) . '</span>';
			}
			echo '</td><td>' . esc_html( isset( $finding['recommendation'] ) ? (string) $finding['recommendation'] : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Connection details, reconnect and disconnect.
	 *
	 * @param array<string, mixed> $config Configuration.
	 * @return void
	 */
	private static function render_connection( array $config ) {
		$next    = wp_next_scheduled( N9C_Monitor_Reporter::CRON_HOOK );
		$sources = array(
			'constant' => __( 'constants in wp-config.php', 'n9c-monitor' ),
			'env'      => __( 'environment variables', 'n9c-monitor' ),
			'option'   => __( 'database (wp_options)', 'n9c-monitor' ),
		);
		$rows = array(
			__( 'Status', 'n9c-monitor' )           => '<span class="n9c-monitor-badge ok">' . esc_html__( 'connected', 'n9c-monitor' ) . '</span>',
			__( 'Instance ID', 'n9c-monitor' )      => '<code>' . esc_html( (string) $config['instance_id'] ) . '</code>',
			__( 'Service', 'n9c-monitor' )          => esc_html( (string) $config['endpoint'] ),
			__( 'Credentials from', 'n9c-monitor' ) => esc_html( isset( $sources[ $config['source'] ] ) ? $sources[ $config['source'] ] : (string) $config['source'] ),
			__( 'Automatic report', 'n9c-monitor' ) => esc_html(
				N9C_Monitor_Reporter::auto_enabled()
					/* translators: %d: number of hours */
					? sprintf( __( 'on, every %d hours via WP-Cron', 'n9c-monitor' ), (int) round( N9C_Monitor_Reporter::interval() / HOUR_IN_SECONDS ) )
					: __( 'off (N9C_MONITOR_AUTO_REPORT), WP-CLI only', 'n9c-monitor' )
			),
			__( 'Next report', 'n9c-monitor' )      => esc_html( $next ? self::date( (int) $next ) : '–' ),
			__( 'Plugin', 'n9c-monitor' )           => esc_html( 'n9c-monitor ' . N9C_MONITOR_VERSION ),
		);
		echo '<h2>' . esc_html__( 'Connection', 'n9c-monitor' ) . '</h2>';
		echo '<table class="widefat striped n9c-monitor-connection"><tbody>';
		foreach ( $rows as $label => $html ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $html ) . '</td></tr>';
		}
		echo '</tbody></table>';

		if ( 'option' !== $config['source'] ) {
			return;
		}
		echo '<details class="n9c-monitor-details"><summary>' . esc_html__( 'Reconnect', 'n9c-monitor' ) . '</summary>';
		echo '<p>' . esc_html__( 'Only needed if N9C asks you to. The instance and its history are kept.', 'n9c-monitor' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'connect' );
		echo '<input type="hidden" name="force" value="1">';
		echo '<input type="text" name="code" class="regular-text code" placeholder="n9c-enroll-…" autocomplete="off" required> ';
		submit_button( __( 'Reconnect', 'n9c-monitor' ), 'secondary', 'submit', false );
		echo '</form></details>';

		echo '<details class="n9c-monitor-details"><summary>' . esc_html__( 'Disconnect', 'n9c-monitor' ) . '</summary>';
		echo '<p>' . esc_html__( 'Removes the credentials from this installation. No more reports are sent. The instance and its history remain in your N9C dashboard, where you can delete it.', 'n9c-monitor' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'disconnect' );
		submit_button( __( 'Disconnect', 'n9c-monitor' ), 'delete', 'submit', false );
		echo '</form></details>';
	}

	/**
	 * The site URL changed since connecting: copy or move?
	 *
	 * @param array<string, mixed> $config Configuration.
	 * @return void
	 */
	private static function render_moved( array $config ) {
		echo '<div class="notice notice-warning inline n9c-monitor-moved"><p><strong>' . esc_html__( 'The address of this installation has changed.', 'n9c-monitor' ) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: 1: previous address, 2: current address */
				__( 'It was connected as %1$s and now runs as %2$s. Automatic reports are paused so that a copy (e.g. staging) does not report as the live site.', 'n9c-monitor' ),
				(string) $config['bound_url'],
				N9C_Monitor_Config::fingerprint()
			)
		);
		echo '</p><div class="n9c-monitor-moved-actions">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'confirm_move' );
		submit_button( __( 'The site has moved – keep reporting', 'n9c-monitor' ), 'primary', 'submit', false );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'disconnect' );
		submit_button( __( 'This is a copy – disconnect', 'n9c-monitor' ), 'secondary', 'submit', false );
		echo '</form></div></div>';
	}

	/**
	 * Not connected: three steps + code form.
	 *
	 * @param array<string, ?string> $links Dashboard links.
	 * @return void
	 */
	private static function render_connect( array $links ) {
		echo '<h2>' . esc_html__( 'Not connected yet', 'n9c-monitor' ) . '</h2>';
		echo '<div class="card n9c-monitor-card"><p><strong>' . esc_html__( 'Three steps to your first evaluation:', 'n9c-monitor' ) . '</strong></p><ol>';
		echo '<li><a href="' . esc_url( (string) $links['register'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Create a free account in the N9C dashboard', 'n9c-monitor' ) . '</a> – ' . esc_html__( 'full scope for 14 days, then free forever for one installation.', 'n9c-monitor' ) . '</li>';
		echo '<li>' . esc_html__( 'In the dashboard, create a connection code for this installation.', 'n9c-monitor' ) . '</li>';
		echo '<li>' . esc_html__( 'Enter the code below and click "Connect" – the first evaluation appears right away.', 'n9c-monitor' ) . '</li>';
		echo '</ol><p>';
		echo '<a class="button button-primary" href="' . esc_url( (string) $links['register'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Create account', 'n9c-monitor' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( (string) $links['new_code'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'I have an account – create a code', 'n9c-monitor' ) . '</a>';
		echo '</p></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="n9c-monitor-connect">';
		self::form_fields( 'connect' );
		echo '<p><label for="n9c-monitor-code">' . esc_html__( 'Connection code (single use, valid for 72 hours):', 'n9c-monitor' ) . '</label><br>';
		echo '<input type="text" id="n9c-monitor-code" name="code" class="regular-text code" placeholder="n9c-enroll-…" autocomplete="off" required> ';
		submit_button( __( 'Connect', 'n9c-monitor' ), 'primary', 'submit', false );
		echo '</p></form>';
		echo '<p class="description">' . esc_html__( 'Nothing is sent to N9C before you connect.', 'n9c-monitor' ) . ' ';
		echo '<a href="' . esc_url( self::url( array( 'view' => 'preview' ) ) ) . '">' . esc_html__( 'Data preview', 'n9c-monitor' ) . '</a></p>';
	}
}
