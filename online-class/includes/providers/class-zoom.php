<?php
/**
 * Zoom Server-to-Server OAuth API provider.
 */

defined( 'ABSPATH' ) || exit;

class OC_Zoom {

	/** Default token lifetime in seconds when `expires_in` is absent from the API response. */
	const DEFAULT_TOKEN_EXPIRY = 3600;

	private static $token_option = 'oc_zoom_access_token';

	/**
	 * Get a valid access token, refreshing if needed.
	 *
	 * @return string|WP_Error
	 */
	private static function get_access_token() {
		global $wpdb;

		// Check cached token.
		$cached = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::$token_option
			)
		);
		if ( $cached ) {
			$token_data = json_decode( $cached, true );
			if ( $token_data && $token_data['expires'] > time() + 60 ) {
				return $token_data['token'];
			}
		}

		$account_id    = OC_DB::get_setting( 'oc_zoom_account_id' );
		$client_id     = OC_DB::get_setting( 'oc_zoom_client_id' );
		$client_secret = OC_DB::get_setting( 'oc_zoom_client_secret' );

		if ( ! $account_id || ! $client_id || ! $client_secret ) {
			return new WP_Error( 'zoom_config', __( 'Zoom API credentials are not configured.', 'online-class' ) );
		}

		$response = wp_remote_post(
			'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . rawurlencode( $account_id ),
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'zoom_token', __( 'Failed to obtain Zoom access token.', 'online-class' ) );
		}

		$token_data = array(
			'token'   => $body['access_token'],
			'expires' => time() + (int) ( $body['expires_in'] ?? self::DEFAULT_TOKEN_EXPIRY ),
		);
		OC_DB::update_setting( self::$token_option, wp_json_encode( $token_data ) );

		return $body['access_token'];
	}

	/**
	 * Make an authenticated request to the Zoom API.
	 *
	 * @param  string $method  GET | POST | PATCH | DELETE
	 * @param  string $path    e.g. '/v2/users/me/meetings'
	 * @param  array  $body    Request payload (for POST/PATCH).
	 * @return array|WP_Error
	 */
	private static function request( $method, $path, $body = array() ) {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 20,
		);
		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( 'https://api.zoom.us' . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code        = wp_remote_retrieve_response_code( $response );
		$parsed_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = $parsed_body['message'] ?? __( 'Zoom API error.', 'online-class' );
			return new WP_Error( 'zoom_api', $msg, array( 'status' => $code ) );
		}

		return $parsed_body ?? array();
	}

	/**
	 * Create a Zoom meeting.
	 *
	 * @param  array $args {
	 *   topic, start_time (ISO 8601), duration (int minutes),
	 *   password (optional), host_email
	 * }
	 * @return array|WP_Error  { meeting_id, meeting_url, password }
	 */
	public static function create_meeting( $args ) {
		$body = array(
			'topic'      => $args['topic'] ?? __( 'Online Class', 'online-class' ),
			'type'       => 2, // Scheduled meeting.
			'start_time' => $args['start_time'],
			'duration'   => (int) ( $args['duration'] ?? 60 ),
			'timezone'   => wp_timezone_string(),
			'settings'   => array(
				'host_video'        => true,
				'participant_video'  => true,
				'join_before_host'  => false,
				'waiting_room'      => true,
				'auto_recording'    => 'none',
			),
		);
		if ( ! empty( $args['password'] ) ) {
			$body['password'] = $args['password'];
		}

		// Create under a specific host email or 'me'.
		$host = ! empty( $args['host_email'] ) ? rawurlencode( $args['host_email'] ) : 'me';
		$result = self::request( 'POST', "/v2/users/{$host}/meetings", $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'meeting_id'  => (string) $result['id'],
			'meeting_url' => $result['join_url'],
			'password'    => $result['password'] ?? '',
			'raw'         => $result,
		);
	}

	/**
	 * Update an existing Zoom meeting.
	 */
	public static function update_meeting( $meeting_id, $args ) {
		$body = array();
		if ( isset( $args['topic'] ) )      { $body['topic']      = $args['topic']; }
		if ( isset( $args['start_time'] ) ) { $body['start_time'] = $args['start_time']; }
		if ( isset( $args['duration'] ) )   { $body['duration']   = (int) $args['duration']; }

		return self::request( 'PATCH', "/v2/meetings/{$meeting_id}", $body );
	}

	/**
	 * Delete a Zoom meeting.
	 */
	public static function delete_meeting( $meeting_id ) {
		return self::request( 'DELETE', "/v2/meetings/{$meeting_id}" );
	}
}
