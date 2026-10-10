<?php
/**
 * Kadence Blocks native adapter, independent of the Kadence theme. The block-pack
 * integration exposes its operations in the dedicated Kadence Blocks tab.
 *
 * `kadence-blocks-read`  : list-blocks (curated catalog over the live registry),
 *                          get-block-schema (real attributes + defaults + example)
 * `kadence-blocks-write` : add-block (insert a Kadence block with a generated
 *                          uniqueID + curated inner-block template)
 *
 * Registers when the Kadence Blocks plugin is active (independent of the active
 * theme, like Spectra). Building reuses the Gutenberg block tree; this pack is the
 * Kadence-aware discover -> inspect -> act layer, mirroring the Elementor widget
 * catalog. Every Kadence block carries a `uniqueID` (its per-block CSS key) and a
 * render_callback that injects CSS from attributes, so a headless-inserted block
 * self-styles.
 *
 * @package EMCP_Tools
 * @since   3.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kadence block catalog + insertion integration.
 */
class EMCP_Tools_Kadence_Blocks_Integration extends EMCP_Tools_Theme_Integration {

	protected function capabilities(): array {
		return array( 'supports_patterns' => true, 'supports_preview' => true, 'styles_model' => 'uniqueid' );
	}


	public function id(): string {
		return 'kadence-blocks';
	}

	public function label(): string {
		return __( 'Kadence Blocks', 'emcp-lite' );
	}

	public function is_available(): bool {
		return EMCP_Tools_Kadence_Blocks_Catalog::is_active();
	}

	// Building content, not theme options.
	public function can_read(): bool {
		return current_user_can( 'edit_posts' );
	}
	public function can_write(): bool {
		return current_user_can( 'edit_posts' );
	}

	protected function operations(): array {
		return array(
			'list-blocks'      => array(
				'mode' => 'read',
				'run'  => array( $this, 'execute_list_blocks' ),
				'perm' => array( $this, 'can_read' ),
				'desc' => __( 'Compact catalog of the Kadence blocks on this site (name, title, description, category). Optional { category (layout|content|media|embed|form|dynamic|header), search }. Step 1 of discover -> inspect -> act.', 'emcp-lite' ),
			),
			'get-block-schema' => array(
				'mode' => 'read',
				'run'  => array( $this, 'execute_get_block_schema' ),
				'perm' => array( $this, 'can_read' ),
				'desc' => __( 'A Kadence block\'s key attributes (real names + defaults from the live registry) + a ready-to-use example ({ name } or { names: [...] }). { full: true } returns the block\'s full attribute set. Notes container children + RichText-content blocks.', 'emcp-lite' ),
			),
			'add-block'        => array(
				'mode' => 'write',
				'run'  => array( $this, 'execute_add_block' ),
				'perm' => array( $this, 'can_write' ),
				'desc' => __( 'Insert a Kadence block into a post ({ post_id, block, attributes?, position? }); a uniqueID is generated and container inner blocks (columns, buttons, list items) are scaffolded. position: { mode: append|prepend|before|after|inside, path?: [..] }.', 'emcp-lite' ),
			),
			'list-patterns'    => array(
				'mode' => 'read',
				'run'  => array( $this, 'execute_list_patterns' ),
				'perm' => array( $this, 'can_read' ),
				'desc' => __( 'List Kadence Design Library patterns (professionally-designed, editor-VALID sections). Optional { category (e.g. Hero, Testimonials, "Counter or Stats", "Call to Action", "Media and Text"), search, include_pro }. Call { categories: true } to list category names. PREFER a pattern over hand-building a whole section.', 'emcp-lite' ),
			),
			'insert-pattern'   => array(
				'mode' => 'write',
				'run'  => array( $this, 'execute_insert_pattern' ),
				'perm' => array( $this, 'can_write' ),
				'desc' => __( 'Insert a Kadence Design Library pattern into a post by id ({ post_id, pattern, position?, localize_images? }). Inserts Kadence\'s own canonical block markup (editor-valid, palette-harmonized). localize_images:true downloads the pattern images into the Media Library (needs upload_files). Patterns ship with placeholder copy — edit the headings/text (RichText) afterward.', 'emcp-lite' ),
			),
		);
	}

