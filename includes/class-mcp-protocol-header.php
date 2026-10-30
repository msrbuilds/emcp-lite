<?php
/**
 * Missing MCP-Protocol-Version header on established HTTP sessions.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP Adapter 0.7 refuses every request after `initialize` that lacks an
 * `MCP-Protocol-Version` header when the session negotiated 2025-06-18 or later
 * (HTTP 400, "MCP-Protocol-Version header is required"). 0.6 never checked it,
 * so clients that only send `Mcp-Session-Id` (the EMCP Cloud Gateway up to
 * 2026-10-30, older SDK builds) stopped working when 3.21.0 bundled 0.7.1.
 *
 * The 2025-06-18 transport spec lets a server rely on the version negotiated at
 * initialization when the header is missing. This fills the header from the
 * stored session before the adapter validates the request. A header the client
 * did send is never changed, and nothing happens without a live session.
 */
class EMCP_Tools_MCP_Protocol_Header {

	/**
	 * Test seam: reads a session as ( user_id, session_id ) => array|false.
	 *
	 * @var callable|null
	 */
	private static $session_reader = null;

	/**
	 * Replace the session reader (tests).
	 *
	 * @param callable|null $reader Reader, or null for the adapter's SessionManager.
	 * @return void
	 */
	public static function use_session_reader( ?callable $reader ): void {
		self::$session_reader = $reader;
	}

	/**
	 * Whether the loaded adapter checks the header at all (0.7 and later).
	 *
	 * @return bool
	 */
	public static function adapter_checks_header(): bool {
		return class_exists( '\WP\MCP\Core\McpVersionNegotiator' )
			&& method_exists( '\WP\MCP\Core\McpVersionNegotiator', 'requires_protocol_version_header' );
	}

	/**
	 * The protocol version a stored session negotiated, as the adapter computes it.
	 *
	 * @param mixed $session Stored session.
	 * @return string|null Negotiated version, or null when the session holds no initialize params.
	 */
	public static function negotiated( $session ): ?string {
		if ( ! is_array( $session ) || ! is_array( $session['client_params'] ?? null ) ) {
			return null;
		}
		$proposed = $session['client_params']['protocolVersion'] ?? '';
		return \WP\MCP\Core\McpVersionNegotiator::negotiate( is_string( $proposed ) ? $proposed : '' );
	}

	/**
	 * rest_pre_dispatch filter: fill a missing header on the EMCP MCP route.
	 *
	 * @param mixed $result  Dispatch result, passed through untouched.
	 * @param mixed $server  REST server (unused).
	 * @param mixed $request WP_REST_Request.
	 * @return mixed
	 */
	public static function fill( $result, $server, $request ) {
		try {
			if ( null !== $result || ! $request instanceof WP_REST_Request ) {
				return $result;
			}
			if ( 'POST' !== $request->get_method() || 0 !== strpos( (string) $request->get_route(), '/mcp/emcp-tools-server' ) ) {
				return $result;
			}
			$header = $request->get_header( 'Mcp-Protocol-Version' );
			if ( is_string( $header ) && '' !== $header ) {
				return $result;
			}
			$session_id = $request->get_header( 'Mcp-Session-Id' );
			if ( ! is_string( $session_id ) || '' === $session_id || ! self::adapter_checks_header() ) {
				return $result;
			}
			$user_id = get_current_user_id();
			if ( ! $user_id ) {
				return $result;
			}
			$reader  = self::$session_reader ?? array( '\WP\MCP\Transport\Infrastructure\SessionManager', 'get_session' );
			$version = self::negotiated( call_user_func( $reader, $user_id, $session_id ) );
			if ( null !== $version ) {
				$request->set_header( 'Mcp-Protocol-Version', $version );
			}
		} catch ( \Throwable $e ) {
			// Never block a request over a compatibility shim; the adapter answers as before.
			return $result;
		}
		return $result;
	}
}
