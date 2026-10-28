<?php
/**
 * The `/authorize` endpoint — validates the authorization request, gates it
 * behind a WordPress login + administrator consent, and (on approval) issues a
 * single-use, PKCE-bound authorization code before redirecting back to the
 * client.
 *
 * Client/redirect-URI validation happens before anything is echoed or
 * redirected, so a bad client can never be used as an open redirect. All other
 * errors are returned to the (validated) redirect URI per RFC 6749 §4.1.2.1.
 *
 * @package EMCP_Tools
 * @since   3.4.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorization endpoint + consent screen.
 *
 * @since 3.4.1
 */
class EMCP_Tools_OAuth_Authorize {

	const NONCE_ACTION = 'emcp_oauth_consent';

	/**
	 * The browser-facing authorize path. This is served as a normal front-end
	 * request (NOT a REST route) so WordPress cookie auth applies — a REST
	 * endpoint would require a nonce the client's browser navigation can't
	 * provide, and cookie sessions would never be recognized.
	 */
	const PATH = '/emcp-oauth/authorize';

	/**
	 * Wire the root-level request interception for the authorize endpoint.
	 */
	public static function init(): void {
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ), 0 );
	}

	/**
	 * The absolute authorize endpoint URL (advertised in the AS metadata).
	 *
	 * @return string
	 */
	public static function endpoint_url(): string {
		// Reachable public base (rest_url-derived / admin-overridable), NOT
		// home_url() — see EMCP_Tools_Site_Context::public_base_url(). Keeps the
		// advertised authorize URL on the host clients can actually reach.
		if ( class_exists( 'EMCP_Tools_Site_Context' ) ) {
			return EMCP_Tools_Site_Context::public_base_url() . self::PATH;
		}
		return home_url( self::PATH );
	}

	/**
	 * Serve the authorize endpoint when the request path matches; no-op
	 * otherwise. Dispatches GET (render consent) vs POST (record decision).
	 *
	 * @param WP $wp Current environment (unused).
	 */
	public static function maybe_serve( $wp = null ): void {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = class_exists( 'EMCP_Tools_OAuth_Metadata' )
			? EMCP_Tools_OAuth_Metadata::normalize_request_path( $path )
			: ( '/' === $path ? $path : rtrim( $path, '/' ) );
		if ( self::PATH !== $path ) {
			return;
		}
		// Consent must never be embedded, including on same-site subdomains.
		if ( ! headers_sent() ) {
			header( "Content-Security-Policy: frame-ancestors 'none'" );
			header( 'X-Frame-Options: DENY' );
		}
		if ( ! EMCP_Tools_OAuth_Server::is_enabled() ) {
			self::error_page( __( 'OAuth sign-in is not enabled on this site.', 'emcp-lite' ) );
		}

		if ( 'POST' === strtoupper( sanitize_key( wp_unslash( isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) ) ) {
			self::handle_post();
		} else {
			self::handle_get();
		}
	}

	/** OAuth authorization request fields (RFC 6749 section 4.1.1). */
	const PARAM_KEYS = array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' );

	/**
	 * OAuth state and registered redirect URIs are opaque: they are compared
	 * byte-for-byte or echoed back, so invalid values are rejected rather than
	 * normalized into a different binding. Any valid UTF-8 without control bytes.
	 */
	const OPAQUE_PATTERN = '/\A[^\x00-\x1f\x7f]{0,4096}\z/u';

	/** Every other field is one or more space-separated URI-safe tokens. */
	const TOKEN_PATTERN = '/\A(?=[ -~]{0,4096}\z)(?:[A-Za-z0-9._~:\/@!$&\'()*+,;=-]+(?: [A-Za-z0-9._~:\/@!$&\'()*+,;=-]+)*)?\z/';

	/**
	 * filter_var() rule for a field.
	 *
	 * @param string $key Field name.
	 * @return array
	 */
	private static function field_rule( string $key ): array {
		return array( 'options' => array( 'regexp' => self::is_opaque( $key ) ? self::OPAQUE_PATTERN : self::TOKEN_PATTERN ) );
	}

	/** Whether a field is opaque (kept byte-for-byte). */
	private static function is_opaque( string $key ): bool {
		return in_array( $key, array( 'state', 'redirect_uri', 'resource' ), true );
	}

	/**
	 * Finish a validated field: false (malformed) becomes null so validate_params()
	 * rejects it instead of silently applying a default.
	 *
	 * @param string       $key   Field name.
	 * @param string|false $value filter_var() result.
	 * @return string|null
	 */
	private static function finish_field( string $key, $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		// Token fields contain nothing sanitize_text_field() would alter.
		return self::is_opaque( $key ) ? $value : sanitize_text_field( $value );
	}

	/**
	 * Authorization request from the query string.
	 *
	 * This is the public OAuth authorization endpoint: the connecting client
	 * application starts the request, so it cannot carry a WordPress nonce. The
	 * GET request only validates the parameters and displays the consent screen
	 * to a logged-in administrator; nothing is stored until the nonce-verified
	 * consent POST (consent_params()).
	 *
	 * @return array<string,string|null>
	 */
	private static function authorization_params(): array {
		$out = array();
		foreach ( self::PARAM_KEYS as $key ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public OAuth authorization request (no state change); see docblock.
			if ( isset( $_GET[ $key ] ) ) {
				$out[ $key ] = self::finish_field( $key, is_string( $_GET[ $key ] ) ? filter_var( wp_unslash( $_GET[ $key ] ), FILTER_VALIDATE_REGEXP, self::field_rule( $key ) ) : false );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}
		return $out;
	}

	/**
	 * The consent form submission. The nonce is verified before any other field
	 * is read; null means the nonce is missing or invalid.
	 *
	 * @return array<string,string|null>|null
	 */
	private static function consent_params(): ?array {
		$nonce = isset( $_POST['_emcp_oauth_nonce'] ) && is_string( $_POST['_emcp_oauth_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_emcp_oauth_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return null;
		}
		$out = array();
		foreach ( array_merge( self::PARAM_KEYS, array( 'action' ) ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$out[ $key ] = self::finish_field( $key, is_string( $_POST[ $key ] ) ? filter_var( wp_unslash( $_POST[ $key ] ), FILTER_VALIDATE_REGEXP, self::field_rule( $key ) ) : false );
			}
		}
		return $out;
	}

	/**
	 * The capability required to approve a connection (filterable).
	 *
	 * @return string
	 */
	public static function required_cap(): string {
		return (string) apply_filters( 'emcp_tools_oauth_authorize_cap', 'manage_options' );
	}

	// ---------------------------------------------------------------------
	// GET — validate + login-gate + render consent
	// ---------------------------------------------------------------------

	private static function handle_get(): void {
		$params       = self::authorization_params();
		$client_id    = (string) ( $params['client_id'] ?? '' );
		$redirect_uri = (string) ( $params['redirect_uri'] ?? '' );
		$client       = self::lookup_client( $client_id );

		// Client + redirect must be valid before we trust redirect_uri as a target.
		// The two failures need different fixes from the person reading the page,
		// so they are reported separately. Nothing is disclosed by doing so: the
		// caller supplied both values.
		if ( '' === $client_id || null === $client ) {
			self::error_page(
				__( 'This site does not recognise the app making this connection request.', 'emcp-lite' ),
				self::stale_client_hint()
			);
		}
		if ( '' === $redirect_uri || ! self::redirect_registered( $client, $redirect_uri ) ) {
			self::error_page(
				__( 'The return address this app asked for does not match the one it registered.', 'emcp-lite' ),
				self::redirect_mismatch_hint( $client, $redirect_uri )
			);
		}

		$state = (string) ( $params['state'] ?? '' );
		$valid = self::validate_params( $params, $client );
		if ( is_wp_error( $valid ) ) {
			self::redirect_error( $redirect_uri, $valid->get_error_code(), $state );
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::current_url() ) );
			exit;
		}
		if ( ! current_user_can( self::required_cap() ) ) {
			self::error_page( __( 'Only administrators can authorize an MCP connection on this site.', 'emcp-lite' ) );
		}

		// KSES removes declarations, so the fixed doctype is printed on its own (standards mode).
		echo "<!doctype html>\n";
		echo wp_kses( self::render_consent( array_merge( $valid, array( 'client_name' => $client['client_name'] ) ) ), EMCP_Lite_Edition::allowed_html( 'document' ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// POST — record the approve/deny decision
	// ---------------------------------------------------------------------

	private static function handle_post(): void {
		if ( ! is_user_logged_in() || ! current_user_can( self::required_cap() ) ) {
			self::error_page( __( 'You are not allowed to authorize this connection.', 'emcp-lite' ) );
		}

		$p = self::consent_params();
		if ( null === $p ) {
			self::error_page( __( 'Security check failed. Please start the connection again.', 'emcp-lite' ) );
		}

		$client_id    = (string) ( $p['client_id'] ?? '' );
		$redirect_uri = (string) ( $p['redirect_uri'] ?? '' );
		$client       = self::lookup_client( $client_id );
		if ( null === $client ) {
			self::error_page(
				__( 'This site does not recognise the app making this connection request.', 'emcp-lite' ),
				self::stale_client_hint()
			);
		}
		if ( ! self::redirect_registered( $client, $redirect_uri ) ) {
			self::error_page(
				__( 'The return address this app asked for does not match the one it registered.', 'emcp-lite' ),
				self::redirect_mismatch_hint( $client, $redirect_uri )
			);
		}

		$state = (string) ( $p['state'] ?? '' );
		if ( 'approve' !== ( $p['action'] ?? '' ) ) {
			self::redirect_error( $redirect_uri, 'access_denied', $state );
		}

		// The consent POST is untrusted even after a valid nonce. Revalidate
		// the same PKCE, scope and resource contract used by the GET request.
		$p['response_type'] = 'code';
		$p['code_challenge_method'] = 'S256';
		$valid = self::validate_params( $p, $client );
		if ( is_wp_error( $valid ) ) {
			self::redirect_error( $redirect_uri, $valid->get_error_code(), $state );
		}

		// An app approved while this user's Connection setup is open belongs to
		// that setup (spec 9.5); nothing is tagged otherwise.
		if ( class_exists( 'EMCP_Tools_Connection_Setup' ) ) {
			EMCP_Tools_Connection_Setup::tag_oauth_consent( get_current_user_id(), (string) $client['client_id'] );
		}

		$code = EMCP_Tools_OAuth_Store::issue_code(
			array(
				'client_id'      => $client['client_id'],
				'user_id'        => get_current_user_id(),
				'redirect_uri'   => $redirect_uri,
				'code_challenge' => $valid['code_challenge'],
				'scopes'         => $valid['scope'],
				'resource'       => EMCP_Tools_OAuth_Metadata::resource(),
			)
		);

		wp_redirect( self::build_redirect( $redirect_uri, array( 'code' => $code, 'state' => $state ) ) );
		exit;
	}

	// ---------------------------------------------------------------------
	// Pure helpers (unit-tested)
	// ---------------------------------------------------------------------

	/**
	 * Validate the non-client authorization parameters.
	 *
	 * @param array      $params Request params.
	 * @param array|null $client The resolved client (null if unknown).
	 * @return array|WP_Error Normalized params, or an error whose code is a valid
	 *                        OAuth error slug (safe to return to redirect_uri).
	 */
	public static function validate_params( array $params, ?array $client ) {
		foreach ( $params as $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 4096 || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
				return new WP_Error( 'invalid_request', 'Malformed authorization parameter.' );
			}
		}
		if ( 'code' !== ( $params['response_type'] ?? '' ) ) {
			return new WP_Error( 'unsupported_response_type', 'Only response_type=code is supported.' );
		}
		if ( null === $client ) {
			return new WP_Error( 'invalid_request', 'Unknown client.' );
		}
		if ( 'S256' !== ( $params['code_challenge_method'] ?? '' ) || ! preg_match( '/\A[A-Za-z0-9_-]{43}\z/', $params['code_challenge'] ?? '' ) ) {
			return new WP_Error( 'invalid_request', 'PKCE with S256 is required.' );
		}
		$scope = $params['scope'] ?? EMCP_Tools_OAuth_Server::SCOPE;
		if ( '' === $scope ) {
			$scope = EMCP_Tools_OAuth_Server::SCOPE;
		}
		if ( EMCP_Tools_OAuth_Server::SCOPE !== $scope ) {
			return new WP_Error( 'invalid_scope', 'Only the mcp scope can be authorized.' );
		}
		$resource = (string) ( $params['resource'] ?? '' );
		if ( '' === $resource ) {
			// Compatibility for older clients on this single-resource server. New
			// MCP clients send resource explicitly; mismatches are always rejected.
			$resource = EMCP_Tools_OAuth_Metadata::resource();
		}
		if ( ! EMCP_Tools_OAuth_Metadata::resource_matches( $resource ) ) {
			return new WP_Error( 'invalid_target', 'The requested resource is not this MCP server.' );
		}
		return array(
			'client_id'      => (string) ( $params['client_id'] ?? '' ),
			'redirect_uri'   => (string) ( $params['redirect_uri'] ?? '' ),
			'code_challenge' => (string) $params['code_challenge'],
			'state'          => (string) ( $params['state'] ?? '' ),
			'scope'          => $scope,
			'resource'       => EMCP_Tools_OAuth_Metadata::resource(),
		);
	}

	/**
	 * Whether a redirect URI is registered for the client.
	 *
	 * @param array  $client       Client with a `redirect_uris` array.
	 * @param string $redirect_uri Candidate.
	 * @return bool
	 */
	public static function redirect_registered( array $client, string $redirect_uri ): bool {
		foreach ( (array) ( $client['redirect_uris'] ?? array() ) as $registered ) {
			if ( is_string( $registered ) && EMCP_Tools_OAuth_Util::redirect_uri_matches( $registered, $redirect_uri ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Append query args to a redirect URI (handles existing query strings).
	 *
	 * @param string $redirect_uri Base URI.
	 * @param array  $args         Args (empty values are dropped).
	 * @return string
	 */
	public static function build_redirect( string $redirect_uri, array $args ): string {
		$pairs = array();
		foreach ( $args as $k => $v ) {
			if ( '' !== (string) $v ) {
				$pairs[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
			}
		}
		if ( empty( $pairs ) ) {
			return $redirect_uri;
		}
		$sep = ( false === strpos( $redirect_uri, '?' ) ) ? '?' : '&';
		return $redirect_uri . $sep . implode( '&', $pairs );
	}

	/**
	 * Render the consent screen HTML.
	 *
	 * @param array $ctx { client_id, client_name, redirect_uri, code_challenge, state, scope, resource }.
	 * @return string
	 */
	public static function render_consent( array $ctx ): string {
		$user       = wp_get_current_user();
		$site       = get_bloginfo( 'name' );
		$client     = (string) ( $ctx['client_name'] ?? 'An MCP client' );
		$nonce      = wp_create_nonce( self::NONCE_ACTION );
		$deny_label = __( 'Deny', 'emcp-lite' );

		$hidden = '';
		foreach ( array( 'client_id', 'redirect_uri', 'code_challenge', 'state', 'scope', 'resource' ) as $k ) {
			$hidden .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) ( $ctx[ $k ] ?? '' ) ) . '" />';
		}
		$hidden .= '<input type="hidden" name="_emcp_oauth_nonce" value="' . esc_attr( $nonce ) . '" />';

		$action = esc_url( self::endpoint_url() );
		wp_enqueue_style( 'emcp-oauth-consent', EMCP_TOOLS_URL . 'assets/css/oauth-consent.css', array(), EMCP_TOOLS_VERSION );
		ob_start();
		wp_print_styles( array( 'emcp-oauth-consent' ) );
		$styles = (string) ob_get_clean();

		return '<html><head><meta charset="utf-8" />'
			. '<meta name="viewport" content="width=device-width, initial-scale=1" />'
			. '<meta name="robots" content="noindex" />'
			. '<title>' . esc_html__( 'Authorize connection', 'emcp-lite' ) . '</title>'
			. $styles . '</head><body><div class="wrap"><div class="card">'
			. '<div class="eyebrow">' . esc_html__( 'Authorize MCP connection', 'emcp-lite' ) . '</div>'
			. '<h1>' . sprintf(
				/* translators: 1: client name, 2: site name */
				esc_html__( '%1$s wants to connect to %2$s', 'emcp-lite' ),
				'<b>' . esc_html( $client ) . '</b>',
				esc_html( $site )
			) . '</h1>'
			. '<p>' . esc_html__( 'It will connect as your WordPress account and can do anything you can through the MCP tools you have enabled.', 'emcp-lite' ) . '</p>'
			. '<div class="who">' . sprintf(
				/* translators: 1: display name, 2: user login */
				esc_html__( 'Signed in as %1$s (%2$s)', 'emcp-lite' ),
				'<b>' . esc_html( $user->display_name ) . '</b>',
				esc_html( $user->user_login )
			) . '</div>'
			. '<p class="warn">' . esc_html__( 'Only approve connections you started yourself. You can revoke access anytime from EMCP Lite → Connection.', 'emcp-lite' ) . '</p>'
			. '<form method="post" action="' . $action . '">' . $hidden
			. '<div class="row">'
			. '<button class="deny" type="submit" name="action" value="deny">' . esc_html( $deny_label ) . '</button>'
			. '<button class="approve" type="submit" name="action" value="approve">' . esc_html__( 'Approve', 'emcp-lite' ) . '</button>'
			. '</div></form></div></div></body></html>';
	}

	// ---------------------------------------------------------------------
	// Internal
	// ---------------------------------------------------------------------

	/**
	 * @param string $client_id Client id.
	 * @return array|null
	 */
	private static function lookup_client( string $client_id ): ?array {
		return '' === $client_id ? null : EMCP_Tools_OAuth_Store::get_client( $client_id );
	}

	/**
	 * Redirect back to the client with an OAuth error, then exit.
	 *
	 * @param string $redirect_uri Validated redirect URI.
	 * @param string $error        OAuth error code.
	 * @param string $state        Opaque state to echo back.
	 */
	private static function redirect_error( string $redirect_uri, string $error, string $state ): void {
		wp_redirect( self::build_redirect( $redirect_uri, array( 'error' => $error, 'state' => $state ) ) );
		exit;
	}

	/**
	 * Output a minimal HTML error page (used when there is no safe redirect
	 * target), then exit.
	 *
	 * @param string $message Message.
	 */
	private static function error_page( string $message, string $hint = '' ): void {
		if ( ! headers_sent() ) {
			status_header( 400 );
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		echo '<!doctype html><meta charset="utf-8" /><title>' . esc_html__( 'Connection error', 'emcp-lite' ) . '</title>'
			. '<div style="max-width:460px;margin:12vh auto;font-family:sans-serif;text-align:center;color:#0a0a14">'
			. '<h1 style="font-size:20px">' . esc_html__( 'Connection error', 'emcp-lite' ) . '</h1>'
			. '<p style="color:#3a3b52">' . esc_html( $message ) . '</p>'
			. ( '' !== $hint ? '<p style="color:#6b6c85;font-size:13px;line-height:1.5">' . esc_html( $hint ) . '</p>' : '' )
			. '</div>';
		exit;
	}

	/**
	 * The recovery hint for an unrecognised client_id / redirect_uri. These
	 * clients register themselves (dynamic client registration), so a stale
	 * cached registration cannot be repaired from this page — the client has to
	 * register again, which happens when the user removes and re-adds the
	 * connector. Without this the page was a dead end and the client just kept
	 * re-opening it.
	 *
	 * @return string
	 */
	private static function redirect_mismatch_hint( array $client, string $redirect_uri ): string {
		$registered = array_values( array_filter( (array) ( $client['redirect_uris'] ?? array() ), 'is_string' ) );
		$hint       = __( 'The app registered a different return address than the one it is now asking for. Removing this MCP connector in the app and adding it again usually clears it.', 'emcp-lite' );
		if ( ! $registered ) {
			return $hint;
		}
		// Show both sides. Whoever is debugging this needs to compare them, and
		// both values already came from the request or from this app's own
		// registration, so neither is a secret.
		return $hint . ' ' . sprintf(
			/* translators: 1: the address requested, 2: comma-separated list of registered addresses. */
			__( 'Requested: %1$s. Registered: %2$s.', 'emcp-lite' ),
			'' !== $redirect_uri ? $redirect_uri : __( '(none)', 'emcp-lite' ),
			implode( ', ', $registered )
		);
	}

	private static function stale_client_hint(): string {
		return __( 'This usually means the app is reconnecting with a registration this site no longer recognises. In your AI app, remove this MCP connector and add it again to start a fresh connection.', 'emcp-lite' );
	}

	/**
	 * The absolute URL of the current request (for the login return).
	 *
	 * @return string
	 */
	private static function current_url(): string {
		// The destination uses the configured origin, never a request Host header.
		$params = array_filter( self::authorization_params(), 'is_string' );
		return esc_url_raw( add_query_arg( $params, self::endpoint_url() ) );
	}
}
