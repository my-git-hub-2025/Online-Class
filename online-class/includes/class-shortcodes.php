<?php
/**
 * Shortcodes that render the student and teacher/admin calendar pages.
 *
 * [oc_student_calendar]  – for students to view and book classes.
 * [oc_teacher_calendar]  – for teachers and admins to manage availability
 *                          and meetings.
 */

defined( 'ABSPATH' ) || exit;

class OC_Shortcodes {

	public static function init() {
		add_shortcode( 'oc_student_calendar',  array( __CLASS__, 'student_calendar' ) );
		add_shortcode( 'oc_teacher_calendar',  array( __CLASS__, 'teacher_calendar' ) );
	}

	/* ------------------------------------------------------------------
	 * Student calendar shortcode
	 * ------------------------------------------------------------------ */
	public static function student_calendar() {
		if ( ! is_user_logged_in() ) {
			return '<p class="oc-login-notice">' .
				esc_html__( 'Please log in to view your schedule.', 'online-class' ) .
				'</p>';
		}

		ob_start();
		?>
		<div class="oc-wrap" id="oc-student-wrap">

			<!-- Toolbar -->
			<div class="oc-toolbar d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
				<h4 class="mb-0"><i class="fas fa-calendar-alt me-2"></i><?php esc_html_e( 'My Schedule', 'online-class' ); ?></h4>
				<div class="oc-legend d-flex gap-3 small">
					<span><span class="oc-dot" style="background:#28a745"></span> <?php esc_html_e( 'Available', 'online-class' ); ?></span>
					<span><span class="oc-dot" style="background:#0d6efd"></span> <?php esc_html_e( 'Booked', 'online-class' ); ?></span>
					<span><span class="oc-dot" style="background:#dc3545"></span> <?php esc_html_e( 'Cancelled', 'online-class' ); ?></span>
				</div>
			</div>

			<!-- Calendar -->
			<div id="oc-student-calendar"></div>

			<!-- =========================================================
			     Booking Modal
			     ========================================================= -->
			<div class="modal fade" id="oc-book-modal" tabindex="-1" aria-labelledby="ocBookModalLabel" aria-hidden="true">
				<div class="modal-dialog modal-dialog-centered">
					<div class="modal-content">
						<div class="modal-header">
							<h5 class="modal-title" id="ocBookModalLabel">
								<i class="fas fa-graduation-cap me-2"></i><?php esc_html_e( 'Book a Class', 'online-class' ); ?>
							</h5>
							<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
						</div>
						<div class="modal-body">
							<form id="oc-book-form">
								<div class="mb-3">
									<label class="form-label fw-semibold"><?php esc_html_e( 'Date', 'online-class' ); ?></label>
									<p id="oc-book-date" class="form-control-plaintext fw-bold"></p>
								</div>
								<div class="mb-3">
									<label for="oc-book-teacher" class="form-label fw-semibold">
										<?php esc_html_e( 'Select Tutor', 'online-class' ); ?> <span class="text-danger">*</span>
									</label>
									<select id="oc-book-teacher" name="teacher_id" class="form-select" required>
										<option value=""><?php esc_html_e( '— choose a tutor —', 'online-class' ); ?></option>
									</select>
								</div>
								<div class="mb-3" id="oc-slot-group">
									<label for="oc-book-slot" class="form-label fw-semibold">
										<?php esc_html_e( 'Select Time Slot', 'online-class' ); ?> <span class="text-danger">*</span>
									</label>
									<select id="oc-book-slot" name="avail_id" class="form-select" required>
										<option value=""><?php esc_html_e( '— choose a time —', 'online-class' ); ?></option>
									</select>
								</div>
								<div class="mb-3">
									<label for="oc-book-topic" class="form-label fw-semibold">
										<?php esc_html_e( 'Topic (optional)', 'online-class' ); ?>
									</label>
									<textarea id="oc-book-topic" name="topic" class="form-control" rows="3"
										placeholder="<?php esc_attr_e( 'What would you like to cover?', 'online-class' ); ?>"></textarea>
								</div>
								<p class="text-muted small">
									<i class="fas fa-info-circle me-1"></i>
									<?php
									$hours = (int) OC_DB::get_setting( 'oc_advance_hours', 24 );
									/* translators: %d hours */
									printf( esc_html__( 'Bookings must be made at least %d hours in advance.', 'online-class' ), $hours );
									?>
								</p>
								<div id="oc-book-alert" class="alert d-none"></div>
							</form>
						</div>
						<div class="modal-footer">
							<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
								<i class="fas fa-times me-1"></i><?php esc_html_e( 'Close', 'online-class' ); ?>
							</button>
							<button type="button" class="btn btn-primary" id="oc-book-submit">
								<i class="fas fa-check me-1"></i><?php esc_html_e( 'Book Class', 'online-class' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>

			<!-- =========================================================
			     Meeting Detail Modal (view + cancel)
			     ========================================================= -->
			<div class="modal fade" id="oc-detail-modal" tabindex="-1" aria-labelledby="ocDetailModalLabel" aria-hidden="true">
				<div class="modal-dialog modal-dialog-centered">
					<div class="modal-content">
						<div class="modal-header">
							<h5 class="modal-title" id="ocDetailModalLabel">
								<i class="fas fa-video me-2"></i><?php esc_html_e( 'Class Details', 'online-class' ); ?>
							</h5>
							<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
						</div>
						<div class="modal-body" id="oc-detail-body">
							<div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div></div>
						</div>
						<div class="modal-footer">
							<a href="#" id="oc-join-link" class="btn btn-success d-none" target="_blank" rel="noopener">
								<i class="fas fa-video me-1"></i><?php esc_html_e( 'Join Meeting', 'online-class' ); ?>
							</a>
							<button type="button" class="btn btn-danger d-none" id="oc-cancel-btn">
								<i class="fas fa-ban me-1"></i><?php esc_html_e( 'Cancel Booking', 'online-class' ); ?>
							</button>
							<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
								<?php esc_html_e( 'Close', 'online-class' ); ?>
							</button>
						</div>
					</div>
				</div>
			</div>

		</div><!-- /.oc-wrap -->
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Teacher / Admin calendar shortcode
	 * ------------------------------------------------------------------ */
	public static function teacher_calendar() {
		if ( ! is_user_logged_in() ) {
			return '<p class="oc-login-notice">' .
				esc_html__( 'Please log in to manage your schedule.', 'online-class' ) .
				'</p>';
		}

		$uid      = get_current_user_id();
		$is_admin = current_user_can( 'administrator' );

		if ( ! $is_admin && ! OC_DB::user_has_role( $uid, 'teacher' ) ) {
			return '<p class="oc-access-denied">' .
				esc_html__( 'You do not have permission to access this page.', 'online-class' ) .
				'</p>';
		}

		ob_start();
		?>
		<div class="oc-wrap" id="oc-teacher-wrap">

			<!-- Toolbar -->
			<div class="oc-toolbar d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
				<h4 class="mb-0">
					<i class="fas fa-chalkboard-teacher me-2"></i>
					<?php echo $is_admin
						? esc_html__( 'Admin – Class Management', 'online-class' )
						: esc_html__( 'My Teaching Schedule', 'online-class' );
					?>
				</h4>
				<div class="d-flex gap-2 flex-wrap">
					<?php if ( $is_admin ) : ?>
					<select id="oc-admin-teacher-filter" class="form-select form-select-sm" style="width:auto">
						<option value=""><?php esc_html_e( 'All Teachers', 'online-class' ); ?></option>
					</select>
					<?php endif; ?>
					<button class="btn btn-sm btn-outline-success" id="oc-add-avail-btn">
						<i class="fas fa-plus me-1"></i><?php esc_html_e( 'Add Availability', 'online-class' ); ?>
					</button>
					<button class="btn btn-sm btn-primary" id="oc-create-meeting-btn">
						<i class="fas fa-video me-1"></i><?php esc_html_e( 'New Meeting', 'online-class' ); ?>
					</button>
				</div>
			</div>

			<!-- Legend -->
			<div class="oc-legend d-flex gap-3 small mb-3 flex-wrap">
				<span><span class="oc-dot" style="background:#28a745"></span> <?php esc_html_e( 'Available', 'online-class' ); ?></span>
				<span><span class="oc-dot" style="background:#6c757d"></span> <?php esc_html_e( 'Booked Slot', 'online-class' ); ?></span>
				<span><span class="oc-dot" style="background:#0d6efd"></span> <?php esc_html_e( 'Scheduled Meeting', 'online-class' ); ?></span>
				<span><span class="oc-dot" style="background:#dc3545"></span> <?php esc_html_e( 'Cancelled', 'online-class' ); ?></span>
			</div>

			<!-- Calendar -->
			<div id="oc-teacher-calendar"></div>

			<!-- =========================================================
			     Availability Modal
			     ========================================================= -->
			<div class="modal fade" id="oc-avail-modal" tabindex="-1" aria-labelledby="ocAvailModalLabel" aria-hidden="true">
				<div class="modal-dialog modal-dialog-centered">
					<div class="modal-content">
						<div class="modal-header">
							<h5 class="modal-title" id="ocAvailModalLabel">
								<i class="fas fa-clock me-2"></i><?php esc_html_e( 'Availability Slot', 'online-class' ); ?>
							</h5>
							<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
						</div>
						<div class="modal-body">
							<form id="oc-avail-form">
								<input type="hidden" id="oc-avail-id" name="avail_id" value="">
								<?php if ( $is_admin ) : ?>
								<div class="mb-3">
									<label for="oc-avail-teacher" class="form-label fw-semibold">
										<?php esc_html_e( 'Teacher', 'online-class' ); ?> <span class="text-danger">*</span>
									</label>
									<select id="oc-avail-teacher" name="teacher_id" class="form-select" required>
										<option value=""><?php esc_html_e( '— select teacher —', 'online-class' ); ?></option>
									</select>
								</div>
								<?php endif; ?>
								<div class="mb-3">
									<label for="oc-avail-school" class="form-label fw-semibold">
										<?php esc_html_e( 'School', 'online-class' ); ?>
									</label>
									<select id="oc-avail-school" name="school_id" class="form-select">
										<option value=""><?php esc_html_e( '— select school —', 'online-class' ); ?></option>
									</select>
								</div>
								<div class="mb-3">
									<label for="oc-avail-class" class="form-label fw-semibold">
										<?php esc_html_e( 'Class', 'online-class' ); ?>
									</label>
									<select id="oc-avail-class" name="class_id" class="form-select">
										<option value=""><?php esc_html_e( '— select class —', 'online-class' ); ?></option>
									</select>
								</div>
								<div class="mb-3">
									<label for="oc-avail-date" class="form-label fw-semibold">
										<?php esc_html_e( 'Date', 'online-class' ); ?> <span class="text-danger">*</span>
									</label>
									<input type="date" id="oc-avail-date" name="avail_date" class="form-control"
										min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>
								</div>
								<div class="row">
									<div class="col-6 mb-3">
										<label for="oc-avail-start" class="form-label fw-semibold">
											<?php esc_html_e( 'Start Time', 'online-class' ); ?> <span class="text-danger">*</span>
										</label>
										<input type="time" id="oc-avail-start" name="start_time" class="form-control" required>
									</div>
									<div class="col-6 mb-3">
										<label for="oc-avail-end" class="form-label fw-semibold">
											<?php esc_html_e( 'End Time', 'online-class' ); ?> <span class="text-danger">*</span>
										</label>
										<input type="time" id="oc-avail-end" name="end_time" class="form-control" required>
									</div>
								</div>
								<div id="oc-avail-alert" class="alert d-none"></div>
							</form>
						</div>
						<div class="modal-footer justify-content-between">
							<button type="button" class="btn btn-danger d-none" id="oc-avail-delete-btn">
								<i class="fas fa-trash me-1"></i><?php esc_html_e( 'Delete', 'online-class' ); ?>
							</button>
							<div>
								<button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">
									<?php esc_html_e( 'Cancel', 'online-class' ); ?>
								</button>
								<button type="button" class="btn btn-success" id="oc-avail-save-btn">
									<i class="fas fa-save me-1"></i><?php esc_html_e( 'Save', 'online-class' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- =========================================================
			     Meeting Modal (create / edit)
			     ========================================================= -->
			<div class="modal fade" id="oc-meeting-modal" tabindex="-1" aria-labelledby="ocMeetingModalLabel" aria-hidden="true">
				<div class="modal-dialog modal-dialog-centered modal-lg">
					<div class="modal-content">
						<div class="modal-header">
							<h5 class="modal-title" id="ocMeetingModalLabel">
								<i class="fas fa-video me-2"></i><?php esc_html_e( 'Meeting', 'online-class' ); ?>
							</h5>
							<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
						</div>
						<div class="modal-body">
							<form id="oc-meeting-form">
								<input type="hidden" id="oc-meeting-id" name="meeting_id" value="">
								<div class="row">
									<div class="col-md-8 mb-3">
										<label for="oc-meeting-title" class="form-label fw-semibold">
											<?php esc_html_e( 'Meeting Title', 'online-class' ); ?> <span class="text-danger">*</span>
										</label>
										<input type="text" id="oc-meeting-title" name="title" class="form-control" required
											placeholder="<?php esc_attr_e( 'e.g. Math – Algebra Session', 'online-class' ); ?>">
									</div>
									<div class="col-md-4 mb-3">
										<label for="oc-meeting-type" class="form-label fw-semibold">
											<?php esc_html_e( 'Platform', 'online-class' ); ?>
										</label>
										<select id="oc-meeting-type" name="meeting_type" class="form-select">
											<option value="zoom">Zoom</option>
											<option value="teams">MS Teams</option>
											<option value="other"><?php esc_html_e( 'Other / Custom URL', 'online-class' ); ?></option>
										</select>
									</div>
								</div>

								<div class="mb-3 d-none" id="oc-custom-url-group">
									<label for="oc-meeting-url" class="form-label fw-semibold">
										<?php esc_html_e( 'Meeting URL', 'online-class' ); ?> <span class="text-danger">*</span>
									</label>
									<input type="url" id="oc-meeting-url" name="meeting_url" class="form-control"
										placeholder="https://...">
								</div>

								<?php if ( $is_admin ) : ?>
								<div class="mb-3">
									<label for="oc-meeting-teacher" class="form-label fw-semibold">
										<?php esc_html_e( 'Teacher / Host', 'online-class' ); ?> <span class="text-danger">*</span>
									</label>
									<select id="oc-meeting-teacher" name="teacher_id" class="form-select" required>
										<option value=""><?php esc_html_e( '— select teacher —', 'online-class' ); ?></option>
									</select>
								</div>
								<?php endif; ?>

								<div class="row">
									<div class="col-md-6 mb-3">
										<label for="oc-meeting-school" class="form-label fw-semibold">
											<?php esc_html_e( 'School', 'online-class' ); ?>
										</label>
										<select id="oc-meeting-school" name="school_id" class="form-select">
											<option value=""><?php esc_html_e( '— select school —', 'online-class' ); ?></option>
										</select>
									</div>
									<div class="col-md-6 mb-3">
										<label for="oc-meeting-class" class="form-label fw-semibold">
											<?php esc_html_e( 'Class', 'online-class' ); ?>
										</label>
										<select id="oc-meeting-class" name="class_id" class="form-select">
											<option value=""><?php esc_html_e( '— select class —', 'online-class' ); ?></option>
										</select>
									</div>
								</div>

								<div class="row">
									<div class="col-md-8 mb-3">
										<label for="oc-meeting-start" class="form-label fw-semibold">
											<?php esc_html_e( 'Start Date & Time', 'online-class' ); ?> <span class="text-danger">*</span>
										</label>
										<input type="datetime-local" id="oc-meeting-start" name="start_datetime" class="form-control" required>
									</div>
									<div class="col-md-4 mb-3">
										<label for="oc-meeting-duration" class="form-label fw-semibold">
											<?php esc_html_e( 'Duration (min)', 'online-class' ); ?>
										</label>
										<input type="number" id="oc-meeting-duration" name="duration" class="form-control"
											value="60" min="15" step="15">
									</div>
								</div>

								<div class="mb-3">
									<label for="oc-meeting-topic" class="form-label fw-semibold">
										<?php esc_html_e( 'Topic / Description', 'online-class' ); ?>
									</label>
									<textarea id="oc-meeting-topic" name="topic" class="form-control" rows="2"
										placeholder="<?php esc_attr_e( 'Session topic…', 'online-class' ); ?>"></textarea>
								</div>

								<div class="mb-3">
									<label class="form-label fw-semibold">
										<?php esc_html_e( 'Attendees (Students)', 'online-class' ); ?>
									</label>
									<div id="oc-meeting-attendees-list" class="border rounded p-2" style="max-height:150px;overflow-y:auto">
										<p class="text-muted small mb-0"><?php esc_html_e( 'Select a class to load students.', 'online-class' ); ?></p>
									</div>
								</div>

								<div id="oc-meeting-alert" class="alert d-none"></div>
							</form>
						</div>
						<div class="modal-footer justify-content-between">
							<button type="button" class="btn btn-danger d-none" id="oc-meeting-delete-btn">
								<i class="fas fa-trash me-1"></i><?php esc_html_e( 'Delete Meeting', 'online-class' ); ?>
							</button>
							<div>
								<button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">
									<?php esc_html_e( 'Cancel', 'online-class' ); ?>
								</button>
								<button type="button" class="btn btn-primary" id="oc-meeting-save-btn">
									<i class="fas fa-save me-1"></i><?php esc_html_e( 'Save Meeting', 'online-class' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>
			</div>

		</div><!-- /.oc-wrap -->
		<?php
		return ob_get_clean();
	}
}
