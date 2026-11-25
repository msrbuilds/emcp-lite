<?php
/**
 * Black Friday 2026 sale notice for EMCP Lite.
 *
 * Shown inside the content area of the EMCP Lite Dashboard screen (never across
 * wp-admin, never above the Lite frame), to administrators, while EMCP_Tools_Sale::active(). Dismissible per user; a
 * dismissed notice stays gone and its announcement bar slide goes with it.
 * No remote requests: the sale dates and copy are in EMCP_Tools_Sale.
 *
 * @package EMCP_Tools
 * @since   1.0.12
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-sale.php';

class EMCP_Tools_Sale_Notice {

	/** User-meta key recording when this user dismissed the sale notice. */
	const META_KEY = 'emcp_tools_bfcm2026_notice_dismissed';

	/** AJAX nonce action name. */
	const NONCE_ACTION = 'emcp_tools_dismiss_sale_notice';

	public function init(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'emcp_tools_frame_content_top', array( $this, 'maybe_render' ) );
		add_action( 'wp_ajax_emcp_tools_dismiss_sale_notice', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Whether the current user dismissed the sale notice.
	 */
	public static function dismissed(): bool {
		return (bool) get_user_meta( get_current_user_id(), self::META_KEY, true );
	}

	/**
	 * Pure decision: show only on the EMCP Lite Dashboard screen, to an
	 * administrator, while the sale runs and the user has not dismissed it.
	 *
	 * @param string   $screen_id Current admin screen id (the Dashboard is the top-level page).
	 * @param bool     $can       Whether the user can manage options.
	 * @param bool     $dismissed Whether the user dismissed the notice.
	 * @param int|null $now       Unix time; defaults to now.
	 */
	public static function visible( string $screen_id, bool $can, bool $dismissed, ?int $now = null ): bool {
		return $can && ! $dismissed && 'toplevel_page_emcp-tools' === $screen_id && EMCP_Tools_Sale::active( $now );
	}

	private function should_show(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return self::visible( $screen ? (string) $screen->id : '', current_user_can( 'manage_options' ), self::dismissed() );
	}

	/** Loads the notice's style and script only where it shows. */
	public function enqueue(): void {
		if ( ! $this->should_show() ) {
			return;
		}
		wp_enqueue_style( 'emcp-tools-sale-notice', EMCP_TOOLS_URL . 'assets/css/sale-notice.css', array(), EMCP_TOOLS_VERSION );
		wp_enqueue_script( 'emcp-tools-sale-notice', EMCP_TOOLS_URL . 'assets/js/sale-notice.js', array(), EMCP_TOOLS_VERSION, true );
	}

	/**
	 * Renders inside the frame's content area (emcp_tools_frame_content_top).
	 *
	 * @param string $active_tab The frame's current tab.
	 */
	public function maybe_render( $active_tab = '' ): void {
		if ( 'dashboard' === $active_tab && $this->should_show() ) {
			$this->render();
		}
	}

	private function render(): void {
		$nonce = wp_create_nonce( self::NONCE_ACTION );
		$units = array(
			'd' => __( 'D', 'emcp-lite' ),
			'h' => __( 'H', 'emcp-lite' ),
			'm' => __( 'M', 'emcp-lite' ),
			's' => __( 'S', 'emcp-lite' ),
		);
		$left  = max( 0, EMCP_Tools_Sale::END - time() );
		$start = array(
			'd' => intdiv( $left, DAY_IN_SECONDS ),
			'h' => intdiv( $left % DAY_IN_SECONDS, HOUR_IN_SECONDS ),
			'm' => intdiv( $left % HOUR_IN_SECONDS, MINUTE_IN_SECONDS ),
			's' => $left % MINUTE_IN_SECONDS,
		);
		?>

		<div class="emcp-sale-wrap"><div class="emcp-sale" data-emcp-nonce="<?php echo esc_attr( $nonce ); ?>" data-emcp-banner="bfcm" data-emcp-end="<?php echo esc_attr( (string) EMCP_Tools_Sale::END ); ?>" role="region" aria-label="<?php esc_attr_e( 'EMCP Black Friday sale', 'emcp-lite' ); ?>">

			<svg class="emcp-sale__grid emcp-sale__grid--l" viewBox="0 0 120 56" aria-hidden="true" preserveAspectRatio="xMinYMid slice">
				<rect x="0" y="0" width="14" height="14" /><rect x="28" y="0" width="14" height="14" class="b1" />
				<rect x="14" y="14" width="14" height="14" class="b2" /><rect x="56" y="14" width="14" height="14" />
				<rect x="0" y="28" width="14" height="14" class="b3" /><rect x="42" y="28" width="14" height="14" />
				<rect x="28" y="42" width="14" height="14" /><rect x="84" y="42" width="14" height="14" class="b1" />
			</svg>
			<svg class="emcp-sale__grid emcp-sale__grid--r" viewBox="0 0 120 56" aria-hidden="true" preserveAspectRatio="xMaxYMid slice">
				<rect x="106" y="0" width="14" height="14" class="b2" /><rect x="78" y="0" width="14" height="14" />
				<rect x="92" y="14" width="14" height="14" /><rect x="50" y="14" width="14" height="14" class="b3" />
				<rect x="106" y="28" width="14" height="14" /><rect x="64" y="28" width="14" height="14" class="b1" />
				<rect x="92" y="42" width="14" height="14" class="b2" /><rect x="22" y="42" width="14" height="14" />
			</svg>

			<button type="button" class="emcp-sale__dismiss" aria-label="<?php esc_attr_e( 'Dismiss this notice', 'emcp-lite' ); ?>">
				<svg viewBox="0 0 20 20" width="12" height="12" aria-hidden="true"><path d="M5.3 4.3a1 1 0 011.4 0L10 7.6l3.3-3.3a1 1 0 111.4 1.4L11.4 9l3.3 3.3a1 1 0 11-1.4 1.4L10 10.4l-3.3 3.3a1 1 0 11-1.4-1.4L8.6 9 5.3 5.7a1 1 0 010-1.4z" fill="currentColor"/></svg>
			</button>

			<div class="emcp-sale__in">
				<div class="emcp-sale__brand">
					<img class="emcp-sale__logo" src="<?php echo esc_url( EMCP_TOOLS_URL . 'assets/img/icon-sm.png' ); ?>" alt="" width="44" height="44">
					<span class="emcp-sale__name">EMCP<small><?php esc_html_e( 'Lite', 'emcp-lite' ); ?></small></span>
				</div>

				<div class="emcp-sale__badge">
					<span class="emcp-sale__bf"><span><?php esc_html_e( 'Black', 'emcp-lite' ); ?></span><span><?php esc_html_e( 'Friday', 'emcp-lite' ); ?></span></span>
					<span class="emcp-sale__pill"><?php esc_html_e( 'Sale', 'emcp-lite' ); ?></span>
					<svg class="emcp-sale__flame" viewBox="0 0 32 40" aria-hidden="true">
						<path class="emcp-sale__flame-out" d="M16 1c2 7 9 10 11 18 2 9-4 19-11 19S3 31 5 22c1-6 6-8 6-14 3 2 4 5 4 8 2-3 2-9 1-15Z" />
						<path class="emcp-sale__flame-in" d="M16 18c1 4 6 6 6 11 0 5-3 8-6 8s-6-3-6-7c0-4 3-5 4-9 1 1 2 3 2 5 1-2 1-5 0-8Z" />
						<text x="16" y="34" text-anchor="middle">%</text>
					</svg>
				</div>

				<ul class="emcp-sale__deals">
					<li>
						<span class="emcp-sale__pct"><?php echo esc_html( EMCP_Tools_Sale::PLUGIN_PERCENT ); ?><i>%</i></span>
						<span class="emcp-sale__what"><b><?php esc_html_e( 'Off', 'emcp-lite' ); ?></b><?php esc_html_e( 'EMCP Pro', 'emcp-lite' ); ?></span>
					</li>
					<li>
						<span class="emcp-sale__pct"><?php echo esc_html( EMCP_Tools_Sale::CLOUD_PERCENT ); ?><i>%</i></span>
						<span class="emcp-sale__what"><b><?php esc_html_e( 'Off', 'emcp-lite' ); ?></b><?php esc_html_e( 'EMCP Cloud', 'emcp-lite' ); ?></span>
					</li>
					<li class="emcp-sale__ltd">
						<span class="emcp-sale__what"><b><?php esc_html_e( 'Lifetime', 'emcp-lite' ); ?></b><?php esc_html_e( 'deals, limited stock', 'emcp-lite' ); ?></span>
					</li>
				</ul>

				<div class="emcp-sale__foot">
				<div class="emcp-sale__clock">
					<span class="emcp-sale__ends"><?php esc_html_e( 'Ends in:', 'emcp-lite' ); ?></span>
					<span class="emcp-sale__digits" role="timer" aria-label="<?php esc_attr_e( 'Time left in the Black Friday sale', 'emcp-lite' ); ?>">
						<?php
						$i = 0;
						foreach ( $units as $key => $label ) {
							if ( $i++ > 0 ) {
								echo '<span class="emcp-sale__colon" aria-hidden="true">:</span>';
							}
							printf(
								'<span class="emcp-sale__unit"><span data-emcp-t="%1$s">%2$s</span><small>%3$s</small></span>',
								esc_attr( $key ),
								esc_html( str_pad( (string) $start[ $key ], 2, '0', STR_PAD_LEFT ) ),
								esc_html( $label )
							);
						}
						?>
					</span>
				</div>

				<a class="emcp-sale__cta" href="<?php echo esc_url( EMCP_Tools_Sale::URL ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Grab the deal', 'emcp-lite' ); ?> <span aria-hidden="true">&rarr;</span>
					<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'emcp-lite' ); ?></span>
				</a>
				</div>
			</div>
		</div></div>

		<?php
	}

	/**
	 * AJAX: record that the current user dismissed the sale notice.
	 */
	public function ajax_dismiss(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		update_user_meta( get_current_user_id(), self::META_KEY, time() );
		wp_send_json_success();
	}
}
