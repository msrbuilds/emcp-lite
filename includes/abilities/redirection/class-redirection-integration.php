<?php
/** Free Redirection tools, shared with the separately packaged Lite edition. */
defined( 'ABSPATH' ) || exit;

final class EMCP_Tools_Redirection_Integration {

	public static function operations(): array {
		return array(
			'status' => array( 'read', 'redirect_manage', array(), array() ),
			'list-redirects' => array( 'read', 'redirect_manage', array( 'page', 'per_page', 'search', 'group_id' ), array() ),
			'get-redirect' => array( 'read', 'redirect_manage', array( 'id' ), array( 'id' ) ),
			'list-groups' => array( 'read', 'group_manage', array( 'page', 'per_page', 'search' ), array() ),
			'get-group' => array( 'read', 'group_manage', array( 'id' ), array( 'id' ) ),
			'list-logs' => array( 'read', 'log_manage', array( 'page', 'per_page', 'search' ), array() ),
			'list-404s' => array( 'read', '404_manage', array( 'page', 'per_page', 'search' ), array() ),
			'create-redirect' => array( 'write', 'redirect_add', array( 'source', 'target', 'group_id', 'title', 'code' ), array( 'source', 'target', 'group_id' ) ),
			'update-redirect' => array( 'write', 'redirect_add', array( 'id', 'version', 'source', 'target', 'title', 'code' ), array( 'id', 'version' ) ),
			'enable-redirect' => array( 'write', 'redirect_add', array( 'id', 'version' ), array( 'id', 'version' ) ),
			'disable-redirect' => array( 'write', 'redirect_add', array( 'id', 'version' ), array( 'id', 'version' ) ),
			'delete-redirect' => array( 'write', 'redirect_delete', array( 'id', 'version' ), array( 'id', 'version' ) ),
			'create-group' => array( 'write', 'group_add', array( 'name' ), array( 'name' ) ),
			'rename-group' => array( 'write', 'group_add', array( 'id', 'version', 'name' ), array( 'id', 'version', 'name' ) ),
		);
	}

	public static function fields(): array {
		return array(
			'id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'group_id' => array( 'type' => 'integer', 'minimum' => 1 ),
			'page' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 10000 ),
			'per_page' => array( 'type' => 'integer', 'minimum' => 5, 'maximum' => 100 ),
			'search' => array( 'type' => 'string', 'maxLength' => 200 ),
			'version' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
			'source' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ),
			'target' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ),
			'title' => array( 'type' => 'string', 'maxLength' => 500 ),
			'code' => array( 'type' => 'integer', 'enum' => array( 301, 302, 303, 307, 308 ) ),
			'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 50 ),
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
		return array( 'emcp-tools/redirection-read', 'emcp-tools/redirection-write' );
	}

	public function register(): void {
		foreach ( array( 'read', 'write' ) as $mode ) {
			emcp_tools_register_ability( 'emcp-tools/redirection-' . $mode, array(
				'label' => 'Redirection ' . ucfirst( $mode ),
				'description' => 'read' === $mode
					? __( 'Inspect the Redirection plugin, redirects, groups and redacted logs. Call without an operation for operation schemas. Separate from EMCP Redirect Manager.', 'emcp-lite' )
					: __( 'Manage exact URL redirects and WordPress-module groups in Redirection. Requires confirm:true; existing records require their current version. Writes default off. Redirect deletion and group creation are irreversible. Call without an operation for schemas.', 'emcp-lite' ),
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

	public function permission(): bool { return current_user_can( 'manage_options' ); }
	public function run_read( $input ) { return $this->run( 'read', $input ); }
	public function run_write( $input ) { return $this->run( 'write', $input ); }

	private function run( string $mode, $input ) {
		if ( ! $this->permission() ) { return EMCP_Tools_Redirection_Api::error( 'forbidden', __( 'Administrator access is required.', 'emcp-lite' ) ); }
		if ( in_array( 'emcp-tools/redirection-' . $mode, (array) get_option( 'emcp_tools_disabled_tools', array() ), true ) ) {
			return EMCP_Tools_Redirection_Api::error( 'tool_disabled', __( 'Enable this tool in EMCP Tools first.', 'emcp-lite' ) );
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'operation', 'arguments' ) ) || ( isset( $input['operation'] ) && ! is_string( $input['operation'] ) ) || ( isset( $input['arguments'] ) && ! is_array( $input['arguments'] ) ) ) {
			return EMCP_Tools_Redirection_Api::error( 'invalid_input', __( 'Expected an operation and an arguments object.', 'emcp-lite' ) );
		}
		$ready = EMCP_Tools_Redirection_Api::ready();
		if ( is_wp_error( $ready ) ) { return $ready; }
		$name = $input['operation'] ?? '';
		$ops = self::operations();
		if ( '' === $name ) {
			$catalog = array();
			foreach ( $ops as $key => $op ) {
				if ( $mode === $op[0] && EMCP_Tools_Redirection_Api::allowed( $op[1] ) ) { $catalog[ $key ] = self::operation_schema( $key ); }
			}
			return array( 'operations' => $catalog );
		}
		if ( ! isset( $ops[ $name ] ) || $mode !== $ops[ $name ][0] ) { return EMCP_Tools_Redirection_Api::error( 'unknown_operation', __( 'Call without an operation to discover supported operations.', 'emcp-lite' ) ); }
		if ( ! EMCP_Tools_Redirection_Api::allowed( $ops[ $name ][1] ) ) { return EMCP_Tools_Redirection_Api::error( 'forbidden', __( 'Redirection permission denied.', 'emcp-lite' ) ); }
		$args = $input['arguments'] ?? array();
		// Strict transports add null placeholders for optional union fields. Empty strings remain intentional values.
		$args = array_filter( $args, static function ( $value ) { return null !== $value; } );
		$valid = rest_validate_value_from_schema( $args, self::operation_schema( $name ), 'arguments' );
		if ( is_wp_error( $valid ) ) { return $valid; }
		// WordPress schema validation permits numeric strings. This contract requires actual integers and booleans.
		foreach ( $args as $key => $value ) {
			$type = self::fields()[ $key ]['type'] ?? '';
			if ( ( 'integer' === $type && ! is_int( $value ) ) || ( 'boolean' === $type && ! is_bool( $value ) ) ) { return EMCP_Tools_Redirection_Api::error( 'invalid_input', __( 'Use typed integer and boolean arguments.', 'emcp-lite' ) ); }
		}
		if ( 'read' === $mode ) { return EMCP_Tools_Redirection_Api::read( $name, $args ); }
		if ( true !== $args['confirm'] ) { return EMCP_Tools_Redirection_Api::error( 'confirm_required', __( 'Writes require confirm:true.', 'emcp-lite' ) ); }
		return EMCP_Tools_Redirection_Writer::write( $name, $args );
	}
}
