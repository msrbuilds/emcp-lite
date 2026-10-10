<?php
/**
 * Kadence Blocks catalog.
 *
 * Both halves are sourced from Kadence Blocks itself, so nothing is guessed:
 *  - the block LIST + each block's ATTRIBUTES and DEFAULTS come from the live
 *    `WP_Block_Type_Registry` (Kadence registers full attribute schemas with
 *    defaults, unlike Spectra which needs its attributes.php read).
 * A curated TITLES/CATEGORY map gives friendlier labels + grouping, HIGHLIGHT
 * records the most useful attributes to surface first, and STRUCTURE records
 * container inner-block templates + RichText-content hints that can't be read
 * from the registry (structural, not attribute-guessing). All 32 top-level
 * blocks + their container relationships were live-verified on Kadence Blocks
 * 3.7.9 (see docs/superpowers/specs/2026-08-07-kadence-integration-design.md).
 *
 * @package EMCP_Tools
 * @since   3.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kadence Blocks catalog (list + real attributes + curation).
 */
class EMCP_Tools_Kadence_Blocks_Catalog {

	const PLUGIN_FILE = 'kadence-blocks/kadence-blocks.php';
	const DEFAULT_CAP = 30;

	/**
	 * The 32 top-level (placeable) Kadence block slugs. Child/structural blocks
	 * (column-child, singlebtn, listitem, table-row, advanced-form-*, header-*)
	 * are excluded from the catalog list; they are inserted via STRUCTURE.
	 *
	 * @var string[]
	 */
	const TOP_LEVEL = array(
		'rowlayout', 'column', 'spacer',
		'advancedheading', 'advancedbtn', 'infobox', 'iconlist', 'icon', 'image',
		'advancedgallery', 'testimonials', 'accordion', 'tabs', 'table', 'countup',
		'countdown', 'progress-bar', 'lottie', 'videopopup', 'vector', 'googlemaps',
		'tableofcontents', 'show-more', 'posts', 'form', 'advanced-form',
		'header', 'navigation', 'navigation-link', 'off-canvas-trigger', 'identity', 'search',
	);

	/**
	 * Curated grouping for the catalog (registry category is a flat
	 * "kadence-blocks" for most). Keyed by slug.
	 *
	 * @var array<string,string>
	 */
	const CATEGORY = array(
		'rowlayout' => 'layout', 'column' => 'layout', 'spacer' => 'layout',
		'advancedheading' => 'content', 'advancedbtn' => 'content', 'infobox' => 'content',
		'iconlist' => 'content', 'icon' => 'content', 'image' => 'media',
		'advancedgallery' => 'media', 'testimonials' => 'content', 'accordion' => 'content',
		'tabs' => 'content', 'table' => 'content', 'countup' => 'content', 'countdown' => 'content',
		'progress-bar' => 'content', 'lottie' => 'media', 'videopopup' => 'media', 'vector' => 'media',
		'googlemaps' => 'embed', 'tableofcontents' => 'content', 'show-more' => 'layout',
		'posts' => 'dynamic', 'form' => 'form', 'advanced-form' => 'form',
		'header' => 'header', 'navigation' => 'header', 'navigation-link' => 'header',
		'off-canvas-trigger' => 'header', 'identity' => 'header', 'search' => 'header',
	);

