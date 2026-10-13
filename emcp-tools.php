<?php
/**
 * Plugin Name: EMCP Lite
 * Plugin URI: https://emcptools.com/
 * Description: Connect AI clients to WordPress through MCP, with content, media, Elementor and Gutenberg tools, access controls and change history.
 * Version: 1.0.2
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Mian Shahzad Raza
 * Author URI: https://msrbuilds.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: emcp-lite
 * Domain Path: /languages
 *
 * @package EMCP_Lite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Let an already installed EMCP edition load first, without modifying its data.
add_action( 'plugins_loaded', 'emcp_lite_start', 19 );

/** Load the retained Free runtime after compatibility and coexistence checks. */
function emcp_lite_start() {
	if ( defined( 'EMCP_TOOLS_VERSION' ) || defined( 'ELEMENTOR_MCP_VERSION' ) ) {
		add_action( 'admin_notices', 'emcp_lite_conflict_notice' );
		return;
	}
	if ( version_compare( PHP_VERSION, '8.1', '<' ) || version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
		add_action( 'admin_notices', 'emcp_lite_requirements_notice' );
		return;
	}
	define( 'EMCP_TOOLS_VERSION', '1.0.2' );
	define( 'EMCP_TOOLS_DIR', plugin_dir_path( __FILE__ ) );
	define( 'EMCP_TOOLS_URL', plugin_dir_url( __FILE__ ) );
	define( 'EMCP_TOOLS_BASENAME', plugin_basename( __FILE__ ) );
	require_once EMCP_TOOLS_DIR . 'includes/class-mcp-adapter-bootstrap.php';
	EMCP_Tools_Adapter_Bootstrap::preload_bundled_namespace();
	require_once EMCP_TOOLS_DIR . 'includes/class-bootstrap.php';
	add_action( 'plugins_loaded', array( 'EMCP_Tools_Bootstrap', 'boot' ), 20 );
}

/** Explain why two editions cannot serve the same MCP namespace. */
function emcp_lite_conflict_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'EMCP Lite is paused because another EMCP edition is active. Activate one edition per site.', 'emcp-lite' ) . '</p></div>';
	}
}

/** Explain the supported host requirements. */
function emcp_lite_requirements_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'EMCP Lite requires WordPress 6.9 or later and PHP 8.1 or later.', 'emcp-lite' ) . '</p></div>';
}

if ( ! function_exists( 'emcp_tools_upgrade_url' ) ) {
	/** Optional information about the separately distributed product. */
	function emcp_tools_upgrade_url() {
		return 'https://emcptools.com/pricing';
	}
}
