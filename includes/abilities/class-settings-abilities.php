<?php
/**
 * WordPress site-settings MCP abilities.
 *
 * get-settings reads a CURATED, TYPED ALLOWLIST of core WordPress settings (the
 * Settings → General/Reading/Writing/Discussion/Media/Permalinks screens).
 * Arbitrary get_option over any key is deliberately NOT exposed. EMCP Lite never
 * writes site settings: they are changed by an administrator in wp-admin.
 * Requires manage_options.
 *
 * Naming: distinct from EMCP_Tools_Settings_Validator (which validates Elementor
 * widget settings) — unrelated class, no collision.
 *
 * @package EMCP_Tools
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the WordPress settings abilities.
 *
 * @since 3.0.0
 */
class EMCP_Tools_Settings_Abilities {

	/**
	 * Names of the abilities actually registered by register().
	 *
	 * @since 3.0.0
	 * @var string[]
	 */
	private $ability_names = array();

	/**
	 * Returns the names of all abilities registered by this group.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Registers this group's MCP abilities.
	 *
	 * @since 3.0.0
	 */
	public function register(): void {
		$this->register_get_settings();
	}

	/**
	 * Permission gate: reading site settings requires manage_options.
	 *
	 * @since 3.0.0
	 * @return bool
	 */
	public function check_manage_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// ---------------------------------------------------------------------
	// Allowlist (source of truth)
	// ---------------------------------------------------------------------