	/**
	 * Friendlier titles/descriptions than the raw registry values. Keyed by slug.
	 *
	 * @var array<string,array{title:string,desc:string}>
	 */
	const TITLES = array(
		'rowlayout'        => array( 'title' => 'Row Layout (Section)', 'desc' => 'A responsive section/row of columns — the primary layout container. Holds kadence/column children.' ),
		'column'           => array( 'title' => 'Section (Column)', 'desc' => 'A single column inside a Row Layout; accepts any inner blocks. Background, padding, border, flex direction.' ),
		'spacer'           => array( 'title' => 'Spacer / Divider', 'desc' => 'Vertical spacing and/or a horizontal divider line.' ),
		'advancedheading'  => array( 'title' => 'Advanced Text / Heading', 'desc' => 'A heading or rich text block. The text is the block content; tag via htmlTag, styling via attributes.' ),
		'advancedbtn'      => array( 'title' => 'Buttons', 'desc' => 'One or more buttons. Each button is a kadence/singlebtn child.' ),
		'infobox'          => array( 'title' => 'Info Box', 'desc' => 'An icon/image + title + text + optional learn-more link card.' ),
		'iconlist'         => array( 'title' => 'Icon List', 'desc' => 'A list of icon + text items. Each item is a kadence/listitem child.' ),
		'icon'             => array( 'title' => 'Icon', 'desc' => 'One or more icons. Each icon is a kadence/single-icon child.' ),
		'image'            => array( 'title' => 'Advanced Image', 'desc' => 'An image with ratio, border, shadow, mask, overlay, caption and link options.' ),
		'advancedgallery'  => array( 'title' => 'Advanced Gallery', 'desc' => 'A grid / masonry / carousel / slider image gallery.' ),
		'testimonials'     => array( 'title' => 'Testimonials', 'desc' => 'A testimonial grid or carousel. Each testimonial is a kadence/testimonial child.' ),
		'accordion'        => array( 'title' => 'Accordion', 'desc' => 'Collapsible panes (FAQ-friendly). Panes are added in the editor; optional FAQ schema.' ),
		'tabs'             => array( 'title' => 'Tabs', 'desc' => 'Tabbed content. Tab titles via the titles attribute; panes are added in the editor.' ),
		'table'            => array( 'title' => 'Table (Advanced)', 'desc' => 'A styled table. Rows/cells are kadence/table-row -> kadence/table-data children.' ),
		'countup'          => array( 'title' => 'Count Up', 'desc' => 'An animated number counter with a title, prefix/suffix.' ),
		'countdown'        => array( 'title' => 'Countdown', 'desc' => 'A countdown timer to a date or evergreen offset.' ),
		'progress-bar'     => array( 'title' => 'Progress Bar', 'desc' => 'An animated progress/percentage bar.' ),
		'lottie'           => array( 'title' => 'Lottie Animation', 'desc' => 'A Lottie/JSON animation with playback controls.' ),
		'videopopup'       => array( 'title' => 'Video Popup', 'desc' => 'A poster image that opens a video in a lightbox.' ),
		'vector'           => array( 'title' => 'Vector / SVG', 'desc' => 'An inline SVG shape.' ),
		'googlemaps'       => array( 'title' => 'Google Maps', 'desc' => 'An embedded Google map by location/coords.' ),
		'tableofcontents'  => array( 'title' => 'Table of Contents', 'desc' => 'An auto-generated table of contents from page headings.' ),
		'show-more'        => array( 'title' => 'Show More', 'desc' => 'A collapsible height container with a show more/less toggle.' ),
		'posts'            => array( 'title' => 'Posts', 'desc' => 'A dynamic grid/list of posts (server-rendered).' ),
		'form'             => array( 'title' => 'Form (classic)', 'desc' => 'The classic Kadence form; fields live in the fields attribute array.' ),
		'advanced-form'    => array( 'title' => 'Advanced Form', 'desc' => 'The newer form container; fields are advanced-form-* child blocks bound to a form CPT.' ),
		'header'           => array( 'title' => 'Header (Advanced)', 'desc' => 'A Kadence header-builder container (theme building).' ),
		'navigation'       => array( 'title' => 'Navigation (Advanced)', 'desc' => 'A Kadence advanced navigation container.' ),
		'navigation-link'  => array( 'title' => 'Navigation Link', 'desc' => 'A link inside an advanced navigation.' ),
		'off-canvas-trigger' => array( 'title' => 'Off Canvas Trigger', 'desc' => 'A hamburger/toggle that opens an off-canvas panel.' ),
		'identity'         => array( 'title' => 'Site Identity', 'desc' => 'Site title/logo + tagline block (header building).' ),
		'search'           => array( 'title' => 'Search (Advanced)', 'desc' => 'A Kadence advanced search field/modal.' ),
	);

