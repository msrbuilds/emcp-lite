<?php
/**
 * REST endpoints the frame's shell calls.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notification read state and the sidebar's collapsed state.
 */
final class EMCP_Tools_Admin_REST_Frame extends EMCP_Tools_Admin_REST_Controller {

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$this->route(
			'notifications/read',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'read_notifications' ),
				'args'     => array(
					'ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
					),
				),
			)
		);
		$this->route(
			'frame/sidebar',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'save_sidebar' ),
				'args'     => array(
					'collapsed' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Remember whether the current user keeps the sidebar collapsed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_sidebar( $request ) {
		$collapsed = rest_sanitize_boolean( $request->get_param( 'collapsed' ) );
		if ( $collapsed ) {
			update_user_meta( get_current_user_id(), EMCP_Tools_Admin_Frame::SIDEBAR_META, '1' );
		} else {
			delete_user_meta( get_current_user_id(), EMCP_Tools_Admin_Frame::SIDEBAR_META );
		}
		return new WP_REST_Response( array( 'collapsed' => $collapsed ) );
	}

	/**
	 * Mark notifications read and return the new unread count. Lite has no
	 * notification feed, so there is nothing to mark and the count is 0.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function read_notifications( $request ) {
		return new WP_REST_Response( array( 'unread' => 0 ) );
	}
}
