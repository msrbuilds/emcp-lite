<?php
/**
 * Plugin bootstrap: dependency check, class loading, and hook wiring.
 *
 * Hooked to `plugins_loaded` (priority 20) by the main plugin file. Everything
 * here is orchestration, loading class files and wiring them together, not
 * feature logic, which lives in the loaded classes.
 *
 * @package EMCP_Tools
 * @since   2.1.0 (extracted from emcp_tools_init)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads and wires the plugin.
 *
 * @since 2.1.0
 */
class EMCP_Tools_Bootstrap {

	/**
	 * Boots the plugin: runs the fallback migration, makes the MCP Adapter
	 * available, checks dependencies, then loads classes and wires hooks.
	 *
	 * @since 2.1.0 (since 1.0.0 as emcp_tools_init)
	 */
	public static function boot(): void {
		// Boot the MCP Adapter plugin when it is active. Without it EMCP still
		// loads its dashboard and offers a one-click install; the MCP endpoint
		// appears once the adapter is active. The Abilities API is core in 6.9+.
		require_once EMCP_TOOLS_DIR . 'includes/class-mcp-adapter-bootstrap.php';
		EMCP_Tools_Adapter_Bootstrap::ensure();
		if ( is_admin() ) {
			require_once EMCP_TOOLS_DIR . 'includes/admin/class-adapter-notice.php';
			EMCP_Tools_Adapter_Notice::init();
		}

		if ( ! self::check_dependencies() ) {
			return;
		}

		self::load_classes();
		self::wire_hooks();

		// The MCP tool surface (~80 ability classes + infra) is only used to
		// register/run abilities: an MCP REST call, the admin Tools screen, WP-CLI,
		// or cron. Load it eagerly for those requests; a plain front-end page view
		// skips it entirely (saving ~10 MB/request). register_abilities() /
		// register_mcp_server() also call load_mcp_surface() as a lazy fallback, so
		// a request type the gate misses still works the moment the Abilities API
		// fires.
		if ( self::needs_mcp_surface() ) {
			self::load_mcp_surface();
		}

		if ( is_admin() ) {
			self::load_admin();
		}

		// Boot the plugin singleton.
		EMCP_Tools_Plugin::instance();
	}

	/**
	 * Whether this request needs the MCP tool surface loaded up front: the admin,
	 * a REST request (the MCP endpoint + the plugin's own routes live there),
	 * WP-CLI (the mcp-adapter stdio bridge + our CLI tools), or cron (background
	 * jobs / library refresh). A plain front-end page view needs none of it.
	 *
	 * @since 3.12.2
	 *
	 * @return bool
	 */
	public static function needs_mcp_surface(): bool {
		return is_admin()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| self::is_rest_request();
	}

