<?php
/**
 * Full-document canvas for a Themer body template takeover.
 *
 * WordPress includes this via the `template_include` filter in the template
 * loader's scope, so it pulls the resolved slots from the render controller's
 * memoized resolver rather than a local variable.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$emcp_slots = class_exists( 'EMCP_Tools_Themer_Render_Controller' )
	? EMCP_Tools_Themer_Render_Controller::slots()
	: array();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'emcp-themer-canvas' ); ?>>
<?php wp_body_open(); ?>
<?php
if ( ! empty( $emcp_slots['header'] ) ) {
	echo wp_kses( EMCP_Tools_Themer_Content_Renderer::render( (int) $emcp_slots['header'] ), EMCP_Lite_Edition::allowed_html() );
}
if ( ! empty( $emcp_slots['body'] ) ) {
	echo '<main class="emcp-themer-body">';
	echo wp_kses( EMCP_Tools_Themer_Content_Renderer::render( (int) $emcp_slots['body'] ), EMCP_Lite_Edition::allowed_html() );
	echo '</main>';
}
if ( ! empty( $emcp_slots['footer'] ) ) {
	echo wp_kses( EMCP_Tools_Themer_Content_Renderer::render( (int) $emcp_slots['footer'] ), EMCP_Lite_Edition::allowed_html() );
}
wp_footer();
?>
</body>
</html>
