<?php
/**
 * WP-CLI commands: wp n9c-monitor connect|report|status|disconnect
 *
 * @package N9C_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects this installation to the N9C Inside Monitor and sends reports.
 */
final class N9C_Monitor_CLI {

	/**
	 * Exchange a connection code for credentials and send the first report.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Connection code from the N9C dashboard (n9c-enroll-...).
	 *
	 * [--force]
	 * : Reconnect an already connected installation (instance and history are kept).
	 *
	 * ## EXAMPLES
	 *
	 *     wp n9c-monitor connect n9c-enroll-0123456789abcdef0123456789abcdef
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function connect( $args, $assoc_args ) {
		$result = N9C_Monitor_Reporter::connect( $args[0], ! empty( $assoc_args['force'] ) );
		if ( ! $result['ok'] ) {
			WP_CLI::error( $result['message'] );
		}
		WP_CLI::success( $result['message'] . ' Instance: ' . $result['instance_id'] );
	}

	/**
	 * Send a report now, or show it without sending.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Print the JSON that would be sent; sends nothing.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function report( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$report                            = N9C_Monitor_Reporter::build_report( false );
			$report['cms_specific']['trigger'] = 'cli';
			WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		if ( N9C_Monitor_Config::has_moved() ) {
			WP_CLI::error( 'The site address changed since connecting. Confirm the move or disconnect in the admin page (Tools > N9C Inside Monitor).' );
		}
		$result = N9C_Monitor_Reporter::send( 'cli' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( 200 !== (int) $result['status'] ) {
			$detail = isset( $result['data']['detail'] ) ? $result['data']['detail'] : $result['data'];
			WP_CLI::error( 'HTTP ' . (int) $result['status'] . ': ' . ( is_string( $detail ) ? $detail : wp_json_encode( $detail ) ) );
		}
		$data   = $result['data'];
		$counts = isset( $data['counts'] ) && is_array( $data['counts'] ) ? $data['counts'] : array();
		WP_CLI::success(
			sprintf(
				'Score %s (%s) - critical %d, medium %d, ok %d',
				isset( $data['score'] ) ? (string) $data['score'] : '?',
				isset( $data['score_status'] ) ? (string) $data['score_status'] : '?',
				isset( $counts['critical'] ) ? (int) $counts['critical'] : 0,
				isset( $counts['medium'] ) ? (int) $counts['medium'] : 0,
				isset( $counts['ok'] ) ? (int) $counts['ok'] : 0
			)
		);
	}

	/**
	 * Connection and result of the last report.
	 *
	 * @return void
	 */
	public function status() {
		$config = N9C_Monitor_Config::load();
		$links  = N9C_Monitor_Config::dashboard_links();
		if ( empty( $config['instance_id'] ) || empty( $config['secret'] ) ) {
			WP_CLI::line( 'Not connected. Free account: ' . $links['register'] );
			return;
		}
		$next = wp_next_scheduled( N9C_Monitor_Reporter::CRON_HOOK );
		WP_CLI::line( 'Instance:     ' . $config['instance_id'] . ' (' . $config['source'] . ')' );
		WP_CLI::line( 'Service:      ' . $config['endpoint'] );
		WP_CLI::line( 'Dashboard:    ' . $links['instance'] );
		WP_CLI::line( 'Auto report:  ' . ( N9C_Monitor_Reporter::auto_enabled() ? 'every ' . (int) round( N9C_Monitor_Reporter::interval() / HOUR_IN_SECONDS ) . ' h' : 'off' ) . ( $next ? ', next ' . gmdate( 'Y-m-d H:i', (int) $next ) . ' UTC' : '' ) );
		if ( N9C_Monitor_Config::has_moved() ) {
			WP_CLI::warning( 'Site address changed since connecting (' . $config['bound_url'] . ' -> ' . N9C_Monitor_Config::fingerprint() . '); automatic reports are paused.' );
		}
		$last = N9C_Monitor_Reporter::last_result();
		if ( ! $last ) {
			WP_CLI::line( 'Last report:  none' );
			return;
		}
		$line = 'Last report:  ' . gmdate( 'Y-m-d H:i', (int) $last['time'] ) . ' UTC (' . $last['trigger'] . '), HTTP ' . (int) $last['status'];
		if ( 200 === (int) $last['status'] ) {
			$line .= ', score ' . $last['score'];
		} elseif ( ! empty( $last['detail'] ) ) {
			$line .= ': ' . $last['detail'];
		}
		WP_CLI::line( $line );
		if ( is_array( $last['plan'] ) && ! empty( $last['plan']['label'] ) ) {
			WP_CLI::line( 'Plan:         ' . $last['plan']['label'] . ( ! empty( $last['plan']['until'] ) ? ' until ' . $last['plan']['until'] : '' ) );
		}
	}

	/**
	 * Remove the local credentials. The instance remains in the N9C dashboard.
	 *
	 * @return void
	 */
	public function disconnect() {
		$config = N9C_Monitor_Config::load();
		if ( 'option' !== $config['source'] ) {
			WP_CLI::error( 'No stored credentials (source: ' . $config['source'] . ').' );
		}
		N9C_Monitor_Reporter::disconnect();
		WP_CLI::success( 'Disconnected.' );
	}
}
