<?php
/**
 * The Data sharing screen: the current state in plain words, the sharing
 * switch, and the Reconnect, Connect this site, Retry and Resend actions.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Data_Sharing_Screen {

	const PAGE = 'emcp-tools-data-sharing';

	public static function init(): void {
		foreach ( array( 'sharing_off', 'sharing_on', 'reconnect', 'connect_copy', 'resend' ) as $action ) {
			add_action( 'admin_post_emcp_lite_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/** One sentence describing the state. */
	public static function status_line( array $state ): string {
		switch ( EMCP_Tools_Optin_State::effective( $state ) ) {
			case 'allowed':
				return true === $state['email_confirmed']
					? __( 'Sharing on. Confirmed: your email address is confirmed.', 'emcp-lite' )
					: __( 'Sharing on. Waiting for email confirmation: open the email from EMCP and press Confirm.', 'emcp-lite' );
			case 'pending':
				return __( 'Waiting for emcptools.com. Your choice is saved: Retry sends it again.', 'emcp-lite' );
			case 'cancelling':
				return __( 'Cancelling. EMCP Lite finishes cancelling the request on a later admin visit.', 'emcp-lite' );
			case 'opted_out':
				switch ( (string) $state['deletion'] ) {
					case 'pending':
						return __( 'Sharing off. Deletion pending: EMCP Lite asks emcptools.com to delete this site\'s record again on a later admin visit.', 'emcp-lite' );
					case 'confirmed':
						return __( 'Sharing off. Deletion confirmed: emcptools.com deleted this site\'s record.', 'emcp-lite' );
					case 'retired':
						return __( 'Sharing off. The record now belongs to a newer connection of this site, so nothing more is sent from here.', 'emcp-lite' );
				}
				return __( 'Sharing off.', 'emcp-lite' );
			case 'expired':
				return __( 'Sharing stopped: emcptools.com no longer has a record for this site. Reconnect to share again.', 'emcp-lite' );
			case 'revoked':
				return __( 'Sharing stopped: this site\'s key is no longer accepted, usually because another installation took over the site address. Reconnect to share again.', 'emcp-lite' );
			case 'copy':
				/* translators: %s: the site address the stored connection belongs to. */
				return sprintf( __( 'This looks like a copy of %s. Nothing is sent from this copy. Connect this site to share its own details.', 'emcp-lite' ), (string) EMCP_Tools_Optin_State::credential_base( $state ) );
			case 'skipped':
				return __( 'Not sharing. You chose to skip.', 'emcp-lite' );
		}
		return __( 'Not sharing. No choice made yet.', 'emcp-lite' );
	}

	/** Ask whether the email has been confirmed, at most once a minute. */
	public static function check_status(): void {
		$s    = EMCP_Tools_Optin_State::read();
		$now  = EMCP_Tools_Optin_State::now();
		$cred = EMCP_Tools_Optin_State::credential( $s );
		if ( 'allowed' !== $s['state'] || true === $s['email_confirmed'] || ! $cred || $now - (int) $s['status_checked_at'] < MINUTE_IN_SECONDS ) {
			return;
		}
		$fp      = EMCP_Tools_Optin_State::fingerprint( $cred );
		$stamped = false;
		$started = EMCP_Tools_Optin_State::transition(
			function ( array $st ) use ( $now, $s, $fp, &$stamped ) {
				if ( $st['version'] !== $s['version'] || EMCP_Tools_Optin_State::fingerprint( EMCP_Tools_Optin_State::credential( $st ) ) !== $fp ) {
					return null;
				}
				$stamped                 = true;
				$st['status_checked_at'] = $now;
				return $st;
			}
		);
		if ( ! $stamped || ! is_array( $started ) ) {
			return;
		}
		self::apply_site_answer( EMCP_Tools_Optin_Client::site( 'status', $cred ), $fp, '', $started['version'] );
	}

	public static function handle_sharing_off(): void {
		EMCP_Tools_Optin_Screen::guard( 'emcp_lite_sharing_off' );
		EMCP_Tools_Optin_Maintenance::opt_out();
		EMCP_Tools_Optin_Screen::done( self::PAGE );
	}

	public static function handle_sharing_on(): void {
		EMCP_Tools_Optin_Screen::guard( 'emcp_lite_sharing_on' );
		EMCP_Tools_Optin_Screen::start( false );
		EMCP_Tools_Optin_Screen::done( self::PAGE, EMCP_Tools_Optin_Screen::busy_args() );
	}

	public static function handle_reconnect(): void {
		EMCP_Tools_Optin_Screen::guard( 'emcp_lite_reconnect' );
		EMCP_Tools_Optin_Screen::start( true );
		EMCP_Tools_Optin_Screen::done( self::PAGE, EMCP_Tools_Optin_Screen::busy_args() );
	}

	public static function handle_connect_copy(): void {
		EMCP_Tools_Optin_Screen::guard( 'emcp_lite_connect_copy' );
		EMCP_Tools_Optin_Screen::start( false );
		EMCP_Tools_Optin_Screen::done( self::PAGE, EMCP_Tools_Optin_Screen::busy_args() );
	}

	public static function handle_resend(): void {
		EMCP_Tools_Optin_Screen::guard( 'emcp_lite_resend' );
		$s    = EMCP_Tools_Optin_State::read();
		$cred = EMCP_Tools_Optin_State::credential( $s );
		if ( 'allowed' === $s['state'] && true !== $s['email_confirmed'] && $cred ) {
			self::apply_site_answer( EMCP_Tools_Optin_Client::site( 'resend', $cred ), EMCP_Tools_Optin_State::fingerprint( $cred ), 'resent', $s['version'] );
		}
		EMCP_Tools_Optin_Screen::done( self::PAGE );
	}

	/** Apply a status or resend answer to the current state. */
	private static function apply_site_answer( array $res, string $fp, string $note, int $version ): void {
		$code = $res['code'];
		$now  = EMCP_Tools_Optin_State::now();
		EMCP_Tools_Optin_State::apply_response(
			$version,
			$fp,
			function ( array $st ) use ( $code, $res, $now, $note ) {
				if ( 'allowed' !== $st['state'] ) {
					return null;
				}
				if ( $code >= 200 && $code < 300 ) {
					if ( is_bool( $res['body']['email_confirmed'] ?? null ) ) {
						$st['email_confirmed'] = $res['body']['email_confirmed'];
					}
					$st['status_checked_at'] = $now;
					$st['error']             = $note;
					return $st;
				}
				if ( 401 === $code || 403 === $code ) {
					$st['state'] = 'revoked';
					return $st;
				}
				if ( 404 === $code ) {
					$st['state'] = 'expired';
					return $st;
				}
				$st['error'] = 'unreachable';
				return $st;
			}
		);
	}

	/** Print the screen (the frame is already around it). */
	public static function render(): void {
		self::check_status();
		$emcp_state = EMCP_Tools_Optin_State::read();
		include EMCP_TOOLS_DIR . 'includes/admin/views/data-sharing.php';
	}
}
