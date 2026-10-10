<?php
/**
 * Elementor Global Variables (design tokens) abilities — Elementor 4.2+.
 *
 * Uses Elementor's Variables service/repository layer. Direct writes to the
 * `_elementor_global_variables` kit meta are deliberately avoided so variable
 * validation, soft deletion, watermarks, and CSS cache invalidation stay under
 * Elementor's control.
 *
 * @package EMCP_Tools
 * @since   3.14.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD and batch access to Elementor Global Variables.
 */
class EMCP_Tools_Global_Variables_Abilities {

	const SERVICE         = '\Elementor\Modules\Variables\Services\Variables_Service';
	const REPOSITORY      = '\Elementor\Modules\Variables\Storage\Variables_Repository';
	const BATCH_PROCESSOR = '\Elementor\Modules\Variables\Services\Batch_Operations\Batch_Processor';
	const MODULE          = '\Elementor\Modules\Variables\Module';

	/**
	 * @return bool Whether Elementor's Variables service is available.
	 */
	public static function is_available(): bool {
		return class_exists( self::SERVICE )
			&& class_exists( self::REPOSITORY )
			&& class_exists( self::BATCH_PROCESSOR );
	}

	/**
	 * @return string[]
	 */
	public function get_ability_names(): array {
		return self::is_available()
			? array(
				'emcp-tools/list-variables',
				'emcp-tools/create-variable',
				'emcp-tools/update-variable',
				'emcp-tools/delete-variable',
				'emcp-tools/restore-variable',
				'emcp-tools/batch-variables',
			)
			: array();
	}

	/**
	 * Register all six Variables abilities.
	 */
	public function register(): void {
		if ( ! self::is_available() ) {
			return;
		}

		$types = $this->variable_types();
		$type_schema = array(
			'type'        => 'string',
			'description' => __( 'Elementor variable type. Use list-variables to discover the types supported by this Elementor installation.', 'emcp-lite' ),
		);
		if ( ! empty( $types ) ) {
			$type_schema['enum'] = $types;
		}

		$variable_fields = array(
			'type'  => $type_schema,
			'label' => array( 'type' => 'string', 'description' => __( 'CSS custom-property label without spaces; maximum 50 characters.', 'emcp-lite' ) ),
			'value' => array( 'type' => 'string', 'description' => __( 'Variable value; maximum 512 characters.', 'emcp-lite' ) ),
		);

		$this->register_ability(
			'emcp-tools/list-variables',
			__( 'List Global Variables', 'emcp-lite' ),
			__( 'List Elementor Global Variables (design tokens), their stable ids, labels, values, types, order, and storage watermark.', 'emcp-lite' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'include_deleted' => array( 'type' => 'boolean', 'description' => __( 'Include soft-deleted variables that can be restored.', 'emcp-lite' ) ),
				),
			),
			array( $this, 'execute_list_variables' ),
			array( $this, 'check_read_permission' ),
			true,
			false,
			true
		);

		$this->register_ability(
			'emcp-tools/create-variable',
			__( 'Create Global Variable', 'emcp-lite' ),
			__( 'Create an Elementor Global Variable and return its stable id and watermark.', 'emcp-lite' ),
			array( 'type' => 'object', 'properties' => $variable_fields, 'required' => array( 'type', 'label', 'value' ) ),
			array( $this, 'execute_create_variable' ),
			array( $this, 'check_write_permission' ),
			false,
			false,
			false
		);

		$update_fields = array_merge(
			array( 'id' => array( 'type' => 'string', 'description' => __( 'Stable Elementor variable id.', 'emcp-lite' ) ) ),
			$variable_fields,
			array( 'order' => array( 'type' => 'integer', 'minimum' => 0 ) )
		);
		$this->register_ability(
			'emcp-tools/update-variable',
			__( 'Update Global Variable', 'emcp-lite' ),
			__( 'Update a Global Variable label/value and optionally its type or order.', 'emcp-lite' ),
			array( 'type' => 'object', 'properties' => $update_fields, 'required' => array( 'id', 'label', 'value' ) ),
			array( $this, 'execute_update_variable' ),
			array( $this, 'check_write_permission' ),
			false,
			false,
			true
		);

