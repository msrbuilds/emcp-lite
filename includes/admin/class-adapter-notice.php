<?php
/**
 * Prompts administrators to install or activate the MCP Adapter plugin.
 *
 * EMCP Lite relies on the official MCP Adapter plugin from WordPress.org for
 * its MCP server endpoint. Until it is installed and active, the Plugins screen
 * and EMCP screens show a notice with a one-click install and activation.
 * Installing runs only after an explicit click, with a nonce and the
 * install_plugins and activate_plugins capabilities.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Adapter_Notice {

	/** admin-post action that installs and activates the adapter. */
	const ACTION = 'emcp_lite_install_adapter';

	/** Query argument carrying the install result back to the notice. */
	const RESULT = 'emcp_adapter';

	/** Hook the notice and the install handler. */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_install' ) );
	}

	/** The Plugins screen and EMCP's own screens. */
	private static function on_relevant_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return 'plugins' === $screen->id || false !== strpos( (string) $screen->id, 'emcp-tools' );
	}

	/** Core's installer, used when the host needs filesystem credentials. */
	private static function core_install_url(): string {
		return wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . EMCP_Tools_Adapter_Bootstrap::PLUGIN_SLUG ), 'install-plugin_' . EMCP_Tools_Adapter_Bootstrap::PLUGIN_SLUG );
	}

	/** Plugins > Add New, searched for the adapter. */
	private static function search_url(): string {
		return self_admin_url( 'plugin-install.php?s=' . rawurlencode( 'MCP Adapter' ) . '&tab=search&type=term' );
	}

	/** Render the notice for the current adapter status. */
	public static function render(): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! self::on_relevant_screen() ) {
			return;
		}
		// Read-only display of the result of the nonce-protected install request.
		$result = isset( $_GET[ self::RESULT ] ) && is_string( $_GET[ self::RESULT ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$status = EMCP_Tools_Adapter_Bootstrap::status();

		if ( 'ready' === $status ) {
			if ( 'activated' === $result ) {
				echo '<div class="notice notice-success is-dismissible"><p>'
					. esc_html__( 'MCP Adapter is installed and active. You can now connect an AI client from EMCP Lite > Connection.', 'emcp-lite' )
					. '</p></div>';
			}
			return;
		}

		echo '<div class="notice notice-warning emcp-adapter-notice"><p><strong>' . esc_html__( 'EMCP Lite needs the MCP Adapter plugin', 'emcp-lite' ) . '</strong></p>';

		if ( 'failed' === $result ) {
			echo '<p>' . esc_html__( 'MCP Adapter could not be installed automatically. Install it from Plugins > Add New instead.', 'emcp-lite' ) . '</p>';
		}

		if ( 'outdated' === $status ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: minimum MCP Adapter version. */
					__( 'EMCP Lite needs MCP Adapter %s or later. Update it on the Plugins screen to connect AI clients.', 'emcp-lite' ),
					EMCP_Tools_Adapter_Bootstrap::MIN_VERSION
				)
			) . '</p><p><a class="button button-primary" href="' . esc_url( self_admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Go to Plugins', 'emcp-lite' ) . '</a></p></div>';
			return;
		}

		echo '<p>' . esc_html__( 'AI clients connect through the free, official MCP Adapter plugin from WordPress.org. Your EMCP settings and tools are ready and start working as soon as it is active.', 'emcp-lite' ) . '</p>';

		$inactive = 'inactive' === $status;
		if ( ! $inactive && ! current_user_can( 'install_plugins' ) ) {
			echo '<p>' . esc_html__( 'Ask a site administrator to install and activate MCP Adapter.', 'emcp-lite' ) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::ACTION );
		echo '<button type="submit" class="button button-primary">' . esc_html( $inactive ? __( 'Activate MCP Adapter', 'emcp-lite' ) : __( 'Install and activate MCP Adapter', 'emcp-lite' ) ) . '</button>';
		if ( ! $inactive ) {
			echo ' &nbsp;<a href="' . esc_url( self::search_url() ) . '">' . esc_html__( 'View on Plugins > Add New', 'emcp-lite' ) . '</a>';
		}
		echo '</p></form></div>';
	}

	/** Install (when needed) and activate the MCP Adapter plugin, then return. */
	public static function handle_install(): void {
		check_admin_referer( self::ACTION );
		$install = ! EMCP_Tools_Adapter_Bootstrap::is_plugin_installed();
		if ( ! current_user_can( 'activate_plugins' ) || ( $install && ! current_user_can( 'install_plugins' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to install or activate plugins on this site.', 'emcp-lite' ), '', array( 'response' => 403 ) );
		}

		$back = remove_query_arg( self::RESULT, wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=emcp-tools' ) );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( $install ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			// Hosts that need FTP or SSH credentials: core's installer asks for them.
			if ( 'direct' !== get_filesystem_method() ) {
				wp_safe_redirect( self::core_install_url() );
				exit;
			}

			$api = plugins_api(
				'plugin_information',
				array(
					'slug'   => EMCP_Tools_Adapter_Bootstrap::PLUGIN_SLUG,
					'fields' => array( 'sections' => false ),
				)
			);
			if ( is_wp_error( $api ) || empty( $api->download_link ) ) {
				self::finish( $back, 'failed' );
			}

			$upgrader  = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );
			$installed = $upgrader->install( (string) $api->download_link );
			if ( true !== $installed || ! EMCP_Tools_Adapter_Bootstrap::is_plugin_installed() ) {
				self::finish( $back, 'failed' );
			}
		}

		$activated = activate_plugin( EMCP_Tools_Adapter_Bootstrap::PLUGIN_FILE );
		self::finish( $back, is_wp_error( $activated ) ? 'failed' : 'activated' );
	}

	/**
	 * Redirect back to the screen that started the install.
	 *
	 * @param string $back   Return URL.
	 * @param string $result 'activated' or 'failed'.
	 */
	private static function finish( string $back, string $result ): void {
		wp_safe_redirect( add_query_arg( self::RESULT, $result, $back ) );
		exit;
	}
}
