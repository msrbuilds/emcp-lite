<?php
/**
 * Stock-image provider registry.
 *
 * Unifies the three stock-photo clients (Unsplash, Pexels, Pixabay) behind a
 * single resolver so the stock-image tools can pick a provider by id, fall back
 * to whichever key is configured, and surface a clear error when none are.
 *
 * Each client exposes: `const OPTION`, static `has_key()` / `access_key()`, and
 * instance `search_images( array )` / `trigger_download( string )`.
 *
 * @package EMCP_Tools
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry + resolver for the stock-image providers.
 *
 * @since 3.1.0
 */
class EMCP_Tools_Stock_Image_Providers {

	/**
	 * Provider id => { label, class }. Order is the fallback priority when no
	 * provider is requested.
	 *
	 * @since 3.1.0
	 * @return array<string,array{label:string,class:string}>
	 */
	public static function map(): array {
		return array(
			'unsplash' => array( 'label' => 'Unsplash', 'class' => 'EMCP_Tools_Unsplash_Client' ),
			'pexels'   => array( 'label' => 'Pexels', 'class' => 'EMCP_Tools_Pexels_Client' ),
			'pixabay'  => array( 'label' => 'Pixabay', 'class' => 'EMCP_Tools_Pixabay_Client' ),
		);
	}

	/**
	 * @since 3.1.0
	 * @return string[]
	 */
	public static function ids(): array {
		return array_keys( self::map() );
	}

	/**
	 * @since 3.1.0
	 * @param string $id Provider id.
	 * @return string
	 */
	public static function label( string $id ): string {
		$m = self::map();
		return $m[ $id ]['label'] ?? $id;
	}

	/**
	 * @since 3.1.0
	 * @param string $id Provider id.
	 * @return object|null Client instance.
	 */
	public static function client( string $id ) {
		$m = self::map();
		if ( ! isset( $m[ $id ] ) || ! class_exists( $m[ $id ]['class'] ) ) {
			return null;
		}
		$class = $m[ $id ]['class'];
		return new $class();
	}

	/**
	 * @since 3.1.0
	 * @param string $id Provider id.
	 * @return bool
	 */
	public static function has_key( string $id ): bool {
		$m = self::map();
		if ( ! isset( $m[ $id ] ) || ! class_exists( $m[ $id ]['class'] ) ) {
			return false;
		}
		return (bool) call_user_func( array( $m[ $id ]['class'], 'has_key' ) );
	}

	/**
	 * Provider ids that currently have a key configured, in priority order.
	 *
	 * @since 3.1.0
	 * @return string[]
	 */
	public static function available(): array {
		return array_values( array_filter( self::ids(), array( __CLASS__, 'has_key' ) ) );
	}

	/**
	 * Resolve a provider to use. If `$requested` is given it must exist and be
	 * keyed; otherwise the first configured provider (map order) is used.
	 *
	 * @since 3.1.0
	 * @param string $requested Optional provider id.
	 * @return array{0:string,1:object}|\WP_Error [ id, client ] or an error.
	 */
	public static function resolve( string $requested = '' ) {
		if ( '' !== $requested ) {
			if ( ! isset( self::map()[ $requested ] ) ) {
				return new \WP_Error(
					'unknown_provider',
					sprintf(
						/* translators: %s: provider id */
						__( 'Unknown stock-image provider "%s". Use one of: unsplash, pexels, pixabay.', 'emcp-lite' ),
						$requested
					)
				);
			}
			if ( ! self::has_key( $requested ) ) {
				return new \WP_Error(
					'no_api_key',
					sprintf(
						/* translators: %s: provider label */
						__( 'No %s API key is configured. Add one on EMCP Lite → Connection, or use a provider you have connected.', 'emcp-lite' ),
						self::label( $requested )
					)
				);
			}
			return array( $requested, self::client( $requested ) );
		}

		$available = self::available();
		if ( empty( $available ) ) {
			return new \WP_Error(
				'no_api_key',
				__( 'No stock-image provider is configured. Add a free Unsplash, Pexels, or Pixabay API key on EMCP Lite → Connection.', 'emcp-lite' )
			);
		}
		$id = $available[0];
		return array( $id, self::client( $id ) );
	}
}
