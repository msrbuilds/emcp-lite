<?php
/**
 * Post Info element: an ordered, configurable list of metadata items.
 *
 * The richer successor to Post Meta. Post Meta is deliberately left alone,
 * because its markup is already in saved templates and changing it would alter
 * existing sites.
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
class EMCP_Tools_Themer_Element_Post_Info extends EMCP_Tools_Themer_Element_Base {

	/** Item keys this element knows how to render. */
	const ITEMS = array( 'author', 'date', 'time', 'comments', 'terms', 'custom' );

	/**
	 * @since 3.13.0
	 * @param array $args items, taxonomy, meta_key, separator, layout,
	 *                    link_author, link_terms.
	 * @return string
	 */
	public static function render( array $args = array() ): string {
		$post_id = self::post_id();
		if ( ! $post_id ) {
			return '';
		}

		$items = array_values( array_filter( array_map( 'strval', (array) ( $args['items'] ?? array( 'author', 'date' ) ) ) ) );
		if ( ! $items ) {
			return '';
		}

		// A separator is decoration, so markup in it is stripped rather than escaped
		// into visible angle brackets.
		$sep    = esc_html( wp_strip_all_tags( (string) ( $args['separator'] ?? '' ) ) );
		$layout = 'stacked' === ( $args['layout'] ?? 'inline' ) ? 'stacked' : 'inline';

		$out = array();
		foreach ( $items as $item ) {
			if ( ! in_array( $item, self::ITEMS, true ) ) {
				continue; // Unknown item: skip rather than render a broken row.
			}
			$value = self::item( $item, $post_id, $args );
			if ( '' !== $value ) {
				$out[] = '<span class="emcp-dyn-post-info__item is-' . esc_attr( $item ) . '">' . $value . '</span>';
			}
		}
		if ( ! $out ) {
			return '';
		}

		$glue = '' !== $sep ? '<span class="emcp-dyn-post-info__sep">' . $sep . '</span>' : '';

		return self::wrap(
			'post-info',
			'<div class="emcp-dyn-post-info__list is-' . esc_attr( $layout ) . '">' . implode( $glue, $out ) . '</div>'
		);
	}

	/**
	 * Render one item.
	 *
	 * @param string $item    Item key.
	 * @param int    $post_id Post id.
	 * @param array  $args    Element args.
	 * @return string
	 */
	private static function item( string $item, int $post_id, array $args ): string {
		switch ( $item ) {
			case 'author':
				$author_id = (int) get_post_field( 'post_author', $post_id );
				$name      = esc_html( (string) get_the_author_meta( 'display_name', $author_id ) );
				if ( '' === $name ) {
					return '';
				}
				return self::truthy( $args['link_author'] ?? false )
					? '<a href="' . esc_url( (string) get_author_posts_url( $author_id ) ) . '">' . $name . '</a>'
					: $name;

			case 'date':
				return esc_html( (string) get_the_date( '', $post_id ) );

			case 'time':
				return esc_html( (string) get_post_time( (string) get_option( 'time_format' ), false, $post_id ) );

			case 'comments':
				return esc_html( (string) get_comments_number( $post_id ) );

			case 'terms':
				$terms = get_the_terms( $post_id, (string) ( $args['taxonomy'] ?? 'category' ) );
				if ( is_wp_error( $terms ) || ! $terms ) {
					return '';
				}
				$names = array();
				foreach ( (array) $terms as $t ) {
					if ( ! is_object( $t ) ) {
						continue;
					}
					$label   = esc_html( (string) ( $t->name ?? '' ) );
					$names[] = self::truthy( $args['link_terms'] ?? false )
						? '<a href="' . esc_url( (string) get_term_link( $t ) ) . '">' . $label . '</a>'
						: $label;
				}
				return implode( ', ', $names );

			case 'custom':
				$key = (string) ( $args['meta_key'] ?? '' );
				if ( '' === $key ) {
					return '';
				}
				$v = get_post_meta( $post_id, $key, true );
				return is_scalar( $v ) ? esc_html( (string) $v ) : '';
		}
		return '';
	}
}