	/**
	 * @param array $input { category?, search? }.
	 * @return array
	 */
	public function execute_list_blocks( $input ): array {
		$category = isset( $input['category'] ) ? (string) $input['category'] : '';
		$search   = isset( $input['search'] ) ? (string) $input['search'] : '';
		$blocks   = EMCP_Tools_Kadence_Blocks_Catalog::blocks_index( $category, $search );
		return array(
			'count'  => count( $blocks ),
			'blocks' => $blocks,
		);
	}

	/**
	 * @param array $input { name? , names?[], full? }.
	 * @return array|WP_Error
	 */
	public function execute_get_block_schema( $input ) {
		$names = array();
		if ( isset( $input['names'] ) && is_array( $input['names'] ) ) {
			$names = array_map( 'strval', $input['names'] );
		} elseif ( isset( $input['name'] ) ) {
			$names = array( (string) $input['name'] );
		}
		if ( empty( $names ) ) {
			return new WP_Error( 'missing_name', __( 'Provide a block "name" or "names" array.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$full = ! empty( $input['full'] );

		$out = array();
		foreach ( $names as $name ) {
			$norm         = $this->normalize( $name );
			$out[ $norm ] = $this->schema_for( $norm, $full );
		}
		return ( 1 === count( $out ) ) ? reset( $out ) : array( 'blocks' => $out );
	}

	/**
	 * @param array $input { post_id, block, attributes?, position? }.
	 * @return array|WP_Error
	 */
	public function execute_add_block( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		$name    = $this->normalize( (string) ( $input['block'] ?? '' ) );

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'emcp-lite' ), array( 'status' => 404 ) );
		}
		if ( ! EMCP_Tools_Kadence_Blocks_Catalog::is_known( $name ) ) {
			/* translators: %s: the requested identifier or comma-separated identifiers. */
			return new WP_Error( 'unknown_block', sprintf( __( 'Unknown Kadence block: %s', 'emcp-lite' ), $name ), array( 'status' => 404 ) );
		}
		$attrs    = ( isset( $input['attributes'] ) && is_array( $input['attributes'] ) ) ? $input['attributes'] : array();
		$position = ( isset( $input['position'] ) && is_array( $input['position'] ) ) ? $input['position'] : array( 'mode' => 'append' );

		$block  = $this->build_block( $name, $attrs );
		$tree   = EMCP_Tools_Block_Tree::from_markup( (string) $post->post_content );
		// An unresolvable path makes insert() a silent no-op, which would report
		// success while dropping the block. Fail loudly instead.
		if ( ! EMCP_Tools_Block_Tree::position_is_valid( $tree, $position ) ) {
			return new WP_Error( 'invalid_position', __( 'position.path does not resolve to a block in this post. List the blocks in this post first and use a real index path.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$tree   = EMCP_Tools_Block_Tree::insert( $tree, array( $block ), $position );
		$markup = EMCP_Tools_Block_Tree::to_markup( $tree );

		$result = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $markup ) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Kadence blocks use a static JS save(), so server-built markup can never
		// byte-match it. Flag the post so the block editor auto-repairs the
		// inserted blocks on next open (runs the editor's own recovery). Must be
		// AFTER wp_update_post — the save_post clear-hook fires on that update.
		if ( class_exists( 'EMCP_Tools_Block_Repair' ) ) {
			EMCP_Tools_Block_Repair::flag( $post_id );
		}
		return array(
			'added'     => $name,
			'unique_id' => $block['attrs']['uniqueID'],
			'post_id'   => $post_id,
			'note'      => __( 'Renders correctly on the front end. Kadence blocks use a static JS save(), so the stored markup differs from the editor\'s; EMCP auto-repairs the inserted blocks the next time the post is opened in the block editor (the human just clicks Update once).', 'emcp-lite' ),
		);
	}

	/**
	 * @param array $input { category?, search?, include_pro?, categories? }.
	 * @return array|WP_Error
	 */
	public function execute_list_patterns( $input ) {
		if ( ! EMCP_Tools_Kadence_Pattern_Library::is_available() ) {
			return new WP_Error( 'kadence_library_unavailable', __( 'The Kadence Prebuilt Library is not available on this site.', 'emcp-lite' ), array( 'status' => 501 ) );
		}
		if ( ! empty( $input['categories'] ) ) {
			// Category discovery lists all category names by default (incl. pro).
			$include_pro = array_key_exists( 'include_pro', $input ) ? ! empty( $input['include_pro'] ) : true;
			return array( 'categories' => EMCP_Tools_Kadence_Pattern_Library::categories( $include_pro ) );
		}
		$category    = isset( $input['category'] ) ? (string) $input['category'] : '';
		$search      = isset( $input['search'] ) ? (string) $input['search'] : '';
		$include_pro = ! empty( $input['include_pro'] );
		$patterns    = EMCP_Tools_Kadence_Pattern_Library::list_patterns( $category, $search, $include_pro );
		$out         = array(
			'count'    => count( $patterns ),
			'patterns' => $patterns,
		);
		if ( empty( $patterns ) && empty( EMCP_Tools_Kadence_Pattern_Library::raw_catalog() ) ) {
			$out['note'] = __( 'The pattern catalog is empty. Open Kadence → Design Library once (any block editor) to populate the local pattern cache, then retry.', 'emcp-lite' );
		}
		return $out;
	}

	/**
	 * @param array $input { post_id, pattern, position?, localize_images? }.
	 * @return array|WP_Error
	 */
	public function execute_insert_pattern( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		$pattern = (string) ( $input['pattern'] ?? '' );

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'emcp-lite' ), array( 'status' => 404 ) );
		}
		if ( '' === $pattern ) {
			return new WP_Error( 'missing_pattern', __( 'Provide a "pattern" id (from list-patterns).', 'emcp-lite' ), array( 'status' => 400 ) );
		}

