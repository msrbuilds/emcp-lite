<?php
/** Free LiteSpeed Cache tools, shared with the separately packaged Lite edition. */
defined( 'ABSPATH' ) || exit;

final class EMCP_Tools_Litespeed_Integration {

	public static function operations(): array {
		return array(
			'status' => array( 'read', '', array(), array() ),
			'purge-path' => array( 'write', '', array( 'path' ), array( 'path' ) ),
			'purge-page-cache' => array( 'write', '', array(), array() ),
		);
	}
	public static function fields(): array {
		return array(
			'path' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2048 ),
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
		return array( 'emcp-tools/litespeed-read', 'emcp-tools/litespeed-write' );
	}

	public function register(): void {
		foreach ( array( 'read', 'write' ) as $mode ) {
			emcp_tools_register_ability( 'emcp-tools/litespeed-' . $mode, array(
				'label' => 'LiteSpeed Cache ' . ucfirst( $mode ),
				'description' => 'read' === $mode
					? __( 'Read effective cache flags, selected TTL values and actual server support. No credentials or raw options. Call without an operation for schemas.', 'emcp-lite' )
					: __( 'Request a path or page-cache purge with confirm:true. Requires HTTP on an enabled LiteSpeed server. Defaults off. Purges cannot be undone; verify subsequent cache headers.', 'emcp-lite' ),
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

	public function permission(): bool { return EMCP_Tools_Litespeed_Api::allowed(); }
	public function run_read( $input ) { return $this->run( 'read', $input ); }
	public function run_write( $input ) { return $this->run( 'write', $input ); }

	private function run( string $mode, $input ) {
		if ( ! $this->permission() ) { return EMCP_Tools_Litespeed_Api::error( 'forbidden', __( 'Administrator access is required.', 'emcp-lite' ) ); }
		if ( in_array( 'emcp-tools/litespeed-' . $mode, (array) get_option( 'emcp_tools_disabled_tools', array() ), true ) ) {
			return EMCP_Tools_Litespeed_Api::error( 'tool_disabled', __( 'Enable this tool in EMCP Tools first.', 'emcp-lite' ) );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'operation', 'arguments' ) ) || ( isset( $input['operation'] ) && ! is_string( $input['operation'] ) ) || ( isset( $input['arguments'] ) && ! is_array( $input['arguments'] ) ) ) {
			return EMCP_Tools_Litespeed_Api::error( 'invalid_input', __( 'Expected an operation and an arguments object.', 'emcp-lite' ) );
		}
		$ready = EMCP_Tools_Litespeed_Api::ready();
		if ( is_wp_error( $ready ) ) { return $ready; }
		$name = $input['operation'] ?? '';
		$ops = self::operations();
		if ( '' === $name ) {
			$catalog = array();
			foreach ( $ops as $key => $op ) {
				if ( $mode === $op[0] && EMCP_Tools_Litespeed_Api::allowed() ) { $catalog[ $key ] = self::operation_schema( $key ); }
			}
			return array( 'operations' => $catalog );
		}
		if ( ! isset( $ops[ $name ] ) || $mode !== $ops[ $name ][0] ) { return EMCP_Tools_Litespeed_Api::error( 'unknown_operation', __( 'Call without an operation to discover supported operations.', 'emcp-lite' ) ); }
		if ( ! EMCP_Tools_Litespeed_Api::allowed() ) { return EMCP_Tools_Litespeed_Api::error( 'forbidden', __( 'LiteSpeed Cache permission denied.', 'emcp-lite' ) ); }
		$args = $input['arguments'] ?? array();
		// Strict transports add null placeholders for optional union fields. Empty strings remain intentional values.
		$args = array_filter( $args, static function ( $value ) { return null !== $value; } );
		$valid = rest_validate_value_from_schema( $args, self::operation_schema( $name ), 'arguments' );
		if ( is_wp_error( $valid ) ) { return $valid; }
		// WordPress schema validation permits numeric strings. This contract requires actual integers and booleans.
		foreach ( $args as $key => $value ) {
			$type = self::fields()[ $key ]['type'] ?? '';
			if ( ( 'integer' === $type && ! is_int( $value ) ) || ( 'boolean' === $type && ! is_bool( $value ) ) ) { return EMCP_Tools_Litespeed_Api::error( 'invalid_input', __( 'Use typed integer and boolean arguments.', 'emcp-lite' ) ); }
		}
		if ( 'read' === $mode ) { return EMCP_Tools_Litespeed_Api::read( $name, $args ); }
		if ( true !== $args['confirm'] ) { return EMCP_Tools_Litespeed_Api::error( 'confirm_required', __( 'Writes require confirm:true.', 'emcp-lite' ) ); }
		return EMCP_Tools_Litespeed_Api::write( $name, $args );
	}
}
