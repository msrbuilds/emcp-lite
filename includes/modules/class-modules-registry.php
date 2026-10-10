<?php
/**
 * Registry + lifecycle for EMCP Tools modules.
 *
 * Holds every registered module, seeds the default active-set once (marker
 * option), and boots active+available modules on `init`.
 *
 * @package EMCP_Tools
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module registry (singleton).
 *
 * @since 3.1.0
 */
class EMCP_Tools_Modules_Registry {

	/** Option holding the list of module ids already considered for seeding. */
	const OPTION_SEEDED = 'emcp_tools_modules_seeded';

	/** @var self|null */
	private static $instance = null;

	/** @var array<string,EMCP_Tools_Module> id => module */
	private $modules = array();

	private function __construct() {}

	/** @return self */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Test helper: drop the singleton + its modules. */
	public static function reset_for_tests(): void {
		self::$instance = null;
	}

	/**
	 * Register a module (idempotent by id).
	 *
	 * @param EMCP_Tools_Module $module Module instance.
	 */
	public function register( EMCP_Tools_Module $module ): void {
		$this->modules[ $module->id() ] = $module;
	}

	/** @return EMCP_Tools_Module[] All registered modules. */
	public function all(): array {
		return array_values( $this->modules );
	}

	/**
	 * @param string $id Module id.
	 * @return EMCP_Tools_Module|null
	 */
	public function get( string $id ): ?EMCP_Tools_Module {
		return $this->modules[ $id ] ?? null;
	}

	/** @return EMCP_Tools_Module[] Registered modules whose id is in the active option. */
	public function active(): array {
		return array_values(
			array_filter(
				$this->modules,
				static function ( EMCP_Tools_Module $m ) {
					return $m->is_active();
				}
			)
		);
	}

	/**
	 * Seed the active-modules option from each module's default_active(), once
	 * per module. A per-module "seeded" list (not a single boolean marker) means
	 * a module added in a later version is seeded on the next load without
	 * re-seeding — or removing — anything the user has already set.
	 */
	public function apply_defaults(): void {
		$seeded  = (array) get_option( self::OPTION_SEEDED, array() );
		$active  = (array) get_option( EMCP_Tools_Module::OPTION_ACTIVE, array() );
		$changed = false;

		foreach ( $this->modules as $module ) {
			if ( in_array( $module->id(), $seeded, true ) ) {
				continue;
			}
			$seeded[] = $module->id();
			if ( $module->default_active() && ! in_array( $module->id(), $active, true ) ) {
				$active[] = $module->id();
			}
			$changed = true;
		}

		if ( $changed ) {
			update_option( EMCP_Tools_Module::OPTION_ACTIVE, array_values( $active ) );
			update_option( self::OPTION_SEEDED, array_values( $seeded ) );
		}
	}

	/** Call register() on every active + available module. Hooked to `init`. */
	public function boot_active(): void {
		foreach ( $this->active() as $module ) {
			if ( $module->is_available() ) {
				$module->register();
			}
		}
	}
}
