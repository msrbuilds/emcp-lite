<?php
/**
 * WordPress Block Bindings source for the Themer dynamic sources.
 *
 * The Gutenberg counterpart to the Elementor dynamic tags: it lets a core
 * Heading, Paragraph, Image or Button pull its value from the same provider,
 * so both builders resolve identically.
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
class EMCP_Tools_Themer_Block_Bindings {

	const SOURCE = 'emcp/themer';

	/** Register the binding source once WordPress is ready. */
	public static function init(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
	}

	/** Register with WordPress. */
	public static function register(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}
		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'EMCP Themer', 'emcp-lite' ),
				'get_value_callback' => array( __CLASS__, 'resolve' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * Which value types may fill which block attribute.
	 *
	 * This is the same gate the Elementor categories apply, expressed for block
	 * attributes: without it an attachment id could be rendered into a heading.
	 *
	 * @param string $attribute Block attribute name.
	 * @param string $type      Value type.
	 * @return bool
	 */
	public static function field_accepts( string $attribute, string $type ): bool {
		$map = array(
			'content' => array( 'text', 'date' ),
			'alt'     => array( 'text' ),
			'title'   => array( 'text' ),
			'text'    => array( 'text', 'date' ),
			'url'     => array( 'url', 'image' ),
			'href'    => array( 'url' ),
			'id'      => array( 'image' ),
		);
		return isset( $map[ $attribute ] ) && in_array( $type, $map[ $attribute ], true );
	}

	/**
	 * Resolve a binding.
	 *
	 * @param array  $source_args    Binding args: { source, ... }.
	 * @param mixed  $block_instance Block instance (unused; context comes from the query).
	 * @param string $attribute_name Target attribute.
	 * @return string|null Null tells WordPress to leave the attribute alone.
	 */
	public static function resolve( $source_args, $block_instance = null, $attribute_name = '' ) {
		$args = (array) $source_args;
		$key  = isset( $args['source'] ) ? (string) $args['source'] : '';
		if ( '' === $key ) {
			return null;
		}
		$def = EMCP_Tools_Themer_Dynamic_Catalog::get( $key );
		if ( null === $def || empty( $def['bindable'] ) ) {
			return null;
		}
		if ( ! self::field_accepts( (string) $attribute_name, $def['type'] ) ) {
			return null;
		}

		$v = EMCP_Tools_Themer_Dynamic::value( $key, $args );
		if ( 'image' === $v['type'] ) {
			return 'id' === $attribute_name
				? (string) $v['value']['id']
				: (string) $v['value']['url'];
		}
		return (string) $v['value'];
	}
}
