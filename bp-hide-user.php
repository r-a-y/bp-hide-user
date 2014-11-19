<?php
/*
Plugin Name: BP Hide User
Description: Allows site admins to hide users from being seen in BuddyPress
Author: r-a-y
Author URI: http://profiles.wordpress.org/r-a-y
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'bp_loaded', array( 'BP_Hide_User', 'init' ) );

/**
 * An efficient way to hide selected users from the BP members directory.
 *
 * This plugin doesn't manipulate any DB queries.  Only removes the
 * 'last_activity' entry from the BP activity table.
 *
 * @todo Hide users from alphabetical filter in members directory?
 */
class BP_Hide_User {
	/**
	 * Static initializer.
	 */
	public static function init() {
		return new self();
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'bp_setup_current_user',    array( $this, 'remove_record_activity' ) );
		add_action( 'bp_member_header_actions', array( $this, 'add_button' ), 99 );
		add_action( 'bp_actions',               array( $this, 'action_listener' ) );
		add_action( 'bp_screens',               array( $this, 'block_hidden_user_profile' ) );

		// misc
		add_action( 'bp_before_directory_members_tabs', array( $this, 'add_template_notices_to_members_directory' ) );
	}

	/**
	 * Remove BP's record activity hook.
	 *
	 * This is to avoid being seen in BP's member directory.  Run on the
	 * 'bp_set_current_user' hook so this is done as early as possible.
	 */
	public function remove_record_activity() {
		global $current_user;

		// no logged-in user, so stop!
		if ( empty( $current_user->ID ) ) {
			return;
		}

		$log = bp_get_option( 'bp_hide_user_log' );

		// user is not in log, so stop!
		if ( ! isset( $log[$current_user->ID] ) ) {
			return;
		}

		// remove BP's record activity action
		//
		// BP uses this to as a way to mark members as active and will list them in
		// the members directory; we don't want this
		remove_action( 'wp_head', 'bp_core_record_activity' );

		// hook for plugins to do other stuff!
		do_action( 'bp_hide_user_loaded' );
	}

	/**
	 * Add our 'Hide user' / 'Unhide user' button on member profile pages.
	 *
	 * Only done on member profile pages at the moment because this shouldn't be a
	 * highly-exposed feature.
	 */
	public function add_button() {
		// only users with the 'bp_moderate' cap can hide users
		if ( ! bp_current_user_can( 'bp_moderate' ) ) {
			return;
		}

		$last_activity = BP_Core_User::get_last_activity( bp_displayed_user_id() );

		if ( empty( $last_activity[bp_displayed_user_id()] ) ) {
			$action = 'unhide';
		} else {
			$action = 'hide';
		}

		if ( 'hide' == $action ) {
			$link_text = __( 'Hide user', 'bp-hide-user' );
			$nonce_action = 'bp_hu_hide_user';
		} else {
			$link_text = __( 'Unhide user', 'bp-hide-user' );
			$nonce_action = 'bp_hu_unhide_user';
		}

		// setup the button arguments
		$button = apply_filters( 'bp_hu_button_args', array(
			'id'                => 'hide',
			'component'         => 'members',
			'must_be_logged_in' => true,
			'block_self'        => true,
			'link_href'         => wp_nonce_url(
				add_query_arg( 'uid', bp_displayed_user_id(), home_url( '/' ) ),
				$nonce_action,
				"bphu-{$action}"
			),
			'link_text'         => $link_text,
			'link_class'        => 'bp-hide-user',
		) );

		// output the HTML button
		bp_button( $button );
	}


	/**
	 * Listens to the hide button action and does stuff when it is invoked.
	 */
	public function action_listener() {
		if ( ! bp_is_root_blog() ) {
			return;
		}

		if ( empty( $_GET['uid'] ) || ! is_user_logged_in() ) {
			return;
		}

		$action = false;

		if ( ! empty( $_GET['bphu-hide'] ) || ! empty( $_GET['bphu-unhide'] ) ) {
			$nonce   = ! empty( $_GET['bphu-hide'] ) ? $_GET['bphu-hide'] : $_GET['bphu-unhide'];
			$action  = ! empty( $_GET['bphu-hide'] ) ? 'hide' : 'unhide';
		}

		if ( ! $action ) {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, "bp_hu_{$action}_user" ) ) {
			return;
		}

		$user_id = (int) $_GET['uid'];

		// sanity check!
		if ( $user_id == bp_loggedin_user_id() ) {
			return;
		}

		$log = bp_get_option( 'bp_hide_user_log' );
		if ( empty( $log ) ) {
			$log = array();
		}

		if ( 'hide' == $action ) {
			// delete BP's last_activity entry so the user will not show up in the directory
			// @todo still can be found in the 'alphabetical' members directory filter though
			BP_Core_User::delete_last_activity( $user_id );

			$message = __( 'You have successfully hid this user.', 'bp-hide-user' );

			// add user to log
			if ( ! isset( $log[$user_id] ) ) {
				$log[$user_id] = true;
			}

		} else {
			$last_activity = BP_Activity_Activity::get( array(
				'per_page' => 1,
				'filter'   => array(
					'user_id' => $user_id,
				)
			) );

			if ( ! empty( $last_activity['activities'] ) ) {
				$timestamp = $last_activity['activities'][0]->date_recorded;
			} else {
				$timestamp = bp_core_current_time();
			}

			// re-record user's last activity
			bp_update_user_last_activity( $user_id, $timestamp );

			// remove from log
			if ( isset( $log[$user_id] ) ) {
				unset( $log[$user_id] );
			}

			$message = __( 'You have successfully reinstated this user.', 'bp-hide-user' );
		}

		// update log
		if ( ! empty( $log ) ) {
			bp_update_option( 'bp_hide_user_log', $log );
		}

		// hook for plugins
		do_action( "bp_hu_{$action}_user", $user_id );

		// delete active member count transient
		delete_transient( 'bp_active_member_count' );

		// add feedback message
		bp_core_add_message( $message );

		// redirect
		bp_core_redirect( bp_core_get_user_domain( $user_id ) );
		die();
	}

	/**
	 * Block visits to a hidden user's profile page.
	 *
	 * To override this, add this code snippet in bp-custom.php:
	 *     add_filter( 'bp_hu_block_hidden_user_profile', '__return_false' );
	 */
	public function block_hidden_user_profile() {
		if ( ! bp_is_user() ) {
			return;
		}

		// return false if you don't want to block hidden profiles from public view
		if ( bp_current_user_can( 'bp_moderate' ) || false === (bool) apply_filters( 'bp_hu_block_hidden_user_profile', true ) ) {
			return;
		}

		$log = bp_get_option( 'bp_hide_user_log' );

		// user is not in log, so stop!
		if ( ! isset( $log[bp_displayed_user_id()] ) ) {
			return;
		}

		// add feedback message
		bp_core_add_message( __( 'The user account you tried to access is restricted.', 'bp-hide-user' ), 'error' );

		// redirect
		$redirect = bp_loggedin_user_id() ? bp_loggedin_user_domain() : bp_get_members_directory_permalink();
		bp_core_redirect( $redirect );
		die();
	}

	/**
	 * BP bug - need to add 'template_notices' hook to members directory.
	 */
	public function add_template_notices_to_members_directory() {
		if ( ! did_action( 'template_notices' ) ) {
			do_action( 'template_notices' );
		}
	}
}