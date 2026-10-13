<?php
/**
 * Same-origin front-end HTML fetcher.
 *
 * Fetches a public page through WordPress' HTTP API without forwarding the
 * current request's credentials, follows redirects manually, and returns a
 * bounded, UTF-8-safe chunk suitable for an MCP response.
 *
 * @package EMCP_Tools
 * @since   3.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch and chunk a public page on this WordPress origin.
 *
 * @since 3.15.0
 */
class EMCP_Tools_Frontend_Page_Fetcher {

	const FETCH_TIMEOUT       = 10;
	const MAX_REDIRECTS       = 3;
	const MAX_DOCUMENT_BYTES  = 2097152; // 2 MiB, matching the existing page audit cap.
	const DEFAULT_CHUNK_BYTES = 65536;
	const MIN_CHUNK_BYTES     = 1024;
	const MAX_CHUNK_BYTES     = 262144;

	/** @var callable|null */
	private $transport;

	/**
	 * @param callable|null $transport Optional `fn(string $url, array $args): array|WP_Error` for tests.
	 */
	public function __construct( ?callable $transport = null ) {
		$this->transport = $transport;
	}

	/**
	 * Resolve, fetch, validate, and chunk one page.
	 *
	 * @param array $input { url?, post_id?, offset?, max_bytes?, expected_sha256? }.
	 * @return array|\WP_Error
	 */
	public function get_page_html( array $input ) {
		$target = $this->resolve_target( $input );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$fetched = $this->fetch( $target['requested_url'] );
		if ( empty( $fetched['ok'] ) ) {
			return new \WP_Error(
				'page_fetch_failed',
				sprintf(
					/* translators: %s: HTTP/transport error. */
					__( 'The page could not be fetched: %s', 'emcp-lite' ),
					(string) ( $fetched['error'] ?? 'unknown error' )
				)
			);
		}

		$body         = $this->normalize_utf8( (string) ( $fetched['body'] ?? '' ) );
		$content_type = (string) ( $fetched['content_type'] ?? '' );
		if ( ! $this->is_html_response( $content_type, $body ) ) {
			return new \WP_Error(
				'invalid_content_type',
				sprintf(
					/* translators: %s: returned Content-Type value. */
					__( 'The URL did not return an HTML document (Content-Type: %s).', 'emcp-lite' ),
					$content_type ?: 'unknown'
				)
			);
		}

		$sha256   = hash( 'sha256', $body );
		$expected = strtolower( trim( (string) ( $input['expected_sha256'] ?? '' ) ) );
		if ( '' !== $expected && ! preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
			return new \WP_Error( 'invalid_checksum', __( 'expected_sha256 must be a 64-character hexadecimal SHA-256 value.', 'emcp-lite' ) );
		}
		if ( '' !== $expected && ! hash_equals( $expected, $sha256 ) ) {
			return new \WP_Error( 'page_changed', __( 'The page changed since the previous chunk. Start again at offset 0.', 'emcp-lite' ) );
		}

		$offset    = max( 0, (int) ( $input['offset'] ?? 0 ) );
		$max_bytes = (int) ( $input['max_bytes'] ?? self::DEFAULT_CHUNK_BYTES );
		$max_bytes = max( self::MIN_CHUNK_BYTES, min( self::MAX_CHUNK_BYTES, $max_bytes ) );
		$chunk     = $this->chunk( $body, $offset, $max_bytes );
		if ( is_wp_error( $chunk ) ) {
			return $chunk;
		}

		return array(
			'target'      => array(
				'requested_url' => $target['requested_url'],
				'final_url'     => (string) ( $fetched['final_url'] ?? $target['requested_url'] ),
				'post_id'       => $target['post_id'],
				'is_front_page' => $target['is_front_page'],
			),
			'response'    => array(
				'status_code'    => (int) ( $fetched['status_code'] ?? 0 ),
				'content_type'   => $content_type,
				'response_ms'    => (int) ( $fetched['response_ms'] ?? 0 ),
				'source_bytes'   => (int) ( $fetched['source_bytes'] ?? strlen( $body ) ),
				'available_bytes' => strlen( $body ),
				'sha256'         => $sha256,
				'fetch_truncated' => ! empty( $fetched['truncated'] ),
			),
			'chunk'       => $chunk,
			'render_mode' => 'source',
		);
	}

