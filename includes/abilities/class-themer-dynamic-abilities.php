<?php
/**
 * MCP discovery for Themer dynamic sources.
 *
 * An agent cannot bind a field without first knowing which sources exist and
 * what each one produces, so this is the read step that precedes every write.
 *
 * @package EMCP_Tools
 * @since   3.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.13.0
 */
class EMCP_Tools_Themer_Dynamic_Abilities {

	/**
	 * Ability names registered by this group.
	 *
	 * @var string[]
	 */
	private $ability_names = array();

	/**
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/** Register the ability. */
	public function register(): void {
		$this->ability_names[] = 'emcp-tools/list-dynamic-sources';
		emcp_tools_register_ability(
			'emcp-tools/list-dynamic-sources',
			array(
				'label'               => __( 'List Dynamic Sources', 'emcp-lite' ),
				'description'         => __( 'Lists the dynamic data sources a Themer template can bind to (post title, featured image, post URL and so on), with the value type each produces and which surfaces accept it. Call this before binding a field with the dynamic or bindings input on the widget and block tools.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'bindable_only' => array(
							'type'        => 'boolean',
							'description' => __( 'Return only sources that can fill a field, excluding markup-only sources.', 'emcp-lite' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'sources' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
						'count'   => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * @param array $input { bindable_only }.
	 * @return array{sources:array,count:int}
	 */
	public function execute_list( $input ): array {
		return self::list_sources( is_array( $input ) ? $input : array() );
	}

	/**
	 * @param array $input { bindable_only }.
	 * @return array{sources:array,count:int}
	 */
	public static function list_sources( array $input = array() ): array {
		$only = ! empty( $input['bindable_only'] );
		$out  = array();
		// The block registrar is the only honest source of block coverage.
		$blocks = class_exists( 'EMCP_Tools_Themer_Blocks' )
			? EMCP_Tools_Themer_Blocks::blocks()
			: array();
		foreach ( EMCP_Tools_Themer_Dynamic_Catalog::all() as $key => $def ) {
			if ( $only && empty( $def['bindable'] ) ) {
				continue;
			}
			// Report only surfaces that actually exist. A source can be bindable
			// without shipping its own block or widget, and claiming otherwise
			// sends an agent looking for something that was never registered.
			// Widget and block coverage are not the same set: five sources ship
			// an Elementor widget with no block counterpart, because the block
			// editor already has a core block for them.
			$surfaces = array();
			if ( ! empty( $def['element'] ) ) {
				$surfaces[] = 'widget';
			}
			if ( isset( $blocks[ $key ] ) ) {
				$surfaces[] = 'block';
			}
			if ( ! empty( $def['bindable'] ) ) {
				$surfaces[] = 'elementor-tag';
				$surfaces[] = 'block-binding';
			}
			$out[] = array(
				'key'      => (string) $key,
				'label'    => (string) $def['label'],
				'type'     => (string) $def['type'],
				'args'     => (array) $def['args'],
				'bindable' => (bool) $def['bindable'],
				'surfaces' => $surfaces,
			);
		}
		return array( 'sources' => $out, 'count' => count( $out ) );
	}
}
