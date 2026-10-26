<?php
/** Read-only WordPress plugin discovery for EMCP Lite. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the plugin lifecycle abilities.
 *
 * @since 3.0.0
 */
class EMCP_Tools_Plugin_Abilities {

	/** @since 3.0.0 @var string[] */
	private $ability_names = array();

	/** @since 3.0.0 @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/** @since 3.0.0 */
	public function register(): void {
		$this->register_list_plugins();
		$this->register_search_plugins();
	}

	// -------------------------------------------------------------------
	// Permission callbacks (per-op capability)
	// -------------------------------------------------------------------

	public function can_list(): bool { return current_user_can( 'activate_plugins' ); }
	public function can_install(): bool { return current_user_can( 'install_plugins' ); }
	public function can_activate(): bool { return current_user_can( 'activate_plugins' ); }
	public function can_update(): bool { return current_user_can( 'update_plugins' ); }
	public function can_delete(): bool { return current_user_can( 'delete_plugins' ); }

	// -------------------------------------------------------------------
	// list-plugins
	// -------------------------------------------------------------------

	private function register_list_plugins(): void {
		$this->ability_names[] = 'emcp-tools/list-plugins';
		emcp_tools_register_ability(
			'emcp-tools/list-plugins',
			array(
				'label'               => __( 'List Plugins', 'emcp-lite' ),
				'description'         => __( 'Lists installed WordPress plugins with status (active/inactive/network), version, whether an update is available, and whether the plugin is protected (EMCP Tools / Elementor can never be disabled via MCP). Optional "status" filter.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_list_plugins' ),
				'permission_callback' => array( $this, 'can_list' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array( 'type' => 'string', 'enum' => array( 'all', 'active', 'inactive' ), 'description' => __( 'Filter by status. Default: all.', 'emcp-lite' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'plugins' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_plugins( $input ): array {
		EMCP_Tools_Package_Guard::load_admin_includes();
		$filter  = in_array( $input['status'] ?? 'all', array( 'all', 'active', 'inactive' ), true ) ? ( $input['status'] ?? 'all' ) : 'all';
		$all     = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$updates = get_site_transient( 'update_plugins' );
		$resp    = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) ? $updates->response : array();

		$rows = array();
		foreach ( $all as $file => $data ) {
			$active = EMCP_Tools_Package_Guard::is_active_plugin( (string) $file );
			if ( 'active' === $filter && ! $active ) { continue; }
			if ( 'inactive' === $filter && $active ) { continue; }
			$rows[] = array(
				'file'             => (string) $file,
				'slug'             => dirname( (string) $file ),
				'name'             => (string) ( $data['Name'] ?? $file ),
				'version'          => (string) ( $data['Version'] ?? '' ),
				'author'           => wp_strip_all_tags( (string) ( $data['Author'] ?? '' ) ),
				'active'           => $active,
				'is_protected'     => EMCP_Tools_Package_Guard::is_protected_plugin( (string) $file ),
				'update_available' => isset( $resp[ $file ] ),
				'new_version'      => isset( $resp[ $file ]->new_version ) ? (string) $resp[ $file ]->new_version : '',
			);
		}
		return array( 'plugins' => $rows );
	}

	// -------------------------------------------------------------------
	// search-plugins
	// -------------------------------------------------------------------

	private function register_search_plugins(): void {
		$this->ability_names[] = 'emcp-tools/search-plugins';
		emcp_tools_register_ability(
			'emcp-tools/search-plugins',
			array(
				'label'               => __( 'Search Plugins', 'emcp-lite' ),
				'description'         => __( 'Searches the wordpress.org plugin directory by keyword so you can find a slug to install. Returns slug, name, version, rating, and requirements. Read-only.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_search_plugins' ),
				'permission_callback' => array( $this, 'can_install' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => __( 'Keyword(s) to search the .org directory.', 'emcp-lite' ) ),
						'per_page' => array( 'type' => 'integer', 'description' => __( '1-50. Default: 10.', 'emcp-lite' ) ),
					),
					'required'   => array( 'search' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'results' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_search_plugins( $input ) {
		$search = sanitize_text_field( $input['search'] ?? '' );
		if ( '' === $search ) {
			return new \WP_Error( 'missing_params', __( 'A "search" keyword is required.', 'emcp-lite' ) );
		}
		EMCP_Tools_Package_Guard::load_admin_includes();
		$per_page = max( 1, min( 50, absint( $input['per_page'] ?? 10 ) ) );
		$api      = plugins_api( 'query_plugins', array( 'search' => $search, 'per_page' => $per_page, 'fields' => array( 'short_description' => true, 'icons' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$rows = array();
		foreach ( (array) ( $api->plugins ?? array() ) as $p ) {
			$p     = (object) $p;
			$rows[] = array(
				'slug'              => (string) ( $p->slug ?? '' ),
				'name'              => wp_strip_all_tags( (string) ( $p->name ?? '' ) ),
				'version'           => (string) ( $p->version ?? '' ),
				'rating'            => (int) ( $p->rating ?? 0 ),
				'num_ratings'       => (int) ( $p->num_ratings ?? 0 ),
				'requires'          => (string) ( $p->requires ?? '' ),
				'tested'            => (string) ( $p->tested ?? '' ),
				'short_description' => wp_strip_all_tags( (string) ( $p->short_description ?? '' ) ),
			);
		}
		return array( 'results' => $rows );
	}

}
