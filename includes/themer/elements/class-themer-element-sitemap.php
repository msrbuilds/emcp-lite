<?php
/**
 * Sitemap element: lists posts of chosen types and terms of chosen taxonomies.
 *
 * Every query is bounded. An unbounded sitemap is a genuine hazard on a site
 * with tens of thousands of posts, and a footer widget is exactly where that
 * would go unnoticed until the site fell over.
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
class EMCP_Tools_Themer_Element_Sitemap extends EMCP_Tools_Themer_Element_Base {

	/** Hard ceiling per list, whatever the caller asks for. */
	const MAX_ITEMS = 100;

	/**
	 * @since 3.13.0
	 * @param array $args post_types, taxonomies, hide_empty, exclude_ids,
	 *                    nofollow, limit.
	 * @return string
	 */
	public static function render( array $args = array() ): string {
		$post_types = array_filter( array_map( 'strval', (array) ( $args['post_types'] ?? array( 'page' ) ) ) );
		$taxonomies = array_filter( array_map( 'strval', (array) ( $args['taxonomies'] ?? array() ) ) );
		if ( ! $post_types && ! $taxonomies ) {
			return '';
		}

		$exclude    = array_map( 'intval', (array) ( $args['exclude_ids'] ?? array() ) );
		$nofollow   = self::truthy( $args['nofollow'] ?? false );
		$hide_empty = ! array_key_exists( 'hide_empty', $args ) || self::truthy( $args['hide_empty'] );
		$limit      = max( 1, min( self::MAX_ITEMS, (int) ( $args['limit'] ?? self::MAX_ITEMS ) ) );
		$rel        = $nofollow ? ' rel="nofollow"' : '';

		$lists = array();

		foreach ( $post_types as $type ) {
			$items = array();
			foreach ( (array) get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'numberposts'      => $limit,
					'orderby'          => 'title',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			) as $p ) {
				$id = is_object( $p ) ? (int) ( $p->ID ?? 0 ) : (int) $p;
				if ( ! $id || in_array( $id, $exclude, true ) ) {
					continue;
				}
				$items[] = '<li><a href="' . esc_url( (string) get_permalink( $id ) ) . '"' . $rel . '>'
					. esc_html( (string) get_the_title( $id ) ) . '</a></li>';
			}
			if ( $items ) {
				$lists[] = '<ul class="emcp-dyn-sitemap__list is-' . esc_attr( $type ) . '">' . implode( '', $items ) . '</ul>';
			}
		}

		foreach ( $taxonomies as $tax ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => $hide_empty,
					'number'     => $limit,
				)
			);
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$items = array();
			foreach ( (array) $terms as $t ) {
				if ( ! is_object( $t ) || in_array( (int) ( $t->term_id ?? 0 ), $exclude, true ) ) {
					continue;
				}
				$items[] = '<li><a href="' . esc_url( (string) get_term_link( $t ) ) . '"' . $rel . '>'
					. esc_html( (string) ( $t->name ?? '' ) ) . '</a></li>';
			}
			if ( $items ) {
				$lists[] = '<ul class="emcp-dyn-sitemap__list is-' . esc_attr( $tax ) . '">' . implode( '', $items ) . '</ul>';
			}
		}

		return self::wrap( 'sitemap', implode( '', $lists ) );
	}
}