	/**
	 * Curated highlight attribute names per full block name (real registry
	 * names). Empty/absent -> get-block-schema returns the first DEFAULT_CAP
	 * attributes. These are the attributes an agent most often sets.
	 *
	 * @var array<string,string[]>
	 */
	const HIGHLIGHT = array(
		'kadence/rowlayout'       => array( 'columns', 'colLayout', 'bgColor', 'bgImg', 'padding', 'maxWidth', 'minHeight', 'verticalAlignment', 'align', 'columnGutter', 'topSep', 'bottomSep', 'htmlTag' ),
		'kadence/column'          => array( 'background', 'backgroundType', 'textAlign', 'textColor', 'padding', 'borderRadius', 'direction', 'justifyContent', 'verticalAlignment', 'gutter', 'htmlTag' ),
		'kadence/spacer'          => array( 'spacerHeight', 'hAlign', 'dividerEnable', 'dividerStyle', 'dividerColor', 'dividerWidth', 'dividerHeight' ),
		'kadence/advancedheading' => array( 'content', 'htmlTag', 'align', 'color', 'size', 'typography', 'fontWeight', 'link', 'markColor', 'markBG' ),
		'kadence/advancedbtn'     => array( 'hAlign', 'btnCount', 'orientation', 'widthType', 'typography', 'gap' ),
		'kadence/infobox'         => array( 'title', 'contentText', 'mediaType', 'mediaIcon', 'mediaImage', 'hAlign', 'titleColor', 'textColor', 'containerBackground', 'link', 'learnMore', 'displayLearnMore' ),
		'kadence/iconlist'        => array( 'columns', 'iconAlign', 'color', 'listGap', 'listStyles', 'blockAlignment', 'linkColor' ),
		'kadence/icon'            => array( 'iconCount', 'textAlignment', 'blockAlignment', 'gap', 'verticalAlignment' ),
		'kadence/image'           => array( 'url', 'alt', 'id', 'align', 'width', 'height', 'ratio', 'useRatio', 'borderRadius', 'link', 'caption', 'showCaption' ),
		'kadence/advancedgallery' => array( 'type', 'columns', 'images', 'imageRatio', 'gutter', 'linkTo', 'lightbox', 'captionStyle' ),
		'kadence/testimonials'    => array( 'style', 'layout', 'columns', 'displayTitle', 'displayContent', 'displayName', 'displayOccupation', 'displayIcon', 'hAlign' ),
		'kadence/accordion'       => array( 'paneCount', 'titleAlignment', 'textColor', 'linkColor', 'contentBgColor', 'showIcon', 'iconStyle', 'faqSchema', 'columnLayout' ),
		'kadence/tabs'            => array( 'tabCount', 'layout', 'titles', 'tabAlignment', 'titleColor', 'titleColorActive', 'contentBgColor', 'blockAlignment' ),
		'kadence/table'           => array( 'columns', 'dataTypography', 'headerTypography', 'backgroundColorEven', 'backgroundColorOdd', 'textAlign', 'isFirstRowHeader', 'enableCaption' ),
		'kadence/countup'         => array( 'title', 'start', 'end', 'prefix', 'suffix', 'duration', 'numberColor', 'titleColor', 'numberAlign', 'titleAlign' ),
		'kadence/countdown'       => array( 'countdownType', 'date', 'units', 'timerLayout', 'numberColor', 'labelColor', 'counterAlign' ),
		'kadence/progress-bar'    => array( 'label', 'progressAmount', 'progressMax', 'progressColor', 'barBackground', 'displayPercent', 'barType', 'hAlign' ),
		'kadence/lottie'          => array( 'fileSrc', 'fileUrl', 'localFile', 'autoplay', 'loop', 'width', 'align' ),
		'kadence/videopopup'      => array( 'type', 'url', 'media', 'ratio', 'playBtn', 'maxWidth', 'align' ),
		'kadence/vector'          => array( 'id', 'maxWidth', 'align', 'margin', 'padding' ),
		'kadence/googlemaps'      => array( 'location', 'zoom', 'heightDesktop', 'mapType', 'apiType', 'showMarker' ),
		'kadence/tableofcontents' => array( 'title', 'allowedHeaders', 'listStyle', 'enableTitle', 'enableToggle', 'containerBackground' ),
		'kadence/show-more'       => array( 'showHideMore', 'heightDesktop', 'enableFadeOut', 'fadeOutSize' ),
		'kadence/posts'           => array( 'postType', 'columns', 'postsToShow', 'orderBy', 'order', 'image', 'imageRatio', 'excerpt', 'readmore', 'meta' ),
		'kadence/form'            => array( 'hAlign', 'submitLabel', 'email', 'redirect', 'style' ),
		'kadence/advanced-form'   => array( 'id' ),
		'kadence/header'          => array( 'id' ),
		'kadence/navigation'      => array( 'id', 'makePost', 'ariaLabel' ),
		'kadence/navigation-link' => array( 'label', 'url', 'type', 'target' ),
		'kadence/off-canvas-trigger' => array( 'icon', 'iconSize', 'iconColor', 'label' ),
		'kadence/identity'        => array( 'showSiteTitle', 'showSiteTagline', 'align', 'linkToHomepage' ),
		'kadence/search'          => array( 'displayStyle', 'showButton', 'inputPlaceholder', 'inputColor' ),
	);

