<?php
/**
 * The admin frame (spec 5.1). Included by EMCP_Tools_Admin::render_page()
 * with $this and $active_tab in scope.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
$emcp_view      = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
$emcp_nav       = $this->nav();
$emcp_current   = $emcp_nav->current( $active_tab, $emcp_view );
$emcp_screen    = EMCP_Tools_Admin_Screens::screen_for_tab( $active_tab, null, $emcp_view );
$emcp_user      = wp_get_current_user();
$emcp_status    = ( new EMCP_Tools_Admin_Bar() )->status();
$emcp_collapsed = EMCP_Tools_Admin_Frame::sidebar_collapsed(
	(int) $emcp_user->ID,
	isset( $_COOKIE[ EMCP_Tools_Admin_Frame::SIDEBAR_COOKIE ] ) && is_string( $_COOKIE[ EMCP_Tools_Admin_Frame::SIDEBAR_COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ EMCP_Tools_Admin_Frame::SIDEBAR_COOKIE ] ) ) : null
);
?>
<div class="wrap emcp-app eui-frame<?php echo $emcp_collapsed ? ' is-collapsed' : ''; ?>">
	<?php
	// Every Frame method returns markup assembled from escaped parts.
	echo wp_kses( EMCP_Tools_Admin_Frame::promo( EMCP_Tools_Admin_Frame::announcements() ), EMCP_Lite_Edition::allowed_html() );
	?>
	<div class="eui-frame__layout">
		<?php echo wp_kses( EMCP_Tools_Admin_Frame::sidebar( $emcp_nav, $active_tab, EMCP_TOOLS_VERSION ), EMCP_Lite_Edition::allowed_html() ); ?>
		<div class="eui-frame__main">
			<?php echo wp_kses( EMCP_Tools_Admin_Frame::topbar( $emcp_current['crumbs'], $emcp_status, 0, EMCP_Tools_Admin_Frame::user_summary( $emcp_user ), $emcp_nav->topbar_links(), $emcp_collapsed, EMCP_Tools_Admin_Frame::cloud_status( class_exists( 'EMCP_Tools_Cloud_Module' ) && class_exists( 'EMCP_Tools_Cloud' ) && EMCP_Tools_Cloud_Module::is_enabled(), class_exists( 'EMCP_Tools_Cloud' ) ? EMCP_Tools_Cloud::status() : array() ) ), EMCP_Lite_Edition::allowed_html() ); ?>
			<div class="eui-frame__content" id="emcp-main">
				<?php // Core moves admin notices to just after this marker. ?>
				<hr class="wp-header-end">
				<?php if ( 'management' === $active_tab ) : ?>
					<?php EMCP_Tools_Management_Access_Admin::render(); ?>
				<?php elseif ( null !== $emcp_screen ) : ?>
					<?php
					echo wp_kses( EMCP_Tools_Admin_Frame::screen_container( $emcp_screen ), EMCP_Lite_Edition::allowed_html() );
					$emcp_fallback_js = EMCP_Tools_Admin_Frame::fallback_script();
					if ( '' !== $emcp_fallback_js ) {
						wp_print_inline_script_tag( $emcp_fallback_js );
					}
					?>
				<?php else : ?>
					<?php echo wp_kses( EMCP_Tools_Admin_Frame::unavailable(), EMCP_Lite_Edition::allowed_html() ); ?>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<div id="emcp-shell-root"></div>
</div>
