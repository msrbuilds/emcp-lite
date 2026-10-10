<?php
/**
 * EMCP Themer / BeTheme (BeBuilder) conflict handling.
 *
 * BeTheme builds page content with BeBuilder, stored in the `mfn-page-items`
 * post meta and rendered by the theme's own page template. EMCP Themer's body
 * templates take over `template_include` and render their own document
 * instead, so on any request a Themer body template claims, BeTheme's template
 * never runs and the page's BeBuilder content does not appear at all.
 *
 * That is worth warning about because of how it fails. Nothing errors, the page
 * returns 200, and the content is simply absent, so it reads as "my page went
 * blank" rather than as two systems competing. It cost three test rounds to
 * identify while building the BeTheme integration, on a site where the person
 * debugging it had written both halves.
 *
 * Unlike the Ultimate Addons clash, this one is NOT resolved automatically. A
 * body template replacing the page's own rendering is exactly what a theme
 * builder is for, so silently deferring to BeBuilder would break the other half
 * of the users. The owner is told what is happening and picks.
 *
 * Deliberately narrow: it only fires when a BODY template is published.
 * Header and footer templates inject into slots and leave BeBuilder content
 * alone, so warning about those would be noise on a setup that works.
 *
 * Lives in the FREE tree, like the Ultimate Addons conflict, because the clash
 * exists whenever the free Themer module and BeTheme are both active.
 *
 * @package EMCP_Tools
 * @since   3.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects the Themer/BeBuilder overlap and explains it.
 *
 * @since 3.14.0
 */
class EMCP_Tools_Themer_BeTheme_Conflict {

	/**
	 * Option that lets an admin dismiss the notice.
	 *
	 * @var string
	 */
	const OPTION_DISMISSED = 'emcp_tools_betheme_conflict_dismissed';

	/**
	 * Themer template types that replace the whole document.
	 *
	 * Header and footer are excluded on purpose: they inject into slots and
	 * leave the theme's own content rendering intact.
	 *
	 * @var string[]
	 */
	const BODY_TYPES = array( 'single', 'archive', 'search', '404' );

	/**
	 * Wires the notice. Called from the Themer module's register(), so it only
	 * runs when the Themer module is on.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! is_admin() || ! self::betheme_active() ) {
			return;
		}
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_emcp_tools_dismiss_betheme_conflict', array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * True when BeTheme is the active theme.
	 *
	 * Checks the template rather than the stylesheet so a child theme of
	 * BeTheme counts, which is how most production BeTheme sites are built.
	 *
	 * @return bool
	 */
	public static function betheme_active(): bool {
		return 'betheme' === strtolower( (string) get_template() );
	}

	/**
	 * Published Themer templates that would replace BeTheme's rendering.
	 *
	 * @return array<int,WP_Post>
	 */
	public static function body_templates(): array {
		if ( ! class_exists( 'EMCP_Tools_Themer_CPT' ) || ! post_type_exists( EMCP_Tools_Themer_CPT::POST_TYPE ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => EMCP_Tools_Themer_CPT::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => 20,
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only notice, bounded to 20.
					array(
						'key'     => EMCP_Tools_Themer_Index::META_TYPE,
						'value'   => self::BODY_TYPES,
						'compare' => 'IN',
					),
				),
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	/**
	 * True when both systems are live and a body template would win.
	 *
	 * @return bool
	 */
	public static function in_conflict(): bool {
		if ( ! self::betheme_active() ) {
			return false;
		}
		if ( ! class_exists( 'EMCP_Tools_Themer_Module' ) || ! EMCP_Tools_Themer_Module::is_enabled() ) {
			return false;
		}
		return array() !== self::body_templates();
	}

	/**
	 * Renders the conflict notice.
	 *
	 * @return void
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( self::OPTION_DISMISSED ) ) {
			return;
		}
		if ( ! self::in_conflict() ) {
			return;
		}

		$templates = self::body_templates();
		$names     = array();
		foreach ( $templates as $tpl ) {
			$type    = (string) get_post_meta( $tpl->ID, EMCP_Tools_Themer_Index::META_TYPE, true );
			$names[] = $tpl->post_title . ( '' !== $type ? ' (' . $type . ')' : '' );
		}

		$modules_url   = admin_url( 'admin.php?page=emcp-tools-modules' );
		$templates_url = admin_url( 'edit.php?post_type=' . EMCP_Tools_Themer_CPT::POST_TYPE );
		$dismiss_url   = wp_nonce_url(
			admin_url( 'admin-post.php?action=emcp_tools_dismiss_betheme_conflict' ),
			'emcp_tools_dismiss_betheme_conflict'
		);
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'EMCP Themer is replacing BeTheme\'s page rendering.', 'emcp-lite' ); ?></strong>
			</p>
			<p>
				<?php
				esc_html_e(
					'You are running BeTheme, and EMCP Themer has a body template published. Where that template matches, EMCP Themer renders the page instead of BeTheme, so anything built with BeBuilder on those pages will not appear. Nothing errors and the page still loads, which is why this tends to look like a page going blank rather than two systems competing.',
					'emcp-lite'
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: comma-separated list of template names */
					esc_html__( 'Templates taking over: %s.', 'emcp-lite' ),
					'<strong>' . esc_html( implode( ', ', $names ) ) . '</strong>'
				);
				?>
			</p>
			<p>
				<?php
				esc_html_e(
					'Pick whichever suits the site. To use BeBuilder and BeTheme\'s own template system, unpublish those Themer body templates or turn the EMCP Themer module off. To use EMCP Themer, build the page content inside the Themer template instead of with BeBuilder. Header and footer templates are unaffected either way and can stay.',
					'emcp-lite'
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $templates_url ); ?>">
					<?php esc_html_e( 'Review Themer Templates', 'emcp-lite' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $modules_url ); ?>">
					<?php esc_html_e( 'Manage EMCP Modules', 'emcp-lite' ); ?>
				</a>
				<a class="button-link" href="<?php echo esc_url( $dismiss_url ); ?>">
					<?php esc_html_e( 'Dismiss', 'emcp-lite' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Stores the dismissal and returns where the admin came from.
	 *
	 * @return void
	 */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-lite' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'emcp_tools_dismiss_betheme_conflict' );

		update_option( self::OPTION_DISMISSED, '1' );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
