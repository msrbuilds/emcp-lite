<?php
/**
 * MCP Adapter integration.
 *
 * The Abilities API is core in WordPress 6.9+, but core does NOT expose
 * abilities over MCP: the WordPress MCP Adapter is what creates the
 * `/wp-json/mcp/...` server endpoint. EMCP Lite uses the official MCP Adapter
 * plugin from WordPress.org (slug `mcp-adapter`) instead of bundling a copy.
 * When the plugin is missing or inactive, EMCP Lite still loads its dashboard
 * and EMCP_Tools_Adapter_Notice offers a one-click install and activation.
 *
 * The adapter plugin boots itself when it loads (`WP\MCP\Plugin::instance()`),
 * which fires `mcp_adapter_init`, where EMCP registers its server. ensure()
 * also boots it (idempotent) in case another plugin only autoloaded the
 * classes without instantiating the adapter.
 *
 * @package EMCP_Tools
 * @since   1.7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locates and boots the MCP Adapter plugin.
 *
 * @since 1.7.4
 */
final class EMCP_Tools_Adapter_Bootstrap {

	/** WordPress.org slug of the official MCP Adapter plugin. */
	const PLUGIN_SLUG = 'mcp-adapter';

	/** Plugin basename of the official MCP Adapter plugin. */
	const PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';

	/** Oldest adapter release whose server API EMCP Lite supports. */
	const MIN_VERSION = '0.7.0';

	/**
	 * Where the adapter came from: 'plugin' (the MCP Adapter plugin),
	 * 'external' (a copy bundled in another plugin) or 'none'.
	 *
	 * @var string
	 */
	private static $source = 'none';

	/**
	 * Loads EMCP's own Composer dependencies and boots the adapter when available.
	 *
	 * Safe to call once, early in plugin init (before the dependency check).
	 *
	 * @since 1.7.4
	 */
	public static function ensure(): void {
		self::load_vendor();

		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			self::$source = 'none';
			return;
		}

		self::$source = self::is_plugin_active() ? 'plugin' : 'external';

		// Idempotent: a no-op when the adapter plugin already booted itself, and
		// the safety net when another plugin only autoloaded the classes.
		if ( class_exists( '\WP\MCP\Plugin' ) ) {
			\WP\MCP\Plugin::instance();
		} elseif ( method_exists( '\WP\MCP\Core\McpAdapter', 'instance' ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}
	}

	/**
	 * Load EMCP's bundled Composer dependencies (svg-sanitize) through the
	 * Jetpack Autoloader. Idempotent.
	 */
	public static function load_vendor(): void {
		$autoloader = EMCP_TOOLS_DIR . 'vendor/autoload_packages.php';
		if ( is_readable( $autoloader ) ) {
			require_once $autoloader;
		}
	}

	/**
	 * Whether the MCP Adapter core class is available (from any source).
	 *
	 * @since 1.7.4
	 *
	 * @return bool
	 */
	public static function is_loaded(): bool {
		return class_exists( '\WP\MCP\Core\McpAdapter' );
	}

	/** Whether the official MCP Adapter plugin is active on this site. */
	public static function is_plugin_active(): bool {
		$active = (array) get_option( 'active_plugins', array() );
		if ( in_array( self::PLUGIN_FILE, $active, true ) ) {
			return true;
		}
		return is_multisite() && array_key_exists( self::PLUGIN_FILE, (array) get_site_option( 'active_sitewide_plugins', array() ) );
	}

	/** Whether the official MCP Adapter plugin is installed (active or not). */
	public static function is_plugin_installed(): bool {
		return is_readable( trailingslashit( WP_PLUGIN_DIR ) . self::PLUGIN_FILE );
	}

	/** Version of the loaded McpAdapter class, or '' when none is loaded. */
	public static function loaded_version(): string {
		if ( ! self::is_loaded() ) {
			return '';
		}
		return defined( 'WP\MCP\Core\McpAdapter::VERSION' ) ? (string) constant( 'WP\MCP\Core\McpAdapter::VERSION' ) : '';
	}

