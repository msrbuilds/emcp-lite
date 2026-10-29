<?php
/** Free read-only Wordfence dispatcher. */
defined( 'ABSPATH' ) || exit;
final class EMCP_Tools_Wordfence_Integration {
	public static function operations(): array { return array( 'status' => array(), 'firewall' => array(), 'scan-status' => array(), 'list-findings' => array( 'status', 'limit', 'offset' ), 'get-finding' => array( 'id' ), 'list-blocks' => array( 'limit', 'offset' ) ); }
	public static function fields(): array { return array(
		'id' => array( 'type' => 'integer', 'minimum' => 1 ),
		'status' => array( 'type' => 'string', 'enum' => array( 'new', 'ignoreC', 'ignoreP' ) ),
		'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
		'offset' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000 ),
	); }
	public static function operation_schema( string $name ): array { return array( 'type' => 'object', 'properties' => array_intersect_key( self::fields(), array_flip( self::operations()[ $name ] ) ), 'required' => 'get-finding' === $name ? array( 'id' ) : array(), 'additionalProperties' => false ); }
	public function get_ability_names(): array { return array( 'emcp-tools/wordfence-read' ); }
	public function permission(): bool { return EMCP_Tools_Wordfence_Api::allowed(); }
	public function register(): void {
		emcp_tools_register_ability( 'emcp-tools/wordfence-read', array(
			'label' => 'Wordfence Read', 'description' => __( 'Read firewall state, stored scan health and bounded finding/block metadata. No scan initiation, settings, IPs, paths or raw finding payloads. Call without an operation for schemas.', 'emcp-lite' ),
			'category' => 'emcp-tools', 'permission_callback' => array( $this, 'permission' ), 'execute_callback' => array( $this, 'run_read' ),
			'input_schema' => array( 'type' => 'object', 'properties' => array( 'operation' => array( 'type' => 'string' ), 'arguments' => array( 'type' => 'object', 'properties' => self::fields(), 'additionalProperties' => false ) ), 'additionalProperties' => false ),
			'meta' => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
		) );
	}
	public function run_read( $input ) {
		if ( ! $this->permission() ) { return EMCP_Tools_Wordfence_Api::error( 'forbidden', 'Native Wordfence administrator access is required.' ); }
		if ( in_array( 'emcp-tools/wordfence-read', (array) get_option( 'emcp_tools_disabled_tools', array() ), true ) ) { return EMCP_Tools_Wordfence_Api::error( 'tool_disabled', 'Enable this tool in EMCP Tools first.' ); }
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'operation', 'arguments' ) ) || ( isset( $input['operation'] ) && ! is_string( $input['operation'] ) ) || ( isset( $input['arguments'] ) && ! is_array( $input['arguments'] ) ) ) { return EMCP_Tools_Wordfence_Api::error( 'invalid_input', 'Expected an operation and an arguments object.' ); }
		$ready = EMCP_Tools_Wordfence_Api::ready(); if ( is_wp_error( $ready ) ) { return $ready; }
		$name = $input['operation'] ?? '';
		if ( '' === $name ) { $catalog = array(); foreach ( self::operations() as $key => $fields ) { $catalog[ $key ] = self::operation_schema( $key ); } return array( 'operations' => $catalog ); }
		if ( ! array_key_exists( $name, self::operations() ) ) { return EMCP_Tools_Wordfence_Api::error( 'unknown_operation', 'Call without an operation to discover read-only operations.' ); }
		$args = array_filter( $input['arguments'] ?? array(), static function ( $value ) { return null !== $value; } );
		$valid = rest_validate_value_from_schema( $args, self::operation_schema( $name ), 'arguments' ); if ( is_wp_error( $valid ) ) { return $valid; }
		foreach ( $args as $key => $value ) { if ( 'integer' === self::fields()[ $key ]['type'] && ! is_int( $value ) ) { return EMCP_Tools_Wordfence_Api::error( 'invalid_input', 'Use typed integer arguments.' ); } }
		return EMCP_Tools_Wordfence_Api::read( $name, $args );
	}
}
