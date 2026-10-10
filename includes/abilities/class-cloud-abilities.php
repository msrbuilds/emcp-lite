<?php
/**
 * EMCP Cloud MCP abilities — status, backup, list, pull, config sync.
 *
 * Free tree. Registered only when the site is connected to EMCP Cloud (the
 * Cloud module is active and a token bundle is stored). Each tool delegates to
 * EMCP_Tools_Cloud_Sync and requires manage_options.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EMCP_Tools_Cloud_Abilities {

	/**
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'emcp-tools/cloud-status',
			'emcp-tools/cloud-config-sync',
			'emcp-tools/cloud-config-inspect',
		);
	}

	/**
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @return void
	 */
	public function register(): void {
		emcp_tools_register_ability( 'emcp-tools/cloud-config-inspect', array(
			'label' => __( 'Inspect managed Cloud settings', 'emcp-lite' ),
			'description' => __( 'Read a versioned, fixed allowlist of stored EMCP settings for configuration previews. No settings are changed; secrets and free-text context are excluded.', 'emcp-lite' ),
			'category' => 'emcp-tools', 'execute_callback' => array( $this, 'execute_config_inspect' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'input_schema' => array( 'type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false ),
			'output_schema' => array( 'type' => 'object' ),
			'meta' => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'show_in_rest' => true ),
		) );
		emcp_tools_register_ability(
			'emcp-tools/cloud-status',
			array(
				'label'               => __( 'Cloud Status', 'emcp-lite' ),
				'description'         => __( 'Return the EMCP Cloud plan, limits, and usage for the connected account.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => array( 'description' => array( 'type' => 'string' ) ) ),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'show_in_rest' => true ),
			)
		);



		emcp_tools_register_ability(
			'emcp-tools/cloud-config-sync',
			array(
				'label'               => __( 'Cloud Config Sync', 'emcp-lite' ),
				'description'         => __( 'Push or pull a config blob (settings, brand_kit, tool_toggles) to/from EMCP Cloud.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_config_sync' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'type'      => array( 'type' => 'string', 'enum' => array( 'settings', 'brand_kit', 'tool_toggles' ) ),
						'direction' => array( 'type' => 'string', 'enum' => array( 'push', 'pull' ) ),
						'data'      => array( 'type' => 'object' ),
					),
					'required'   => array( 'type', 'direction' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false ), 'show_in_rest' => true ),
			)
		);


	}

	public function execute_config_inspect( $input ) {
		if ( ! empty( (array) $input ) ) { return new WP_Error( 'invalid_input', 'This read accepts no settings or actions.' ); }
		return EMCP_Tools_Settings_Sync::managed_snapshot();
	}

	/**
	 * @param array $input { category? }.
	 * @return array|WP_Error
	 */


	/**
	 * @param array $input { slug }.
	 * @return array|WP_Error
	 */


	/**
	 * @return array|WP_Error
	 */
	public function execute_status() {
		$r = EMCP_Tools_Cloud_Sync::status();
		return is_wp_error( $r ) ? $r : (array) $r;
	}

	/**
	 * @param array $input { kind, id }.
	 * @return array|WP_Error
	 */


	/**
	 * @param array $input { kind? }.
	 * @return array|WP_Error
	 */


	/**
	 * @param array $input { artifact_uuid, kind? }.
	 * @return array|WP_Error
	 */


	/**
	 * @param array $input { type, direction, data? }.
	 * @return array|WP_Error
	 */
	public function execute_config_sync( $input ) {
		$type      = isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : '';
		$direction = isset( $input['direction'] ) ? sanitize_key( (string) $input['direction'] ) : 'pull';
		if ( 'push' === $direction ) {
			$data = isset( $input['data'] ) && is_array( $input['data'] ) ? $input['data'] : array();
			$r    = EMCP_Tools_Cloud_Sync::push_config( $type, $data );
		} else {
			$r = EMCP_Tools_Cloud_Sync::pull_config( $type );
		}
		return is_wp_error( $r ) ? $r : (array) $r;
	}
}
