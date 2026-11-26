<?php
/**
 * The opt-in screen. Included by EMCP_Tools_Optin_Screen::render() with
 * $emcp_state and $emcp_crypto in scope.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$emcp_pending = 'pending' === $emcp_state['state'];
$emcp_message = EMCP_Tools_Optin_Screen::message( $emcp_state );
$emcp_post    = admin_url( 'admin-post.php' );
?>
<div class="emcp-optin" role="region" aria-labelledby="emcp-optin-title">
	<div class="emcp-optin__card">
		<img class="emcp-optin__logo" src="<?php echo esc_url( EMCP_TOOLS_URL . 'assets/img/icon-sm.png' ); ?>" alt="<?php esc_attr_e( 'EMCP Lite', 'emcp-lite' ); ?>" width="48" height="48">
		<h1 id="emcp-optin-title" class="emcp-optin__title"><?php esc_html_e( 'Never miss an important update', 'emcp-lite' ); ?></h1>
		<p class="emcp-optin__lead">
			<?php esc_html_e( 'Share a few details about this site with EMCP and get product news, security notices and tips by email. We send you an email first, and you only join after you confirm it. You can stop sharing at any time under Data sharing.', 'emcp-lite' ); ?>
		</p>

		<details class="emcp-optin__details">
			<summary><?php esc_html_e( 'What we collect', 'emcp-lite' ); ?></summary>
			<ul>
				<?php foreach ( EMCP_Tools_Optin_Screen::collected() as $emcp_item ) : ?>
					<li><?php echo esc_html( $emcp_item ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p>
				<?php esc_html_e( 'Never passwords, keys, content or other users. Sharing refreshes these details when they change and once a month on an admin visit.', 'emcp-lite' ); ?>
				<a href="<?php echo esc_url( EMCP_Tools_Optin_Screen::PRIVACY_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'emcp-lite' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'emcp-lite' ); ?></span></a>
			</p>
		</details>

		<?php if ( ! $emcp_crypto ) : ?>
			<p class="emcp-optin__notice" role="status"><?php esc_html_e( 'Sharing is not available on this server, because it cannot encrypt the stored connection details. Nothing has been sent.', 'emcp-lite' ); ?></p>
		<?php elseif ( '' !== $emcp_message ) : ?>
			<p class="emcp-optin__notice" role="status"><?php echo esc_html( $emcp_message ); ?></p>
		<?php endif; ?>

		<div class="emcp-optin__actions">
			<?php if ( $emcp_crypto ) : ?>
				<form method="post" action="<?php echo esc_url( $emcp_post ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( $emcp_pending ? 'emcp_lite_optin_retry' : 'emcp_lite_optin_allow' ); ?>">
					<?php wp_nonce_field( $emcp_pending ? 'emcp_lite_optin_retry' : 'emcp_lite_optin_allow' ); ?>
					<button type="submit" class="emcp-optin__btn emcp-optin__btn--primary">
						<?php $emcp_pending ? esc_html_e( 'Retry', 'emcp-lite' ) : esc_html_e( 'Allow & continue', 'emcp-lite' ); ?>
					</button>
				</form>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $emcp_post ); ?>">
				<input type="hidden" name="action" value="emcp_lite_optin_skip">
				<?php wp_nonce_field( 'emcp_lite_optin_skip' ); ?>
				<button type="submit" class="emcp-optin__btn"><?php esc_html_e( 'Skip', 'emcp-lite' ); ?></button>
			</form>
		</div>
	</div>
</div>