	/**
	 * Structural hints not derivable from the registry, keyed by full block name.
	 * `inner`: container inner-block template (child name + count key/default).
	 * `text` : the block stores display text as a RichText `content`/`title`
	 *          attribute rendered into the saved HTML (not the attrs JSON) —
	 *          add-block emits a tag wrapper. { attr, tag_attr?, tag?, class }.
	 * `note` : an editor-only/structural caveat surfaced in get-block-schema.
	 *
	 * @var array<string,array>
	 */
	const STRUCTURE = array(
		// colLayout MUST be non-empty or the editor shows Kadence's "Select Your
		// Layout" picker instead of the columns (the frontend renders regardless).
		// "equal" = equal-width columns for any count; verified against the block's
		// own `!colLayout` gate in blocks-rowlayout.js.
		'kadence/rowlayout'    => array(
			'inner'           => array( 'child' => 'kadence/column', 'count_attr' => 'columns', 'default_count' => 2 ),
			'container_attrs' => array( 'colLayout' => 'equal' ),
		),
		'kadence/advancedbtn'  => array( 'inner' => array( 'child' => 'kadence/singlebtn', 'default_count' => 1 ) ),
		'kadence/icon'         => array( 'inner' => array( 'child' => 'kadence/single-icon', 'default_count' => 1 ) ),
		'kadence/iconlist'     => array( 'inner' => array( 'child' => 'kadence/listitem', 'default_count' => 3 ) ),
		'kadence/testimonials' => array( 'inner' => array( 'child' => 'kadence/testimonial', 'default_count' => 2 ) ),
		'kadence/advancedheading' => array( 'text' => array( 'attr' => 'content', 'tag_attr' => 'htmlTag', 'tag' => 'h2', 'class' => 'kt-adv-heading-wrap' ) ),
		'kadence/tabs'         => array( 'note' => 'Tab panes are inner blocks added in the editor; set tab titles via the titles attribute. add-block inserts the container only.' ),
		'kadence/accordion'    => array( 'note' => 'Accordion panes are inner blocks added in the editor. add-block inserts the container only.' ),
		'kadence/table'        => array( 'note' => 'Rows/cells are kadence/table-row -> kadence/table-data children, added in the editor. add-block inserts the container only.' ),
		'kadence/form'         => array( 'note' => 'Form fields live in the "fields" attribute array (not inner blocks); see the block docs for field shapes.' ),
		'kadence/advanced-form' => array( 'note' => 'The Advanced Form binds to a form CPT (id attribute) whose fields are advanced-form-* child blocks; author it in the editor.' ),
		'kadence/column'       => array( 'container' => true ),
	);

