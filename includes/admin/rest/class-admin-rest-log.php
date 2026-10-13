<?php
/**
 * MCP Log REST (spec 8.22). Cookie-only through the base controller.
 *
 * @package EMCP_Tools
 * @since   3.18.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP Log REST controller.
 */
final class EMCP_Tools_Admin_REST_Log extends EMCP_Tools_Admin_REST_Controller {

	const EXPORT_ROUTE = '/emcp-tools/v1/admin/log/export.csv';

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$this->route( 'log/export\.csv', array( 'methods' => 'GET', 'callback' => array( $this, 'export' ) ) );
		$this->route(
			'log',
			array(
				array(
					'methods'  => 'GET',
					'callback' => array( $this, 'get' ),
				),
				array(
					'methods'  => 'DELETE',
					'callback' => array( $this, 'delete' ),
				),
			)
		);
	}

	/**
	 * Register the MCP Log screen.
	 */
	public static function register_screen(): void {
		EMCP_Tools_Admin_Screens::register(
			'log',
			array(
				'script' => 'screen-log',
				'tabs'   => array( 'mcp-log' ),
				'boot'   => static function (): array {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view filters, sanitized by payload().
					$get = array();
					foreach ( array( 'status', 'search', 'paged' ) as $key ) {
						if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ) {
							$get[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
						}
					}
					$get = is_array( $get ) ? $get : array();
					return ( new EMCP_Tools_Admin_Log_Data() )->payload(
						array(
							'status' => $get['status'] ?? 'all',
							'search' => $get['search'] ?? '',
							'page'   => $get['paged'] ?? 1,
						)
					);
				},
			)
		);
	}

	/**
	 * The filters a request carried.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function args( $request ): array {
		return array(
			'status' => ( is_string( $request->get_param( 'status' ) ) ? sanitize_text_field( $request->get_param( 'status' ) ) : '' ),
			'search' => sanitize_text_field( ( is_string( $request->get_param( 'search' ) ) ? sanitize_text_field( $request->get_param( 'search' ) ) : '' ) ),
			'page'   => ( is_scalar( $request->get_param( 'page' ) ) ? absint( $request->get_param( 'page' ) ) : 1 ),
		);
	}

	/**
	 * GET admin/log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get( $request ) {
		return new WP_REST_Response( ( new EMCP_Tools_Admin_Log_Data() )->payload( self::args( $request ) ) );
	}

	/**
	 * DELETE admin/log (needs confirm: true).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( $request ) {
		if ( true !== rest_sanitize_boolean( $request->get_param( 'confirm' ) ) ) {
			return new WP_Error( 'confirm_required', __( 'Confirm this action to continue.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		EMCP_Tools_MCP_Request_Log::clear();
		return new WP_REST_Response( ( new EMCP_Tools_Admin_Log_Data() )->payload( array() ) );
	}

	/**
	 * GET admin/log/export.csv: the CSV for the filters in view.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function export( $request ) {
		$res = new WP_REST_Response( array(
			'csv' => EMCP_Tools_MCP_Request_Log::export_csv( self::args( $request ) ),
			'filename' => 'emcp-mcp-log-' . gmdate( 'Ymd-His' ) . '.csv',
		) );
		$res->header( 'Cache-Control', 'no-store' );
		$res->header( 'X-Content-Type-Options', 'nosniff' );
		return $res;
	}

}
