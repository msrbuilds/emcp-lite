<?php
/**
 * Sidebar registry for the admin frame (spec 5.1).
 *
 * Visibility comes from EMCP_Tools_Admin::get_submenus() (the single gating
 * source); this class only arranges visible tabs into the design's groups and
 * answers "which entry is current" for the sidebar and the breadcrumb.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grouped sidebar entries, footer links and breadcrumbs.
 */
final class EMCP_Tools_Admin_Nav {

	const SLUG = 'emcp-tools';

	/**
	 * Visible entries in design order.
	 *
	 * @var array[]
	 */
	private $entries = array();

	/**
	 * Footer links.
	 *
	 * @var array[]
	 */
	private $footer = array();

	/**
	 * Build the registry.
	 *
	 * @param string[] $visible_tabs Tab ids that get_submenus() shows.
	 * @param int[]    $counts       Tab id => count badge.
	 * @param bool     $affiliate    Whether the Freemius affiliation page exists.
	 * @param string[] $links        Optional footer URLs: 'account' (the Freemius
	 *                               licence page) and 'upgrade' (unlicensed sites).
	 *                               The WordPress submenu that used to carry them is
	 *                               hidden, so the frame must.
	 */
	public function __construct( array $visible_tabs, array $counts = array(), bool $affiliate = false, array $links = array() ) {
		foreach ( self::definitions() as $tab => $def ) {
			if ( ! in_array( $tab, $visible_tabs, true ) ) {
				continue;
			}
			$count           = isset( $counts[ $tab ] ) && (int) $counts[ $tab ] > 0 ? (int) $counts[ $tab ] : null;
			$this->entries[] = array(
				'id'    => $tab,
				'label' => $def['label'],
				'group' => $def['group'],
				'icon'  => $def['icon'],
				'url'   => self::url( $tab ),
				'count' => $count,
			);
		}
		$this->entries = array_values( (array) apply_filters( 'emcp_tools_admin_nav', $this->entries ) );

		$this->footer[] = array(
			'id'       => 'help',
			'label'    => __( 'Get help', 'emcp-lite' ),
			'icon'     => 'life-buoy',
			'url'      => 'https://emcptools.com/docs',
			'external' => true,
			'badge'    => null,
		);
		if ( in_array( 'changelog', $visible_tabs, true ) ) {
			$this->footer[] = array(
				'id'       => 'changelog',
				'label'    => __( 'Changelog', 'emcp-lite' ),
				'icon'     => 'gift',
				'url'      => self::url( 'changelog' ),
				'external' => false,
				'badge'    => __( 'New', 'emcp-lite' ),
			);
		}
		if ( ! empty( $links['account'] ) ) {
			$this->footer[] = array(
				'id'       => 'account',
				'label'    => __( 'Account', 'emcp-lite' ),
				'icon'     => 'circle-user',
				'url'      => (string) $links['account'],
				'external' => false,
				'badge'    => null,
			);
		}
		if ( ! empty( $links['upgrade'] ) ) {
			$this->footer[] = array(
				'id'       => 'upgrade',
				'label'    => __( 'Upgrade to Pro', 'emcp-lite' ),
				'icon'     => 'crown',
				'url'      => (string) $links['upgrade'],
				'external' => true,
				'badge'    => null,
			);
		}
		if ( $affiliate ) {
			$this->footer[] = array(
				'id'       => 'affiliate',
				'label'    => __( 'Affiliate', 'emcp-lite' ),
				'icon'     => 'dollar-sign',
				'url'      => admin_url( 'admin.php?page=' . self::SLUG . '-affiliation' ),
				'external' => false,
				'badge'    => null,
			);
		}
	}

	/**
	 * Every sidebar tab in design order: label, group and Lucide icon.
	 *
	 * @return array<string, array{label:string, group:string, icon:string}>
	 */
	public static function definitions(): array {
		return array(
			'dashboard'     => array( 'label' => __( 'Dashboard', 'emcp-lite' ), 'group' => 'overview', 'icon' => 'layout-dashboard' ),
			'connection'    => array( 'label' => __( 'Connection', 'emcp-lite' ), 'group' => 'setup', 'icon' => 'plug' ),
			'tools'         => array( 'label' => __( 'Tools', 'emcp-lite' ), 'group' => 'setup', 'icon' => 'wrench' ),
			'modules'       => array( 'label' => __( 'Modules', 'emcp-lite' ), 'group' => 'setup', 'icon' => 'blocks' ),
			'page-builders' => array( 'label' => __( 'Page Builders', 'emcp-lite' ), 'group' => 'setup', 'icon' => 'layout-template' ),
			'management'    => array( 'label' => __( 'Management access', 'emcp-lite' ), 'group' => 'setup', 'icon' => 'lock' ),
			'ai-chat'       => array( 'label' => __( 'AI Chat', 'emcp-lite' ), 'group' => 'build', 'icon' => 'message-square' ),
			'context'       => array( 'label' => __( 'Context', 'emcp-lite' ), 'group' => 'build', 'icon' => 'info' ),
			'prompts'       => array( 'label' => __( 'Prompts', 'emcp-lite' ), 'group' => 'build', 'icon' => 'lightbulb' ),
			'skills'        => array( 'label' => __( 'Skills', 'emcp-lite' ), 'group' => 'build', 'icon' => 'sparkles' ),
			'memory'        => array( 'label' => __( 'Memory', 'emcp-lite' ), 'group' => 'build', 'icon' => 'brain' ),
			'widgets'       => array( 'label' => __( 'Sandbox', 'emcp-lite' ), 'group' => 'build', 'icon' => 'code' ),
			'templates'     => array( 'label' => __( 'Templates', 'emcp-lite' ), 'group' => 'library', 'icon' => 'layout-grid' ),
			'brand-kits'    => array( 'label' => __( 'Brand Kits', 'emcp-lite' ), 'group' => 'library', 'icon' => 'palette' ),
			'marketplace'   => array( 'label' => __( 'Marketplace', 'emcp-lite' ), 'group' => 'library', 'icon' => 'store' ),
			'history'       => array( 'label' => __( 'History', 'emcp-lite' ), 'group' => 'safety', 'icon' => 'history' ),
			'migrate'       => array( 'label' => __( 'Backup & Migrate', 'emcp-lite' ), 'group' => 'safety', 'icon' => 'database-backup' ),
			'redirects'     => array( 'label' => __( 'Redirects', 'emcp-lite' ), 'group' => 'safety', 'icon' => 'shuffle' ),
			'mcp-log'       => array( 'label' => __( 'MCP Log', 'emcp-lite' ), 'group' => 'safety', 'icon' => 'scroll-text' ),
		);
	}

