<?php
/**
 * Requests from WordPress to emcptools.com. The browser never calls the website.
 *
 * Every request carries the user agent `EMCP-Lite/<version>` (WordPress's
 * default user agent includes the site URL) and no other identifying headers.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Optin_Client {

	const BASE = 'https://emcptools.com';

	/** The website origin; `EMCP_LITE_OPTIN_API` points a test site at a staging deploy. */
	public static function base(): string {
		$base = defined( 'EMCP_LITE_OPTIN_API' ) ? (string) EMCP_LITE_OPTIN_API : self::BASE;
		return rtrim( $base, '/' );
	}

	/** @return array{code:int, body:array} */
	public static function optin( array $payload ): array {
		return self::post( '/api/lite/optin', $payload, 5 );
	}

	/** @return array{code:int, body:array} */
	public static function cancel( string $install_id, string $request_id ): array {
		return self::post( '/api/lite/cancel', array( 'install_id' => $install_id, 'request_id' => $request_id ), 5 );
	}

	/**
	 * An authenticated action on this site's record: update, touch, status, resend or delete.
	 *
	 * @return array{code:int, body:array}
	 */
	public static function site( string $action, array $credential, array $fields = array() ): array {
		$body = array(
			'site_id'  => (string) ( $credential['site_id'] ?? '' ),
			'site_key' => (string) ( $credential['site_key'] ?? '' ),
			'action'   => $action,
		);
		if ( $fields ) {
			$body['fields'] = $fields;
		}
		return self::post( '/api/lite/refresh', $body, 'status' === $action ? 3 : 5 );
	}

	/** A deactivation reason, sent without waiting for the answer. */
	public static function feedback( array $body ): void {
		wp_remote_post(
			self::base() . '/api/lite/deactivation',
			self::args( $body, 1 ) + array( 'blocking' => false )
		);
	}

	/** @return array{code:int, body:array} code 0 on a transport error. */
	private static function post( string $path, array $body, int $timeout ): array {
		$res = wp_remote_post( self::base() . $path, self::args( $body, $timeout ) + array( 'blocking' => true ) );
		if ( is_wp_error( $res ) ) {
			return array( 'code' => 0, 'body' => array() );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $res ),
			'body' => is_array( $decoded ) ? $decoded : array(),
		);
	}

	private static function args( array $body, int $timeout ): array {
		return array(
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => 'EMCP-Lite/' . EMCP_TOOLS_VERSION,
			'headers'     => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'        => (string) wp_json_encode( $body ),
		);
	}
}
