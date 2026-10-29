<?php
/** Native CPT UI schema access. No arbitrary option or executable configuration surface. */
defined( 'ABSPATH' ) || exit;
final class EMCP_Tools_Cptui_Api {
	const SUPPORTS = array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author', 'comments', 'page-attributes' );
	public static function active(): bool { return defined( 'CPTUI_VERSION' ) && function_exists( 'cptui_update_post_type' ); }
	public static function allowed(): bool { return current_user_can( 'manage_options' ) && current_user_can( apply_filters( 'cptui_required_capabilities', 'manage_options' ) ); }
	public static function error( string $code, string $message ): WP_Error { return new WP_Error( $code, $message ); }
	public static function ready() {
		if ( ! self::active() ) { return self::error( 'plugin_inactive', 'Activate Custom Post Type UI first.' ); }
		return '1.19.3' === CPTUI_VERSION ? true : self::error( 'unsupported_version', 'This adapter is qualified against CPT UI 1.19.3.' );
	}
	public static function rows( string $kind ): array {
		// MCP stdio can outlive native admin requests. Never version a stale options cache.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'post-type' === $kind ? 'cptui_post_types' : 'cptui_taxonomies', 'options' );
		return 'post-type' === $kind ? cptui_get_post_type_data() : cptui_get_taxonomy_data();
	}
	public static function row( string $kind, string $slug ): ?array { return self::rows( $kind )[ $slug ] ?? null; }
	public static function hash(): string { return hash( 'sha256', wp_json_encode( array( self::rows( 'post-type' ), self::rows( 'taxonomy' ) ) ) ); }
	public static function safe( string $kind, array $row ): bool {
		// Native form saves rebuild rows and discard extension-owned keys. Refuse those rows.
		$known = array_keys( self::defaults( $kind, $row['name'] ?? '' ) );
		if ( array_diff( array_keys( $row ), array_merge( $known, array( 'label', 'singular_label' ) ) ) ) { return false; }
		foreach ( array( 'register_meta_box_cb', 'meta_box_cb', 'rest_controller_class', 'custom_supports', 'default_term' ) as $key ) { if ( ! empty( $row[ $key ] ) ) { return false; } }
		if ( 'post-type' === $kind && 'post' !== ( $row['capability_type'] ?? 'post' ) ) { return false; }
		return 'taxonomy' !== $kind || false === get_option( 'default_term_' . $row['name'], false );
	}
	public static function defaults( string $kind, string $slug ): array {
		$row = array_fill_keys( array( 'description', 'rewrite_slug', 'query_var_slug', 'rest_base', 'rest_controller_class', 'rest_namespace', 'menu_icon', 'register_meta_box_cb', 'has_archive_string', 'menu_position', 'show_in_menu_string', 'custom_supports', 'enter_title_here', 'default_term', 'meta_box_cb' ), '' );
		$row += array_fill_keys( array( 'public', 'publicly_queryable', 'show_ui', 'show_in_nav_menus', 'show_in_rest', 'rewrite', 'rewrite_withfront', 'query_var', 'show_in_menu', 'can_export' ), 'true' );
		$row += array_fill_keys( array( 'delete_with_user', 'has_archive', 'exclude_from_search', 'hierarchical', 'rewrite_hierarchical', 'show_admin_column', 'show_tagcloud', 'sort', 'show_in_quick_edit' ), 'false' );
		return $row + array( 'name' => $slug, 'capability_type' => 'post', 'supports' => array( 'title', 'editor' ), 'taxonomies' => array(), 'labels' => array(), 'object_types' => array() );
	}
	/** Native helpers inspect POST as well as their argument. Restore all transport state. */
	public static function save( string $kind, array $row, bool $new ) {
		$old = $_POST;
		// These native form fields are unslashed before storage. Other configuration fields are not.
		foreach ( array( 'label', 'singular_label', 'description', 'labels' ) as $key ) {
			if ( isset( $row[ $key ] ) ) { $row[ $key ] = wp_slash( $row[ $key ] ); }
		}
		// CPT UI persists an empty icon as null, but its form save expects an empty string.
		foreach ( array( 'menu_icon', 'register_meta_box_cb', 'meta_box_cb' ) as $key ) { $row[ $key ] = (string) ( $row[ $key ] ?? '' ); }
		if ( 'post-type' === $kind ) {
			$data = array( 'cpt_custom_post_type' => $row, 'cpt_type_status' => $new ? 'new' : 'edit', 'cpt_original' => $row['name'], 'cpt_supports' => $row['supports'] ?? array(), 'cpt_labels' => $row['labels'] ?? array(), 'cpt_addon_taxes' => $row['taxonomies'] ?? array() );
		} else {
			$data = array( 'cpt_custom_tax' => $row, 'cpt_tax_status' => $new ? 'new' : 'edit', 'tax_original' => $row['name'], 'cpt_tax_labels' => $row['labels'] ?? array(), 'cpt_post_types' => $row['object_types'] ?? array() );
		}
		$_POST = $data;
		try { return 'post-type' === $kind ? cptui_update_post_type( $data ) : cptui_update_taxonomy( $data ); }
		finally { $_POST = $old; }
	}
	public static function refresh( string $kind, string $slug ): void {
		$row = self::row( $kind, $slug );
		if ( 'post-type' === $kind ) {
			if ( post_type_exists( $slug ) ) { unregister_post_type( $slug ); }
			if ( $row ) { cptui_register_single_post_type( $row ); }
		} else {
			if ( taxonomy_exists( $slug ) ) { unregister_taxonomy( $slug ); }
			if ( $row ) { cptui_register_single_taxonomy( $row ); }
		}
		flush_rewrite_rules( false );
	}
	public static function links( string $kind, array $row ) {
		foreach ( ( 'post-type' === $kind ? ( $row['taxonomies'] ?? array() ) : ( $row['object_types'] ?? array() ) ) as $slug ) {
			$object = 'post-type' === $kind ? get_taxonomy( $slug ) : get_post_type_object( $slug );
			if ( ! $object || ! $object->public || ( 'taxonomy' === $kind && 'post' !== $object->capability_type ) ) { return self::error( 'linked_unavailable', 'A linked public content definition is unavailable or protected.' ); }
		}
		return true;
	}
	/** Query storage directly for all statuses, including orphaned records and trash. */
	public static function empty_definition( string $kind, string $slug ) {
		global $wpdb;
		if ( 'post-type' === $kind ) {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $slug ) );
			foreach ( self::rows( 'taxonomy' ) as $row ) { if ( in_array( $slug, $row['object_types'] ?? array(), true ) ) { return self::error( 'definition_in_use', 'A taxonomy still references this post type.' ); } }
			foreach ( get_taxonomies( array(), 'objects' ) as $tax ) { if ( in_array( $slug, $tax->object_type, true ) ) { return self::error( 'definition_in_use', 'A registered taxonomy still references this post type.' ); } }
		} else {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $slug ) );
			foreach ( self::rows( 'post-type' ) as $row ) { if ( in_array( $slug, $row['taxonomies'] ?? array(), true ) ) { return self::error( 'definition_in_use', 'A post type still references this taxonomy.' ); } }
		}
		return null !== $count && 0 === (int) $count ? true : self::error( 'definition_in_use', 'Content or terms exist, or their absence could not be verified. Undo is blocked even with force.' );
	}
	public static function read( string $operation, array $args ) {
		if ( 'status' === $operation ) { return array( 'version' => CPTUI_VERSION, 'qualified_version' => '1.19.3' ); }
		if ( 'list-registered-post-types' === $operation ) {
			$rows = array();
			foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) { $rows[] = array( 'name' => $type->name, 'label' => $type->label, 'cptui_owned' => isset( self::rows( 'post-type' )[ $type->name ] ) ); }
		} else {
			$kind = false !== strpos( $operation, 'taxonom' ) ? 'taxonomy' : 'post-type';
			if ( 0 === strpos( $operation, 'get-' ) ) { $row = self::row( $kind, $args['slug'] ); return $row ? array( 'definition' => $row, 'version' => self::hash() ) : self::error( 'not_found', 'No CPT UI-owned definition has that slug.' ); }
			$rows = array_values( self::rows( $kind ) );
		}
		return array( 'items' => array_slice( $rows, ( $args['page'] ?? 0 ) * ( $args['per_page'] ?? 20 ), $args['per_page'] ?? 20 ), 'total' => count( $rows ), 'version' => self::hash() );
	}
}
