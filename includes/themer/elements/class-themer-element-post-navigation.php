<?php
/**
 * Post Navigation element: previous and next links for a single post.
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
class EMCP_Tools_Themer_Element_Post_Navigation extends EMCP_Tools_Themer_Element_Base {

	/**
	 * @since 3.13.0
	 * @param array $args in_same_term, taxonomy, show_label, show_title,
	 *                    show_arrow, prev_label, next_label.
	 * @return string
	 */
	public static function render( array $args = array() ): string {
		if ( ! self::post_id() ) {
			return '';
		}

		$in_same_term = self::truthy( $args['in_same_term'] ?? false );
		$taxonomy     = (string) ( $args['taxonomy'] ?? 'category' );
		$show_title   = ! array_key_exists( 'show_title', $args ) || self::truthy( $args['show_title'] );
		$show_label   = ! array_key_exists( 'show_label', $args ) || self::truthy( $args['show_label'] );
		$show_arrow   = ! array_key_exists( 'show_arrow', $args ) || self::truthy( $args['show_arrow'] );

		// With the title hidden there must still be something clickable.
		$link_format = $show_title ? '%title' : esc_html__( 'Post', 'emcp-lite' );

		$prev = (string) get_previous_post_link( '%link', $link_format, $in_same_term, '', $taxonomy );
		$next = (string) get_next_post_link( '%link', $link_format, $in_same_term, '', $taxonomy );

		if ( '' === trim( $prev ) && '' === trim( $next ) ) {
			return '';
		}

		$html = self::side( $prev, (string) ( $args['prev_label'] ?? __( 'Previous', 'emcp-lite' ) ), '<', 'prev', $show_label, $show_arrow )
			. self::side( $next, (string) ( $args['next_label'] ?? __( 'Next', 'emcp-lite' ) ), '>', 'next', $show_label, $show_arrow );

		return self::wrap( 'post-navigation', $html );
	}

	/**
	 * Render one direction, or nothing when that neighbour does not exist.
	 *
	 * @param string $link       Link markup from WordPress.
	 * @param string $label      Label text.
	 * @param string $arrow      Arrow glyph.
	 * @param string $dir        'prev' or 'next'.
	 * @param bool   $show_label Whether to show the label.
	 * @param bool   $show_arrow Whether to show the arrow.
	 * @return string
	 */
	private static function side( string $link, string $label, string $arrow, string $dir, bool $show_label, bool $show_arrow ): string {
		if ( '' === trim( $link ) ) {
			return '';
		}
		$inner = '';
		if ( $show_arrow ) {
			// Decorative: the link text already carries the meaning.
			$inner .= '<span class="emcp-dyn-post-navigation__arrow" aria-hidden="true">' . esc_html( $arrow ) . '</span>';
		}
		if ( $show_label ) {
			$inner .= '<span class="emcp-dyn-post-navigation__label">' . esc_html( $label ) . '</span>';
		}
		$inner .= '<span class="emcp-dyn-post-navigation__title">' . wp_kses_post( $link ) . '</span>';

		return '<div class="emcp-dyn-post-navigation__' . esc_attr( $dir ) . '">' . $inner . '</div>';
	}
}
