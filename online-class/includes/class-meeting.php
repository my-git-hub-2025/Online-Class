<?php
/**
 * Meeting manager – provider-agnostic facade over Zoom, MS Teams and
 * generic (custom URL) meeting providers.
 */

defined( 'ABSPATH' ) || exit;

class OC_Meeting {

	/**
	 * Create a remote meeting via the configured provider and persist the
	 * record to the database.
	 *
	 * @param  array $data {
	 *   teacher_id, school_id, class_id, availability_id,
	 *   topic, title, start_datetime, duration, meeting_type,
	 *   meeting_url (for type=other), created_by, attendees (int[])
	 * }
	 * @return array|WP_Error  { meeting_id (DB), meeting_url, meeting_password }
	 */
	public static function create( $data ) {
		$type     = sanitize_text_field( $data['meeting_type'] ?? 'zoom' );
		$duration = (int) ( $data['duration'] ?? OC_DB::get_setting( 'oc_default_duration', 60 ) );
		$topic    = sanitize_text_field( $data['topic'] ?? $data['title'] ?? __( 'Online Class', 'online-class' ) );

		// Parse start/end datetimes.
		$start_dt  = sanitize_text_field( $data['start_datetime'] );
		$start_ts  = strtotime( $start_dt );
		$end_ts    = $start_ts + $duration * 60;
		$end_dt    = gmdate( 'Y-m-d H:i:s', $end_ts );

		$meeting_url      = '';
		$ext_meeting_id   = '';
		$meeting_password = '';
		$meeting_raw      = array();

		if ( 'zoom' === $type ) {
			$teacher  = OC_DB::get_user( (int) $data['teacher_id'] );
			$result   = OC_Zoom::create_meeting( array(
				'topic'       => $topic,
				'start_time'  => gmdate( 'Y-m-d\TH:i:s\Z', $start_ts ),
				'duration'    => $duration,
				'host_email'  => $teacher ? $teacher->user_email : '',
			) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$meeting_url      = $result['meeting_url'];
			$ext_meeting_id   = $result['meeting_id'];
			$meeting_password = $result['password'];
			$meeting_raw      = $result['raw'] ?? array();

		} elseif ( 'teams' === $type ) {
			$teacher = OC_DB::get_user( (int) $data['teacher_id'] );
			$result  = OC_Teams::create_meeting( array(
				'topic'       => $topic,
				'start_time'  => gmdate( 'Y-m-d\TH:i:s\Z', $start_ts ),
				'end_time'    => gmdate( 'Y-m-d\TH:i:s\Z', $end_ts ),
				'host_email'  => $teacher ? $teacher->user_email : '',
			) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$meeting_url    = $result['meeting_url'];
			$ext_meeting_id = $result['meeting_id'];
			$meeting_raw    = $result['raw'] ?? array();

		} else {
			// Generic / custom URL – the URL is provided directly by the teacher.
			$meeting_url = esc_url_raw( $data['meeting_url'] ?? '' );
		}

		// Persist to DB.
		$db_id = OC_DB::create_meeting( array(
			'title'           => $data['title'] ?? $topic,
			'teacher_id'      => (int) $data['teacher_id'],
			'availability_id' => $data['availability_id'] ?? null,
			'school_id'       => $data['school_id']  ?? null,
			'class_id'        => $data['class_id']   ?? null,
			'topic'           => $topic,
			'start_datetime'  => $start_dt,
			'end_datetime'    => $end_dt,
			'duration'        => $duration,
			'meeting_type'    => $type,
			'meeting_url'     => $meeting_url,
			'meeting_id'      => $ext_meeting_id,
			'meeting_password' => $meeting_password,
			'meeting_data'    => $meeting_raw,
			'created_by'      => (int) $data['created_by'],
		) );

		if ( ! $db_id ) {
			return new WP_Error( 'db_error', __( 'Failed to save meeting to database.', 'online-class' ) );
		}

		// Mark availability slot as booked.
		if ( ! empty( $data['availability_id'] ) ) {
			OC_DB::update_availability( (int) $data['availability_id'], array( 'is_booked' => 1 ) );
		}

		// Save attendees.
		if ( ! empty( $data['attendees'] ) && is_array( $data['attendees'] ) ) {
			foreach ( $data['attendees'] as $uid ) {
				OC_DB::add_attendee( $db_id, (int) $uid );
			}
		}

		return array(
			'db_id'           => $db_id,
			'meeting_url'     => $meeting_url,
			'meeting_password' => $meeting_password,
		);
	}

	/**
	 * Update a meeting record and the remote meeting if the time/topic changed.
	 *
	 * @param  int   $db_id
	 * @param  array $data
	 * @return true|WP_Error
	 */
	public static function update( $db_id, $data ) {
		$meeting = OC_DB::get_meeting( $db_id );
		if ( ! $meeting ) {
			return new WP_Error( 'not_found', __( 'Meeting not found.', 'online-class' ) );
		}

		// Push updates to the remote provider if applicable.
		if ( $meeting->meeting_id ) {
			$remote_args = array();
			if ( isset( $data['topic'] ) )          { $remote_args['topic']      = $data['topic']; }
			if ( isset( $data['start_datetime'] ) ) { $remote_args['start_time'] = gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $data['start_datetime'] ) ); }
			if ( isset( $data['duration'] ) )        { $remote_args['duration']   = (int) $data['duration']; }

			if ( ! empty( $remote_args ) ) {
				if ( 'zoom' === $meeting->meeting_type ) {
					OC_Zoom::update_meeting( $meeting->meeting_id, $remote_args );
				} elseif ( 'teams' === $meeting->meeting_type ) {
					$teacher = OC_DB::get_user( $meeting->teacher_id );
					if ( isset( $remote_args['start_time'] ) && isset( $data['duration'] ) ) {
						$remote_args['end_time'] = gmdate(
							'Y-m-d\TH:i:s\Z',
							strtotime( $data['start_datetime'] ) + (int) $data['duration'] * 60
						);
					}
					OC_Teams::update_meeting( $meeting->meeting_id, $remote_args, $teacher ? $teacher->user_email : '' );
				}
			}
		}

		// Recalculate end_datetime when start or duration changes.
		if ( isset( $data['start_datetime'] ) || isset( $data['duration'] ) ) {
			$start    = $data['start_datetime'] ?? $meeting->start_datetime;
			$duration = $data['duration'] ?? $meeting->duration;
			$data['end_datetime'] = gmdate( 'Y-m-d H:i:s', strtotime( $start ) + (int) $duration * 60 );
		}

		OC_DB::update_meeting( $db_id, $data );

		// Sync attendees if provided.
		if ( isset( $data['attendees'] ) && is_array( $data['attendees'] ) ) {
			global $wpdb;
			$wpdb->delete( OC_DB::table( 'oc_attendees' ), array( 'meeting_id' => $db_id ), array( '%d' ) );
			foreach ( $data['attendees'] as $uid ) {
				OC_DB::add_attendee( $db_id, (int) $uid );
			}
		}

		return true;
	}

	/**
	 * Delete a meeting locally and on the remote provider.
	 *
	 * @param  int $db_id
	 * @return true|WP_Error
	 */
	public static function delete( $db_id ) {
		$meeting = OC_DB::get_meeting( $db_id );
		if ( ! $meeting ) {
			return new WP_Error( 'not_found', __( 'Meeting not found.', 'online-class' ) );
		}

		if ( $meeting->meeting_id ) {
			if ( 'zoom' === $meeting->meeting_type ) {
				OC_Zoom::delete_meeting( $meeting->meeting_id );
			} elseif ( 'teams' === $meeting->meeting_type ) {
				$teacher = OC_DB::get_user( $meeting->teacher_id );
				OC_Teams::delete_meeting( $meeting->meeting_id, $teacher ? $teacher->user_email : '' );
			}
		}

		// Release the availability slot.
		if ( $meeting->availability_id ) {
			OC_DB::update_availability( $meeting->availability_id, array( 'is_booked' => 0 ) );
		}

		OC_DB::delete_meeting( $db_id );
		return true;
	}
}
