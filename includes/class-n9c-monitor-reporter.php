<?php
/**
 * Connect, build + send reports, remember the last result, WP-Cron schedule.
 * Shared by the admin page, WP-CLI and the scheduled event.
 *
 * @package N9C_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reporting service.
 */
final class N9C_Monitor_Reporter {

	const CRON_HOOK          = 'n9c_monitor_report';
	const RETRY_HOOK         = 'n9c_monitor_retry';
	const SCHEDULE           = 'n9c_monitor_interval';
	const LAST_RESULT_OPTION = 'n9c_monitor_last_result';
	const DEFAULT_INTERVAL   = 21600; // 6 hours, like the TYPO3 extension.
	const RETRY_AFTER        = 1800;

	/**
	 * Register cron hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- interval is at least one hour.
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_run' ) );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'cron_run' ) );
	}

	/**
	 * Report interval in seconds (filter "n9c_monitor_report_interval", minimum one hour).
	 *
	 * @return int
	 */
	public static function interval() {
		/**
		 * Filters the interval of the automatic report in seconds.
		 *
		 * @param int $seconds Default 21600 (6 hours). Minimum 3600.
		 */
		return max( HOUR_IN_SECONDS, (int) apply_filters( 'n9c_monitor_report_interval', self::DEFAULT_INTERVAL ) );
	}

	/**
	 * Automatic report enabled (constant N9C_MONITOR_AUTO_REPORT = false disables it).
	 *
	 * @return bool
	 */
	public static function auto_enabled() {
		return ! defined( 'N9C_MONITOR_AUTO_REPORT' ) || (bool) N9C_MONITOR_AUTO_REPORT;
	}

