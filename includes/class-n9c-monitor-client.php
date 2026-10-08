<?php
/**
 * HTTP client for the agent API (protocol n9c.agent.report/1).
 *
 * Uses the WordPress HTTP API, so proxy settings (WP_PROXY_*) apply.
 *
 * @package N9C_Monitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent API client.
 */
final class N9C_Monitor_Client {

	/**
	 * Signature header value: HMAC-SHA256 over "<timestamp>.<raw body>".
	 *
	 * @param string $secret    Instance secret.
	 * @param string $timestamp Unix timestamp as string.
	 * @param string $body      Exact bytes that are sent.
	 * @return string
	 */
	public static function sign( $secret, $timestamp, $body ) {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Proof of identity when reconnecting an already connected instance.
	 *
	 * @param string $previous_secret Secret issued before.
	 * @param string $token           New connection code.
	 * @return string
	 */
	public static function rebind_proof( $previous_secret, $token ) {
		return 'sha256=' . hash_hmac( 'sha256', 'rebind.' . trim( $token ), $previous_secret );
	}

	/**
	 * Exchange a connection code for instance ID and secret.
	 *
	 * @param string               $endpoint API base URL.
	 * @param array<string, mixed> $payload  Enroll request.
	 * @return array{status: int, data: array<string, mixed>}|WP_Error
	 */
	public function enroll( $endpoint, array $payload ) {
		$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return $this->post( $endpoint . '/agent/v1/enroll', (string) $body, array( 'Content-Type' => 'application/json' ) );
	}

	/**
	 * Send a signed report.
	 *
	 * @param string $endpoint    API base URL.
	 * @param string $instance_id Instance ID.
	 * @param string $secret      Instance secret.
	 * @param string $body        JSON report.
	 * @return array{status: int, data: array<string, mixed>}|WP_Error
	 */
	public function send_report( $endpoint, $instance_id, $secret, $body ) {
		$timestamp = (string) time();
		return $this->post(
			$endpoint . '/agent/v1/report',
			$body,
			array(
				'Content-Type'    => 'application/json',
				'X-N9C-Instance'  => $instance_id,
				'X-N9C-Timestamp' => $timestamp,
				'X-N9C-Signature' => self::sign( $secret, $timestamp, $body ),
			)
		);
	}

	/**
	 * POST helper.
	 *
	 * @param string                $url     Target URL.
	 * @param string                $body    Raw body.
	 * @param array<string, string> $headers Headers.
	 * @return array{status: int, data: array<string, mixed>}|WP_Error
	 */
	private function post( $url, $body, array $headers ) {
		$response = wp_remote_post(
			$url,
			array(
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => 30,
				'redirection' => 0,
				'user-agent'  => N9C_Monitor_Collector::AGENT_NAME . '/' . N9C_MONITOR_VERSION . '; WordPress',
				'data_format' => 'body',
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'data'   => is_array( $data ) ? $data : array( 'detail' => mb_substr( wp_strip_all_tags( $raw ), 0, 500 ) ),
		);
	}
}
