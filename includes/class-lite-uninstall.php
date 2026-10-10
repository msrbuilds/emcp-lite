<?php
/** Remove local credentials and maintenance jobs, preserving user content. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EMCP_Lite_Uninstall {
	/** Another installed edition owns compatible data, even while inactive. */
	public static function other_edition_installed(): bool {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$own = plugin_basename( dirname( __DIR__ ) . '/emcp-tools.php' );
		foreach ( get_plugins() as $file => $data ) {
			if ( $own !== $file && ( preg_match( '~(?:^|/)(?:emcp-tools|elementor-mcp)\.php$~', $file ) || in_array( $data['TextDomain'] ?? '', array( 'emcp-tools', 'elementor-mcp' ), true ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Uninstall every site in bounded batches. */
	public static function run(): void {
		if ( self::other_edition_installed() ) { return; }
		if ( ! is_multisite() ) { self::site(); return; }
		$offset = 0;
		do {
			$ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ) );
			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				try { self::site(); } finally { restore_current_blog(); }
			}
			$offset += count( $ids );
		} while ( 100 === count( $ids ) );
	}

	/** Clear credentials for the current site without deleting content or History. */
	private static function site(): void {
		global $wpdb;
		foreach ( array( 'emcp_tools_cloud_connection', 'emcp_tools_site_uuid', 'emcp_tools_cloud_identity_base', 'emcp_tools_gateway_provisioned', 'emcp_tools_oauth_db_version', 'emcp_tools_oauth_gc_throttle', 'emcp_tools_unsplash_access_key', 'emcp_tools_pexels_api_key', 'emcp_tools_pixabay_api_key' ) as $option ) {
			delete_option( $option );
		}
		delete_transient( 'emcp_tools_cloud_pending' );
		delete_transient( 'emcp_tools_oauth_gc_throttle' );
		foreach ( array( 'emcp_tools_oauth_gc', 'emcp_tools_changes_prune', 'emcp_tools_changes_migrate', 'emcp_tools_refresh_pro_prompts', 'emcp_tools_refresh_pro_brand_kits' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes plugin credential tables; no WordPress table API exists.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i, %i', $wpdb->prefix . 'emcp_oauth_tokens', $wpdb->prefix . 'emcp_oauth_clients' ) );
		// Authorization codes live in transients; remove only this plugin's exact prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Enumerate plugin credential transients once during uninstall.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_emcp_oauth_code_' ) . '%' ) );
		foreach ( $names as $name ) { delete_transient( substr( $name, strlen( '_transient_' ) ) ); }
	}
}