	/**
	 * The typed allowlist: option name => metadata.
	 *
	 * type: string|int|bool|enum. options: enum members. group: the Settings
	 * screen it belongs to.
	 *
	 * @since 3.0.0
	 * @return array<string,array>
	 */
	private static function allowlist(): array {
		$settings = array(
			// General.
			'blogname'                      => array( 'group' => 'general', 'label' => 'Site Title', 'type' => 'string' ),
			'blogdescription'               => array( 'group' => 'general', 'label' => 'Tagline', 'type' => 'string' ),
			'admin_email'                   => array( 'group' => 'general', 'label' => 'Administration Email', 'type' => 'string' ),
			'timezone_string'               => array( 'group' => 'general', 'label' => 'Timezone', 'type' => 'string' ),
			'gmt_offset'                    => array( 'group' => 'general', 'label' => 'GMT Offset', 'type' => 'string' ),
			'date_format'                   => array( 'group' => 'general', 'label' => 'Date Format', 'type' => 'string' ),
			'time_format'                   => array( 'group' => 'general', 'label' => 'Time Format', 'type' => 'string' ),
			'start_of_week'                 => array( 'group' => 'general', 'label' => 'Week Starts On', 'type' => 'int' ),
			'WPLANG'                        => array( 'group' => 'general', 'label' => 'Site Language', 'type' => 'string' ),
			// Reading.
			'show_on_front'                 => array( 'group' => 'reading', 'label' => 'Front Page Displays', 'type' => 'enum', 'options' => array( 'posts', 'page' ) ),
			'page_on_front'                 => array( 'group' => 'reading', 'label' => 'Front Page', 'type' => 'int' ),
			'page_for_posts'                => array( 'group' => 'reading', 'label' => 'Posts Page', 'type' => 'int' ),
			'posts_per_page'                => array( 'group' => 'reading', 'label' => 'Blog Pages Show At Most', 'type' => 'int' ),
			'posts_per_rss'                 => array( 'group' => 'reading', 'label' => 'Syndication Feeds Show', 'type' => 'int' ),
			'rss_use_excerpt'               => array( 'group' => 'reading', 'label' => 'Feed Shows Summary', 'type' => 'bool' ),
			'blog_public'                   => array( 'group' => 'reading', 'label' => 'Search Engine Visibility', 'type' => 'bool' ),
			// Writing.
			'default_category'              => array( 'group' => 'writing', 'label' => 'Default Post Category', 'type' => 'int' ),
			'default_post_format'           => array( 'group' => 'writing', 'label' => 'Default Post Format', 'type' => 'enum', 'options' => array( '0', 'aside', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio', 'chat' ) ),
			// Discussion.
			'default_comment_status'        => array( 'group' => 'discussion', 'label' => 'Allow Comments By Default', 'type' => 'enum', 'options' => array( 'open', 'closed' ) ),
			'default_ping_status'           => array( 'group' => 'discussion', 'label' => 'Allow Pingbacks By Default', 'type' => 'enum', 'options' => array( 'open', 'closed' ) ),
			'comment_registration'          => array( 'group' => 'discussion', 'label' => 'Users Must Register To Comment', 'type' => 'bool' ),
			'require_name_email'            => array( 'group' => 'discussion', 'label' => 'Comment Author Must Fill Name/Email', 'type' => 'bool' ),
			'comment_moderation'            => array( 'group' => 'discussion', 'label' => 'Hold Comments For Moderation', 'type' => 'bool' ),
			'comments_per_page'             => array( 'group' => 'discussion', 'label' => 'Comments Per Page', 'type' => 'int' ),
			'thread_comments'               => array( 'group' => 'discussion', 'label' => 'Enable Threaded Comments', 'type' => 'bool' ),
			'close_comments_for_old_posts'  => array( 'group' => 'discussion', 'label' => 'Auto-Close Comments On Old Posts', 'type' => 'bool' ),
			// Media.
			'thumbnail_size_w'              => array( 'group' => 'media', 'label' => 'Thumbnail Width', 'type' => 'int' ),
			'thumbnail_size_h'              => array( 'group' => 'media', 'label' => 'Thumbnail Height', 'type' => 'int' ),
			'medium_size_w'                 => array( 'group' => 'media', 'label' => 'Medium Width', 'type' => 'int' ),
			'medium_size_h'                 => array( 'group' => 'media', 'label' => 'Medium Height', 'type' => 'int' ),
			'large_size_w'                  => array( 'group' => 'media', 'label' => 'Large Width', 'type' => 'int' ),
			'large_size_h'                  => array( 'group' => 'media', 'label' => 'Large Height', 'type' => 'int' ),
			'uploads_use_yearmonth_folders' => array( 'group' => 'media', 'label' => 'Organize Uploads Into Month/Year Folders', 'type' => 'bool' ),
			// Permalinks.
			'permalink_structure'           => array( 'group' => 'permalinks', 'label' => 'Permalink Structure', 'type' => 'string' ),
			'category_base'                 => array( 'group' => 'permalinks', 'label' => 'Category Base', 'type' => 'string' ),
			'tag_base'                      => array( 'group' => 'permalinks', 'label' => 'Tag Base', 'type' => 'string' ),
		);
		return $settings;
	}

	/** Valid group names (the Settings screens). */
	private static function groups(): array {
		return array( 'general', 'reading', 'writing', 'discussion', 'media', 'permalinks' );
	}

	// ---------------------------------------------------------------------
	// get-settings
	// ---------------------------------------------------------------------

	private function register_get_settings(): void {
		$this->ability_names[] = 'emcp-tools/get-settings';
		emcp_tools_register_ability(
			'emcp-tools/get-settings',
			array(
				'label'               => __( 'Get Settings', 'emcp-lite' ),
				'description'         => __( 'Reads curated WordPress site settings (General, Reading, Writing, Discussion, Media, Permalinks). With no args returns every allowlisted setting; pass "group" to filter to one screen or "keys" for specific settings. Each row carries the value plus metadata (type, label, enum options). Read-only: site settings are changed by an administrator in wp-admin.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_get_settings' ),
				'permission_callback' => array( $this, 'check_manage_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'group' => array( 'type' => 'string', 'enum' => self::groups(), 'description' => __( 'Filter to one Settings screen.', 'emcp-lite' ) ),
						'keys'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => __( 'Return only these allowlisted keys.', 'emcp-lite' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'settings' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Execute: get-settings
	// ---------------------------------------------------------------------

	/**
	 * @param array $input
	 * @return array
	 */
	public function execute_get_settings( $input ): array {
		$map   = self::allowlist();
		$group = isset( $input['group'] ) ? sanitize_key( (string) $input['group'] ) : '';
		$keys  = ( isset( $input['keys'] ) && is_array( $input['keys'] ) )
			? array_map( 'strval', $input['keys'] )
			: array();

		$rows = array();
		foreach ( $map as $key => $entry ) {
			if ( '' !== $group && $entry['group'] !== $group ) {
				continue;
			}
			if ( ! empty( $keys ) && ! in_array( $key, $keys, true ) ) {
				continue;
			}
			$row = array(
				'key'      => $key,
				'group'    => $entry['group'],
				'label'    => $entry['label'],
				'type'     => $entry['type'],
				'value'    => $this->read_setting( $key, $entry ),
			);
			if ( 'enum' === $entry['type'] && ! empty( $entry['options'] ) ) {
				$row['options'] = array_values( $entry['options'] );
			}
			$rows[] = $row;
		}
		return array( 'settings' => $rows );
	}

	/**
	 * Read an option and coerce it to the declared type for clean JSON.
	 *
	 * @param string $key
	 * @param array  $entry Allowlist entry.
	 * @return mixed
	 */
	private function read_setting( string $key, array $entry ) {
		$raw = get_option( $key );
		switch ( $entry['type'] ) {
			case 'int':
				return (int) $raw;
			case 'bool':
				return ! empty( $raw ) && '0' !== $raw;
			case 'enum':
			case 'string':
			default:
				return null === $raw ? '' : (string) $raw;
		}
	}
}
