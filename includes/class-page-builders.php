<?php
/**
 * EMCP page-builder selection. Gutenberg remains available beside one builder.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EMCP_Tools_Page_Builders {
	const OPTION = 'emcp_tools_page_builder';
	const SETTINGS_GROUP = 'emcp_tools_page_builders';
	const BLOCK_PACK_OPTION = 'emcp_tools_block_packs';

	public static function block_packs(): array {
		return array(
			'otter' => array('label'=>'Otter Blocks', 'class'=>'EMCP_Tools_Otter_Integration', 'native_tools'=>true, 'requirement'=>__('Requires Otter Blocks 3.2.5–3.2.x and EMCP Pro. Otter Pro is optional.','emcp-lite')),
			'blocksy-blocks' => array('label'=>'Blocksy Blocks', 'class'=>'EMCP_Tools_Blocksy_Blocks_Integration', 'requirement'=>__('Requires the Blocksy theme, Blocksy Companion and EMCP Pro.','emcp-lite')),
			'spectra' => array('label'=>'Spectra', 'class'=>'EMCP_Tools_Spectra_Integration'),
			'kadence-blocks' => array('label'=>'Kadence Blocks', 'class'=>'EMCP_Tools_Kadence_Blocks_Integration'),
			'generateblocks' => array('label'=>'GenerateBlocks', 'class'=>'EMCP_Tools_GenerateBlocks_Integration'),
		);
	}

	public static function sanitize_block_packs($value): array {
		$value=is_array($value)?array_filter($value,'is_string'):array();
		return array_values(array_intersect(array_keys(self::block_packs()),$value));
	}

	/** Builder owning the ability group currently being registered. */
	public static $registering = '';

	/** Implemented standalone integrations. Block packs belong to Gutenberg. */
	public static function catalog(): array {
		return array(
			'visual-composer' => array('label'=>__('Visual Composer','emcp-lite'),'description'=>__('Native documents, element schemas, staged HTML/CSS, local library and recovery.','emcp-lite'),'requirement'=>__('Requires active Visual Composer 45.16.2–45.16.x and EMCP Pro.','emcp-lite')),
			'beaver' => array('label'=>__('Beaver Builder','emcp-lite'),'description'=>__('Native staged layouts, module schemas, responsive styles, saved library and recovery.','emcp-lite'),'requirement'=>__('Requires active Beaver Builder 2.11.1–2.11.x and EMCP Pro.','emcp-lite')),
			'wpbakery' => array('label'=>__('WPBakery','emcp-lite'),'description'=>__('Native shortcode layouts, element schemas, page CSS, templates and recovery.','emcp-lite'),'requirement'=>__('Requires active WPBakery 9.0.1–9.0.x and EMCP Pro.','emcp-lite')),
			'kirki' => array('label'=>__('Kirki','emcp-lite'),'description'=>__('Native canvas pages, responsive styles, staged versions, local library and CMS discovery.','emcp-lite'),'requirement'=>__('Requires active Kirki 6.3.1–6.3.x and EMCP Pro.','emcp-lite')),
			'oxygen' => array('label'=>__('Oxygen','emcp-lite'),'description'=>__('Native Oxygen 6 pages, elements, responsive classes, components and design-system discovery.','emcp-lite'),'requirement'=>__('Requires active Oxygen 6.1.3–6.1.x and EMCP Pro.','emcp-lite')),
			'thrive' => array('label' => __( 'Thrive Architect', 'emcp-lite' ), 'description' => __( 'Native Architect pages, element controls, HTML and CSS editing, and local library discovery.', 'emcp-lite' ), 'requirement' => __( 'Requires Thrive Architect 11.0.x and EMCP Pro.', 'emcp-lite' )),
			'divi' => array('label' => __( 'Divi', 'emcp-lite' ), 'description' => __( 'Divi 5 pages, modules, library layouts, Theme Builder assignments and theme options.', 'emcp-lite' ), 'requirement' => __( 'Requires active Divi 5.13.x and EMCP Pro.', 'emcp-lite' )),
			'avada' => array('label' => __( 'Avada', 'emcp-lite' ), 'description' => __( 'Native layouts, page editing, and Avada Core Portfolio, FAQ and Slider elements.', 'emcp-lite' ), 'requirement' => __( 'Requires the active Avada theme, Avada Builder 3.16.1–3.16.x and EMCP Pro. Core features require Avada Core.', 'emcp-lite' )),
			'breakdance' => array(
				'label' => __( 'Breakdance', 'emcp-lite' ),
				'description' => __( 'Native element schemas, nested page editing, templates and design-system discovery.', 'emcp-lite' ),
				'requirement' => __( 'Requires active Breakdance 2.8.3–2.8.x and an EMCP Pro license.', 'emcp-lite' ),
			),
			'bricks' => array(
				'label' => __( 'Bricks', 'emcp-lite' ),
				'description' => __( 'Native elements, page editing, local templates and design-system discovery.', 'emcp-lite' ),
				'requirement' => __( 'Requires active Bricks 2.3.13 to 2.4.x and an EMCP Pro license.', 'emcp-lite' ),
			),
			'elementor' => array(
				'label' => __( 'Elementor', 'emcp-lite' ),
				'description' => __( 'Pages, containers, widgets, templates, global styles, and Elementor addons.', 'emcp-lite' ),
				'requirement' => __( 'Requires the Elementor plugin to be active.', 'emcp-lite' ),
			),
			'bebuilder' => array(
				'label' => __( 'BeBuilder', 'emcp-lite' ),
				'description' => __( 'BeTheme settings, builder item schemas, page structures, and sections.', 'emcp-lite' ),
				'requirement' => __( 'Requires BeTheme to be the active theme and an EMCP Pro license.', 'emcp-lite' ),
			),
		);
	}

	public static function available( string $id ): bool {
		if ('visual-composer' === $id) { return function_exists('emcp_tools_fs') && emcp_tools_fs()->can_use_premium_code() && class_exists('EMCP_Tools_Visual_Composer_Integration') && EMCP_Tools_Visual_Composer_Integration::supported(); }
		if (isset(self::block_packs()[$id])) {
			$class=self::block_packs()[$id]['class'];
			return class_exists($class) && (new $class())->is_available();
		}
		if ('beaver' === $id) { return function_exists('emcp_tools_fs') && emcp_tools_fs()->can_use_premium_code() && class_exists('EMCP_Tools_Beaver_Integration') && EMCP_Tools_Beaver_Integration::supported(); }
		if ('wpbakery' === $id) { return function_exists('emcp_tools_fs') && emcp_tools_fs()->can_use_premium_code() && class_exists('EMCP_Tools_WPBakery_Integration') && EMCP_Tools_WPBakery_Integration::supported(); }
		if ('kirki' === $id) { return function_exists('emcp_tools_fs') && emcp_tools_fs()->can_use_premium_code() && class_exists('EMCP_Tools_Kirki_Integration') && EMCP_Tools_Kirki_Integration::supported(); }
		if ('oxygen' === $id) { return function_exists('emcp_tools_fs') && emcp_tools_fs()->can_use_premium_code() && class_exists('EMCP_Tools_Oxygen_Integration') && EMCP_Tools_Oxygen_Integration::supported(); }
		if ( 'thrive' === $id ) { return function_exists( 'emcp_tools_fs' ) && emcp_tools_fs()->can_use_premium_code() && class_exists( 'EMCP_Tools_Thrive_Integration' ) && EMCP_Tools_Thrive_Integration::supported(); }
		if ( 'divi' === $id ) { return function_exists( 'emcp_tools_fs' ) && emcp_tools_fs()->can_use_premium_code() && class_exists( 'EMCP_Tools_Divi_Integration' ) && EMCP_Tools_Divi_Integration::supported(); }
		if ( 'avada' === $id ) { return function_exists( 'emcp_tools_fs' ) && emcp_tools_fs()->can_use_premium_code() && class_exists( 'EMCP_Tools_Avada_Integration' ) && EMCP_Tools_Avada_Integration::supported(); }
		if ( 'breakdance' === $id ) {
			return function_exists( 'emcp_tools_fs' ) && emcp_tools_fs()->can_use_premium_code()
				&& class_exists( 'EMCP_Tools_Breakdance_Integration' ) && EMCP_Tools_Breakdance_Integration::supported();
		}
		if ( 'bricks' === $id ) {
			return function_exists( 'emcp_tools_fs' ) && emcp_tools_fs()->can_use_premium_code()
				&& class_exists( 'EMCP_Tools_Bricks_Integration' ) && EMCP_Tools_Bricks_Integration::supported();
		}
		if ( 'gutenberg' === $id ) {
			return true;
		}
		if ( 'elementor' === $id ) {
			return (bool) did_action( 'elementor/loaded' );
		}
		if ( 'bebuilder' === $id ) {
			return 'betheme' === strtolower( get_template() )
				&& function_exists( 'emcp_tools_fs' ) && emcp_tools_fs()->can_use_premium_code()
				&& class_exists( 'EMCP_Tools_BeTheme_Integration' );
		}
		return false;
	}

	/** Preserve an existing builder on upgrade until the first explicit choice. */
	public static function selected(): string {
		$saved = get_option( self::OPTION, null );
		if ( null !== $saved ) {
			return is_string( $saved ) && isset( self::catalog()[ $saved ] ) ? $saved : '';
		}
		foreach ( array( 'elementor', 'bebuilder', 'bricks', 'breakdance', 'avada', 'divi', 'thrive', 'oxygen', 'kirki', 'wpbakery', 'beaver' ) as $id ) {
			if ( self::available( $id ) ) {
				return $id;
			}
		}
		return '';
	}

	public static function enabled( string $id ): bool {
		if (isset(self::block_packs()[$id])) {
			// Preserve existing integrations on upgrade; explicit empty array disables all.
			$saved=get_option(self::BLOCK_PACK_OPTION,array_keys(self::block_packs()));
			return is_array($saved) && in_array($id,$saved,true) && self::available($id);
		}
		return 'gutenberg' === $id || ( self::selected() === $id && self::available( $id ) );
	}

	/** A scalar option makes multiple active builders impossible, even without JS. */
	public static function sanitize( $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( is_string( $value ) && isset( self::catalog()[ $value ] ) && self::available( $value ) ) {
			return $value;
		}
		add_settings_error( self::OPTION, 'builder_unavailable', __( 'Choose an available page builder. Your previous selection has been kept.', 'emcp-lite' ) );
		return self::selected();
	}

	/** Hide inactive standalone builder categories, retaining the canonical catalog. */
	public static function visible_categories( array $categories ): array {
		return array_filter( $categories, static function ( array $category ): bool {
			$platform = $category['platform'] ?? 'elementor';
			return (! isset( self::catalog()[ $platform ] ) && ! isset(self::block_packs()[$platform])) || self::enabled( $platform );
		} );
	}
}
