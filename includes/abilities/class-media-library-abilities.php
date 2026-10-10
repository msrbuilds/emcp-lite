<?php
/**
 * WordPress Media Library MCP ability for Elementor.
 *
 * Registers a single read-only tool, `list-media`, that lets an AI agent
 * discover and query images already uploaded to the WordPress Media Library.
 * This fills the gap left by the stock-image search tools: those find generic
 * stock photos, but can't surface a client's own photos (e.g. 300+
 * job-site images already in their library). Backed by a direct WP_Query on
 * attachments — no HTTP round-trip.
 *
 * @package EMCP_Tools
 * @since   2.0.2
 * @link    https://github.com/msrbuilds/elementor-mcp/issues/25
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the Media Library query ability.
 *
 * @since 2.0.2
 */
class EMCP_Tools_Media_Library_Abilities {

	/**
	 * The data access layer.
	 *
	 * @var EMCP_Tools_Data
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @since 2.0.2
	 *
	 * @param EMCP_Tools_Data $data The data access layer.
	 */
	public function __construct( EMCP_Tools_Data $data ) {
		$this->data = $data;
	}

	/**
	 * Returns the ability names registered by this class.
	 *
	 * @since 2.0.2
	 *
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return array(
			'emcp-tools/list-media',
			'emcp-tools/get-media',
			'emcp-tools/upload-media',
			'emcp-tools/update-media',
			'emcp-tools/delete-media',
		);
	}

	/**
	 * Registers the Media Library abilities.
	 *
	 * @since 2.0.2
	 */
	public function register(): void {
		$this->register_list_media();
		$this->register_get_media();
		$this->register_upload_media();
		$this->register_update_media();
		$this->register_delete_media();
	}