	/**
	 * Resolve url|post_id|frontpage to a public same-origin URL.
	 *
	 * @param array $input Tool input.
	 * @return array|\WP_Error
	 */
	public function resolve_target( array $input ) {
		$post_id      = null;
		$is_front_page = false;

		if ( ! empty( $input['url'] ) ) {
			$url = esc_url_raw( trim( (string) $input['url'] ) );
		} elseif ( ! empty( $input['post_id'] ) ) {
			$post_id = absint( $input['post_id'] );
			$post    = get_post( $post_id );
			if ( ! $post ) {
				return new \WP_Error( 'post_not_found', __( 'Post not found.', 'emcp-lite' ) );
			}
			if ( isset( $post->post_status ) && 'publish' !== (string) $post->post_status ) {
				return new \WP_Error( 'post_not_public', __( 'Only published posts can be fetched through their public front-end URL.', 'emcp-lite' ) );
			}
			$url = (string) get_permalink( $post_id );
		} else {
			$url           = home_url( '/' );
			$is_front_page = true;
		}

		if ( '' === $url || ! $this->same_origin( $url, home_url( '/' ) ) ) {
			return new \WP_Error( 'invalid_target', __( 'The URL must be an HTTP or HTTPS page on this site\'s exact origin.', 'emcp-lite' ) );
		}
		if ( $this->is_blocked_target( $url ) ) {
			return new \WP_Error( 'blocked_target', __( 'Only public front-end page URLs can be fetched; login, admin, and MCP endpoints are blocked.', 'emcp-lite' ) );
		}

		return array(
			'requested_url' => $url,
			'post_id'       => $post_id,
			'is_front_page' => $is_front_page,
		);
	}

	/**
	 * Fetch one HTML document, checking every redirect against the first origin.
	 *
	 * @param string $url       Absolute URL.
	 * @param int    $timeout   Timeout in seconds.
	 * @param int    $max_bytes Maximum response bytes retained.
	 * @return array
	 */
	public function fetch( string $url, int $timeout = self::FETCH_TIMEOUT, int $max_bytes = self::MAX_DOCUMENT_BYTES ): array {
		$current   = $url;
		$start     = microtime( true );
		$max_bytes = max( 1, min( self::MAX_DOCUMENT_BYTES, $max_bytes ) );

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop ) {
			$args = array(
				'timeout'             => max( 1, $timeout ),
				'redirection'         => 0,
				'limit_response_size' => $max_bytes,
				'user-agent'          => 'EMCP-Page-HTML/' . ( defined( 'EMCP_TOOLS_VERSION' ) ? EMCP_TOOLS_VERSION : '0' ),
			);
			$res  = $this->transport
				? call_user_func( $this->transport, $current, $args )
				: wp_remote_get( $current, $args );

			if ( is_wp_error( $res ) ) {
				return $this->failed_fetch( $start, $res->get_error_message(), $url );
			}

			$status   = (int) wp_remote_retrieve_response_code( $res );
			$location = (string) wp_remote_retrieve_header( $res, 'location' );
			if ( $status >= 300 && $status < 400 && '' !== $location ) {
				$next = $this->resolve_redirect_target( $location, $current );
				if ( '' === $next || ! $this->same_origin( $next, $url ) || $this->is_blocked_target( $next ) ) {
					return $this->failed_fetch( $start, 'off_origin_redirect', $url, $status );
				}
				$current = $next;
				continue;
			}

			$body           = (string) wp_remote_retrieve_body( $res );
			$headers        = $this->normalize_headers( wp_remote_retrieve_headers( $res ) );
			$available      = strlen( $body );
			$content_length = isset( $headers['content-length'] ) && 1 === preg_match( '/^\d+$/', trim( $headers['content-length'] ) )
				? (int) trim( $headers['content-length'] )
				: 0;
			$source_bytes   = max( $available, $content_length );
			$truncated      = $source_bytes > $available || $available >= $max_bytes;

			return array(
				'ok'           => true,
				'status_code'  => $status,
				'response_ms'  => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'source_bytes' => $source_bytes,
				'headers'      => $headers,
				'content_type' => (string) ( $headers['content-type'] ?? '' ),
				'body'         => $body,
				'truncated'    => $truncated,
				'error'        => null,
				'final_url'    => $current,
				'host'         => (string) wp_parse_url( $url, PHP_URL_HOST ),
			);
		}

