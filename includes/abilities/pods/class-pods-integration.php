<?php
/** Free Pods tools, shared with the separately packaged Lite edition. */
defined( 'ABSPATH' ) || exit;

final class EMCP_Tools_Pods_Integration {

	public static function operations(): array {
		return array(
			'status' => array( 'read', '', array(), array() ),
			'list-pods' => array( 'read', '', array( 'page', 'per_page' ), array() ),
			'get-pod' => array( 'read', '', array( 'pod' ), array( 'pod' ) ),
			'list-fields' => array( 'read', '', array( 'pod', 'page', 'per_page' ), array( 'pod' ) ),
			'list-records' => array( 'read', '', array( 'pod', 'page', 'per_page' ), array( 'pod' ) ),
			'get-record' => array( 'read', '', array( 'pod', 'id' ), array( 'pod', 'id' ) ),
			'create-pod' => array( 'write', '', array( 'pod', 'label', 'fields' ), array( 'pod', 'label', 'fields' ) ),
			'update-pod-label' => array( 'write', '', array( 'pod', 'version', 'label' ), array( 'pod', 'version', 'label' ) ),
			'create-record' => array( 'write', '', array( 'pod', 'title', 'values' ), array( 'pod', 'title', 'values' ) ),
			'update-record' => array( 'write', '', array( 'pod', 'id', 'version', 'values' ), array( 'pod', 'id', 'version', 'values' ) ),
		);
	}

	public static function fields(): array {
		return array(
			'pod' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,19}$' ),
			'id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'page' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000 ),
			'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
			'version' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
			'label' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
			'title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
			'fields' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => array( 'type' => 'object', 'properties' => array(
				'name' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,39}$' ),
				'label' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ),
				'type' => array( 'type' => 'string', 'enum' => EMCP_Tools_Pods_Api::TYPES ),
			), 'required' => array( 'name', 'label', 'type' ), 'additionalProperties' => false ) ),
			'values' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => array( 'type' => 'object', 'properties' => array(
				'name' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,39}$' ),
				'value' => array( 'type' => array( 'string', 'integer', 'boolean' ) ),
			), 'required' => array( 'name', 'value' ), 'additionalProperties' => false ) ),
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
		return array( 'emcp-tools/pods-read', 'emcp-tools/pods-write' );
	}

	public function register(): void {
		foreach ( array( 'read', 'write' ) as $mode ) {
			emcp_tools_register_ability( 'emcp-tools/pods-' . $mode, array(
				'label' => 'Pods ' . ucfirst( $mode ),
				'description' => 'read' === $mode
					? __( 'Inspect public post/meta Pods, permitted fields and records. Text, paragraph, integer and boolean field values only. No raw SQL or executable templates. Call without an operation for schemas.', 'emcp-lite' )
					: __( 'Create a post/meta Pod with scalar fields, edit its label, create draft records or update scalar values. Creation is irreversible; edits support conflict-aware History. Requires confirmation and current versions for edits. Writes default off.', 'emcp-lite' ),
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

	public function permission(): bool { return EMCP_Tools_Pods_Api::allowed(); }
	public function run_read( $input ) { return $this->run( 'read', $input ); }
	public function run_write( $input ) { return $this->run( 'write', $input ); }

	private function run( string $mode, $input ) {
		if ( ! $this->permission() ) { return EMCP_Tools_Pods_Api::error( 'forbidden', __( 'Administrator access is required.', 'emcp-lite' ) ); }
		if ( in_array( 'emcp-tools/pods-' . $mode, (array) get_option( 'emcp_tools_disabled_tools', array() ), true ) ) {
			return EMCP_Tools_Pods_Api::error( 'tool_disabled', __( 'Enable this tool in EMCP Tools first.', 'emcp-lite' ) );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'operation', 'arguments' ) ) || ( isset( $input['operation'] ) && ! is_string( $input['operation'] ) ) || ( isset( $input['arguments'] ) && ! is_array( $input['arguments'] ) ) ) {
			return EMCP_Tools_Pods_Api::error( 'invalid_input', __( 'Expected an operation and an arguments object.', 'emcp-lite' ) );
		}
		$ready = EMCP_Tools_Pods_Api::ready();
		if ( is_wp_error( $ready ) ) { return $ready; }
		$name = $input['operation'] ?? '';
		$ops = self::operations();
		if ( '' === $name ) {
			$catalog = array();
			foreach ( $ops as $key => $op ) {
				if ( $mode === $op[0] && EMCP_Tools_Pods_Api::allowed() ) { $catalog[ $key ] = self::operation_schema( $key ); }
			}
			return array( 'operations' => $catalog );
		}
		if ( ! isset( $ops[ $name ] ) || $mode !== $ops[ $name ][0] ) { return EMCP_Tools_Pods_Api::error( 'unknown_operation', __( 'Call without an operation to discover supported operations.', 'emcp-lite' ) ); }
		if ( ! EMCP_Tools_Pods_Api::allowed() ) { return EMCP_Tools_Pods_Api::error( 'forbidden', __( 'Pods permission denied.', 'emcp-lite' ) ); }
		$args = $input['arguments'] ?? array();
		// Strict transports add null placeholders for optional union fields. Empty strings remain intentional values.
		$args = array_filter( $args, static function ( $value ) { return null !== $value; } );
		$valid = rest_validate_value_from_schema( $args, self::operation_schema( $name ), 'arguments' );
		if ( is_wp_error( $valid ) ) { return $valid; }
		// WordPress schema validation permits numeric strings. This contract requires actual integers and booleans.
		foreach ( $args as $key => $value ) {
			$type = self::fields()[ $key ]['type'] ?? '';
			if ( ( 'integer' === $type && ! is_int( $value ) ) || ( 'boolean' === $type && ! is_bool( $value ) ) ) { return EMCP_Tools_Pods_Api::error( 'invalid_input', __( 'Use typed integer and boolean arguments.', 'emcp-lite' ) ); }
		}
		if ( 'read' === $mode ) { return EMCP_Tools_Pods_Api::read( $name, $args ); }
		if ( true !== $args['confirm'] ) { return EMCP_Tools_Pods_Api::error( 'confirm_required', __( 'Writes require confirm:true.', 'emcp-lite' ) ); }
		return EMCP_Tools_Pods_Writer::write( $name, $args );
	}
}
