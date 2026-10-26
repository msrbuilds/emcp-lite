<?php
/**
 * Prompts screen data (spec 8.8): the bundled samples (the synced library
 * ships with EMCP Pro), paged 12 at a time with full content for Copy.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Admin_Prompts_Data {

	const PER_PAGE = 12;

	/** Samples shown on free builds (file name => meta) (the bundled prompts/ folder). */
	private static function samples(): array {
		return array(
			'LOCAL_BUSINESS'          => array( __( 'Local Business', 'emcp-lite' ), __( 'General', 'emcp-lite' ), __( 'Multi-purpose small business landing page with hero, services, testimonials, and contact section.', 'emcp-lite' ) ),
			'DENTAL_CLINIC'           => array( __( 'Dental Clinic', 'emcp-lite' ), __( 'Health & Wellness', 'emcp-lite' ), __( 'Professional dental practice with services grid, team profiles, insurance info, and appointment booking.', 'emcp-lite' ) ),
			'WEB_DEVELOPER_PORTFOLIO' => array( __( 'Web Developer Portfolio', 'emcp-lite' ), __( 'Professional Services', 'emcp-lite' ), __( 'Developer portfolio with project showcase, tech stack, GitHub stats, and contact form.', 'emcp-lite' ) ),
			'HAIR_SALON'              => array( __( 'Hair Salon', 'emcp-lite' ), __( 'Lifestyle', 'emcp-lite' ), __( 'Stylish salon page with services menu, stylist profiles, gallery, and online booking.', 'emcp-lite' ) ),
			'CAR_WASH'                => array( __( 'Car Wash', 'emcp-lite' ), __( 'Lifestyle', 'emcp-lite' ), __( 'Car wash site with wash packages, add-on services, membership plans, and booking form.', 'emcp-lite' ) ),
		);
	}

	/** The free samples as a library bundle. */
	private static function free_bundle(): array {
		$categories = array();
		foreach ( self::samples() as $file => $meta ) {
			$path = EMCP_TOOLS_DIR . 'prompts/' . $file . '.md';
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$cat = sanitize_title( $meta[1] );
			if ( ! isset( $categories[ $cat ] ) ) {
				$categories[ $cat ] = array( 'slug' => $cat, 'label' => $meta[1], 'prompts' => array() );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin file.
			$categories[ $cat ]['prompts'][] = array( 'slug' => sanitize_title( $file ), 'title' => $meta[0], 'description' => $meta[2], 'content' => (string) file_get_contents( $path ), 'tier' => 'free' );
		}
		return array( 'categories' => array_values( $categories ) );
	}

	/**
	 * Prompt titles for the command palette, from the bundled samples. Never
	 * fetches (spec 7: the palette must stay instant).
	 */
	public static function palette_items(): array {
		// No titles for a screen that is switched off: WordPress would refuse the link.
		if ( class_exists( 'EMCP_Tools_Modules_Registry' ) ) {
			$module = EMCP_Tools_Modules_Registry::instance()->get( 'prompts' );
			if ( $module && ! ( $module->is_active() && $module->is_available() ) ) {
				return array();
			}
		}
		$categories = self::free_bundle()['categories'];
		$base = admin_url( 'admin.php?page=emcp-tools-prompts' );
		$out  = array();
		foreach ( $categories as $cat ) {
			foreach ( (array) ( $cat['prompts'] ?? array() ) as $p ) {
				$title = (string) ( $p['title'] ?? '' );
				if ( '' !== $title ) {
					$out[] = array(
						'kind'  => 'prompt',
						'label' => $title,
						'hint'  => (string) ( $cat['label'] ?? '' ),
						'url'   => $base . '&q=' . rawurlencode( $title ),
					);
				}
			}
		}
		return $out;
	}

	public function find( string $category, string $slug ): ?array {
		foreach ( self::free_bundle()['categories'] as $cat ) {
			if ( ( $cat['slug'] ?? '' ) !== $category ) {
				continue;
			}
			foreach ( $cat['prompts'] ?? array() as $prompt ) {
				if ( ( $prompt['slug'] ?? '' ) === $slug ) {
					return $prompt;
				}
			}
		}
		return null;
	}

	public function payload( array $query = array() ): array {
		$list  = EMCP_Tools_Admin_Library_List::build(
			self::free_bundle()['categories'],
			'prompts',
			static function ( array $p ): string {
				return ( $p['title'] ?? '' ) . ' ' . ( $p['description'] ?? '' );
			},
			$query,
			self::PER_PAGE
		);
		$items = array_map(
			static function ( array $p ): array {
				return array(
					'slug'          => (string) $p['slug'],
					'category'      => (string) $p['category'],
					'categoryLabel' => (string) $p['categoryLabel'],
					'title'         => (string) ( $p['title'] ?? '' ),
					'description'   => (string) ( $p['description'] ?? '' ),
					'content'       => (string) ( $p['content'] ?? '' ),
					'tier'          => (string) ( $p['tier'] ?? 'free' ),
				);
			},
			$list['items']
		);
		return array_merge(
			$list,
			array(
				'items'           => $items,
				'source'          => 'free',
				'syncedAt'        => 0,
				'error'           => '',
				'licensed'        => false,
				'noticeDismissed' => EMCP_Tools_Admin::prompts_notice_dismissed(),
				'v1Url'           => '',
				'aiChatUrl'       => '',
				'upgradeUrl'      => function_exists( 'emcp_tools_upgrade_url' ) ? emcp_tools_upgrade_url() : 'https://emcptools.com/pricing',
			)
		);
	}
}
