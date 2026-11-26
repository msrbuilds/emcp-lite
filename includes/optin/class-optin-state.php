<?php
/**
 * Opt-in consent state and its encrypted credential.
 *
 * One option, `emcp_lite_optin`, holds the plain state, a version counter,
 * retry times and the last inventory hash, plus one credential encrypted with
 * EMCP_Tools_Secret and bound to this installation's base URL. A staging copy
 * made with search-replace cannot rewrite the URL inside the encrypted value,
 * so it never matches and the copy never sends anything with it.
 *
 * Every read-check-write runs under the `emcp-lite-optin` lease, never held
 * across an HTTP request. A response is applied only while the version and the
 * credential it was sent with are both still current.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Optin_State {

	const OPTION = 'emcp_lite_optin';
	const LEASE  = 'emcp-lite-optin';

	/** States a site can be in. */
	const STATES = array( 'unset', 'pending', 'cancelling', 'allowed', 'skipped', 'opted_out', 'revoked', 'expired' );

	/** @var int Lease attempts before giving up. */
	public static $lease_retries = 20;

	/** @var int Microseconds between lease attempts. */
	public static $lease_wait_us = 100000;

	/** @var callable|null Test seam for the cipher. */
	private static $encrypt = null;

	/** @var callable|null Test seam for the cipher. */
	private static $decrypt = null;

	/** Default shape of the stored option. */
	public static function defaults(): array {
		return array(
			'state'             => 'unset',
			'version'           => 0,
			'credential'        => '',
			'after'             => '',
			'deletion'          => '',
			'retry_at'          => 0,
			'retry_step'        => 0,
			'attempt_at'        => 0,
			'inv_hash'          => '',
			'inv_changed'       => false,
			'last_sent_at'      => 0,
			'touch_at'          => 0,
			'email_confirmed'   => null,
			'status_checked_at' => 0,
			'verified'          => null,
			'error'             => '',
		);
	}

	/** The stored state, read past the object cache. */
	public static function read(): array {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$s   = array_merge( self::defaults(), array_intersect_key( $raw, self::defaults() ) );
		if ( ! in_array( $s['state'], self::STATES, true ) ) {
			$s['state'] = 'unset';
		}
		$s['version'] = (int) $s['version'];
		return $s;
	}

	/**
	 * Read, change and write the state under the lease.
	 *
	 * @param callable $fn Receives the fresh state; returns the new state, or null to write nothing.
	 * @return array|false The state after the call, or false when the lease could not be taken.
	 */
	public static function transition( callable $fn ) {
		$lease = new EMCP_Tools_Lease();
		$owner = 'optin-' . bin2hex( random_bytes( 8 ) );
		$got   = false;
		for ( $i = 0; $i < max( 1, self::$lease_retries ); $i++ ) {
			if ( $lease->acquire( self::LEASE, $owner, 30 ) ) {
				$got = true;
				break;
			}
			if ( self::$lease_wait_us > 0 ) {
				usleep( self::$lease_wait_us );
			}
		}
		if ( ! $got ) {
			return false;
		}
		try {
			$state = self::read();
			$next  = $fn( $state );
			if ( ! is_array( $next ) ) {
				return $state;
			}
			$next            = array_merge( self::defaults(), array_intersect_key( $next, self::defaults() ) );
			$next['version'] = $state['version'] + 1;
			update_option( self::OPTION, $next, false );
			return $next;
		} finally {
			$lease->release( self::LEASE, $owner );
		}
	}

	/**
	 * Apply an answer only if nothing changed since the request was sent.
	 *
	 * @param int      $version     The state version the request was sent from.
	 * @param string   $fingerprint fingerprint() of the credential it was sent with.
	 * @param callable $fn          As for transition().
	 * @return bool Whether the change was written.
	 */
	public static function apply_response( int $version, string $fingerprint, callable $fn ): bool {
		$applied = false;
		self::transition(
			function ( array $s ) use ( $version, $fingerprint, $fn, &$applied ) {
				if ( $s['version'] !== $version || self::fingerprint( self::credential( $s ) ) !== $fingerprint ) {
					return null;
				}
				$next    = $fn( $s );
				$applied = is_array( $next );
				return $next;
			}
		);
		return $applied;
	}

	/** This installation's base URL, in the form Lite Cloud binds to. */
	public static function installation_base(): string {
		if ( class_exists( 'EMCP_Tools_Cloud' ) ) {
			return EMCP_Tools_Cloud::installation_base();
		}
		return rtrim( (string) home_url(), '/' );
	}

	/** Test seam: replace the cipher (null restores EMCP_Tools_Secret). */
	public static function use_cipher( ?callable $encrypt, ?callable $decrypt ): void {
		self::$encrypt = $encrypt;
		self::$decrypt = $decrypt;
	}

	private static function encrypt( string $plain ): string {
		return self::$encrypt ? (string) call_user_func( self::$encrypt, $plain ) : EMCP_Tools_Secret::encrypt( $plain );
	}

	private static function decrypt( string $token ): string {
		return self::$decrypt ? (string) call_user_func( self::$decrypt, $token ) : EMCP_Tools_Secret::decrypt( $token );
	}

	/**
	 * Encrypt a credential and prove the result is real ciphertext that decrypts back.
	 *
	 * @return string|null The token, or null when this server cannot encrypt.
	 */
	public static function seal( array $credential ): ?string {
		$plain = (string) wp_json_encode( $credential );
		$token = self::encrypt( $plain );
		if ( ! EMCP_Tools_Secret::is_encrypted( $token ) || self::decrypt( $token ) !== $plain ) {
			return null;
		}
		return $token;
	}

	/** Whether sharing can work here at all. */
	public static function encryption_available(): bool {
		return null !== self::seal( array( 'probe' => bin2hex( random_bytes( 4 ) ) ) );
	}

	/** The decrypted credential without the base check, or null. */
	private static function open( array $state ): ?array {
		$token = (string) ( $state['credential'] ?? '' );
		if ( '' === $token || ! EMCP_Tools_Secret::is_encrypted( $token ) ) {
			return null;
		}
		$plain = self::decrypt( $token );
		$cred  = '' === $plain ? null : json_decode( $plain, true );
		return is_array( $cred ) ? $cred : null;
	}

	/** The credential, or null if missing, plaintext, undecryptable or issued for another base. */
	public static function credential( array $state ): ?array {
		$cred = self::open( $state );
		if ( ! $cred || ( $cred['base'] ?? '' ) !== self::installation_base() ) {
			return null;
		}
		return $cred;
	}

	/** The base URL a stored credential was issued for (for the copy message), or null. */
	public static function credential_base( array $state ): ?string {
		$cred = self::open( $state );
		return $cred ? (string) ( $cred['base'] ?? '' ) : null;
	}

	/** Whether a credential is stored but cannot be used here. */
	public static function is_copy( array $state ): bool {
		return '' !== (string) ( $state['credential'] ?? '' ) && null === self::credential( $state );
	}

	/** The state as the screens show it: `copy` wins whenever the credential belongs elsewhere. */
	public static function effective( array $state ): string {
		return self::is_copy( $state ) ? 'copy' : (string) $state['state'];
	}

	/** A stable digest of a credential, '' for none. */
	public static function fingerprint( ?array $credential ): string {
		if ( ! $credential ) {
			return '';
		}
		ksort( $credential );
		return hash( 'sha256', (string) wp_json_encode( $credential ) );
	}

	/** The state after the website acknowledged a cancel. */
	public static function cancel_acknowledged( array $state ): array {
		$state['state']      = 'skipped' === ( $state['after'] ?? '' ) ? 'skipped' : 'unset';
		$state['after']      = '';
		$state['credential'] = '';
		$state['retry_at']   = 0;
		$state['retry_step'] = 0;
		$state['error']      = '';
		return $state;
	}

	/** A random identifier the website accepts (16 to 64 of A-Z a-z 0-9 _ -). */
	public static function random_id(): string {
		return rtrim( strtr( base64_encode( random_bytes( 24 ) ), '+/', '-_' ), '=' );
	}
}
