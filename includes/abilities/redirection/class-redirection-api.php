<?php
/** Native Redirection adapter. No raw SQL or endpoint passthrough is exposed. */
defined( 'ABSPATH' ) || exit;

final class EMCP_Tools_Redirection_Api {

	public static function active(): bool {
		return defined( 'REDIRECTION_VERSION' ) && class_exists( 'Red_Item' ) && class_exists( 'Red_Group' ) && class_exists( 'Redirection_Capabilities' );
	}

	public static function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message );
	}

	public static function ready() {
		if ( ! self::active() ) {
			return self::error( 'plugin_inactive', __( 'Activate Redirection to use this integration.', 'emcp-lite' ) );
		}
		if ( '5.10.1' !== REDIRECTION_VERSION ) {
			return self::error( 'unsupported_version', __( 'This integration is qualified for Redirection 5.10.1. Other versions need qualification.', 'emcp-lite' ) );
		}
		global $wpdb;
		foreach ( array( 'redirection_items', 'redirection_groups', 'redirection_logs', 'redirection_404' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return self::error( 'setup_required', __( 'Finish Redirection database setup in its own admin screen.', 'emcp-lite' ) );
			}
		}
		return true;
	}

	public static function allowed( string $cap ): bool {
		return self::active() && current_user_can( 'manage_options' ) && Redirection_Capabilities::has_access( 'redirection_cap_' . $cap );
	}

	public static function group( int $id ): ?array {
		$group = Red_Group::get( $id, true );
		return $group ? $group->to_json() : null;
	}

	public static function item( int $id ): ?array {
		$item = Red_Item::get_by_id( $id );
		return $item ? $item->to_json() : null;
	}

	public static function config( array $item ): array {
		unset( $item['hits'], $item['last_access'], $item['version'] );
		return $item;
	}

	public static function hash( string $kind, int $id ): string {
		if ( 'group' === $kind ) {
			$state = self::group( $id );
			if ( null === $state ) { return 'absent'; }
			$children = array();
			$page = 0;
			do {
				$list = Red_Item::get_filtered( array( 'filterBy' => array( 'group' => $id ), 'per_page' => 100, 'page' => $page++ ) );
				foreach ( $list['items'] as $row ) { $children[] = self::config( $row ); }
			} while ( count( $children ) < $list['total'] && count( $list['items'] ) );
			$state['children'] = $children;
		} else {
			$state = self::item( $id );
			if ( null === $state ) { return 'absent'; }
			$state = self::config( $state );
			$group = self::group( (int) $state['group_id'] );
			unset( $group['redirects'] );
			$state['group'] = $group;
		}
		return hash( 'sha256', wp_json_encode( $state ) );
	}

	public static function view( string $kind, int $id ) {
		$row = 'group' === $kind ? self::group( $id ) : self::item( $id );
		if ( null === $row ) { return self::error( 'not_found', __( 'Redirection object not found.', 'emcp-lite' ) ); }
		$row['version'] = self::hash( $kind, $id );
		return $row;
	}

	public static function supported( array $item ): bool {
		return 'url' === $item['match_type'] && 'url' === $item['action_type'] && empty( $item['regex'] )
			&& isset( $item['action_data']['url'] ) && in_array( (int) $item['action_code'], array( 301, 302, 303, 307, 308 ), true );
	}

	public static function wp_group( int $id ) {
		$group = self::group( $id );
		return $group && 1 === (int) $group['module_id']
			? true : self::error( 'linked_unavailable', __( 'Select an existing WordPress-module Redirection group.', 'emcp-lite' ) );
	}

	public static function url( string $value, bool $source = false ): bool {
		if ( '' === $value || strlen( $value ) > 2000 || preg_match( '/[\x00-\x20\x7f\\\\]/', $value ) || preg_match( '/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $value ) ) { return false; }
		if ( '/' === $value[0] && 0 !== strpos( $value, '//' ) ) { return false === strpos( $value, '#' ); }
		if ( $source ) { return false; }
		$parts = wp_parse_url( $value );
		return is_array( $parts ) && in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true )
			&& ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
	}

	/** Reject known literal local cycles, without making requests to target URLs. */
	public static function cycle( string $source, string $target, int $exclude = 0 ): bool {
		$seen = array( $source => true );
		for ( $hop = 0; $hop < 25; ++$hop ) {
			if ( 0 !== strpos( $target, '/' ) ) {
				$base = wp_parse_url( home_url() );
				$parts = wp_parse_url( $target );
				if ( ! $parts || strtolower( $parts['host'] ?? '' ) !== strtolower( $base['host'] ?? '' ) ) { return false; }
				$target = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
			}
			if ( isset( $seen[ $target ] ) ) { return true; }
			$seen[ $target ] = true;
			$list = Red_Item::get_filtered( array( 'filterBy' => array( 'url' => $target, 'url-match' => 'plain' ), 'per_page' => 100 ) );
			$next = null;
			foreach ( $list['items'] as $item ) {
				if ( $exclude !== (int) $item['id'] && $item['url'] === $target && ! empty( $item['enabled'] ) && self::supported( $item ) ) { $next = $item['action_data']['url']; break; }
			}
			if ( null === $next ) { return false; }
			$target = $next;
		}
		return true;
	}

	public static function read( string $operation, array $args ) {
		if ( 'status' === $operation ) { return array( 'version' => REDIRECTION_VERSION, 'qualified_version' => '5.10.1', 'write_module' => 'WordPress' ); }
		if ( 'get-redirect' === $operation || 'get-group' === $operation ) { return self::view( 'get-group' === $operation ? 'group' : 'redirect', $args['id'] ); }
		$params = array( 'page' => $args['page'] ?? 0, 'per_page' => $args['per_page'] ?? 20 );
		if ( 'list-groups' === $operation ) {
			if ( isset( $args['search'] ) ) { $params['filterBy'] = array( 'name' => $args['search'] ); }
			return Red_Group::get_filtered( $params );
		}
		if ( 'list-redirects' === $operation ) {
			if ( isset( $args['group_id'] ) ) { $params['filterBy']['group'] = $args['group_id']; }
			if ( isset( $args['search'] ) ) { $params['filterBy']['url'] = $args['search']; }
			return Red_Item::get_filtered( $params );
		}
		if ( isset( $args['search'] ) ) { $params['filterBy']['url'] = $args['search']; }
		$result = 'list-404s' === $operation ? Red_404_Log::get_filtered( $params ) : Red_Redirect_Log::get_filtered( $params );
		foreach ( $result['items'] as &$row ) { unset( $row['ip'], $row['agent'], $row['referrer'], $row['http_headers'], $row['request_data'] ); }
		unset( $row );
		return $result;
	}
}