	/** Whether a compatible adapter (MIN_VERSION or later, from any source) is loaded. */
	public static function is_ready(): bool {
		$version = self::loaded_version();
		return self::is_loaded() && ( '' === $version || version_compare( $version, self::MIN_VERSION, '>=' ) );
	}

	/**
	 * What the site needs to do for MCP to work.
	 *
	 * Another plugin may bundle an older adapter (Elementor 4.3 ships 0.6.1).
	 * Activating the MCP Adapter plugin normally replaces it, because both use
	 * the Jetpack Autoloader, which loads the newest copy. So an old copy only
	 * counts as a conflict once the MCP Adapter plugin is active.
	 *
	 * @return string 'ready'; 'missing' or 'inactive' (install or activate the
	 *                MCP Adapter plugin); 'outdated' (the MCP Adapter plugin itself
	 *                is older than MIN_VERSION); 'conflict' (it is active, but
	 *                another plugin's older copy is loaded instead).
	 */
	public static function status(): string {
		if ( self::is_ready() ) {
			return 'ready';
		}
		if ( ! self::is_plugin_active() ) {
			return self::is_plugin_installed() ? 'inactive' : 'missing';
		}
		$core = self::core_source();
		return empty( $core['ours'] ) && '' !== $core['plugin'] ? 'conflict' : 'outdated';
	}

	/**
	 * Where the adapter was loaded from: 'plugin', 'external' or 'none'.
	 *
	 * @since 1.7.4
	 *
	 * @return string
	 */
	public static function source(): string {
		return self::$source;
	}

	/** The adapter plugin's class root, used to recognise its own copy. */
	private static function plugin_class_dir(): string {
		return trailingslashit( WP_PLUGIN_DIR ) . self::PLUGIN_SLUG . '/includes/';
	}

	/**
	 * Which copy of McpAdapter a file is: the plugin folder it sits in and
	 * whether it is the official MCP Adapter plugin's own copy.
	 *
	 * @since 3.19.0
	 *
	 * @param string $file        The class file ('' when unknown).
	 * @param string $plugins_dir WP_PLUGIN_DIR.
	 * @param string $ours_dir    The MCP Adapter plugin's class root.
	 * @param string $version     The loaded McpAdapter::VERSION.
	 * @return array{file: string, version: string, ours: bool, plugin: string}
	 */
	public static function source_from_file( string $file, string $plugins_dir, string $ours_dir, string $version ): array {
		$norm    = static fn( string $p ) => str_replace( '\\', '/', $p );
		$file    = $norm( $file );
		$plugins = rtrim( $norm( $plugins_dir ), '/' ) . '/';
		$ours    = $norm( $ours_dir );
		$plugin  = '';
		$rel     = $file;
		if ( '' !== $file && 0 === stripos( $file, $plugins ) ) {
			$rel    = substr( $file, strlen( $plugins ) );
			$plugin = (string) strtok( $rel, '/' );
		}
		return array(
			'file'    => $rel,
			'version' => $version,
			'ours'    => '' !== $file && '' !== $ours && 0 === stripos( $file, $ours ),
			'plugin'  => $plugin,
		);
	}

	/**
	 * Where the loaded McpAdapter class came from. Another plugin can load its
	 * own bundled copy before the MCP Adapter plugin does.
	 *
	 * @since 3.19.0
	 *
	 * @return array{file: string, version: string, ours: bool, plugin: string}
	 */
	public static function core_source(): array {
		$class = '\WP\MCP\Core\McpAdapter';
		$file  = '';
		$ver   = '';
		if ( class_exists( $class, false ) ) {
			try {
				$file = (string) ( new \ReflectionClass( $class ) )->getFileName();
			} catch ( \Throwable $e ) {
				$file = '';
			}
			$const = ltrim( $class, '\\' ) . '::VERSION';
			$ver   = defined( $const ) ? (string) constant( $const ) : '';
		}
		return self::source_from_file( $file, WP_PLUGIN_DIR, self::plugin_class_dir(), $ver );
	}
}
