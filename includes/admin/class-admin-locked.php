<?php
/**
 * Feature cards for the locked-Pro screen (spec 8.25). Static text only, so
 * the free tree carries no Pro code.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locked-Pro screen data.
 */
final class EMCP_Tools_Admin_Locked {

	/**
	 * Register the `locked` React screen.
	 */
	public static function register_screen(): void {
		EMCP_Tools_Admin_Screens::register(
			'locked',
			array(
				'script' => 'screen-locked',
				'boot'   => static function ( array $context ): array {
					return self::feature( (string) ( $context['key'] ?? $context['tab'] ?? '' ) );
				},
			)
		);
	}

	/**
	 * The feature card for a Pro-only tab.
	 *
	 * @param string $tab Tab id.
	 * @return array{title:string, description:string, bullets:string[], upgrade_url:string, compare_url:string}
	 */
	public static function feature( string $tab ): array {
		$cards = array(
			'ai-chat' => array(
				'title'       => __( 'AI Chat', 'emcp-lite' ),
				'description' => __( 'Chat with any AI model inside WordPress and the page editors to build and edit pages.', 'emcp-lite' ),
				'bullets'     => array(
					__( 'Describe a page and watch it being built', 'emcp-lite' ),
					__( 'Edit the page you are on from the Elementor or block editor', 'emcp-lite' ),
					__( 'Bring your own API key from Anthropic, OpenAI, OpenRouter or Gemini', 'emcp-lite' ),
				),
			),
			'skills'  => array(
				'title'       => __( 'Skills', 'emcp-lite' ),
				'description' => __( 'Curated playbooks that teach connected agents how to work on your site.', 'emcp-lite' ),
				'bullets'     => array(
					__( 'Agents load the right playbook for the task at runtime', 'emcp-lite' ),
					__( 'Install the skills in Claude Desktop or Claude Code', 'emcp-lite' ),
					__( 'Playbooks for every supported builder and theme', 'emcp-lite' ),
				),
			),
			'memory'  => array(
				'title'       => __( 'Project Memory', 'emcp-lite' ),
				'description' => __( 'Remember your site across agent sessions so agents stop guessing.', 'emcp-lite' ),
				'bullets'     => array(
					__( 'Guardrails, site facts and conventions you approve', 'emcp-lite' ),
					__( 'Agents propose memory; nothing is used until you approve it', 'emcp-lite' ),
					__( 'Session summaries you can review', 'emcp-lite' ),
				),
			),
			'templates' => array(
				'title'       => __( 'Templates', 'emcp-lite' ),
				'description' => __( 'Ready-made page designs you apply in one click, then edit visually.', 'emcp-lite' ),
				'bullets'     => array(
					__( '50 page templates across 10 industries', 'emcp-lite' ),
					__( 'Each one creates a draft page you can edit in Elementor', 'emcp-lite' ),
					__( 'The library stays in sync with new designs', 'emcp-lite' ),
				),
			),
			'widgets:widgets' => array(
				'title'       => __( 'Widgets', 'emcp-lite' ),
				'description' => __( 'Custom Elementor widgets your AI agent designs and you approve.', 'emcp-lite' ),
				'bullets'     => array(
					__( 'The plugin compiles each widget from a spec; the AI never writes raw PHP', 'emcp-lite' ),
					__( 'Nothing goes live until you switch it on', 'emcp-lite' ),
					__( 'Back up and share widgets through EMCP Cloud', 'emcp-lite' ),
				),
			),
			'widgets:blocks'  => array(
				'title'       => __( 'Blocks', 'emcp-lite' ),
				'description' => __( 'Custom Gutenberg blocks your AI agent designs and you approve.', 'emcp-lite' ),
				'bullets'     => array(
					__( 'Compiled from a spec; the AI never writes raw PHP or JS', 'emcp-lite' ),
					__( 'Preview a block before you switch it on', 'emcp-lite' ),
					__( 'Blocks appear in the inserter under EMCP', 'emcp-lite' ),
				),
			),
			'widgets:export'  => array(
				'title'       => __( 'Export as plugin', 'emcp-lite' ),
				'description' => __( 'Package your active widgets, blocks and snippets as a standalone plugin.', 'emcp-lite' ),
				'bullets'     => array(
					__( 'Keeps working after EMCP Tools is removed', 'emcp-lite' ),
					__( 'Stays dormant while EMCP Tools is active', 'emcp-lite' ),
					__( 'API keys stay out of the ZIP unless you choose otherwise', 'emcp-lite' ),
				),
			),
			'migrate' => array(
				'title'       => __( 'Backup & Migrate', 'emcp-lite' ),
				'description' => __( 'Back up the whole site, restore it, and move it to another server.', 'emcp-lite' ),
				'bullets'     => array(
					__( 'Full, database, files or content backups', 'emcp-lite' ),
					__( 'Push a local site to live with a small connector plugin', 'emcp-lite' ),
					__( 'One-way sync of chosen tables and folders', 'emcp-lite' ),
				),
			),
		);
		// A child view (tab:view) without its own card shows its tab's.
		$card  = $cards[ $tab ] ?? $cards[ strtok( $tab, ':' ) ] ?? array(
			'title'       => __( 'EMCP Pro', 'emcp-lite' ),
			'description' => __( 'This feature is part of EMCP Pro.', 'emcp-lite' ),
			'bullets'     => array(
				__( 'Every Pro tool and module', 'emcp-lite' ),
				__( 'Premium templates, prompts and brand kits', 'emcp-lite' ),
				__( 'Priority support', 'emcp-lite' ),
			),
		);
		return array_merge(
			$card,
			array(
				'upgrade_url' => function_exists( 'emcp_tools_upgrade_url' ) ? (string) emcp_tools_upgrade_url() : 'https://emcptools.com/pricing',
				'compare_url' => 'https://emcptools.com/pricing',
			)
		);
	}
}
