<?php
/**
 * Kadence Prebuilt-Library pattern access.
 *
 * Kadence's designs live in its cloud Prebuilt Library (the "Design Library").
 * The catalog is cached locally under uploads/kadence_blocks_library/ (a metadata
 * file + a preview-HTML file), and the real, canonical block markup for a pattern
 * is fetched on demand through Kadence's own REST controller
 * (Kadence_Blocks_Prebuilt_Library_REST_Controller::get_pattern_content). Because
 * that markup is Kadence's own save() output, an inserted pattern is a set of
 * professionally-designed, editor-VALID Kadence blocks — the reliable "act" path
 * for Kadence (unlike hand-built blocks, which can't byte-match the static save()).
 *
 * This wrapper reads the local metadata cache for discovery and delegates markup
 * retrieval + image localization to the Kadence controller. Tests seed
 * $GLOBALS['_kadence_pattern_catalog'] and $GLOBALS['_kadence_pattern_content'].
 *
 * @package EMCP_Tools
 * @since   3.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read + fetch Kadence library patterns.
 */
class EMCP_Tools_Kadence_Pattern_Library {

	const CONTROLLER = 'Kadence_Blocks_Prebuilt_Library_REST_Controller';

	/**
	 * @var array<string,array>|null Cached metadata catalog (id => entry).
	 */
	private static $catalog_cache = null;

	/**
	 * @return bool Whether the Kadence Prebuilt Library controller is available.
	 */
	public static function is_available(): bool {
		return class_exists( self::CONTROLLER ) || isset( $GLOBALS['_kadence_pattern_catalog'] );
	}

