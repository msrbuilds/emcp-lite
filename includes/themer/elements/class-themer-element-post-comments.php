<?php
/**
 * Post Comments element.
 *
 * Deliberately thin. Comment markup belongs to the active theme, and overriding
 * it causes more problems than it solves, so this invokes the theme's own
 * template and stays out of the way.
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
class EMCP_Tools_Themer_Element_Post_Comments extends EMCP_Tools_Themer_Element_Base {

	/**
	 * @since 3.13.0
	 * @param array $args hide_when_closed.
	 * @return string
	 */
	public static function render( array $args = array() ): string {
		$post_id = self::post_id();
		if ( ! $post_id ) {
			return '';
		}

		// Hiding a closed thread is what a template author expects, so it is the
		// default rather than something to remember to switch on.
		$hide_when_closed = ! array_key_exists( 'hide_when_closed', $args )
			|| self::truthy( $args['hide_when_closed'] );

		if ( $hide_when_closed && ! comments_open( $post_id ) ) {
			return '';
		}

		ob_start();
		comments_template();
		$html = (string) ob_get_clean();

		// A theme that renders nothing must not leave an empty container behind.
		return self::wrap( 'post-comments', $html );
	}
}
