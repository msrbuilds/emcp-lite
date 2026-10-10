<?php
/**
 * Database MCP tools: list-tables/describe-table/query (read; enabled) +
 * insert-row/update-rows/delete-rows (structured, parameterized writes;
 * disabled-by-default). Reads via validated read-only SQL; writes via
 * $wpdb->insert/update/delete with forced WHERE, protected tables, before-image
 * snapshots, confirm-on-delete, and an audit log.
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
		$this->register_query();



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

	private function register_query(): void {
		$this->ability( 'emcp-tools/query', __( 'Query (read-only)', 'emcp-lite' ), __( 'Run a read-only SQL query (SELECT/SHOW/DESCRIBE/EXPLAIN). Writes/DDL and file-access SQL are rejected. Results are capped.', 'emcp-lite' ), 'execute_query', array( 'sql' => array( 'type' => 'string' ), 'limit' => array( 'type' => 'integer' ) ), array( 'sql' ), true );
	}
	public function execute_query( $input ) {
		$sql = (string) ( $input['sql'] ?? '' );
		$ro  = EMCP_Tools_Database_Guard::is_read_only_sql( $sql );
		if ( is_wp_error( $ro ) ) {
			return $ro;
		}
		// Read-path secret guard: the user tables hold password hashes, session
		// tokens, and activation keys. Refuse raw reads that touch them and point
		// the agent at the dedicated, redacting user tools.
		// Cross-schema secrets. The protected-table list covers the WordPress
		// user tables only, so mysql.user (server account password hashes) and
		// the metadata schemas would otherwise be readable straight through.
		if ( EMCP_Tools_Database_Guard::references_system_schema( $sql ) ) {
			return new \WP_Error(
				'protected_read',
				__( 'Reading the server system schemas (mysql, information_schema, performance_schema, sys) is not allowed.', 'emcp-lite' )
			);
		}
		if ( EMCP_Tools_Database_Guard::query_touches_protected( $sql ) ) {
			return new \WP_Error(
				'protected_read',
				__( 'Reading the user tables (passwords, session tokens, activation keys) via raw SQL is not allowed. Use the list-users / get-user tools instead.', 'emcp-lite' )
			);
		}
		global $wpdb;
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : EMCP_Tools_Database_Guard::MAX_ROWS;
		$limit = min( EMCP_Tools_Database_Guard::MAX_ROWS, max( 1, $limit ) );

		// Bound the work in the DATABASE, not just the response. Previously the
		// full result set was fetched into PHP and sliced afterwards, so a wide
		// query could exhaust memory and run unbounded before the limit applied.
		$bounded = EMCP_Tools_Database_Guard::bound_sql( $sql, $limit + 1 ); // +1 detects truncation.
		if ( is_wp_error( $bounded ) ) {
			return $bounded;
		}

		// Server-side statement timeout, restored in the finally block below.
		$restore_timeout = EMCP_Tools_Database_Guard::apply_statement_timeout( $wpdb );
		try {
			$rows = $wpdb->get_results( $bounded, ARRAY_A ); // phpcs:ignore WordPress.DB -- validated read-only; admin-authored.
		} finally {
			if ( is_callable( $restore_timeout ) ) {
				$restore_timeout();
			}
		}
		if ( null === $rows ) {
			return new \WP_Error( 'query_failed', $wpdb->last_error ? $wpdb->last_error : __( 'Query failed.', 'emcp-lite' ) );
		}
		$truncated = count( $rows ) > $limit;
		if ( $truncated ) {
			$rows = array_slice( $rows, 0, $limit );
		}
		return array( 'rows' => $rows, 'row_count' => count( $rows ), 'truncated' => $truncated );
	}

	// ---- writes (disabled by default) ---------------------------------

	/**
	 * Record a DB write to the change ledger via the recorder (which offloads a
	 * large before-image to the blob store), falling back to the raw ledger.
	 *
	 * @param array $entry Ledger entry.
	 */
	private function record_change( array $entry ): void {
		if ( class_exists( 'EMCP_Tools_Change_Recorder' ) ) {
			EMCP_Tools_Change_Recorder::record_db( $entry );
		} elseif ( class_exists( 'EMCP_Tools_Change_Log' ) ) {
			EMCP_Tools_Change_Log::record( $entry );
		}
	}









}
