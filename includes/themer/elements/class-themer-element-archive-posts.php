<?php
/**
 * Archive Posts element: renders the main query with layout options and
 * pagination, which is the reason Archive Loop needed a successor.
 *
 * Reads the MAIN query without iterating it. A Themer template is still using
 * that query, so advancing or resetting it here would disturb everything around
 * this element.
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
class EMCP_Tools_Themer_Element_Archive_Posts extends EMCP_Tools_Themer_Element_Base {

	/**
	 * @since 3.13.0
	 * @param array $args layout, columns, show_image, show_excerpt, excerpt_words,
	 *                    show_more, more_text, show_pagination.
	 * @return string
	 */
	public static function render( array $args = array() ): string {
		global $wp_query;

		$posts = ( $wp_query instanceof WP_Query ) ? (array) $wp_query->posts : array();
		if ( ! $posts ) {
			return '';
		}

		$layout  = 'list' === ( $args['layout'] ?? 'grid' ) ? 'list' : 'grid';
		$columns = max( 1, min( 6, (int) ( $args['columns'] ?? 3 ) ) );
		$show    = static function ( string $key ) use ( $args ): bool {
			return ! array_key_exists( $key, $args )
				|| EMCP_Tools_Themer_Element_Base::truthy( $args[ $key ] );
		};

		$cards = array();
		foreach ( $posts as $p ) {
			$id = is_object( $p ) ? (int) ( $p->ID ?? 0 ) : (int) $p;
			if ( ! $id ) {
				continue;
			}
			$card = '';

			if ( $show( 'show_image' ) ) {
				$thumb = (int) get_post_thumbnail_id( $id );
				if ( $thumb ) {
					$src = wp_get_attachment_image_src( $thumb, 'medium' );
					if ( is_array( $src ) && isset( $src[0] ) ) {
						$card .= '<a class="emcp-dyn-archive-posts__image" href="' . esc_url( (string) get_permalink( $id ) ) . '">'
							. '<img src="' . esc_url( (string) $src[0] ) . '" alt="" /></a>';
					}
				}
			}

			$card .= '<h3 class="emcp-dyn-archive-posts__title"><a href="' . esc_url( (string) get_permalink( $id ) ) . '">'
				. esc_html( (string) get_the_title( $id ) ) . '</a></h3>';

			if ( $show( 'show_excerpt' ) ) {
				$words   = max( 5, min( 200, (int) ( $args['excerpt_words'] ?? 25 ) ) );
				$excerpt = wp_trim_words( (string) get_the_excerpt( $id ), $words );
				if ( '' !== trim( $excerpt ) ) {
					$card .= '<p class="emcp-dyn-archive-posts__excerpt">' . esc_html( $excerpt ) . '</p>';
				}
			}

			if ( $show( 'show_more' ) ) {
				$more  = (string) ( $args['more_text'] ?? __( 'Read more', 'emcp-lite' ) );
				$card .= '<a class="emcp-dyn-archive-posts__more" href="' . esc_url( (string) get_permalink( $id ) ) . '">'
					. esc_html( $more ) . '</a>';
			}

			$cards[] = '<article class="emcp-dyn-archive-posts__card">' . $card . '</article>';
		}

		if ( ! $cards ) {
			return '';
		}

		$html = '<div class="emcp-dyn-archive-posts__wrap is-' . esc_attr( $layout ) . ' is-cols-' . (int) $columns . '">'
			. implode( '', $cards ) . '</div>';

		if ( $show( 'show_pagination' ) ) {
			$links = paginate_links( array( 'type' => 'array' ) );
			if ( is_array( $links ) && $links ) {
				$html .= '<nav class="emcp-dyn-archive-posts__pagination">'
					. implode( '', array_map( 'wp_kses_post', $links ) ) . '</nav>';
			}
		}

		return self::wrap( 'archive-posts', $html );
	}
}
