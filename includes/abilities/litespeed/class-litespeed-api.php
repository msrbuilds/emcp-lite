<?php
/** Native page-cache inspection and bounded invalidation. */
defined( 'ABSPATH' ) || exit;
final class EMCP_Tools_Litespeed_Api {
	public static function active(): bool { return defined( 'LSCWP_V' ) && class_exists( 'LiteSpeed\\Conf' ); }
	public static function allowed(): bool { return current_user_can( 'manage_options' ); }
	public static function error( string $code, string $message ): WP_Error { return new WP_Error( $code, $message ); }
	public static function ready() {
		if ( ! self::active() ) { return self::error( 'plugin_inactive', 'Activate LiteSpeed Cache first.' ); }
		if ( '7.9.2' !== LSCWP_V || ! is_callable( array( 'LiteSpeed\\Purge', 'purge_all_lscache' ) ) ) { return self::error( 'unsupported_version', 'This LiteSpeed Cache version has not been qualified.' ); }
		if ( is_multisite() ) { return self::error( 'unsupported_environment', 'This adapter currently supports single sites.' ); }
		return true;
	}
	public static function valid_path( string $path ): bool {
		// Paths only. Reject parser ambiguity, encoded separators, controls and dot traversal.
		return strlen( $path ) <= 2048 && 1 === preg_match( '~^/(?!/)[^\\\\?#\x00-\x20\x7f]*$~D', $path )
			&& ! preg_match( '/%(?:2f|5c|2e|0[0-9a-f]|1[0-9a-f]|7f|25)/i', $path )
			&& ! preg_match( '~(?:^|/)\.{1,2}(?:/|$)~', $path )
			&& ! preg_match( '/%(?![0-9a-f]{2})/i', $path );
	}
	public static function read( string $operation, array $args ): array {
		$keys = array( 'cache', 'cache-ttl_pub', 'cache-ttl_priv', 'cache-ttl_frontpage', 'optm-css_min', 'optm-js_min', 'optm-html_min' );
		wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );
		foreach ( $keys as $key ) { wp_cache_delete( LiteSpeed\Conf::name( $key ), 'options' ); }
		$conf = LiteSpeed\Conf::cls(); $conf->load_options();
		$cli = defined( 'WP_CLI' ) && WP_CLI;
		$server = defined( 'LITESPEED_SERVER_TYPE' ) ? LITESPEED_SERVER_TYPE : 'LITESPEED_SERVER_NONE';
		$supported = ! $cli && in_array( $server, array( 'LITESPEED_SERVER_OLS', 'LITESPEED_SERVER_ENT', 'LITESPEED_SERVER_ADC' ), true ) && defined( 'LITESPEED_ALLOWED' ) && LITESPEED_ALLOWED;
		$settings = array(); foreach ( $keys as $key ) { $settings[ $key ] = str_contains( $key, 'ttl_' ) ? (int) $conf->conf( $key ) : (bool) $conf->conf( $key ); }
		return array( 'version' => LSCWP_V, 'server' => $cli ? 'unknown_cli' : $server, 'transport' => $cli ? 'cli' : 'http', 'server_supported' => $supported, 'purge_supported' => $supported && $settings['cache'], 'settings' => $settings, 'purge_note' => 'Purge requests require HTTP on a supported cache server. Verify a subsequent cache response to confirm invalidation.' );
	}
	public static function write( string $operation, array $args ) {
		if ( 'purge-path' === $operation && ! self::valid_path( $args['path'] ) ) { return self::error( 'invalid_path', 'Use one absolute site-relative path without an origin, query, fragment, controls or traversal.' ); }
		$lease = new EMCP_Tools_Lease(); $owner = wp_generate_uuid4();
		if ( ! $lease->acquire( 'litespeed_cache', $owner, 120 ) ) { return self::error( 'cache_busy', 'Another EMCP cache operation is running.' ); }
		try {
			if ( ! self::allowed() ) { return self::error( 'forbidden', 'Administrator access is required.' ); }
			$ready = self::ready(); if ( is_wp_error( $ready ) ) { return $ready; }
			$status = self::read( 'status', array() );
			if ( ! $status['purge_supported'] || headers_sent() ) { return self::error( 'unsupported_environment', 'Purge requires an HTTP request with writable headers on an enabled LiteSpeed cache server.' ); }
			if ( ! empty( $args['dry_run'] ) ) { return array( 'dry_run' => true, 'validated' => true ); }
			$id = EMCP_Tools_Change_Log::record( array( 'domain' => 'litespeed', 'action' => $operation, 'target' => 'purge-path' === $operation ? $args['path'] : 'page-cache', 'summary' => 'LiteSpeed page-cache purge attempt. Verify cache response headers for completion.', 'rollback' => array( 'type' => 'irreversible', 'reason' => 'Invalidated cached copies cannot be restored.' ) ) );
			if ( ! $id ) { return self::error( 'history_failed', 'Nothing purged because the audit record could not be saved.' ); }
			try {
				if ( 'purge-path' === $operation ) { $ok = LiteSpeed\Purge::cls()->purge_url( $args['path'], false, true ); if ( ! $ok ) { return new WP_Error( 'purge_failed', 'The native purge was refused.', array( 'change_id' => $id ) ); } }
				else { LiteSpeed\Purge::purge_all_lscache( 'EMCP confirmed page-cache purge' ); }
			} catch ( Throwable $error ) { return new WP_Error( 'partial_write', 'Purge outcome is uncertain after a native hook failure. Inspect cache headers before retrying.', array( 'change_id' => $id ) ); }
			return array( 'state' => 'purge_requested', 'scope' => 'purge-path' === $operation ? 'path_and_query_variants' : 'page_cache', 'change_id' => $id, 'undoable' => false );
		} finally { $lease->release( 'litespeed_cache', $owner ); }
	}
}
