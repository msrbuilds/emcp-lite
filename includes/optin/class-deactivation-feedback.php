<?php
/**
 * The deactivation feedback popup on the Plugins screen.
 *
 * The browser never contacts emcptools.com: it posts to an admin-ajax action
 * here, which validates the fields and relays them without waiting. The reason
 * is linked to this site only while it shares its details, never from the
 * network Plugins screen and never from a copy.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Deactivation_Feedback {

	const NONCE   = 'emcp_lite_deactivation_feedback';
	const REASONS = array( 'not_needed', 'better_plugin', 'broke_site', 'not_working', 'missing_feature', 'temporary', 'other' );

	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::NONCE, array( __CLASS__, 'ajax' ) );
	}

	public static function enqueue( $hook = '' ): void {
		if ( 'plugins.php' !== $hook || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		wp_enqueue_style( 'emcp-lite-deactivation', EMCP_TOOLS_URL . 'assets/css/deactivation-feedback.css', array(), EMCP_TOOLS_VERSION );
		wp_enqueue_script( 'emcp-lite-deactivation', EMCP_TOOLS_URL . 'assets/js/deactivation-feedback.js', array(), EMCP_TOOLS_VERSION, true );
		$network = is_network_admin();
		wp_localize_script(
			'emcp-lite-deactivation',
			'emcpLiteDeactivation',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'plugin'   => EMCP_TOOLS_BASENAME,
				'network'  => $network ? '1' : '',
				'linked'   => ! $network && self::linkable() ? '1' : '',
				'reasons'  => self::reason_labels(),
				'strings'  => array(
					'title'      => __( 'Quick feedback', 'emcp-lite' ),
					'intro'      => __( 'If you have a moment, tell us why you are deactivating EMCP Lite.', 'emcp-lite' ),
					'detail'     => __( 'Which plugin?', 'emcp-lite' ),
					'comment'    => __( 'Anything else? (optional)', 'emcp-lite' ),
					'sends'      => __( 'Sends your reason and comment, with the EMCP Lite, WordPress and PHP versions.', 'emcp-lite' ),
					'sendsId'    => __( 'Sends your reason and comment, with the EMCP Lite, WordPress and PHP versions, and this site\'s EMCP id.', 'emcp-lite' ),
					'submit'     => __( 'Submit & deactivate', 'emcp-lite' ),
					'skip'       => __( 'Skip & deactivate', 'emcp-lite' ),
					'close'      => __( 'Close without deactivating', 'emcp-lite' ),
					'legend'     => __( 'Reason for deactivating', 'emcp-lite' ),
				),
			)
		);
	}

	/** @return array<string,string> */
	public static function reason_labels(): array {
		return array(
			'not_needed'      => __( 'No longer needed', 'emcp-lite' ),
			'better_plugin'   => __( 'Found a better plugin', 'emcp-lite' ),
			'broke_site'      => __( 'Broke my site or conflicted', 'emcp-lite' ),
			'not_working'     => __( 'Couldn\'t get it working', 'emcp-lite' ),
			'missing_feature' => __( 'Missing a feature', 'emcp-lite' ),
			'temporary'       => __( 'Temporary deactivation', 'emcp-lite' ),
			'other'           => __( 'Other', 'emcp-lite' ),
		);
	}

	/** Whether a reason from this site may carry its id and key. */
	private static function linkable(): bool {
		$s    = EMCP_Tools_Optin_State::read();
		$cred = EMCP_Tools_Optin_State::credential( $s );
		return 'allowed' === $s['state'] && $cred && '' !== (string) ( $cred['site_key'] ?? '' );
	}

	/**
	 * Validate the posted fields into the request body.
	 *
	 * @return array|WP_Error
	 */
	public static function build_body( array $input, bool $network ) {
		$reason = sanitize_key( (string) ( $input['reason'] ?? '' ) );
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			return new WP_Error( 'emcp_lite_bad_reason', 'Unknown reason.' );
		}
		$comment = sanitize_textarea_field( (string) ( $input['comment'] ?? '' ) );
		$detail  = 'better_plugin' === $reason ? sanitize_text_field( (string) ( $input['detail'] ?? '' ) ) : '';
		if ( mb_strlen( $comment ) > 1000 || mb_strlen( $detail ) > 200 ) {
			return new WP_Error( 'emcp_lite_too_long', 'Too long.' );
		}
		$body = array(
			'reason'       => $reason,
			'detail'       => $detail,
			'comment'      => $comment,
			'lite_version' => (string) EMCP_TOOLS_VERSION,
			'wp_version'   => (string) get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
		);
		if ( ! $network && self::linkable() ) {
			$cred             = EMCP_Tools_Optin_State::credential( EMCP_Tools_Optin_State::read() );
			$body['site_id']  = (string) $cred['site_id'];
			$body['site_key'] = (string) $cred['site_key'];
		}
		return $body;
	}

	/** admin-ajax: relay the reason without waiting, then answer at once. */
	public static function ajax(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( null, 403 );
		}
		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
		$body  = self::build_body( is_array( $input ) ? $input : array(), ! empty( $input['network'] ) );
		if ( is_wp_error( $body ) ) {
			wp_send_json_error( null, 400 );
		}
		EMCP_Tools_Optin_Client::feedback( $body );
		wp_send_json_success();
	}
}