		$this->register_ability(
			'emcp-tools/delete-variable',
			__( 'Delete Global Variable', 'emcp-lite' ),
			__( 'Soft-delete a Global Variable. Existing references may stop resolving until it is restored. Requires confirm:true.', 'emcp-lite' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'id'      => array( 'type' => 'string' ),
					'confirm' => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'id', 'confirm' ),
			),
			array( $this, 'execute_delete_variable' ),
			array( $this, 'check_write_permission' ),
			false,
			true,
			false
		);

		$restore_fields = $variable_fields;
		$restore_fields['id'] = array( 'type' => 'string' );
		$this->register_ability(
			'emcp-tools/restore-variable',
			__( 'Restore Global Variable', 'emcp-lite' ),
			__( 'Restore a soft-deleted Global Variable, optionally overriding its label, value, or compatible type.', 'emcp-lite' ),
			array( 'type' => 'object', 'properties' => $restore_fields, 'required' => array( 'id' ) ),
			array( $this, 'execute_restore_variable' ),
			array( $this, 'check_write_permission' ),
			false,
			false,
			false
		);

		$this->register_ability(
			'emcp-tools/batch-variables',
			__( 'Batch Global Variables', 'emcp-lite' ),
			__( 'Atomically create, update, delete, or restore multiple Global Variables. Pass the watermark returned by list-variables; delete batches also require confirm:true.', 'emcp-lite' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'watermark'  => array( 'type' => 'integer', 'minimum' => 0 ),
					'operations' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'confirm'    => array( 'type' => 'boolean' ),
				),
				'required'   => array( 'watermark', 'operations' ),
			),
			array( $this, 'execute_batch_variables' ),
			array( $this, 'check_write_permission' ),
			false,
			true,
			false
		);
	}

	/**
	 * Register one ability with consistent annotations.
	 */
	private function register_ability( string $name, string $label, string $description, array $input_schema, array $execute, array $permission, bool $readonly, bool $destructive, bool $idempotent ): void {
		emcp_tools_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'emcp-tools',
				'input_schema'        => $input_schema,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations'  => array( 'readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $idempotent ),
					'show_in_rest' => true,
				),
			)
		);
	}

	public function check_read_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	public function check_write_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/** @return array|\WP_Error */
	public function execute_list_variables( $input ) {
		$service = $this->service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}

		try {
			$record = $service->load();
		} catch ( \Throwable $e ) {
			return $this->exception_error( $e );
		}

		$include_deleted = ! empty( $input['include_deleted'] );
		$variables       = array();
		foreach ( (array) ( $record['data'] ?? array() ) as $id => $variable ) {
			$variable = (array) $variable;
			if ( ! $include_deleted && ! empty( $variable['deleted'] ) ) {
				continue;
			}
			$variables[] = array_merge( array( 'id' => (string) $id ), $variable );
		}

		return array(
			'count'      => count( $variables ),
			'variables'  => $variables,
			'types'      => $this->variable_types(),
			'watermark'  => (int) ( $record['watermark'] ?? 0 ),
			'version'    => (int) ( $record['version'] ?? 1 ),
		);
	}

	/** @return array|\WP_Error */
	public function execute_create_variable( $input ) {
		$data = $this->variable_data( $input, array( 'type', 'label', 'value' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return $this->write_call( 'create', array( $data ) );
	}

	/** @return array|\WP_Error */
	public function execute_update_variable( $input ) {
		$id = $this->required_string( $input, 'id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$data = $this->variable_data( $input, array( 'label', 'value' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $input['order'] ) ) {
			if ( ! is_numeric( $input['order'] ) || (int) $input['order'] < 0 ) {
				return new \WP_Error( 'invalid_order', __( 'Order must be a non-negative integer.', 'emcp-lite' ), array( 'status' => 400 ) );
			}
			$data['order'] = (int) $input['order'];
		}

		return $this->write_call( 'update', array( $id, $data ) );
	}

	/** @return array|\WP_Error */
	public function execute_delete_variable( $input ) {
		$id = $this->required_string( $input, 'id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( empty( $input['confirm'] ) ) {
			return new \WP_Error( 'confirm_required', __( 'Deleting a Global Variable requires confirm:true.', 'emcp-lite' ), array( 'status' => 400 ) );
		}

		return $this->write_call( 'delete', array( $id ) );
	}

	/** @return array|\WP_Error */
	public function execute_restore_variable( $input ) {
		$id = $this->required_string( $input, 'id' );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$overrides = $this->variable_data( $input, array() );
		if ( is_wp_error( $overrides ) ) {
			return $overrides;
		}

		return $this->write_call( 'restore', array( $id, $overrides ) );
	}

	/** @return array|\WP_Error */
	public function execute_batch_variables( $input ) {
		if ( ! isset( $input['watermark'] ) || ! is_numeric( $input['watermark'] ) || (int) $input['watermark'] < 0 ) {
			return new \WP_Error( 'invalid_watermark', __( 'A non-negative watermark is required.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		if ( empty( $input['operations'] ) || ! is_array( $input['operations'] ) ) {
			return new \WP_Error( 'missing_operations', __( 'A non-empty operations array is required.', 'emcp-lite' ), array( 'status' => 400 ) );
		}

		$allowed    = array( 'create', 'update', 'delete', 'restore' );
		$has_delete = false;
		foreach ( $input['operations'] as $index => $operation ) {
			$type = is_array( $operation ) ? (string) ( $operation['type'] ?? '' ) : '';
			if ( ! in_array( $type, $allowed, true ) ) {
				/* translators: %d: zero-based operation index. */
				return new \WP_Error( 'invalid_batch_operation', sprintf( __( 'Invalid variable operation at index %d.', 'emcp-lite' ), (int) $index ), array( 'status' => 400 ) );
			}
			$has_delete = $has_delete || 'delete' === $type;
		}
		if ( $has_delete && empty( $input['confirm'] ) ) {
			return new \WP_Error( 'confirm_required', __( 'A batch containing deletes requires confirm:true.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		$operations = $this->normalize_batch_operations( $input['operations'] );
		if ( is_wp_error( $operations ) ) {
			return $operations;
		}

		$service = $this->service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}
		try {
			$current = $service->load();
			if ( (int) ( $current['watermark'] ?? 0 ) !== (int) $input['watermark'] ) {
				return new \WP_Error(
					'stale_watermark',
					__( 'Global Variables changed since they were listed. List them again and retry with the new watermark.', 'emcp-lite' ),
					array( 'status' => 409, 'current_watermark' => (int) ( $current['watermark'] ?? 0 ) )
				);
			}
			$result = $service->process_batch( $operations );
			$this->clear_elementor_cache();
			return $result;
		} catch ( \Throwable $e ) {
			return $this->exception_error( $e );
		}
	}

	/**
	 * Construct Elementor's own Variables service.
	 *
	 * Protected so the unit suite can substitute an in-memory service.
	 *
	 * @return object|\WP_Error
	 */
	protected function service() {
		if ( ! self::is_available() ) {
			return new \WP_Error( 'variables_unavailable', __( 'Elementor Global Variables are not available on this site.', 'emcp-lite' ), array( 'status' => 503 ) );
		}
		try {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			if ( ! $kit || ! $kit->get_id() ) {
				return new \WP_Error( 'kit_not_found', __( 'Active Elementor kit not found.', 'emcp-lite' ), array( 'status' => 404 ) );
			}
			$repository = new \Elementor\Modules\Variables\Storage\Variables_Repository( $kit );
			return new \Elementor\Modules\Variables\Services\Variables_Service(
				$repository,
				new \Elementor\Modules\Variables\Services\Batch_Operations\Batch_Processor()
			);
		} catch ( \Throwable $e ) {
			return $this->exception_error( $e );
		}
	}

	/** @return array|\WP_Error */
	private function write_call( string $method, array $arguments ) {
		$service = $this->service();
		if ( is_wp_error( $service ) ) {
			return $service;
		}
		try {
			$result = $service->{$method}( ...$arguments );
			$this->clear_elementor_cache();
			return $result;
		} catch ( \Throwable $e ) {
			return $this->exception_error( $e );
		}
	}

	/** @return array|\WP_Error */
	private function variable_data( array $input, array $required_fields ) {
		$data = array();
		foreach ( array( 'type', 'label', 'value' ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				if ( in_array( $field, $required_fields, true ) ) {
					/* translators: %s: required input field name. */
					/* translators: %s: required input field name. */
			return new \WP_Error( 'missing_' . $field, sprintf( __( 'The "%s" field is required.', 'emcp-lite' ), $field ), array( 'status' => 400 ) );
				}
				continue;
			}
			$value = trim( sanitize_text_field( (string) $input[ $field ] ) );
			if ( '' === $value ) {
				/* translators: %s: required input field name. */
				return new \WP_Error( 'invalid_' . $field, sprintf( __( 'The "%s" field cannot be empty.', 'emcp-lite' ), $field ), array( 'status' => 400 ) );
			}
			if ( 'type' === $field && ! in_array( $value, $this->variable_types(), true ) ) {
				/* translators: %s: the requested identifier or comma-separated identifiers. */
				return new \WP_Error( 'invalid_type', sprintf( __( 'Unsupported Elementor variable type: %s', 'emcp-lite' ), $value ), array( 'status' => 400 ) );
			}
			if ( 'label' === $field && ( strlen( $value ) > 50 || false !== strpos( $value, ' ' ) ) ) {
				return new \WP_Error( 'invalid_label', __( 'Variable labels must be 50 characters or fewer and cannot contain spaces.', 'emcp-lite' ), array( 'status' => 400 ) );
			}
			if ( 'value' === $field && strlen( $value ) > 512 ) {
				return new \WP_Error( 'invalid_value', __( 'Variable values cannot exceed 512 characters.', 'emcp-lite' ), array( 'status' => 400 ) );
			}
			$data[ $field ] = $value;
		}
		return $data;
	}

	/** @return string|\WP_Error */
	private function required_string( array $input, string $field ) {
		$value = trim( sanitize_text_field( (string) ( $input[ $field ] ?? '' ) ) );
		if ( '' === $value ) {
			/* translators: %s: required input field name. */
			return new \WP_Error( 'missing_' . $field, sprintf( __( 'The "%s" field is required.', 'emcp-lite' ), $field ), array( 'status' => 400 ) );
		}
		if ( 'id' === $field && strlen( $value ) > 64 ) {
			return new \WP_Error( 'invalid_id', __( 'Variable ids cannot exceed 64 characters.', 'emcp-lite' ), array( 'status' => 400 ) );
		}
		return $value;
	}

	/**
	 * Validate and sanitize batch operations before Elementor's service sees them.
	 *
	 * @param array $operations Raw operations.
	 * @return array|\WP_Error
	 */
	private function normalize_batch_operations( array $operations ) {
		$normalized = array();
		foreach ( $operations as $index => $operation ) {
			$type = (string) $operation['type'];
			$out  = array( 'type' => $type );

			if ( 'create' === $type ) {
				if ( empty( $operation['variable'] ) || ! is_array( $operation['variable'] ) ) {
					/* translators: %d: zero-based operation index. */
					return new \WP_Error( 'invalid_batch_operation', sprintf( __( 'Create operation at index %d requires a variable object.', 'emcp-lite' ), (int) $index ), array( 'status' => 400 ) );
				}
				$variable = $this->variable_data( $operation['variable'], array( 'type', 'label', 'value' ) );
				if ( is_wp_error( $variable ) ) {
					return $variable;
				}
				if ( isset( $operation['variable']['id'] ) ) {
					$variable['id'] = sanitize_text_field( (string) $operation['variable']['id'] );
				}
				if ( isset( $operation['variable']['order'] ) ) {
					if ( ! is_numeric( $operation['variable']['order'] ) || (int) $operation['variable']['order'] < 0 ) {
						return new \WP_Error( 'invalid_order', __( 'Order must be a non-negative integer.', 'emcp-lite' ), array( 'status' => 400 ) );
					}
					$variable['order'] = (int) $operation['variable']['order'];
				}
				$out['variable'] = $variable;
			} elseif ( 'update' === $type ) {
				$id = $this->required_string( $operation, 'id' );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				if ( empty( $operation['variable'] ) || ! is_array( $operation['variable'] ) ) {
					/* translators: %d: zero-based operation index. */
					return new \WP_Error( 'invalid_batch_operation', sprintf( __( 'Update operation at index %d requires a variable object.', 'emcp-lite' ), (int) $index ), array( 'status' => 400 ) );
				}
				$variable = $this->variable_data( $operation['variable'], array() );
				if ( is_wp_error( $variable ) ) {
					return $variable;
				}
				if ( isset( $operation['variable']['order'] ) ) {
					if ( ! is_numeric( $operation['variable']['order'] ) || (int) $operation['variable']['order'] < 0 ) {
						return new \WP_Error( 'invalid_order', __( 'Order must be a non-negative integer.', 'emcp-lite' ), array( 'status' => 400 ) );
					}
					$variable['order'] = (int) $operation['variable']['order'];
				}
				$out['id']       = $id;
				$out['variable'] = $variable;
			} else {
				$id = $this->required_string( $operation, 'id' );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				$out['id'] = $id;
				if ( 'restore' === $type ) {
					$overrides = $this->variable_data( $operation, array() );
					if ( is_wp_error( $overrides ) ) {
						return $overrides;
					}
					$out = array_merge( $out, $overrides );
				}
			}
			$normalized[] = $out;
		}
		return $normalized;
	}

	/**
	 * Get the variable types registered by this Elementor version.
	 *
	 * @return string[]
	 */
	private function variable_types(): array {
		try {
			if ( class_exists( self::MODULE ) && method_exists( self::MODULE, 'instance' ) ) {
				$registry = self::MODULE::instance()->get_variable_types_registry();
				if ( is_object( $registry ) && method_exists( $registry, 'all' ) ) {
					return array_values( array_map( 'strval', array_keys( (array) $registry->all() ) ) );
				}
			}
		} catch ( \Throwable $e ) {
			// The registry initializes on `init`; schemas can still use known keys.
		}

		return array( 'global-color-variable', 'global-font-variable', 'global-size-variable', 'global-custom-size-variable' );
	}

	private function clear_elementor_cache(): void {
		try {
			if ( isset( \Elementor\Plugin::$instance->files_manager ) && method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		} catch ( \Throwable $e ) {
			// The write is authoritative; cache refresh is best-effort.
		}
	}

	/** @return \WP_Error */
	private function exception_error( \Throwable $e ) {
		$short = ( new \ReflectionClass( $e ) )->getShortName();
		$map   = array(
			'VariablesLimitReached' => 'variable_limit_reached',
			'DuplicatedLabel'       => 'duplicated_label',
			'RecordNotFound'        => 'variable_not_found',
			'Type_Mismatch'         => 'type_mismatch',
			'InvalidVariable'       => 'invalid_variable',
			'BatchOperationFailed'  => 'batch_operation_failed',
			'FatalError'            => 'variable_write_failed',
		);
		$client_errors = array( 'VariablesLimitReached', 'DuplicatedLabel', 'Type_Mismatch', 'InvalidVariable', 'BatchOperationFailed' );
		$status        = 'RecordNotFound' === $short ? 404 : ( in_array( $short, $client_errors, true ) ? 400 : 500 );
		$data          = array( 'status' => $status );
		if ( method_exists( $e, 'getErrorDetails' ) ) {
			$data['errors'] = $e->getErrorDetails();
		}
		return new \WP_Error( $map[ $short ] ?? 'variables_error', $e->getMessage(), $data );
	}
}