	/**
	 * The raw metadata catalog (pattern-id => entry), from the local cache. Tests
	 * seed $GLOBALS['_kadence_pattern_catalog'].
	 *
	 * @return array<string,array>
	 */
	public static function raw_catalog(): array {
		if ( isset( $GLOBALS['_kadence_pattern_catalog'] ) ) {
			return (array) $GLOBALS['_kadence_pattern_catalog'];
		}
		if ( null !== self::$catalog_cache ) {
			return self::$catalog_cache;
		}
		$out = array();
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir();
			$dir     = isset( $uploads['basedir'] ) ? trailingslashit( $uploads['basedir'] ) . 'kadence_blocks_library/' : '';
			if ( '' !== $dir && is_dir( $dir ) ) {
				foreach ( (array) glob( $dir . '*.json' ) as $file ) {
					$data = json_decode( (string) @file_get_contents( $file ), true );
					if ( ! is_array( $data ) || empty( $data ) ) {
						continue;
					}
					$first = reset( $data );
					// The metadata file's entries carry slug/name/categories; the
					// preview file's entries carry only 'html'. Pick the metadata one.
					if ( is_array( $first ) && isset( $first['slug'], $first['name'] ) ) {
						$out = $data;
						break;
					}
				}
			}
		}
		self::$catalog_cache = $out;
		return $out;
	}

	/**
	 * Reset the in-process catalog cache (tests).
	 */
	public static function flush_cache(): void {
		self::$catalog_cache = null;
	}

	/**
	 * A filtered, compact list of patterns.
	 *
	 * @param string $category    Optional category filter (case-insensitive).
	 * @param string $search      Optional name/keyword/category filter.
	 * @param bool   $include_pro Include pro/locked patterns (default false).
	 * @return array<int,array>
	 */
	public static function list_patterns( string $category = '', string $search = '', bool $include_pro = false ): array {
		$search = strtolower( trim( $search ) );
		$cat_lc = strtolower( trim( $category ) );
		$out    = array();
		foreach ( self::raw_catalog() as $id => $e ) {
			$e   = (array) $e;
			$pro = ! empty( $e['pro'] ) || ! empty( $e['locked'] );
			if ( ! $include_pro && $pro ) {
				continue;
			}
			$cats = self::cats( $e );
			if ( '' !== $cat_lc && ! in_array( $cat_lc, array_map( 'strtolower', $cats ), true ) ) {
				continue;
			}
			$hay = strtolower( (string) ( $e['name'] ?? '' ) . ' ' . (string) ( $e['slug'] ?? '' ) . ' ' . implode( ' ', (array) ( $e['keywords'] ?? array() ) ) . ' ' . implode( ' ', $cats ) );
			if ( '' !== $search && false === strpos( $hay, $search ) ) {
				continue;
			}
			$out[] = array(
				'id'         => (string) $id,
				'name'       => (string) ( $e['name'] ?? $id ),
				'categories' => $cats,
				'keywords'   => array_values( (array) ( $e['keywords'] ?? array() ) ),
				'pro'        => $pro,
			);
		}
		return $out;
	}

	/**
	 * Distinct pattern categories with counts.
	 *
	 * @param bool $include_pro Include pro/locked patterns in the counts.
	 * @return array<string,int>
	 */
	public static function categories( bool $include_pro = true ): array {
		$c = array();
		foreach ( self::raw_catalog() as $e ) {
			$e = (array) $e;
			if ( ! $include_pro && ( ! empty( $e['pro'] ) || ! empty( $e['locked'] ) ) ) {
				continue;
			}
			foreach ( self::cats( $e ) as $cat ) {
				$c[ $cat ] = ( $c[ $cat ] ?? 0 ) + 1;
			}
		}
		ksort( $c );
		return $c;
	}

	/**
	 * The metadata entry for a pattern id (accepts the catalog key like "ptn-123"
	 * or the numeric library id), or null.
	 *
	 * @param string $id Pattern id.
	 * @return array|null
	 */
	public static function entry( string $id ): ?array {
		$cat = self::raw_catalog();
		if ( isset( $cat[ $id ] ) ) {
			return (array) $cat[ $id ];
		}
		foreach ( $cat as $e ) {
			if ( (string) ( ( (array) $e )['id'] ?? '' ) === $id ) {
				return (array) $e;
			}
		}
		return null;
	}

	/**
	 * The canonical block markup for a pattern, optionally with its images
	 * downloaded into the Media Library.
	 *
	 * @param string $id              Pattern id.
	 * @param bool   $localize_images Download pattern images locally (needs upload_files).
	 * @return string|WP_Error
	 */
	public static function get_markup( string $id, bool $localize_images = false ) {
		$entry = self::entry( $id );
		if ( null === $entry ) {
			/* translators: %s: the requested identifier or comma-separated identifiers. */
			return new WP_Error( 'unknown_pattern', sprintf( __( 'Unknown pattern: %s', 'emcp-lite' ), $id ), array( 'status' => 404 ) );
		}
		$content = self::fetch_content( $entry );
		if ( is_wp_error( $content ) ) {
			return $content;
		}
		if ( '' === trim( (string) $content ) ) {
			return new WP_Error( 'empty_pattern', __( 'The pattern returned no content (it may be a Pro/locked pattern that needs a Kadence connection).', 'emcp-lite' ), array( 'status' => 422 ) );
		}
		if ( $localize_images ) {
			$content = self::localize_images( (string) $content );
		}
		return (string) $content;
	}

	/**
	 * Fetch a pattern's block markup via the Kadence controller. Tests seed
	 * $GLOBALS['_kadence_pattern_content'][ id ].
	 *
	 * @param array $entry Metadata entry.
	 * @return string|WP_Error
	 */
	protected static function fetch_content( array $entry ) {
		$lib_id = (string) ( $entry['id'] ?? '' );
		if ( isset( $GLOBALS['_kadence_pattern_content'][ $lib_id ] ) ) {
			return (string) $GLOBALS['_kadence_pattern_content'][ $lib_id ];
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'kadence_library_unavailable', __( 'The Kadence Prebuilt Library is not available on this site.', 'emcp-lite' ), array( 'status' => 501 ) );
		}
		$cls  = self::CONTROLLER;
		$ctrl = new $cls();
		$req  = new WP_REST_Request( 'GET', '/kb-design-library/v1/get_pattern_content' );
		foreach ( array(
			'library'      => 'section',
			'pattern_type' => 'pattern',
			'pattern_id'   => $lib_id,
			'key'          => 'section',
		) as $k => $v ) {
			$req->set_param( $k, $v );
		}
		$resp = $ctrl->get_pattern_content( $req );
		$data = ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( is_string( $data ) ) {
			$decoded = json_decode( $data, true );
			$data    = is_array( $decoded ) ? $decoded : array( 'content' => $data );
		}
		return isset( $data['content'] ) ? (string) $data['content'] : '';
	}

	/**
	 * Best-effort image localization via Kadence's own process_pattern (downloads
	 * remote images into the Media Library and rewrites the URLs). Returns the
	 * original content on any failure or missing capability.
	 *
	 * @param string $content Block markup.
	 * @return string
	 */
	protected static function localize_images( string $content ): string {
		if ( ! self::is_available() || ! function_exists( 'current_user_can' ) || ! current_user_can( 'upload_files' ) ) {
			return $content;
		}
		try {
			$cls  = self::CONTROLLER;
			$ctrl = new $cls();
			$req  = new WP_REST_Request( 'POST', '/kb-design-library/v1/process_pattern' );
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( array( 'content' => $content, 'image_library' => array() ) ) );
			$resp = $ctrl->process_pattern( $req );
			$data = ( $resp instanceof WP_REST_Response ) ? $resp->get_data() : $resp;
			if ( is_string( $data ) && '' !== $data && 'failed' !== $data ) {
				return $data;
			}
		} catch ( \Throwable $e ) {
			// fall through to original content.
		}
		return $content;
	}

	/**
	 * Normalize an entry's categories to a flat string list.
	 *
	 * @param array $e Metadata entry.
	 * @return string[]
	 */
	private static function cats( array $e ): array {
		$out = array();
		foreach ( (array) ( $e['categories'] ?? array() ) as $c ) {
			$out[] = is_array( $c ) ? (string) ( $c['slug'] ?? $c['name'] ?? '' ) : (string) $c;
		}
		return array_values( array_filter( $out ) );
	}
}
