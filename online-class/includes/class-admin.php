<?php
/**
 * WordPress admin settings page for the Online Class plugin.
 * All settings are read/written via $wpdb (wp_options table).
 */

defined( 'ABSPATH' ) || exit;

class OC_Admin {

	public static function register_menu() {
		add_menu_page(
			__( 'Online Class', 'online-class' ),
			__( 'Online Class', 'online-class' ),
			'manage_options',
			'online-class',
			array( __CLASS__, 'settings_page' ),
			'dashicons-video-alt3',
			30
		);

		add_submenu_page(
			'online-class',
			__( 'Settings', 'online-class' ),
			__( 'Settings', 'online-class' ),
			'manage_options',
			'online-class',
			array( __CLASS__, 'settings_page' )
		);

		add_submenu_page(
			'online-class',
			__( 'Meetings', 'online-class' ),
			__( 'Meetings', 'online-class' ),
			'manage_options',
			'online-class-meetings',
			array( __CLASS__, 'meetings_page' )
		);

		add_submenu_page(
			'online-class',
			__( 'Availability', 'online-class' ),
			__( 'Availability', 'online-class' ),
			'manage_options',
			'online-class-availability',
			array( __CLASS__, 'availability_page' )
		);
	}

	/* ------------------------------------------------------------------
	 * Settings page
	 * ------------------------------------------------------------------ */
	public static function settings_page() {
		// Save settings submitted via POST.
		if ( isset( $_POST['oc_save_settings'] ) ) {
			if ( ! check_admin_referer( 'oc_save_settings' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'online-class' ) );
			}

			$keys = array(
				'oc_zoom_account_id', 'oc_zoom_client_id', 'oc_zoom_client_secret',
				'oc_teams_tenant_id', 'oc_teams_client_id', 'oc_teams_client_secret',
				'oc_default_meeting_type', 'oc_default_duration', 'oc_advance_hours',
			);
			foreach ( $keys as $key ) {
				if ( isset( $_POST[ $key ] ) ) {
					OC_DB::update_setting( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
				}
			}

			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'Settings saved.', 'online-class' ) .
				'</p></div>';
		}

		$s = OC_DB::get_all_settings();
		?>
		<div class="wrap">
			<h1><i class="fas fa-cogs me-2"></i><?php esc_html_e( 'Online Class – Settings', 'online-class' ); ?></h1>

			<form method="post">
				<?php wp_nonce_field( 'oc_save_settings' ); ?>

