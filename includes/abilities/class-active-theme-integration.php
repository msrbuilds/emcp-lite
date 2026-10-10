<?php
/**
 * Active Theme integration — the framework-agnostic Themes-tab pack.
 *
 * `theme-read`  : get-theme-context, get-mods
 * `theme-write` : set-mods (allowlist-guarded), create-child-theme (confirm-gated)
 *
 * Works for ANY active theme. Building pages reuses the Gutenberg/Elementor
 * tools; this integration supplies the context the agent reasons over. File
 * edits happen via the Filesystem tools after create-child-theme.
 *
 * @package EMCP_Tools
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The framework-agnostic active-theme integration.
 */
class EMCP_Tools_Active_Theme_Integration extends EMCP_Tools_Theme_Integration {

	/**
	 * Known theme-framework template slugs (for the `framework` context hint).
	 *
	 * @var string[]
	 */
	const KNOWN_FRAMEWORKS = array( 'astra', 'kadence', 'generatepress', 'oceanwp', 'blocksy', 'neve', 'hello-elementor' );

	/**
	 * Theme mods this pack refuses to write (owned by other domains / structural).
	 *
	 * @var string[]
	 */
	const REFUSED_MODS = array( 'nav_menu_locations', 'sidebars_widgets', 'custom_css_post_id' );

	/**
	 * Theme-support features probed for the context.
	 *
	 * @var string[]
	 */
	const PROBED_SUPPORTS = array( 'post-thumbnails', 'custom-logo', 'editor-styles', 'wp-block-styles', 'align-wide', 'responsive-embeds', 'custom-background', 'html5' );

	public function id(): string {
		return 'theme';
	}

	public function label(): string {
		return __( 'Active Theme', 'emcp-lite' );
	}

	public function is_available(): bool {
		return true;
	}

	protected function operations(): array {
		return array(
			'get-theme-context'  => array(
				'mode' => 'read',
				'run'  => array( $this, 'execute_get_context' ),
				'perm' => array( $this, 'can_read' ),
				'desc' => __( 'Active theme identity + capabilities: parent/child, detected framework, is_block_theme, template dir, theme supports, registered menu locations, and whether a child theme exists. Call this first.', 'emcp-lite' ),
			),
			'get-mods'           => array(
				'mode' => 'read',
				'run'  => array( $this, 'execute_get_mods' ),
				'perm' => array( $this, 'can_read' ),
				'desc' => __( 'All theme_mod values for the active theme (customizer-backed state).', 'emcp-lite' ),
			),
		);
	}

	/**
	 * @return bool switch_themes gate for create-child-theme.
	 */
	public function can_manage_theme(): bool {
		return current_user_can( 'switch_themes' ) && current_user_can( 'edit_theme_options' );
	}

	/**
	 * @param array $input Unused.
	 * @return array
	 */
	public function execute_get_context( $input ): array {
		$stylesheet = (string) get_stylesheet();
		$template   = (string) get_template();
		$theme      = wp_get_theme();

		$supports = array();
		foreach ( self::PROBED_SUPPORTS as $feature ) {
			if ( current_theme_supports( $feature ) ) {
				$supports[] = $feature;
			}
		}

		return array(
			'active'         => $stylesheet,
			'parent'         => $template,
			'is_child'       => $stylesheet !== $template,
			'framework'      => in_array( $template, self::KNOWN_FRAMEWORKS, true ) ? $template : null,
			'is_block_theme' => function_exists( 'wp_is_block_theme' ) ? (bool) wp_is_block_theme() : false,
			'name'           => $theme ? (string) $theme->get( 'Name' ) : $stylesheet,
			'version'        => $theme ? (string) $theme->get( 'Version' ) : '',
			'template_dir'   => (string) get_stylesheet_directory(),
			'supports'       => $supports,
			'menu_locations' => function_exists( 'get_registered_nav_menus' ) ? (array) get_registered_nav_menus() : array(),
			'has_child'      => EMCP_Tools_Child_Theme_Builder::child_exists(),
		);
	}

	/**
	 * @param array $input Unused.
	 * @return array
	 */
	public function execute_get_mods( $input ): array {
		$mods = get_theme_mods();
		return array( 'mods' => is_array( $mods ) ? $mods : array() );
	}

}
