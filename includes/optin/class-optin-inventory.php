<?php
/**
 * The installation details a sharing site sends: never the admin's name or email.
 *
 * Hooks only mark the inventory as changed (once per request); the payload is
 * built and hashed later, on an eligible admin request, because core fires
 * deactivated_plugin before it saves active_plugins.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EMCP_Tools_Optin_Inventory {

	/** @var bool Whether this request already marked (or must not send). */
	private static $marked = false;

	/** Hooks that change the inventory. */
	public static function init(): void {
		add_action( 'switch_theme', array( __CLASS__, 'mark_changed' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'mark_changed' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'mark_changed' ) );
		add_action( 'update_option_emcp_tools_page_builder', array( __CLASS__, 'mark_changed' ) );
		add_action( 'add_option_emcp_tools_page_builder', array( __CLASS__, 'mark_changed' ) );
	}

	/** Test seam: forget this request's mark. */
	public static function reset_request(): void {
		self::$marked = false;
	}

	/** Whether this request marked the inventory (it then sends nothing). */
	public static function marked_this_request(): bool {
		return self::$marked;
	}

	/** Flag the inventory as changed. Writes at most once per request, only while sharing. */
	public static function mark_changed(): void {
		if ( self::$marked ) {
			return;
		}
		self::$marked = true;
		EMCP_Tools_Optin_State::transition(
			function ( array $s ) {
				if ( 'allowed' !== $s['state'] || $s['inv_changed'] ) {
					return null;
				}
				$s['inv_changed'] = true;
				return $s;
			}
		);
	}

	/** The installation fields, as the website expects them. */
	public static function build(): array {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all     = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
		$active  = (array) get_option( 'active_plugins', array() );
		$plugins = array();
		foreach ( $active as $file ) {
			$file = (string) $file;
			if ( ! isset( $all[ $file ] ) ) {
				continue;
			}
			$slug      = false === strpos( $file, '/' ) ? preg_replace( '/\.php$/', '', $file ) : strtok( $file, '/' );
			$plugins[] = array(
				'slug'    => self::cap( (string) $slug, 200 ),
				'name'    => self::cap( wp_strip_all_tags( (string) ( $all[ $file ]['Name'] ?? '' ) ), 200 ),
				'version' => self::cap( (string) ( $all[ $file ]['Version'] ?? '' ), 40 ),
			);
		}
		usort( $plugins, fn( $a, $b ) => strcmp( $a['slug'], $b['slug'] ) );
		$plugins = array_slice( $plugins, 0, 500 );

		$theme  = wp_get_theme();
		$parent = $theme->parent();

		return array(
			'site_title'       => self::cap( html_entity_decode( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 200 ),
			'wp_version'       => self::cap( (string) get_bloginfo( 'version' ), 40 ),
			'php_version'      => self::cap( PHP_VERSION, 40 ),
			'lite_version'     => self::cap( (string) EMCP_TOOLS_VERSION, 40 ),
			'locale'           => self::cap( (string) get_locale(), 20 ),
			'multisite'        => (bool) is_multisite(),
			'theme'            => array(
				'name'    => self::cap( (string) $theme->get( 'Name' ), 200 ),
				'version' => self::cap( (string) $theme->get( 'Version' ), 40 ),
				'parent'  => $parent ? self::cap( (string) $parent->get( 'Name' ), 200 ) : null,
			),
			'plugins'          => $plugins,
			'builder_selected' => self::cap( (string) get_option( 'emcp_tools_page_builder', '' ), 60 ),
		);
	}

	/** A stable digest of the fields. */
	public static function hash( array $inventory ): string {
		return hash( 'sha256', (string) wp_json_encode( $inventory ) );
	}

	private static function cap( string $value, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
