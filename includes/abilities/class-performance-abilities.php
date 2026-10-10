<?php
/**
 * Performance Analyzer MCP ability (read-only).
 *
 * One tool — analyze-performance — that audits server config, WordPress
 * internals, and a target page (default frontpage) and returns a scored report.
 * manage_options; enabled by default.
 *
 * @package EMCP_Tools
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.0.0
 */
class EMCP_Tools_Performance_Abilities {

	/** @var string[] */
	private $ability_names = array();

	public function get_ability_names(): array {
		return $this->ability_names;
	}

	public function register(): void {
		$this->register_analyze_performance();
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	private function register_analyze_performance(): void {
		$this->ability_names[] = 'emcp-tools/analyze-performance';
		emcp_tools_register_ability(
			'emcp-tools/analyze-performance',
			array(
				'label'               => __( 'Analyze Performance', 'emcp-lite' ),
				'description'         => __( 'Scans server configuration, WordPress internals (database size, autoloaded options, cron backlog, object cache, OPcache, plugin count), and a target page (defaults to the frontpage; pass "url" or "post_id" for a specific page) for performance issues and bottlenecks. Returns a scored report with severities and ranked, actionable recommendations. Read-only; analyzes this site only.', 'emcp-lite' ),
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, 'execute_analyze_performance' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'url'                => array( 'type' => 'string', 'format' => 'uri', 'description' => __( 'A page on THIS site to analyze. External hosts are rejected. Defaults to the frontpage.', 'emcp-lite' ) ),
						'post_id'            => array( 'type' => 'integer', 'description' => __( 'Analyze the permalink of this post/page. Ignored when url is set.', 'emcp-lite' ) ),
						'include_page_fetch' => array( 'type' => 'boolean', 'description' => __( 'Set false to skip the loopback page fetch and run the server/database audit only. Default true.', 'emcp-lite' ) ),
						'deep_assets'        => array( 'type' => 'boolean', 'description' => __( 'Reserved: when true, sample same-host asset sizes for an estimated page weight. Default false.', 'emcp-lite' ) ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'target'              => array( 'type' => 'object' ),
						'summary'             => array( 'type' => 'object' ),
						'sections'            => array( 'type' => 'object' ),
						'page_fetch'          => array( 'type' => 'object' ),
						'top_recommendations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * @param array $input
	 * @return array|\WP_Error
	 */
	public function execute_analyze_performance( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$analyzer = new EMCP_Tools_Performance_Analyzer();
		return $analyzer->analyze( $input );
	}
}
