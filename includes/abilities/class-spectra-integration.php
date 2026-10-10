<?php
/**
 * Spectra native block adapter, independent of Astra. The block-pack integration
 * exposes its operations in the dedicated Spectra tab and compatibility tools.
 *
 * `spectra-read`  : list-blocks (dynamic catalog), get-block-schema (curated params + example)
 * `spectra-write` : add-block (insert a Spectra block with curated defaults + block_id)
 *
 * Registers only when the Spectra plugin (Ultimate Addons for Gutenberg) is
 * active. Building reuses the Gutenberg block tree; this pack is the Spectra-aware
 * discover -> inspect -> act layer, mirroring the Elementor widget catalog.
 *
 * @package EMCP_Tools
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spectra block catalog + insertion integration.
 */
class EMCP_Tools_Spectra_Integration extends EMCP_Tools_Theme_Integration {

	protected function capabilities(): array {
		return array( 'supports_patterns' => false, 'supports_preview' => false, 'styles_model' => 'attributes' );
	}


	public function id(): string {
		return 'spectra';
	}

	public function label(): string {
		return __( 'Spectra Blocks', 'emcp-lite' );
	}

	public function is_available(): bool {
		return EMCP_Tools_Spectra_Catalog::is_active();
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
				'desc' => __( 'Compact catalog of the Spectra blocks available on this site (name, title, description, category, doc). Optional { category, search }. Step 1 of discover -> inspect -> act.', 'emcp-lite' ),
			),
			'get-block-schema' => array(
				'mode' => 'read',
				'run'  => array( $this, 'execute_get_block_schema' ),
				'perm' => array( $this, 'can_read' ),
				'desc' => __( 'A Spectra block\'s key attributes (real names + defaults from Spectra) + a ready-to-use example ({ name } or { names: [...] }). { full: true } returns the block\'s full attribute set.', 'emcp-lite' ),
			),
			'add-block'        => array(
				'mode' => 'write',
				'run'  => array( $this, 'execute_add_block' ),
				'perm' => array( $this, 'can_write' ),
				'desc' => __( 'Insert a Spectra block into a post ({ post_id, block, attributes?, position? }); Spectra applies its own defaults, a block_id is generated. position: { mode: append|prepend|before|after|inside, path?: [..] }.', 'emcp-lite' ),
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
		$blocks   = EMCP_Tools_Spectra_Catalog::blocks_index( $category, $search );
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
			$out[ $this->normalize( $name ) ] = $this->schema_for( $this->normalize( $name ), $full );
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
		if ( null === EMCP_Tools_Spectra_Catalog::block_meta( $name ) ) {
			/* translators: %s: the requested identifier or comma-separated identifiers. */
			return new WP_Error( 'unknown_block', sprintf( __( 'Unknown Spectra block: %s', 'emcp-lite' ), $name ), array( 'status' => 404 ) );
		}
		$attrs    = ( isset( $input['attributes'] ) && is_array( $input['attributes'] ) ) ? $input['attributes'] : array();
		$position = ( isset( $input['position'] ) && is_array( $input['position'] ) ) ? $input['position'] : array( 'mode' => 'append' );

		$block = $this->build_block( $name, $attrs );
		$tree  = EMCP_Tools_Block_Tree::from_markup( (string) $post->post_content );
		// An unresolvable path makes insert() a silent no-op, which would report
		// success while dropping the block. Fail loudly instead.
		if ( ! EMCP_Tools_Block_Tree::position_is_valid( $tree, $position ) ) {
			return new WP_Error( 'invalid_position', __( 'position.path does not resolve to a block in this post. List the blocks in this post first and use a real index path.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$tree  = EMCP_Tools_Block_Tree::insert( $tree, array( $block ), $position );
		$markup = EMCP_Tools_Block_Tree::to_markup( $tree );

		$result = wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $markup ) ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'added'    => $name,
			'block_id' => $block['attrs']['block_id'],
			'post_id'  => $post_id,
		);
	}

	// ── helpers ────────────────────────────────────────────────────────────────

	private function normalize( string $name ): string {
		return ( false === strpos( $name, '/' ) ) ? 'uagb/' . $name : $name;
	}

	/**
	 * Build the get-block-schema entry for one block.
	 *
	 * @param string $name Full block name.
	 * @param bool   $full Include raw registered attributes.
	 * @return array
	 */
	private function schema_for( string $name, bool $full ): array {
		$meta = EMCP_Tools_Spectra_Catalog::block_meta( $name );
		if ( null === $meta ) {
			return array( 'name' => $name, 'error' => 'unknown_block' );
		}
		$cats  = isset( $meta['admin_categories'] ) ? (array) $meta['admin_categories'] : array();
		$entry = array(
			'name'        => $name,
			'title'       => (string) ( $meta['title'] ?? $name ),
			'description' => (string) ( $meta['description'] ?? '' ),
			'category'    => $cats[0] ?? '',
			'doc'         => EMCP_Tools_Spectra_Catalog::doc_url( $name ),
		);

		$real = EMCP_Tools_Spectra_Catalog::real_attributes( $name );
		if ( ! empty( $real ) ) {
			// Real attributes from Spectra's own attributes.php (names + defaults).
			$highlight = EMCP_Tools_Spectra_Catalog::highlight( $name );
			$keys      = ! empty( $highlight )
				? array_values( array_intersect( $highlight, array_keys( $real ) ) )
				: array_slice( array_keys( $real ), 0, EMCP_Tools_Spectra_Catalog::DEFAULT_CAP );
			$params = array();
			foreach ( $keys as $k ) {
				$params[] = array( 'name' => $k, 'default' => $real[ $k ] );
			}
			$entry['attributes']       = $params;
			$entry['total_attributes'] = count( $real );
			if ( empty( $highlight ) && count( $real ) > count( $keys ) ) {
				$entry['note'] = sprintf(
					/* translators: 1: shown count, 2: total count. */
					__( 'Showing the first %1$d of %2$d attributes; pass full:true for all. The heading/body text of static blocks is RichText content, not an attribute.', 'emcp-lite' ),
					count( $keys ),
					count( $real )
				);
			}
			$entry['example'] = $this->example_markup( $name );
			$entry['dynamic'] = ! empty( EMCP_Tools_Spectra_Catalog::structure( $name )['dynamic'] ) || ! empty( $meta['dynamic_assets'] );
		} else {
			$reg = $this->registered_block( $name );
			if ( $reg && ! empty( $reg->attributes ) ) {
				$entry['registered_attributes'] = array_keys( (array) $reg->attributes );
				$entry['note']                  = __( 'Attributes read from the block registry. Use full:true for the raw schema, or see the doc link.', 'emcp-lite' );
			} else {
				$entry['note'] = __( 'Attributes are not available server-side for this block. See the doc link for its settings.', 'emcp-lite' );
			}
		}

		// Native attributes registered via a shared helper (background image/video,
		// border radius, box shadow) are NOT in attributes.php, so surface them here
		// so agents use them instead of a core/html workaround.
		$shared = EMCP_Tools_Spectra_Catalog::shared_attributes( $name );
		if ( ! empty( $shared ) ) {
			$entry['shared_attributes'] = $shared;
			$entry['schema_note']       = __( 'Some native attributes (background image/video, border radius, box shadow) are registered via a shared helper and are NOT in the attribute list above, see shared_attributes. Prefer these native container attributes over a core/html workaround; they are editable in Spectra and survive editor saves.', 'emcp-lite' );
		}

		if ( $full ) {
			$entry['full_attributes'] = ! empty( $real ) ? $real : ( ( $reg = $this->registered_block( $name ) ) ? (array) $reg->attributes : null );
		}
		return $entry;
	}

	/**
	 * @param string $name Block name.
	 * @return object|null
	 */
	private function registered_block( string $name ) {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return null;
		}
		return WP_Block_Type_Registry::get_instance()->get_registered( $name );
	}

