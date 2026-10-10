<?php
/**
 * AI-safe transaction MCP abilities: the change ledger + rollback.
 *
 * @package EMCP_Tools
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers list-changes / get-change / rollback-change.
 *
 * @since 3.3.0
 */
class EMCP_Tools_Transaction_Abilities {

	/**
	 * Ability names.
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'emcp-tools/list-changes',
			'emcp-tools/get-change',
			'emcp-tools/rollback-change',
		);
	}

	/**
	 * Capability check (the ledger spans admin-grade fs/db targets).
	 *
	 * @return bool
	 */
	public function check_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register the three abilities.
	 */
	public function register(): void {
		emcp_tools_register_ability(
			'emcp-tools/list-changes',
			array(
				'label'               => __( 'List Changes', 'emcp-lite' ),
				'description'         => __( 'Lists recent changes recorded by EMCP, including content, block, media and settings edits. Each entry reports whether it can be undone and whether it has already been rolled back. Filter by domain, rolled_back or reversible. Use get-change for details and rollback-change to undo an eligible change. Read-only.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_list' ),
				'permission_callback' => array( $this, 'check_manage' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'domain'      => array( 'type' => 'string', 'description' => __( 'Filter by a recorded domain, such as elementor, content, media, settings, globals, acf, users, seo, redirect, filesystem, database or wpcli.', 'emcp-lite' ) ),
						'rolled_back' => array( 'type' => 'boolean', 'description' => __( 'Only entries with this rolled-back state.', 'emcp-lite' ) ),
						'reversible'  => array( 'type' => 'boolean', 'description' => __( 'Only entries that are (or are not) reversible.', 'emcp-lite' ) ),
						'limit'       => array( 'type' => 'integer', 'description' => __( 'Max entries (default 50).', 'emcp-lite' ) ),
					),
				),
			)
		);

		emcp_tools_register_ability(
			'emcp-tools/get-change',
			array(
				'label'               => __( 'Get Change', 'emcp-lite' ),
				'description'         => __( 'Returns the full detail of one change-ledger entry by id, including its rollback reference (the before-image / backup pointer). Read-only.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_get' ),
				'permission_callback' => array( $this, 'check_manage' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array( 'type' => 'string', 'description' => __( 'Change id.', 'emcp-lite' ) ),
					),
					'required'   => array( 'id' ),
				),
			)
		);

		emcp_tools_register_ability(
			'emcp-tools/rollback-change',
			array(
				'label'               => __( 'Roll Back Change', 'emcp-lite' ),
				'description'         => __( 'Undoes an eligible recorded change by id using its saved prior state. Refuses if the target has changed since the recording unless force is explicitly requested. Marks the entry rolled back and records a compensating entry. Only reverts changes EMCP itself recorded; availability depends on the change type and saved snapshot.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_rollback' ),
				'permission_callback' => array( $this, 'check_manage' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array( 'type' => 'string', 'description' => __( 'Change id to roll back.', 'emcp-lite' ) ),
						'force' => array( 'type' => 'boolean', 'description' => __( 'Roll back even if the target changed since (overwrites the newer state). Default: false.', 'emcp-lite' ) ),
					),
					'required'   => array( 'id' ),
				),
			)
		);
	}

	/**
	 * list-changes.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_list( $input ) {
		$domain = isset( $input['domain'] ) ? (string) $input['domain'] : '';
		$limit  = isset( $input['limit'] ) ? max( 1, (int) $input['limit'] ) : 50;
		$args   = array( 'limit' => 100 );
		if ( '' !== $domain ) {
			$args['domain'] = $domain;
		}
		if ( isset( $input['rolled_back'] ) ) {
			$args['rolled_back'] = (bool) $input['rolled_back'];
		}

		$out = array();
		// Newest first, page by page: the reversible filter can skip any number of rows.
		do {
			$page = EMCP_Tools_Change_Log::query( $args );
			foreach ( $page['items'] as $e ) {
				$row = $this->list_row( $e, $input );
				if ( null !== $row ) {
					$out[] = $row;
				}
				if ( count( $out ) >= $limit ) {
					break 2;
				}
			}
			$args['cursor'] = $page['next_cursor'];
		} while ( null !== $page['next_cursor'] );

		return array(
			'changes' => $out,
			'total'   => EMCP_Tools_Change_Log::count(),
		);
	}

	/**
	 * One list-changes row, or null when the filters drop it.
	 *
	 * @param array $e     Ledger entry.
	 * @param array $input Input.
	 */
	private function list_row( array $e, $input ): ?array {
		$blocker    = EMCP_Tools_Change_Log::rollback_blocker( $e );
		$reversible = ! is_wp_error( $blocker );
		if ( isset( $input['rolled_back'] ) && (bool) $input['rolled_back'] !== ! empty( $e['rolled_back'] ) ) {
			return null;
		}
		if ( isset( $input['reversible'] ) && (bool) $input['reversible'] !== $reversible ) {
			return null;
		}
		return array(
			'id'          => $e['id'] ?? '',
			'ts'          => $e['ts'] ?? 0,
			'user_login'  => $e['user_login'] ?? '',
			'domain'      => $e['domain'] ?? '',
			'action'      => $e['action'] ?? '',
			'target'      => $e['target'] ?? '',
			'summary'     => $e['summary'] ?? '',
			'rolled_back' => ! empty( $e['rolled_back'] ),
			'reversible'  => $reversible,
			'rollback_unavailable_reason' => is_wp_error( $blocker ) ? $blocker->get_error_message() : '',
			'rollback'    => self::light_rollback( $e['rollback'] ?? null ),
		);
	}

	/**
	 * get-change.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function execute_get( $input ) {
		$id    = isset( $input['id'] ) ? (string) $input['id'] : '';
		$entry = EMCP_Tools_Change_Log::get( $id );
		if ( null === $entry ) {
			return new WP_Error( 'not_found', __( 'Change not found.', 'emcp-lite' ) );
		}
		return $entry;
	}

	/**
	 * rollback-change.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public function execute_rollback( $input ) {
		$id    = isset( $input['id'] ) ? (string) $input['id'] : '';
		$force = ! empty( $input['force'] );
		return EMCP_Tools_Change_Log::rollback( $id, $force );
	}

	/**
	 * Strip heavy payloads (before_rows/before) from a rollback ref for list output.
	 *
	 * @param array|null $rollback Rollback ref.
	 * @return array|null
	 */
	private static function light_rollback( $rollback ) {
		if ( ! is_array( $rollback ) ) {
			return null;
		}
		unset( $rollback['before_rows'], $rollback['before'], $rollback['inserted_key'], $rollback['snapshot'], $rollback['values'] );
		return $rollback;
	}
}
