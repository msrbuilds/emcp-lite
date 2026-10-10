<?php
/**
 * Author Box element: avatar, name, bio, and links for the post's author.
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
class EMCP_Tools_Themer_Element_Author_Box extends EMCP_Tools_Themer_Element_Base {

	/** Tags a name may use. Anything else falls back, so no markup can be injected. */
	const NAME_TAGS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'p' );

	/** Avatar bounds, so a stray control value cannot request a 10000px image. */
	const AVATAR_MIN = 16;
	const AVATAR_MAX = 512;

	/**
	 * @since 3.13.0
	 * @param array $args show_avatar, avatar_size, show_name, name_tag, show_bio,
	 *                    show_website, show_posts_link, layout, alignment.
	 * @return string
	 */
	public static function render( array $args = array() ): string {
		$post_id = self::post_id();
		if ( ! $post_id ) {
			return '';
		}
		$author_id = (int) get_post_field( 'post_author', $post_id );
		if ( ! $author_id ) {
			return '';
		}

		// Every part defaults to shown, so an empty args array is a sane box.
		$show = static function ( string $key ) use ( $args ): bool {
			return ! array_key_exists( $key, $args )
				|| EMCP_Tools_Themer_Element_Base::truthy( $args[ $key ] );
		};

		$parts = array();

		if ( $show( 'show_avatar' ) ) {
			$size   = (int) ( $args['avatar_size'] ?? 96 );
			$size   = max( self::AVATAR_MIN, min( self::AVATAR_MAX, $size ) );
			$avatar = get_avatar( $author_id, $size );
			if ( $avatar ) {
				$parts[] = '<div class="emcp-dyn-author-box__avatar">' . $avatar . '</div>';
			}
		}

		$body = array();

		if ( $show( 'show_name' ) ) {
			$name = (string) get_the_author_meta( 'display_name', $author_id );
			if ( '' !== $name ) {
				$tag    = strtolower( (string) ( $args['name_tag'] ?? 'h4' ) );
				$tag    = in_array( $tag, self::NAME_TAGS, true ) ? $tag : 'h4';
				$body[] = '<' . $tag . ' class="emcp-dyn-author-box__name">' . esc_html( $name ) . '</' . $tag . '>';
			}
		}

		if ( $show( 'show_bio' ) ) {
			$bio = (string) get_the_author_meta( 'description', $author_id );
			if ( '' !== $bio ) {
				$body[] = '<div class="emcp-dyn-author-box__bio">' . wp_kses_post( $bio ) . '</div>';
			}
		}

		$links = array();
		if ( $show( 'show_website' ) ) {
			$url = (string) get_the_author_meta( 'user_url', $author_id );
			if ( '' !== $url ) {
				$links[] = '<a class="emcp-dyn-author-box__website" href="' . esc_url( $url ) . '" rel="nofollow">'
					. esc_html__( 'Website', 'emcp-lite' ) . '</a>';
			}
		}
		if ( $show( 'show_posts_link' ) ) {
			$links[] = '<a class="emcp-dyn-author-box__posts" href="' . esc_url( (string) get_author_posts_url( $author_id ) ) . '">'
				. esc_html__( 'All posts', 'emcp-lite' ) . '</a>';
		}
		if ( $links ) {
			$body[] = '<div class="emcp-dyn-author-box__links">' . implode( ' ', $links ) . '</div>';
		}

		if ( $body ) {
			$parts[] = '<div class="emcp-dyn-author-box__body">' . implode( '', $body ) . '</div>';
		}
		if ( ! $parts ) {
			// Everything is switched off. An empty box is worse than no box.
			return '';
		}

		$layout    = 'stacked' === ( $args['layout'] ?? 'side' ) ? 'stacked' : 'side';
		$alignment = preg_replace( '/[^a-z]/', '', strtolower( (string) ( $args['alignment'] ?? '' ) ) );
		$classes   = 'emcp-dyn-author-box__inner is-' . $layout . ( $alignment ? ' has-text-align-' . $alignment : '' );

		return self::wrap(
			'author-box',
			'<div class="' . esc_attr( $classes ) . '">' . implode( '', $parts ) . '</div>'
		);
	}
}