	/**
	 * Best-effort early detection of a REST request. `REST_REQUEST` is not defined
	 * until `parse_request` (after plugins_loaded), so at boot time we fall back to
	 * the request URI: the configured REST base (rest_get_url_prefix()) or the plain-permalink
	 * `?rest_route=` form. Deliberately broad, any REST request loads the full
	 * surface, so a custom MCP route is never missed.
	 *
	 * @since 3.12.2
	 *
	 * @return bool
	 */
	public static function is_rest_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return false;
		}
		// The configured prefix is authoritative; WordPress routes no other REST base.
		return false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' );
	}

	/**
	 * Whether Elementor is loaded/active in this request.
	 *
	 * Single source of truth for the optional-Elementor gate: the tool registrar,
	 * the admin Tools page, and the Brand Kits / Templates tabs all read this.
	 *
	 * @since 3.0.0
	 *
	 * @return bool
	 */
	public static function elementor_active(): bool {
		return (bool) did_action( 'elementor/loaded' );
	}

	/**
	 * Loads all class files (core, data, abilities, features). Self-guarded
	 * feature groups (Pro / atomic) are loaded unconditionally; they no-op on
	 * registration when their gate isn't met.
	 *
	 * @since 2.1.0
	 */
	private static function load_classes(): void {
		require_once EMCP_TOOLS_DIR . 'includes/class-page-builders.php';
		// Schema compatibility + the emcp_tools_register_ability() entry point
		// must load before any ability group registers.
		require_once EMCP_TOOLS_DIR . 'includes/class-schema-compat.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-id-generator.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-url-guard.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-frontend-page-fetcher.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-post-authorization.php';
		EMCP_Tools_Post_Authorization::register();
		require_once EMCP_TOOLS_DIR . 'includes/class-context-sections.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-site-context.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-elementor-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-element-factory.php';
		require_once EMCP_TOOLS_DIR . 'includes/schemas/class-control-mapper.php';
		require_once EMCP_TOOLS_DIR . 'includes/schemas/class-schema-generator.php';
		require_once EMCP_TOOLS_DIR . 'includes/validators/class-element-validator.php';
		require_once EMCP_TOOLS_DIR . 'includes/validators/class-settings-validator.php';
		require_once EMCP_TOOLS_DIR . 'includes/validators/class-widget-setting-keys.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-secret.php';
		require_once EMCP_TOOLS_DIR . 'includes/oauth/class-oauth-util.php';
		require_once EMCP_TOOLS_DIR . 'includes/cloud/class-cloud.php';
		require_once EMCP_TOOLS_DIR . 'includes/cloud/class-cloud-http.php';
		require_once EMCP_TOOLS_DIR . 'includes/cloud/class-cloud-connect.php';
		require_once EMCP_TOOLS_DIR . 'includes/cloud/class-cloud-client.php';
		require_once EMCP_TOOLS_DIR . 'includes/cloud/class-gateway-credential.php';
		require_once EMCP_TOOLS_DIR . 'includes/cloud/class-cloud-sync.php';
		require_once EMCP_TOOLS_DIR . 'includes/cloud/class-settings-sync.php';
		require_once EMCP_TOOLS_DIR . 'includes/oauth/class-oauth-store.php';
		require_once EMCP_TOOLS_DIR . 'includes/oauth/class-oauth-metadata.php';
		require_once EMCP_TOOLS_DIR . 'includes/oauth/class-oauth-clients.php';
		require_once EMCP_TOOLS_DIR . 'includes/oauth/class-oauth-authorize.php';
		require_once EMCP_TOOLS_DIR . 'includes/oauth/class-oauth-token.php';
		require_once EMCP_TOOLS_DIR . 'includes/oauth/class-oauth-bearer.php';
		require_once EMCP_TOOLS_DIR . 'includes/oauth/class-oauth-server.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-change-log.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-lease.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-management-policy.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-management-access.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-mcp-request-log.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-activity-stats.php';
		require_once EMCP_TOOLS_DIR . 'includes/attention/interface-attention-check.php';
		require_once EMCP_TOOLS_DIR . 'includes/attention/class-attention.php';
		require_once EMCP_TOOLS_DIR . 'includes/attention/class-attention-checks.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-connection-setup.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-connection-first-call.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-request-context.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-mcp-log-recorder.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-controller.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/class-admin-frame.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-frame.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-tools-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-tools.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-builders-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-builders.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-modules-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-modules.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-connection-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-connection.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-library-list.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-prompts-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-prompts.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-palette.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-context-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-context.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-history-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-dashboard-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-history.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-redirects-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-redirects.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-log-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-log.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-dashboard.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/class-changelog-parser.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-changelog-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-changelog.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/data/class-admin-brand-kits-data.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/rest/class-admin-rest-brand-kits.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-change-blobs.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-change-recorder.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-structured-import.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-search-ranker.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-search-index.php';
		require_once EMCP_TOOLS_DIR . 'includes/redirects/class-redirect-store.php';
		require_once EMCP_TOOLS_DIR . 'includes/redirects/class-redirect-handler.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-content-mirror.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-admin-bar.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-nav-menu-shortcode.php';
		// Editor-side auto-repair for AI-inserted static-save blocks (Kadence).
		require_once EMCP_TOOLS_DIR . 'includes/class-block-repair.php';
		add_action( 'init', array( 'EMCP_Tools_Nav_Menu_Shortcode', 'register' ) );
		// Brand Kits. The writer + backup store + free-kit fetcher load
		// unconditionally so the MCP REST/CLI/proxy surface can reach them.
		require_once EMCP_TOOLS_DIR . 'includes/class-system-kit-writer.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-kit-backup-store.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-free-brand-kits.php';
		// Atomic elements support (Elementor 4.0+).
		require_once EMCP_TOOLS_DIR . 'includes/class-atomic-props.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-atomic-styles.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-atomic-widget-map.php';
		// Modules framework (free) + built-in modules. The registry boots active
		// modules on `init`; each module self-gates on its options + availability.
		require_once EMCP_TOOLS_DIR . 'includes/modules/class-module.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/class-modules-registry.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/image-optimization/class-webp-generator.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/image-optimization/class-image-optimizer.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/image-optimization/class-webp-rewriter.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/image-optimization/class-bulk-optimizer.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/image-optimization/class-image-resizer.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/image-optimization/class-image-optimization-module.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/class-prompts-module.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/class-brand-kits-module.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/class-templates-module.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/class-agent-skills-module.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/svg-support/class-svg-sanitizer.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/svg-support/class-svg-support-module.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/class-cloud-module.php';
		// EMCP Themer (free): builder-agnostic theme builder engine + module + MCP tools.
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-matcher-registry.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-conditions.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-context.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-index.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-resolver.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-condition-schema.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-cpt.php';
		// Loop Items: per-item context, query builder, renderer (3.18.0).
		require_once EMCP_TOOLS_DIR . 'includes/themer/loop/class-themer-loop-context.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/loop/class-themer-loop-query.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/loop/class-themer-loop-renderer.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/loop/class-themer-loop-config.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-content-renderer.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-theme-adapters.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-render-controller.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-hfe-conflict.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-betheme-conflict.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/elements/class-themer-element-base.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/elements/class-themer-element-archive-posts.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/elements/class-themer-element-post-info.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/elements/class-themer-element-sitemap.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/elements/class-themer-element-search-form.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/elements/class-themer-element-post-comments.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/elements/class-themer-element-post-navigation.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/elements/class-themer-element-author-box.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/elements/class-themer-element-loop-base.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/elements/class-themer-element-loop-grid.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/elements/class-themer-element-loop-carousel.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/loop/class-themer-loop-assets.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/loop/class-themer-loop-options.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/loop/class-themer-loop-widget-map.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/loop/class-themer-loop-block-map.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/loop/class-themer-loop-rest.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/dynamic/class-themer-dynamic-catalog.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-dynamic.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/dynamic/class-themer-elementor-tags.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/dynamic/class-themer-block-bindings.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/dynamic/class-themer-dynamic-compiler.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-themer-dynamic-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/blocks/class-themer-blocks.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/widgets/class-themer-widgets.php';
		require_once EMCP_TOOLS_DIR . 'includes/themer/class-themer-metabox.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/class-themer-module.php';
		require_once EMCP_TOOLS_DIR . 'includes/modules/class-redirect-module.php';
		// Integration base classes stay runtime: their subclasses in the deferred
		// MCP surface extend them, so the bases must load first.
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-theme-integration.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-block-pack-integration.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/forms/class-form-integration.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/seo/class-seo-integration.php';

		require_once EMCP_TOOLS_DIR . 'includes/class-lite-edition.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-ability-registrar.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-plugin.php';
	}

	/** @var bool Whether the MCP tool surface (ability classes + infra) is loaded. */
	private static $mcp_surface_loaded = false;

	/**
	 * Loads the MCP tool surface: every ability class plus its exclusive infra
	 * (schema catalogs, guards, audits, integrations, stock clients). This is the
	 * heaviest part of the plugin (~80 files) and is only needed to register or run
	 * abilities, an MCP request, the admin Tools screen, WP-CLI, or cron. A plain
	 * front-end page view never touches it, so deferring these requires off the
	 * boot path keeps the per-request footprint low (memory, #128MB-hosts).
	 *
	 * Idempotent + called from three places: eagerly in boot() when the request
	 * needs the surface, and defensively at the top of register_abilities() /
	 * register_mcp_server() so the classes are always present the moment the
	 * Abilities API lazily fires, even on a request type not covered by the gate.
	 *
	 * @since 3.12.2
	 */
	public static function load_mcp_surface(): void {
		if ( self::$mcp_surface_loaded ) {
			return;
		}
		self::$mcp_surface_loaded = true;

		require_once EMCP_TOOLS_DIR . 'includes/widgets/class-widget-catalog.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-query-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-page-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-layout-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-widget-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-template-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-global-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-composite-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-unsplash-client.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-pexels-client.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-pixabay-client.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-stock-image-providers.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-stock-image-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-media-library-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-image-resize-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-block-tree.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-gutenberg-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-page-snapshot.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-snapshot-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-transaction-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-search-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-redirect-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-content-mirror-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-content-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-dispatcher-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-settings-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-package-guard.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-plugin-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-theme-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-user-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-nav-menu-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-acf-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-metabox-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-child-theme-builder.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-active-theme-integration.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-astra-integration.php';
		require_once EMCP_TOOLS_DIR . 'includes/blocks-catalog/class-spectra-catalog.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-spectra-integration.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-kadence-integration.php';
		require_once EMCP_TOOLS_DIR . 'includes/blocks-catalog/class-kadence-blocks-catalog.php';
		require_once EMCP_TOOLS_DIR . 'includes/blocks-catalog/class-kadence-pattern-library.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-kadence-blocks-integration.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/forms/class-cf7-integration.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/seo/class-slimseo-integration.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/seo/class-visibility-integration.php';
		require_once EMCP_TOOLS_DIR . 'includes/performance/class-performance-finding.php';
		require_once EMCP_TOOLS_DIR . 'includes/performance/class-performance-server-audit.php';
		require_once EMCP_TOOLS_DIR . 'includes/performance/class-performance-page-audit.php';
		require_once EMCP_TOOLS_DIR . 'includes/performance/class-performance-analyzer.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-performance-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/sql/class-sql-lexer.php';
		require_once EMCP_TOOLS_DIR . 'includes/sql/class-sql-policy.php';
		require_once EMCP_TOOLS_DIR . 'includes/class-database-guard.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-database-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/security/class-security-finding.php';
		require_once EMCP_TOOLS_DIR . 'includes/security/class-security-malware-audit.php';
		require_once EMCP_TOOLS_DIR . 'includes/security/class-security-integrity-audit.php';
		require_once EMCP_TOOLS_DIR . 'includes/security/class-security-hardening-audit.php';
		require_once EMCP_TOOLS_DIR . 'includes/security/class-security-software-audit.php';
		require_once EMCP_TOOLS_DIR . 'includes/security/class-security-scanner.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-security-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-svg-icon-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-cloud-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-atomic-widget-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-atomic-layout-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-global-classes-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-global-classes-write-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-global-variables-abilities.php';
		require_once EMCP_TOOLS_DIR . 'includes/abilities/class-themer-abilities.php';
	}

	/**
	 * Registers the runtime hooks shared by all contexts.
	 *
	 * @since 2.1.0
	 */
	private static function wire_hooks(): void {
		EMCP_Tools_Management_Access::init();
		EMCP_Tools_Attention::init();
		// structuredContent must be a JSON object; the adapter assigns a tool's
		// return value to it verbatim, so a list result makes strict clients
		// reject the response. Our own abilities are normalized at registration,
		// but this catches everything else the server surfaces, including the
		// three core/* abilities we do not register ourselves. Normalization is
		// idempotent, so running on both paths is harmless.
		add_filter(
			'mcp_adapter_tool_call_result',
			array( 'EMCP_Tools_Schema_Compat', 'normalize_result' ),
			99
		);

		// Invalidate Elementor's rendered-element cache on any _elementor_data
		// write, so MCP-created/edited pages never serve a stale empty render (#111).
		EMCP_Tools_Data::init();
		// Content search index: install-on-init + incremental re-index on save/delete.
		EMCP_Tools_Search_Index::init();
		// Detected context summaries go stale when the theme or plugins change.
		foreach ( array( 'switch_theme', 'activated_plugin', 'deactivated_plugin' ) as $emcp_hook ) {
			add_action( $emcp_hook, array( 'EMCP_Tools_Context_Sections', 'flush' ) );
		}
		EMCP_Tools_Change_Blobs::init();
		// Change ledger: the one-time cutover to the emcp_changes table (spec 9.1).
		EMCP_Tools_Change_Cutover::init();
		EMCP_Tools_Change_Notices::init();
		EMCP_Tools_Change_Retention::init();
		// The Redirect Manager (store table install + front-end 301/302 handler) is
		// booted by EMCP_Tools_Redirect_Module::register() only when the module is
		// active, a true kill switch from the Modules tab.
		// OAuth sign-in: install storage on init (routes wired in later phases).
		EMCP_Tools_OAuth_Server::init();
		// Content mirror: auto-export-on-save (gated by its option) + delete cleanup.
		EMCP_Tools_Content_Mirror::init();
		// Auto-repair flagged posts' AI-inserted blocks in the block editor.
		EMCP_Tools_Block_Repair::init();
		add_action( 'init', array( 'EMCP_Tools_Kit_Backup_Store', 'register_post_type' ) );
		// Modules: register built-ins, seed defaults once, boot the active ones
		// on `init` (after registration, before most feature hooks).
		$emcp_modules = EMCP_Tools_Modules_Registry::instance();
		$emcp_modules->register( new EMCP_Tools_Image_Optimization_Module() );
		$emcp_modules->register( new EMCP_Tools_Prompts_Module() );
		$emcp_modules->register( new EMCP_Tools_Brand_Kits_Module() );
		$emcp_modules->register( new EMCP_Tools_Templates_Module() );
		$emcp_modules->register( new EMCP_Tools_Themer_Module() );
		$emcp_modules->register( new EMCP_Tools_Redirect_Module() );
		$emcp_modules->register( new EMCP_Tools_Agent_Skills_Module() );
		$emcp_modules->register( new EMCP_Tools_SVG_Support_Module() );
		$emcp_modules->register( new EMCP_Tools_Cloud_Module() );
		do_action( 'emcp_tools_register_modules', $emcp_modules );
		$emcp_modules->apply_defaults();
		add_action( 'init', array( $emcp_modules, 'boot_active' ), 5 );

		// Admin-bar MCP status + exposure toggle (front-end + wp-admin; the class
		// self-gates on capability + is_admin_bar_showing()).
		( new EMCP_Tools_Admin_Bar() )->init();

		// Admin frame REST (cookie-only): promo dismissal + notification read state.
		( new EMCP_Tools_Admin_REST_Frame() )->register();
		( new EMCP_Tools_Admin_REST_Tools() )->register();
		( new EMCP_Tools_Admin_REST_Builders() )->register();
		( new EMCP_Tools_Admin_REST_Modules() )->register();
		( new EMCP_Tools_Admin_REST_Connection() )->register();
		( new EMCP_Tools_Admin_REST_Prompts() )->register();
		( new EMCP_Tools_Admin_REST_Brand_Kits() )->register();
		( new EMCP_Tools_Admin_REST_Palette() )->register();
		( new EMCP_Tools_Admin_REST_Context() )->register();
		( new EMCP_Tools_Admin_REST_History() )->register();
		( new EMCP_Tools_Admin_REST_Log() )->register();
		( new EMCP_Tools_Admin_REST_Dashboard() )->register();
		( new EMCP_Tools_Admin_REST_Changelog() )->register();
		if ( class_exists( 'EMCP_Tools_Redirect_Module' ) && EMCP_Tools_Redirect_Module::is_enabled() ) {
			( new EMCP_Tools_Admin_REST_Redirects() )->register(); // The table exists only while the module is on.
		}
	}

	/**
	 * Load the admin classes without wiring any admin hooks. The admin REST
	 * endpoints need them, and REST requests are not is_admin().
	 */
	public static function require_admin_classes(): void {
		require_once EMCP_TOOLS_DIR . 'includes/admin/class-admin-icons.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/class-admin-nav.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/class-admin-screens.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/class-admin-locked.php';
		require_once EMCP_TOOLS_DIR . 'includes/admin/class-admin.php';
	}

	/**
	 * Loads admin-only classes.
	 *
	 * @since 2.1.0
	 */
	private static function load_admin(): void {
		self::require_admin_classes();
		require_once EMCP_TOOLS_DIR . 'includes/admin/class-mcpb-builder.php';
	}

	/**
	 * Checks that all required dependencies are available, queuing an admin
	 * notice listing anything missing.
	 *
	 * @since 2.1.0 (since 1.0.0 as emcp_tools_check_dependencies)
	 *
	 * @return bool True if all dependencies are met.
	 */
	private static function check_dependencies(): bool {
		// PHP 8.1+ is required. Elementor 4.0+ uses 8.1+ features that silently
		// fail on older PHP (writes no-op, _elementor_data never persists).
		// WordPress only enforces Requires PHP at activation, not on every load,
		// so we re-check here to surface a clear admin notice if the host
		// downgraded PHP after the plugin was already installed.
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						sprintf(
							/* translators: %s: current PHP version */
							esc_html__( 'EMCP Lite requires PHP 8.1 or higher. Your server is running PHP %s, please upgrade PHP to avoid silent Elementor write failures.', 'emcp-lite' ),
							esc_html( PHP_VERSION )
						)
					);
				}
			);
			return false;
		}

		$missing = array();

		// WordPress Abilities API must be available. Core in WordPress 6.9+ (and
		// 7.0); only missing on older WordPress, which the plugin doesn't support.
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$missing[] = 'WordPress Abilities API (requires WordPress 6.9+)';
		}

		// The MCP Adapter plugin is not a boot requirement: EMCP_Tools_Adapter_Notice
		// guides the install, and the server registers once the adapter fires
		// mcp_adapter_init.

		if ( ! empty( $missing ) ) {
			add_action(
				'admin_notices',
				function () use ( $missing ) {
					$list = implode( ', ', $missing );
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						sprintf(
							/* translators: %s: comma-separated list of missing dependencies */
							esc_html__( 'EMCP Lite requires the following to be installed and active: %s', 'emcp-lite' ),
							'<strong>' . esc_html( $list ) . '</strong>'
						)
					);
				}
			);

			return false;
		}

		// Elementor is OPTIONAL. When absent, the plugin still loads and every
		// beyond-Elementor tool works; only the Elementor tool family + the
		// Elementor admin areas are unavailable. Builder availability is shown
		// on the Page Builders screen instead of an installation notice.

		return true;
	}
}
