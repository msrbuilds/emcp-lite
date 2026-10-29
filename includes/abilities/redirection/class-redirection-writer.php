<?php
/** Leased native writes and dedicated History restoration for Redirection. */
defined( 'ABSPATH' ) || exit;

final class EMCP_Tools_Redirection_Writer {
	const HISTORY = 'redirection-native';
	const LOCK = 'redirection_native';

	public static function boot(): void {
		add_filter( 'emcp_tools_change_current_hash', array( __CLASS__, 'history_hash' ), 10, 2 );
		add_filter( 'emcp_tools_change_apply_rollback', array( __CLASS__, 'history_apply' ), 10, 3 );
	}

	public static function locked( callable $callback ) {
		$lease = new EMCP_Tools_Lease();
		$owner = wp_generate_uuid4();
		if ( ! $lease->acquire( self::LOCK, $owner, 120 ) ) { return EMCP_Tools_Redirection_Api::error( 'redirection_busy', __( 'Another Redirection change is running. Retry after it finishes.', 'emcp-lite' ) ); }
		try { return $callback(); } finally { $lease->release( self::LOCK, $owner ); }
	}

	private static function record( string $operation, string $kind, int $id, ?array $before, string $after, bool $irreversible = false ): string {
		$rollback = $irreversible ? array( 'type' => 'irreversible', 'reason' => __( 'This operation cannot safely restore the original Redirection identity. Inspect the native plugin before further changes.', 'emcp-lite' ) )
			: array( 'type' => self::HISTORY, 'kind' => $kind, 'object_id' => $id, 'operation' => $operation, 'before' => $before, 'after_hash' => $after );
		return EMCP_Tools_Change_Log::record( array( 'domain' => 'redirection', 'action' => $operation, 'target' => $kind . ':' . $id, 'summary' => 'Redirection: ' . $operation . ' #' . $id, 'rollback' => $rollback ) );
	}

