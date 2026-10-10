<?php
/**
 * Spectra (Ultimate Addons for Gutenberg) block catalog.
 *
 * Both halves are sourced from Spectra itself, so nothing is guessed:
 *  - the block LIST comes from `UAGB_Block_Module::get_blocks_info()`;
 *  - a block's ATTRIBUTES come from Spectra's own
 *    `includes/blocks/<slug>/attributes.php` (real names + defaults).
 * A small STRUCTURE map records which blocks need an innerBlocks template
 * (e.g. buttons -> buttons-child) and which are dynamic (server-rendered), which
 * cannot be read from attributes; that is structural, not attribute-guessing.
 *
 * @package EMCP_Tools
 * @since   3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spectra block catalog (list + real attributes).
 */
class EMCP_Tools_Spectra_Catalog {

	const PLUGIN_FILE = 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php';
	const PLUGIN_SLUG = 'ultimate-addons-for-gutenberg';

	/**
	 * Structural hints that can't be derived from attributes.php: which blocks
	 * need an innerBlocks template, and which are dynamic (server-rendered, so
	 * add-block emits self-closing markup). Keyed by full block name. Extend as
	 * container/parent blocks are covered.
	 *
	 * @var array<string,array{inner?:array,dynamic?:bool}>
	 */
	const STRUCTURE = array(
		'uagb/buttons'          => array( 'inner' => array( array( 'name' => 'uagb/buttons-child' ) ) ),
		'uagb/icon-list'        => array( 'inner' => array( array( 'name' => 'uagb/icon-list-child' ) ) ),
		'uagb/tabs'             => array( 'inner' => array( array( 'name' => 'uagb/tabs-child' ) ) ),
		'uagb/slider'           => array( 'inner' => array( array( 'name' => 'uagb/slider-child' ) ) ),
		'uagb/social-share'     => array( 'inner' => array( array( 'name' => 'uagb/social-share-child' ) ) ),
		'uagb/restaurant-menu'  => array( 'inner' => array( array( 'name' => 'uagb/restaurant-menu-child' ) ) ),
		'uagb/faq'              => array( 'inner' => array( array( 'name' => 'uagb/faq-child' ) ) ),
		'uagb/content-timeline' => array( 'inner' => array( array( 'name' => 'uagb/content-timeline-child' ) ) ),
		// Dynamic (server-rendered) blocks — add-block emits self-closing markup.
		'uagb/post-grid'        => array( 'dynamic' => true ),
		'uagb/post-carousel'    => array( 'dynamic' => true ),
		'uagb/post-masonry'     => array( 'dynamic' => true ),
		'uagb/post-timeline'    => array( 'dynamic' => true ),
		'uagb/google-map'       => array( 'dynamic' => true ),
		'uagb/table-of-contents' => array( 'dynamic' => true ),
		'uagb/taxonomy-list'    => array( 'dynamic' => true ),
		'uagb/forms'            => array( 'dynamic' => true ),
	);

	/** Attributes returned by default (a curated key subset when set); [] = return all (capped). */
	const HIGHLIGHT = array(
		'uagb/advanced-heading' => array( 'headingAlign', 'headingColor', 'subHeadingColor', 'seperatorStyle', 'separatorColor' ),
		'uagb/container'        => array( 'innerContentWidth', 'directionDesktop', 'backgroundType', 'backgroundColor' ),
		'uagb/separator'        => array( 'separatorStyle', 'separatorColor', 'separatorWidth', 'separatorHeight' ),
		'uagb/call-to-action'   => array( 'ctaType', 'ctaLink', 'ctaTarget', 'textAlign' ),
	);

	const DEFAULT_CAP = 30;

	/**
	 * Native attributes registered via a shared helper (UAGB_Block_Helper) rather
	 * than as literal keys in the block's attributes.php — so they DO NOT appear in
	 * real_attributes()/get-block-schema, yet they are real, editable attributes an
	 * agent should use instead of a core/html workaround. Keyed by full block name.
	 *
	 * @var array<string,array<string,string>>
	 */
	const SHARED_ATTRS = array(
		'uagb/container' => array(
			'background'       => 'backgroundType supports "color" | "gradient" | "image" | "video".',
			'background_image' => 'backgroundType:"image" + backgroundImage:{id,url} (also set backgroundImageDesktop). Overlay: overlayType:"color" + backgroundImageColor:"rgba(...)", OR overlayType:"gradient" + gradientValue:"linear-gradient(90deg,rgba(..)0%,rgba(..)100%)" (the overlay gradient is read from gradientValue, NOT gradientOverlayColor1/2). Position/size/repeat default to center/cover/no-repeat.',
			'background_video' => 'backgroundType:"video" + backgroundVideo:{url} + backgroundVideoFallbackImage:{id,url}; overlay via backgroundVideoColor + backgroundVideoOpacity.',
			'border_radius'    => 'containerBorderTopLeftRadius / containerBorderTopRightRadius / containerBorderBottomLeftRadius / containerBorderBottomRightRadius (+ containerBorderRadiusUnit).',
			'box_shadow'       => 'boxShadowColor / boxShadowBlur / boxShadowHOffset / boxShadowVOffset / boxShadowSpread / boxShadowPosition.',
		),
	);

