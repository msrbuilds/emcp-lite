<?php
/**
 * The Data sharing screen. Included by EMCP_Tools_Data_Sharing_Screen::render()
 * with $emcp_state in scope.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$emcp_effective = EMCP_Tools_Optin_State::effective( $emcp_state );
$emcp_on        = 'allowed' === $emcp_effective;
$emcp_post      = admin_url( 'admin-post.php' );
$emcp_dot       = $emcp_on ? ( true === $emcp_state['email_confirmed'] ? 'is-on' : 'is-warn' ) : ( in_array( $emcp_effective, array( 'pending', 'cancelling', 'revoked', 'expired', 'copy' ), true ) || 'pending' === $emcp_state['deletion'] ? 'is-warn' : '' );
$emcp_can_on    = in_array( $emcp_effective, array( 'unset', 'skipped', 'opted_out' ), true ) && 'pending' !== $emcp_state['deletion'];
$emcp_actions   = array();
if ( $emcp_on ) {
	$emcp_actions[] = array( 'emcp_lite_sharing_off', __( 'Turn off sharing', 'emcp-lite' ), false );
	if ( true !== $emcp_state['email_confirmed'] ) {
		$emcp_actions[] = array( 'emcp_lite_resend', __( 'Resend email', 'emcp-lite' ), false );
	}
} elseif ( 'pending' === $emcp_effective ) {
	$emcp_actions[] = array( 'emcp_lite_optin_retry', __( 'Retry', 'emcp-lite' ), true );
	$emcp_actions[] = array( 'emcp_lite_optin_skip', __( 'Cancel', 'emcp-lite' ), false );
} elseif ( 'copy' === $emcp_effective ) {
	$emcp_actions[] = array( 'emcp_lite_connect_copy', __( 'Connect this site', 'emcp-lite' ), true );
} elseif ( in_array( $emcp_effective, array( 'revoked', 'expired' ), true ) ) {
	$emcp_actions[] = array( 'emcp_lite_reconnect', __( 'Reconnect', 'emcp-lite' ), true );
} elseif ( $emcp_can_on ) {
	$emcp_actions[] = array( 'emcp_lite_sharing_on', __( 'Turn on sharing', 'emcp-lite' ), true );
}
$emcp_notes = array(
	'resent'      => __( 'If the limits allow, a new confirmation email is on its way. The previous link stops working once the new one is sent.', 'emcp-lite' ),
	'unreachable' => __( 'emcptools.com could not be reached. EMCP Lite tries again on a later admin visit.', 'emcp-lite' ),
);
$emcp_note      = $emcp_notes[ (string) $emcp_state['error'] ] ?? '';
if ( '' === $emcp_note ) {
	$emcp_note = EMCP_Tools_Optin_Screen::message( array( 'state' => '', 'error' => '' ) );
}
$emcp_crypto    = EMCP_Tools_Optin_State::encryption_available();
?>
<div class="emcp-sharing" role="region" aria-labelledby="emcp-sharing-title">
	<h1 id="emcp-sharing-title" class="emcp-sharing__title"><?php esc_html_e( 'Data sharing', 'emcp-lite' ); ?></h1>
	<p class="emcp-sharing__status" role="status">
		<span class="emcp-sharing__dot <?php echo esc_attr( $emcp_dot ); ?>" aria-hidden="true"></span>
		<span><?php echo esc_html( EMCP_Tools_Data_Sharing_Screen::status_line( $emcp_state ) ); ?></span>
	</p>
	<p class="emcp-sharing__meta">
		<?php
		if ( (int) $emcp_state['last_sent_at'] > 0 ) {
			/* translators: %s: a date and time. */
			echo esc_html( sprintf( __( 'Last sent: %s.', 'emcp-lite' ), date_i18n( 'Y-m-d H:i', (int) $emcp_state['last_sent_at'] ) ) ) . ' ';
		}
		if ( $emcp_on && (int) $emcp_state['status_checked_at'] > 0 ) {
			/* translators: %s: a date and time. */
			echo esc_html( sprintf( __( 'Email status last checked: %s.', 'emcp-lite' ), date_i18n( 'Y-m-d H:i', (int) $emcp_state['status_checked_at'] ) ) ) . ' ';
		}
		esc_html_e( 'A record that emcptools.com does not hear from for 180 days is deleted.', 'emcp-lite' );
		?>
	</p>

	<?php if ( '' !== $emcp_note ) : ?>
		<p class="emcp-sharing__notice"><?php echo esc_html( $emcp_note ); ?></p>
	<?php endif; ?>
	<?php if ( ! $emcp_crypto && ! $emcp_on ) : ?>
		<p class="emcp-sharing__notice"><?php esc_html_e( 'Sharing is not available on this server, because it cannot encrypt the stored connection details.', 'emcp-lite' ); ?></p>
	<?php endif; ?>

	<p><strong><?php esc_html_e( 'Share site details with EMCP', 'emcp-lite' ); ?></strong>: <?php echo $emcp_on ? esc_html__( 'On', 'emcp-lite' ) : esc_html__( 'Off', 'emcp-lite' ); ?></p>

	<?php if ( $emcp_actions ) : ?>
		<div class="emcp-sharing__actions">
			<?php foreach ( $emcp_actions as list( $emcp_action, $emcp_label, $emcp_primary ) ) : ?>
				<?php
				if ( ! $emcp_crypto && in_array( $emcp_action, array( 'emcp_lite_sharing_on', 'emcp_lite_reconnect', 'emcp_lite_connect_copy', 'emcp_lite_optin_retry' ), true ) ) {
					continue;
				}
				?>
				<form method="post" action="<?php echo esc_url( $emcp_post ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( $emcp_action ); ?>">
					<?php wp_nonce_field( $emcp_action ); ?>
					<button type="submit" class="emcp-optin__btn<?php echo $emcp_primary ? ' emcp-optin__btn--primary' : ''; ?>"><?php echo esc_html( $emcp_label ); ?></button>
				</form>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<details class="emcp-sharing__details">
		<summary><?php esc_html_e( 'What we collect', 'emcp-lite' ); ?></summary>
		<ul>
			<?php foreach ( EMCP_Tools_Optin_Screen::collected() as $emcp_item ) : ?>
				<li><?php echo esc_html( $emcp_item ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p>
			<?php esc_html_e( 'Sent only while sharing is on: when these details change, and once a month on an admin visit. Turning sharing off asks emcptools.com to delete this site\'s record. A deactivation reason you choose to send never includes this site\'s address unless sharing is on.', 'emcp-lite' ); ?>
			<a href="<?php echo esc_url( EMCP_Tools_Optin_Screen::PRIVACY_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'emcp-lite' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'emcp-lite' ); ?></span></a>
		</p>
	</details>
</div>
