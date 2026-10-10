<?php
/**
 * The seven rich Themer widgets.
 *
 * Each is thin: a catalog key, an icon, and its controls. All rendering happens
 * in the element classes, which the Gutenberg blocks share.
 *
 * Required only from inside the Elementor registration callback.
 *
 * @package EMCP_Tools
 * @since   3.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'EMCP_Tools_Themer_Widget_Rich_Base' ) ) {
	return;
}

if ( ! class_exists( 'EMCP_Tools_Themer_Widget_Author_Box' ) ) {

	/** @since 3.13.0 */
	class EMCP_Tools_Themer_Widget_Author_Box extends EMCP_Tools_Themer_Widget_Rich_Base {

		protected function emcp_key(): string {
			return 'author-box';
		}

		public function get_icon(): string {
			return 'eicon-person';
		}

		protected function register_controls(): void {
			$this->start_controls_section( 'emcp_content', array( 'label' => __( 'Author Box', 'emcp-lite' ) ) );

			$this->emcp_toggle( 'show_avatar', __( 'Avatar', 'emcp-lite' ) );
			$this->add_control(
				'avatar_size',
				array(
					'label'     => __( 'Avatar size', 'emcp-lite' ),
					'type'      => \Elementor\Controls_Manager::NUMBER,
					'default'   => 96,
					'min'       => 16,
					'max'       => 512,
					'condition' => array( 'show_avatar' => 'yes' ),
				)
			);
			$this->emcp_toggle( 'show_name', __( 'Name', 'emcp-lite' ) );
			$this->add_control(
				'name_tag',
				array(
					'label'     => __( 'Name tag', 'emcp-lite' ),
					'type'      => \Elementor\Controls_Manager::SELECT,
					'default'   => 'h4',
					'options'   => array( 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'div' => 'div', 'span' => 'span' ),
					'condition' => array( 'show_name' => 'yes' ),
				)
			);
			$this->emcp_toggle( 'show_bio', __( 'Biography', 'emcp-lite' ) );
			$this->emcp_toggle( 'show_website', __( 'Website link', 'emcp-lite' ) );
			$this->emcp_toggle( 'show_posts_link', __( 'Archive link', 'emcp-lite' ) );
			$this->add_control(
				'layout',
				array(
					'label'   => __( 'Layout', 'emcp-lite' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'default' => 'side',
					'options' => array(
						'side'    => __( 'Avatar beside text', 'emcp-lite' ),
						'stacked' => __( 'Stacked', 'emcp-lite' ),
					),
				)
			);
			$this->add_responsive_control(
				'alignment',
				array(
					'label'   => __( 'Alignment', 'emcp-lite' ),
					'type'    => \Elementor\Controls_Manager::CHOOSE,
					'options' => array(
						'left'   => array( 'title' => __( 'Left', 'emcp-lite' ), 'icon' => 'eicon-text-align-left' ),
						'center' => array( 'title' => __( 'Center', 'emcp-lite' ), 'icon' => 'eicon-text-align-center' ),
						'right'  => array( 'title' => __( 'Right', 'emcp-lite' ), 'icon' => 'eicon-text-align-right' ),
					),
				)
			);

			$this->end_controls_section();
		}
	}
}

