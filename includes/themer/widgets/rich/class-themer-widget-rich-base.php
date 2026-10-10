<?php
/**
 * Base for the Themer widgets that need bespoke controls.
 *
 * The thin base drives every control from catalog args, which suits a value
 * renderer. These have their own control sets and markup, so they extend this
 * instead and delegate rendering to a builder-agnostic element class, which is
 * what lets a Gutenberg block reuse the same code.
 *
 * Required only from inside the Elementor registration callback, where
 * \Elementor\Widget_Base is guaranteed to exist.
 *
 * @package EMCP_Tools
 * @since   3.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'EMCP_Tools_Themer_Widget_Rich_Base' ) && class_exists( '\Elementor\Widget_Base' ) ) {

	/**
	 * @since 3.13.0
	 */
	abstract class EMCP_Tools_Themer_Widget_Rich_Base extends \Elementor\Widget_Base {

		/** Catalog key this widget renders. */
		abstract protected function emcp_key(): string;

		/**
		 * Keys of the rich widgets, in panel order.
		 *
		 * @return string[]
		 */
		public static function rich_widget_keys(): array {
			return array(
				'author-box',
				'post-navigation',
				'post-comments',
				'search-form',
				'sitemap',
				'post-info',
				'archive-posts',
			);
		}

		/**
		 * Class names to register.
		 *
		 * @return string[]
		 */
		public static function rich_widget_classes(): array {
			return array(
				'EMCP_Tools_Themer_Widget_Author_Box',
				'EMCP_Tools_Themer_Widget_Post_Navigation',
				'EMCP_Tools_Themer_Widget_Post_Comments',
				'EMCP_Tools_Themer_Widget_Search_Form',
				'EMCP_Tools_Themer_Widget_Sitemap',
				'EMCP_Tools_Themer_Widget_Post_Info',
				'EMCP_Tools_Themer_Widget_Archive_Posts',
			);
		}

		/**
		 * @return string
		 */
		public function get_name(): string {
			return 'emcp-' . $this->emcp_key();
		}

		/**
		 * @return string
		 */
		public function get_title(): string {
			$def = EMCP_Tools_Themer_Dynamic_Catalog::get( $this->emcp_key() );
			return $def ? (string) $def['label'] : $this->emcp_key();
		}

		/**
		 * @return string
		 */
		public function get_icon(): string {
			return 'eicon-post-content';
		}

		/**
		 * @return string[]
		 */
		public function get_categories(): array {
			return array( EMCP_Tools_Themer_Widgets::CATEGORY );
		}

		/**
		 * Render through the shared dispatcher, so a widget and its block cannot
		 * diverge.
		 */
		protected function render(): void {
			$html = EMCP_Tools_Themer_Dynamic::render( $this->emcp_key(), (array) $this->get_settings_for_display() );
			// The element renderers escape their own output.
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * A switcher control, since these widgets are mostly toggles.
		 *
		 * @param string $key     Control key.
		 * @param string $label   Label.
		 * @param string $default 'yes' or ''.
		 */
		protected function emcp_toggle( string $key, string $label, string $default = 'yes' ): void {
			$this->add_control(
				$key,
				array(
					'label'        => $label,
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'default'      => $default,
					'return_value' => 'yes',
				)
			);
		}
	}
}
