<?php
/**
 * Scaffolds and activates a child theme of the active parent theme.
 *
 * This is the framework-agnostic enabler for agent-driven theme file edits: it
 * creates `style.css` + a minimal `functions.php` inside a fresh child theme
 * directory and switches to it. The agent then performs file writes through the
 * existing Filesystem tools (ABSPATH-confined, `edit_files`-gated, backed up,
 * audit-logged) targeting the returned child directory.
 *
 * Deliberately conservative: it only ever creates a child of the CURRENT active
 * parent, refuses when the active theme is already a child (no grandchildren),
 * never touches the parent or any other theme, and is idempotent (an existing
 * child is activated, never overwritten).
 *
 * @package EMCP_Tools
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Child theme scaffolder.
 */
class EMCP_Tools_Child_Theme_Builder {

	/**
	 * Whether the active theme is itself a child (stylesheet != template).
	 *
	 * @return bool
	 */
	public static function is_active_a_child(): bool {
		return get_stylesheet() !== get_template();
	}

	/**
	 * The active parent (template) slug.
	 *
	 * @return string
	 */
	public static function parent_slug(): string {
		return (string) get_template();
	}

	/**
	 * The child theme slug (`<parent>-child`).
	 *
	 * @return string
	 */
	public static function child_slug(): string {
		return self::parent_slug() . '-child';
	}

	/**
	 * The child theme directory path.
	 *
	 * @return string
	 */
	public static function child_dir(): string {
		return trailingslashit( get_theme_root() ) . self::child_slug();
	}

	/**
	 * Whether the child theme directory already exists on disk.
	 *
	 * @return bool
	 */
	public static function child_exists(): bool {
		return is_dir( self::child_dir() );
	}

}
