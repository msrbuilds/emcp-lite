<?php
/**
 * The opt-in screen and its three admin actions (Allow, Skip, Retry).
 *
 * Shown in place of the screen content inside the Lite frame, to a user with
 * manage_options, until a choice is made for the site. Not a redirect.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Optin_Screen {

	const PRIVACY_URL = 'https://emcptools.com/privacy/';
	const TERMS_URL   = 'https://emcptools.com/terms-of-service/';

	/** @var bool Test seam: whether handlers end the request after redirecting. */
	public static $exit = true;

	public static function init(): void {
		add_action( 'admin_post_emcp_lite_optin_allow', array( __CLASS__, 'handle_allow' ) );
		add_action( 'admin_post_emcp_lite_optin_skip', array( __CLASS__, 'handle_skip' ) );
		add_action( 'admin_post_emcp_lite_optin_retry', array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/** Whether the opt-in replaces this screen's content. */
	public static function applies( array $state ): bool {
		return current_user_can( 'manage_options' ) && ! is_network_admin()
			&& in_array( EMCP_Tools_Optin_State::effective( $state ), array( 'unset', 'pending' ), true );
	}

	public static function enqueue( $hook = '' ): void {
		if ( false === strpos( (string) $hook, 'emcp-tools' ) ) {
			return;
		}
		wp_enqueue_style( 'emcp-lite-optin', EMCP_TOOLS_URL . 'assets/css/optin.css', array(), EMCP_TOOLS_VERSION );
	}

	/** The exact fields Allow shares, for the "What we collect" lists. */
	public static function collected(): array {
		return array(
			__( 'Your first and last name and email address (yours only, no other users)', 'emcp-lite' ),
			__( 'Site address and title, and the site language', 'emcp-lite' ),
			__( 'WordPress, PHP and EMCP Lite versions, and whether this is a multisite network', 'emcp-lite' ),
			__( 'Active theme (name, version and parent theme)', 'emcp-lite' ),
			__( 'Active plugins (folder name, name and version)', 'emcp-lite' ),
			__( 'The page builder selected in EMCP Lite', 'emcp-lite' ),
		);
	}

	/** Print the screen. */
	public static function render( array $state ): void {
		$emcp_state  = $state;
		$emcp_crypto = EMCP_Tools_Optin_State::encryption_available();
		include EMCP_TOOLS_DIR . 'includes/admin/views/optin-screen.php';
	}

	/** A message for the screen, from the last outcome. */
	public static function message( array $state ): string {
		if ( 'pending' === $state['state'] ) {
			return __( 'We could not reach emcptools.com. Your choice is saved: Retry sends it again, or Skip cancels it.', 'emcp-lite' );
		}
		switch ( (string) $state['error'] ) {
			case 'site_registered':
				return __( 'This site address is already shared by another installation of EMCP Lite, so nothing was changed. You can try again later or skip.', 'emcp-lite' );
			case 'expired':
				return __( 'That request is no longer valid. Choose again to send a new one.', 'emcp-lite' );
			case 'invalid':
				return __( 'emcptools.com did not accept the details from this site. You can try again later or skip.', 'emcp-lite' );
		}
		return '';
	}

	public static function handle_allow(): void {
		self::guard( 'emcp_lite_optin_allow' );
		self::start( false );
		self::done();
	}

	/**
	 * Start a new opt-in request and send it.
	 *
	 * @param bool $reuse Keep this installation's id and key (Reconnect). A copy never reuses.
	 * @return bool Whether a request was started.
	 */
	public static function start( bool $reuse ): bool {
		if ( ! EMCP_Tools_Optin_State::encryption_available() ) {
			return false;
		}
		$state = EMCP_Tools_Optin_State::transition(
			function ( array $s ) use ( $reuse ) {
				$effective = EMCP_Tools_Optin_State::effective( $s );
				if ( ! in_array( $effective, array( 'unset', 'copy', 'skipped', 'opted_out', 'revoked', 'expired' ), true ) ) {
					return null;
				}
				if ( 'opted_out' === $effective && 'pending' === $s['deletion'] ) {
					return null; // The kept credential still has a delete to deliver.
				}
				$current = $reuse && 'copy' !== $effective ? EMCP_Tools_Optin_State::credential( $s ) : null;
				$token   = EMCP_Tools_Optin_State::seal( self::new_credential( $current ) );
				if ( null === $token ) {
					return null;
				}
				return array_merge( $s, self::fresh(), array( 'state' => 'pending', 'credential' => $token ) );
			}
		);
		if ( ! is_array( $state ) || 'pending' !== $state['state'] ) {
			return false;
		}
		self::send();
		return true;
	}

	public static function handle_retry(): void {
		self::guard( 'emcp_lite_optin_retry' );
		self::send();
		self::done();
	}

	public static function handle_skip(): void {
		self::guard( 'emcp_lite_optin_skip' );
		$cancel = false;
		EMCP_Tools_Optin_State::transition(
			function ( array $s ) use ( &$cancel ) {
				if ( 'pending' === $s['state'] && EMCP_Tools_Optin_State::credential( $s ) ) {
					$cancel = true;
					return array_merge( $s, array( 'state' => 'cancelling', 'after' => 'skipped', 'retry_at' => 0, 'retry_step' => 0, 'attempt_at' => 0 ) );
				}
				if ( in_array( EMCP_Tools_Optin_State::effective( $s ), array( 'unset', 'pending', 'copy' ), true ) ) {
					return array_merge( $s, self::fresh(), array( 'state' => 'skipped', 'credential' => '' ) );
				}
				return null;
			}
		);
		if ( $cancel ) {
			EMCP_Tools_Optin_Maintenance::cancel_now();
		}
		self::done();
	}

	/**
	 * Send the pending opt-in (same request id on every retry) and apply the answer.
	 */
	public static function send(): void {
		$s    = EMCP_Tools_Optin_State::read();
		$cred = EMCP_Tools_Optin_State::credential( $s );
		if ( 'pending' !== $s['state'] || ! $cred ) {
			return;
		}
		$proof   = EMCP_Tools_Optin_Proof::issue();
		$inv     = EMCP_Tools_Optin_Inventory::build();
		$user    = wp_get_current_user();
		$name    = trim( get_user_meta( $user->ID, 'first_name', true ) . ' ' . get_user_meta( $user->ID, 'last_name', true ) );
		$payload = array_merge(
			$inv,
			array(
				'install_id'     => (string) $cred['install_id'],
				'request_id'     => (string) $cred['request_id'],
				'issued_at'      => (string) $cred['issued_at'],
				'admin_email'    => (string) $user->user_email,
				'admin_name'     => '' !== $name ? $name : (string) $user->display_name,
				'site_url'       => EMCP_Tools_Optin_State::installation_base(),
				'challenge_id'   => $proof['id'],
				'challenge_hash' => $proof['hash'],
			)
		);
		if ( '' !== (string) ( $cred['site_key'] ?? '' ) ) {
			$payload['site_key'] = (string) $cred['site_key'];
		}
		$fp   = EMCP_Tools_Optin_State::fingerprint( $cred );
		$res  = EMCP_Tools_Optin_Client::optin( $payload );
		$code = $res['code'];
		EMCP_Tools_Optin_Proof::forget( $proof['id'] );

		if ( 200 === $code && ! empty( $res['body']['site_id'] ) && ! empty( $res['body']['site_key'] ) ) {
			$body = $res['body'];
			$hash = EMCP_Tools_Optin_Inventory::hash( $inv );
			$now  = EMCP_Tools_Optin_State::now();
			EMCP_Tools_Optin_State::apply_response(
				$s['version'],
				$fp,
				function ( array $st ) use ( $cred, $body, $hash, $now ) {
					$token = EMCP_Tools_Optin_State::seal(
						array_merge(
							$cred,
							array(
								'site_id'  => (string) $body['site_id'],
								'site_key' => (string) $body['site_key'],
							)
						)
					);
					if ( null === $token ) {
						return null;
					}
					return array_merge(
						$st,
						self::fresh(),
						array(
							'state'             => 'allowed',
							'credential'        => $token,
							'verified'          => (bool) ( $body['verified'] ?? false ),
							'email_confirmed'   => false,
							'status_checked_at' => $now,
							'inv_hash'          => $hash,
							'seen_version'      => EMCP_TOOLS_VERSION,
							'last_sent_at'      => $now,
						)
					);
				}
			);
			return;
		}

		$refused = array(
			409 => 'site_registered',
			410 => 'expired',
			400 => 'invalid',
		);
		$error   = $refused[ $code ] ?? 'unreachable';
		EMCP_Tools_Optin_State::apply_response(
			$s['version'],
			$fp,
			function ( array $st ) use ( $error ) {
				if ( 'unreachable' === $error ) {
					$st['error'] = $error;
					return $st;
				}
				return array_merge( $st, self::fresh(), array( 'state' => 'unset', 'credential' => '', 'error' => $error ) );
			}
		);
	}

	/** A credential for a new request, keeping the install id (and key, for Reconnect) when usable. */
	public static function new_credential( ?array $current ): array {
		return array(
			'install_id' => $current ? (string) $current['install_id'] : EMCP_Tools_Optin_State::random_id(),
			'request_id' => EMCP_Tools_Optin_State::random_id(),
			'issued_at'  => gmdate( 'c', EMCP_Tools_Optin_State::now() ),
			'site_id'    => $current ? (string) ( $current['site_id'] ?? '' ) : '',
			'site_key'   => $current ? (string) ( $current['site_key'] ?? '' ) : '',
			'base'       => EMCP_Tools_Optin_State::installation_base(),
		);
	}

	/** Bookkeeping fields reset on any new choice. */
	public static function fresh(): array {
		return array(
			'after'             => '',
			'deletion'          => '',
			'retry_at'          => 0,
			'retry_step'        => 0,
			'attempt_at'        => 0,
			'inv_changed'       => false,
			'email_confirmed'   => null,
			'status_checked_at' => 0,
			'verified'          => null,
			'error'             => '',
		);
	}

	/** Nonce and capability for an action; stops the request otherwise. */
	public static function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change data sharing for this site.', 'emcp-lite' ), '', array( 'response' => 403 ) );
		}
	}

	/** Back to where the admin came from. */
	public static function done( string $page = 'emcp-tools' ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		if ( self::$exit ) {
			exit;
		}
	}
}
