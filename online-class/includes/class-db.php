<?php
/**
 * Database layer – all queries use $wpdb exclusively.
 *
 * Tables used from the existing WP installation:
 *   {prefix}users
 *   {prefix}usermeta
 *   {prefix}bp_groups          (BuddyPress)
 *   {prefix}bp_groups_members  (BuddyPress)
 *   {prefix}bp_groups_groupmeta (BuddyPress)
 *
 * Custom tables created by this plugin:
 *   {prefix}oc_availability
 *   {prefix}oc_meetings
 *   {prefix}oc_attendees
 */

defined( 'ABSPATH' ) || exit;

class OC_DB {

	/* ------------------------------------------------------------------
	 * Table names (resolved at runtime via $wpdb->prefix)
	 * ------------------------------------------------------------------ */

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	/* ------------------------------------------------------------------
	 * Activation: create custom tables
	 * ------------------------------------------------------------------ */
	public static function install() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$availability = self::table( 'oc_availability' );
		$meetings     = self::table( 'oc_meetings' );
		$attendees    = self::table( 'oc_attendees' );

		$sql = array();

		$sql[] = "CREATE TABLE IF NOT EXISTS {$availability} (
			id            BIGINT(20)   NOT NULL AUTO_INCREMENT,
			teacher_id    BIGINT(20)   NOT NULL,
			school_id     BIGINT(20)   DEFAULT NULL,
			class_id      BIGINT(20)   DEFAULT NULL,
			avail_date    DATE         NOT NULL,
			start_time    TIME         NOT NULL,
			end_time      TIME         NOT NULL,
			is_booked     TINYINT(1)   NOT NULL DEFAULT 0,
			created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY   (id),
			KEY teacher_date (teacher_id, avail_date)
		) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$meetings} (
			id             BIGINT(20)    NOT NULL AUTO_INCREMENT,
			title          VARCHAR(255)  NOT NULL DEFAULT '',
			teacher_id     BIGINT(20)    NOT NULL,
			availability_id BIGINT(20)  DEFAULT NULL,
			school_id      BIGINT(20)   DEFAULT NULL,
			class_id       BIGINT(20)   DEFAULT NULL,
			topic          VARCHAR(500)  DEFAULT NULL,
			start_datetime DATETIME      NOT NULL,
			end_datetime   DATETIME      NOT NULL,
			duration       SMALLINT(5)  NOT NULL DEFAULT 60,
			meeting_type   VARCHAR(20)  NOT NULL DEFAULT 'zoom',
			meeting_url    VARCHAR(1000) DEFAULT NULL,
			meeting_id     VARCHAR(255)  DEFAULT NULL,
			meeting_password VARCHAR(100) DEFAULT NULL,
			meeting_data   LONGTEXT      DEFAULT NULL,
			status         VARCHAR(20)  NOT NULL DEFAULT 'scheduled',
			created_by     BIGINT(20)   NOT NULL,
			created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY    (id),
			KEY teacher_start (teacher_id, start_datetime),
			KEY status (status)
		) {$charset};";

		$sql[] = "CREATE TABLE IF NOT EXISTS {$attendees} (
			id         BIGINT(20)  NOT NULL AUTO_INCREMENT,
			meeting_id BIGINT(20)  NOT NULL,
			user_id    BIGINT(20)  NOT NULL,
			status     VARCHAR(20) NOT NULL DEFAULT 'invited',
			created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY meeting_user (meeting_id, user_id)
		) {$charset};";

		foreach ( $sql as $query ) {
			$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Store installed version
		self::update_setting( 'oc_version', OC_VERSION );
	}

	public static function deactivate() {}

	/* ------------------------------------------------------------------
	 * Plugin settings stored in wp_options via $wpdb
	 * ------------------------------------------------------------------ */

	public static function get_setting( $key, $default = '' ) {
		global $wpdb;
		$val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$key
			)
		);
		return ( $val !== null ) ? $val : $default;
	}

	public static function update_setting( $key, $value ) {
		global $wpdb;
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
				$key
			)
		);
		if ( $exists ) {
			$wpdb->update(
				$wpdb->options,
				array( 'option_value' => $value ),
				array( 'option_name'  => $key )
			);
		} else {
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $key,
					'option_value' => $value,
					'autoload'     => 'yes',
				)
			);
		}
	}

	public static function get_all_settings() {
		global $wpdb;
		$keys = array(
			'oc_zoom_account_id', 'oc_zoom_client_id', 'oc_zoom_client_secret',
			'oc_teams_tenant_id', 'oc_teams_client_id', 'oc_teams_client_secret',
			'oc_default_meeting_type', 'oc_default_duration', 'oc_advance_hours',
		);
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$keys
			),
			OBJECT_K
		);
		$result = array();
		foreach ( $keys as $k ) {
			$result[ $k ] = isset( $rows[ $k ] ) ? $rows[ $k ]->option_value : '';
		}
		return $result;
	}

	/* ------------------------------------------------------------------
	 * Role helpers (queries wp_usermeta directly)
	 * ------------------------------------------------------------------ */

	/**
	 * Check if a user has a specific WP role using $wpdb.
	 */
	public static function user_has_role( $user_id, $role ) {
		global $wpdb;
		$capabilities_key = $wpdb->prefix . 'capabilities';
		$meta_value       = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
				(int) $user_id,
				$capabilities_key
			)
		);
		if ( ! $meta_value ) {
			return false;
		}
		$caps = maybe_unserialize( $meta_value );
		return is_array( $caps ) && ! empty( $caps[ $role ] );
	}

	/**
	 * Return all users that have the given role.
	 *
	 * @return array  Each row: { ID, display_name, user_email }
	 */
	public static function get_users_by_role( $role ) {
		global $wpdb;
		$capabilities_key = $wpdb->prefix . 'capabilities';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID, u.display_name, u.user_email
				 FROM {$wpdb->users} u
				 JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
				 WHERE um.meta_key = %s AND um.meta_value LIKE %s
				 ORDER BY u.display_name ASC",
				$capabilities_key,
				'%"' . $wpdb->esc_like( $role ) . '"%'
			)
		);
	}

	/**
	 * Get a user row from wp_users.
	 */
	public static function get_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, display_name, user_email FROM {$wpdb->users} WHERE ID = %d LIMIT 1",
				(int) $user_id
			)
		);
	}

	/* ------------------------------------------------------------------
	 * BuddyPress Groups helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Check if BuddyPress groups tables exist.
	 */
	public static function bp_installed() {
		global $wpdb;
		$table = $wpdb->prefix . 'bp_groups';
		return ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get all groups of a given type ('school' | 'class').
	 * Groups are typed via wp_bp_groups_groupmeta key 'group_type'.
	 *
	 * @return array  Each row: { id, name, description }
	 */
	public static function get_groups_by_type( $type ) {
		global $wpdb;
		if ( ! self::bp_installed() ) {
			return array();
		}
		$meta_table = $wpdb->prefix . 'bp_groups_groupmeta';
		$groups     = $wpdb->prefix . 'bp_groups';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.id, g.name, g.description
				 FROM {$groups} g
				 JOIN {$meta_table} gm ON g.id = gm.group_id
				 WHERE gm.meta_key = 'group_type' AND gm.meta_value = %s
				 ORDER BY g.name ASC",
				$type
			)
		);
	}

	/**
	 * Get all classes that belong to a given school group.
	 * Classes store their parent school ID in groupmeta key 'school_id'.
	 */
	public static function get_classes_by_school( $school_id ) {
		global $wpdb;
		if ( ! self::bp_installed() ) {
			return array();
		}
		$meta_table = $wpdb->prefix . 'bp_groups_groupmeta';
		$groups     = $wpdb->prefix . 'bp_groups';
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.id, g.name, g.description
				 FROM {$groups} g
				 JOIN {$meta_table} gm  ON g.id = gm.group_id AND gm.meta_key  = 'group_type'  AND gm.meta_value = 'class'
				 JOIN {$meta_table} gm2 ON g.id = gm2.group_id AND gm2.meta_key = 'school_id'  AND gm2.meta_value = %s
				 ORDER BY g.name ASC",
				(string) $school_id
			)
		);
	}

	/**
	 * Get members of a BP group, optionally filtered by role.
	 *
	 * @param  int    $group_id
	 * @param  string $role  'teacher' | 'student' | '' (all)
	 * @return array
	 */
	public static function get_group_members( $group_id, $role = '' ) {
		global $wpdb;
		if ( ! self::bp_installed() ) {
			return array();
		}
		$members_table    = $wpdb->prefix . 'bp_groups_members';
		$capabilities_key = $wpdb->prefix . 'capabilities';

		if ( $role ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.display_name, u.user_email
					 FROM {$wpdb->users} u
					 JOIN {$members_table} gm ON u.ID = gm.user_id
					 JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
					 WHERE gm.group_id    = %d
					   AND gm.is_confirmed = 1
					   AND gm.is_banned    = 0
					   AND um.meta_key     = %s
					   AND um.meta_value   LIKE %s
					 ORDER BY u.display_name ASC",
					(int) $group_id,
					$capabilities_key,
					'%"' . $wpdb->esc_like( $role ) . '"%'
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT u.ID, u.display_name, u.user_email
					 FROM {$wpdb->users} u
					 JOIN {$members_table} gm ON u.ID = gm.user_id
					 WHERE gm.group_id     = %d
					   AND gm.is_confirmed = 1
					   AND gm.is_banned    = 0
					 ORDER BY u.display_name ASC",
					(int) $group_id
				)
			);
		}
		return $rows;
	}

	/**
	 * Get all groups a user belongs to (filtered by type).
	 */
	public static function get_user_groups( $user_id, $type = '' ) {
		global $wpdb;
		if ( ! self::bp_installed() ) {
			return array();
		}
		$members_table = $wpdb->prefix . 'bp_groups_members';
		$meta_table    = $wpdb->prefix . 'bp_groups_groupmeta';
		$groups        = $wpdb->prefix . 'bp_groups';

		if ( $type ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT g.id, g.name, g.description
					 FROM {$groups} g
					 JOIN {$members_table} gm  ON g.id = gm.group_id
					 JOIN {$meta_table} gm2    ON g.id = gm2.group_id
					 WHERE gm.user_id       = %d
					   AND gm.is_confirmed  = 1
					   AND gm.is_banned     = 0
					   AND gm2.meta_key     = 'group_type'
					   AND gm2.meta_value   = %s
					 ORDER BY g.name ASC",
					(int) $user_id,
					$type
				)
			);
		} else {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT g.id, g.name, g.description
					 FROM {$groups} g
					 JOIN {$members_table} gm ON g.id = gm.group_id
					 WHERE gm.user_id      = %d
					   AND gm.is_confirmed = 1
					   AND gm.is_banned    = 0
					 ORDER BY g.name ASC",
					(int) $user_id
				)
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Availability
	 * ------------------------------------------------------------------ */

	/**
	 * Get availability slots for one or more teachers in a date range.
	 *
	 * @param  int|int[]  $teacher_id   Single ID or array of IDs. 0 = all.
	 * @param  string     $date_from    Y-m-d
	 * @param  string     $date_to      Y-m-d
	 * @return array
	 */
	public static function get_availability( $teacher_id = 0, $date_from = '', $date_to = '' ) {
		global $wpdb;
		$table = self::table( 'oc_availability' );

		$where  = array( '1=1' );
		$params = array();

		if ( $teacher_id ) {
			$ids = is_array( $teacher_id ) ? array_map( 'intval', $teacher_id ) : array( (int) $teacher_id );
			$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$where[]  = "a.teacher_id IN ({$ph})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params   = array_merge( $params, $ids );
		}
		if ( $date_from ) {
			$where[]  = 'a.avail_date >= %s';
			$params[] = $date_from;
		}
		if ( $date_to ) {
			$where[]  = 'a.avail_date <= %s';
			$params[] = $date_to;
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT a.*, u.display_name AS teacher_name
					  FROM {$table} a
					  JOIN {$wpdb->users} u ON a.teacher_id = u.ID
					  WHERE {$where_sql}
					  ORDER BY a.avail_date ASC, a.start_time ASC";

		if ( $params ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get a single availability slot.
	 */
	public static function get_availability_slot( $id ) {
		global $wpdb;
		$table = self::table( 'oc_availability' );
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Add a new availability slot.
	 *
	 * @param  array $data
	 * @return int|false  Inserted ID or false.
	 */
	public static function add_availability( $data ) {
		global $wpdb;
		$result = $wpdb->insert(
			self::table( 'oc_availability' ),
			array(
				'teacher_id'  => (int) $data['teacher_id'],
				'school_id'   => ! empty( $data['school_id'] ) ? (int) $data['school_id'] : null,
				'class_id'    => ! empty( $data['class_id'] )  ? (int) $data['class_id']  : null,
				'avail_date'  => sanitize_text_field( $data['avail_date'] ),
				'start_time'  => sanitize_text_field( $data['start_time'] ),
				'end_time'    => sanitize_text_field( $data['end_time'] ),
				'is_booked'   => 0,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%d' )
		);
		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update an availability slot.
	 */
	public static function update_availability( $id, $data ) {
		global $wpdb;
		$update = array();
		$format = array();

		if ( isset( $data['school_id'] ) ) { $update['school_id']  = (int) $data['school_id'];                    $format[] = '%d'; }
		if ( isset( $data['class_id'] ) )  { $update['class_id']   = (int) $data['class_id'];                     $format[] = '%d'; }
		if ( isset( $data['avail_date'] ) ){ $update['avail_date'] = sanitize_text_field( $data['avail_date'] );  $format[] = '%s'; }
		if ( isset( $data['start_time'] ) ){ $update['start_time'] = sanitize_text_field( $data['start_time'] );  $format[] = '%s'; }
		if ( isset( $data['end_time'] ) )  { $update['end_time']   = sanitize_text_field( $data['end_time'] );    $format[] = '%s'; }
		if ( isset( $data['is_booked'] ) ) { $update['is_booked']  = (int) $data['is_booked'];                    $format[] = '%d'; }

		if ( empty( $update ) ) {
			return false;
		}

		return $wpdb->update(
			self::table( 'oc_availability' ),
			$update,
			array( 'id' => (int) $id ),
			$format,
			array( '%d' )
		);
	}

	/**
	 * Delete an availability slot (only when not booked).
	 */
	public static function delete_availability( $id ) {
		global $wpdb;
		return $wpdb->delete(
			self::table( 'oc_availability' ),
			array( 'id' => (int) $id ),
			array( '%d' )
		);
	}

	/* ------------------------------------------------------------------
	 * Meetings
	 * ------------------------------------------------------------------ */

	/**
	 * Flexible meeting query.
	 *
	 * @param  array $args {
	 *   teacher_id, student_id, school_id, class_id,
	 *   status, date_from, date_to
	 * }
	 * @return array
	 */
	public static function get_meetings( $args = array() ) {
		global $wpdb;
		$m_table = self::table( 'oc_meetings' );
		$a_table = self::table( 'oc_attendees' );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['teacher_id'] ) ) {
			$where[]  = 'm.teacher_id = %d';
			$params[] = (int) $args['teacher_id'];
		}
		if ( ! empty( $args['school_id'] ) ) {
			$where[]  = 'm.school_id = %d';
			$params[] = (int) $args['school_id'];
		}
		if ( ! empty( $args['class_id'] ) ) {
			$where[]  = 'm.class_id = %d';
			$params[] = (int) $args['class_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'm.status = %s';
			$params[] = sanitize_text_field( $args['status'] );
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'DATE(m.start_datetime) >= %s';
			$params[] = $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'DATE(m.start_datetime) <= %s';
			$params[] = $args['date_to'];
		}
		if ( ! empty( $args['student_id'] ) ) {
			$where[]  = "m.id IN (SELECT meeting_id FROM {$a_table} WHERE user_id = %d)";
			$params[] = (int) $args['student_id'];
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT m.*, u.display_name AS teacher_name
					  FROM {$m_table} m
					  JOIN {$wpdb->users} u ON m.teacher_id = u.ID
					  WHERE {$where_sql}
					  ORDER BY m.start_datetime ASC";

		if ( $params ) {
			return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get a single meeting.
	 */
	public static function get_meeting( $id ) {
		global $wpdb;
		$table = self::table( 'oc_meetings' );
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Create a meeting record.
	 *
	 * @param  array $data
	 * @return int|false
	 */
	public static function create_meeting( $data ) {
		global $wpdb;
		$result = $wpdb->insert(
			self::table( 'oc_meetings' ),
			array(
				'title'           => sanitize_text_field( $data['title'] ?? '' ),
				'teacher_id'      => (int) $data['teacher_id'],
				'availability_id' => ! empty( $data['availability_id'] ) ? (int) $data['availability_id'] : null,
				'school_id'       => ! empty( $data['school_id'] )  ? (int) $data['school_id']  : null,
				'class_id'        => ! empty( $data['class_id'] )   ? (int) $data['class_id']   : null,
				'topic'           => sanitize_textarea_field( $data['topic'] ?? '' ),
				'start_datetime'  => sanitize_text_field( $data['start_datetime'] ),
				'end_datetime'    => sanitize_text_field( $data['end_datetime'] ),
				'duration'        => (int) ( $data['duration'] ?? 60 ),
				'meeting_type'    => sanitize_text_field( $data['meeting_type'] ?? 'zoom' ),
				'meeting_url'     => esc_url_raw( $data['meeting_url'] ?? '' ),
				'meeting_id'      => sanitize_text_field( $data['meeting_id'] ?? '' ),
				'meeting_password' => sanitize_text_field( $data['meeting_password'] ?? '' ),
				'meeting_data'    => ! empty( $data['meeting_data'] ) ? wp_json_encode( $data['meeting_data'] ) : null,
				'status'          => 'scheduled',
				'created_by'      => (int) $data['created_by'],
			),
			array( '%s','%d','%d','%d','%d','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%d' )
		);
		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update a meeting record.
	 */
	public static function update_meeting( $id, $data ) {
		global $wpdb;
		$allowed_keys = array(
			'title', 'school_id', 'class_id', 'topic',
			'start_datetime', 'end_datetime', 'duration',
			'meeting_type', 'meeting_url', 'meeting_id',
			'meeting_password', 'meeting_data', 'status',
		);
		$update = array();
		$format = array();

		foreach ( $allowed_keys as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			switch ( $key ) {
				case 'school_id':
				case 'class_id':
				case 'duration':
					$update[ $key ] = (int) $data[ $key ];
					$format[]       = '%d';
					break;
				case 'meeting_url':
					$update[ $key ] = esc_url_raw( $data[ $key ] );
					$format[]       = '%s';
					break;
				case 'meeting_data':
					$update[ $key ] = is_array( $data[ $key ] ) ? wp_json_encode( $data[ $key ] ) : $data[ $key ];
					$format[]       = '%s';
					break;
				default:
					$update[ $key ] = sanitize_text_field( $data[ $key ] );
					$format[]       = '%s';
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		return $wpdb->update(
			self::table( 'oc_meetings' ),
			$update,
			array( 'id' => (int) $id ),
			$format,
			array( '%d' )
		);
	}

	/**
	 * Delete a meeting and its attendees.
	 */
	public static function delete_meeting( $id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'oc_attendees' ), array( 'meeting_id' => (int) $id ), array( '%d' ) );
		return $wpdb->delete( self::table( 'oc_meetings' ), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/* ------------------------------------------------------------------
	 * Attendees
	 * ------------------------------------------------------------------ */

	public static function get_meeting_attendees( $meeting_id ) {
		global $wpdb;
		$table = self::table( 'oc_attendees' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.*, u.display_name, u.user_email
				 FROM {$table} a
				 JOIN {$wpdb->users} u ON a.user_id = u.ID
				 WHERE a.meeting_id = %d
				 ORDER BY u.display_name ASC",
				(int) $meeting_id
			)
		);
	}

	public static function add_attendee( $meeting_id, $user_id, $status = 'invited' ) {
		global $wpdb;
		// Use INSERT IGNORE to honour the UNIQUE constraint without an error.
		return $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO " . self::table( 'oc_attendees' ) . " (meeting_id, user_id, status) VALUES (%d, %d, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				(int) $meeting_id,
				(int) $user_id,
				sanitize_text_field( $status )
			)
		);
	}

	public static function update_attendee_status( $meeting_id, $user_id, $status ) {
		global $wpdb;
		return $wpdb->update(
			self::table( 'oc_attendees' ),
			array( 'status' => sanitize_text_field( $status ) ),
			array( 'meeting_id' => (int) $meeting_id, 'user_id' => (int) $user_id ),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}

	public static function remove_attendee( $meeting_id, $user_id ) {
		global $wpdb;
		return $wpdb->delete(
			self::table( 'oc_attendees' ),
			array( 'meeting_id' => (int) $meeting_id, 'user_id' => (int) $user_id ),
			array( '%d', '%d' )
		);
	}
}
