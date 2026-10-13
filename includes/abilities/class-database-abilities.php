<?php
/**
 * Database schema inspection: table names, sizes and column definitions.
 * Arbitrary SQL is not exposed because read-only queries can disclose secrets.
 *
 * @package EMCP_Tools
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.0.0
 */
class EMCP_Tools_Database_Abilities {

	/** @var string[] */
	private $ability_names = array();

	public function get_ability_names(): array {
		return $this->ability_names;
	}

	public function register(): void {
		$this->register_list_tables();
		$this->register_describe_table();




	}

	/** All database tools require manage_options. */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	private function ability( string $name, string $label, string $description, string $exec, array $props, array $required, bool $readonly ): void {
		$this->ability_names[] = $name;
		emcp_tools_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, $exec ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array( 'type' => 'object', 'properties' => $props, 'required' => $required ),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array( 'annotations' => array( 'readonly' => $readonly, 'destructive' => ! $readonly ), 'show_in_rest' => true ),
			)
		);
	}

	// ---- reads ---------------------------------------------------------

	private function register_list_tables(): void {
		$this->ability( 'emcp-tools/list-tables', __( 'List Tables', 'emcp-lite' ), __( 'List database tables with estimated row counts and sizes.', 'emcp-lite' ), 'execute_list_tables', array(), array(), true );
	}
	public function execute_list_tables( $input ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name AS n, table_rows AS r, (data_length + index_length) AS sz FROM information_schema.TABLES WHERE table_schema = %s ORDER BY n ASC',
				DB_NAME
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array( 'table' => (string) $row['n'], 'rows' => (int) $row['r'], 'size_bytes' => (int) $row['sz'] );
		}
		return array( 'tables' => $out );
	}

	private function register_describe_table(): void {
		$this->ability( 'emcp-tools/describe-table', __( 'Describe Table', 'emcp-lite' ), __( 'Return the columns, types, and keys of a table.', 'emcp-lite' ), 'execute_describe_table', array( 'table' => array( 'type' => 'string' ) ), array( 'table' ), true );
	}
	public function execute_describe_table( $input ) {
		$table = EMCP_Tools_Database_Guard::valid_table( (string) ( $input['table'] ?? '' ) );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		global $wpdb;
		$cols = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table ), ARRAY_A );
		return array( 'table' => $table, 'columns' => is_array( $cols ) ? $cols : array() );
	}

}
