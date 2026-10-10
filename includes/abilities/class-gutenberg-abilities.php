<?php
/**
 * Gutenberg block MCP abilities.
 *
 * Ten tools to discover WordPress blocks/patterns and build/edit a post's block
 * tree incrementally with raw block markup + index-path addressing. Pure WP core
 * (no Elementor); operates on any post's post_content via EMCP_Tools_Block_Tree.
 *
 * @package EMCP_Tools
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.1.0
 */
require_once dirname( __DIR__ ) . '/class-lite-edition.php';

class EMCP_Tools_Gutenberg_Abilities {

	/** @var string[] */
	private $ability_names = array();

	/** @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Register all ten abilities.
	 *
	 * @since 3.1.0
	 */
	public function register(): void {
		$this->register_list_blocks();
		$this->register_get_block_schema();
		$this->register_get_post_blocks();
		$this->register_list_patterns();
		$this->register_add_block();
		$this->register_update_block();
		$this->register_remove_block();
		$this->register_move_block();
		$this->register_duplicate_block();
		$this->register_insert_pattern();
	}

	// ---- permissions -------------------------------------------------------

	/**
	 * @param array|null $input
	 * @return bool
	 */
	public function check_read_permission( $input = null ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$post_id = absint( $input['post_id'] ?? 0 );
		return ! $post_id || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * @param array|null $input
	 * @return bool
	 */
	public function check_write_permission( $input = null ): bool {
		$post_id = absint( $input['post_id'] ?? 0 );
		return $post_id && current_user_can( 'edit_post', $post_id );
	}

	// ---- shared position schema fragment -----------------------------------

	/** @return array */
	private function position_schema(): array {
		return array(
			'type'        => 'object',
			'description' => __( 'Where to place the block(s). mode: append (top end) | prepend (top start) | before | after | inside. before/after/inside need "path".', 'emcp-lite' ),
			'properties'  => array(
				'mode' => array( 'type' => 'string', 'enum' => array( 'append', 'prepend', 'before', 'after', 'inside' ) ),
				'path' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			),
		);
	}

	// ---- list-blocks -------------------------------------------------------

	private function register_list_blocks(): void {
		$this->ability_names[] = 'emcp-tools/list-blocks';
		emcp_tools_register_ability(
			'emcp-tools/list-blocks',
			array(
				'label'               => __( 'List Blocks', 'emcp-lite' ),
				'description'         => __( 'Lists registered Gutenberg block types (name, title, category). Filter by category or search. Step 1 of build a block page: list-blocks -> get-block-schema -> add-block.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_list_blocks' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'category' => array( 'type' => 'string', 'description' => __( 'Only blocks in this category (e.g. text, media, design).', 'emcp-lite' ) ),
						'search'   => array( 'type' => 'string', 'description' => __( 'Substring match on block name or title.', 'emcp-lite' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'blocks' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_blocks( $input ): array|\WP_Error {
		$category = isset( $input['category'] ) ? (string) $input['category'] : '';
		$search   = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
		$rows     = array();
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			$cat = isset( $type->category ) ? (string) $type->category : '';
			if ( '' !== $category && $cat !== $category ) {
				continue;
			}
			$title = isset( $type->title ) && '' !== (string) $type->title ? (string) $type->title : (string) $name;
			if ( '' !== $search && false === strpos( strtolower( $name . ' ' . $title ), $search ) ) {
				continue;
			}
			$rows[] = array( 'name' => (string) $name, 'title' => $title, 'category' => $cat );
		}
		return array( 'blocks' => $rows );
	}

	// ---- get-block-schema --------------------------------------------------

	private function register_get_block_schema(): void {
		$this->ability_names[] = 'emcp-tools/get-block-schema';
		emcp_tools_register_ability(
			'emcp-tools/get-block-schema',
			array(
				'label'               => __( 'Get Block Schema', 'emcp-lite' ),
				'description'         => __( 'Returns a block type\'s attributes, supports, and a minimal markup example. Pass "name" for one or "names" for a batch. Step 2 before add-block.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_get_block_schema' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'name'  => array( 'type' => 'string', 'description' => __( 'Block type name, e.g. core/heading.', 'emcp-lite' ) ),
						'names' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => __( 'Batch of block type names.', 'emcp-lite' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'blocks' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_get_block_schema( $input ): array|\WP_Error {
		$names = array();
		if ( ! empty( $input['names'] ) && is_array( $input['names'] ) ) {
			$names = array_map( 'strval', $input['names'] );
		} elseif ( ! empty( $input['name'] ) ) {
			$names = array( (string) $input['name'] );
		}
		$registry = WP_Block_Type_Registry::get_instance();
		$rows     = array();
		foreach ( $names as $name ) {
			$type = $registry->get_registered( $name );
			if ( ! $type ) {
				$rows[] = array( 'name' => $name, 'error' => __( 'Block type not registered.', 'emcp-lite' ) );
				continue;
			}
			$short  = ( 0 === strpos( $name, 'core/' ) ) ? substr( $name, 5 ) : $name;
			$rows[] = array(
				'name'       => (string) $name,
				'title'      => isset( $type->title ) ? (string) $type->title : (string) $name,
				'category'   => isset( $type->category ) ? (string) $type->category : '',
				'attributes' => is_array( $type->attributes ?? null ) ? $type->attributes : array(),
				'supports'   => is_array( $type->supports ?? null ) ? $type->supports : array(),
				'example'    => sprintf( '<!-- wp:%1$s -->…<!-- /wp:%1$s -->', $short ),
			);
		}
		return array( 'blocks' => $rows );
	}

	// ---- get-post-blocks ---------------------------------------------------

	private function register_get_post_blocks(): void {
		$this->ability_names[] = 'emcp-tools/get-post-blocks';
		emcp_tools_register_ability(
			'emcp-tools/get-post-blocks',
			array(
				'label'               => __( 'Get Post Blocks', 'emcp-lite' ),
				'description'         => __( 'Returns a post\'s parsed block tree with an index PATH per block (e.g. [2,1]). Call this immediately before update-block/remove-block/move-block to get current paths.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_get_post_blocks' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'emcp-lite' ) ),
						'depth'   => array( 'type' => 'integer', 'description' => __( 'Max nesting depth to return. Omit for full tree.', 'emcp-lite' ) ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'blocks' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_get_post_blocks( $input ) {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'Post not found.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$depth  = isset( $input['depth'] ) ? max( 1, (int) $input['depth'] ) : null;
		$tree   = EMCP_Tools_Block_Tree::from_markup( (string) $post->post_content );
		return array( 'blocks' => EMCP_Tools_Block_Tree::summarize( $tree, $depth ) );
	}

	// ---- list-patterns -----------------------------------------------------

	private function register_list_patterns(): void {
		$this->ability_names[] = 'emcp-tools/list-patterns';
		emcp_tools_register_ability(
			'emcp-tools/list-patterns',
			array(
				'label'               => __( 'List Patterns', 'emcp-lite' ),
				'description'         => __( 'Lists registered block patterns (prebuilt block compositions), name, title, categories, description. Use insert-pattern to drop one into a post.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_list_patterns' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string', 'description' => __( 'Substring match on pattern name or title.', 'emcp-lite' ) ),
						'category' => array( 'type' => 'string', 'description' => __( 'Only patterns in this category.', 'emcp-lite' ) ),
					),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'patterns' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_list_patterns( $input ): array|\WP_Error {
		$search   = isset( $input['search'] ) ? strtolower( (string) $input['search'] ) : '';
		$category = isset( $input['category'] ) ? (string) $input['category'] : '';
		$rows     = array();
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) {
			$name  = (string) ( $p['name'] ?? '' );
			$title = (string) ( $p['title'] ?? $name );
			$cats  = array_map( 'strval', (array) ( $p['categories'] ?? array() ) );
			if ( '' !== $category && ! in_array( $category, $cats, true ) ) {
				continue;
			}
			if ( '' !== $search && false === strpos( strtolower( $name . ' ' . $title ), $search ) ) {
				continue;
			}
			$rows[] = array(
				'name'        => $name,
				'title'       => $title,
				'categories'  => $cats,
				'description' => (string) ( $p['description'] ?? '' ),
			);
		}
		return array( 'patterns' => $rows );
	}

	// ---- write helpers -----------------------------------------------------

	/**
	 * Load a post for a write op, or return a WordPress error.
	 *
	 * @param array $input
	 * @return array{0: object|null, 1: \WP_Error|null} [post, errorResult]
	 */
	private function load_for_write( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return array( null, new \WP_Error( 'emcp_invalid_block_request', __( 'Post not found.', 'emcp-lite' ), array( 'status' => 400 ) ) );
		}
		return array( $post, null );
	}

	/**
	 * Persist a mutated tree back to the post.
	 *
	 * @param object $post
	 * @param array  $tree
	 * @return \WP_Error|null
	 */
	private function save_tree( $post, array $tree ): ?\WP_Error {
		$allowed = EMCP_Lite_Edition::validate_write( $tree );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$before_content = (string) $post->post_content;
		// wp_update_post() runs wp_unslash() on the data, and block serialization
		// emits backslash escapes (&, \", \\ …) in attributes — so the markup
		// MUST be slashed here or those escapes get stripped and the block corrupts.
		$saved = wp_update_post(
			array(
				'ID'           => (int) $post->ID,
				'post_content' => wp_slash( EMCP_Tools_Block_Tree::to_markup( $tree ) ),
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		// Record the block edit so it can be rolled back (post_content only).
		if ( class_exists( 'EMCP_Tools_Change_Recorder' ) ) {
			$post_id = (int) $post->ID;
			EMCP_Tools_Change_Recorder::record_post_fields(
				$post_id,
				array( 'fields' => array( 'post_content' => $before_content ), 'meta' => array(), 'terms' => array() ),
				sprintf( 'Edited blocks on post #%d', $post_id ),
				trim( (string) get_the_title( $post_id ) . ' (#' . $post_id . ')' ),
				'gutenberg',
				'block-write'
			);
		}
		return null;
	}

	/**
	 * Normalize the position input to a clean { mode, path } array.
	 *
	 * @param array $input
	 * @return array
	 */
	private function position_from_input( array $input ): array {
		$pos  = isset( $input['position'] ) && is_array( $input['position'] ) ? $input['position'] : array();
		$mode = isset( $pos['mode'] ) ? (string) $pos['mode'] : 'append';
		$path = isset( $pos['path'] ) && is_array( $pos['path'] ) ? array_map( 'intval', $pos['path'] ) : array();
		return array( 'mode' => $mode, 'path' => $path );
	}

	// ---- add-block ---------------------------------------------------------

	private function register_add_block(): void {
		$this->ability_names[] = 'emcp-tools/add-block';
		emcp_tools_register_ability(
			'emcp-tools/add-block',
			array(
				'label'               => __( 'Add Block', 'emcp-lite' ),
				'description'         => __( 'Inserts raw Gutenberg block markup into a post at a position. markup may contain one or more blocks. position.mode = append|prepend|before|after|inside (before/after/inside need position.path from get-post-blocks).', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_add_block' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'emcp-lite' ) ),
						'markup'   => array( 'type' => 'string', 'description' => __( 'Gutenberg block markup, e.g. <!-- wp:heading --><h2>Hi</h2><!-- /wp:heading -->.', 'emcp-lite' ) ),
						'bindings' => array( 'type' => 'object', 'description' => __( 'Bind attributes to live data: { attribute: { source, args } }. Call list-dynamic-sources for the keys. Applies to the first block in markup.', 'emcp-lite' ) ),
						'position' => $this->position_schema(),
					),
					'required'   => array( 'post_id', 'markup' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'added' => array( 'type' => 'integer' ), 'path' => array( 'type' => 'array' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	/**
	 * Merge a friendly binding map into block attributes.
	 *
	 * @since 3.13.0
	 * @param array $attributes Block attributes.
	 * @param array $bindings   { attribute: { source, args } }.
	 * @return array|\WP_Error
	 */
	public static function apply_bindings( array $attributes, array $bindings ) {
		if ( ! $bindings ) {
			return $attributes;
		}
		if ( ! class_exists( 'EMCP_Tools_Themer_Dynamic_Compiler' ) ) {
			return new \WP_Error( 'dynamic_unavailable', __( 'Dynamic sources are not available on this site.', 'emcp-lite' ) );
		}
		$compiled = EMCP_Tools_Themer_Dynamic_Compiler::for_blocks( $bindings );
		if ( is_wp_error( $compiled ) ) {
			return $compiled;
		}
		if ( ! isset( $attributes['metadata'] ) || ! is_array( $attributes['metadata'] ) ) {
			$attributes['metadata'] = array();
		}
		$attributes['metadata']['bindings'] = $compiled;
		return $attributes;
	}

	/**
	 * Apply a binding map to the first parsed block, in place.
	 *
	 * The block tools take raw markup, so bindings are merged into the parsed
	 * attributes before the tree is serialized again.
	 *
	 * @param array $blocks   Parsed blocks, by reference.
	 * @param array $bindings { attribute: { source, args } }.
	 * @return \WP_Error|null Error payload, or null on success.
	 */
	private function bind_first_block( array &$blocks, array $bindings ): ?\WP_Error {
		if ( ! $bindings || ! $blocks ) {
			return null;
		}
		$attrs = self::apply_bindings( (array) ( $blocks[0]['attrs'] ?? array() ), $bindings );
		if ( is_wp_error( $attrs ) ) {
			return $attrs;
		}
		$blocks[0]['attrs'] = $attrs;
		return null;
	}

	public function execute_add_block( $input ): array|\WP_Error {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$new = EMCP_Tools_Block_Tree::strip_separators( parse_blocks( (string) ( $input['markup'] ?? '' ) ) );
		if ( ! $new ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'markup did not parse to any block.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$bind_err = $this->bind_first_block( $new, is_array( $input['bindings'] ?? null ) ? $input['bindings'] : array() );
		if ( null !== $bind_err ) {
			return $bind_err;
		}		$position = $this->position_from_input( $input );
		$tree     = EMCP_Tools_Block_Tree::from_markup( (string) $post->post_content );
		if ( in_array( $position['mode'], array( 'before', 'after', 'inside' ), true ) && null === EMCP_Tools_Block_Tree::at( $tree, $position['path'] ) ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'position.path does not resolve to a block. Call get-post-blocks first.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$tree = EMCP_Tools_Block_Tree::insert( $tree, $new, $position );
		$saved = $this->save_tree( $post, $tree );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return array( 'post_id' => (int) $post->ID, 'added' => count( $new ), 'path' => $position['path'] );
	}

	// ---- update-block ------------------------------------------------------

	private function register_update_block(): void {
		$this->ability_names[] = 'emcp-tools/update-block';
		emcp_tools_register_ability(
			'emcp-tools/update-block',
			array(
				'label'               => __( 'Update Block', 'emcp-lite' ),
				'description'         => __( 'Replaces the block at an index path with new block markup. Get the path from get-post-blocks first.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_update_block' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'emcp-lite' ) ),
						'path'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Index path of the block to replace, e.g. [2,1].', 'emcp-lite' ) ),
						'markup'  => array( 'type' => 'string', 'description' => __( 'Replacement block markup.', 'emcp-lite' ) ),
						'bindings' => array( 'type' => 'object', 'description' => __( 'Bind attributes to live data: { attribute: { source, args } }. Call list-dynamic-sources for the keys. Applies to the first block in markup.', 'emcp-lite' ) ),
					),
					'required'   => array( 'post_id', 'path', 'markup' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'path' => array( 'type' => 'array' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_update_block( $input ): array|\WP_Error {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$path = isset( $input['path'] ) && is_array( $input['path'] ) ? array_map( 'intval', $input['path'] ) : array();
		$tree = EMCP_Tools_Block_Tree::from_markup( (string) $post->post_content );
		if ( ! $path || null === EMCP_Tools_Block_Tree::at( $tree, $path ) ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'path does not resolve to a block. Call get-post-blocks first.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$new = EMCP_Tools_Block_Tree::strip_separators( parse_blocks( (string) ( $input['markup'] ?? '' ) ) );
		if ( ! $new ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'markup did not parse to any block.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$bind_err = $this->bind_first_block( $new, is_array( $input['bindings'] ?? null ) ? $input['bindings'] : array() );
		if ( null !== $bind_err ) {
			return $bind_err;
		}		$tree = EMCP_Tools_Block_Tree::replace( $tree, $path, $new );
		$saved = $this->save_tree( $post, $tree );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return array( 'post_id' => (int) $post->ID, 'path' => $path );
	}

	// ---- remove-block ------------------------------------------------------

	private function register_remove_block(): void {
		$this->ability_names[] = 'emcp-tools/remove-block';
		emcp_tools_register_ability(
			'emcp-tools/remove-block',
			array(
				'label'               => __( 'Remove Block', 'emcp-lite' ),
				'description'         => __( 'Deletes the block at an index path (and its inner blocks). Destructive. Get the path from get-post-blocks first.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_remove_block' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'emcp-lite' ) ),
						'path'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Index path of the block to remove.', 'emcp-lite' ) ),
					),
					'required'   => array( 'post_id', 'path' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'removed' => array( 'type' => 'boolean' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_remove_block( $input ): array|\WP_Error {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$path = isset( $input['path'] ) && is_array( $input['path'] ) ? array_map( 'intval', $input['path'] ) : array();
		$tree = EMCP_Tools_Block_Tree::from_markup( (string) $post->post_content );
		if ( ! $path || null === EMCP_Tools_Block_Tree::at( $tree, $path ) ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'path does not resolve to a block. Call get-post-blocks first.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$tree = EMCP_Tools_Block_Tree::remove( $tree, $path );
		$saved = $this->save_tree( $post, $tree );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return array( 'post_id' => (int) $post->ID, 'removed' => true );
	}

	// ---- move-block --------------------------------------------------------

	private function register_move_block(): void {
		$this->ability_names[] = 'emcp-tools/move-block';
		emcp_tools_register_ability(
			'emcp-tools/move-block',
			array(
				'label'               => __( 'Move Block', 'emcp-lite' ),
				'description'         => __( 'Moves the block at "path" to a target position ({mode,path}). Get paths from get-post-blocks first.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_move_block' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'emcp-lite' ) ),
						'path'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Index path of the block to move.', 'emcp-lite' ) ),
						'position' => $this->position_schema(),
					),
					'required'   => array( 'post_id', 'path', 'position' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'path' => array( 'type' => 'array' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_move_block( $input ): array|\WP_Error {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$from = isset( $input['path'] ) && is_array( $input['path'] ) ? array_map( 'intval', $input['path'] ) : array();
		$tree = EMCP_Tools_Block_Tree::from_markup( (string) $post->post_content );
		if ( ! $from || null === EMCP_Tools_Block_Tree::at( $tree, $from ) ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'path does not resolve to a block. Call get-post-blocks first.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$position = $this->position_from_input( $input );
		if ( in_array( $position['mode'], array( 'before', 'after', 'inside' ), true ) && null === EMCP_Tools_Block_Tree::at( $tree, $position['path'] ) ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'target position.path does not resolve to a block.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$tree = EMCP_Tools_Block_Tree::move( $tree, $from, $position );
		$saved = $this->save_tree( $post, $tree );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return array( 'post_id' => (int) $post->ID, 'path' => $position['path'] );
	}

	// ---- duplicate-block ---------------------------------------------------

	private function register_duplicate_block(): void {
		$this->ability_names[] = 'emcp-tools/duplicate-block';
		emcp_tools_register_ability(
			'emcp-tools/duplicate-block',
			array(
				'label'               => __( 'Duplicate Block', 'emcp-lite' ),
				'description'         => __( 'Clones the block at an index path and inserts the copy immediately after it. Get the path from get-post-blocks first.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_duplicate_block' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'emcp-lite' ) ),
						'path'    => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Index path of the block to duplicate.', 'emcp-lite' ) ),
					),
					'required'   => array( 'post_id', 'path' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'path' => array( 'type' => 'array' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_duplicate_block( $input ): array|\WP_Error {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$path = isset( $input['path'] ) && is_array( $input['path'] ) ? array_map( 'intval', $input['path'] ) : array();
		$tree = EMCP_Tools_Block_Tree::from_markup( (string) $post->post_content );
		if ( ! $path || null === EMCP_Tools_Block_Tree::at( $tree, $path ) ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'path does not resolve to a block. Call get-post-blocks first.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$tree = EMCP_Tools_Block_Tree::duplicate( $tree, $path );
		$saved = $this->save_tree( $post, $tree );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$copy_path                            = $path;
		$copy_path[ count( $copy_path ) - 1 ] = (int) end( $path ) + 1;
		return array( 'post_id' => (int) $post->ID, 'path' => $copy_path );
	}

	// ---- insert-pattern ----------------------------------------------------

	private function register_insert_pattern(): void {
		$this->ability_names[] = 'emcp-tools/insert-pattern';
		emcp_tools_register_ability(
			'emcp-tools/insert-pattern',
			array(
				'label'               => __( 'Insert Pattern', 'emcp-lite' ),
				'description'         => __( 'Inserts a registered block pattern (by name from list-patterns) into a post at a position.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_insert_pattern' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer', 'description' => __( 'Post ID.', 'emcp-lite' ) ),
						'pattern_name' => array( 'type' => 'string', 'description' => __( 'Pattern name from list-patterns, e.g. core/two-columns.', 'emcp-lite' ) ),
						'position'     => $this->position_schema(),
					),
					'required'   => array( 'post_id', 'pattern_name' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'added' => array( 'type' => 'integer' ), 'path' => array( 'type' => 'array' ) ) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_insert_pattern( $input ): array|\WP_Error {
		list( $post, $err ) = $this->load_for_write( $input );
		if ( $err ) {
			return $err;
		}
		$name    = (string) ( $input['pattern_name'] ?? '' );
		$pattern = $name ? WP_Block_Patterns_Registry::get_instance()->get_registered( $name ) : null;
		if ( ! $pattern || empty( $pattern['content'] ) ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'Pattern not registered. Use list-patterns for valid names.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$new = EMCP_Tools_Block_Tree::strip_separators( parse_blocks( (string) $pattern['content'] ) );
		if ( ! $new ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'Pattern produced no blocks.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$position = $this->position_from_input( $input );
		$tree     = EMCP_Tools_Block_Tree::from_markup( (string) $post->post_content );
		if ( in_array( $position['mode'], array( 'before', 'after', 'inside' ), true ) && null === EMCP_Tools_Block_Tree::at( $tree, $position['path'] ) ) {
			return new \WP_Error( 'emcp_invalid_block_request', __( 'position.path does not resolve to a block. Call get-post-blocks first.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$tree = EMCP_Tools_Block_Tree::insert( $tree, $new, $position );
		$saved = $this->save_tree( $post, $tree );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		return array( 'post_id' => (int) $post->ID, 'added' => count( $new ), 'path' => $position['path'] );
	}
}
