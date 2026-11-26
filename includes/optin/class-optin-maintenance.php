<?php
/**
 * Remote upkeep on admin visits: cancel and delete retries, inventory refresh
 * and the monthly keep-alive. No cron.
 *
 * At most one remote call per eligible request and per 10 minutes, each
 * blocking for at most 5 seconds. Never on AJAX, REST, cron, the network admin,
 * or a Plugins screen request that carries an action, so deactivation is never
 * delayed. Answers are applied only while the state they were sent from is
 * still current (EMCP_Tools_Optin_State::apply_response()).
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Optin_Maintenance {

	const MIN_GAP    = 600;
	const KEEP_ALIVE = 30 * DAY_IN_SECONDS;
	const PENDING    = 7 * DAY_IN_SECONDS;
	const BACKOFF    = array( 3600, 21600, 86400 );

	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'run' ), 99 );
	}

	/** Whether this request may spend up to 5 seconds on a remote call. */
	public static function eligible_request(): bool {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return false;
		}
		if ( ! is_admin() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$page = (string) ( $GLOBALS['pagenow'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only to stay out of the way.
		if ( 'plugins.php' === $page && ( isset( $_REQUEST['action'] ) || isset( $_REQUEST['action2'] ) ) ) {
			return false;
		}
		return true;
	}

	/** One unit of upkeep, if anything is due. */
	public static function run(): void {
		if ( ! self::eligible_request() || EMCP_Tools_Optin_Inventory::marked_this_request() ) {
			return;
		}
		$s   = EMCP_Tools_Optin_State::read();
		$now = EMCP_Tools_Optin_State::now();
		if ( $now - (int) $s['attempt_at'] < self::MIN_GAP ) {
			return;
		}
		switch ( EMCP_Tools_Optin_State::effective( $s ) ) {
			case 'pending':
				self::expire_pending( $s, $now );
				return;
			case 'cancelling':
				if ( $now >= (int) $s['retry_at'] ) {
					self::cancel_now();
				}
				return;
			case 'opted_out':
				if ( 'pending' === $s['deletion'] && $now >= (int) $s['retry_at'] ) {
					self::delete_now();
				}
				return;
			case 'allowed':
				self::refresh( $s, $now );
				return;
		}
	}

	/** A pending request reaching 7 days is cancelled (then the prompt returns), never dropped. */
	private static function expire_pending( array $s, int $now ): void {
		$cred   = EMCP_Tools_Optin_State::credential( $s );
		$issued = $cred ? strtotime( (string) ( $cred['issued_at'] ?? '' ) ) : false;
		if ( ! $cred || ! $issued || $now - $issued < self::PENDING ) {
			return;
		}
		$fp    = EMCP_Tools_Optin_State::fingerprint( $cred );
		$moved = EMCP_Tools_Optin_State::apply_response(
			$s['version'],
			$fp,
			function ( array $st ) {
				$st['state']      = 'cancelling';
				$st['after']      = 'prompt';
				$st['retry_at']   = 0;
				$st['retry_step'] = 0;
				return $st;
			}
		);
		if ( $moved ) {
			self::cancel_now();
		}
	}

	/** Send the cancel for a `cancelling` request; acknowledge or back off. */
	public static function cancel_now(): void {
		$s = self::begin( fn( $st ) => 'cancelling' === $st['state'] );
		if ( ! $s ) {
			return;
		}
		$cred = EMCP_Tools_Optin_State::credential( $s );
		$res  = EMCP_Tools_Optin_Client::cancel( (string) $cred['install_id'], (string) $cred['request_id'] );
		$fp   = EMCP_Tools_Optin_State::fingerprint( $cred );
		if ( $res['code'] >= 200 && $res['code'] < 300 ) {
			EMCP_Tools_Optin_State::apply_response( $s['version'], $fp, array( 'EMCP_Tools_Optin_State', 'cancel_acknowledged' ) );
			return;
		}
		EMCP_Tools_Optin_State::apply_response( $s['version'], $fp, array( __CLASS__, 'backoff' ) );
	}

	/** Stop sharing at once, then ask the website to delete the record. */
	public static function opt_out(): void {
		$pending = false;
		$cancel  = false;
		EMCP_Tools_Optin_State::transition(
			function ( array $s ) use ( &$pending, &$cancel ) {
				// An unresolved request may already have a saved row: cancel it rather than drop it.
				if ( in_array( $s['state'], array( 'pending', 'cancelling' ), true ) && EMCP_Tools_Optin_State::credential( $s ) ) {
					$cancel = true;
					return array_merge( $s, array( 'state' => 'cancelling', 'after' => 'skipped', 'retry_at' => 0, 'retry_step' => 0, 'attempt_at' => 0 ) );
				}
				$cred                = EMCP_Tools_Optin_State::credential( $s );
				$pending             = 'allowed' === $s['state'] && $cred && '' !== (string) ( $cred['site_key'] ?? '' );
				$s['state']          = 'opted_out';
				$s['deletion']       = $pending ? 'pending' : '';
				$s['credential']     = $pending ? $s['credential'] : '';
				$s['retry_at']       = 0;
				$s['retry_step']     = 0;
				$s['attempt_at']     = 0;
				$s['inv_changed']    = false;
				$s['email_confirmed'] = null;
				return $s;
			}
		);
		if ( $cancel ) {
			self::cancel_now();
		} elseif ( $pending ) {
			self::delete_now();
		}
	}

	/** Send the pending delete; confirm, retire or back off. */
	public static function delete_now(): void {
		$s = self::begin( fn( $st ) => 'opted_out' === $st['state'] && 'pending' === $st['deletion'] );
		if ( ! $s ) {
			return;
		}
		$cred = EMCP_Tools_Optin_State::credential( $s );
		$res  = EMCP_Tools_Optin_Client::site( 'delete', $cred );
		$fp   = EMCP_Tools_Optin_State::fingerprint( $cred );
		$code = $res['code'];
		// Only 401 is final (a later claim retired the key); 403 can be a firewall challenge.
		if ( ( $code >= 200 && $code < 300 ) || 404 === $code || 401 === $code ) {
			$outcome = 404 === $code || ( $code >= 200 && $code < 300 ) ? 'confirmed' : 'retired';
			EMCP_Tools_Optin_State::apply_response(
				$s['version'],
				$fp,
				function ( array $st ) use ( $outcome ) {
					$st['deletion']   = $outcome;
					$st['credential'] = '';
					$st['retry_at']   = 0;
					$st['retry_step'] = 0;
					return $st;
				}
			);
			return;
		}
		EMCP_Tools_Optin_State::apply_response( $s['version'], $fp, array( __CLASS__, 'backoff' ) );
	}

	/** Send changed installation fields, or the monthly keep-alive. */
	private static function refresh( array $s, int $now ): void {
		$cred = EMCP_Tools_Optin_State::credential( $s );
		if ( ! $cred || '' === (string) ( $cred['site_key'] ?? '' ) || $now < (int) $s['retry_at'] ) {
			return;
		}
		$changed = $s['inv_changed'] || EMCP_TOOLS_VERSION !== $s['seen_version'];
		$keep    = $now - (int) $s['last_sent_at'] >= self::KEEP_ALIVE;
		if ( ! $changed && ! $keep ) {
			return;
		}
		$inv  = EMCP_Tools_Optin_Inventory::build();
		$hash = EMCP_Tools_Optin_Inventory::hash( $inv );
		$fp   = EMCP_Tools_Optin_State::fingerprint( $cred );
		if ( ! $keep && $hash === $s['inv_hash'] ) {
			EMCP_Tools_Optin_State::apply_response(
				$s['version'],
				$fp,
				function ( array $st ) {
					$st['inv_changed']  = false;
					$st['seen_version'] = EMCP_TOOLS_VERSION;
					return $st;
				}
			);
			return;
		}
		$action  = $hash !== $s['inv_hash'] ? 'update' : 'touch';
		$started = self::begin( fn( $st ) => 'allowed' === $st['state'] && EMCP_Tools_Optin_State::fingerprint( EMCP_Tools_Optin_State::credential( $st ) ) === $fp );
		if ( ! $started ) {
			return;
		}
		$res  = EMCP_Tools_Optin_Client::site( $action, $cred, 'update' === $action ? $inv : array() );
		$code = $res['code'];
		if ( $code >= 200 && $code < 300 ) {
			$confirmed = $res['body']['email_confirmed'] ?? null;
			EMCP_Tools_Optin_State::apply_response(
				$started['version'],
				$fp,
				function ( array $st ) use ( $hash, $now, $confirmed ) {
					$st['inv_hash']     = $hash;
					$st['inv_changed']  = false;
					$st['seen_version'] = EMCP_TOOLS_VERSION;
					$st['last_sent_at'] = $now;
					$st['retry_at']     = 0;
					$st['retry_step']   = 0;
					$st['error']        = '';
					if ( is_bool( $confirmed ) ) {
						$st['email_confirmed']   = $confirmed;
						$st['status_checked_at'] = $now;
					}
					return $st;
				}
			);
			return;
		}
		if ( 401 === $code || 403 === $code || 404 === $code ) {
			$state = 404 === $code ? 'expired' : 'revoked';
			EMCP_Tools_Optin_State::apply_response(
				$started['version'],
				$fp,
				function ( array $st ) use ( $state ) {
					$st['state'] = $state;
					return $st;
				}
			);
			return;
		}
		EMCP_Tools_Optin_State::apply_response( $started['version'], $fp, array( __CLASS__, 'backoff' ) );
	}

	/** Schedule the next try: 1 hour, 6 hours, then every 24 hours. */
	public static function backoff( array $s ): array {
		$step            = min( (int) $s['retry_step'], count( self::BACKOFF ) - 1 );
		$s['retry_at']   = EMCP_Tools_Optin_State::now() + self::BACKOFF[ $step ];
		$s['retry_step'] = (int) $s['retry_step'] + 1;
		$s['error']      = 'unreachable';
		return $s;
	}

	/**
	 * Stamp the attempt under the lease when the guard still holds and a usable credential exists.
	 *
	 * @return array|null The state the request is sent from.
	 */
	private static function begin( callable $guard ): ?array {
		$ok    = false;
		$state = EMCP_Tools_Optin_State::transition(
			function ( array $s ) use ( $guard, &$ok ) {
				if ( ! $guard( $s ) || ! EMCP_Tools_Optin_State::credential( $s ) ) {
					return null;
				}
				$ok              = true;
				$s['attempt_at'] = EMCP_Tools_Optin_State::now();
				return $s;
			}
		);
		return $ok && is_array( $state ) ? $state : null;
	}
}