if ( ! class_exists( 'EMCP_Tools_Themer_Widget_Post_Navigation' ) ) {

	/** @since 3.13.0 */
	class EMCP_Tools_Themer_Widget_Post_Navigation extends EMCP_Tools_Themer_Widget_Rich_Base {

		protected function emcp_key(): string {
			return 'post-navigation';
		}

		public function get_icon(): string {
			return 'eicon-post-navigation';
		}

		protected function register_controls(): void {
			$this->start_controls_section( 'emcp_content', array( 'label' => __( 'Post Navigation', 'emcp-lite' ) ) );

			$this->emcp_toggle( 'show_label', __( 'Label', 'emcp-lite' ) );
			$this->emcp_toggle( 'show_title', __( 'Post title', 'emcp-lite' ) );
			$this->emcp_toggle( 'show_arrow', __( 'Arrow', 'emcp-lite' ) );
			$this->add_control(
				'prev_label',
				array(
					'label'       => __( 'Previous label', 'emcp-lite' ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => __( 'Previous', 'emcp-lite' ),
					'condition'   => array( 'show_label' => 'yes' ),
				)
			);
			$this->add_control(
				'next_label',
				array(
					'label'     => __( 'Next label', 'emcp-lite' ),
					'type'      => \Elementor\Controls_Manager::TEXT,
					'default'   => __( 'Next', 'emcp-lite' ),
					'condition' => array( 'show_label' => 'yes' ),
				)
			);
			$this->emcp_toggle( 'in_same_term', __( 'Stay in the same term', 'emcp-lite' ), '' );
			$this->add_control(
				'taxonomy',
				array(
					'label'       => __( 'Taxonomy', 'emcp-lite' ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => 'category',
					'description' => __( 'Which taxonomy the previous and next posts must share.', 'emcp-lite' ),
					'condition'   => array( 'in_same_term' => 'yes' ),
				)
			);

			$this->end_controls_section();
		}
	}
}

if ( ! class_exists( 'EMCP_Tools_Themer_Widget_Post_Comments' ) ) {

	/** @since 3.13.0 */
	class EMCP_Tools_Themer_Widget_Post_Comments extends EMCP_Tools_Themer_Widget_Rich_Base {

		protected function emcp_key(): string {
			return 'post-comments';
		}

		public function get_icon(): string {
			return 'eicon-comments';
		}

		protected function register_controls(): void {
			$this->start_controls_section( 'emcp_content', array( 'label' => __( 'Post Comments', 'emcp-lite' ) ) );

			$this->emcp_toggle( 'hide_when_closed', __( 'Hide when comments are closed', 'emcp-lite' ) );
			$this->add_control(
				'emcp_note',
				array(
					'type'            => \Elementor\Controls_Manager::RAW_HTML,
					'raw'             => __( 'Comments are rendered by your theme, so their appearance follows the theme rather than these controls.', 'emcp-lite' ),
					'content_classes' => 'elementor-descriptor',
				)
			);

			$this->end_controls_section();
		}
	}
}

if ( ! class_exists( 'EMCP_Tools_Themer_Widget_Search_Form' ) ) {

	/** @since 3.13.0 */
	class EMCP_Tools_Themer_Widget_Search_Form extends EMCP_Tools_Themer_Widget_Rich_Base {

		protected function emcp_key(): string {
			return 'search-form';
		}

		public function get_icon(): string {
			return 'eicon-search';
		}

		protected function register_controls(): void {
			$this->start_controls_section( 'emcp_content', array( 'label' => __( 'Search Form', 'emcp-lite' ) ) );

			$this->add_control(
				'placeholder',
				array(
					'label'   => __( 'Placeholder', 'emcp-lite' ),
					'type'    => \Elementor\Controls_Manager::TEXT,
					'default' => __( 'Search', 'emcp-lite' ),
				)
			);
			$this->add_control(
				'button_type',
				array(
					'label'   => __( 'Button', 'emcp-lite' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'default' => 'icon',
					'options' => array( 'icon' => __( 'Icon', 'emcp-lite' ), 'text' => __( 'Text', 'emcp-lite' ) ),
				)
			);
			$this->add_control(
				'button_text',
				array(
					'label'       => __( 'Button text', 'emcp-lite' ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => __( 'Search', 'emcp-lite' ),
					'description' => __( 'Also used as the accessible name when the button shows an icon.', 'emcp-lite' ),
				)
			);
			$this->emcp_toggle( 'full_width', __( 'Full width', 'emcp-lite' ), '' );

			$this->end_controls_section();
		}
	}
}

if ( ! class_exists( 'EMCP_Tools_Themer_Widget_Sitemap' ) ) {

	/** @since 3.13.0 */
	class EMCP_Tools_Themer_Widget_Sitemap extends EMCP_Tools_Themer_Widget_Rich_Base {

		protected function emcp_key(): string {
			return 'sitemap';
		}

		public function get_icon(): string {
			return 'eicon-bullet-list';
		}

		protected function register_controls(): void {
			$this->start_controls_section( 'emcp_content', array( 'label' => __( 'Sitemap', 'emcp-lite' ) ) );

			$this->add_control(
				'post_types',
				array(
					'label'       => __( 'Post types', 'emcp-lite' ),
					'type'        => \Elementor\Controls_Manager::SELECT2,
					'multiple'    => true,
					'default'     => array( 'page' ),
					'options'     => self::emcp_post_type_options(),
					'label_block' => true,
				)
			);
			$this->add_control(
				'taxonomies',
				array(
					'label'       => __( 'Taxonomies', 'emcp-lite' ),
					'type'        => \Elementor\Controls_Manager::SELECT2,
					'multiple'    => true,
					'default'     => array(),
					'options'     => self::emcp_taxonomy_options(),
					'label_block' => true,
				)
			);
			$this->emcp_toggle( 'hide_empty', __( 'Hide empty terms', 'emcp-lite' ) );
			$this->emcp_toggle( 'nofollow', __( 'Add nofollow', 'emcp-lite' ), '' );
			$this->add_control(
				'limit',
				array(
					'label'       => __( 'Items per list', 'emcp-lite' ),
					'type'        => \Elementor\Controls_Manager::NUMBER,
					'default'     => 50,
					'min'         => 1,
					'max'         => 100,
					'description' => __( 'Capped at 100 so a large site cannot be slowed by this widget.', 'emcp-lite' ),
				)
			);

			$this->end_controls_section();
		}

		/** @return array<string,string> */
		private static function emcp_post_type_options(): array {
			$out = array();
			foreach ( (array) get_post_types( array( 'public' => true ), 'objects' ) as $t ) {
				if ( is_object( $t ) && isset( $t->name ) ) {
					$out[ (string) $t->name ] = (string) ( $t->label ?? $t->name );
				}
			}
			return $out;
		}

		/** @return array<string,string> */
		private static function emcp_taxonomy_options(): array {
			$out = array();
			foreach ( (array) get_taxonomies( array( 'public' => true ), 'objects' ) as $t ) {
				if ( is_object( $t ) && isset( $t->name ) ) {
					$out[ (string) $t->name ] = (string) ( $t->label ?? $t->name );
				}
			}
			return $out;
		}
	}
}

if ( ! class_exists( 'EMCP_Tools_Themer_Widget_Post_Info' ) ) {

	/** @since 3.13.0 */
	class EMCP_Tools_Themer_Widget_Post_Info extends EMCP_Tools_Themer_Widget_Rich_Base {

		protected function emcp_key(): string {
			return 'post-info';
		}

		public function get_icon(): string {
			return 'eicon-post-info';
		}

		protected function register_controls(): void {
			$this->start_controls_section( 'emcp_content', array( 'label' => __( 'Post Info', 'emcp-lite' ) ) );

			$this->add_control(
				'items',
				array(
					'label'       => __( 'Items', 'emcp-lite' ),
					'type'        => \Elementor\Controls_Manager::SELECT2,
					'multiple'    => true,
					'default'     => array( 'author', 'date' ),
					'options'     => array(
						'author'   => __( 'Author', 'emcp-lite' ),
						'date'     => __( 'Date', 'emcp-lite' ),
						'time'     => __( 'Time', 'emcp-lite' ),
						'comments' => __( 'Comments count', 'emcp-lite' ),
						'terms'    => __( 'Terms', 'emcp-lite' ),
						'custom'   => __( 'Custom field', 'emcp-lite' ),
					),
					'label_block' => true,
					'description' => __( 'Order here is the order they render in.', 'emcp-lite' ),
				)
			);
			$this->add_control(
				'taxonomy',
				array(
					'label'   => __( 'Taxonomy', 'emcp-lite' ),
					'type'    => \Elementor\Controls_Manager::TEXT,
					'default' => 'category',
				)
			);
			$this->add_control(
				'meta_key',
				array(
					'label' => __( 'Custom field key', 'emcp-lite' ),
					'type'  => \Elementor\Controls_Manager::TEXT,
				)
			);
			$this->add_control(
				'separator',
				array(
					'label'   => __( 'Separator', 'emcp-lite' ),
					'type'    => \Elementor\Controls_Manager::TEXT,
					'default' => '/',
				)
			);
			$this->add_control(
				'layout',
				array(
					'label'   => __( 'Layout', 'emcp-lite' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'default' => 'inline',
					'options' => array( 'inline' => __( 'Inline', 'emcp-lite' ), 'stacked' => __( 'Stacked', 'emcp-lite' ) ),
				)
			);
			$this->emcp_toggle( 'link_author', __( 'Link the author', 'emcp-lite' ), '' );
			$this->emcp_toggle( 'link_terms', __( 'Link the terms', 'emcp-lite' ), '' );

			$this->end_controls_section();
		}
	}
}

if ( ! class_exists( 'EMCP_Tools_Themer_Widget_Archive_Posts' ) ) {

	/** @since 3.13.0 */
	class EMCP_Tools_Themer_Widget_Archive_Posts extends EMCP_Tools_Themer_Widget_Rich_Base {

		protected function emcp_key(): string {
			return 'archive-posts';
		}

		public function get_icon(): string {
			return 'eicon-posts-grid';
		}

		protected function register_controls(): void {
			$this->start_controls_section( 'emcp_content', array( 'label' => __( 'Archive Posts', 'emcp-lite' ) ) );

			$this->add_control(
				'layout',
				array(
					'label'   => __( 'Layout', 'emcp-lite' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'default' => 'grid',
					'options' => array( 'grid' => __( 'Grid', 'emcp-lite' ), 'list' => __( 'List', 'emcp-lite' ) ),
				)
			);
			$this->add_responsive_control(
				'columns',
				array(
					'label'     => __( 'Columns', 'emcp-lite' ),
					'type'      => \Elementor\Controls_Manager::NUMBER,
					'default'   => 3,
					'min'       => 1,
					'max'       => 6,
					'condition' => array( 'layout' => 'grid' ),
				)
			);
			$this->emcp_toggle( 'show_image', __( 'Featured image', 'emcp-lite' ) );
			$this->emcp_toggle( 'show_excerpt', __( 'Excerpt', 'emcp-lite' ) );
			$this->add_control(
				'excerpt_words',
				array(
					'label'     => __( 'Excerpt words', 'emcp-lite' ),
					'type'      => \Elementor\Controls_Manager::NUMBER,
					'default'   => 25,
					'min'       => 5,
					'max'       => 200,
					'condition' => array( 'show_excerpt' => 'yes' ),
				)
			);
			$this->emcp_toggle( 'show_more', __( 'Read more link', 'emcp-lite' ) );
			$this->add_control(
				'more_text',
				array(
					'label'     => __( 'Read more text', 'emcp-lite' ),
					'type'      => \Elementor\Controls_Manager::TEXT,
					'default'   => __( 'Read more', 'emcp-lite' ),
					'condition' => array( 'show_more' => 'yes' ),
				)
			);
			$this->emcp_toggle( 'show_pagination', __( 'Pagination', 'emcp-lite' ) );

			$this->end_controls_section();
		}
	}
}