	public static function write( string $operation, array $args ) {
		return self::locked( static function () use ( $operation, $args ) {
			$permission = EMCP_Tools_Redirection_Integration::operations()[ $operation ][1];
			if ( ! EMCP_Tools_Redirection_Api::allowed( $permission ) ) { return EMCP_Tools_Redirection_Api::error( 'forbidden', __( 'Redirection permission denied.', 'emcp-lite' ) ); }
			$kind = false !== strpos( $operation, 'group' ) ? 'group' : 'redirect';
			$id = $args['id'] ?? 0;
			$before = $id ? ( 'group' === $kind ? EMCP_Tools_Redirection_Api::group( $id ) : EMCP_Tools_Redirection_Api::item( $id ) ) : null;
			if ( $id && ! $before ) { return EMCP_Tools_Redirection_Api::error( 'not_found', __( 'Redirection object not found.', 'emcp-lite' ) ); }
			if ( $id && ! hash_equals( EMCP_Tools_Redirection_Api::hash( $kind, $id ), $args['version'] ) ) { return EMCP_Tools_Redirection_Api::error( 'conflict', __( 'The object or its group changed. Read it again before writing.', 'emcp-lite' ) ); }
			if ( 'group' === $kind ) {
				if ( '' === trim( $args['name'] ) || sanitize_text_field( $args['name'] ) !== $args['name'] ) { return EMCP_Tools_Redirection_Api::error( 'invalid_name', __( 'Use a nonempty plain-text group name.', 'emcp-lite' ) ); }
				if ( $id && 1 !== (int) $before['module_id'] ) { return EMCP_Tools_Redirection_Api::error( 'unsupported_module', __( 'Only WordPress-module groups can be changed.', 'emcp-lite' ) ); }
				$desired = array( 'name' => $args['name'], 'moduleId' => 1 );
			} else {
				if ( $before && ! EMCP_Tools_Redirection_Api::supported( $before ) ) { return EMCP_Tools_Redirection_Api::error( 'unsupported_redirect', __( 'Only exact URL redirects can be changed by this integration.', 'emcp-lite' ) ); }
				$desired = $before ? EMCP_Tools_Redirection_Api::config( $before ) : array( 'group_id' => $args['group_id'], 'match_type' => 'url', 'action_type' => 'url', 'regex' => false, 'action_code' => 302, 'title' => '', 'action_data' => array() );
				foreach ( array( 'source' => 'url', 'title' => 'title', 'code' => 'action_code' ) as $key => $native ) { if ( array_key_exists( $key, $args ) ) { $desired[ $native ] = $args[ $key ]; } }
				if ( isset( $args['target'] ) ) { $desired['action_data']['url'] = $args['target']; }
				$group_ok = EMCP_Tools_Redirection_Api::wp_group( (int) $desired['group_id'] );
				if ( is_wp_error( $group_ok ) ) { return $group_ok; }
				if ( ! EMCP_Tools_Redirection_Api::url( $desired['url'], true ) || ! EMCP_Tools_Redirection_Api::url( $desired['action_data']['url'] ) ) { return EMCP_Tools_Redirection_Api::error( 'invalid_url', __( 'Use a local source path and a local path or HTTP(S) target without credentials or control characters.', 'emcp-lite' ) ); }
				if ( in_array( $operation, array( 'create-redirect', 'update-redirect', 'enable-redirect' ), true ) && EMCP_Tools_Redirection_Api::cycle( $desired['url'], $desired['action_data']['url'], $id ) ) { return EMCP_Tools_Redirection_Api::error( 'redirect_cycle', __( 'This redirect would create a known local cycle.', 'emcp-lite' ) ); }
			}
			if ( ! empty( $args['dry_run'] ) ) { return array( 'dry_run' => true, 'operation' => $operation, 'id' => $id, 'validated' => true ); }
			$capture = EMCP_Tools_Query_Capture::run( array( 'tables' => array( 'redirection_items', 'redirection_groups' ) ), static function () use ( $operation, $id, $desired ) {
				switch ( $operation ) {
					case 'create-group': return Red_Group::create( $desired['name'], 1 );
					case 'rename-group': return Red_Group::get( $id, true )->update( $desired );
					case 'create-redirect': return Red_Item::create( $desired );
					case 'update-redirect': return Red_Item::get_by_id( $id )->update( $desired );
					case 'enable-redirect': Red_Item::get_by_id( $id )->enable(); return true;
					case 'disable-redirect': Red_Item::get_by_id( $id )->disable(); return true;
					case 'delete-redirect': Red_Item::get_by_id( $id )->delete(); return true;
				}
			} );
			if ( ! $id ) {
				$table = 'group' === $kind ? 'redirection_groups' : 'redirection_items';
				$created = array_values( array_unique( $capture['inserted'][ $table ] ?? array() ) );
				if ( 1 === count( $created ) && empty( $capture['uncertain'] ) ) { $id = $created[0]; }
				if ( ! $id ) {
					if ( ! $created && empty( $capture['uncertain'] ) && $capture['error'] ) { return EMCP_Tools_Redirection_Api::error( 'native_error', __( 'Redirection refused the operation before creating a record.', 'emcp-lite' ) ); }
					$change = self::record( 'partial-' . $operation, $kind, 0, null, '', true );
					$error = EMCP_Tools_Redirection_Api::error( 'partial_write', __( 'Creation could not be attributed unambiguously. Inspect Redirection and History; do not replay the request.', 'emcp-lite' ) );
					$error->add_data( array( 'change_id' => $change, 'created_ids' => $created ) );
					return $error;
				}
			}
			$after = EMCP_Tools_Redirection_Api::hash( $kind, $id );
			$row = 'absent' === $after ? null : ( 'group' === $kind ? EMCP_Tools_Redirection_Api::group( $id ) : EMCP_Tools_Redirection_Api::item( $id ) );
			$verified = 'delete-redirect' === $operation ? null === $row : null !== $row;
			if ( $verified && $row ) {
				if ( 'group' === $kind ) { $verified = $row['name'] === trim( $desired['name'] ) && 1 === (int) $row['module_id']; }
				elseif ( 'enable-redirect' === $operation || 'disable-redirect' === $operation ) { $verified = $row['enabled'] === ( 'enable-redirect' === $operation ); }
				else { $verified = $row['url'] === $desired['url'] && ( $row['action_data']['url'] ?? null ) === $desired['action_data']['url'] && (int) $row['action_code'] === (int) $desired['action_code'] && (string) $row['title'] === (string) $desired['title']; }
			}
			if ( $capture['error'] || false === $capture['result'] || ! $verified ) {
				$recovered = false;
				if ( 'delete-redirect' !== $operation && 'create-group' !== $operation ) {
					$recovered = self::restore( $kind, $id, $before );
				}
				if ( true === $recovered ) {
					$change = self::record( 'recovered-' . $operation, $kind, $id, null, '', true );
					$error = EMCP_Tools_Redirection_Api::error( 'native_error_recovered', __( 'The native operation failed. Its previous state was restored; no automatic retry was performed.', 'emcp-lite' ) );
					$error->add_data( array( 'change_id' => $change, 'recovered' => true ) );
					return $error;
				}
				$after = EMCP_Tools_Redirection_Api::hash( $kind, $id );
				$change = self::record( 'partial-' . $operation, $kind, $id, $before, $after, 'delete-redirect' === $operation || 'create-group' === $operation );
				$error = EMCP_Tools_Redirection_Api::error( 'partial_write', __( 'The native operation did not complete cleanly. Inspect the object and its recovery History entry before retrying.', 'emcp-lite' ) );
				$error->add_data( array( 'id' => $id, 'change_id' => $change ) );
				return $error;
			}
			$change = self::record( $operation, $kind, $id, $before, $after, in_array( $operation, array( 'delete-redirect', 'create-group' ), true ) );
			if ( '' === $change ) {
				if ( ! in_array( $operation, array( 'delete-redirect', 'create-group' ), true ) && true === self::restore( $kind, $id, $before ) ) {
					return EMCP_Tools_Redirection_Api::error( 'history_error_recovered', __( 'History could not be saved, so the native change was restored.', 'emcp-lite' ) );
				}
				$error = EMCP_Tools_Redirection_Api::error( 'partial_write', __( 'The change was applied but History could not be saved or the change safely restored. Read the object before any retry.', 'emcp-lite' ) );
				$error->add_data( array( 'id' => $id, 'applied' => true ) );
				return $error;
			}
			return array( 'id' => $id, 'version' => $after, 'change_id' => $change, 'object' => $row );
		} );
	}

