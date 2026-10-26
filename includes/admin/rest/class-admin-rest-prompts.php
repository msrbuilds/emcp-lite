<?php
/**
 * Prompts screen REST (spec 8.8).
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Admin_REST_Prompts extends EMCP_Tools_Admin_REST_Controller {

	public function register_routes(): void {
		$this->route(
			'prompts',
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'get' ),
				'args'     => array(
					'search'   => array( 'type' => 'string', 'default' => '' ),
					'category' => array( 'type' => 'string', 'default' => '' ),
					'page'     => array( 'type' => 'integer', 'default' => 1 ),
				),
			)
		);
		$this->route( 'prompts/sync', array( 'methods' => 'POST', 'callback' => array( $this, 'sync' ) ) );
		$this->route( 'prompts/notice/dismiss', array( 'methods' => 'POST', 'callback' => array( $this, 'dismiss_notice' ) ) );
		$this->route( 'prompts/(?P<category>[a-z0-9-]+)/(?P<slug>[a-z0-9-]+)/copied', array( 'methods' => 'POST', 'callback' => array( $this, 'copied' ) ) );
	}

	public static function register_screen(): void {
		EMCP_Tools_Admin_Screens::register(
			'prompts',
			array(
				'script' => 'screen-prompts',
				'tabs'   => array( 'prompts' ),
				'boot'   => static function (): array {
					return ( new EMCP_Tools_Admin_Prompts_Data() )->payload();
				},
			)
		);
	}

	private static function query( $request ): array {
		return array(
			'search'   => sanitize_text_field( self::string_param( $request, 'search' ) ),
			'category' => sanitize_key( self::string_param( $request, 'category' ) ),
			'page'     => max( 1, self::int_param( $request, 'page' ) ),
		);
	}

	public function get( $request ) {
		return new WP_REST_Response( ( new EMCP_Tools_Admin_Prompts_Data() )->payload( self::query( $request ) ) );
	}

	public function sync( $request ) {
		return new WP_Error( 'emcp_no_license', __( 'Syncing the prompt library needs an active EMCP Pro licence.', 'emcp-lite' ), array( 'status' => 403 ) );
	}

	public function dismiss_notice( $request ) {
		update_user_meta( get_current_user_id(), EMCP_Tools_Admin::META_PROMPTS_NOTICE_DISMISSED, '1' );
		return new WP_REST_Response( array( 'dismissed' => true ) );
	}

	public function copied( $request ) {
		$category = sanitize_key( self::route_param( $request, 'category' ) );
		$slug     = sanitize_key( self::route_param( $request, 'slug' ) );
		$prompt   = ( new EMCP_Tools_Admin_Prompts_Data() )->find( $category, $slug );
		if ( null === $prompt ) {
			return new WP_Error( 'emcp_unknown_prompt', __( 'That prompt is not in the library.', 'emcp-lite' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( array( 'recorded' => false ) );
	}
}