		$markup = EMCP_Tools_Kadence_Pattern_Library::get_markup( $pattern, ! empty( $input['localize_images'] ) );
		if ( is_wp_error( $markup ) ) {
			return $markup;
		}

		$new_blocks = array_values( array_filter( parse_blocks( (string) $markup ), static function ( $b ) {
			return ! empty( $b['blockName'] );
		} ) );
		if ( empty( $new_blocks ) ) {
			return new WP_Error( 'empty_pattern', __( 'The pattern produced no blocks.', 'emcp-lite' ), array( 'status' => 422 ) );
		}

		$position = ( isset( $input['position'] ) && is_array( $input['position'] ) ) ? $input['position'] : array( 'mode' => 'append' );
		$tree     = EMCP_Tools_Block_Tree::from_markup( (string) $post->post_content );
		// An unresolvable path makes insert() a silent no-op, which would report
		// success while dropping the block. Fail loudly instead.
		if ( ! EMCP_Tools_Block_Tree::position_is_valid( $tree, $position ) ) {
			return new WP_Error( 'invalid_position', __( 'position.path does not resolve to a block in this post. List the blocks in this post first and use a real index path.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$tree     = EMCP_Tools_Block_Tree::insert( $tree, $new_blocks, $position );
		$out      = EMCP_Tools_Block_Tree::to_markup( $tree );

		$result = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $out ) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'inserted'     => $pattern,
			'blocks_added' => count( $new_blocks ),
			'post_id'      => $post_id,
			'note'         => __( 'Inserted Kadence canonical markup (editor-valid). Patterns ship with placeholder copy — edit the headings/text (RichText fields) to your content.', 'emcp-lite' ),
		);
	}

