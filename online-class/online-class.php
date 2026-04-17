<?php
/**
 * Plugin Name: Online Class Booking
 * Plugin URI:  https://github.com/my-git-hub-2025/Online-Class
 * Description: Complete online class booking system supporting Zoom, MS Teams and other meeting platforms. Calendar views for students, teachers and admins using wp_users, wp_usermeta and wp_bp_groups.
 * Version:     1.0.0
 * Author:      Online Class Team
 * License:     GPL v2 or later
 * Text Domain: online-class
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'OC_VERSION',     '1.0.0' );
define( 'OC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'OC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'OC_PLUGIN_FILE', __FILE__ );

/* ------------------------------------------------------------------
 * Load core classes
 * ------------------------------------------------------------------ */
require_once OC_PLUGIN_DIR . 'includes/class-db.php';
require_once OC_PLUGIN_DIR . 'includes/providers/class-zoom.php';
require_once OC_PLUGIN_DIR . 'includes/providers/class-teams.php';
require_once OC_PLUGIN_DIR . 'includes/class-meeting.php';
require_once OC_PLUGIN_DIR . 'includes/class-ajax.php';
require_once OC_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once OC_PLUGIN_DIR . 'includes/class-admin.php';

/* ------------------------------------------------------------------
 * Activation / Deactivation
 * ------------------------------------------------------------------ */
register_activation_hook( __FILE__, array( 'OC_DB', 'install' ) );
register_deactivation_hook( __FILE__, array( 'OC_DB', 'deactivate' ) );

/* ------------------------------------------------------------------
 * Bootstrap
 * ------------------------------------------------------------------ */
add_action( 'init', array( 'OC_Shortcodes', 'init' ) );
add_action( 'admin_menu', array( 'OC_Admin', 'register_menu' ) );

OC_Ajax::register_actions();

/* ------------------------------------------------------------------
 * Enqueue assets – frontend
 * ------------------------------------------------------------------ */
add_action( 'wp_enqueue_scripts', 'oc_enqueue_assets' );
add_action( 'admin_enqueue_scripts', 'oc_enqueue_assets_admin' );

/**
 * Shared asset registration helper.
 */
function oc_register_assets() {
	wp_register_style(
		'oc-fullcalendar',
		'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css',
		array(),
		'5.11.3'
	);
	wp_register_style(
		'oc-bootstrap',
		'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
		array(),
		'5.3.2'
	);
	wp_register_style(
		'oc-fontawesome',
		'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
		array(),
		'6.5.0'
	);
	wp_register_style(
		'oc-style',
		OC_PLUGIN_URL . 'assets/css/online-class.css',
		array( 'oc-fullcalendar', 'oc-bootstrap', 'oc-fontawesome' ),
		OC_VERSION
	);

	wp_register_script(
		'oc-fullcalendar',
		'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js',
		array(),
		'5.11.3',
		true
	);
	wp_register_script(
		'oc-bootstrap',
		'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
		array( 'jquery' ),
		'5.3.2',
		true
	);
	wp_register_script(
		'oc-main',
		OC_PLUGIN_URL . 'assets/js/online-class.js',
		array( 'jquery', 'oc-fullcalendar', 'oc-bootstrap' ),
		OC_VERSION,
		true
	);
}

/**
 * Build the JS localisation payload.
 *
 * @return array
 */
function oc_script_data() {
	$user_id = get_current_user_id();
	return array(
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'nonce'     => wp_create_nonce( 'oc_nonce' ),
		'userId'    => $user_id,
		'isAdmin'   => current_user_can( 'administrator' ) ? 1 : 0,
		'isTeacher' => OC_DB::user_has_role( $user_id, 'teacher' ) ? 1 : 0,
		'i18n'      => array(
			'bookClass'       => __( 'Book Class', 'online-class' ),
			'cancel'          => __( 'Cancel', 'online-class' ),
			'save'            => __( 'Save', 'online-class' ),
			'confirmCancel'   => __( 'Are you sure you want to cancel this booking?', 'online-class' ),
			'confirmDelete'   => __( 'Are you sure you want to delete this meeting?', 'online-class' ),
			'errorOccurred'   => __( 'An error occurred. Please try again.', 'online-class' ),
			'bookingSuccess'  => __( 'Class booked successfully!', 'online-class' ),
			'cancelSuccess'   => __( 'Booking cancelled successfully.', 'online-class' ),
			'savedSuccess'    => __( 'Saved successfully.', 'online-class' ),
			'deletedSuccess'  => __( 'Deleted successfully.', 'online-class' ),
		),
	);
}

function oc_enqueue_assets() {
	oc_register_assets();
	wp_enqueue_style( 'oc-style' );
	wp_enqueue_script( 'oc-main' );
	wp_localize_script( 'oc-main', 'ocData', oc_script_data() );
}

function oc_enqueue_assets_admin( $hook ) {
	if ( strpos( $hook, 'online-class' ) === false ) {
		return;
	}
	oc_register_assets();
	wp_enqueue_style( 'oc-style' );
	wp_enqueue_script( 'oc-main' );
	wp_localize_script( 'oc-main', 'ocData', oc_script_data() );
}
