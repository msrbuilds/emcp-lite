<?php
/** Bounded, credential-free native WP Mail SMTP access. */
defined( 'ABSPATH' ) || exit;
final class EMCP_Tools_Wp_Mail_Smtp_Api {
	public static function active(): bool { return function_exists( 'wp_mail_smtp' ) && defined( 'WPMS_PLUGIN_VER' ); }
	public static function allowed(): bool { return current_user_can( 'manage_options' ) && ( ! self::active() || current_user_can( wp_mail_smtp()->get_capability_manage_options() ) ); }
	public static function error( string $code, string $message ): WP_Error { return new WP_Error( $code, $message ); }
	public static function ready() {
		if ( ! self::active() ) { return self::error( 'plugin_inactive', 'Activate WP Mail SMTP first.' ); }
		if ( '4.10.3' !== WPMS_PLUGIN_VER || ! class_exists( 'WPMailSMTP\TestEmail\TestEmail' ) ) { return self::error( 'unsupported_version', 'This WP Mail SMTP version has not been qualified.' ); }
		if ( is_multisite() ) { return self::error( 'unsupported_environment', 'This adapter currently supports single-site mail configuration.' ); }
		return true;
	}
	private static function fresh_options() {
		wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( WPMailSMTP\Options::META_KEY, 'options' );
		// Qualified native cache seam. Reload observers without rewriting options or credentials.
		static $reader;
		if ( ! $reader ) { $reader = new class() extends WPMailSMTP\Options {
			public function refresh(): void { foreach ( static::$update_observers as $observer ) { $observer->populate_options(); } }
		}; }
		$reader->refresh(); return WPMailSMTP\Options::init();
	}
	public static function receipt_key( string $id ): string { return 'emcp_wpms_test_' . hash( 'sha256', strtolower( $id ) ); }
	public static function read( string $operation, array $args ) {
		if ( 'test-status' === $operation ) {
			$key = self::receipt_key( $args['request_id'] ); wp_cache_delete( $key, 'options' );
			$row = get_option( $key );
			if ( ! is_array( $row ) || (int) $row['actor'] !== get_current_user_id() ) { return self::error( 'not_found', 'No test receipt exists for this actor and request.' ); }
			return self::public_receipt( $row );
		}
		$options = self::fresh_options();
		$mailer = (string) $options->get( 'mail', 'mailer' );
		return array( 'version' => WPMS_PLUGIN_VER, 'mailer' => sanitize_key( $mailer ), 'smtp_authentication' => 'smtp' === $mailer ? (bool) $options->get( 'smtp', 'auth' ) : null, 'test_email_supported' => true, 'credentials_exposed' => false, 'delivery_note' => 'A successful test means transport acceptance. Verify receipt separately.' );
	}
	public static function public_receipt( array $row ): array { return array_intersect_key( $row, array_flip( array( 'request_id', 'state', 'change_id', 'created_at' ) ) ); }
	public static function write( string $operation, array $args ) {
		if ( ! is_email( $args['recipient'] ) || preg_match( '/[\r\n]/', $args['recipient'] ) ) { return self::error( 'invalid_recipient', 'Provide one valid email address.' ); }
		if ( function_exists( 'fluentMailGetSettings' ) ) { return self::error( 'competing_mailer', 'Deactivate the competing FluentSMTP plugin before sending through WP Mail SMTP.' ); }
		if ( ! empty( $args['dry_run'] ) ) { return array( 'dry_run' => true, 'validated' => true ); }
		$lease = new EMCP_Tools_Lease(); $owner = wp_generate_uuid4();
		if ( ! $lease->acquire( 'wpms_send', $owner, 300 ) ) { return self::error( 'send_busy', 'Another test send is running.' ); }
		try {
			if ( ! self::allowed() ) { return self::error( 'forbidden', 'Native mail management permission is required.' ); }
			self::fresh_options();
			$key = self::receipt_key( $args['request_id'] ); wp_cache_delete( $key, 'options' );
			$hash = hash( 'sha256', get_current_user_id() . '|' . $args['recipient'] );
			$existing = get_option( $key );
			if ( is_array( $existing ) ) { return hash_equals( $existing['hash'], $hash ) ? self::public_receipt( $existing ) : self::error( 'request_conflict', 'This request ID is already bound to another input or actor.' ); }
			$row = array( 'request_id' => strtolower( $args['request_id'] ), 'actor' => get_current_user_id(), 'hash' => $hash, 'state' => 'unknown', 'created_at' => time(), 'change_id' => '' );
			if ( ! add_option( $key, $row, '', false ) ) { return self::error( 'reservation_failed', 'Could not reserve this request. Nothing sent.' ); }
			$row['change_id'] = EMCP_Tools_Change_Log::record( array( 'domain' => 'wp-mail-smtp', 'action' => 'send-test', 'target' => $row['request_id'], 'summary' => 'WP Mail SMTP test attempt. Inspect its receipt for the outcome.', 'rollback' => array( 'type' => 'irreversible', 'reason' => 'A mail attempt cannot be undone or automatically replayed.' ) ) );
			if ( '' === $row['change_id'] ) { $row['state'] = 'not_sent'; update_option( $key, $row, false ); return self::error( 'history_failed', 'Nothing sent because the audit record could not be saved.' ); }
			update_option( $key, $row, false );
			$intercepted = false;
			$observer = static function ( $result ) use ( &$intercepted ) { if ( null !== $result ) { $intercepted = true; } return $result; };
			add_filter( 'pre_wp_mail', $observer, PHP_INT_MAX );
			try {
				$result = ( new WPMailSMTP\TestEmail\TestEmail() )->as_html( false )->with_domain_check( false )->send( $args['recipient'] );
				$row['state'] = $intercepted ? 'intercepted' : ( 1 === $result ? 'accepted' : 'failed' );
			} catch ( Throwable $error ) { $row['state'] = 'unknown'; }
			finally { remove_filter( 'pre_wp_mail', $observer, PHP_INT_MAX ); }
			// A failure here leaves the durable unknown receipt and prevents duplicate delivery.
			try {
				update_option( $key, $row, false );
				wp_cache_delete( $key, 'options' );
				if ( get_option( $key ) !== $row ) { $row['state'] = 'unknown'; }
			} catch ( Throwable $error ) { $row['state'] = 'unknown'; }
			return self::public_receipt( $row );
		} finally { $lease->release( 'wpms_send', $owner ); }
	}
}
