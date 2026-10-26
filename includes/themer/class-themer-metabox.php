<?php
/**
 * Condition metabox for the Themer template edit screen.
 *
 * Renders a step-wise cascading condition builder (Relation → Group → Sub-type →
 * [Pro] Object) driven by a type-aware schema, mounted by themer-conditions.js and
 * serialized to a hidden JSON field. Free shows Include + broad leaves; the Pro
 * overlay adds the Exclude relation, specific-object search, and priority via the
 * schema filter. Search/404 templates need no conditions (a note is shown instead).
 * Saving parses the hidden JSON, validates selectors against the registered set,
 * and writes the conditions meta (the index rebuilds on save_post).
 *
 * @package EMCP_Tools
 * @since   3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.1.0
 */
class EMCP_Tools_Themer_Metabox {

	const NONCE = 'emcp_themer_conditions_nonce';

	/** Wire admin hooks. */
	public function init(): void {
		add_action( 'add_meta_boxes_' . EMCP_Tools_Themer_CPT::POST_TYPE, array( $this, 'add' ) );
		add_action( 'save_post_' . EMCP_Tools_Themer_CPT::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** Register the metabox. */
	public function add(): void {
		add_meta_box(
			'emcp-themer-conditions',
			__( 'EMCP Themer, Display Conditions', 'emcp-lite' ),
			array( $this, 'render' ),
			EMCP_Tools_Themer_CPT::POST_TYPE,
			'normal',
			'high'
		);
	}

	/** Whether the Pro condition layer is active (a granular selector is registered). */
	private function is_pro(): bool {
		return in_array( 'post', (array) apply_filters( 'emcp_themer_selectors', array() ), true );
	}

	/** The selector keys valid for saving (free broad set + any Pro-registered). */
	private function valid_selectors(): array {
		return (array) apply_filters(
			'emcp_themer_selectors',
			array( 'entire-site', 'all-singular', 'all-archives', 'front-page', 'post-type', 'post-type-archive', 'tax-archive' )
		);
	}

	/**
	 * Enqueue the builder assets on the template edit screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || EMCP_Tools_Themer_CPT::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'emcp-themer-conditions', EMCP_TOOLS_URL . 'assets/css/themer-conditions.css', array(), EMCP_TOOLS_VERSION );
		wp_enqueue_script( 'emcp-themer-conditions', EMCP_TOOLS_URL . 'assets/js/themer-conditions.js', array( 'jquery' ), EMCP_TOOLS_VERSION, true );

		$schemas = array();
		foreach ( EMCP_Tools_Themer_CPT::TYPES as $t ) {
			if ( EMCP_Tools_Themer_Condition_Schema::type_uses_builder( $t ) ) {
				$schemas[ $t ] = EMCP_Tools_Themer_Condition_Schema::for_type( $t );
			}
		}

		wp_localize_script(
			'emcp-themer-conditions',
			'emcpThemerCond',
			array(
				'schemasByType' => $schemas,
				'isPro'         => $this->is_pro(),
				'ajax'          => array(
					'url'    => admin_url( 'admin-ajax.php' ),
					'action' => 'emcp_themer_object_search',
					'nonce'  => wp_create_nonce( 'emcp_themer_object_search' ),
				),
				'i18n'          => array(
					'include'      => __( 'Include', 'emcp-lite' ),
					'exclude'      => __( 'Exclude', 'emcp-lite' ),
					'addCondition' => __( 'Add condition', 'emcp-lite' ),
					'chooseGroup'  => __( 'Choose…', 'emcp-lite' ),
					'all'          => __( 'All', 'emcp-lite' ),
					'searchType'   => __( 'Type 1+ characters…', 'emcp-lite' ),
					'noBuilder'    => __( 'This template type applies automatically, no display conditions needed.', 'emcp-lite' ),
					'proHint'      => __( 'Upgrade to EMCP Pro for Exclude rules, per-page / per-category / per-author targeting, and priority.', 'emcp-lite' ),
					'remove'       => __( 'Remove', 'emcp-lite' ),
				),
			)
		);
	}

	/**
	 * Render the metabox: type select + the condition-builder mount + hidden JSON.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render( $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$type = (string) get_post_meta( $post->ID, '_emcp_themer_type', true );
		if ( '' === $type ) {
			// New template: seed from ?emcp_themer_type= if present, otherwise leave
			// UNSET so the user must consciously choose. Do NOT default to a real
			// type ('header') — that silently mistyped templates (e.g. a template
			// named "Single Page" saved as a header, which then renders in the
			// header slot instead of the body).
			$req  = isset( $_GET['emcp_themer_type'] ) && is_string( $_GET['emcp_themer_type'] ) ? sanitize_key( wp_unslash( $_GET['emcp_themer_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = in_array( $req, EMCP_Tools_Themer_CPT::TYPES, true ) ? $req : '';
		}
		$cond = get_post_meta( $post->ID, '_emcp_themer_conditions', true );
		$cond = is_array( $cond ) ? $cond : array( 'include' => array(), 'exclude' => array(), 'priority' => 0 );

		$type_labels = EMCP_Tools_Themer_CPT::type_labels();

		// Template type row.
		echo '<div class="emcp-themer-field-row" style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;margin:0 0 4px;">';

		// Template type.
		echo '<div class="emcp-themer-field" style="flex:1 1 260px;min-width:240px;">';
		echo '<p style="margin-top:0;"><label for="emcp-themer-type"><strong>' . esc_html__( 'Template type', 'emcp-lite' ) . '</strong> <span style="color:#d63638">*</span></label><br>';
		echo '<select id="emcp-themer-type" name="emcp_themer_type" class="emcp-themer-type-select" required style="width:100%;max-width:340px;">';
		printf( '<option value="" %s>%s</option>', selected( $type, '', false ), esc_html__( 'Choose a template type', 'emcp-lite' ) );
		foreach ( EMCP_Tools_Themer_CPT::TYPES as $t ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $t ), selected( $type, $t, false ), esc_html( $type_labels[ $t ] ?? ucfirst( $t ) ) );
		}
		echo '</select>';
		echo '<br><span class="description">' . esc_html__( 'What this template replaces on the front end. A Single template renders in the content area (keeping your theme header/footer); a Header/Footer template replaces the theme\'s header/footer.', 'emcp-lite' ) . '</span></p>';
		echo '</div>';

		echo '</div>'; // .emcp-themer-field-row

		// Loop Item preview settings. A part has no conditions; it has a sample
		// post the editor renders it against. Both panels are always rendered
		// and themer-conditions.js shows the one that matches the chosen type.
		$is_part = EMCP_Tools_Themer_CPT::is_part( $type );
		$pv      = EMCP_Tools_Themer_CPT::loop_preview( (int) $post->ID );
		printf( '<div id="emcp-themer-loop-preview" class="emcp-themer-loop-preview-panel"%s>', $is_part ? '' : ' style="display:none;"' );
		echo '<p style="margin:12px 0 4px;"><strong>' . esc_html__( 'Loop Item preview', 'emcp-lite' ) . '</strong><br><span class="description">' . esc_html__( 'A Loop Item renders once per post inside a Loop Grid or Loop Carousel. Choose the sample post the editor previews it with, and the canvas width.', 'emcp-lite' ) . '</span></p>';
		echo '<div class="emcp-themer-field-row" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">';
		echo '<p style="margin:0;"><label for="emcp-themer-loop-pt">' . esc_html__( 'Preview post type', 'emcp-lite' ) . '</label><br><select id="emcp-themer-loop-pt" name="emcp_themer_loop_preview[post_type]">';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $pt->name ), selected( $pv['post_type'], $pt->name, false ), esc_html( $pt->label ) );
		}
		echo '</select></p>';
		printf(
			'<p style="margin:0;"><label for="emcp-themer-loop-pid">%1$s</label><br><input type="number" min="0" class="small-text" id="emcp-themer-loop-pid" name="emcp_themer_loop_preview[post_id]" value="%2$d"> <span class="description">%3$s</span></p>',
			esc_html__( 'Preview post ID', 'emcp-lite' ),
			(int) $pv['post_id'],
			esc_html__( '0 = latest published', 'emcp-lite' )
		);
		printf(
			'<p style="margin:0;"><label for="emcp-themer-loop-width">%1$s</label><br><input type="number" min="%2$d" max="%3$d" step="10" class="small-text" id="emcp-themer-loop-width" name="emcp_themer_loop_preview[width]" value="%4$d"> px</p>',
			esc_html__( 'Canvas width', 'emcp-lite' ),
			(int) EMCP_Tools_Themer_CPT::LOOP_PREVIEW_MIN_WIDTH,
			(int) EMCP_Tools_Themer_CPT::LOOP_PREVIEW_MAX_WIDTH,
			(int) $pv['width']
		);
		echo '</div></div>';

		printf( '<div id="emcp-themer-conditions-wrap"%s>', $is_part ? ' style="display:none;"' : '' );

		// Conflict notice: another template of the same type already targets an
		// overlapping condition. Only one can render a given slot, so warn the admin.
		$conflicts = self::find_conflicts( (int) $post->ID, $type, $cond );
		if ( ! empty( $conflicts ) ) {
			echo '<div class="notice notice-warning inline emcp-themer-conflict" style="margin:4px 0 14px;padding:8px 12px;">';
			echo '<p style="margin:.35em 0;"><strong>' . esc_html__( 'Conflict', 'emcp-lite' ) . '</strong>: ';
			echo esc_html(
				sprintf(
					/* translators: 1: count, 2: template type label */
					_n(
						'%1$d other %2$s template targets an overlapping condition. Only one template can render a given page, the resolver picks the most specific rule, then the highest priority, then the newest.',
						'%1$d other %2$s templates target overlapping conditions. Only one template can render a given page, the resolver picks the most specific rule, then the highest priority, then the newest.',
						count( $conflicts ),
						'emcp-lite'
					),
					count( $conflicts ),
					strtolower( (string) ( $type_labels[ $type ] ?? $type ) )
				)
			);
			echo '</p><ul style="margin:.2em 0 .35em 1.3em;list-style:disc;">';
			foreach ( $conflicts as $c ) {
				printf(
					'<li><a href="%1$s">%2$s</a> <span class="description">(%3$s)</span></li>',
					esc_url( $c['edit'] ),
					esc_html( '' !== $c['title'] ? $c['title'] : __( '(untitled)', 'emcp-lite' ) ),
					/* translators: shared condition selectors */
					esc_html( sprintf( __( 'shares: %s', 'emcp-lite' ), implode( ', ', $c['shared'] ) ) )
				);
			}
			echo '</ul></div>';
		}

		// Mount point for the JS cascading builder + the serialized value it writes.
		echo '<div id="emcp-themer-conditions-app" class="emcp-themer-conditions"></div>';
		printf(
			'<input type="hidden" id="emcp-themer-conditions-json" name="emcp_themer_conditions_json" value="%s">',
			esc_attr( (string) wp_json_encode( $cond ) )
		);

		if ( ! $this->is_pro() ) {
			echo '<p class="description emcp-themer-pro-hint">' . esc_html__( 'Free templates support Include rules with broad targeting. Upgrade to EMCP Pro for Exclude rules, per-page / per-category / per-author targeting, priority, and unlimited templates per type.', 'emcp-lite' ) . '</p>';
		}
		echo '</div>'; // #emcp-themer-conditions-wrap
	}

	/**
	 * Persist type + conditions from the hidden JSON field.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 */
	public function save( $post_id, $post ): void {
		$nonce = isset( $_POST[ self::NONCE ] ) && is_string( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( isset( $_POST['emcp_themer_type'] ) && is_string( $_POST['emcp_themer_type'] ) ) {
			$type = sanitize_text_field( wp_unslash( $_POST['emcp_themer_type'] ) );
			if ( in_array( $type, EMCP_Tools_Themer_CPT::TYPES, true ) ) {
				update_post_meta( $post_id, '_emcp_themer_type', $type );
			}
		}

		$saved_type = (string) get_post_meta( $post_id, '_emcp_themer_type', true );
		if ( EMCP_Tools_Themer_CPT::is_part( $saved_type ) ) {
			if ( isset( $_POST['emcp_themer_loop_preview'] ) && is_array( $_POST['emcp_themer_loop_preview'] ) ) {
				$preview = array();
				foreach ( array( 'post_type', 'post_id', 'width' ) as $key ) {
					if ( isset( $_POST['emcp_themer_loop_preview'][ $key ] ) && is_string( $_POST['emcp_themer_loop_preview'][ $key ] ) ) {
						$preview[ $key ] = sanitize_text_field( wp_unslash( $_POST['emcp_themer_loop_preview'][ $key ] ) );
					}
				}
				update_post_meta( $post_id, EMCP_Tools_Themer_CPT::META_LOOP_PREVIEW, EMCP_Tools_Themer_CPT::sanitize_loop_preview( $preview ) );
			}
			// A part takes no conditions; stale rules from a former slot type go.
			delete_post_meta( $post_id, '_emcp_themer_conditions' );
			return;
		}

		if ( ! isset( $_POST['emcp_themer_conditions_json'] ) || ! is_string( $_POST['emcp_themer_conditions_json'] ) ) {
			return;
		}
		$raw     = sanitize_textarea_field( wp_unslash( $_POST['emcp_themer_conditions_json'] ) );
		$decoded = json_decode( $raw, true );
		update_post_meta( $post_id, '_emcp_themer_conditions', $this->sanitize_conditions( is_array( $decoded ) ? $decoded : array() ) );
	}

	/**
	 * Sanitize + validate a decoded conditions payload. Drops rules with unknown
	 * (or Pro-only, when unlicensed) selectors; strips Exclude/priority on free.
	 *
	 * @param array $cond Decoded { include, exclude, priority }.
	 * @return array
	 */
	private function sanitize_conditions( array $cond ): array {
		$valid = $this->valid_selectors();
		$pro   = $this->is_pro();

		$clean_rules = static function ( $rules ) use ( $valid ) {
			$out = array();
			foreach ( (array) $rules as $rule ) {
				$object = is_array( $rule ) ? (string) ( $rule['object'] ?? '' ) : '';
				if ( '' === $object ) {
					continue;
				}
				$key = false === strpos( $object, ':' ) ? $object : substr( $object, 0, strpos( $object, ':' ) );
				if ( in_array( $key, $valid, true ) ) {
					$out[] = array( 'object' => sanitize_text_field( $object ) );
				}
			}
			return $out;
		};

		$include  = $clean_rules( $cond['include'] ?? array() );
		$exclude  = $pro ? $clean_rules( $cond['exclude'] ?? array() ) : array();
		$priority = ( $pro && isset( $cond['priority'] ) ) ? (int) $cond['priority'] : 0;

		return array( 'include' => $include, 'exclude' => $exclude, 'priority' => $priority );
	}

	// -------------------------------------------------------------------------
	// Conflict detection (another template of the same type overlaps this one)
	// -------------------------------------------------------------------------

	/**
	 * The include-condition object keys of a conditions payload (e.g. `all-archives`,
	 * `post-type:post`, `post:12`).
	 *
	 * @param array $cond Conditions { include, exclude, priority }.
	 * @return string[]
	 */
	public static function include_objects( array $cond ): array {
		$objs = array();
		foreach ( (array) ( $cond['include'] ?? array() ) as $rule ) {
			if ( is_array( $rule ) && '' !== (string) ( $rule['object'] ?? '' ) ) {
				$objs[] = (string) $rule['object'];
			}
		}
		return array_values( array_unique( $objs ) );
	}

	/**
	 * Other same-type templates whose include conditions overlap this one's — the
	 * resolver can only render one per slot, so an overlap is a conflict the admin
	 * should know about. Matches on shared include-object keys.
	 *
	 * @param int    $current_id Template being edited.
	 * @param string $type       Its type.
	 * @param array  $cond       Its stored conditions.
	 * @return array<int,array{id:int,title:string,shared:string[],edit:string}>
	 */
	public static function find_conflicts( int $current_id, string $type, array $cond ): array {
		if ( '' === $type ) {
			return array();
		}
		$mine = self::include_objects( $cond );
		if ( empty( $mine ) ) {
			return array();
		}
		$q = new WP_Query(
			array(
				'post_type'      => EMCP_Tools_Themer_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'post__not_in'   => array( $current_id ),
				'meta_key'       => '_emcp_themer_type',
				'meta_value'     => $type,
			)
		);
		$out = array();
		foreach ( (array) $q->posts as $oid ) {
			$oid    = (int) $oid;
			$ocond  = get_post_meta( $oid, '_emcp_themer_conditions', true );
			$ocond  = is_array( $ocond ) ? $ocond : array();
			$shared = array_values( array_intersect( $mine, self::include_objects( $ocond ) ) );
			if ( ! empty( $shared ) ) {
				$out[] = array(
					'id'     => $oid,
					'title'  => (string) get_the_title( $oid ),
					'shared' => $shared,
					'edit'   => (string) get_edit_post_link( $oid, 'raw' ),
				);
			}
		}
		return $out;
	}
}