	/** Restore only native configuration; traffic counters remain untouched. */
	private static function restore( string $kind, int $id, ?array $before ) {
		try {
			if ( 'group' === $kind ) {
				$group = Red_Group::get( $id, true );
				if ( ! $group || ! $before || 1 !== $group->get_module_id() ) { return false; }
				$group->update( array( 'name' => $before['name'], 'moduleId' => 1 ) );
				return Red_Group::get( $id, true )->get_name() === $before['name'];
			}
			$item = Red_Item::get_by_id( $id );
			if ( ! $before ) { if ( $item ) { $item->delete(); } return ! Red_Item::get_by_id( $id ); }
			if ( ! $item || is_wp_error( EMCP_Tools_Redirection_Api::wp_group( (int) $before['group_id'] ) ) ) { return false; }
			$result = $item->update( EMCP_Tools_Redirection_Api::config( $before ) );
			if ( is_wp_error( $result ) ) { return false; }
			$item = Red_Item::get_by_id( $id );
			if ( $item->is_enabled() !== (bool) $before['enabled'] ) { $before['enabled'] ? $item->enable() : $item->disable(); }
			return EMCP_Tools_Redirection_Api::config( EMCP_Tools_Redirection_Api::item( $id ) ) === EMCP_Tools_Redirection_Api::config( $before );
		} catch ( Throwable $error ) {
			return false;
		}
	}

