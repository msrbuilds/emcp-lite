<?php
/** Remove local credentials and maintenance jobs, preserving user content. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EMCP_Lite_Uninstall {
	/** @var bool|null Test seam for other_edition_installed(). */
	public static $other_edition = null;

	/** @var int Seconds the remote opt-in deletes may take across a network. */
	public static $budget = 30;

	/** Another installed edition owns compatible data, even while inactive. */
	public static function other_edition_installed(): bool {
		if ( null !== self::$other_edition ) {
			return self::$other_edition;
		}
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
		// Lite owns its opt-in record, so it is removed whatever the shared-data guard decides.
		self::optin_all();
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

	/** Load what the opt-in cleanup needs; uninstall.php runs without the plugin bootstrap. */
	private static function load_optin(): void {
		$dir = dirname( __DIR__ ) . '/includes/';
		if ( ! defined( 'EMCP_TOOLS_VERSION' ) ) {
			$data = function_exists( 'get_file_data' ) ? get_file_data( dirname( __DIR__ ) . '/emcp-tools.php', array( 'Version' => 'Version' ) ) : array();
			define( 'EMCP_TOOLS_VERSION', (string) ( $data['Version'] ?? '' ) );
		}
		foreach ( array( 'EMCP_Tools_Secret' => 'class-secret.php', 'EMCP_Tools_Lease' => 'class-lease.php', 'EMCP_Tools_Optin_State' => 'optin/class-optin-state.php', 'EMCP_Tools_Optin_Client' => 'optin/class-optin-client.php' ) as $class => $file ) {
			if ( ! class_exists( $class ) ) {
				require_once $dir . $file;
			}
		}
	}

	/** Delete the opt-in record and option on every site, within the time budget. */
	private static function optin_all(): void {
		self::load_optin();
		$deadline = microtime( true ) + self::$budget;
		if ( ! is_multisite() ) { self::optin_site( $deadline ); return; }
		$offset = 0;
		do {
			$ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ) );
			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				try { self::optin_site( $deadline ); } finally { restore_current_blog(); }
			}
			$offset += count( $ids );
		} while ( 100 === count( $ids ) );
	}

	/** One site: send the delete (or the cancel) with a credential issued for this site, then forget it. */
	private static function optin_site( float $deadline ): void {
		$state = EMCP_Tools_Optin_State::read();
		$cred  = EMCP_Tools_Optin_State::credential( $state );
		if ( $cred && microtime( true ) < $deadline ) {
			if ( in_array( $state['state'], array( 'pending', 'cancelling' ), true ) ) {
				EMCP_Tools_Optin_Client::cancel( (string) $cred['install_id'], (string) $cred['request_id'] );
			} elseif ( in_array( $state['state'], array( 'allowed', 'opted_out' ), true ) && '' !== (string) ( $cred['site_key'] ?? '' ) ) {
				EMCP_Tools_Optin_Client::site( 'delete', $cred );
			}
		}
		delete_option( EMCP_Tools_Optin_State::OPTION );
		delete_option( EMCP_Tools_Lease::PREFIX . EMCP_Tools_Optin_State::LEASE );
	}

	/** Clear credentials for the current site without deleting content or History. */
	private static function site(): void {
		global $wpdb;
		foreach ( array( 'emcp_tools_cloud_connection', 'emcp_tools_site_uuid', 'emcp_tools_cloud_identity_base', 'emcp_tools_gateway_provisioned', 'emcp_tools_gateway_tokens_extended', 'emcp_tools_oauth_db_version', 'emcp_tools_oauth_gc_throttle', 'emcp_tools_unsplash_access_key', 'emcp_tools_pexels_api_key', 'emcp_tools_pixabay_api_key' ) as $option ) {
			delete_option( $option );
		}
		delete_transient( 'emcp_tools_cloud_pending' );
		delete_transient( 'emcp_tools_oauth_gc_throttle' );
		foreach ( array( 'emcp_tools_oauth_gc', 'emcp_tools_cloud_keepalive', 'emcp_tools_changes_prune', 'emcp_tools_changes_migrate', 'emcp_tools_refresh_pro_prompts', 'emcp_tools_refresh_pro_brand_kits' ) as $hook ) {
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
