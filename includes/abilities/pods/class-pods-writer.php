<?php
/** Native Pods writes, insert attribution and bounded field recovery. */
defined( 'ABSPATH' ) || exit;
final class EMCP_Tools_Pods_Writer {
	const HISTORY = 'pods-native';
	const LOCK = 'pods_native';
	public static function boot(): void {
		add_filter( 'emcp_tools_change_current_hash', array( __CLASS__, 'history_hash' ), 10, 2 );
		add_filter( 'emcp_tools_change_apply_rollback', array( __CLASS__, 'history_apply' ), 10, 3 );
	}
	public static function locked( callable $fn ) {
		$lease = new EMCP_Tools_Lease(); $owner = wp_generate_uuid4();
		if ( ! $lease->acquire( self::LOCK, $owner, 120 ) ) { return EMCP_Tools_Pods_Api::error( 'pods_busy', 'Another Pods change is running.' ); }
		try { return $fn(); } finally { $lease->release( self::LOCK, $owner ); }
	}
	private static function record( string $operation, string $name, int $id, ?array $before, string $schema, bool $irreversible ): string {
		$pod = EMCP_Tools_Pods_Api::pod( $name );
		$hash = is_wp_error( $pod ) ? 'unavailable' : ( $id ? EMCP_Tools_Pods_Api::record_hash( $pod, $id ) : EMCP_Tools_Pods_Api::schema_hash( $pod ) );
		$rollback = $irreversible ? array( 'type' => 'irreversible', 'reason' => 'Creation or uncertain native writes require native inspection. Automatic deletion could remove later content or schema references.' )
			: array( 'type' => self::HISTORY, 'pod' => $name, 'object_id' => $id, 'before' => $before, 'schema' => $schema, 'after_hash' => $hash );
		return EMCP_Tools_Change_Log::record( array( 'domain' => 'pods', 'action' => $operation, 'target' => $name . ':' . $id, 'summary' => 'Pods: ' . $operation . ' ' . $name, 'rollback' => $rollback ) );
	}
	private static function save_values( string $name, int $id, array $data ) {
		$conflict = pods_no_conflict_check( 'post' );
		try { return ( new Pods( $name, $id ) )->save( $data ); }
		finally { if ( ! $conflict && pods_no_conflict_check( 'post' ) ) { pods_no_conflict_off( 'post' ); } }
	}
	private static function meta( int $id, array $names ): array { clean_post_cache( $id ); $out = array(); foreach ( $names as $name ) { $out[ $name ] = get_post_meta( $id, $name, false ); } return $out; }
	public static function write( string $operation, array $args ) {
		return self::locked( static function () use ( $operation, $args ) {
			if ( ! EMCP_Tools_Pods_Api::allowed() ) { return EMCP_Tools_Pods_Api::error( 'forbidden', 'Pods administrator access required.' ); }
			$name = $args['pod']; $id = $args['id'] ?? 0; $before = null; $schema = ''; $data = array(); $pod = null;
			$create = in_array( $operation, array( 'create-pod', 'create-record' ), true );
			if ( 'create-pod' === $operation ) {
				if ( EMCP_Tools_Pods_Api::protected_name( $name ) || post_type_exists( $name ) || taxonomy_exists( $name ) || pods_api()->load_pod( $name ) ) { return EMCP_Tools_Pods_Api::error( 'protected_slug', 'Choose an unused, non-reserved schema slug.' ); }
				$names = array();
				foreach ( $args['fields'] as $field ) {
					if ( EMCP_Tools_Pods_Api::protected_name( $field['name'] ) || 0 === strpos( $field['name'], 'post_' ) || in_array( $field['name'], array_merge( $names, array( 'id', 'name', 'title', 'content', 'status', 'slug', 'author', 'date' ) ), true ) || sanitize_text_field( $field['label'] ) !== $field['label'] ) { return EMCP_Tools_Pods_Api::error( 'invalid_field', 'Use unique ordinary scalar field names and plain-text labels.' ); }
					$names[] = $field['name'];
				}
			} else {
				$pod = EMCP_Tools_Pods_Api::pod( $name ); if ( is_wp_error( $pod ) ) { return $pod; }
				$schema = EMCP_Tools_Pods_Api::schema_hash( $pod );
				if ( $id ) { $record = EMCP_Tools_Pods_Api::record( $pod, $id, true ); if ( is_wp_error( $record ) ) { return $record; } }
				if ( ! $create && ! hash_equals( $id ? EMCP_Tools_Pods_Api::record_hash( $pod, $id ) : $schema, $args['version'] ) ) { return EMCP_Tools_Pods_Api::error( 'conflict', 'The schema or record changed. Read it again.' ); }
				if ( 'update-pod-label' === $operation ) {
					if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) { return EMCP_Tools_Pods_Api::error( 'unsupported_transport', 'Pods treats AJAX schema saves as replacements. Use MCP REST or CLI.' ); }
					$before = array( 'label' => $pod['label'] );
				} else {
					$data = EMCP_Tools_Pods_Api::validate_values( $pod, $args['values'] ); if ( is_wp_error( $data ) ) { return $data; }
					if ( $id ) { $before = self::meta( $id, array_keys( $data ) ); foreach ( $before as $rows ) { if ( count( $rows ) > 1 ) { return EMCP_Tools_Pods_Api::error( 'unsupported_field', 'Multi-row field storage cannot be edited by this adapter.' ); } } }
					else { $auth = EMCP_Tools_Post_Authorization::authorize_create( $pod['object'] ?: $pod['name'], 'draft' ); if ( is_wp_error( $auth ) ) { return $auth; } }
				}
			}
			foreach ( array( 'label', 'title' ) as $key ) { if ( isset( $args[ $key ] ) && ( '' === trim( $args[ $key ] ) || sanitize_text_field( $args[ $key ] ) !== $args[ $key ] ) ) { return EMCP_Tools_Pods_Api::error( 'invalid_text', 'Use nonempty plain-text labels and titles.' ); } }
			if ( ! empty( $args['dry_run'] ) ) { return array( 'dry_run' => true, 'validated' => true ); }
			$capture = EMCP_Tools_Query_Capture::run( array( 'tables' => array( 'posts' ) ), static function () use ( $operation, $args, $name, $id, $pod, $data ) {
				switch ( $operation ) {
					case 'create-pod':
						$result = pods_api()->save_pod( array( 'name' => $name, 'label' => $args['label'], 'type' => 'post_type', 'storage' => 'meta', 'public' => 1, 'show_ui' => 1, 'rest_enable' => 1, 'capability_type' => 'post', 'fields' => $args['fields'] ) );
						if ( ! is_wp_error( $result ) ) { pods_meta()->cache_pods( true ); pods_init()->setup_content_types( true ); flush_rewrite_rules( false ); } return $result;
					case 'update-pod-label': return pods_api()->save_pod( array( 'id' => (int) $pod['id'], 'label' => $args['label'] ) );
					case 'create-record': return ( new Pods( $name ) )->add( array( 'post_title' => $args['title'], 'post_author' => get_current_user_id(), 'post_status' => 'draft' ) + $data );
					case 'update-record': return self::save_values( $name, $id, $data );
				}
			} );
			if ( $create ) {
				$inserted = $capture['inserted']['posts'] ?? array(); $native_id = is_numeric( $capture['result'] ) ? (int) $capture['result'] : 0;
				$owned = $native_id && in_array( $native_id, $inserted, true );
				if ( 'create-record' === $operation && $owned ) { $id = $native_id; }
				$verified = $owned && ! $capture['error'] && empty( $capture['uncertain'] );
				$saved_pod = EMCP_Tools_Pods_Api::pod( $name );
				$verified = $verified && ! is_wp_error( $saved_pod );
				if ( $verified && 'create-pod' === $operation ) {
					$verified = (int) $saved_pod['id'] === $native_id && $saved_pod['label'] === $args['label'];
					$fields = $saved_pod->get_fields( array( 'bypass_cache' => true ) );
					foreach ( $args['fields'] as $field ) {
						$stored = $fields[ $field['name'] ] ?? null;
						if ( ! $stored || $stored['type'] !== $field['type'] || $stored['label'] !== $field['label'] || ! EMCP_Tools_Pods_Api::field_supported( $stored ) ) { $verified = false; }
					}
				}
				if ( $verified && 'create-record' === $operation ) {
					clean_post_cache( $id ); $saved = get_post( $id );
					$verified = $saved && ( $saved_pod['object'] ?: $saved_pod['name'] ) === $saved->post_type && 'draft' === $saved->post_status && (int) $saved->post_author === get_current_user_id() && $args['title'] === $saved->post_title;
					$values = EMCP_Tools_Pods_Api::values( $saved_pod, $id );
					foreach ( $data as $key => $value ) { if ( ! self::same_value( $values[ $key ] ?? null, $value ) ) { $verified = false; } }
				}
				$change = self::record( $verified ? $operation : 'partial-' . $operation, $name, $id, null, '', true );
				if ( ! $verified || '' === $change ) { return new WP_Error( 'partial_write', 'Creation did not finish cleanly. Inspect the captured native IDs and History; do not replay automatically.', array( 'created_ids' => $inserted, 'change_id' => $change ) ); }
				return array( 'id' => $native_id, 'pod' => $name, 'change_id' => $change, 'irreversible' => true );
			}
			$current_pod = EMCP_Tools_Pods_Api::pod( $name ); $verified = ! is_wp_error( $current_pod ) && ! $capture['error'] && is_numeric( $capture['result'] );
			if ( $verified && ! $id ) { $verified = $current_pod['label'] === $args['label']; }
			if ( $verified && $id ) { $values = EMCP_Tools_Pods_Api::values( $current_pod, $id ); foreach ( $data as $key => $value ) { if ( ! self::same_value( $values[ $key ] ?? null, $value ) ) { $verified = false; } } }
			if ( ! $verified ) {
				$restored = self::restore( $name, $id, $before, $schema );
				$change = self::record( true === $restored ? 'recovered-' . $operation : 'partial-' . $operation, $name, $id, $before, $schema, true === $restored );
				return new WP_Error( true === $restored ? 'native_error_recovered' : 'partial_write', 'Native save failed. Check the recovery result in History before retrying.', array( 'change_id' => $change, 'recovered' => true === $restored ) );
			}
			$change = self::record( $operation, $name, $id, $before, $schema, false );
			if ( '' === $change ) { $restored = self::restore( $name, $id, $before, $schema ); return EMCP_Tools_Pods_Api::error( true === $restored ? 'history_error_recovered' : 'partial_write', 'History failed. Read the native object before retrying.' ); }
			return array( 'pod' => $name, 'id' => $id, 'change_id' => $change, 'version' => $id ? EMCP_Tools_Pods_Api::record_hash( $current_pod, $id ) : EMCP_Tools_Pods_Api::schema_hash( $current_pod ) );
		} );
	}
	private static function same_value( $actual, $expected ): bool { if ( is_bool( $expected ) ) { return (bool) $actual === $expected; } if ( is_int( $expected ) ) { return is_numeric( $actual ) && (float) $actual === (float) $expected; } return $actual === $expected; }
	private static function restore( string $name, int $id, array $before, string $schema ) {
		if ( ! $id && defined( 'DOING_AJAX' ) && DOING_AJAX ) { return EMCP_Tools_Pods_Api::error( 'unsupported_transport', 'Pods schema restoration requires REST or CLI.' ); }
		$pod = EMCP_Tools_Pods_Api::pod( $name ); if ( is_wp_error( $pod ) ) { return $pod; }
		if ( $id && ! hash_equals( $schema, EMCP_Tools_Pods_Api::schema_hash( $pod ) ) ) { return EMCP_Tools_Pods_Api::error( 'schema_changed', 'The field schema changed. Force cannot bypass field constraints.' ); }
		try {
			if ( ! $id ) { pods_api()->save_pod( array( 'id' => (int) $pod['id'], 'label' => $before['label'] ) ); return EMCP_Tools_Pods_Api::pod( $name )['label'] === $before['label']; }
			$record = EMCP_Tools_Pods_Api::record( $pod, $id, true ); if ( is_wp_error( $record ) ) { return $record; }
			$fields = $pod->get_fields( array( 'bypass_cache' => true ) ); $values = array(); $absent = array();
			foreach ( $before as $key => $rows ) {
				if ( ! isset( $fields[ $key ] ) || ! EMCP_Tools_Pods_Api::field_supported( $fields[ $key ] ) || ! pods_permission( $fields[ $key ] ) ) { return EMCP_Tools_Pods_Api::error( 'forbidden', 'A saved field is no longer writable.' ); }
				if ( ! $rows ) { $absent[ $key ] = null; }
				$value = $rows[0] ?? ''; $type = $fields[ $key ]['type'];
				$values[ $key ] = 'number' === $type ? (int) $value : ( 'boolean' === $type ? (bool) $value : $value );
			}
			$result = self::save_values( $name, $id, $values ); if ( is_wp_error( $result ) || (int) $result !== $id ) { return false; }
			if ( $absent ) { pods_api()->save_post_meta( $id, $absent, false, $fields ); }
			return self::meta( $id, array_keys( $before ) ) === $before;
		} catch ( Throwable $e ) { return false; }
	}
	public static function history_hash( $hash, array $rb ): string {
		if ( self::HISTORY !== ( $rb['type'] ?? '' ) ) { return (string) $hash; }
		if ( ! EMCP_Tools_Pods_Api::active() ) { return 'plugin_inactive'; }
		$pod = EMCP_Tools_Pods_Api::pod( $rb['pod'] ); if ( is_wp_error( $pod ) ) { return 'unavailable'; }
		return $rb['object_id'] ? EMCP_Tools_Pods_Api::record_hash( $pod, $rb['object_id'] ) : EMCP_Tools_Pods_Api::schema_hash( $pod );
	}
	public static function history_apply( $result, array $rb, bool $force ) {
		if ( self::HISTORY !== ( $rb['type'] ?? '' ) ) { return $result; }
		$ready = EMCP_Tools_Pods_Api::ready(); if ( is_wp_error( $ready ) ) { return $ready; }
		return self::locked( static function () use ( $rb, $force ) {
			if ( ! EMCP_Tools_Pods_Api::allowed() ) { return EMCP_Tools_Pods_Api::error( 'forbidden', 'Pods administrator access required.' ); }
			if ( ! $force && ! hash_equals( $rb['after_hash'], self::history_hash( '', $rb ) ) ) { return EMCP_Tools_Pods_Api::error( 'conflict', 'The native object changed before undo acquired its lease.' ); }
			$result = self::restore( $rb['pod'], $rb['object_id'], $rb['before'], $rb['schema'] );
			if ( true === $result || is_wp_error( $result ) ) { return $result; }
			$change = self::record( 'partial-undo', $rb['pod'], $rb['object_id'], null, '', true );
			return new WP_Error( 'partial_write', 'Native undo failed. Inspect Pods before retrying.', array( 'change_id' => $change ) );
		} );
	}
}