	public static function history_hash( $hash, array $rollback ): string {
		if ( self::HISTORY !== ( $rollback['type'] ?? '' ) ) { return (string) $hash; }
		if ( ! EMCP_Tools_Redirection_Api::active() ) { return 'plugin_inactive'; }
		return EMCP_Tools_Redirection_Api::hash( $rollback['kind'], (int) $rollback['object_id'] );
	}

	public static function history_apply( $result, array $rollback, bool $force ) {
		if ( self::HISTORY !== ( $rollback['type'] ?? '' ) ) { return $result; }
		$ready = EMCP_Tools_Redirection_Api::ready();
		if ( is_wp_error( $ready ) ) { return $ready; }
		return self::locked( static function () use ( $rollback, $force ) {
			$kind = $rollback['kind'];
			$id = (int) $rollback['object_id'];
			$cap = 'group' === $kind ? 'group_add' : ( empty( $rollback['before'] ) ? 'redirect_delete' : 'redirect_add' );
			if ( ! EMCP_Tools_Redirection_Api::allowed( $cap ) ) { return EMCP_Tools_Redirection_Api::error( 'forbidden', __( 'Redirection permission denied.', 'emcp-lite' ) ); }
			if ( ! $force && ! hash_equals( $rollback['after_hash'], EMCP_Tools_Redirection_Api::hash( $kind, $id ) ) ) { return EMCP_Tools_Redirection_Api::error( 'conflict', __( 'The Redirection object changed before undo acquired its lease.', 'emcp-lite' ) ); }
			$before = $rollback['before'];
			if ( 'redirect' === $kind && $before ) {
				$group_ok = EMCP_Tools_Redirection_Api::wp_group( (int) $before['group_id'] );
				if ( is_wp_error( $group_ok ) ) { return $group_ok; }
				if ( EMCP_Tools_Redirection_Api::cycle( $before['url'], $before['action_data']['url'], $id ) ) { return EMCP_Tools_Redirection_Api::error( 'redirect_cycle', __( 'Undo would introduce a redirect cycle.', 'emcp-lite' ) ); }
			}
			$current = 'group' === $kind ? EMCP_Tools_Redirection_Api::group( $id ) : EMCP_Tools_Redirection_Api::item( $id );
			if ( ! $current ) { return EMCP_Tools_Redirection_Api::error( 'linked_unavailable', __( 'The original Redirection object no longer exists.', 'emcp-lite' ) ); }
			if ( 'group' === $kind && 1 !== (int) $current['module_id'] ) { return EMCP_Tools_Redirection_Api::error( 'unsupported_module', __( 'The group is no longer a WordPress-module group.', 'emcp-lite' ) ); }
			if ( 'redirect' === $kind && ( ! EMCP_Tools_Redirection_Api::supported( $current ) || is_wp_error( EMCP_Tools_Redirection_Api::wp_group( (int) $current['group_id'] ) ) ) ) { return EMCP_Tools_Redirection_Api::error( 'unsupported_redirect', __( 'The current redirect is outside the supported restore scope.', 'emcp-lite' ) ); }
			if ( true === self::restore( $kind, $id, $before ) ) { return true; }
			$after = EMCP_Tools_Redirection_Api::hash( $kind, $id );
			$change = self::record( 'partial-undo', $kind, $id, $current, $after );
			$error = EMCP_Tools_Redirection_Api::error( 'partial_write', __( 'Native undo failed. Inspect Redirection before retrying.', 'emcp-lite' ) );
			$error->add_data( array( 'id' => $id, 'change_id' => $change ) );
			return $error;
		} );
	}
}