	/**
	 * Build a parsed-block array for a Spectra block: curated defaults + caller
	 * attributes + a generated block_id, plus any curated innerBlocks template.
	 *
	 * @param string $name  Full block name.
	 * @param array  $attrs Caller attributes.
	 * @return array Parsed block array.
	 */
	private function build_block( string $name, array $attrs ): array {
		// Caller attributes + a generated block_id. Spectra applies its own
		// defaults from the block's attributes.php, so the stored block stays
		// minimal (only the caller's overrides + block_id).
		$merged = array_merge( $attrs, array( 'block_id' => EMCP_Tools_Id_Generator::generate() ) );

		$inner_blocks = array();
		$structure    = EMCP_Tools_Spectra_Catalog::structure( $name );
		if ( ! empty( $structure['inner'] ) ) {
			foreach ( $structure['inner'] as $child ) {
				$inner_blocks[] = $this->build_block( (string) $child['name'], isset( $child['attrs'] ) ? (array) $child['attrs'] : array() );
			}
		}
		return $this->block_array( $name, $merged, $inner_blocks );
	}

	/**
	 * Assemble a serialize_block-compatible parsed block array.
	 *
	 * @param string $name         Block name.
	 * @param array  $attrs        Attributes.
	 * @param array  $inner_blocks Parsed inner blocks.
	 * @return array
	 */
	private function block_array( string $name, array $attrs, array $inner_blocks ): array {
		if ( empty( $inner_blocks ) ) {
			return array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			);
		}
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

	/**
	 * A generated example markup string for a curated block.
	 *
	 * @param string $name Block name.
	 * @return string
	 */
	private function example_markup( string $name ): string {
		$block = $this->build_block( $name, array() );
		return function_exists( 'serialize_block' ) ? serialize_block( $block ) : '';
	}
}
