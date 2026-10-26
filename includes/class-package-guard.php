<?php
/**
 * Shared read-only helper for the Plugins & Themes MCP tools.
 *
 * Reports which plugins are active or protected, and loads the wp-admin
 * includes that the plugin/theme listing and WordPress.org search tools need
 * (they are absent on REST/WP-CLI requests). EMCP Lite never installs,
 * updates, activates, deactivates or deletes plugins or themes.
 *
 * @package EMCP_Tools
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helpers shared by the plugin + theme ability groups.
 *
 * @since 3.0.0
 */
class EMCP_Tools_Package_Guard {

	/**
	 * Plugin files reported as protected in the plugin listing.
	 *
	 * EMCP itself and Elementor / Elementor Pro. The EMCP basename is
	 * self-resolved from the constant, never hardcoded.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	public static function protected_plugin_files(): array {
		// Hardcoded core list — ALWAYS enforced. The filter can only ADD to it,
		// never remove an entry.
		$core = array( 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' );
		if ( defined( 'EMCP_TOOLS_BASENAME' ) ) {
			$core[] = EMCP_TOOLS_BASENAME;
		}
		/**
		 * Filter ADDITIONAL MCP-protected plugin basenames. The hardcoded core
		 * list above is always enforced regardless of what this filter returns.
		 *
		 * @since 3.0.0
		 * @param string[] $extra Extra protected plugin basenames (added to the core list).
		 */
		$extra = (array) apply_filters( 'emcp_tools_protected_plugins', array() );
		return array_values( array_unique( array_merge( $core, $extra ) ) );
	}

	/**
	 * Whether a plugin file is protected.
	 *
	 * @since 3.0.0
	 * @param string $file Plugin basename (e.g. "elementor/elementor.php").
	 * @return bool
	 */
	public static function is_protected_plugin( string $file ): bool {
		return in_array( $file, self::protected_plugin_files(), true );
	}

	/**
	 * Whether a plugin is currently active (site or network).
	 *
	 * @since 3.0.0
	 * @param string $file Plugin basename.
	 * @return bool
	 */
	public static function is_active_plugin( string $file ): bool {
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) ) {
			return true;
		}
		return function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $file );
	}

	/**
	 * Load the wp-admin plugin/theme includes on demand.
	 *
	 * get_plugins(), plugins_api() and themes_api() live under wp-admin/includes
	 * and are NOT loaded on the REST/WP-CLI requests the MCP server runs in.
	 *
	 * @since 3.0.0
	 */
	public static function load_admin_includes(): void {
		$includes = array(
			'wp-admin/includes/plugin.php',
			'wp-admin/includes/plugin-install.php',
			'wp-admin/includes/theme.php',
		);
		foreach ( $includes as $rel ) {
			$path = ABSPATH . $rel;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}
}
