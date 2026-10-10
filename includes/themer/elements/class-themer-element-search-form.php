<?php
/**
 * Search Form element.
 *
 * Built by hand rather than delegating to get_search_form(), because the theme
 * version cannot be configured and a Search or 404 template needs control over
 * the placeholder and button.
 *
 * Accessibility is not optional here. The input is labelled, and an icon-only
 * button carries an accessible name, because a search box nobody can operate is
 * worse than no search box.
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
class EMCP_Tools_Themer_Element_Search_Form extends EMCP_Tools_Themer_Element_Base {

	/**
	 * Unlike the other elements this needs no post: a search form is valid on a
	 * 404 or an empty search result, which is exactly where it matters most.
	 *
	 * @since 3.13.0
	 * @param array $args placeholder, button_type, button_text, full_width.
	 * @return string
	 */
	public static function render( array $args = array() ): string {
		$placeholder = (string) ( $args['placeholder'] ?? __( 'Search', 'emcp-lite' ) );
		$button_type = 'text' === ( $args['button_type'] ?? 'icon' ) ? 'text' : 'icon';
		$button_text = (string) ( $args['button_text'] ?? __( 'Search', 'emcp-lite' ) );
		$full        = self::truthy( $args['full_width'] ?? false );

		// Unique per render so several forms on one template do not share an id.
		$id = 'emcp-search-' . substr( md5( uniqid( 'emcp', true ) ), 0, 8 );

		$button = 'text' === $button_type
			? esc_html( $button_text )
			: '<span class="emcp-dyn-search-form__icon" aria-hidden="true">&#9906;</span>';

		$html = '<form role="search" method="get" class="emcp-dyn-search-form__form' . ( $full ? ' is-full' : '' ) . '"'
			. ' action="' . esc_url( home_url( '/' ) ) . '">'
			. '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $placeholder ) . '</label>'
			. '<input type="search" id="' . esc_attr( $id ) . '" class="emcp-dyn-search-form__input" name="s"'
			. ' value="' . esc_attr( (string) get_search_query() ) . '"'
			. ' placeholder="' . esc_attr( $placeholder ) . '" />'
			. '<button type="submit" class="emcp-dyn-search-form__submit" aria-label="' . esc_attr( $button_text ) . '">'
			. $button
			. '</button>'
			. '</form>';

		return self::wrap( 'search-form', $html );
	}
}