	/**
	 * @return bool Whether Kadence Blocks is active.
	 */
	public static function is_active(): bool {
		if ( defined( 'KADENCE_BLOCKS_VERSION' ) ) {
			return true;
		}
		return function_exists( 'is_plugin_active' ) && is_plugin_active( self::PLUGIN_FILE );
	}

	/**
	 * Compact block index (curated title/desc/category, dynamic flag), filtered
	 * to the registered top-level blocks.
	 *
	 * @param string $category Optional curated-category filter.
	 * @param string $search   Optional case-insensitive filter.
	 * @return array<int,array>
	 */
	public static function blocks_index( string $category = '', string $search = '' ): array {
		$search = strtolower( trim( $search ) );
		$out    = array();
		foreach ( self::TOP_LEVEL as $slug ) {
			$name = 'kadence/' . $slug;
			if ( ! self::block_exists( $name ) ) {
				continue;
			}
			$bt  = self::registered( $name );
			$cat = self::CATEGORY[ $slug ] ?? 'content';
			if ( '' !== $category && $cat !== $category ) {
				continue;
			}
			$title = self::TITLES[ $slug ]['title'] ?? ( ( $bt && is_string( $bt->title ) ) ? $bt->title : $name );
			$desc  = self::TITLES[ $slug ]['desc'] ?? ( ( $bt && is_string( $bt->description ) ) ? $bt->description : '' );
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
				'category'    => $cat,
			);
		}
		return $out;
	}

	/**
	 * Whether a block is present — seeded test attributes take precedence, else
	 * the live registry. Lets the catalog be exercised without real blocks.
	 *
	 * @param string $name Full block name.
	 * @return bool
	 */
	public static function block_exists( string $name ): bool {
		if ( isset( $GLOBALS['_kadence_block_attributes'][ $name ] ) ) {
			return true;
		}
		return null !== self::registered( $name );
	}

	/**
	 * The registered block type for a full name, or null.
	 *
	 * @param string $name Full block name.
	 * @return WP_Block_Type|null
	 */
	public static function registered( string $name ) {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return null;
		}
		return WP_Block_Type_Registry::get_instance()->get_registered( $name );
	}

	/**
	 * A block's real attributes (name => default) from the live registry. Tests
	 * seed $GLOBALS['_kadence_block_attributes'][$name].
	 *
	 * @param string $name Full block name.
	 * @return array<string,mixed>
	 */
	public static function real_attributes( string $name ): array {
		if ( isset( $GLOBALS['_kadence_block_attributes'][ $name ] ) ) {
			return (array) $GLOBALS['_kadence_block_attributes'][ $name ];
		}
		$bt = self::registered( $name );
		if ( null === $bt || empty( $bt->attributes ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $bt->attributes as $key => $def ) {
			$out[ $key ] = is_array( $def ) && array_key_exists( 'default', $def ) ? $def['default'] : null;
		}
		return $out;
	}

	/**
	 * @param string $name Full block name.
	 * @return string[] Curated highlight attribute names, or [].
	 */
	public static function highlight( string $name ): array {
		return self::HIGHLIGHT[ $name ] ?? array();
	}

	/**
	 * @param string $name Full block name.
	 * @return array Structure hint ({ inner?, text?, note?, container? }), or [].
	 */
	public static function structure( string $name ): array {
		return self::STRUCTURE[ $name ] ?? array();
	}

	/**
	 * Whether a full block name is a known top-level Kadence block.
	 *
	 * @param string $name Full block name.
	 * @return bool
	 */
	public static function is_known( string $name ): bool {
		$slug = ( 0 === strpos( $name, 'kadence/' ) ) ? substr( $name, 8 ) : $name;
		return in_array( $slug, self::TOP_LEVEL, true ) && self::block_exists( 'kadence/' . $slug );
	}

	/**
	 * The Kadence docs URL for a block.
	 *
	 * @param string $name Full block name.
	 * @return string
	 */
	public static function doc_url( string $name ): string {
		$slug = ( 0 === strpos( $name, 'kadence/' ) ) ? substr( $name, 8 ) : $name;
		return 'https://www.kadencewp.com/kadence-blocks/';
	}
}
