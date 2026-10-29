<?php
/** Free Cptui tools, shared with the separately packaged Lite edition. */
defined( 'ABSPATH' ) || exit;

final class EMCP_Tools_Cptui_Integration {

	public static function operations(): array {
		return array(
			'status' => array( 'read', '', array(), array() ),
			'list-post-types' => array( 'read', '', array( 'page', 'per_page' ), array() ),
			'list-taxonomies' => array( 'read', '', array( 'page', 'per_page' ), array() ),
			'list-registered-post-types' => array( 'read', '', array( 'page', 'per_page' ), array() ),
			'get-post-type' => array( 'read', '', array( 'slug' ), array( 'slug' ) ),
			'get-taxonomy' => array( 'read', '', array( 'slug' ), array( 'slug' ) ),
			'create-post-type' => array( 'write', '', array( 'slug', 'label', 'singular_label', 'description', 'public', 'show_in_rest', 'hierarchical', 'supports' ), array( 'slug', 'label', 'singular_label' ) ),
			'create-taxonomy' => array( 'write', '', array( 'slug', 'label', 'singular_label', 'description', 'public', 'show_in_rest', 'hierarchical', 'object_types' ), array( 'slug', 'label', 'singular_label', 'object_types' ) ),
			'update-post-type' => array( 'write', '', array( 'slug', 'version', 'label', 'singular_label', 'description' ), array( 'slug', 'version' ) ),
			'update-taxonomy' => array( 'write', '', array( 'slug', 'version', 'label', 'singular_label', 'description' ), array( 'slug', 'version' ) ),
		);
	}

	public static function fields(): array {
		return array(
			'slug' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,19}$' ),
			'page' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000 ),
			'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
			'version' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
			'label' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
			'singular_label' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
			'description' => array( 'type' => 'string', 'maxLength' => 2000 ),
			'public' => array( 'type' => 'boolean' ),
			'show_in_rest' => array( 'type' => 'boolean' ),
			'hierarchical' => array( 'type' => 'boolean' ),
			'supports' => array( 'type' => 'array', 'maxItems' => 8, 'uniqueItems' => true, 'items' => array( 'type' => 'string', 'enum' => EMCP_Tools_Cptui_Api::SUPPORTS ) ),
			'object_types' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'uniqueItems' => true, 'items' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,19}$' ) ),
			'confirm' => array( 'type' => 'boolean' ),
			'dry_run' => array( 'type' => 'boolean' ),
		);
	}

	public static function operation_schema( string $name ): array {
		$op = self::operations()[ $name ];
		$fields = $op[2];
		$required = $op[3];
		if ( 'write' === $op[0] ) { $fields = array_merge( $fields, array( 'confirm', 'dry_run' ) ); $required[] = 'confirm'; }
		return array( 'type' => 'object', 'properties' => array_intersect_key( self::fields(), array_flip( $fields ) ), 'required' => $required, 'additionalProperties' => false );
	}

	public function get_ability_names(): array {
		return array( 'emcp-tools/cptui-read', 'emcp-tools/cptui-write' );
	}

	public function register(): void {
		foreach ( array( 'read', 'write' ) as $mode ) {
			emcp_tools_register_ability( 'emcp-tools/cptui-' . $mode, array(
				'label' => 'CPT UI ' . ucfirst( $mode ),
				'description' => 'read' === $mode
					? __( 'Inspect CPT UI-owned post types and taxonomies, registered public types and schema versions. Call without an operation for schemas.', 'emcp-lite' )
					: __( 'Create structured CPT UI post types and taxonomies; update labels and descriptions. Slugs immutable, no code or custom capabilities. Requires confirm and current version for updates. Undo of creation refuses populated definitions. Writes default off.', 'emcp-lite' ),
				'category' => 'emcp-tools',
				'permission_callback' => array( $this, 'permission' ),
				'execute_callback' => array( $this, 'run_' . $mode ),
				'input_schema' => array( 'type' => 'object', 'properties' => array(
					'operation' => array( 'type' => 'string' ),
					'arguments' => array( 'type' => 'object', 'properties' => self::fields(), 'additionalProperties' => false ),
				), 'additionalProperties' => false ),
				'meta' => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => 'read' === $mode, 'destructive' => 'write' === $mode, 'idempotent' => 'read' === $mode ) ),
			) );
		}
	}

	public function permission(): bool { return EMCP_Tools_Cptui_Api::allowed(); }
	public function run_read( $input ) { return $this->run( 'read', $input ); }
	public function run_write( $input ) { return $this->run( 'write', $input ); }

	private function run( string $mode, $input ) {
		if ( ! $this->permission() ) { return EMCP_Tools_Cptui_Api::error( 'forbidden', __( 'Administrator access is required.', 'emcp-lite' ) ); }
		if ( in_array( 'emcp-tools/cptui-' . $mode, (array) get_option( 'emcp_tools_disabled_tools', array() ), true ) ) {
			return EMCP_Tools_Cptui_Api::error( 'tool_disabled', __( 'Enable this tool in EMCP Tools first.', 'emcp-lite' ) );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'operation', 'arguments' ) ) || ( isset( $input['operation'] ) && ! is_string( $input['operation'] ) ) || ( isset( $input['arguments'] ) && ! is_array( $input['arguments'] ) ) ) {
			return EMCP_Tools_Cptui_Api::error( 'invalid_input', __( 'Expected an operation and an arguments object.', 'emcp-lite' ) );
		}
		$ready = EMCP_Tools_Cptui_Api::ready();
		if ( is_wp_error( $ready ) ) { return $ready; }
		$name = $input['operation'] ?? '';
		$ops = self::operations();
		if ( '' === $name ) {
			$catalog = array();
			foreach ( $ops as $key => $op ) {
				if ( $mode === $op[0] && EMCP_Tools_Cptui_Api::allowed() ) { $catalog[ $key ] = self::operation_schema( $key ); }
			}
			return array( 'operations' => $catalog );
		}
		if ( ! isset( $ops[ $name ] ) || $mode !== $ops[ $name ][0] ) { return EMCP_Tools_Cptui_Api::error( 'unknown_operation', __( 'Call without an operation to discover supported operations.', 'emcp-lite' ) ); }
		if ( ! EMCP_Tools_Cptui_Api::allowed() ) { return EMCP_Tools_Cptui_Api::error( 'forbidden', __( 'Cptui permission denied.', 'emcp-lite' ) ); }
		$args = $input['arguments'] ?? array();
		// Strict transports add null placeholders for optional union fields. Empty strings remain intentional values.
		$args = array_filter( $args, static function ( $value ) { return null !== $value; } );
		$valid = rest_validate_value_from_schema( $args, self::operation_schema( $name ), 'arguments' );
		if ( is_wp_error( $valid ) ) { return $valid; }
		// WordPress schema validation permits numeric strings. This contract requires actual integers and booleans.
		foreach ( $args as $key => $value ) {
			$type = self::fields()[ $key ]['type'] ?? '';
			if ( ( 'integer' === $type && ! is_int( $value ) ) || ( 'boolean' === $type && ! is_bool( $value ) ) ) { return EMCP_Tools_Cptui_Api::error( 'invalid_input', __( 'Use typed integer and boolean arguments.', 'emcp-lite' ) ); }
		}
		if ( 'read' === $mode ) { return EMCP_Tools_Cptui_Api::read( $name, $args ); }
		if ( true !== $args['confirm'] ) { return EMCP_Tools_Cptui_Api::error( 'confirm_required', __( 'Writes require confirm:true.', 'emcp-lite' ) ); }
		return EMCP_Tools_Cptui_Writer::write( $name, $args );
	}
}