				<!-- Zoom -->
				<h2 class="title"><?php esc_html_e( 'Zoom (Server-to-Server OAuth)', 'online-class' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="oc_zoom_account_id"><?php esc_html_e( 'Account ID', 'online-class' ); ?></label></th>
						<td><input type="text" id="oc_zoom_account_id" name="oc_zoom_account_id" class="regular-text"
							value="<?php echo esc_attr( $s['oc_zoom_account_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc_zoom_client_id"><?php esc_html_e( 'Client ID', 'online-class' ); ?></label></th>
						<td><input type="text" id="oc_zoom_client_id" name="oc_zoom_client_id" class="regular-text"
							value="<?php echo esc_attr( $s['oc_zoom_client_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc_zoom_client_secret"><?php esc_html_e( 'Client Secret', 'online-class' ); ?></label></th>
						<td><input type="password" id="oc_zoom_client_secret" name="oc_zoom_client_secret" class="regular-text"
							value="<?php echo esc_attr( $s['oc_zoom_client_secret'] ); ?>"></td>
					</tr>
				</table>

				<!-- MS Teams -->
				<h2 class="title"><?php esc_html_e( 'Microsoft Teams (Graph API)', 'online-class' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="oc_teams_tenant_id"><?php esc_html_e( 'Tenant ID', 'online-class' ); ?></label></th>
						<td><input type="text" id="oc_teams_tenant_id" name="oc_teams_tenant_id" class="regular-text"
							value="<?php echo esc_attr( $s['oc_teams_tenant_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc_teams_client_id"><?php esc_html_e( 'Client ID', 'online-class' ); ?></label></th>
						<td><input type="text" id="oc_teams_client_id" name="oc_teams_client_id" class="regular-text"
							value="<?php echo esc_attr( $s['oc_teams_client_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc_teams_client_secret"><?php esc_html_e( 'Client Secret', 'online-class' ); ?></label></th>
						<td><input type="password" id="oc_teams_client_secret" name="oc_teams_client_secret" class="regular-text"
							value="<?php echo esc_attr( $s['oc_teams_client_secret'] ); ?>"></td>
					</tr>
				</table>

				<!-- General -->
				<h2 class="title"><?php esc_html_e( 'General Settings', 'online-class' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="oc_default_meeting_type"><?php esc_html_e( 'Default Meeting Platform', 'online-class' ); ?></label></th>
						<td>
							<select id="oc_default_meeting_type" name="oc_default_meeting_type">
								<option value="zoom"  <?php selected( $s['oc_default_meeting_type'], 'zoom' ); ?>>Zoom</option>
								<option value="teams" <?php selected( $s['oc_default_meeting_type'], 'teams' ); ?>>MS Teams</option>
								<option value="other" <?php selected( $s['oc_default_meeting_type'], 'other' ); ?>><?php esc_html_e( 'Other / Custom URL', 'online-class' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="oc_default_duration"><?php esc_html_e( 'Default Meeting Duration (minutes)', 'online-class' ); ?></label></th>
						<td><input type="number" id="oc_default_duration" name="oc_default_duration" class="small-text"
							value="<?php echo esc_attr( $s['oc_default_duration'] ?: 60 ); ?>" min="15" step="15"></td>
					</tr>
					<tr>
						<th scope="row"><label for="oc_advance_hours"><?php esc_html_e( 'Advance Booking (hours)', 'online-class' ); ?></label></th>
						<td>
							<input type="number" id="oc_advance_hours" name="oc_advance_hours" class="small-text"
								value="<?php echo esc_attr( $s['oc_advance_hours'] ?: 24 ); ?>" min="1">
							<p class="description"><?php esc_html_e( 'Students must book this many hours before the class start time.', 'online-class' ); ?></p>
						</td>
					</tr>
				</table>

				<!-- Shortcode reference -->
				<h2 class="title"><?php esc_html_e( 'Shortcodes', 'online-class' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Student Calendar', 'online-class' ); ?></th>
						<td><code>[oc_student_calendar]</code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Teacher / Admin Calendar', 'online-class' ); ?></th>
						<td><code>[oc_teacher_calendar]</code></td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'online-class' ), 'primary', 'oc_save_settings' ); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Meetings list page (admin overview)
	 * ------------------------------------------------------------------ */
	public static function meetings_page() {
		global $wpdb;

		$status    = sanitize_text_field( $_GET['status'] ?? '' );
		$meetings  = OC_DB::get_meetings( $status ? array( 'status' => $status ) : array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'All Meetings', 'online-class' ); ?></h1>

			<div class="tablenav top">
				<form method="get">
					<input type="hidden" name="page" value="online-class-meetings">
					<select name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'online-class' ); ?></option>
						<option value="scheduled"  <?php selected( $status, 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'online-class' ); ?></option>
						<option value="cancelled"  <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'online-class' ); ?></option>
						<option value="completed"  <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'online-class' ); ?></option>
					</select>
					<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'online-class' ); ?>">
				</form>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'Title', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'Teacher', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'Start', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'Platform', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'Status', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'Join Link', 'online-class' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $meetings ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No meetings found.', 'online-class' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $meetings as $m ) : ?>
					<tr>
						<td><?php echo (int) $m->id; ?></td>
						<td><?php echo esc_html( $m->title ); ?></td>
						<td><?php echo esc_html( $m->teacher_name ); ?></td>
						<td><?php echo esc_html( $m->start_datetime ); ?></td>
						<td><?php echo (int) $m->duration; ?> min</td>
						<td><?php echo esc_html( strtoupper( $m->meeting_type ) ); ?></td>
						<td>
							<span class="oc-badge oc-badge-<?php echo esc_attr( $m->status ); ?>">
								<?php echo esc_html( ucfirst( $m->status ) ); ?>
							</span>
						</td>
						<td>
							<?php if ( $m->meeting_url ) : ?>
								<a href="<?php echo esc_url( $m->meeting_url ); ?>" target="_blank" rel="noopener">
									<?php esc_html_e( 'Join', 'online-class' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Availability list page (admin overview)
	 * ------------------------------------------------------------------ */
	public static function availability_page() {
		$slots = OC_DB::get_availability( 0, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Teacher Availability (next 30 days)', 'online-class' ); ?></h1>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'Teacher', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'Date', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'Start', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'End', 'online-class' ); ?></th>
						<th><?php esc_html_e( 'Booked?', 'online-class' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $slots ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No availability slots found.', 'online-class' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $slots as $s ) : ?>
					<tr>
						<td><?php echo (int) $s->id; ?></td>
						<td><?php echo esc_html( $s->teacher_name ); ?></td>
						<td><?php echo esc_html( $s->avail_date ); ?></td>
						<td><?php echo esc_html( $s->start_time ); ?></td>
						<td><?php echo esc_html( $s->end_time ); ?></td>
						<td><?php echo $s->is_booked ? esc_html__( 'Yes', 'online-class' ) : esc_html__( 'No', 'online-class' ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