		return $this->failed_fetch( $start, 'too_many_redirects', $url );
	}

	/**
	 * Exact-origin comparison, including scheme and effective port.
	 */
	public function same_origin( string $candidate, string $origin ): bool {
		$a = $this->origin_parts( $candidate );
		$b = $this->origin_parts( $origin );
		return null !== $a && null !== $b && $a === $b;
	}

	/**
	 * Resolve an absolute or relative Location value against its current URL.
	 */
	public function resolve_redirect_target( string $location, string $current_url ): string {
		$location = trim( $location );
		if ( '' === $location ) {
			return '';
		}

		$parts = wp_parse_url( $location );
		if ( is_array( $parts ) && ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
			return $location;
		}

		if ( class_exists( 'WP_Http' ) && method_exists( 'WP_Http', 'make_absolute_url' ) ) {
			return (string) \WP_Http::make_absolute_url( $location, $current_url );
		}

		$base = wp_parse_url( $current_url );
		if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}
		$authority = strtolower( (string) $base['scheme'] ) . '://' . $base['host'];
		if ( isset( $base['port'] ) ) {
			$authority .= ':' . (int) $base['port'];
		}
		if ( '/' === substr( $location, 0, 1 ) ) {
			return $authority . $location;
		}
		$path = (string) ( $base['path'] ?? '/' );
		$dir  = preg_replace( '#/[^/]*$#', '/', $path );
		return $authority . ( $dir ?: '/' ) . $location;
	}

	/**
	 * Return a UTF-8-safe byte chunk.
	 *
	 * @return array|\WP_Error
	 */
	public function chunk( string $body, int $offset, int $max_bytes ) {
		$length = strlen( $body );
		if ( $offset < 0 || $offset > $length ) {
			return new \WP_Error( 'invalid_offset', __( 'offset is outside the available HTML document.', 'emcp-lite' ) );
		}
		if ( $offset < $length && $this->is_continuation_byte( ord( $body[ $offset ] ) ) ) {
			return new \WP_Error( 'invalid_offset', __( 'offset must point to a UTF-8 character boundary. Use next_offset from the previous response.', 'emcp-lite' ) );
		}

		$max_bytes = max( 1, $max_bytes );
		if ( function_exists( 'mb_strcut' ) ) {
			$html = (string) mb_strcut( $body, $offset, $max_bytes, 'UTF-8' );
		} else {
			$html = substr( $body, $offset, $max_bytes );
			while ( '' !== $html && 1 !== preg_match( '//u', $html ) ) {
				$html = substr( $html, 0, -1 );
			}
		}

		$returned = strlen( $html );
		$next     = $offset + $returned;
		$complete = $next >= $length;

		return array(
			'html'           => $html,
			'offset'         => $offset,
			'returned_bytes' => $returned,
			'next_offset'    => $complete ? null : $next,
			'complete'       => $complete,
		);
	}

	/** @return array{scheme:string,host:string,port:int}|null */
	private function origin_parts( string $url ): ?array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return array( 'scheme' => $scheme, 'host' => strtolower( trim( (string) $parts['host'], '[]' ) ), 'port' => $port );
	}

	private function is_blocked_target( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return true;
		}
		$path = strtolower( (string) ( $parts['path'] ?? '/' ) );
		// Decode nested path escapes, then normalize separators and dot segments.
		// Web servers do not all canonicalize these in the same order, so checking
		// only the literal URL could let a front-end-looking path reach wp-admin.
		for ( $i = 0; $i < 5; ++$i ) {
			$decoded = rawurldecode( $path );
			if ( $decoded === $path ) {
				break;
			}
			$path = $decoded;
		}
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return true;
		}
		$path     = str_replace( '\\', '/', $path );
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		$path = '/' . implode( '/', $segments );
		if ( preg_match( '#/(?:wp-admin)(?:/|$)#', $path ) || preg_match( '#/wp-login\.php(?:/|$)#', $path ) || preg_match( '#/' . preg_quote( rest_get_url_prefix(), '#' ) . '/mcp(?:/|$)#', $path ) ) {
			return true;
		}
		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$route = isset( $query['rest_route'] ) && is_scalar( $query['rest_route'] )
			? strtolower( (string) $query['rest_route'] )
			: '';
		$route = str_replace( '\\', '/', rawurldecode( $route ) );
		return 1 === preg_match( '#^/+mcp(?:/|$)#', $route );
	}

	private function is_html_response( string $content_type, string $body ): bool {
		$type = strtolower( trim( explode( ';', $content_type )[0] ) );
		if ( in_array( $type, array( 'text/html', 'application/xhtml+xml' ), true ) ) {
			return true;
		}
		if ( '' !== $type ) {
			return false;
		}
		return 1 === preg_match( '/^\s*(?:<!doctype\s+html|<html\b)/i', $body );
	}

	private function normalize_utf8( string $body ): string {
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			return (string) wp_check_invalid_utf8( $body, true );
		}
		if ( 1 === preg_match( '//u', $body ) ) {
			return $body;
		}
		if ( function_exists( 'iconv' ) ) {
			$clean = iconv( 'UTF-8', 'UTF-8//IGNORE', $body );
			return false === $clean ? '' : $clean;
		}
		return '';
	}

	private function is_continuation_byte( int $byte ): bool {
		return 0x80 === ( $byte & 0xC0 );
	}

	private function failed_fetch( float $start, string $error, string $url, int $status = 0 ): array {
		return array(
			'ok' => false, 'status_code' => $status,
			'response_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'source_bytes' => 0, 'headers' => array(), 'content_type' => '', 'body' => '',
			'truncated' => false, 'error' => $error, 'final_url' => $url,
			'host' => (string) wp_parse_url( $url, PHP_URL_HOST ),
		);
	}

	private function normalize_headers( $headers ): array {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		if ( ! is_array( $headers ) ) {
			return array();
		}
		$out = array();
		foreach ( $headers as $key => $value ) {
			$out[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return $out;
	}
}