	// ── helpers ────────────────────────────────────────────────────────────────

	/**
	 * The block's RichText/HTML-source attribute names (fields whose value lives
	 * in the saved HTML), read from the live registry.
	 *
	 * @param string $name Full block name.
	 * @return string[]
	 */
	private function source_fields( string $name ): array {
		$bt = EMCP_Tools_Kadence_Blocks_Catalog::registered( $name );
		if ( null === $bt || empty( $bt->attributes ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $bt->attributes as $key => $def ) {
			if ( is_array( $def ) && in_array( ( $def['source'] ?? '' ), array( 'html', 'rich-text' ), true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * @param string $name Block name (with or without the kadence/ prefix).
	 * @return string
	 */
	private function normalize( string $name ): string {
		return ( false === strpos( $name, '/' ) ) ? 'kadence/' . $name : $name;
	}

	/**
	 * Build the get-block-schema entry for one block.
	 *
	 * @param string $name Full block name.
	 * @param bool   $full Include the raw registered attributes.
	 * @return array
	 */
	private function schema_for( string $name, bool $full ): array {
		if ( ! EMCP_Tools_Kadence_Blocks_Catalog::is_known( $name ) ) {
			return array( 'name' => $name, 'error' => 'unknown_block' );
		}
		$slug  = substr( $name, 8 );
		$title = EMCP_Tools_Kadence_Blocks_Catalog::TITLES[ $slug ]['title'] ?? $name;
		$desc  = EMCP_Tools_Kadence_Blocks_Catalog::TITLES[ $slug ]['desc'] ?? '';
		$entry = array(
			'name'        => $name,
			'title'       => $title,
			'description' => $desc,
			'category'    => EMCP_Tools_Kadence_Blocks_Catalog::CATEGORY[ $slug ] ?? 'content',
			'doc'         => EMCP_Tools_Kadence_Blocks_Catalog::doc_url( $name ),
		);

		$real      = EMCP_Tools_Kadence_Blocks_Catalog::real_attributes( $name );
		$highlight = EMCP_Tools_Kadence_Blocks_Catalog::highlight( $name );
		$keys      = ! empty( $highlight )
			? array_values( array_intersect( $highlight, array_keys( $real ) ) )
			: array_slice( array_keys( $real ), 0, EMCP_Tools_Kadence_Blocks_Catalog::DEFAULT_CAP );

		$params = array();
		foreach ( $keys as $k ) {
			$params[] = array( 'name' => $k, 'default' => $real[ $k ] );
		}
		$entry['attributes']       = $params;
		$entry['total_attributes'] = count( $real );
		if ( empty( $highlight ) && count( $real ) > count( $keys ) ) {
			$entry['note'] = sprintf(
				/* translators: 1: shown count, 2: total count. */
				__( 'Showing the first %1$d of %2$d attributes; pass full:true for all.', 'emcp-lite' ),
				count( $keys ),
				count( $real )
			);
		}

		// Structural hints (container children, RichText content, editor-only caveats).
		$structure = EMCP_Tools_Kadence_Blocks_Catalog::structure( $name );
		if ( ! empty( $structure['inner'] ) ) {
			$entry['inner_blocks'] = sprintf(
				/* translators: 1: child block name. */
				__( 'Container: add-block scaffolds %1$s children automatically.', 'emcp-lite' ),
				$structure['inner']['child']
			);
		}
		$source_fields = $this->source_fields( $name );
		if ( ! empty( $source_fields ) ) {
			$entry['content_attributes'] = $source_fields;
			$entry['content_note']       = __( 'These are RichText/HTML fields — pass them as attributes and add-block renders them into the saved block markup (they are read back from the HTML, not the attrs JSON).', 'emcp-lite' );
		}
		if ( ! empty( $structure['note'] ) ) {
			$entry['structure_note'] = $structure['note'];
		}

		$entry['example']     = $this->example_markup( $name );
		$entry['editor_note'] = __( 'Kadence blocks use a static JS save(), so a headlessly-inserted block renders correctly on the front end but the block editor may flag it "Attempt recovery" (one click regenerates valid markup, content preserved).', 'emcp-lite' );

		if ( $full ) {
			$entry['full_attributes'] = $real;
		}
		return $entry;
	}

	/**
	 * Build a parsed-block array for a Kadence block: caller attributes + a
	 * generated uniqueID, plus any curated innerBlocks template and RichText
	 * content wrapper.
	 *
	 * @param string $name  Full block name.
	 * @param array  $attrs Caller attributes.
	 * @return array Parsed block array.
	 */
	private function build_block( string $name, array $attrs ): array {
		$attrs['uniqueID'] = $attrs['uniqueID'] ?? EMCP_Tools_Id_Generator::generate();
		$structure         = EMCP_Tools_Kadence_Blocks_Catalog::structure( $name );

		// Container defaults the editor requires (e.g. rowlayout colLayout), only
		// when the caller didn't set them.
		if ( ! empty( $structure['container_attrs'] ) ) {
			foreach ( $structure['container_attrs'] as $ck => $cv ) {
				if ( ! array_key_exists( $ck, $attrs ) ) {
					$attrs[ $ck ] = $cv;
				}
			}
		}

		// Container inner-block template.
		$inner_blocks = array();
		if ( ! empty( $structure['inner'] ) ) {
			$tpl   = $structure['inner'];
			$count = (int) ( $tpl['default_count'] ?? 1 );
			if ( ! empty( $tpl['count_attr'] ) && isset( $attrs[ $tpl['count_attr'] ] ) && is_numeric( $attrs[ $tpl['count_attr'] ] ) ) {
				$count = max( 1, (int) $attrs[ $tpl['count_attr'] ] );
			}
			for ( $i = 0; $i < $count; $i++ ) {
				$inner_blocks[] = $this->build_block( (string) $tpl['child'], array() );
			}
		}

		// RichText/HTML-source attributes (heading text, info-box title/text, image
		// caption, ...) live in the SAVED HTML, not the attrs JSON. Render each
		// caller-provided source attribute into innerHTML using its real registry
		// selector, and drop it from the stored attributes (WordPress reads the
		// value back from the HTML). $attrs is mutated by reference.
		$inner_html = $this->render_source_fields( $name, $attrs );

		// Wrap the rendered fields in a neutral root carrying ONLY the block's
		// default generated class. Gutenberg's className support extracts any
		// non-default class on the markup's ROOT element into the `className`
		// attribute — with the title div as root, `kt-blocks-info-box-title`
		// became className, Kadence's save() re-rendered it on the root, the
		// title selector then matched the root and swallowed the whole block on
		// every parse (observed live: infobox never converged). The default
		// wp-block-* class is exempt from that extraction, so this root is inert;
		// the field selectors still match the children. Advanced Heading is
		// excluded: its rendered element IS the block root by design.
		if ( '' !== $inner_html && 'kadence/advancedheading' !== $name ) {
			$inner_html = '<div class="wp-block-' . str_replace( '/', '-', $name ) . '">' . $inner_html . '</div>';
		}

		return $this->block_array( $name, $attrs, $inner_blocks, $inner_html );
	}

	/**
	 * Render the saved HTML for every caller-provided attribute the block marks as
	 * a `source: html|rich-text` attribute, using the attribute's own registry
	 * selector so nothing is guessed. Each rendered attribute is unset from $attrs
	 * (source attributes are parsed from the HTML, not the JSON).
	 *
	 * @param string $name  Full block name.
	 * @param array  $attrs Attributes (with uniqueID); mutated by reference.
	 * @return string
	 */
	private function render_source_fields( string $name, array &$attrs ): string {
		$bt = EMCP_Tools_Kadence_Blocks_Catalog::registered( $name );
		if ( null === $bt || empty( $bt->attributes ) ) {
			return '';
		}
		$uid  = (string) ( $attrs['uniqueID'] ?? '' );
		$slug = substr( $name, 8 );
		$html = '';
		foreach ( (array) $bt->attributes as $key => $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$source = $def['source'] ?? '';
			if ( 'html' !== $source && 'rich-text' !== $source ) {
				continue;
			}
			if ( ! array_key_exists( $key, $attrs ) ) {
				continue; // only caller-provided fields.
			}
			$value            = (string) $attrs[ $key ];
			list( $tag, $cls ) = $this->selector_element( (string) ( $def['selector'] ?? '' ), $attrs, $slug, $uid );
			$html            .= sprintf(
				'<%1$s%2$s>%3$s</%1$s>',
				$tag,
				'' !== $cls ? ' class="' . esc_attr( $cls ) . '"' : '',
				wp_kses_post( $value )
			);
			unset( $attrs[ $key ] );
		}
		return $html;
	}

	/**
	 * Resolve a source attribute's selector into a { tag, class } to wrap its
	 * value. Advanced Heading is the one special case: its selector is a bare tag
	 * list, but Kadence keys the block CSS off a `.kt-adv-heading{uniqueID}` class,
	 * so the tag comes from htmlTag and the uniqueID class is added.
	 *
	 * @param string $selector The attribute's registry selector.
	 * @param array  $attrs    The block attributes (for htmlTag).
	 * @param string $slug     Block slug (kadence/<slug>).
	 * @param string $uid      The block uniqueID.
	 * @return array{0:string,1:string} [ tag, class ].
	 */
	private function selector_element( string $selector, array $attrs, string $slug, string $uid ): array {
		if ( 'advancedheading' === $slug ) {
			$tag = 'h2';
			if ( ! empty( $attrs['htmlTag'] ) && preg_match( '/^(h[1-6]|p|div|span)$/i', (string) $attrs['htmlTag'] ) ) {
				$tag = strtolower( (string) $attrs['htmlTag'] );
			}
			return array( $tag, trim( 'kt-adv-heading' . $uid . ' wp-block-kadence-advancedheading' ) );
		}
		$first = trim( explode( ',', $selector )[0] );
		if ( '' === $first ) {
			return array( 'div', '' );
		}
		if ( '.' === $first[0] ) {
			return array( 'div', substr( $first, 1 ) ); // class selector, e.g. ".kt-blocks-info-box-title".
		}
		if ( false !== strpos( $first, '.' ) ) {
			list( $tag, $cls ) = explode( '.', $first, 2 ); // tag.class, e.g. "p.wp-block-kadence-advancedheading".
			return array( '' !== $tag ? $tag : 'div', $cls );
		}
		return array( $first, '' ); // bare tag, e.g. "figcaption".
	}

	/**
	 * Assemble a serialize_block-compatible parsed block array.
	 *
	 * @param string $name         Block name.
	 * @param array  $attrs        Attributes.
	 * @param array  $inner_blocks Parsed inner blocks.
	 * @param string $inner_html   Optional inner HTML (RichText content blocks).
	 * @return array
	 */
	private function block_array( string $name, array $attrs, array $inner_blocks, string $inner_html = '' ): array {
		if ( ! empty( $inner_blocks ) ) {
			$inner_content = array();
			foreach ( $inner_blocks as $unused ) {
				$inner_content[] = null; // one null placeholder per child; serialize_block fills them.
			}
			return array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => $inner_blocks,
				'innerHTML'    => '',
				'innerContent' => $inner_content,
			);
		}
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $inner_html,
			'innerContent' => ( '' !== $inner_html ) ? array( $inner_html ) : array(),
		);
	}

	/**
	 * A generated example markup string for a block.
	 *
	 * @param string $name Block name.
	 * @return string
	 */
	private function example_markup( string $name ): string {
		$block = $this->build_block( $name, array() );
		return function_exists( 'serialize_block' ) ? serialize_block( $block ) : '';
	}
}
