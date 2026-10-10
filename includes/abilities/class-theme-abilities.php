<?php
/** Read-only WordPress theme discovery for EMCP Lite. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the theme lifecycle abilities.
 *
 * @since 3.0.0
 */
class EMCP_Tools_Theme_Abilities {

	/** @since 3.0.0 @var string[] */
	private $ability_names = array();

	/** @since 3.0.0 @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/** @since 3.0.0 */
	public function register(): void {
		$this->register_list_themes();
		$this->register_search_themes();
	}

	public function can_list(): bool { return current_user_can( 'switch_themes' ); }
	public function can_install(): bool { return current_user_can( 'install_themes' ); }
	public function can_switch(): bool { return current_user_can( 'switch_themes' ); }
	public function can_update(): bool { return current_user_can( 'update_themes' ); }
	public function can_delete(): bool { return current_user_can( 'delete_themes' ); }

	// -------------------------------------------------------------------
	// list-themes
	// -------------------------------------------------------------------

	private function register_list_themes(): void {
		$this->ability_names[] = 'emcp-tools/list-themes';
		emcp_tools_register_ability(
			'emcp-tools/list-themes',
			array(
				'label'               => __( 'List Themes', 'emcp-lite' ),
				'description'         => __( 'Lists installed themes with version, active status, parent (for child themes), and whether an update is available.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_list_themes' ),
				'permission_callback' => array( $this, 'can_list' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass() ),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'themes' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_themes( $input ): array {
		EMCP_Tools_Package_Guard::load_upgrader_deps();
		$active  = function_exists( 'get_stylesheet' ) ? (string) get_stylesheet() : '';
		$updates = get_site_transient( 'update_themes' );
		$resp    = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) ? $updates->response : array();
		$themes  = function_exists( 'wp_get_themes' ) ? wp_get_themes() : array();

		$rows = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$stylesheet = (string) $stylesheet;
			$parent     = ( is_object( $theme ) && method_exists( $theme, 'get_template' ) ) ? (string) $theme->get_template() : $stylesheet;
			$rows[]     = array(
				'stylesheet'       => $stylesheet,
				'name'             => is_object( $theme ) ? (string) $theme->get( 'Name' ) : $stylesheet,
				'version'          => is_object( $theme ) ? (string) $theme->get( 'Version' ) : '',
				'parent'           => ( $parent !== $stylesheet ) ? $parent : '',
				'is_active'        => ( $stylesheet === $active ),
				'update_available' => isset( $resp[ $stylesheet ] ),
				'new_version'      => isset( $resp[ $stylesheet ]['new_version'] ) ? (string) $resp[ $stylesheet ]['new_version'] : '',
			);
		}
		return array( 'themes' => $rows );
	}

	// -------------------------------------------------------------------
	// search-themes
	// -------------------------------------------------------------------

	private function register_search_themes(): void {
		$this->ability_names[] = 'emcp-tools/search-themes';
		emcp_tools_register_ability(
			'emcp-tools/search-themes',
			array(
				'label'               => __( 'Search Themes', 'emcp-lite' ),
				'description'         => __( 'Searches the wordpress.org theme directory by keyword. Returns slug, name, version, rating, and requirements. Read-only.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_search_themes' ),
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
	public function execute_search_themes( $input ) {
		$search = sanitize_text_field( $input['search'] ?? '' );
		if ( '' === $search ) {
			return new \WP_Error( 'missing_params', __( 'A "search" keyword is required.', 'emcp-lite' ) );
		}
		EMCP_Tools_Package_Guard::load_upgrader_deps();
		$per_page = max( 1, min( 50, absint( $input['per_page'] ?? 10 ) ) );
		$api      = themes_api( 'query_themes', array( 'search' => $search, 'per_page' => $per_page, 'fields' => array( 'description' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$rows = array();
		foreach ( (array) ( $api->themes ?? array() ) as $t ) {
			$t      = (object) $t;
			$rows[] = array(
				'slug'     => (string) ( $t->slug ?? '' ),
				'name'     => wp_strip_all_tags( (string) ( $t->name ?? '' ) ),
				'version'  => (string) ( $t->version ?? '' ),
				'rating'   => (int) ( $t->rating ?? 0 ),
				'requires' => (string) ( $t->requires ?? '' ),
			);
		}
		return array( 'results' => $rows );
	}

}
