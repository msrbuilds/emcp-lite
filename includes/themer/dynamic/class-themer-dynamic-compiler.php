<?php
/**
 * Compiles a friendly binding map into builder-native storage.
 *
 * Agents author bindings as { field: { source, args } }. Elementor stores them
 * as a shortcode-like tag string under __dynamic__, and Gutenberg as a
 * metadata.bindings map. Asking an agent to assemble
 * [elementor-tag id="..." settings="%7B%7D"] by hand would reliably produce
 * malformed templates, so the plugin builds it.
 *
 * Format confirmed against \Elementor\Core\DynamicTags\Manager:
 * DYNAMIC_SETTING_KEY = '__dynamic__', TAG_LABEL = 'elementor-tag', and the
 * parser reads id, name and settings by regex.
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
class EMCP_Tools_Themer_Dynamic_Compiler {

	/**
	 * Validate one binding entry.
	 *
	 * @param string $field            Target field or attribute.
	 * @param array  $entry            { source, args }.
	 * @param bool   $check_field_type Apply the attribute/type matrix.
	 * @return array{key:string,args:array,def:array}|\WP_Error
	 */
	private static function validate( string $field, array $entry, bool $check_field_type ) {
		$key = isset( $entry['source'] ) ? (string) $entry['source'] : '';
		if ( '' === $key ) {
			return new \WP_Error(
				'missing_dynamic_source',
				sprintf(
					/* translators: %s: field name. */
					__( 'The binding for "%s" has no source.', 'emcp-lite' ),
					$field
				)
			);
		}
		$def = EMCP_Tools_Themer_Dynamic_Catalog::get( $key );
		if ( null === $def ) {
			return new \WP_Error(
				'unknown_dynamic_source',
				sprintf(
					/* translators: %s: source key. */
					__( 'There is no dynamic source called "%s". Call list-dynamic-sources to see what is available.', 'emcp-lite' ),
					$key
				)
			);
		}
		if ( empty( $def['bindable'] ) ) {
			return new \WP_Error(
				'dynamic_type_mismatch',
				sprintf(
					/* translators: %s: source key. */
					__( 'The source "%s" produces markup, so it cannot be bound to a field. Use its block or widget instead.', 'emcp-lite' ),
					$key
				)
			);
		}
		if ( $check_field_type && ! EMCP_Tools_Themer_Block_Bindings::field_accepts( $field, $def['type'] ) ) {
			return new \WP_Error(
				'dynamic_type_mismatch',
				sprintf(
					/* translators: 1: source key, 2: value type, 3: field name. */
					__( 'The source "%1$s" produces a value of type %2$s, which cannot fill "%3$s".', 'emcp-lite' ),
					$key,
					$def['type'],
					$field
				)
			);
		}
		return array(
			'key'  => $key,
			'args' => isset( $entry['args'] ) ? (array) $entry['args'] : array(),
			'def'  => $def,
		);
	}

	/**
	 * Build an Elementor __dynamic__ map.
	 *
	 * @param array $dynamic { field: { source, args } }.
	 * @return array|\WP_Error
	 */
	public static function for_elementor( array $dynamic ) {
		$out = array();
		foreach ( $dynamic as $field => $entry ) {
			// Elementor decides per control which categories it accepts, so the
			// block attribute matrix is not applied here; only the source is checked.
			$v = self::validate( (string) $field, (array) $entry, false );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			$out[ (string) $field ] = sprintf(
				'[elementor-tag id="%1$s" name="%2$s" settings="%3$s"]',
				substr( md5( (string) $field . '|' . $v['key'] ), 0, 7 ),
				EMCP_Tools_Themer_Elementor_Tags::tag_name( $v['key'] ),
				rawurlencode( (string) wp_json_encode( $v['args'] ) )
			);
		}
		return $out;
	}

	/**
	 * Build a Gutenberg metadata.bindings map.
	 *
	 * @param array $bindings { attribute: { source, args } }.
	 * @return array|\WP_Error
	 */
	public static function for_blocks( array $bindings ) {
		$out = array();
		foreach ( $bindings as $attribute => $entry ) {
			$v = self::validate( (string) $attribute, (array) $entry, true );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			$out[ (string) $attribute ] = array(
				'source' => EMCP_Tools_Themer_Block_Bindings::SOURCE,
				'args'   => array_merge( array( 'source' => $v['key'] ), $v['args'] ),
			);
		}
		return $out;
	}
}
