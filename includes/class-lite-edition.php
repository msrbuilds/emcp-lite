<?php
/** WordPress.org edition catalog, matching physically removed implementations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class EMCP_Lite_Edition {
	/** Allowed presentation markup at final output boundaries. */
	public static function allowed_html( string $context = 'content' ): array {
		$allowed = wp_kses_allowed_html( 'post' );
		$common = array( 'id' => true, 'class' => true, 'role' => true, 'aria-*' => true, 'data-*' => true );
		$allowed['form'] = $common + array( 'action' => true, 'method' => true );
		$allowed['input'] = $common + array( 'type' => true, 'name' => true, 'value' => true, 'placeholder' => true, 'required' => true, 'checked' => true, 'disabled' => true, 'autocomplete' => true );
		$allowed['textarea'] = $common + array( 'name' => true, 'rows' => true, 'cols' => true, 'required' => true, 'placeholder' => true );
		$allowed['select'] = $common + array( 'name' => true, 'required' => true, 'multiple' => true, 'disabled' => true );
		$allowed['option'] = array( 'value' => true, 'selected' => true, 'disabled' => true );
		$allowed['button'] = $common + array( 'type' => true, 'name' => true, 'value' => true, 'disabled' => true );
		$allowed['svg'] = $common + array( 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'focusable' => true );
		$allowed['path'] = array( 'd' => true, 'fill' => true, 'fill-rule' => true, 'clip-rule' => true, 'stroke' => true );
		foreach ( array( 'circle' => array( 'cx', 'cy', 'r' ), 'rect' => array( 'x', 'y', 'width', 'height', 'rx', 'ry' ), 'line' => array( 'x1', 'x2', 'y1', 'y2' ), 'polyline' => array( 'points' ), 'polygon' => array( 'points' ), 'g' => array( 'transform' ) ) as $tag => $attributes ) {
			$allowed[ $tag ] = array_fill_keys( array_merge( $attributes, array( 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin' ) ), true );
		}
		$allowed['label'] = $common + array( 'for' => true );
		$allowed['input'] += array( 'min' => true, 'max' => true, 'step' => true, 'minlength' => true, 'maxlength' => true );
		$allowed['iframe'] = $common + array( 'src' => true, 'title' => true, 'width' => true, 'height' => true, 'loading' => true, 'allow' => true, 'allowfullscreen' => true, 'frameborder' => true );
		if ( 'document' === $context ) {
			$allowed['html'] = array( 'lang' => true );
			$allowed['head'] = array();
			$allowed['body'] = $common;
			$allowed['title'] = array();
			$allowed['meta'] = array( 'charset' => true, 'name' => true, 'content' => true );
			$allowed['link'] = array( 'rel' => true, 'href' => true, 'id' => true, 'media' => true, 'type' => true );
		}
		return $allowed;
	}

	/** Generated CSS must never escape its style element. */
	public static function sanitize_stylesheet( string $css ): string {
		if ( preg_match( '/[<\x00]|expression\s*\(|@import|(?:javascript|vbscript)\s*:/i', $css ) ) {
			return '';
		}
		return wp_strip_all_tags( $css );
	}

	const CODE_POST_TYPES = array( 'custom_css', 'elementor_snippet', 'emcp_php_snippet', 'emcp_code_snippet', 'wpcode', 'wpcode_snippet' );
	const REMOVED_TOOLS = array(
		'emcp-tools/query',
		'emcp-tools/update-settings',
		'emcp-tools/add-custom-css',
		'emcp-tools/install-plugin',
		'emcp-tools/activate-plugin',
		'emcp-tools/deactivate-plugin',
		'emcp-tools/update-plugin',
		'emcp-tools/delete-plugin',
		'emcp-tools/install-theme',
		'emcp-tools/switch-theme',
		'emcp-tools/update-theme',
		'emcp-tools/delete-theme',
		'emcp-tools/create-user',
		'emcp-tools/update-user',
		'emcp-tools/theme-write',
		'emcp-tools/cloud-config-deploy',
		'emcp-tools/add-code-snippet',
		'emcp-tools/add-custom-js',
		'emcp-tools/cloud-backup',
		'emcp-tools/cloud-list',
		'emcp-tools/cloud-marketplace-install',
		'emcp-tools/cloud-marketplace-list',
		'emcp-tools/cloud-pull',
		'emcp-tools/create-php-snippet',
		'emcp-tools/create-theme-php-template',
		'emcp-tools/delete-file',
		'emcp-tools/delete-php-snippet',
		'emcp-tools/delete-rows',
		'emcp-tools/delete-theme-php-template',
		'emcp-tools/dispatch-wp-cli',
		'emcp-tools/edit-file',
		'emcp-tools/export-sandbox-artifact',
		'emcp-tools/get-php-snippet',
		'emcp-tools/get-theme-php-template',
		'emcp-tools/get-wp-cli-job',
		'emcp-tools/import-sandbox-artifact',
		'emcp-tools/insert-row',
		'emcp-tools/list-code-snippets',
		'emcp-tools/list-directory',
		'emcp-tools/list-php-snippets',
		'emcp-tools/list-theme-php-templates',
		'emcp-tools/list-wp-cli-jobs',
		'emcp-tools/read-file',
		'emcp-tools/run-wp-cli',
		'emcp-tools/search-files',
		'emcp-tools/update-php-snippet',
		'emcp-tools/update-rows',
		'emcp-tools/update-theme-php-template',
		'emcp-tools/validate-php-snippet',
		'emcp-tools/write-file',
	);
	/** Removed operations cannot be restored by saved preferences. */
	public static function allows_tool( string $name ): bool {
		return ! in_array( $name, self::REMOVED_TOOLS, true );
	}

	/** Validate nested tool data before any write, including batch arguments. */
	public static function validate_write( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( 'post_type' === $key && is_string( $item ) && in_array( $item, self::CODE_POST_TYPES, true ) ) {
					return new WP_Error( 'emcp_lite_code_not_supported', __( 'Code documents cannot be written through EMCP Lite.', 'emcp-lite' ) );
				}
				if ( 'post_id' === $key && is_numeric( $item ) && function_exists( 'get_post_type' ) && in_array( get_post_type( (int) $item ), self::CODE_POST_TYPES, true ) ) {
					return new WP_Error( 'emcp_lite_code_not_supported', __( 'Code documents cannot be written through EMCP Lite.', 'emcp-lite' ) );
				}
				if ( in_array( strtolower( (string) $key ), array( 'custom_css', 'custom_js', 'custom_javascript', 'php_code', 'js_code', 'css_code' ), true ) && null !== $item && '' !== $item ) {
					return new WP_Error( 'emcp_lite_code_not_supported', __( 'Custom code insertion is not available in EMCP Lite. Use structured design controls.', 'emcp-lite' ) );
				}
				$result = self::validate_write( $item );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		} elseif ( is_string( $value ) ) {
			// Validate JSON strings too: Elementor imports may carry encoded settings.
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return self::validate_write( $decoded );
			}
			if ( preg_match( '/<\?(?:php|=)|<\s*(?:script|style|object|embed|iframe|link)\b/i', $value ) ) {
				return new WP_Error( 'emcp_lite_code_not_supported', __( 'Executable markup and custom stylesheets are not available in EMCP Lite.', 'emcp-lite' ) );
			}
			if ( class_exists( 'WP_HTML_Tag_Processor' ) && false !== strpos( $value, '<' ) ) {
				$html = new WP_HTML_Tag_Processor( $value );
				while ( $html->next_tag() ) {
					foreach ( (array) $html->get_attribute_names_with_prefix( '' ) as $attribute ) {
						$raw = $html->get_attribute( $attribute );
						if ( 0 === stripos( $attribute, 'on' ) || in_array( strtolower( $attribute ), array( 'srcdoc', 'style' ), true ) || ( is_string( $raw ) && preg_match( '/^(?:javascript|vbscript|data):/i', preg_replace( '/[\x00-\x20]+/', '', $raw ) ) ) ) {
							return new WP_Error( 'emcp_lite_code_not_supported', __( 'Script attributes are not available in EMCP Lite.', 'emcp-lite' ) );
						}
					}
				}
			}
		}
		return true;
	}

	/** Remove catalog entries whose implementations do not ship in this edition. */
	public static function catalog( array $catalog ): array {
		foreach ( $catalog as $id => &$group ) {
			foreach ( self::REMOVED_TOOLS as $slug ) { unset( $group['tools'][ $slug ] ); }
			if ( empty( $group['tools'] ) ) { unset( $catalog[ $id ] ); }
		}
		unset( $group );
		return $catalog;
	}
}
