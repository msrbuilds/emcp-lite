<?php
/** CPT UI native writes and conflict-aware, content-aware History. */
defined( 'ABSPATH' ) || exit;
final class EMCP_Tools_Cptui_Writer {
	const HISTORY = 'cptui-native';
	const LOCK = 'cptui_native';
	public static function boot(): void {
		add_filter( 'emcp_tools_change_current_hash', array( __CLASS__, 'history_hash' ), 10, 2 );
		add_filter( 'emcp_tools_change_apply_rollback', array( __CLASS__, 'history_apply' ), 10, 3 );
	}
	public static function locked( callable $fn ) {
		$lease = new EMCP_Tools_Lease(); $owner = wp_generate_uuid4();
		if ( ! $lease->acquire( self::LOCK, $owner, 120 ) ) { return EMCP_Tools_Cptui_Api::error( 'cptui_busy', 'Another CPT UI change is running.' ); }
		try { return $fn(); } finally { $lease->release( self::LOCK, $owner ); }
	}
	private static function record( string $action, string $kind, string $slug, ?array $before, bool $uncertain = false ): string {
		$rollback = $uncertain ? array( 'type' => 'irreversible', 'reason' => 'The native write could not be attributed. Inspect CPT UI before making further changes.' )
			: array( 'type' => self::HISTORY, 'kind' => $kind, 'slug' => $slug, 'before' => $before, 'after_hash' => EMCP_Tools_Cptui_Api::hash() );
		return EMCP_Tools_Change_Log::record( array( 'domain' => 'cptui', 'action' => $action, 'target' => $kind . ':' . $slug, 'summary' => 'CPT UI: ' . $action . ' ' . $slug, 'rollback' => $rollback ) );
	}
	public static function write( string $operation, array $args ) {
		return self::locked( static function () use ( $operation, $args ) {
			if ( ! EMCP_Tools_Cptui_Api::allowed() ) { return EMCP_Tools_Cptui_Api::error( 'forbidden', 'CPT UI permission denied.' ); }
			$kind = false !== strpos( $operation, 'taxonomy' ) ? 'taxonomy' : 'post-type';
			$new = 0 === strpos( $operation, 'create-' ); $slug = $args['slug'];
			$before = EMCP_Tools_Cptui_Api::row( $kind, $slug );
			$reserved = array_merge( cptui_reserved_post_types(), cptui_reserved_taxonomies() );
			if ( in_array( $slug, $reserved, true ) || preg_match( '/^(?:wp_|emcp_|elementor|acf_|_)/', $slug ) ) { return EMCP_Tools_Cptui_Api::error( 'protected_slug', 'Use an ordinary, non-reserved content slug.' ); }
			if ( $new && ( $before || post_type_exists( $slug ) || taxonomy_exists( $slug ) ) ) { return EMCP_Tools_Cptui_Api::error( 'already_exists', 'The slug is already registered or stored.' ); }
			if ( ! $new && ! $before ) { return EMCP_Tools_Cptui_Api::error( 'not_found', 'Only CPT UI-owned definitions can be updated.' ); }
			if ( ! $new && ! hash_equals( EMCP_Tools_Cptui_Api::hash(), $args['version'] ) ) { return EMCP_Tools_Cptui_Api::error( 'conflict', 'CPT UI schemas changed. Read the definition again.' ); }
			foreach ( array( 'post-type' => 'cptui_post_types', 'taxonomy' => 'cptui_taxonomies' ) as $type => $option ) {
				if ( EMCP_Tools_Cptui_Api::rows( $type ) !== get_option( $option, array() ) ) { return EMCP_Tools_Cptui_Api::error( 'filtered_storage', 'An external storage filter controls these definitions. Use its native editor.' ); }
			}
			$row = $before ?? EMCP_Tools_Cptui_Api::defaults( $kind, $slug );
			foreach ( array( 'label', 'singular_label', 'description', 'supports', 'object_types' ) as $key ) { if ( array_key_exists( $key, $args ) ) { $row[ $key ] = $args[ $key ]; } }
			foreach ( array( 'public', 'show_in_rest', 'hierarchical' ) as $key ) { if ( isset( $args[ $key ] ) ) { $row[ $key ] = $args[ $key ] ? 'true' : 'false'; } }
			if ( $new ) { $row['publicly_queryable'] = $row['public']; $row['show_in_nav_menus'] = $row['public']; $row['exclude_from_search'] = 'true' === $row['public'] ? 'false' : 'true'; }
			foreach ( array( 'label', 'singular_label', 'description' ) as $key ) {
				if ( isset( $args[ $key ] ) && ( sanitize_textarea_field( $args[ $key ] ) !== $args[ $key ] || ( 'description' !== $key && '' === trim( $args[ $key ] ) ) ) ) { return EMCP_Tools_Cptui_Api::error( 'invalid_text', 'Use plain text labels and descriptions.' ); }
			}
			if ( ! EMCP_Tools_Cptui_Api::safe( $kind, $row ) ) { return EMCP_Tools_Cptui_Api::error( 'unsupported_definition', 'Custom callbacks, capabilities and default terms are outside this adapter.' ); }
			$links = EMCP_Tools_Cptui_Api::links( $kind, $row ); if ( is_wp_error( $links ) ) { return $links; }
			if ( ! empty( $args['dry_run'] ) ) { return array( 'dry_run' => true, 'slug' => $slug, 'validated' => true ); }
			// WordPress emits these hooks only after a successful option statement. An absent receipt never authorizes deleting a possibly concurrent create.
			$option = 'post-type' === $kind ? 'cptui_post_types' : 'cptui_taxonomies'; $receipt = null;
			$updated = static function ( $old, $value ) use ( &$receipt, $slug ) { $receipt = $value[ $slug ] ?? null; };
			$added = static function ( $name, $value ) use ( &$receipt, $slug ) { $receipt = $value[ $slug ] ?? null; };
			add_action( 'update_option_' . $option, $updated, PHP_INT_MIN, 2 ); add_action( 'add_option_' . $option, $added, PHP_INT_MIN, 2 );
			$error = null; $result = null;
			try { $result = EMCP_Tools_Cptui_Api::save( $kind, $row, $new ); } catch ( Throwable $e ) { $error = $e; }
			finally { remove_action( 'update_option_' . $option, $updated, PHP_INT_MIN ); remove_action( 'add_option_' . $option, $added, PHP_INT_MIN ); }
			$current = EMCP_Tools_Cptui_Api::row( $kind, $slug );
			$verified = $current && in_array( $result, array( 'add_success', 'update_success' ), true );
			foreach ( array( 'label', 'singular_label', 'description', 'supports', 'object_types', 'public', 'show_in_rest', 'hierarchical' ) as $key ) {
				$expected = $row[ $key ] ?? null;
				// CPT UI stores primary labels as HTML entities after native normalization.
				if ( in_array( $key, array( 'label', 'singular_label' ), true ) && is_string( $expected ) ) { $expected = htmlspecialchars( str_replace( '"', '', htmlspecialchars_decode( $expected ) ), ENT_QUOTES ); }
				if ( isset( $args[ $key ] ) && ( $current[ $key ] ?? null ) !== $expected ) { $verified = false; }
			}
			if ( $error || ! $verified ) {
				if ( $current === $before ) { return EMCP_Tools_Cptui_Api::error( 'native_error', 'CPT UI did not change the definition.' ); }
				if ( null !== $receipt && $current === $receipt && true === self::restore( $kind, $slug, $before ) ) {
					$change = self::record( 'recovered-' . $operation, $kind, $slug, null, true );
					return new WP_Error( 'native_error_recovered', 'CPT UI failed after saving. Its previous definition was restored.', array( 'change_id' => $change ) );
				}
				$change = self::record( 'partial-' . $operation, $kind, $slug, $before, null === $receipt || $current !== $receipt );
				return new WP_Error( 'partial_write', 'Inspect the native definition and History before retrying.', array( 'slug' => $slug, 'change_id' => $change ) );
			}
			try { EMCP_Tools_Cptui_Api::refresh( $kind, $slug ); } catch ( Throwable $e ) {
				$change = self::record( 'partial-refresh', $kind, $slug, $before );
				return new WP_Error( 'partial_write', 'Saved, but registration or soft rewrite refresh failed.', array( 'change_id' => $change, 'slug' => $slug ) );
			}
			$change = self::record( $operation, $kind, $slug, $before );
			if ( '' === $change ) {
				$restored = self::restore( $kind, $slug, $before );
				return EMCP_Tools_Cptui_Api::error( true === $restored ? 'history_error_recovered' : 'partial_write', 'History could not be saved. Read the native definition before further changes.' );
			}
			return array( 'definition' => $current, 'version' => EMCP_Tools_Cptui_Api::hash(), 'change_id' => $change );
		} );
	}
	private static function restore( string $kind, string $slug, ?array $before ) {
		// Force skips version conflicts only. It cannot authorize a newly installed storage adapter.
		foreach ( array( 'post-type' => 'cptui_post_types', 'taxonomy' => 'cptui_taxonomies' ) as $type => $option ) {
			if ( EMCP_Tools_Cptui_Api::rows( $type ) !== get_option( $option, array() ) ) { return EMCP_Tools_Cptui_Api::error( 'filtered_storage', 'An external storage filter controls these definitions. Use its native editor.' ); }
		}
		if ( ! $before ) { $empty = EMCP_Tools_Cptui_Api::empty_definition( $kind, $slug ); if ( is_wp_error( $empty ) ) { return $empty; } }
		else {
			if ( ! EMCP_Tools_Cptui_Api::safe( $kind, $before ) ) { return EMCP_Tools_Cptui_Api::error( 'unsupported_definition', 'The saved definition is outside the supported restore scope.' ); }
			$links = EMCP_Tools_Cptui_Api::links( $kind, $before ); if ( is_wp_error( $links ) ) { return $links; }
		}
		try {
			if ( $before ) { EMCP_Tools_Cptui_Api::save( $kind, $before, false ); }
			elseif ( 'post-type' === $kind ) { cptui_delete_post_type( array( 'name' => $slug ) ); }
			else { cptui_delete_taxonomy( array( 'name' => $slug ) ); }
			EMCP_Tools_Cptui_Api::refresh( $kind, $slug );
			return EMCP_Tools_Cptui_Api::row( $kind, $slug ) === $before;
		} catch ( Throwable $e ) { return false; }
	}
	public static function history_hash( $hash, array $rb ): string { return self::HISTORY === ( $rb['type'] ?? '' ) ? ( EMCP_Tools_Cptui_Api::active() ? EMCP_Tools_Cptui_Api::hash() : 'plugin_inactive' ) : (string) $hash; }
	public static function history_apply( $result, array $rb, bool $force ) {
		if ( self::HISTORY !== ( $rb['type'] ?? '' ) ) { return $result; }
		$ready = EMCP_Tools_Cptui_Api::ready(); if ( is_wp_error( $ready ) ) { return $ready; }
		return self::locked( static function () use ( $rb, $force ) {
			if ( ! EMCP_Tools_Cptui_Api::allowed() ) { return EMCP_Tools_Cptui_Api::error( 'forbidden', 'CPT UI permission denied.' ); }
			if ( ! $force && ! hash_equals( $rb['after_hash'], EMCP_Tools_Cptui_Api::hash() ) ) { return EMCP_Tools_Cptui_Api::error( 'conflict', 'Schemas changed before undo acquired its lease.' ); }
			$current = EMCP_Tools_Cptui_Api::row( $rb['kind'], $rb['slug'] );
			if ( ! $current || ! EMCP_Tools_Cptui_Api::safe( $rb['kind'], $current ) ) { return EMCP_Tools_Cptui_Api::error( 'linked_unavailable', 'The current definition is missing or outside the supported restore scope.' ); }
			$result = self::restore( $rb['kind'], $rb['slug'], $rb['before'] );
			if ( true === $result || is_wp_error( $result ) ) { return $result; }
			$change = self::record( 'partial-undo', $rb['kind'], $rb['slug'], $current );
			return new WP_Error( 'partial_write', 'Undo failed. Inspect the native definition before retrying.', array( 'change_id' => $change ) );
		} );
	}
}
