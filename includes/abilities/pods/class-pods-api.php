<?php
/** Bounded native Pods access for ordinary post/meta storage. */
defined( 'ABSPATH' ) || exit;
final class EMCP_Tools_Pods_Api {
	const TYPES = array( 'text', 'paragraph', 'number', 'boolean' );
	public static function active(): bool { return defined( 'PODS_VERSION' ) && class_exists( 'Pods' ) && function_exists( 'pods_api' ); }
	public static function allowed(): bool { return current_user_can( 'manage_options' ) && function_exists( 'pods_is_admin' ) && pods_is_admin(); }
	public static function error( string $code, string $message ): WP_Error { return new WP_Error( $code, $message ); }
	public static function ready() { return ! self::active() ? self::error( 'plugin_inactive', 'Activate Pods first.' ) : ( '3.3.9.2' === PODS_VERSION ? true : self::error( 'unsupported_version', 'This adapter is qualified against Pods 3.3.9.2.' ) ); }
	public static function protected_name( string $name ): bool { return (bool) preg_match( '/^(?:_|wp_|emcp_|elementor|acf_)/', $name ) || in_array( $name, array( 'attachment', 'revision', 'nav_menu_item', 'user', 'users', 'comment', 'comments', 'settings' ), true ); }
	public static function pod( string $name ) {
		$pod = pods_api()->load_pod( array( 'name' => $name, 'bypass_cache' => true ) );
		if ( ! $pod ) { return self::error( 'not_found', 'Pod not found.' ); }
		$type = get_post_type_object( $pod['object'] ?: $pod['name'] );
		if ( 'post_type' !== $pod['type'] || 'meta' !== $pod['storage'] || self::protected_name( $pod['name'] ) || ! $type || 'post' !== $type->capability_type || ! $type->public ) {
			return self::error( 'unsupported_pod', 'Only public post-type Pods with meta storage and ordinary post capabilities are supported.' );
		}
		if ( ! pods_permission( $pod ) ) { return self::error( 'forbidden', 'Native Pods access denied.' ); }
		return $pod;
	}
	public static function field_supported( $field ): bool {
		return in_array( $field['type'], self::TYPES, true ) && ! $field->is_repeatable() && ! self::protected_name( $field['name'] )
			&& ( 'number' !== $field['type'] || 0 === (int) ( $field['number_decimals'] ?? 0 ) );
	}
	public static function schema_hash( $pod ): string {
		$state = array(); $ids = array( (int) $pod['id'] );
		foreach ( $pod->get_fields( array( 'bypass_cache' => true ) ) as $field ) { $ids[] = (int) $field['id']; if ( $field->get_group_id() ) { $ids[] = $field->get_group_id(); } }
		$ids = array_unique( $ids ); sort( $ids );
		foreach ( $ids as $id ) { clean_post_cache( $id ); $post = get_post( $id ); $state[] = array( $post ? (array) $post : null, get_post_meta( $id ) ); }
		return hash( 'sha256', wp_json_encode( $state ) );
	}
	public static function schema( $pod ): array {
		$fields = array();
		foreach ( $pod->get_fields( array( 'bypass_cache' => true ) ) as $field ) { if ( pods_permission( $field ) ) { $fields[] = array( 'name' => $field['name'], 'label' => $field['label'], 'type' => $field['type'], 'writable' => self::field_supported( $field ) ); } }
		return array( 'id' => (int) $pod['id'], 'name' => $pod['name'], 'label' => $pod['label'], 'storage' => 'meta', 'type' => 'post_type', 'fields' => $fields, 'version' => self::schema_hash( $pod ) );
	}
	public static function record( $pod, int $id, bool $write = false ) {
		clean_post_cache( $id ); $post = get_post( $id ); $type = $pod['object'] ?: $pod['name'];
		if ( ! $post || $type !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) { return self::error( 'not_found', 'An available record in this Pod is required.' ); }
		if ( ! current_user_can( $write ? 'edit_post' : 'read_post', $id ) ) { return self::error( 'forbidden', 'WordPress record access denied.' ); }
		return $post;
	}
	public static function record_hash( $pod, int $id ): string {
		clean_post_cache( $id ); $post = get_post( $id );
		return $post ? hash( 'sha256', wp_json_encode( array( self::schema_hash( $pod ), (array) $post, get_post_meta( $id ) ) ) ) : 'absent';
	}
	public static function values( $pod, int $id ): array {
		$instance = new Pods( $pod['name'], $id ); $values = array();
		foreach ( $pod->get_fields( array( 'bypass_cache' => true ) ) as $field ) {
			if ( self::field_supported( $field ) && pods_permission( $field ) ) { $values[ $field['name'] ] = $instance->field( $field['name'], true, true ); }
		}
		return $values;
	}
	public static function validate_values( $pod, array $values ) {
		$fields = $pod->get_fields( array( 'bypass_cache' => true ) ); $data = array();
		foreach ( $values as $value ) {
			$name = $value['name']; $field = $fields[ $name ] ?? null;
			if ( ! $field || ! self::field_supported( $field ) || ! pods_permission( $field ) || array_key_exists( $name, $data ) ) { return self::error( 'unsupported_field', 'Use each supported, permitted scalar field once.' ); }
			$v = $value['value']; $type = $field['type'];
			if ( ( 'number' === $type && ( ! is_int( $v ) || abs( $v ) > 2147483647 ) ) || ( 'boolean' === $type && ! is_bool( $v ) ) || ( in_array( $type, array( 'text', 'paragraph' ), true ) && ( ! is_string( $v ) || strlen( $v ) > 20000 || wp_strip_all_tags( $v ) !== $v ) ) ) { return self::error( 'invalid_value', 'Use plain text, an integer or a boolean matching the native field.' ); }
			$data[ $name ] = $v;
		}
		return $data;
	}
	public static function read( string $operation, array $args ) {
		if ( 'status' === $operation ) { return array( 'version' => PODS_VERSION, 'storage' => 'post/meta', 'field_types' => self::TYPES ); }
		if ( 'list-pods' === $operation ) {
			$rows = array();
			foreach ( pods_api()->load_pods( array( 'type' => 'post_type', 'storage' => 'meta', 'refresh' => true ) ) as $candidate ) { $pod = self::pod( $candidate['name'] ); if ( ! is_wp_error( $pod ) ) { $row = self::schema( $pod ); unset( $row['fields'] ); $rows[] = $row; } }
			return self::page( $rows, $args );
		}
		$pod = self::pod( $args['pod'] ); if ( is_wp_error( $pod ) ) { return $pod; }
		if ( 'get-pod' === $operation ) { return self::schema( $pod ); }
		if ( 'list-fields' === $operation ) { return self::page( self::schema( $pod )['fields'], $args ); }
		if ( 'get-record' === $operation ) {
			$post = self::record( $pod, $args['id'] ); if ( is_wp_error( $post ) ) { return $post; }
			return array( 'id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status, 'values' => self::values( $pod, $post->ID ), 'version' => self::record_hash( $pod, $post->ID ) );
		}
		// Restrict the final paginated query to individually authorized IDs, so custom meta-cap filters cannot leak rows or inflate totals.
		$type = $pod['object'] ?: $pod['name'];
		$scan = new WP_Query( array( 'post_type' => $type, 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ), 'fields' => 'ids', 'posts_per_page' => 5001, 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'ASC' ) );
		if ( count( $scan->posts ) > 5000 ) { return self::error( 'query_too_large', 'This initial adapter supports listing up to 5000 records per Pod. Use get-record for larger Pods.' ); }
		$ids = array_values( array_filter( $scan->posts, static function ( $id ) { return current_user_can( 'read_post', $id ); } ) );
		if ( ! $ids ) { return array( 'items' => array(), 'total' => 0 ); }
		$query = new WP_Query( array( 'post_type' => $type, 'post__in' => $ids, 'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future' ), 'posts_per_page' => $args['per_page'] ?? 20, 'paged' => ( $args['page'] ?? 0 ) + 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
		$rows = array(); foreach ( $query->posts as $post ) { $rows[] = array( 'id' => $post->ID, 'title' => $post->post_title, 'status' => $post->post_status ); }
		return array( 'items' => $rows, 'total' => (int) $query->found_posts );
	}
	private static function page( array $rows, array $args ): array { return array( 'items' => array_slice( $rows, ( $args['page'] ?? 0 ) * ( $args['per_page'] ?? 20 ), $args['per_page'] ?? 20 ), 'total' => count( $rows ) ); }
}