	/**
	 * Custom WP-Cron schedule.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_schedule( $schedules ) {
		$hours = (int) round( self::interval() / HOUR_IN_SECONDS );
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::interval(),
			/* translators: %d: number of hours */
			'display'  => did_action( 'init' ) ? sprintf( __( 'N9C Inside Monitor (every %d hours)', 'n9c-monitor' ), $hours ) : 'N9C Inside Monitor',
		);
		return $schedules;
	}

	/**
	 * Make sure the recurring event exists while connected.
	 *
	 * @return void
	 */
	public static function ensure_scheduled() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			if ( ! self::auto_enabled() ) {
				self::unschedule();
			}
			return;
		}
		if ( self::auto_enabled() && N9C_Monitor_Config::is_connected() ) {
			wp_schedule_event( time() + self::interval(), self::SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Remove all scheduled events of this plugin.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::RETRY_HOOK );
	}

	/**
	 * Scheduled report (WP-Cron runs it in a background request).
	 *
	 * @return void
	 */
	public static function cron_run() {
		if ( ! self::auto_enabled() || ! N9C_Monitor_Config::is_connected() || N9C_Monitor_Config::has_moved() ) {
			return;
		}
		$result = self::send( 'auto' );
		$status = is_wp_error( $result ) ? 0 : (int) $result['status'];
		// Network problems or server errors: try again in 30 minutes instead of waiting 6 hours.
		if ( ( 0 === $status || $status >= 500 ) && ! wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_single_event( time() + self::RETRY_AFTER, self::RETRY_HOOK );
		}
	}

	/**
	 * Exchange a connection code for credentials and send the first report.
	 *
	 * @param string $code  Connection code (n9c-enroll-...).
	 * @param bool   $force Reconnect an already connected installation.
	 * @return array{ok: bool, message: string, instance_id?: string, reused?: bool}
	 */
	public static function connect( $code, $force = false ) {
		$code = trim( (string) $code );
		if ( ! preg_match( '/^n9c-enroll-[0-9a-f]{32}$/', $code ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The connection code does not have the expected format (n9c-enroll- followed by 32 characters).', 'n9c-monitor' ),
			);
		}
		$config = N9C_Monitor_Config::load();
		if ( in_array( $config['source'], array( 'constant', 'env' ), true ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'The connection is defined by N9C_MONITOR_* constants or environment variables. Please change it there.', 'n9c-monitor' ),
			);
		}
		$connected = ! empty( $config['instance_id'] ) && ! empty( $config['secret'] );
		if ( $connected && ! $force ) {
			return array(
				'ok'      => false,
				'message' => __( 'This installation is already connected. Use "Reconnect" (or --force) to connect it again.', 'n9c-monitor' ),
			);
		}

		$collector = new N9C_Monitor_Collector();
		$report    = $collector->collect( true );
		$payload   = array(
			'token' => $code,
			'agent' => $report['agent'],
			'cms'   => array(
				'type'    => 'wordpress',
				'version' => $report['cms']['version'],
			),
			'sites' => $report['sites'],
		);
		// A moved or cloned site must not take over the instance of the original.
		if ( $connected && ! N9C_Monitor_Config::has_moved() ) {
			$payload['previous_instance_id'] = $config['instance_id'];
			$payload['previous_proof']       = N9C_Monitor_Client::rebind_proof( (string) $config['secret'], $code );
		}

		$client = new N9C_Monitor_Client();
		$result = $client->enroll( $config['endpoint'], $payload );
		if ( is_wp_error( $result ) ) {
			return array(
				'ok'      => false,
				/* translators: 1: API URL, 2: error message */
				'message' => sprintf( __( 'Could not reach %1$s: %2$s', 'n9c-monitor' ), $config['endpoint'], $result->get_error_message() ),
			);
		}
		if ( 200 !== $result['status'] || empty( $result['data']['instance_id'] ) || empty( $result['data']['secret'] ) ) {
			$detail = isset( $result['data']['detail'] ) ? $result['data']['detail'] : $result['data'];
			return array(
				'ok'      => false,
				/* translators: 1: HTTP status code, 2: error details */
				'message' => sprintf( __( 'Connection refused (HTTP %1$d): %2$s', 'n9c-monitor' ), $result['status'], is_string( $detail ) ? $detail : wp_json_encode( $detail ) ),
			);
		}

		N9C_Monitor_Config::save( (string) $result['data']['instance_id'], (string) $result['data']['secret'], $config['endpoint'] );
		self::unschedule();
		self::ensure_scheduled();

		$reused  = ! empty( $result['data']['reused'] );
		$message = $reused
			? __( 'Reconnected (existing instance, history is kept).', 'n9c-monitor' )
			: __( 'Connected.', 'n9c-monitor' );

		$first = self::send( 'connect', true );
		if ( is_wp_error( $first ) || 200 !== (int) $first['status'] ) {
			$message .= ' ' . __( 'The first report could not be delivered yet; it will be retried automatically.', 'n9c-monitor' );
		} else {
			$message .= ' ' . __( 'First report delivered.', 'n9c-monitor' );
		}

		return array(
			'ok'          => true,
			'instance_id' => (string) $result['data']['instance_id'],
			'reused'      => $reused,
			'message'     => $message,
		);
	}

	/**
	 * Forget the local credentials (the instance in the N9C dashboard remains).
	 *
	 * @return void
	 */
	public static function disconnect() {
		N9C_Monitor_Config::clear();
		self::unschedule();
		delete_site_option( self::LAST_RESULT_OPTION );
	}

	/**
	 * Build the report.
	 *
	 * @param bool $quick Short time budget for the file scan.
	 * @return array<string, mixed>
	 */
	public static function build_report( $quick = false ) {
		$collector = new N9C_Monitor_Collector();
		return $collector->collect( $quick );
	}

	/**
	 * Build, sign and send a report and remember the result.
	 *
	 * @param string $trigger auto | manual | cli | connect.
	 * @param bool   $quick   Short time budget for the file scan.
	 * @return array{status: int, data: array<string, mixed>}|WP_Error
	 */
	public static function send( $trigger = 'manual', $quick = false ) {
		$config = N9C_Monitor_Config::load();
		if ( empty( $config['instance_id'] ) || empty( $config['secret'] ) ) {
			return new WP_Error( 'n9c_monitor_not_connected', __( 'Not connected yet. Enter a connection code first.', 'n9c-monitor' ) );
		}
		$report                            = self::build_report( $quick );
		$report['cms_specific']['trigger'] = $trigger;
		$body                              = (string) wp_json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$client = new N9C_Monitor_Client();
		$result = $client->send_report( $config['endpoint'], (string) $config['instance_id'], (string) $config['secret'], $body );
		if ( is_wp_error( $result ) ) {
			self::remember( 0, array( 'detail' => $result->get_error_message() ), $trigger );
			return $result;
		}
		$previous = self::last_result();
		// 409/429: the service has a very recent report already - keep showing its evaluation.
		if ( ! in_array( (int) $result['status'], array( 409, 429 ), true ) || ! is_array( $previous ) || 200 !== (int) $previous['status'] ) {
			self::remember( (int) $result['status'], $result['data'], $trigger );
		}
		return $result;
	}

	/**
	 * Last stored result.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last_result() {
		$last = get_site_option( self::LAST_RESULT_OPTION, null );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * Store the outcome of a report.
	 *
	 * @param int                  $status  HTTP status (0 = not reached).
	 * @param array<string, mixed> $data    Response body.
	 * @param string               $trigger Trigger.
	 * @return void
	 */
	private static function remember( $status, array $data, $trigger ) {
		$detail = isset( $data['detail'] ) ? $data['detail'] : null;
		$plan   = isset( $data['plan'] ) && is_array( $data['plan'] ) ? array_intersect_key( $data['plan'], array_flip( array( 'tier', 'label', 'until', 'full' ) ) ) : null;
		$value  = array(
			'time'         => time(),
			'trigger'      => $trigger,
			'status'       => $status,
			'score'        => isset( $data['score'] ) ? $data['score'] : null,
			'score_status' => isset( $data['score_status'] ) ? $data['score_status'] : null,
			'counts'       => isset( $data['counts'] ) && is_array( $data['counts'] ) ? $data['counts'] : null,
			'delta'        => isset( $data['delta'] ) && is_array( $data['delta'] ) ? $data['delta'] : null,
			'detail'       => null === $detail ? null : mb_substr( is_string( $detail ) ? $detail : (string) wp_json_encode( $detail ), 0, 500 ),
			'findings'     => isset( $data['findings'] ) && is_array( $data['findings'] ) ? array_slice( $data['findings'], 0, 50 ) : null,
			'plan'         => $plan,
		);
		if ( false === get_site_option( self::LAST_RESULT_OPTION, false ) ) {
			add_site_option( self::LAST_RESULT_OPTION, $value );
		} else {
			update_site_option( self::LAST_RESULT_OPTION, $value );
		}
	}
}
