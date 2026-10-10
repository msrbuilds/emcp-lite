<?php
/**
 * Shared helpers for the Themer element renderers.
 *
 * Element renderers know nothing about Elementor or Gutenberg, so the widget
 * and the block for a given element can both call the same code. That is what
 * keeps the two builders from drifting apart, which is the same principle the
 * value provider follows for dynamic sources.
 *
 * @package EMCP_Tools
 * @since   3.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.13.0
 */
abstract class EMCP_Tools_Themer_Element_Base {

	/**
	 * The post this element renders against.
	 *
	 * Delegates so elements resolve exactly as dynamic sources do, editor
	 * preview fallback included. A second copy of that logic is how two code
	 * paths start disagreeing.
	 *
	 * @since 3.13.0
	 * @return int
	 */
	public static function post_id(): int {
		return EMCP_Tools_Themer_Dynamic::current_post_id();
	}

	/**
	 * Normalize a toggle from either builder.
	 *
	 * Blocks send real booleans; Elementor sends 'yes' or ''. Both must mean the
	 * same thing to an element renderer.
	 *
	 * @since 3.13.0
	 * @param mixed $v Raw value.
	 * @return bool
	 */
	public static function truthy( $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( is_int( $v ) ) {
			return $v > 0;
		}
		$s = strtolower( trim( (string) $v ) );
		return '' !== $s && '0' !== $s && 'no' !== $s && 'false' !== $s;
	}

	/**
	 * Wrap rendered content in the house markup.
	 *
	 * Empty content renders nothing at all rather than an empty box, so a
	 * template does not gain a stray gap where a widget had nothing to show.
	 *
	 * @since 3.13.0
	 * @param string $key   Catalog key.
	 * @param string $inner Rendered inner HTML.
	 * @param array  $args  Element args, reserved for extra classes.
	 * @return string
	 */
	public static function wrap( string $key, string $inner, array $args = array() ): string {
		unset( $args );
		if ( '' === trim( $inner ) ) {
			return '';
		}
		return '<div class="emcp-dyn emcp-dyn-' . esc_attr( $key ) . '">' . $inner . '</div>';
	}
}