	/**
	 * Permission check for uploading a new attachment.
	 *
	 * Mirrors sideload-image: gated on `upload_files`.
	 *
	 * @since 3.12.1
	 *
	 * @return bool
	 */
	public function check_upload_permission(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Permission check for read-only library queries.
	 *
	 * Mirrors search-images: read access is gated on `edit_posts`.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	// -------------------------------------------------------------------------
	// list-media
	// -------------------------------------------------------------------------

	private function register_list_media(): void {
		emcp_tools_register_ability(
			'emcp-tools/list-media',
			array(
				'label'               => __( 'List Media', 'emcp-lite' ),
				'description'         => __( 'Lists and searches images already in the WordPress Media Library. Use this to find a site\'s own uploaded photos (e.g. a client\'s product or job-site images) before reaching for stock photos. The optional "search" matches the title, alt text, caption, and description. Returns attachment IDs and URLs you can pass straight to add-free-widget.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_list_media' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'search'    => array(
							'type'        => 'string',
							'description' => __( 'Keyword to match against the attachment title, alt text, caption, and description. Omit to list everything.', 'emcp-lite' ),
						),
						'mime_type' => array(
							'type'        => 'string',
							'description' => __( 'MIME type filter. Accepts a top-level type ("image") or a specific type ("image/jpeg", "image/png"). Use "any" for all media types. Default: image.', 'emcp-lite' ),
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => __( 'Page number (1-based). Default: 1.', 'emcp-lite' ),
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => __( 'Results per page (1-100). Default: 20.', 'emcp-lite' ),
						),
						'orderby'   => array(
							'type'        => 'string',
							'enum'        => array( 'date', 'title' ),
							'description' => __( 'Sort field. Default: date (newest first).', 'emcp-lite' ),
						),
						'order'     => array(
							'type'        => 'string',
							'enum'        => array( 'desc', 'asc' ),
							'description' => __( 'Sort direction. Default: desc.', 'emcp-lite' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'result_count' => array( 'type' => 'integer' ),
						'page'         => array( 'type' => 'integer' ),
						'page_count'   => array( 'type' => 'integer' ),
						'total'        => array( 'type' => 'integer' ),
						'results'      => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'            => array( 'type' => 'integer' ),
									'title'         => array( 'type' => 'string' ),
									'url'           => array( 'type' => 'string' ),
									'thumbnail_url' => array( 'type' => 'string' ),
									'alt'           => array( 'type' => 'string' ),
									'mime_type'     => array( 'type' => 'string' ),
									'width'         => array( 'type' => 'integer' ),
									'height'        => array( 'type' => 'integer' ),
									'filesize'      => array( 'type' => 'integer' ),
									'date'          => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the list-media ability.
	 *
	 * @since 2.0.2
	 *
	 * @param array $input The input parameters.
	 * @return array|\WP_Error
	 */
	public function execute_list_media( $input ) {
		$search   = sanitize_text_field( $input['search'] ?? '' );
		$mime     = sanitize_text_field( $input['mime_type'] ?? 'image' );
		$page     = max( 1, absint( $input['page'] ?? 1 ) );
		$per_page = absint( $input['per_page'] ?? 20 );
		$per_page = max( 1, min( 100, $per_page ) );

		$orderby = ( isset( $input['orderby'] ) && 'title' === $input['orderby'] ) ? 'title' : 'date';
		$order   = ( isset( $input['order'] ) && 'asc' === strtolower( (string) $input['order'] ) ) ? 'ASC' : 'DESC';

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => $order,
		);

		// Default to images; allow a specific MIME or "any" to widen.
		if ( '' !== $mime && 'any' !== strtolower( $mime ) && '*' !== $mime ) {
			$args['post_mime_type'] = $mime;
		}

		// Keyword search. WP_Query's `s` covers the title, caption (excerpt) and
		// description (content) but NOT the alt text, which lives in postmeta.
		// So we resolve the matching attachment IDs from both sources up front
		// (lightweight id-only queries) and feed the union into the paginated
		// query via post__in — no global query filters, nothing to leak.
		if ( '' !== $search ) {
			$id_args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			);
			if ( isset( $args['post_mime_type'] ) ) {
				$id_args['post_mime_type'] = $args['post_mime_type'];
			}

			$text_ids = get_posts( array_merge( $id_args, array( 's' => $search ) ) );
			$alt_ids  = get_posts(
				array_merge(
					$id_args,
					array(
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded to attachments; the alt-text postmeta has no dedicated column.
							array(
								'key'     => '_wp_attachment_image_alt',
								'value'   => $search,
								'compare' => 'LIKE',
							),
						),
					)
				)
			);

			$ids = array_values( array_unique( array_map( 'absint', array_merge( (array) $text_ids, (array) $alt_ids ) ) ) );

			if ( empty( $ids ) ) {
				return array(
					'result_count' => 0,
					'page'         => $page,
					'page_count'   => 0,
					'total'        => 0,
					'results'      => array(),
				);
			}

			$args['post__in'] = $ids;
		}

		$query   = new \WP_Query( $args );
		$results = array();
		foreach ( $query->posts as $attachment ) {
			$results[] = $this->format_attachment( $attachment );
		}

		return array(
			'result_count' => count( $results ),
			'page'         => $page,
			'page_count'   => (int) $query->max_num_pages,
			'total'        => (int) $query->found_posts,
			'results'      => $results,
		);
	}

	/**
	 * Edit permission for a specific attachment (attachments are posts).
	 *
	 * @since 3.0.0
	 * @param array|null $input Tool input; may carry an `id`.
	 * @return bool
	 */
	public function check_edit_permission( $input = null ): bool {
		$id = absint( $input['id'] ?? 0 );
		return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_posts' );
	}

	/**
	 * Delete permission for a specific attachment.
	 *
	 * @since 3.0.0
	 * @param array|null $input Tool input; may carry an `id`.
	 * @return bool
	 */
	public function check_delete_permission( $input = null ): bool {
		$id = absint( $input['id'] ?? 0 );
		return $id ? current_user_can( 'delete_post', $id ) : current_user_can( 'delete_posts' );
	}

	/**
	 * Resolve an id to an attachment post, or a WP_Error.
	 *
	 * @since 3.0.0
	 * @param mixed $raw
	 * @return object|\WP_Error WP_Post-like on success.
	 */
	private function resolve_attachment( $raw ) {
		$id = absint( $raw );
		if ( ! $id ) {
			return new \WP_Error( 'missing_params', __( 'An attachment "id" is required.', 'emcp-lite' ) );
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return new \WP_Error( 'attachment_not_found', __( 'Attachment not found.', 'emcp-lite' ) );
		}
		if ( 'attachment' !== ( $post->post_type ?? '' ) ) {
			return new \WP_Error( 'not_an_attachment', __( 'That ID is not a media attachment.', 'emcp-lite' ) );
		}
		return $post;
	}

	// -------------------------------------------------------------------------
	// get-media
	// -------------------------------------------------------------------------

	private function register_get_media(): void {
		emcp_tools_register_ability(
			'emcp-tools/get-media',
			array(
				'label'               => __( 'Get Media', 'emcp-lite' ),
				'description'         => __( 'Returns full detail for one Media Library attachment: title, URL, every registered image size (url + dimensions), mime type, filesize, alt text, caption, description, and raw attachment metadata. The single-item complement to list-media.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_get_media' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer', 'description' => __( 'Attachment ID.', 'emcp-lite' ) ) ),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ),
					'slug' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ),
					'mime_type' => array( 'type' => 'string' ), 'filesize' => array( 'type' => 'integer' ),
					'alt' => array( 'type' => 'string' ), 'caption' => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ), 'date' => array( 'type' => 'string' ),
					'author' => array( 'type' => 'object' ), 'post_parent' => array( 'type' => 'integer' ),
					'width' => array( 'type' => 'integer' ), 'height' => array( 'type' => 'integer' ),
					'sizes' => array( 'type' => 'object' ), 'metadata' => array( 'type' => 'object' ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_get_media( $input ) {
		$post = $this->resolve_attachment( $input['id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$id   = (int) $post->ID;
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();

		$sizes = array();
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( array_keys( $meta['sizes'] ) as $size ) {
				$src = wp_get_attachment_image_src( $id, $size );
				if ( is_array( $src ) ) {
					$sizes[ $size ] = array( 'url' => (string) $src[0], 'width' => (int) $src[1], 'height' => (int) $src[2] );
				}
			}
		}

		$author_id  = (int) ( $post->post_author ?? 0 );
		$author_obj = $author_id && function_exists( 'get_userdata' ) ? get_userdata( $author_id ) : null;

		$filesize = 0;
		if ( isset( $meta['filesize'] ) ) {
			$filesize = (int) $meta['filesize'];
		}

		return array(
			'id'          => $id,
			'title'       => (string) $post->post_title,
			'slug'        => (string) $post->post_name,
			'url'         => (string) wp_get_attachment_url( $id ),
			'mime_type'   => (string) ( $post->post_mime_type ?? '' ),
			'filesize'    => $filesize,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => (string) $post->post_excerpt,
			'description' => (string) $post->post_content,
			'date'        => (string) ( $post->post_date ?? '' ),
			'author'      => array( 'id' => $author_id, 'name' => $author_obj ? (string) $author_obj->display_name : '' ),
			'post_parent' => (int) ( $post->post_parent ?? 0 ),
			'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'sizes'       => $sizes,
			'metadata'    => $meta,
		);
	}

	// -------------------------------------------------------------------------
	// update-media
	// -------------------------------------------------------------------------

	private function register_update_media(): void {
		emcp_tools_register_ability(
			'emcp-tools/update-media',
			array(
				'label'               => __( 'Update Media', 'emcp-lite' ),
				'description'         => __( 'Updates an existing attachment\'s metadata: title, alt text, caption, and/or description. Only the fields you pass change. Great for fixing missing alt text (accessibility/SEO) on images already in the library.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_update_media' ),
				'permission_callback' => array( $this, 'check_edit_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array( 'type' => 'integer', 'description' => __( 'Attachment ID.', 'emcp-lite' ) ),
						'title'       => array( 'type' => 'string' ),
						'alt'         => array( 'type' => 'string', 'description' => __( 'Alt text (accessibility).', 'emcp-lite' ) ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					),
					'required'   => array( 'id' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'id' => array( 'type' => 'integer' ), 'updated' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'alt' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ),
					'caption' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_update_media( $input ) {
		$post = $this->resolve_attachment( $input['id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$id      = (int) $post->ID;
		$updated = array();

		// Capture the before-image of exactly what this update changes, for rollback.
		$emcp_before = null;
		if ( class_exists( 'EMCP_Tools_Change_Recorder' ) ) {
			$emcp_bf = array();
			if ( array_key_exists( 'title', $input ) )       { $emcp_bf['post_title'] = $post->post_title; }
			if ( array_key_exists( 'caption', $input ) )     { $emcp_bf['post_excerpt'] = $post->post_excerpt; }
			if ( array_key_exists( 'description', $input ) ) { $emcp_bf['post_content'] = $post->post_content; }
			$emcp_bm = array();
			if ( array_key_exists( 'alt', $input ) ) {
				$emcp_prior_alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
				$emcp_bm['_wp_attachment_image_alt'] = ( '' === $emcp_prior_alt ) ? '__DELETE__' : $emcp_prior_alt;
			}
			if ( $emcp_bf || $emcp_bm ) {
				$emcp_before = array( 'fields' => $emcp_bf, 'meta' => $emcp_bm, 'terms' => array() );
			}
		}

		$postarr = array( 'ID' => $id );
		if ( array_key_exists( 'title', $input ) ) {
			$postarr['post_title'] = sanitize_text_field( (string) $input['title'] );
			$updated[]             = 'title';
		}
		if ( array_key_exists( 'caption', $input ) ) {
			$postarr['post_excerpt'] = sanitize_text_field( (string) $input['caption'] );
			$updated[]               = 'caption';
		}
		if ( array_key_exists( 'description', $input ) ) {
			// Description maps to post_content, which allows HTML by design;
			// wp_update_post applies wp_filter_post_kses for users without
			// unfiltered_html. Do NOT sanitize_text_field this (it would strip
			// legitimate markup) — title/caption are plain-text, so they are.
			$postarr['post_content'] = (string) $input['description'];
			$updated[]               = 'description';
		}
		if ( count( $postarr ) > 1 ) {
			$res = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		if ( array_key_exists( 'alt', $input ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
			$updated[] = 'alt';
		}

		if ( null !== $emcp_before && ! empty( $updated ) ) {
			EMCP_Tools_Change_Recorder::record_post_fields(
				$id,
				$emcp_before,
				sprintf( 'Updated media #%d (%s)', $id, implode( ', ', $updated ) ),
				trim( (string) $post->post_title . ' (#' . $id . ')' ),
				'media',
				'update-media'
			);
		}

		$fresh = get_post( $id );
		return array(
			'id'          => $id,
			'updated'     => $updated,
			'alt'         => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'       => (string) ( $fresh->post_title ?? $post->post_title ),
			'caption'     => (string) ( $fresh->post_excerpt ?? $post->post_excerpt ),
			'description' => (string) ( $fresh->post_content ?? $post->post_content ),
		);
	}

	// -------------------------------------------------------------------------
	// delete-media
	// -------------------------------------------------------------------------

	private function register_delete_media(): void {
		emcp_tools_register_ability(
			'emcp-tools/delete-media',
			array(
				'label'               => __( 'Delete Media', 'emcp-lite' ),
				'description'         => __( 'Deletes a Media Library attachment. DESTRUCTIVE and effectively permanent, WordPress bypasses Trash for media unless MEDIA_TRASH is defined. Requires confirm:true. Pass force:true to skip Trash even when MEDIA_TRASH is on.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_delete_media' ),
				'permission_callback' => array( $this, 'check_delete_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer', 'description' => __( 'Attachment ID.', 'emcp-lite' ) ),
						'confirm' => array( 'type' => 'boolean', 'description' => __( 'Must be true to proceed (acknowledges permanent deletion).', 'emcp-lite' ) ),
						'force'   => array( 'type' => 'boolean', 'description' => __( 'Skip Trash even when MEDIA_TRASH is defined. Default: false.', 'emcp-lite' ) ),
					),
					'required'   => array( 'id', 'confirm' ),
				),
				'output_schema'       => array( 'type' => 'object', 'properties' => array(
					'success' => array( 'type' => 'boolean' ), 'id' => array( 'type' => 'integer' ),
					'deleted' => array( 'type' => 'string' ),
				) ),
				'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ), 'show_in_rest' => true ),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_delete_media( $input ) {
		$post = $this->resolve_attachment( $input['id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( true !== ( $input['confirm'] ?? null ) ) {
			return new \WP_Error( 'confirmation_required', __( 'Deleting media is permanent on most sites (WordPress bypasses Trash unless MEDIA_TRASH is defined). Pass confirm:true to proceed.', 'emcp-lite' ) );
		}
		$id      = (int) $post->ID;
		$force   = ! empty( $input['force'] );
		$trashed = ! $force && defined( 'MEDIA_TRASH' ) && MEDIA_TRASH;

		// Snapshot the attachment (post + meta + a trashed copy of every file)
		// BEFORE deleting, so the delete is reversible from the change ledger.
		$has_rec  = class_exists( 'EMCP_Tools_Change_Recorder' ) && ! EMCP_Tools_Change_Log::$suppress;
		$snapshot = ( $has_rec && ! $trashed ) ? EMCP_Tools_Change_Recorder::snapshot_attachment( $id ) : array();

		$res = wp_delete_attachment( $id, $force );

		if ( $has_rec && $res && ! empty( $snapshot ) ) {
			EMCP_Tools_Change_Recorder::record_attachment_delete(
				$snapshot,
				$id,
				sprintf( 'Deleted media #%d', $id ),
				trim( (string) $post->post_title . ' (#' . $id . ')' )
			);
		}
		return array(
			'success' => (bool) $res,
			'id'      => $id,
			'deleted' => $trashed ? 'trashed' : 'deleted',
		);
	}

	// -------------------------------------------------------------------------
	// upload-media
	// -------------------------------------------------------------------------

	/**
	 * The largest decoded file (bytes) upload-media will accept. Filterable so a
	 * host with more headroom can raise it; the base64 payload already arrived in
	 * the request, so this only guards the decode/write, not an HTTP upload limit.
	 *
	 * @since 3.12.1
	 */
	const UPLOAD_MAX_BYTES = 33554432; // 32 MB.

	private function register_upload_media(): void {
		emcp_tools_register_ability(
			'emcp-tools/upload-media',
			array(
				'label'               => __( 'Upload Media', 'emcp-lite' ),
				'description'         => __( 'Uploads a file from the CLIENT machine into the WordPress Media Library by passing its raw bytes as base64. This is the companion to sideload-image: sideload-image fetches a URL the SERVER can reach, while upload-media takes a LOCAL file the client reads and base64-encodes. Use this when the user asks to upload an image (or other allowed media) from their own computer. Pass `filename` (with its real extension, e.g. "logo.png") and `data` (base64, with or without a `data:` URI prefix). Optionally set alt/title/caption/description and attach it to a post. Only WordPress-allowed file types are accepted (executable types are refused). Returns the new attachment id and URLs, ready for add-free-widget.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_upload_media' ),
				'permission_callback' => array( $this, 'check_upload_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'filename'    => array(
							'type'        => 'string',
							'description' => __( 'The file name including its extension (e.g. "photo.jpg", "logo.png", "brochure.pdf"). The extension determines the file type, so it must be correct.', 'emcp-lite' ),
						),
						'data'        => array(
							'type'        => 'string',
							'description' => __( 'The file contents, base64-encoded. A `data:<mime>;base64,` prefix is accepted and stripped automatically.', 'emcp-lite' ),
						),
						'alt'         => array(
							'type'        => 'string',
							'description' => __( 'Alt text for the image (accessibility/SEO). Optional but recommended for images.', 'emcp-lite' ),
						),
						'title'       => array(
							'type'        => 'string',
							'description' => __( 'Attachment title. Defaults to the filename without its extension.', 'emcp-lite' ),
						),
						'caption'     => array(
							'type'        => 'string',
							'description' => __( 'Attachment caption. Optional.', 'emcp-lite' ),
						),
						'description' => array(
							'type'        => 'string',
							'description' => __( 'Attachment description. Optional.', 'emcp-lite' ),
						),
						'post_id'     => array(
							'type'        => 'integer',
							'description' => __( 'Optional post ID to attach the media to (sets post_parent). Omit to leave it unattached.', 'emcp-lite' ),
						),
						'convert_webp' => array(
							'type'        => 'boolean',
							'description' => __( 'Set false to skip WebP conversion/optimization for this upload (useful on slow shared hosting). Default true.', 'emcp-lite' ),
						),
					),
					'required'   => array( 'filename', 'data' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'        => array( 'type' => 'integer' ),
						'title'     => array( 'type' => 'string' ),
						'url'       => array( 'type' => 'string' ),
						'mime_type' => array( 'type' => 'string' ),
						'filesize'  => array( 'type' => 'integer' ),
						'alt'       => array( 'type' => 'string' ),
						'width'     => array( 'type' => 'integer' ),
						'height'    => array( 'type' => 'integer' ),
						'change_id' => array( 'type' => 'string' ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Executes the upload-media ability: decode base64 client bytes into a temp
	 * file and hand it to media_handle_sideload(), which enforces the allowed
	 * MIME allowlist, moves the file into uploads, inserts the attachment, and
	 * generates metadata (triggering the Image Optimization / SVG modules).
	 *
	 * @since 3.12.1
	 *
	 * @param array $input Tool input.
	 * @return array|\WP_Error
	 */
	public function execute_upload_media( $input ) {
		$filename = sanitize_file_name( (string) ( $input['filename'] ?? '' ) );
		$raw_data = (string) ( $input['data'] ?? '' );

		if ( '' === $filename ) {
			return new \WP_Error( 'missing_filename', __( 'The filename parameter is required (with a real extension, e.g. "photo.jpg").', 'emcp-lite' ) );
		}
		if ( ! preg_match( '/\.[A-Za-z0-9]{1,8}$/', $filename ) ) {
			return new \WP_Error( 'no_extension', __( 'The filename must include a file extension (e.g. "photo.jpg") so the file type can be determined.', 'emcp-lite' ) );
		}
		if ( '' === $raw_data ) {
			return new \WP_Error( 'missing_data', __( 'The data parameter (base64-encoded file contents) is required.', 'emcp-lite' ) );
		}

		// Strip an optional `data:<mime>;base64,` URI prefix and any whitespace/newlines.
		if ( 0 === strpos( $raw_data, 'data:' ) ) {
			$comma    = strpos( $raw_data, ',' );
			$raw_data = false !== $comma ? substr( $raw_data, $comma + 1 ) : $raw_data;
		}
		$raw_data = preg_replace( '/\s+/', '', $raw_data );

		$bytes = base64_decode( $raw_data, true );
		if ( false === $bytes || '' === $bytes ) {
			return new \WP_Error( 'bad_base64', __( 'The data parameter is not valid base64. Pass the file contents base64-encoded.', 'emcp-lite' ) );
		}

		$max = (int) apply_filters( 'emcp_tools_upload_media_max_bytes', self::UPLOAD_MAX_BYTES );
		if ( strlen( $bytes ) > $max ) {
			return new \WP_Error(
				'file_too_large',
				sprintf(
					/* translators: 1: file size, 2: limit */
					__( 'The decoded file is %1$s, which exceeds the %2$s upload-media limit. Raise it with the emcp_tools_upload_media_max_bytes filter, or use sideload-image with a URL.', 'emcp-lite' ),
					size_format( strlen( $bytes ) ),
					size_format( $max )
				)
			);
		}

		// Load required WordPress media functions.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Write the decoded bytes to a temp file named for the real filename so
		// the extension-based type check works.
		$tmp_file = wp_tempnam( $filename );
		if ( ! $tmp_file ) {
			return new \WP_Error( 'tmp_failed', __( 'Could not create a temporary file for the upload.', 'emcp-lite' ) );
		}
		if ( false === file_put_contents( $tmp_file, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $tmp_file );
			return new \WP_Error( 'write_failed', __( 'Could not write the uploaded file to disk.', 'emcp-lite' ) );
		}
		unset( $bytes );

		// Reject disallowed types up front with a clear message. media_handle_sideload
		// enforces this too, but a pre-check gives an actionable error and keeps
		// executable uploads out even if a filter loosens things downstream.
		$check = wp_check_filetype_and_ext( $tmp_file, $filename );
		$type  = $check['type'] ? $check['type'] : '';
		if ( '' === $type || ! get_allowed_mime_types() || ! in_array( $type, get_allowed_mime_types(), true ) ) {
			wp_delete_file( $tmp_file );
			return new \WP_Error(
				'disallowed_type',
				sprintf(
					/* translators: %s: filename */
					__( 'The file type of "%s" is not permitted for upload on this site (executable and unknown types are refused). Allowed types are the standard WordPress media types; enable the SVG Uploads module for SVGs.', 'emcp-lite' ),
					$filename
				)
			);
		}

		$post_id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post_data = array();
		if ( ! empty( $input['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( (string) $input['title'] );
		}
		if ( isset( $input['caption'] ) ) {
			$post_data['post_excerpt'] = sanitize_text_field( (string) $input['caption'] );
		}
		if ( isset( $input['description'] ) ) {
			$post_data['post_content'] = sanitize_textarea_field( (string) $input['description'] );
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		// media_handle_sideload() runs wp_generate_attachment_metadata() synchronously,
		// where the Image Optimization module compresses + generates WebP. Honor the
		// same convert_webp:false opt-out sideload-image offers.
		$skip_webp = array_key_exists( 'convert_webp', (array) $input ) && false === $input['convert_webp'];
		if ( $skip_webp ) {
			add_filter( 'emcp_tools_optimize_attachment', '__return_false', 99 );
		}
		try {
			$attachment_id = media_handle_sideload( $file_array, $post_id, null, $post_data );
		} finally {
			if ( $skip_webp ) {
				remove_filter( 'emcp_tools_optimize_attachment', '__return_false', 99 );
			}
		}

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			return new \WP_Error(
				'upload_failed',
				sprintf(
					/* translators: 1: filename, 2: error message */
					__( 'Upload of "%1$s" failed: %2$s', 'emcp-lite' ),
					$filename,
					$attachment_id->get_error_message()
				)
			);
		}

		// Alt text (image accessibility/SEO).
		if ( isset( $input['alt'] ) && '' !== (string) $input['alt'] ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
		}

		$change_id = '';
		if ( class_exists( 'EMCP_Tools_Change_Recorder' ) ) {
			$change_id = EMCP_Tools_Change_Recorder::record_resource_create( (int) $attachment_id, 'media', 'upload-media' );
			if ( ! EMCP_Tools_Change_Log::$suppress && '' === $change_id ) {
				return new \WP_Error( 'history_record_failed', __( 'The file was uploaded, but History could not save its creation. Inspect the attachment before retrying.', 'emcp-lite' ), array( 'attachment_id' => (int) $attachment_id ) );
			}
		}
		$result = $this->execute_get_media( array( 'id' => (int) $attachment_id ) );
		if ( is_array( $result ) ) {
			$result['change_id'] = $change_id;
		}
		return $result;
	}

	/**
	 * Normalizes an attachment post into the tool's result shape.
	 *
	 * @since 2.0.2
	 *
	 * @param \WP_Post $attachment The attachment post object.
	 * @return array
	 */
	private function format_attachment( $attachment ): array {
		$id   = (int) $attachment->ID;
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();

		$filesize = 0;
		if ( isset( $meta['filesize'] ) ) {
			$filesize = (int) $meta['filesize'];
		} else {
			$file = get_attached_file( $id );
			if ( $file && file_exists( $file ) ) {
				$filesize = (int) filesize( $file );
			}
		}

		$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );

		return array(
			'id'            => $id,
			'title'         => $attachment->post_title,
			'url'           => (string) wp_get_attachment_url( $id ),
			'thumbnail_url' => $thumb ? $thumb : '',
			'alt'           => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'mime_type'     => $attachment->post_mime_type,
			'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'filesize'      => $filesize,
			'date'          => $attachment->post_date_gmt,
		);
	}
}