	/**
	 * Shared-helper attributes for a block (see SHARED_ATTRS), or [].
	 *
	 * @param string $name Full block name.
	 * @return array<string,string>
	 */
	public static function shared_attributes( string $name ): array {
		return self::SHARED_ATTRS[ $name ] ?? array();
	}

	/**
	 * @return bool Whether Spectra is active.
	 */
	public static function is_active(): bool {
		return function_exists( 'is_plugin_active' )
			? is_plugin_active( self::PLUGIN_FILE )
			: class_exists( 'UAGB_Block_Module' );
	}

	/**
	 * Compact block index from Spectra's registry (excludes child/extension/deprecated).
	 *
	 * @param string $category Optional admin-category filter.
	 * @param string $search   Optional case-insensitive filter.
	 * @return array<int,array>
	 */
	public static function blocks_index( string $category = '', string $search = '' ): array {
		$info   = class_exists( 'UAGB_Block_Module' ) ? (array) UAGB_Block_Module::get_blocks_info() : array();
		$search = strtolower( trim( $search ) );
		$out    = array();

		foreach ( $info as $name => $b ) {
			$name = (string) $name;
			if ( ! empty( $b['extension'] ) || ! empty( $b['deprecated'] ) || false !== strpos( $name, '-child' ) ) {
				continue;
			}
			$cats = isset( $b['admin_categories'] ) ? (array) $b['admin_categories'] : array();
			if ( '' !== $category && ! in_array( $category, $cats, true ) ) {
				continue;
			}
			$title = (string) ( $b['title'] ?? $name );
			$desc  = (string) ( $b['description'] ?? '' );
			if ( '' !== $search
				&& false === strpos( strtolower( $title ), $search )
				&& false === strpos( strtolower( $desc ), $search )
				&& false === strpos( strtolower( $name ), $search ) ) {
				continue;
			}
			$out[] = array(
				'name'        => $name,
				'title'       => $title,
				'description' => $desc,
				'category'    => $cats[0] ?? '',
				'doc'         => (string) ( $b['doc'] ?? '' ),
				'dynamic'     => ! empty( $b['dynamic_assets'] ) || ! empty( self::STRUCTURE[ $name ]['dynamic'] ),
			);
		}
		return $out;
	}

	/**
	 * The raw get_blocks_info() entry for a block, or null.
	 *
	 * @param string $name Full block name.
	 * @return array|null
	 */
	public static function block_meta( string $name ): ?array {
		$info = class_exists( 'UAGB_Block_Module' ) ? (array) UAGB_Block_Module::get_blocks_info() : array();
		return isset( $info[ $name ] ) ? (array) $info[ $name ] : null;
	}

	/**
	 * A block's REAL attributes (name => default) from Spectra's own
	 * attributes.php. Tests seed $GLOBALS['_uagb_block_attributes'][$name].
	 *
	 * @param string $name Full block name.
	 * @return array<string,mixed>
	 */
	public static function real_attributes( string $name ): array {
		if ( isset( $GLOBALS['_uagb_block_attributes'][ $name ] ) ) {
			return (array) $GLOBALS['_uagb_block_attributes'][ $name ];
		}
		$slug = ( 0 === strpos( $name, 'uagb/' ) ) ? substr( $name, 5 ) : $name;
		$dir  = defined( 'UAGB_DIR' ) ? UAGB_DIR : ( ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' ) . '/' . self::PLUGIN_SLUG . '/' );
		$file = $dir . 'includes/blocks/' . $slug . '/attributes.php';
		if ( self::is_active() && is_readable( $file ) ) {
			$attrs = require $file; // returns the attributes array.
			return is_array( $attrs ) ? $attrs : array();
		}
		return array();
	}

	/**
	 * The curated highlight attribute names for a block (real names), or [].
	 *
	 * @param string $name Full block name.
	 * @return string[]
	 */
	public static function highlight( string $name ): array {
		return self::HIGHLIGHT[ $name ] ?? array();
	}

	/**
	 * Structure hint for a block: { inner?: [...], dynamic?: bool }.
	 *
	 * @param string $name Full block name.
	 * @return array
	 */
	public static function structure( string $name ): array {
		return self::STRUCTURE[ $name ] ?? array();
	}

	/**
	 * @param string $name Full block name.
	 * @return string Documentation URL, or ''.
	 */
	public static function doc_url( string $name ): string {
		$meta = self::block_meta( $name );
		$doc  = $meta['doc'] ?? '';
		return '' !== $doc ? ( 'https://wpspectra.com/docs/' . $doc . '/' ) : '';
	}
}