	/**
	 * Group id => label, in display order.
	 *
	 * @return array<string,string>
	 */
	public static function group_labels(): array {
		return array(
			'overview' => __( 'Overview', 'emcp-lite' ),
			'setup'    => __( 'Setup', 'emcp-lite' ),
			'build'    => __( 'Build', 'emcp-lite' ),
			'library'  => __( 'Library', 'emcp-lite' ),
			'safety'   => __( 'Safety', 'emcp-lite' ),
		);
	}

	/**
	 * Child pages shown in the breadcrumb: tab => view => label.
	 *
	 * @return array<string, array<string,string>>
	 */
	private static function subviews(): array {
		return array(
			'skills'  => array(
				'custom' => __( 'Custom skills', 'emcp-lite' ),
			),
			'widgets' => array(
				'widgets'  => __( 'Widgets', 'emcp-lite' ),
				'blocks'   => __( 'Blocks', 'emcp-lite' ),
				'snippets' => __( 'PHP Snippets', 'emcp-lite' ),
				'export'   => __( 'Export as plugin', 'emcp-lite' ),
			),
		);
	}

	/**
	 * Admin URL of a tab (existing page slugs are kept).
	 *
	 * @param string $tab Tab id.
	 */
	public static function url( string $tab ): string {
		return admin_url( 'admin.php?page=' . ( 'dashboard' === $tab ? self::SLUG : self::SLUG . '-' . $tab ) );
	}

	/**
	 * Tab id of a page slug.
	 *
	 * @param string $slug Page slug.
	 */
	public static function tab_from_slug( string $slug ): string {
		return self::SLUG === $slug ? 'dashboard' : (string) preg_replace( '/^' . preg_quote( self::SLUG . '-', '/' ) . '/', '', $slug );
	}

	/**
	 * Visible entries, flat.
	 *
	 * @return array[]
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * Footer links.
	 *
	 * @return array[]
	 */
	public function footer(): array {
		return $this->footer;
	}

	/** Footer items the top bar shows as icons (the avatar is Account). */
	const TOPBAR_LINKS = array( 'help', 'changelog', 'account', 'affiliate' );

	/**
	 * The footer items the top bar carries, keyed by id.
	 *
	 * @return array<string, array>
	 */
	public function topbar_links(): array {
		$out = array();
		foreach ( $this->footer as $item ) {
			if ( in_array( $item['id'], self::TOPBAR_LINKS, true ) ) {
				$out[ $item['id'] ] = $item;
			}
		}
		return $out;
	}

	/**
	 * The footer items that stay in the sidebar (Upgrade to Pro).
	 *
	 * @return array[]
	 */
	public function sidebar_footer(): array {
		return array_values(
			array_filter(
				$this->footer,
				static function ( $item ) {
					return ! in_array( $item['id'], self::TOPBAR_LINKS, true );
				}
			)
		);
	}

	/**
	 * Entries grouped, empty groups dropped.
	 *
	 * @return array[]
	 */
	public function groups(): array {
		$out = array();
		foreach ( self::group_labels() as $id => $label ) {
			$items = array_values(
				array_filter(
					$this->entries,
					static function ( $e ) use ( $id ) {
						return $e['group'] === $id;
					}
				)
			);
			if ( $items ) {
				$out[] = array(
					'id'    => $id,
					'label' => $label,
					'items' => $items,
				);
			}
		}
		return $out;
	}

	/**
	 * The current entry and the breadcrumb for a tab and optional ?view=.
	 *
	 * @param string $tab  Tab id.
	 * @param string $view Sub-view.
	 * @return array{entry:?array, crumbs:string[]}
	 */
	public function current( string $tab, string $view = '' ): array {
		foreach ( $this->entries as $entry ) {
			if ( $entry['id'] !== $tab ) {
				continue;
			}
			$groups = self::group_labels();
			$crumbs = array( $groups[ $entry['group'] ] ?? '', $entry['label'] );
			$child  = self::subviews()[ $tab ][ $view ] ?? null;
			if ( null !== $child ) {
				$crumbs[] = $child;
			}
			return array(
				'entry'  => $entry,
				'crumbs' => array_values( array_filter( $crumbs ) ),
			);
		}
		foreach ( $this->footer as $item ) {
			if ( $item['id'] === $tab ) {
				return array(
					'entry'  => null,
					'crumbs' => array( $item['label'] ),
				);
			}
		}
		return array(
			'entry'  => null,
			'crumbs' => array(),
		);
	}
}
