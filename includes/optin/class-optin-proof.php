<?php
/**
 * Site-control proof for an opt-in.
 *
 * Before Allow is sent, Lite stores a one-time random challenge for 10 minutes
 * and sends only its SHA-256. emcptools.com then reads it back from this public,
 * read-only route (HTTPS sites only); it answers once and forgets the challenge.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Optin_Proof {

	const TRANSIENT = 'emcp_lite_optin_proof_';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_rest_route(
			'emcp-lite/v1',
			'/optin-proof',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'callback'            => function ( $request ) {
					return rest_ensure_response( self::respond( (string) $request->get_param( 'id' ) ) );
				},
			)
		);
	}

	/**
	 * Create a challenge.
	 *
	 * @return array{id:string, hash:string}
	 */
	public static function issue(): array {
		$id        = EMCP_Tools_Optin_State::random_id();
		$challenge = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		set_transient( self::TRANSIENT . $id, $challenge, 10 * 60 );
		return array(
			'id'   => $id,
			'hash' => hash( 'sha256', $challenge ),
		);
	}

	public static function forget( string $id ): void {
		delete_transient( self::TRANSIENT . $id );
	}

	/**
	 * The challenge for an id, once.
	 *
	 * @return array|WP_Error
	 */
	public static function respond( string $id ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $id ) ) {
			return new WP_Error( 'emcp_lite_no_proof', 'Not found.', array( 'status' => 404 ) );
		}
		$challenge = get_transient( self::TRANSIENT . $id );
		if ( ! is_string( $challenge ) || '' === $challenge ) {
			return new WP_Error( 'emcp_lite_no_proof', 'Not found.', array( 'status' => 404 ) );
		}
		delete_transient( self::TRANSIENT . $id );
		return array(
			'id'        => $id,
			'challenge' => $challenge,
		);
	}
}
