<?php
/**
 * Microsoft Teams (Graph API) meeting provider.
 */

defined( 'ABSPATH' ) || exit;

class OC_Teams {

	private static $token_option = 'oc_teams_access_token';

	/**
	 * Get a valid Microsoft identity platform access token.
	 *
	 * @return string|WP_Error
	 */
	private static function get_access_token() {
		global $wpdb;

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

		$tenant_id     = OC_DB::get_setting( 'oc_teams_tenant_id' );
		$client_id     = OC_DB::get_setting( 'oc_teams_client_id' );
		$client_secret = OC_DB::get_setting( 'oc_teams_client_secret' );

		if ( ! $tenant_id || ! $client_id || ! $client_secret ) {
			return new WP_Error( 'teams_config', __( 'MS Teams API credentials are not configured.', 'online-class' ) );
		}

		$response = wp_remote_post(
			"https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token",
			array(
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'scope'         => 'https://graph.microsoft.com/.default',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'teams_token', __( 'Failed to obtain MS Teams access token.', 'online-class' ) );
		}

		$token_data = array(
			'token'   => $body['access_token'],
			'expires' => time() + (int) ( $body['expires_in'] ?? 3600 ),
		);
		OC_DB::update_setting( self::$token_option, wp_json_encode( $token_data ) );

		return $body['access_token'];
	}

	/**
	 * Make an authenticated request to the Microsoft Graph API.
	 */
	private static function request( $method, $path, $body = array(), $user_email = '' ) {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// Use /users/{email}/ to act on behalf of the organiser.
		$base = 'https://graph.microsoft.com/v1.0';
		$url  = $user_email
			? $base . '/users/' . rawurlencode( $user_email ) . $path
			: $base . '/me' . $path;

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

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code        = wp_remote_retrieve_response_code( $response );
		$parsed_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$msg = $parsed_body['error']['message'] ?? __( 'MS Teams API error.', 'online-class' );
			return new WP_Error( 'teams_api', $msg, array( 'status' => $code ) );
		}

		return $parsed_body ?? array();
	}

	/**
	 * Create a Teams online meeting.
	 *
	 * @param  array $args { subject, start_time (ISO 8601), end_time (ISO 8601), host_email }
	 * @return array|WP_Error { meeting_id, meeting_url, raw }
	 */
	public static function create_meeting( $args ) {
		$body = array(
			'subject'   => $args['topic'] ?? __( 'Online Class', 'online-class' ),
			'startDateTime' => $args['start_time'],
			'endDateTime'   => $args['end_time'],
		);

		$result = self::request( 'POST', '/onlineMeetings', $body, $args['host_email'] ?? '' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'meeting_id'  => $result['id'] ?? '',
			'meeting_url' => $result['joinWebUrl'] ?? '',
			'password'    => '',
			'raw'         => $result,
		);
	}

	/**
	 * Update a Teams meeting.
	 */
	public static function update_meeting( $meeting_id, $args, $host_email = '' ) {
		$body = array();
		if ( isset( $args['topic'] ) )     { $body['subject']       = $args['topic']; }
		if ( isset( $args['start_time'] ) ){ $body['startDateTime'] = $args['start_time']; }
		if ( isset( $args['end_time'] ) )  { $body['endDateTime']   = $args['end_time']; }

		return self::request( 'PATCH', '/onlineMeetings/' . $meeting_id, $body, $host_email );
	}

	/**
	 * Delete a Teams meeting.
	 */
	public static function delete_meeting( $meeting_id, $host_email = '' ) {
		return self::request( 'DELETE', '/onlineMeetings/' . $meeting_id, array(), $host_email );
	}
}
