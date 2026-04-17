<?php
/**
 * AJAX action handlers.
 *
 * All handlers:
 *  1. Verify the oc_nonce.
 *  2. Ensure the user is logged in.
 *  3. Perform role checks where required.
 *  4. Call OC_DB / OC_Meeting methods.
 *  5. Return JSON via wp_send_json_*.
 */

defined( 'ABSPATH' ) || exit;

class OC_Ajax {

	public static function register_actions() {
		$student_actions = array(
			'oc_get_available_slots',
			'oc_get_available_teachers',
			'oc_book_class',
			'oc_cancel_booking',
			'oc_get_student_events',
		);
		$teacher_actions = array(
			'oc_get_teacher_events',
			'oc_add_availability',
			'oc_update_availability',
			'oc_delete_availability',
			'oc_create_meeting',
			'oc_update_meeting',
			'oc_delete_meeting',
			'oc_get_groups',
			'oc_get_group_users',
			'oc_get_teachers',
			'oc_get_meeting_detail',
		);
		foreach ( array_merge( $student_actions, $teacher_actions ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Shared helpers
	 * ------------------------------------------------------------------ */

	private static function verify() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'online-class' ) ), 401 );
		}
		if ( ! check_ajax_referer( 'oc_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'online-class' ) ), 403 );
		}
	}

	private static function require_teacher_or_admin() {
		$uid = get_current_user_id();
		if ( ! current_user_can( 'administrator' ) && ! OC_DB::user_has_role( $uid, 'teacher' ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'online-class' ) ), 403 );
		}
	}

	private static function current_is_teacher_or_admin() {
		$uid = get_current_user_id();
		return current_user_can( 'administrator' ) || OC_DB::user_has_role( $uid, 'teacher' );
	}

	/* ------------------------------------------------------------------
	 * Student: get calendar events (their bookings)
	 * ------------------------------------------------------------------ */
	public static function handle_oc_get_student_events() {
		self::verify();
		$uid   = get_current_user_id();
		$start = sanitize_text_field( $_GET['start'] ?? '' );
		$end   = sanitize_text_field( $_GET['end']   ?? '' );

		$meetings = OC_DB::get_meetings( array(
			'student_id' => $uid,
			'date_from'  => $start,
			'date_to'    => $end,
		) );

		$events = array();
		foreach ( $meetings as $m ) {
			$events[] = self::meeting_to_event( $m );
		}

		// Also get available slots so student can book.
		$availability = OC_DB::get_availability( 0, $start, $end );
		foreach ( $availability as $a ) {
			if ( $a->is_booked ) {
				continue;
			}
			$events[] = array(
				'id'    => 'avail_' . $a->id,
				'title' => sprintf( __( 'Available: %s', 'online-class' ), esc_html( $a->teacher_name ) ),
				'start' => $a->avail_date . 'T' . $a->start_time,
				'end'   => $a->avail_date . 'T' . $a->end_time,
				'color' => '#28a745',
				'extendedProps' => array(
					'type'        => 'availability',
					'avail_id'    => (int) $a->id,
					'teacher_id'  => (int) $a->teacher_id,
					'teacher_name' => $a->teacher_name,
				),
			);
		}

		wp_send_json_success( $events );
	}

	/* ------------------------------------------------------------------
	 * Student: get available teachers for a specific slot
	 * ------------------------------------------------------------------ */
	public static function handle_oc_get_available_teachers() {
		self::verify();

		$date  = sanitize_text_field( $_GET['date'] ?? '' );
		$slots = OC_DB::get_availability( 0, $date, $date );

		$teachers = array();
		foreach ( $slots as $s ) {
			if ( $s->is_booked ) {
				continue;
			}
			$teachers[] = array(
				'avail_id'    => (int) $s->id,
				'teacher_id'  => (int) $s->teacher_id,
				'teacher_name' => esc_html( $s->teacher_name ),
				'start_time'  => $s->start_time,
				'end_time'    => $s->end_time,
			);
		}

		wp_send_json_success( $teachers );
	}

	/* ------------------------------------------------------------------
	 * Student: get available time slots for a given date
	 * ------------------------------------------------------------------ */
	public static function handle_oc_get_available_slots() {
		self::verify();

		$date = sanitize_text_field( $_GET['date'] ?? '' );
		$tid  = (int) ( $_GET['teacher_id'] ?? 0 );

		$rows = OC_DB::get_availability( $tid ?: 0, $date, $date );

		$slots = array();
		foreach ( $rows as $r ) {
			if ( $r->is_booked ) {
				continue;
			}
			$slots[] = array(
				'avail_id'    => (int) $r->id,
				'teacher_id'  => (int) $r->teacher_id,
				'teacher_name' => esc_html( $r->teacher_name ),
				'start_time'  => $r->start_time,
				'end_time'    => $r->end_time,
			);
		}

		wp_send_json_success( $slots );
	}

	/* ------------------------------------------------------------------
	 * Student: book a class
	 * ------------------------------------------------------------------ */
	public static function handle_oc_book_class() {
		self::verify();

		$avail_id = (int) ( $_POST['avail_id'] ?? 0 );
		$topic    = sanitize_textarea_field( $_POST['topic'] ?? '' );
		$uid      = get_current_user_id();

		if ( ! $avail_id ) {
			wp_send_json_error( array( 'message' => __( 'Please select a time slot.', 'online-class' ) ) );
		}

		$slot = OC_DB::get_availability_slot( $avail_id );
		if ( ! $slot ) {
			wp_send_json_error( array( 'message' => __( 'Time slot not found.', 'online-class' ) ) );
		}
		if ( $slot->is_booked ) {
			wp_send_json_error( array( 'message' => __( 'This time slot is already booked.', 'online-class' ) ) );
		}

		// Enforce 24-hour advance booking rule.
		$advance_hours = (int) OC_DB::get_setting( 'oc_advance_hours', 24 );
		$slot_ts       = strtotime( $slot->avail_date . ' ' . $slot->start_time );
		if ( $slot_ts < time() + $advance_hours * 3600 ) {
			wp_send_json_error( array(
				'message' => sprintf(
					/* translators: %d = hours */
					__( 'Bookings must be made at least %d hours in advance.', 'online-class' ),
					$advance_hours
				),
			) );
		}

		$default_type     = OC_DB::get_setting( 'oc_default_meeting_type', 'zoom' );
		$default_duration = (int) OC_DB::get_setting( 'oc_default_duration', 60 );

		$result = OC_Meeting::create( array(
			'teacher_id'      => $slot->teacher_id,
			'availability_id' => $avail_id,
			'school_id'       => $slot->school_id,
			'class_id'        => $slot->class_id,
			'title'           => __( 'Online Class', 'online-class' ),
			'topic'           => $topic,
			'start_datetime'  => $slot->avail_date . ' ' . $slot->start_time,
			'duration'        => $default_duration,
			'meeting_type'    => $default_type,
			'created_by'      => $uid,
			'attendees'       => array( $uid ),
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message'     => __( 'Class booked successfully!', 'online-class' ),
			'meeting_url' => $result['meeting_url'],
		) );
	}

	/* ------------------------------------------------------------------
	 * Student: cancel a booking
	 * ------------------------------------------------------------------ */
	public static function handle_oc_cancel_booking() {
		self::verify();

		$meeting_id = (int) ( $_POST['meeting_id'] ?? 0 );
		$uid        = get_current_user_id();

		if ( ! $meeting_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid meeting.', 'online-class' ) ) );
		}

		// Verify this user is an attendee.
		$attendees = OC_DB::get_meeting_attendees( $meeting_id );
		$is_attendee = false;
		foreach ( $attendees as $a ) {
			if ( (int) $a->user_id === $uid ) {
				$is_attendee = true;
				break;
			}
		}
		if ( ! $is_attendee && ! current_user_can( 'administrator' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not an attendee of this meeting.', 'online-class' ) ) );
		}

		OC_DB::update_meeting( $meeting_id, array( 'status' => 'cancelled' ) );
		// Release the slot.
		$meeting = OC_DB::get_meeting( $meeting_id );
		if ( $meeting && $meeting->availability_id ) {
			OC_DB::update_availability( $meeting->availability_id, array( 'is_booked' => 0 ) );
		}

		wp_send_json_success( array( 'message' => __( 'Booking cancelled.', 'online-class' ) ) );
	}

	/* ------------------------------------------------------------------
	 * Teacher/Admin: get calendar events (availability + meetings)
	 * ------------------------------------------------------------------ */
	public static function handle_oc_get_teacher_events() {
		self::verify();
		self::require_teacher_or_admin();

		$uid   = get_current_user_id();
		$start = sanitize_text_field( $_GET['start'] ?? '' );
		$end   = sanitize_text_field( $_GET['end']   ?? '' );

		// Admins can pass teacher_id filter; teachers only see their own.
		if ( current_user_can( 'administrator' ) && ! empty( $_GET['teacher_id'] ) ) {
			$teacher_id = (int) $_GET['teacher_id'];
		} else {
			$teacher_id = $uid;
		}

		$events = array();

		// Availability slots.
		$availability = OC_DB::get_availability( $teacher_id, $start, $end );
		foreach ( $availability as $a ) {
			$events[] = array(
				'id'    => 'avail_' . $a->id,
				'title' => $a->is_booked
					? __( '⏳ Booked Slot', 'online-class' )
					: __( '✅ Available', 'online-class' ),
				'start' => $a->avail_date . 'T' . $a->start_time,
				'end'   => $a->avail_date . 'T' . $a->end_time,
				'color' => $a->is_booked ? '#6c757d' : '#28a745',
				'extendedProps' => array(
					'type'      => 'availability',
					'avail_id'  => (int) $a->id,
					'is_booked' => (bool) $a->is_booked,
					'school_id' => (int) $a->school_id,
					'class_id'  => (int) $a->class_id,
				),
			);
		}

		// Meetings.
		$meetings = OC_DB::get_meetings( array(
			'teacher_id' => $teacher_id,
			'date_from'  => $start,
			'date_to'    => $end,
		) );
		foreach ( $meetings as $m ) {
			$events[] = self::meeting_to_event( $m, true );
		}

		wp_send_json_success( $events );
	}

	/* ------------------------------------------------------------------
	 * Teacher/Admin: add availability
	 * ------------------------------------------------------------------ */
	public static function handle_oc_add_availability() {
		self::verify();
		self::require_teacher_or_admin();

		$uid       = get_current_user_id();
		$teacher_id = current_user_can( 'administrator' ) && ! empty( $_POST['teacher_id'] )
			? (int) $_POST['teacher_id']
			: $uid;

		$data = array(
			'teacher_id' => $teacher_id,
			'school_id'  => (int) ( $_POST['school_id']  ?? 0 ),
			'class_id'   => (int) ( $_POST['class_id']   ?? 0 ),
			'avail_date' => sanitize_text_field( $_POST['avail_date']  ?? '' ),
			'start_time' => sanitize_text_field( $_POST['start_time']  ?? '' ),
			'end_time'   => sanitize_text_field( $_POST['end_time']    ?? '' ),
		);

		if ( ! $data['avail_date'] || ! $data['start_time'] || ! $data['end_time'] ) {
			wp_send_json_error( array( 'message' => __( 'Date and times are required.', 'online-class' ) ) );
		}
		if ( $data['start_time'] >= $data['end_time'] ) {
			wp_send_json_error( array( 'message' => __( 'End time must be after start time.', 'online-class' ) ) );
		}

		$id = OC_DB::add_availability( $data );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Failed to save availability.', 'online-class' ) ) );
		}

		wp_send_json_success( array( 'id' => $id, 'message' => __( 'Availability saved.', 'online-class' ) ) );
	}

	/* ------------------------------------------------------------------
	 * Teacher/Admin: update availability
	 * ------------------------------------------------------------------ */
	public static function handle_oc_update_availability() {
		self::verify();
		self::require_teacher_or_admin();

		$id  = (int) ( $_POST['avail_id'] ?? 0 );
		$uid = get_current_user_id();

		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'online-class' ) ) );
		}

		$slot = OC_DB::get_availability_slot( $id );
		if ( ! $slot ) {
			wp_send_json_error( array( 'message' => __( 'Slot not found.', 'online-class' ) ) );
		}
		// Teachers can only edit their own slots.
		if ( ! current_user_can( 'administrator' ) && (int) $slot->teacher_id !== $uid ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'online-class' ) ), 403 );
		}

		$data = array_filter( array(
			'school_id'  => isset( $_POST['school_id'] )  ? (int) $_POST['school_id']                        : null,
			'class_id'   => isset( $_POST['class_id'] )   ? (int) $_POST['class_id']                         : null,
			'avail_date' => isset( $_POST['avail_date'] )  ? sanitize_text_field( $_POST['avail_date'] )  : null,
			'start_time' => isset( $_POST['start_time'] )  ? sanitize_text_field( $_POST['start_time'] )  : null,
			'end_time'   => isset( $_POST['end_time'] )    ? sanitize_text_field( $_POST['end_time'] )    : null,
		), function ( $v ) { return $v !== null; } );

		OC_DB::update_availability( $id, $data );
		wp_send_json_success( array( 'message' => __( 'Availability updated.', 'online-class' ) ) );
	}

	/* ------------------------------------------------------------------
	 * Teacher/Admin: delete availability
	 * ------------------------------------------------------------------ */
	public static function handle_oc_delete_availability() {
		self::verify();
		self::require_teacher_or_admin();

		$id  = (int) ( $_POST['avail_id'] ?? 0 );
		$uid = get_current_user_id();

		$slot = OC_DB::get_availability_slot( $id );
		if ( ! $slot ) {
			wp_send_json_error( array( 'message' => __( 'Slot not found.', 'online-class' ) ) );
		}
		if ( ! current_user_can( 'administrator' ) && (int) $slot->teacher_id !== $uid ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'online-class' ) ), 403 );
		}
		if ( $slot->is_booked ) {
			wp_send_json_error( array( 'message' => __( 'Cannot delete a booked slot. Cancel the meeting first.', 'online-class' ) ) );
		}

		OC_DB::delete_availability( $id );
		wp_send_json_success( array( 'message' => __( 'Availability deleted.', 'online-class' ) ) );
	}

	/* ------------------------------------------------------------------
	 * Teacher/Admin: create meeting
	 * ------------------------------------------------------------------ */
	public static function handle_oc_create_meeting() {
		self::verify();
		self::require_teacher_or_admin();

		$uid = get_current_user_id();

		$teacher_id = current_user_can( 'administrator' ) && ! empty( $_POST['teacher_id'] )
			? (int) $_POST['teacher_id']
			: $uid;

		$data = array(
			'teacher_id'     => $teacher_id,
			'availability_id' => (int) ( $_POST['availability_id'] ?? 0 ),
			'school_id'      => (int) ( $_POST['school_id']        ?? 0 ),
			'class_id'       => (int) ( $_POST['class_id']         ?? 0 ),
			'title'          => sanitize_text_field( $_POST['title']          ?? '' ),
			'topic'          => sanitize_textarea_field( $_POST['topic']      ?? '' ),
			'start_datetime' => sanitize_text_field( $_POST['start_datetime'] ?? '' ),
			'duration'       => (int) ( $_POST['duration']         ?? 60 ),
			'meeting_type'   => sanitize_text_field( $_POST['meeting_type']   ?? 'zoom' ),
			'meeting_url'    => esc_url_raw( $_POST['meeting_url']            ?? '' ),
			'created_by'     => $uid,
			'attendees'      => array_map( 'intval', (array) ( $_POST['attendees'] ?? array() ) ),
		);

		if ( ! $data['start_datetime'] ) {
			wp_send_json_error( array( 'message' => __( 'Start date/time is required.', 'online-class' ) ) );
		}

		$result = OC_Meeting::create( $data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message'     => __( 'Meeting created.', 'online-class' ),
			'db_id'       => $result['db_id'],
			'meeting_url' => $result['meeting_url'],
		) );
	}

	/* ------------------------------------------------------------------
	 * Teacher/Admin: update meeting
	 * ------------------------------------------------------------------ */
	public static function handle_oc_update_meeting() {
		self::verify();
		self::require_teacher_or_admin();

		$meeting_id = (int) ( $_POST['meeting_id'] ?? 0 );
		$uid        = get_current_user_id();

		$meeting = OC_DB::get_meeting( $meeting_id );
		if ( ! $meeting ) {
			wp_send_json_error( array( 'message' => __( 'Meeting not found.', 'online-class' ) ) );
		}
		if ( ! current_user_can( 'administrator' ) && (int) $meeting->teacher_id !== $uid ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'online-class' ) ), 403 );
		}

		$data = array_filter( array(
			'title'          => isset( $_POST['title'] )          ? sanitize_text_field( $_POST['title'] )               : null,
			'topic'          => isset( $_POST['topic'] )          ? sanitize_textarea_field( $_POST['topic'] )           : null,
			'school_id'      => isset( $_POST['school_id'] )      ? (int) $_POST['school_id']                            : null,
			'class_id'       => isset( $_POST['class_id'] )       ? (int) $_POST['class_id']                             : null,
			'start_datetime' => isset( $_POST['start_datetime'] ) ? sanitize_text_field( $_POST['start_datetime'] )     : null,
			'duration'       => isset( $_POST['duration'] )        ? (int) $_POST['duration']                             : null,
			'meeting_type'   => isset( $_POST['meeting_type'] )   ? sanitize_text_field( $_POST['meeting_type'] )       : null,
			'meeting_url'    => isset( $_POST['meeting_url'] )    ? esc_url_raw( $_POST['meeting_url'] )                 : null,
			'attendees'      => isset( $_POST['attendees'] )      ? array_map( 'intval', (array) $_POST['attendees'] )  : null,
		), function ( $v ) { return $v !== null; } );

		$result = OC_Meeting::update( $meeting_id, $data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Meeting updated.', 'online-class' ) ) );
	}

	/* ------------------------------------------------------------------
	 * Teacher/Admin: delete meeting
	 * ------------------------------------------------------------------ */
	public static function handle_oc_delete_meeting() {
		self::verify();
		self::require_teacher_or_admin();

		$meeting_id = (int) ( $_POST['meeting_id'] ?? 0 );
		$uid        = get_current_user_id();

		$meeting = OC_DB::get_meeting( $meeting_id );
		if ( ! $meeting ) {
			wp_send_json_error( array( 'message' => __( 'Meeting not found.', 'online-class' ) ) );
		}
		if ( ! current_user_can( 'administrator' ) && (int) $meeting->teacher_id !== $uid ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'online-class' ) ), 403 );
		}

		$result = OC_Meeting::delete( $meeting_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Meeting deleted.', 'online-class' ) ) );
	}

	/* ------------------------------------------------------------------
	 * Shared: get schools or classes
	 * ------------------------------------------------------------------ */
	public static function handle_oc_get_groups() {
		self::verify();

		$type      = sanitize_text_field( $_GET['type'] ?? 'school' );
		$school_id = (int) ( $_GET['school_id'] ?? 0 );
		$uid       = get_current_user_id();

		if ( $type === 'class' && $school_id ) {
			// If not admin, limit to groups the user belongs to.
			if ( current_user_can( 'administrator' ) ) {
				$groups = OC_DB::get_classes_by_school( $school_id );
			} else {
				$all    = OC_DB::get_classes_by_school( $school_id );
				$user_g = OC_DB::get_user_groups( $uid, 'class' );
				$user_ids = array_column( $user_g, 'id' );
				$groups = array_filter( $all, function ( $g ) use ( $user_ids ) { return in_array( $g->id, $user_ids, true ); } );
				$groups = array_values( $groups );
			}
		} else {
			if ( current_user_can( 'administrator' ) ) {
				$groups = OC_DB::get_groups_by_type( 'school' );
			} else {
				$groups = OC_DB::get_user_groups( $uid, 'school' );
			}
		}

		wp_send_json_success( $groups );
	}

	/* ------------------------------------------------------------------
	 * Shared: get users in a group (filtered to teachers or students)
	 * ------------------------------------------------------------------ */
	public static function handle_oc_get_group_users() {
		self::verify();

		$group_id = (int) ( $_GET['group_id'] ?? 0 );
		$role     = sanitize_text_field( $_GET['role'] ?? '' );

		if ( ! $group_id ) {
			wp_send_json_success( array() );
		}

		$users = OC_DB::get_group_members( $group_id, $role );
		wp_send_json_success( $users );
	}

	/* ------------------------------------------------------------------
	 * Admin: get all teachers
	 * ------------------------------------------------------------------ */
	public static function handle_oc_get_teachers() {
		self::verify();
		self::require_teacher_or_admin();

		$teachers = OC_DB::get_users_by_role( 'teacher' );
		wp_send_json_success( $teachers );
	}

	/* ------------------------------------------------------------------
	 * Shared: get full meeting detail (including attendees)
	 * ------------------------------------------------------------------ */
	public static function handle_oc_get_meeting_detail() {
		self::verify();

		$meeting_id = (int) ( $_GET['meeting_id'] ?? 0 );
		$meeting    = OC_DB::get_meeting( $meeting_id );

		if ( ! $meeting ) {
			wp_send_json_error( array( 'message' => __( 'Meeting not found.', 'online-class' ) ) );
		}

		$uid = get_current_user_id();
		// Only teacher/admin or an attendee may see the detail.
		$attendees = OC_DB::get_meeting_attendees( $meeting_id );
		$attendee_ids = array_column( $attendees, 'user_id' );
		if ( ! self::current_is_teacher_or_admin() && ! in_array( $uid, $attendee_ids, false ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'online-class' ) ), 403 );
		}

		$meeting->attendees = $attendees;
		wp_send_json_success( $meeting );
	}

	/* ------------------------------------------------------------------
	 * Internal helper: convert a meeting DB row to a FullCalendar event.
	 * ------------------------------------------------------------------ */
	private static function meeting_to_event( $m, $show_teacher = false ) {
		$status_colors = array(
			'scheduled'  => '#0d6efd',
			'cancelled'  => '#dc3545',
			'completed'  => '#6c757d',
		);
		$color = $status_colors[ $m->status ] ?? '#0d6efd';

		$title = $m->title ?: __( 'Online Class', 'online-class' );
		if ( $show_teacher && isset( $m->teacher_name ) ) {
			$title = $m->teacher_name . ': ' . $title;
		}

		return array(
			'id'    => 'meeting_' . $m->id,
			'title' => esc_html( $title ),
			'start' => $m->start_datetime,
			'end'   => $m->end_datetime,
			'color' => $color,
			'extendedProps' => array(
				'type'         => 'meeting',
				'meeting_id'   => (int) $m->id,
				'status'       => $m->status,
				'meeting_url'  => $m->meeting_url,
				'meeting_type' => $m->meeting_type,
				'topic'        => $m->topic,
			),
		);
	}
}
