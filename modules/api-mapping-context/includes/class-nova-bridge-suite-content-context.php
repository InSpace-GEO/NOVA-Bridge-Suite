<?php
/**
 * API endpoint discovery, field mapping, and user-defined REST guidance.
 *
 * @package NOVA_Bridge_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers content-writing REST resources and exposes private authoring context.
 *
 * This component deliberately stores only administrator-authored overrides. Live
 * route schemas are discovered on demand and are never copied into the option.
 */
final class Nova_Bridge_Suite_Content_Context {

	/** Option containing the versioned context configuration. */
	public const OPTION_NAME = 'nova_bridge_suite_content_contexts';

	/** Dedicated Settings API group. */
	public const SETTINGS_GROUP = 'nova_bridge_suite_content_context_settings';

	/** Canonical option schema version. */
	public const SCHEMA_VERSION = 4;

	/** Maximum number of configured resources. */
	private const MAX_RESOURCES = 1000;

	/** Maximum configured fields per resource. */
	private const MAX_FIELDS_PER_RESOURCE = 2000;

	/** Maximum configured templates per post-type resource. */
	private const MAX_TEMPLATES_PER_RESOURCE = 250;

	/** Maximum characters accepted for a template identifier or slug. */
	private const MAX_TEMPLATE_IDENTIFIER_LENGTH = 500;

	/** Maximum bytes accepted for a single JSON Pointer. */
	private const MAX_POINTER_LENGTH = 600;

	/** Maximum characters accepted for a single description. */
	private const MAX_DESCRIPTION_LENGTH = 4000;

	/** Maximum characters accepted for a NOVA content mapping key. */
	private const MAX_MAPPING_LENGTH = 250;

	/** Maximum aggregate description characters in the option. */
	private const MAX_TOTAL_DESCRIPTION_LENGTH = 500000;

	/** Maximum serialized payload size before JSON decoding. */
	private const MAX_PAYLOAD_BYTES = 4194304;

	/** Discovery REST namespace. */
	private const REST_NAMESPACE = 'nova-bridge/v1';

	/** Discovery REST path. */
	private const REST_DISCOVERY_ROUTE = '/content-endpoints';

	/** On-demand bridge field inspection path. */
	private const REST_BRIDGE_FIELDS_ROUTE = '/content-endpoints/bridge-fields';

	/** Whether runtime hooks have already been added. */
	private static $bootstrapped = false;

	/** Guards against accidental re-entry while enumerating the REST server. */
	private static $discovering = false;

	/** Post types whose prepare filter has already been registered. */
	private static $prepared_post_types = [];

	/** Normalized per-request settings cache. */
	private static $config_cache = null;

	/**
	 * Adds the module runtime hooks.
	 *
	 * The module entry point is responsible for loading this file and calling
	 * bootstrap() before WordPress fires the hooks registered here.
	 */
	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;

		add_filter( 'nova_bridge_suite_settings_tabs', [ __CLASS__, 'register_settings_tab' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_action( 'init', [ __CLASS__, 'register_post_type_prepare_filters' ], 999 );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_api' ], 999 );
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'filter_rest_post_dispatch' ], 999, 3 );
		add_filter( 'rest_prepare_product_cat', [ __CLASS__, 'filter_product_category_response' ], 10000, 3 );
		add_filter( 'woocommerce_rest_prepare_product_cat', [ __CLASS__, 'filter_product_category_response' ], 10000, 3 );
		add_action( 'add_option_' . self::OPTION_NAME, [ __CLASS__, 'flush_config_cache' ] );
		add_action( 'update_option_' . self::OPTION_NAME, [ __CLASS__, 'flush_config_cache' ] );
		add_action( 'delete_option_' . self::OPTION_NAME, [ __CLASS__, 'flush_config_cache' ] );
	}

	/** Registers the module-owned NOVA settings tab. */
	public static function register_settings_tab( array $tabs ): array {
		$tabs['api-mapping-context'] = [
			'label'           => __( 'API Mapping Context', 'nova-bridge-suite' ),
			'render_callback' => [ __CLASS__, 'render_settings_tab' ],
			'legacy_slugs'    => [ 'content-context' ],
		];

		return $tabs;
	}

	/**
	 * Returns the canonical empty configuration.
	 *
	 * @return array{version:int,resources:array<string,array<string,mixed>>}
	 */
	private static function empty_config(): array {
		return [
			'version'   => self::SCHEMA_VERSION,
			'resources' => [],
		];
	}

	/**
	 * Registers the isolated option and creates it with autoload disabled.
	 */
	public static function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'description'       => __( 'NOVA content endpoint field guidance.', 'nova-bridge-suite' ),
				'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
				'default'           => self::empty_config(),
				'show_in_rest'      => false,
			]
		);

		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::empty_config(), '', 'no' );
		}
	}

	/**
	 * Reads and normalizes the saved option without mutating it.
	 *
	 * @return array{version:int,resources:array<string,array<string,mixed>>}
	 */
	public static function get_option(): array {
		if ( is_array( self::$config_cache ) ) {
			return self::$config_cache;
		}

		$raw = get_option( self::OPTION_NAME, self::empty_config() );

		if ( ! is_array( $raw ) ) {
			self::$config_cache = self::empty_config();

			return self::$config_cache;
		}

		$result = self::normalize_settings( $raw, false );
		self::$config_cache = is_array( $result ) ? $result : self::empty_config();

		return self::$config_cache;
	}

	/** Clears the normalized settings cache after an option mutation. */
	public static function flush_config_cache(): void {
		self::$config_cache = null;
	}

	/**
	 * Strict Settings API sanitizer.
	 *
	 * The UI submits a JSON string in a `payload` field to prevent PHP's input
	 * nesting and max-input-vars limits from damaging large configurations. Tests
	 * and integrations may also pass the canonical array directly.
	 *
	 * @param mixed $input Raw settings input.
	 * @return array{version:int,resources:array<string,array<string,mixed>>}
	 */
	public static function sanitize_settings( $input ): array {
		$decoded = $input;

		if ( is_array( $input ) && array_key_exists( 'payload', $input ) ) {
			$payload = $input['payload'];

			if ( ! is_string( $payload ) ) {
				return self::reject_settings( __( 'The content context payload must be JSON text.', 'nova-bridge-suite' ) );
			}

			if ( strlen( $payload ) > self::MAX_PAYLOAD_BYTES ) {
				return self::reject_settings( __( 'The content context payload is too large.', 'nova-bridge-suite' ) );
			}

			$decoded = json_decode( $payload, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				// Settings API callers normally pass an already-unslashed value, while
				// direct integrations may pass WordPress magic-quoted request data.
				// Decode the original first so regex routes such as "\\d+" are not
				// corrupted by an unnecessary second wp_unslash().
				$unslashed = wp_unslash( $payload );
				if ( $unslashed !== $payload ) {
					$decoded = json_decode( $unslashed, true );
				}
			}
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				return self::reject_settings( __( 'The content context payload is not valid JSON.', 'nova-bridge-suite' ) );
			}
		}

		if ( ! is_array( $decoded ) ) {
			return self::reject_settings( __( 'The content context settings must be an object.', 'nova-bridge-suite' ) );
		}

		$normalized = self::normalize_settings( $decoded, true );
		if ( ! is_array( $normalized ) ) {
			return self::get_option();
		}

		return $normalized;
	}

	/**
	 * Normalizes the canonical option structure.
	 *
	 * @param array $input         Candidate configuration.
	 * @param bool  $report_errors Whether invalid input should be reported.
	 * @return array|null Canonical configuration, or null when strict validation fails.
	 */
	private static function normalize_settings( array $input, bool $report_errors ) {
		$version = isset( $input['version'] ) ? absint( $input['version'] ) : self::SCHEMA_VERSION;
		if ( ! in_array( $version, [ 1, 2, 3, self::SCHEMA_VERSION ], true ) ) {
			return self::normalization_error( __( 'Unsupported content context settings version.', 'nova-bridge-suite' ), $report_errors );
		}
		$is_legacy = 1 === $version;

		$resources = isset( $input['resources'] ) ? $input['resources'] : [];
		if ( ! is_array( $resources ) ) {
			return self::normalization_error( __( 'Content context resources must be an object or array.', 'nova-bridge-suite' ), $report_errors );
		}

		if ( count( $resources ) > self::MAX_RESOURCES ) {
			return self::normalization_error( __( 'Too many content context resources were submitted.', 'nova-bridge-suite' ), $report_errors );
		}

		$canonical   = self::empty_config();
		$total_chars = 0;

		foreach ( $resources as $resource_key => $resource ) {
			if ( ! is_array( $resource ) ) {
				return self::normalization_error( __( 'Each content context resource must be an object.', 'nova-bridge-suite' ), $report_errors );
			}

			$resource_id = isset( $resource['id'] ) && is_scalar( $resource['id'] )
				? trim( (string) $resource['id'] )
				: ( is_string( $resource_key ) ? trim( $resource_key ) : '' );
			$type        = isset( $resource['type'] ) && is_scalar( $resource['type'] )
				? sanitize_key( (string) $resource['type'] )
				: '';
			$enabled     = $is_legacy || ! array_key_exists( 'enabled', $resource ) || ! empty( $resource['enabled'] );

			if ( ! in_array( $type, [ 'post_type', 'taxonomy', 'route' ], true ) ) {
				return self::normalization_error( __( 'A content context resource has an invalid type.', 'nova-bridge-suite' ), $report_errors );
			}

			$post_type = '';
			$taxonomy  = '';
			$route     = '';
			$methods   = [];

			if ( 'post_type' === $type ) {
				$post_type = isset( $resource['post_type'] ) && is_scalar( $resource['post_type'] )
					? sanitize_key( (string) $resource['post_type'] )
					: '';

				if ( '' === $post_type ) {
					if ( 0 === strpos( $resource_id, 'post_type:' ) ) {
						$post_type = sanitize_key( substr( $resource_id, strlen( 'post_type:' ) ) );
					}
				}

				if ( '' === $post_type || strlen( $post_type ) > 191 ) {
					return self::normalization_error( __( 'A post type context has an invalid post type.', 'nova-bridge-suite' ), $report_errors );
				}

				$resource_id = 'post_type:' . $post_type;
			} elseif ( 'taxonomy' === $type ) {
				$taxonomy = isset( $resource['taxonomy'] ) && is_scalar( $resource['taxonomy'] )
					? sanitize_key( (string) $resource['taxonomy'] )
					: '';

				if ( '' === $taxonomy && 0 === strpos( $resource_id, 'taxonomy:' ) ) {
					$taxonomy = sanitize_key( substr( $resource_id, strlen( 'taxonomy:' ) ) );
				}

				if ( '' === $taxonomy || strlen( $taxonomy ) > 191 ) {
					return self::normalization_error( __( 'A taxonomy context has an invalid taxonomy.', 'nova-bridge-suite' ), $report_errors );
				}

				$resource_id = 'taxonomy:' . $taxonomy;
			} else {
				$route = isset( $resource['route'] ) && is_scalar( $resource['route'] )
					? self::sanitize_route_pattern( (string) $resource['route'] )
					: '';

				if ( '' === $route ) {
					return self::normalization_error( __( 'A route context has an invalid REST route.', 'nova-bridge-suite' ), $report_errors );
				}

				$resource_id = 'route:' . substr( hash( 'sha256', $route ), 0, 24 );
				$methods     = self::sanitize_saved_methods( isset( $resource['methods'] ) ? $resource['methods'] : [] );
			}

			$field_input = [];
			if ( isset( $resource['fields'] ) ) {
				$field_input = $resource['fields'];
			} elseif ( isset( $resource['descriptions'] ) ) {
				$field_input = $resource['descriptions'];
			} elseif ( isset( $resource['meta_descriptions'] ) ) {
				$field_input = $resource['meta_descriptions'];
			}

			if ( ! is_array( $field_input ) ) {
				return self::normalization_error( __( 'Content context fields must be an object or array.', 'nova-bridge-suite' ), $report_errors );
			}

			if ( count( $field_input ) > self::MAX_FIELDS_PER_RESOURCE ) {
				return self::normalization_error( __( 'Too many fields were configured for a content resource.', 'nova-bridge-suite' ), $report_errors );
			}

			$fields                    = [];
			$legacy_template_items     = [];
			$legacy_template_selected  = [];
			foreach ( $field_input as $field_key => $field ) {
				$pointer     = is_string( $field_key ) ? trim( $field_key ) : '';
				$description = '';
				$mapping     = '';
				$manual      = false;

				if ( is_array( $field ) ) {
					if ( isset( $field['path'] ) && is_scalar( $field['path'] ) ) {
						$pointer = trim( (string) $field['path'] );
					}

					if ( isset( $field['description'] ) && is_scalar( $field['description'] ) ) {
						$description = (string) $field['description'];
					}

					if ( isset( $field['mapping'] ) && is_scalar( $field['mapping'] ) ) {
						$mapping = (string) $field['mapping'];
					} elseif ( isset( $field['nova_field'] ) && is_scalar( $field['nova_field'] ) ) {
						$mapping = (string) $field['nova_field'];
					}

					$manual = ! empty( $field['manual'] );
				} elseif ( is_scalar( $field ) ) {
					$description = (string) $field;
				}

				if ( ! self::is_valid_json_pointer( $pointer ) ) {
					return self::normalization_error( __( 'A content context field has an invalid JSON Pointer path.', 'nova-bridge-suite' ), $report_errors );
				}

				$description = sanitize_textarea_field( $description );
				$description = trim( self::limit_characters( $description, self::MAX_DESCRIPTION_LENGTH ) );
				$mapping     = sanitize_text_field( $mapping );
				$mapping     = trim( self::limit_characters( $mapping, self::MAX_MAPPING_LENGTH ) );

				$total_chars += self::string_length( $description ) + self::string_length( $mapping );
				if ( $total_chars > self::MAX_TOTAL_DESCRIPTION_LENGTH ) {
					return self::normalization_error( __( 'The combined content context descriptions are too large.', 'nova-bridge-suite' ), $report_errors );
				}

				$template_segments = 'post_type' === $type ? self::decode_json_pointer( $pointer ) : null;
				if (
					is_array( $template_segments )
					&& isset( $template_segments[0], $template_segments[1] )
					&& '@templates' === (string) $template_segments[0]
					&& (
						2 === count( $template_segments )
						|| ( 4 === count( $template_segments ) && 'fields' === (string) $template_segments[2] )
					)
				) {
					$template_id = self::sanitize_template_identifier( $template_segments[1] );
					if ( '' === $template_id ) {
						return self::normalization_error( __( 'A template context has an invalid template identifier.', 'nova-bridge-suite' ), $report_errors );
					}
					if ( ! isset( $legacy_template_items[ $template_id ] ) ) {
						$legacy_template_items[ $template_id ] = [
							'slug'        => 'default' === $template_id ? '' : $template_id,
							'description' => '',
							'mapping'     => '',
							'fields'      => [],
						];
					}

					if ( 2 === count( $template_segments ) ) {
						$legacy_template_items[ $template_id ]['description'] = $description;
						$legacy_template_items[ $template_id ]['mapping']     = $mapping;
					} else {
						$target_pointer = (string) $template_segments[3];
						if ( ! self::is_valid_json_pointer( $target_pointer ) ) {
							return self::normalization_error( __( 'A template field override has an invalid target JSON Pointer.', 'nova-bridge-suite' ), $report_errors );
						}
						$legacy_template_items[ $template_id ]['fields'][ $target_pointer ] = [
							'description' => $description,
							'mapping'     => $mapping,
							'manual'      => (bool) $manual,
						];
					}
					$legacy_template_selected[ $template_id ] = true;
					continue;
				}
				if ( is_array( $template_segments ) && isset( $template_segments[0] ) && '@templates' === (string) $template_segments[0] ) {
					return self::normalization_error( __( 'A legacy template context has an invalid JSON Pointer shape.', 'nova-bridge-suite' ), $report_errors );
				}
				if ( '' === $description && '' === $mapping && ! $manual ) {
					continue;
				}

				$fields[ $pointer ] = [
					'description' => $description,
					'mapping'     => $mapping,
					'manual'      => (bool) $manual,
				];
			}

			// Version 2 deliberately retains post type selections even when no
			// descriptions have been authored yet. Route contexts remain sparse so
			// the option does not become a copy of the complete REST route table.
			if ( empty( $fields ) && 'route' === $type ) {
				continue;
			}

			ksort( $fields, SORT_NATURAL | SORT_FLAG_CASE );

			$template_config = [];
			if ( 'post_type' === $type && ( array_key_exists( 'templates', $resource ) || ! empty( $legacy_template_items ) ) ) {
				$template_config = self::normalize_template_settings(
					array_key_exists( 'templates', $resource ) ? $resource['templates'] : [],
					$legacy_template_items,
					array_keys( $legacy_template_selected ),
					count( $fields ),
					$total_chars,
					$report_errors
				);
				if ( null === $template_config ) {
					return null;
				}
			}

			$canonical_resource = [
				'id'      => $resource_id,
				'type'    => $type,
				'enabled' => (bool) $enabled,
				'fields'  => $fields,
			];

			if ( 'post_type' === $type ) {
				$canonical_resource['post_type'] = $post_type;
				if ( ! empty( $template_config ) ) {
					$canonical_resource['templates'] = $template_config;
				}
			} elseif ( 'taxonomy' === $type ) {
				$canonical_resource['taxonomy'] = $taxonomy;
			} else {
				$canonical_resource['route']   = $route;
				$canonical_resource['methods'] = $methods;
			}

			$canonical['resources'][ $resource_id ] = $canonical_resource;
		}

		ksort( $canonical['resources'], SORT_NATURAL | SORT_FLAG_CASE );

		return $canonical;
	}

	/** Sanitizes a stable template identifier without stripping path separators. */
	private static function sanitize_template_identifier( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( sanitize_text_field( (string) $value ) );
		if (
			'' === $value
			|| strlen( $value ) > self::MAX_TEMPLATE_IDENTIFIER_LENGTH
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value )
			|| 1 === preg_match( '/^\d+$/', $value )
		) {
			return '';
		}

		return $value;
	}

	/** Sanitizes a WordPress template slug; an empty slug represents default. */
	private static function sanitize_template_slug( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$raw   = trim( (string) $value );
		$value = trim( sanitize_text_field( $raw ) );
		if (
			strlen( $value ) > self::MAX_TEMPLATE_IDENTIFIER_LENGTH
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value )
			|| ( '' !== $raw && '' === $value )
		) {
			return null;
		}

		return $value;
	}

	/**
	 * Normalizes endpoint-level template selection, context, and field overrides.
	 *
	 * @param mixed        $input             Candidate v4 template configuration.
	 * @param array        $legacy_items      Items migrated from v1-v3 pointers.
	 * @param string[]     $legacy_selected   Template IDs inferred from old pointers.
	 * @param int          $base_field_count  Number of configured non-template fields.
	 * @param int          $total_chars       Aggregate option character counter.
	 * @param bool         $report_errors     Whether to report validation errors.
	 * @return array|null Canonical template configuration, or null on error.
	 */
	private static function normalize_template_settings( $input, array $legacy_items, array $legacy_selected, int $base_field_count, int &$total_chars, bool $report_errors ) {
		if ( ! is_array( $input ) ) {
			return self::normalization_error( __( 'A post type template configuration must be an object.', 'nova-bridge-suite' ), $report_errors );
		}

		$raw_items = isset( $input['items'] ) ? $input['items'] : [];
		if ( ! is_array( $raw_items ) ) {
			return self::normalization_error( __( 'Template configuration items must be an object.', 'nova-bridge-suite' ), $report_errors );
		}
		if ( count( $raw_items ) > self::MAX_TEMPLATES_PER_RESOURCE || count( $legacy_items ) > self::MAX_TEMPLATES_PER_RESOURCE ) {
			return self::normalization_error( __( 'Too many templates were configured for a content resource.', 'nova-bridge-suite' ), $report_errors );
		}

		$items               = [];
		$slug_owners         = [];
		$configured_fields   = $base_field_count;
		foreach ( $raw_items as $item_key => $item ) {
			if ( ! is_array( $item ) ) {
				return self::normalization_error( __( 'Each template configuration item must be an object.', 'nova-bridge-suite' ), $report_errors );
			}

			$template_id = self::sanitize_template_identifier( isset( $item['id'] ) ? $item['id'] : $item_key );
			if ( '' === $template_id ) {
				return self::normalization_error( __( 'A template configuration has an invalid identifier.', 'nova-bridge-suite' ), $report_errors );
			}
			if ( isset( $items[ $template_id ] ) ) {
				return self::normalization_error( __( 'Template configuration identifiers must be unique.', 'nova-bridge-suite' ), $report_errors );
			}

			$slug = array_key_exists( 'slug', $item ) ? self::sanitize_template_slug( $item['slug'] ) : ( 'default' === $template_id ? '' : $template_id );
			if ( null === $slug ) {
				return self::normalization_error( __( 'A template configuration has an invalid slug.', 'nova-bridge-suite' ), $report_errors );
			}
			if ( 'default' === $template_id ) {
				if ( '' !== $slug ) {
					return self::normalization_error( __( 'The default template must use an empty slug.', 'nova-bridge-suite' ), $report_errors );
				}
			} elseif ( '' === $slug ) {
				$slug = $template_id;
			}
			if ( $template_id !== self::template_id_from_slug( $slug ) ) {
				return self::normalization_error( __( 'A template identifier must match its template slug.', 'nova-bridge-suite' ), $report_errors );
			}
			if ( isset( $slug_owners[ $slug ] ) && $template_id !== $slug_owners[ $slug ] ) {
				return self::normalization_error( __( 'Template configuration slugs must be unique.', 'nova-bridge-suite' ), $report_errors );
			}
			$slug_owners[ $slug ] = $template_id;

			$description = isset( $item['description'] ) && is_scalar( $item['description'] )
				? (string) $item['description']
				: ( isset( $item['context'] ) && is_scalar( $item['context'] ) ? (string) $item['context'] : '' );
			$mapping = isset( $item['mapping'] ) && is_scalar( $item['mapping'] )
				? (string) $item['mapping']
				: ( isset( $item['nova_field'] ) && is_scalar( $item['nova_field'] ) ? (string) $item['nova_field'] : '' );
			$description = trim( self::limit_characters( sanitize_textarea_field( $description ), self::MAX_DESCRIPTION_LENGTH ) );
			$mapping     = trim( self::limit_characters( sanitize_text_field( $mapping ), self::MAX_MAPPING_LENGTH ) );
			$total_chars += self::string_length( $description ) + self::string_length( $mapping );
			if ( $total_chars > self::MAX_TOTAL_DESCRIPTION_LENGTH ) {
				return self::normalization_error( __( 'The combined content context descriptions are too large.', 'nova-bridge-suite' ), $report_errors );
			}

			$raw_fields = isset( $item['fields'] ) ? $item['fields'] : [];
			if ( ! is_array( $raw_fields ) ) {
				return self::normalization_error( __( 'Template field overrides must be an object.', 'nova-bridge-suite' ), $report_errors );
			}
			$override_fields = [];
			foreach ( $raw_fields as $field_key => $field ) {
				if ( ! is_array( $field ) && ! is_scalar( $field ) ) {
					return self::normalization_error( __( 'Each template field override must be an object.', 'nova-bridge-suite' ), $report_errors );
				}

				$pointer = is_string( $field_key ) ? trim( $field_key ) : '';
				$field_description = '';
				$field_mapping     = '';
				$manual            = false;
				if ( is_array( $field ) ) {
					if ( isset( $field['path'] ) && is_scalar( $field['path'] ) ) {
						$pointer = trim( (string) $field['path'] );
					}
					if ( isset( $field['description'] ) && is_scalar( $field['description'] ) ) {
						$field_description = (string) $field['description'];
					}
					if ( isset( $field['mapping'] ) && is_scalar( $field['mapping'] ) ) {
						$field_mapping = (string) $field['mapping'];
					} elseif ( isset( $field['nova_field'] ) && is_scalar( $field['nova_field'] ) ) {
						$field_mapping = (string) $field['nova_field'];
					}
					$manual = ! empty( $field['manual'] );
				} else {
					$field_description = (string) $field;
				}

				if ( ! self::is_valid_json_pointer( $pointer ) ) {
					return self::normalization_error( __( 'A template field override has an invalid target JSON Pointer.', 'nova-bridge-suite' ), $report_errors );
				}
				if ( isset( $override_fields[ $pointer ] ) ) {
					return self::normalization_error( __( 'Template field override paths must be unique.', 'nova-bridge-suite' ), $report_errors );
				}
				$field_description = trim( self::limit_characters( sanitize_textarea_field( $field_description ), self::MAX_DESCRIPTION_LENGTH ) );
				$field_mapping     = trim( self::limit_characters( sanitize_text_field( $field_mapping ), self::MAX_MAPPING_LENGTH ) );
				if ( '' === $field_description && '' === $field_mapping && ! $manual ) {
					continue;
				}

				++$configured_fields;
				if ( $configured_fields > self::MAX_FIELDS_PER_RESOURCE ) {
					return self::normalization_error( __( 'Too many fields were configured for a content resource.', 'nova-bridge-suite' ), $report_errors );
				}
				$total_chars += self::string_length( $field_description ) + self::string_length( $field_mapping );
				if ( $total_chars > self::MAX_TOTAL_DESCRIPTION_LENGTH ) {
					return self::normalization_error( __( 'The combined content context descriptions are too large.', 'nova-bridge-suite' ), $report_errors );
				}

				$override_fields[ $pointer ] = [
					'description' => $field_description,
					'mapping'     => $field_mapping,
					'manual'      => (bool) $manual,
				];
			}
			ksort( $override_fields, SORT_NATURAL | SORT_FLAG_CASE );
			$items[ $template_id ] = [
				'slug'        => $slug,
				'description' => $description,
				'mapping'     => $mapping,
				'fields'      => $override_fields,
			];
		}

		foreach ( $legacy_items as $legacy_id => $legacy_item ) {
			$template_id = self::sanitize_template_identifier( $legacy_id );
			if ( '' === $template_id || ! is_array( $legacy_item ) ) {
				return self::normalization_error( __( 'A migrated template context is invalid.', 'nova-bridge-suite' ), $report_errors );
			}
			$legacy_slug = self::sanitize_template_slug( isset( $legacy_item['slug'] ) ? $legacy_item['slug'] : ( 'default' === $template_id ? '' : $template_id ) );
			if ( null === $legacy_slug ) {
				return self::normalization_error( __( 'A migrated template context has an invalid slug.', 'nova-bridge-suite' ), $report_errors );
			}
			if ( ! isset( $items[ $template_id ] ) ) {
				if ( isset( $slug_owners[ $legacy_slug ] ) && $template_id !== $slug_owners[ $legacy_slug ] ) {
					return self::normalization_error( __( 'Template configuration slugs must be unique.', 'nova-bridge-suite' ), $report_errors );
				}
				$slug_owners[ $legacy_slug ] = $template_id;
				$items[ $template_id ] = [
					'slug'        => $legacy_slug,
					'description' => isset( $legacy_item['description'] ) ? (string) $legacy_item['description'] : '',
					'mapping'     => isset( $legacy_item['mapping'] ) ? (string) $legacy_item['mapping'] : '',
					'fields'      => isset( $legacy_item['fields'] ) && is_array( $legacy_item['fields'] ) ? $legacy_item['fields'] : [],
				];
				$configured_fields += count( $items[ $template_id ]['fields'] );
			} else {
				if ( '' === $items[ $template_id ]['description'] && ! empty( $legacy_item['description'] ) ) {
					$items[ $template_id ]['description'] = (string) $legacy_item['description'];
				}
				if ( '' === $items[ $template_id ]['mapping'] && ! empty( $legacy_item['mapping'] ) ) {
					$items[ $template_id ]['mapping'] = (string) $legacy_item['mapping'];
				}
				foreach ( (array) ( $legacy_item['fields'] ?? [] ) as $pointer => $field ) {
					if ( ! isset( $items[ $template_id ]['fields'][ $pointer ] ) ) {
						$items[ $template_id ]['fields'][ $pointer ] = $field;
						++$configured_fields;
					}
				}
			}
			if ( $configured_fields > self::MAX_FIELDS_PER_RESOURCE ) {
				return self::normalization_error( __( 'Too many fields were configured for a content resource.', 'nova-bridge-suite' ), $report_errors );
			}
			ksort( $items[ $template_id ]['fields'], SORT_NATURAL | SORT_FLAG_CASE );
		}
		if ( count( $items ) > self::MAX_TEMPLATES_PER_RESOURCE ) {
			return self::normalization_error( __( 'Too many templates were configured for a content resource.', 'nova-bridge-suite' ), $report_errors );
		}

		$raw_selected = isset( $input['selected'] ) ? $input['selected'] : [];
		if ( ! is_array( $raw_selected ) ) {
			return self::normalization_error( __( 'Selected templates must be an array.', 'nova-bridge-suite' ), $report_errors );
		}
		$selected = [];
		foreach ( array_merge( $raw_selected, $legacy_selected ) as $selected_id ) {
			$template_id = self::sanitize_template_identifier( $selected_id );
			if ( '' === $template_id || ! isset( $items[ $template_id ] ) ) {
				return self::normalization_error( __( 'A selected template does not have a matching configuration item.', 'nova-bridge-suite' ), $report_errors );
			}
			if ( ! in_array( $template_id, $selected, true ) ) {
				$selected[] = $template_id;
			}
		}

		$has_explicit_primary = array_key_exists( 'primary', $input );
		$primary = '';
		if ( $has_explicit_primary && null !== $input['primary'] && '' !== $input['primary'] ) {
			$primary = self::sanitize_template_identifier( $input['primary'] );
			if ( '' === $primary ) {
				return self::normalization_error( __( 'The primary template identifier is invalid.', 'nova-bridge-suite' ), $report_errors );
			}
		}
		if ( '' !== $primary && ! in_array( $primary, $selected, true ) ) {
			return self::normalization_error( __( 'The primary template must also be selected.', 'nova-bridge-suite' ), $report_errors );
		}
		if ( '' === $primary && ! empty( $selected ) ) {
			if ( 1 === count( $selected ) ) {
				$primary = $selected[0];
			} elseif ( in_array( 'default', $selected, true ) ) {
				$primary = 'default';
			} else {
				$primary = $selected[0];
			}
		}
		if ( count( $selected ) > 1 ) {
			foreach ( $selected as $selected_id ) {
				if ( '' === (string) ( $items[ $selected_id ]['description'] ?? '' ) ) {
					return self::normalization_error( __( 'Each selected template needs usage guidance when more than one template is selected.', 'nova-bridge-suite' ), $report_errors );
				}
			}
		}

		ksort( $items, SORT_NATURAL | SORT_FLAG_CASE );
		if ( empty( $items ) && empty( $selected ) && '' === $primary ) {
			return [];
		}

		return [
			'selected' => array_values( $selected ),
			'primary'  => $primary,
			'items'    => $items,
		];
	}

	/**
	 * Reports a settings error and returns no normalized value.
	 *
	 * @param string $message       Error message.
	 * @param bool   $report_errors Whether to call add_settings_error().
	 * @return null
	 */
	private static function normalization_error( string $message, bool $report_errors ) {
		if ( $report_errors && function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION_NAME, 'nova_content_context_invalid', $message, 'error' );
		}

		return null;
	}

	/**
	 * Rejects Settings API input while preserving the last valid option.
	 */
	private static function reject_settings( string $message ): array {
		self::normalization_error( $message, true );

		return self::get_option();
	}

	/**
	 * Validates an RFC 6901 JSON Pointer (root pointers are not field paths).
	 */
	private static function is_valid_json_pointer( string $pointer ): bool {
		if ( '' === $pointer || strlen( $pointer ) > self::MAX_POINTER_LENGTH || '/' !== $pointer[0] ) {
			return false;
		}

		if ( false !== strpos( $pointer, "\0" ) || false !== strpos( $pointer, "\r" ) || false !== strpos( $pointer, "\n" ) ) {
			return false;
		}

		return 1 === preg_match( '/^(?:\/(?:[^~]|~[01])*)+$/u', $pointer );
	}

	/**
	 * Sanitizes a stored route pattern without altering valid WP regex syntax.
	 */
	private static function sanitize_route_pattern( string $route ): string {
		// Registered WordPress routes are regex patterns. Named captures such as
		// `(?P<id>[\d]+)` legitimately contain angle brackets, and optional
		// segments contain question marks, so HTML/text sanitizers would corrupt
		// the stable key. A stored pattern is only ever accepted when it exactly
		// matches a route returned by the live REST server before use.
		$route = trim( $route );
		if ( '' === $route || '/' !== $route[0] || strlen( $route ) > 1000 ) {
			return '';
		}

		if (
			false !== strpos( $route, "\0" ) ||
			false !== strpos( $route, "\r" ) ||
			false !== strpos( $route, "\n" )
		) {
			return '';
		}

		return '/' . ltrim( $route, '/' );
	}

	/**
	 * Sanitizes saved write methods.
	 *
	 * @param mixed $methods Raw method collection.
	 * @return string[]
	 */
	private static function sanitize_saved_methods( $methods ): array {
		if ( is_string( $methods ) ) {
			$methods = preg_split( '/[\s,|]+/', $methods );
		}

		if ( ! is_array( $methods ) ) {
			return [];
		}

		$sanitized = [];
		foreach ( $methods as $method_key => $method_value ) {
			$method = is_string( $method_key ) && ! is_numeric( $method_key )
				? strtoupper( $method_key )
				: ( is_scalar( $method_value ) ? strtoupper( (string) $method_value ) : '' );

			if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
				$sanitized[] = $method;
			}
		}

		$sanitized = array_values( array_unique( $sanitized ) );
		sort( $sanitized );

		return $sanitized;
	}

	/** Returns a multibyte-safe string length when possible. */
	private static function string_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/** Truncates text without requiring the mbstring extension. */
	private static function limit_characters( string $value, int $length ): string {
		if ( self::string_length( $value ) <= $length ) {
			return $value;
		}

		return function_exists( 'mb_substr' ) ? (string) mb_substr( $value, 0, $length, 'UTF-8' ) : substr( $value, 0, $length );
	}

	/**
	 * Hooks the late response merger for every registered post type.
	 *
	 * Registration is intentionally done on init rather than at file load so CPTs
	 * registered by themes and other plugins are included.
	 */
	public static function register_post_type_prepare_filters(): void {
		$post_types = get_post_types( [], 'names' );
		if ( ! is_array( $post_types ) ) {
			return;
		}

		foreach ( $post_types as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( '' === $post_type || isset( self::$prepared_post_types[ $post_type ] ) ) {
				continue;
			}

			add_filter( 'rest_prepare_' . $post_type, [ __CLASS__, 'filter_post_type_response' ], 10000, 3 );
			self::$prepared_post_types[ $post_type ] = true;
		}
	}

	/**
	 * Registers the authenticated discovery endpoint and collision-safe helper fields.
	 */
	public static function register_rest_api(): void {
		// Catch post types registered after our late init callback as well.
		self::register_post_type_prepare_filters();

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_DISCOVERY_ROUTE,
			[
				'methods'             => class_exists( 'WP_REST_Server' ) ? WP_REST_Server::READABLE : 'GET',
				'callback'            => [ __CLASS__, 'get_discovery_response' ],
				'permission_callback' => [ __CLASS__, 'can_discover_resources' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_BRIDGE_FIELDS_ROUTE,
			[
				'methods'             => class_exists( 'WP_REST_Server' ) ? WP_REST_Server::READABLE : 'GET',
				'callback'            => [ __CLASS__, 'get_bridge_fields_response' ],
				'permission_callback' => [ __CLASS__, 'can_inspect_bridge_fields' ],
				'args'                => [
					'post_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		$config = self::get_option();
		if ( empty( $config['resources'] ) ) {
			return;
		}

		global $wp_rest_additional_fields;

		foreach ( $config['resources'] as $resource ) {
			if ( ! is_array( $resource ) || empty( $resource['enabled'] ) ) {
				continue;
			}

			$type        = isset( $resource['type'] ) ? (string) $resource['type'] : '';
			$has_fields  = ! empty( $resource['fields'] ) && is_array( $resource['fields'] );
			$has_templates = 'post_type' === $type
				&& ! empty( $resource['templates'] )
				&& is_array( $resource['templates'] )
				&& ( ! empty( $resource['templates']['selected'] ) || ! empty( $resource['templates']['items'] ) );
			if ( ! $has_fields && ! $has_templates ) {
				continue;
			}
			$object_type = '';
			if ( 'post_type' === $type ) {
				$object_type = isset( $resource['post_type'] ) ? sanitize_key( (string) $resource['post_type'] ) : '';
				if ( '' === $object_type || ! post_type_exists( $object_type ) ) {
					continue;
				}
			} elseif ( 'taxonomy' === $type ) {
				$object_type = isset( $resource['taxonomy'] ) ? sanitize_key( (string) $resource['taxonomy'] ) : '';
				if ( '' === $object_type || ! taxonomy_exists( $object_type ) ) {
					continue;
				}
			} else {
				continue;
			}
			if ( 'post_type' === $type && self::is_suite_owned_context_post_type( $object_type ) ) {
				continue;
			}

			// Service/Blog modules already register this field. Never replace theirs.
			if ( ! isset( $wp_rest_additional_fields[ $object_type ]['meta_descriptions'] ) ) {
				$get_callback = static function ( $object, $field_name, $request ) use ( $type, $object_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
					if ( 'taxonomy' === $type ) {
						return Nova_Bridge_Suite_Content_Context::get_taxonomy_meta_descriptions( $object_type, $object, $request );
					}

					return Nova_Bridge_Suite_Content_Context::get_generic_meta_descriptions( $object_type, $object, $request );
				};

				register_rest_field(
					$object_type,
					'meta_descriptions',
					[
						'get_callback' => $get_callback,
						'schema'       => [
							'description'          => __( 'Private NOVA authoring guidance for this resource.', 'nova-bridge-suite' ),
							'type'                 => 'object',
							'context'              => [ 'edit' ],
							'readonly'             => true,
							'additionalProperties' => [
								'type' => 'string',
							],
						],
					]
				);
			}

			if ( ! isset( $wp_rest_additional_fields[ $object_type ]['nova_content_mappings'] ) ) {
				$mapping_callback = static function ( $object, $field_name, $request ) use ( $type, $object_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
					return Nova_Bridge_Suite_Content_Context::get_generic_content_mappings( $type, $object_type, $object, $request );
				};

				register_rest_field(
					$object_type,
					'nova_content_mappings',
					[
						'get_callback' => $mapping_callback,
						'schema'       => [
							'description'          => __( 'Private NOVA source-field mappings for this resource.', 'nova-bridge-suite' ),
							'type'                 => 'object',
							'context'              => [ 'edit' ],
							'readonly'             => true,
							'additionalProperties' => [ 'type' => 'string' ],
						],
					]
				);
			}

			if ( 'post_type' === $type && $has_templates && ! isset( $wp_rest_additional_fields[ $object_type ]['nova_template_contexts'] ) ) {
				$template_callback = static function ( $object, $field_name, $request ) use ( $object_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
					return Nova_Bridge_Suite_Content_Context::get_generic_template_contexts( $object_type, $object, $request );
				};

				register_rest_field(
					$object_type,
					'nova_template_contexts',
					[
						'get_callback' => $template_callback,
						'schema'       => [
							'description' => __( 'Private NOVA template selection and usage context for this endpoint.', 'nova-bridge-suite' ),
							'type'        => 'object',
							'context'     => [ 'edit' ],
							'readonly'    => true,
							'properties'  => [
								'primary'  => [ 'type' => 'string' ],
								'current'  => [ 'type' => 'string' ],
								'selected' => [
									'type'  => 'array',
									'items' => [
										'type'       => 'object',
										'properties' => [
											'id'      => [ 'type' => 'string' ],
											'slug'    => [ 'type' => 'string' ],
											'label'   => [ 'type' => 'string' ],
											'context' => [ 'type' => 'string' ],
											'mapping' => [ 'type' => 'string' ],
											'primary' => [ 'type' => 'boolean' ],
											'current' => [ 'type' => 'boolean' ],
										],
									],
								],
							],
						],
					]
				);
			}
		}
	}

	/**
	 * Permission callback for the discovery endpoint.
	 *
	 * Authentication and administrator privileges are mandatory because the
	 * complete inventory includes REST-disabled types and arbitrary route schemas.
	 *
	 * @return bool|WP_Error
	 */
	public static function can_discover_resources() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'nova_content_context_unauthenticated',
				__( 'Authentication is required to inspect content endpoints.', 'nova-bridge-suite' ),
				[ 'status' => 401 ]
			);
		}

		// The response includes hidden post types and arbitrary third-party route
		// schemas whose permission callbacks cannot be evaluated safely without a
		// concrete request. Keep the complete inventory administrator-only.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'nova_content_context_forbidden',
			__( 'You are not allowed to inspect content endpoints.', 'nova-bridge-suite' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * REST callback wrapping the public discovery helper.
	 */
	public static function get_discovery_response() {
		return rest_ensure_response( self::discover_resources() );
	}

	/** Permission callback for inspecting one concrete builder document. */
	public static function can_inspect_bridge_fields( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'nova_content_context_unauthenticated', __( 'Authentication is required to inspect builder fields.', 'nova-bridge-suite' ), [ 'status' => 401 ] );
		}
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'nova_content_context_invalid_post', __( 'The source document does not exist.', 'nova-bridge-suite' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'nova_content_context_forbidden', __( 'You cannot inspect this source document.', 'nova-bridge-suite' ), [ 'status' => 403 ] );
		}

		$post_type = get_post_type_object( (string) $post->post_type );
		if ( ! $post_type instanceof WP_Post_Type || ! self::can_include_post_type( $post_type ) ) {
			return new WP_Error( 'nova_content_context_irrelevant_post_type', __( 'This document does not belong to a NOVA publishing endpoint.', 'nova-bridge-suite' ), [ 'status' => 400 ] );
		}

		return true;
	}

	/** Returns the actual text map emitted for one selected builder document. */
	public static function get_bridge_fields_response( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'nova_content_context_invalid_post', __( 'The source document does not exist.', 'nova-bridge-suite' ), [ 'status' => 404 ] );
		}

		$fields    = [];
		$providers = [];
		foreach ( self::detect_actual_builders( $post ) as $builder ) {
			$extracted = self::extract_bridge_fields( $builder, $post );
			if ( is_wp_error( $extracted ) ) {
				$providers[] = [ 'id' => $builder, 'label' => self::builder_label( $builder ), 'available' => false, 'reason' => $extracted->get_error_code(), 'message' => $extracted->get_error_message() ];
				continue;
			}
			$providers[] = [ 'id' => $builder, 'label' => self::builder_label( $builder ), 'available' => true, 'reason' => '', 'field_count' => count( $extracted ) ];
			$fields = array_merge( $fields, $extracted );
		}

		return rest_ensure_response( [
			'post_id'   => (int) $post->ID,
			'post_type' => (string) $post->post_type,
			'title'     => (string) $post->post_title,
			'template'  => function_exists( 'get_page_template_slug' ) ? (string) get_page_template_slug( $post->ID ) : '',
			'providers' => $providers,
			'fields'    => $fields,
		] );
	}

	/**
	 * Merges configured descriptions into a normal post type REST response.
	 *
	 * @param mixed $response REST response.
	 * @param mixed $post     Prepared post.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public static function filter_post_type_response( $response, $post, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $post instanceof WP_Post ) {
			return $response;
		}
		if ( ! self::request_uses_edit_context( $request ) ) {
			return $response;
		}
		if ( self::is_suite_owned_context_post_type( (string) $post->post_type ) ) {
			return $response;
		}

		// Another plugin may own this field with a scalar or list schema. In that
		// case, leave both its schema and every contextual response value untouched.
		if ( ! self::post_type_allows_meta_descriptions_merge( (string) $post->post_type ) ) {
			return $response;
		}

		$status = (int) $response->get_status();
		if ( $status < 200 || $status >= 300 || ! self::can_expose_post_context( (string) $post->post_type, (int) $post->ID ) ) {
			return $response;
		}

		$descriptions = self::get_saved_descriptions( 'post_type:' . (string) $post->post_type, (int) $post->ID );
		if ( empty( $descriptions ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$data = self::add_meta_descriptions_to_record( $data, $descriptions );
		$response->set_data( $data );

		return $response;
	}

	/** Adds private product-category guidance to native and Woo REST responses. */
	public static function filter_product_category_response( $response, $term, $request ) {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		if ( ! self::request_uses_edit_context( $request ) ) {
			return $response;
		}

		$status = (int) $response->get_status();
		if ( $status < 200 || $status >= 300 ) {
			return $response;
		}

		$term_id = 0;
		if ( $term instanceof WP_Term ) {
			$term_id = (int) $term->term_id;
		} elseif ( is_object( $term ) && isset( $term->term_id ) ) {
			$term_id = absint( $term->term_id );
		} elseif ( is_array( $term ) && isset( $term['id'] ) ) {
			$term_id = absint( $term['id'] );
		}
		if ( 0 === $term_id && $request instanceof WP_REST_Request ) {
			$term_id = absint( $request->get_param( 'id' ) );
		}

		if ( ! self::can_expose_taxonomy_context( 'product_cat', $term_id ) ) {
			return $response;
		}

		$descriptions = self::get_saved_descriptions( 'taxonomy:product_cat' );
		$mappings     = self::get_saved_mappings( 'taxonomy:product_cat' );
		if ( empty( $descriptions ) && empty( $mappings ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		if ( ! empty( $descriptions ) && self::post_type_allows_meta_descriptions_merge( 'product_cat' ) ) {
			$data = self::add_meta_descriptions_to_record( $data, $descriptions );
		}
		if ( ! empty( $mappings ) && ( ! isset( $data['nova_content_mappings'] ) || is_array( $data['nova_content_mappings'] ) ) ) {
			$data['nova_content_mappings'] = array_merge( isset( $data['nova_content_mappings'] ) ? $data['nova_content_mappings'] : [], $mappings );
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Whether a post type's registered field can safely accept our string map.
	 *
	 * Missing registrations are safe because NOVA registers its own object field.
	 * Existing registrations without an explicit object schema are treated as
	 * incompatible rather than guessing from a response that may omit the field.
	 */
	private static function post_type_allows_meta_descriptions_merge( string $post_type ): bool {
		global $wp_rest_additional_fields;

		if ( ! isset( $wp_rest_additional_fields[ $post_type ]['meta_descriptions'] ) ) {
			return true;
		}

		$registration = $wp_rest_additional_fields[ $post_type ]['meta_descriptions'];
		$schema       = is_array( $registration ) && isset( $registration['schema'] ) && is_array( $registration['schema'] )
			? $registration['schema']
			: [];

		if ( 'object' !== ( $schema['type'] ?? '' ) ) {
			return false;
		}

		if ( isset( $schema['properties'] ) ) {
			if ( ! is_array( $schema['properties'] ) ) {
				return false;
			}
			foreach ( $schema['properties'] as $property_schema ) {
				if ( ! is_array( $property_schema ) || 'string' !== ( $property_schema['type'] ?? '' ) ) {
					return false;
				}
			}
		}

		if ( ! array_key_exists( 'additionalProperties', $schema ) || true === $schema['additionalProperties'] ) {
			return true;
		}

		return is_array( $schema['additionalProperties'] )
			&& 'string' === ( $schema['additionalProperties']['type'] ?? '' );
	}

	/**
	 * Generic additional-field callback. It never returns guidance anonymously.
	 *
	 * @param string $post_type Registered object type.
	 * @param mixed  $object    Prepared REST object array.
	 * @param mixed  $request   REST request.
	 * @return array<string,string>
	 */
	public static function get_generic_meta_descriptions( string $post_type, $object, $request ): array {
		if ( ! self::request_uses_edit_context( $request ) ) {
			return [];
		}
		if ( self::is_suite_owned_context_post_type( $post_type ) ) {
			return [];
		}

		$post_id = is_array( $object ) && isset( $object['id'] ) ? absint( $object['id'] ) : 0;
		if ( ! self::can_expose_post_context( $post_type, $post_id ) ) {
			return [];
		}

		return self::get_saved_descriptions( 'post_type:' . $post_type, $post_id );
	}

	/** Generic taxonomy additional-field callback. */
	public static function get_taxonomy_meta_descriptions( string $taxonomy, $object, $request ): array {
		if ( ! self::request_uses_edit_context( $request ) ) {
			return [];
		}
		$term_id = is_array( $object ) && isset( $object['id'] ) ? absint( $object['id'] ) : 0;
		if ( ! self::can_expose_taxonomy_context( $taxonomy, $term_id ) ) {
			return [];
		}

		return self::get_saved_descriptions( 'taxonomy:' . $taxonomy );
	}

	/**
	 * Returns configured pointer-to-description pairs for a resource.
	 *
	 * @return array<string,string>
	 */
	private static function get_saved_descriptions( string $resource_id, int $post_id = 0 ): array {
		$config = self::get_option();
		if (
			empty( $config['resources'][ $resource_id ]['enabled'] )
			|| ! isset( $config['resources'][ $resource_id ] )
			|| ! is_array( $config['resources'][ $resource_id ] )
		) {
			return [];
		}

		$descriptions = [];
		foreach ( (array) ( $config['resources'][ $resource_id ]['fields'] ?? [] ) as $pointer => $field ) {
			if ( ! self::saved_pointer_applies_to_post( (string) $pointer, $post_id ) ) {
				continue;
			}
			if ( is_array( $field ) && isset( $field['description'] ) && is_string( $field['description'] ) && '' !== $field['description'] ) {
				$descriptions[ (string) $pointer ] = $field['description'];
			}
		}

		$active_template = self::active_template_configuration( $config['resources'][ $resource_id ], $post_id );
		if ( is_array( $active_template ) ) {
			foreach ( (array) ( $active_template['fields'] ?? [] ) as $pointer => $field ) {
				if ( ! self::saved_pointer_applies_to_post( (string) $pointer, $post_id ) ) {
					continue;
				}
				if ( is_array( $field ) && isset( $field['description'] ) && is_string( $field['description'] ) && '' !== $field['description'] ) {
					$descriptions[ (string) $pointer ] = $field['description'];
				}
			}
		}

		return $descriptions;
	}

	/** Returns configured pointer-to-NOVA-field mappings for a resource. */
	private static function get_saved_mappings( string $resource_id, int $post_id = 0 ): array {
		$config = self::get_option();
		if (
			empty( $config['resources'][ $resource_id ]['enabled'] )
			|| ! isset( $config['resources'][ $resource_id ] )
			|| ! is_array( $config['resources'][ $resource_id ] )
		) {
			return [];
		}

		$mappings = [];
		foreach ( (array) ( $config['resources'][ $resource_id ]['fields'] ?? [] ) as $pointer => $field ) {
			if ( ! self::saved_pointer_applies_to_post( (string) $pointer, $post_id ) ) {
				continue;
			}
			if ( is_array( $field ) && isset( $field['mapping'] ) && is_string( $field['mapping'] ) && '' !== $field['mapping'] ) {
				$mappings[ (string) $pointer ] = $field['mapping'];
			}
		}

		$active_template = self::active_template_configuration( $config['resources'][ $resource_id ], $post_id );
		if ( is_array( $active_template ) ) {
			foreach ( (array) ( $active_template['fields'] ?? [] ) as $pointer => $field ) {
				if ( ! self::saved_pointer_applies_to_post( (string) $pointer, $post_id ) ) {
					continue;
				}
				if ( is_array( $field ) && isset( $field['mapping'] ) && is_string( $field['mapping'] ) && '' !== $field['mapping'] ) {
					$mappings[ (string) $pointer ] = $field['mapping'];
				}
			}
		}

		return $mappings;
	}

	/** Returns the selected template item matching a concrete post, if any. */
	private static function active_template_configuration( array $resource, int $post_id ) {
		if ( $post_id < 1 || empty( $resource['templates'] ) || ! is_array( $resource['templates'] ) ) {
			return null;
		}

		$templates = $resource['templates'];
		$selected  = isset( $templates['selected'] ) && is_array( $templates['selected'] ) ? $templates['selected'] : [];
		$items     = isset( $templates['items'] ) && is_array( $templates['items'] ) ? $templates['items'] : [];
		$slug      = function_exists( 'get_page_template_slug' ) ? get_page_template_slug( $post_id ) : '';
		$slug      = is_string( $slug ) ? $slug : '';

		foreach ( $selected as $template_id ) {
			if ( ! isset( $items[ $template_id ] ) || ! is_array( $items[ $template_id ] ) ) {
				continue;
			}
			if ( $slug === (string) ( $items[ $template_id ]['slug'] ?? '' ) ) {
				$item       = $items[ $template_id ];
				$item['id'] = (string) $template_id;

				return $item;
			}
		}

		return null;
	}

	/** Additional-field callback for private NOVA source mappings. */
	public static function get_generic_content_mappings( string $type, string $object_type, $object, $request ): array {
		if ( ! self::request_uses_edit_context( $request ) ) {
			return [];
		}
		if ( 'taxonomy' === $type ) {
			$term_id = is_array( $object ) && isset( $object['id'] ) ? absint( $object['id'] ) : 0;
			if ( ! self::can_expose_taxonomy_context( $object_type, $term_id ) ) {
				return [];
			}

			return self::get_saved_mappings( 'taxonomy:' . $object_type );
		}
		if ( self::is_suite_owned_context_post_type( $object_type ) ) {
			return [];
		}

		$post_id = is_array( $object ) && isset( $object['id'] ) ? absint( $object['id'] ) : 0;
		if ( ! self::can_expose_post_context( $object_type, $post_id ) ) {
			return [];
		}

		return self::get_saved_mappings( 'post_type:' . $object_type, $post_id );
	}

	/** Additional-field callback for private endpoint template configuration. */
	public static function get_generic_template_contexts( string $post_type, $object, $request ): array {
		if ( ! self::request_uses_edit_context( $request ) ) {
			return [];
		}
		if ( self::is_suite_owned_context_post_type( $post_type ) ) {
			return [];
		}

		$post_id = is_array( $object ) && isset( $object['id'] ) ? absint( $object['id'] ) : 0;
		if ( ! self::can_expose_post_context( $post_type, $post_id ) ) {
			return [];
		}

		$config      = self::get_option();
		$resource_id = 'post_type:' . sanitize_key( $post_type );
		if (
			empty( $config['resources'][ $resource_id ]['enabled'] )
			|| empty( $config['resources'][ $resource_id ]['templates'] )
			|| ! is_array( $config['resources'][ $resource_id ]['templates'] )
		) {
			return [];
		}

		$templates   = $config['resources'][ $resource_id ]['templates'];
		$selected    = isset( $templates['selected'] ) && is_array( $templates['selected'] ) ? $templates['selected'] : [];
		$items       = isset( $templates['items'] ) && is_array( $templates['items'] ) ? $templates['items'] : [];
		$primary     = isset( $templates['primary'] ) && is_string( $templates['primary'] ) ? $templates['primary'] : '';
		$labels      = self::template_labels_for_post_type( $post_type );
		$current_slug = '';
		if ( $post_id > 0 && function_exists( 'get_page_template_slug' ) ) {
			$resolved_slug = get_page_template_slug( $post_id );
			$current_slug  = is_string( $resolved_slug ) ? $resolved_slug : '';
		}
		$current_id = '';
		foreach ( $items as $template_id => $item ) {
			if ( is_array( $item ) && $current_slug === (string) ( $item['slug'] ?? '' ) ) {
				$current_id = (string) $template_id;
				break;
			}
		}
		if ( '' === $current_id && $post_id > 0 ) {
			$current_id = self::template_id_from_slug( $current_slug );
		}

		$records = [];
		foreach ( $selected as $template_id ) {
			if ( ! isset( $items[ $template_id ] ) || ! is_array( $items[ $template_id ] ) ) {
				continue;
			}
			$item     = $items[ $template_id ];
			$slug     = (string) ( $item['slug'] ?? '' );
			$label_id = self::template_id_from_slug( $slug );
			$records[] = [
				'id'      => (string) $template_id,
				'slug'    => $slug,
				'label'   => isset( $labels[ $label_id ] ) ? (string) $labels[ $label_id ] : ( 'default' === $template_id ? __( 'Default template', 'nova-bridge-suite' ) : (string) $template_id ),
				'context' => isset( $item['description'] ) ? (string) $item['description'] : '',
				'mapping' => isset( $item['mapping'] ) ? (string) $item['mapping'] : '',
				'primary' => '' !== $primary && $primary === (string) $template_id,
				'current' => '' !== $current_id && $current_id === (string) $template_id,
			];
		}

		return [
			'primary'  => $primary,
			'current'  => $current_id,
			'selected' => $records,
		];
	}

	/** Returns live theme labels indexed by canonical template ID. */
	private static function template_labels_for_post_type( string $post_type ): array {
		$labels = [ 'default' => __( 'Default template', 'nova-bridge-suite' ) ];
		try {
			$theme     = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
			$templates = is_object( $theme ) && method_exists( $theme, 'get_page_templates' )
				? $theme->get_page_templates( null, $post_type )
				: [];
		} catch ( Throwable $exception ) {
			$templates = [];
		}

		foreach ( (array) $templates as $slug => $label ) {
			$slug = self::sanitize_template_slug( $slug );
			if ( null !== $slug && is_scalar( $label ) ) {
				$labels[ self::template_id_from_slug( $slug ) ] = (string) $label;
			}
		}

		return $labels;
	}

	/** Keeps concrete bridge selectors scoped to the document they came from. */
	private static function saved_pointer_applies_to_post( string $pointer, int $post_id ): bool {
		$segments = self::decode_json_pointer( $pointer );
		if (
			! is_array( $segments )
			|| count( $segments ) < 4
			|| '@builders' !== (string) $segments[0]
			|| 'documents' !== (string) $segments[2]
		) {
			return true;
		}

		return $post_id > 0 && (string) $post_id === (string) $segments[3];
	}

	/** Whether a REST request explicitly asks for private edit-context fields. */
	private static function request_uses_edit_context( $request ): bool {
		return $request instanceof WP_REST_Request && 'edit' === (string) $request->get_param( 'context' );
	}

	/**
	 * Merges explicit context after module-provided descriptions.
	 *
	 * Canonical pointers are retained. Simple pointers also replace an existing
	 * legacy Blog/Service key so consumers never receive contradictory guidance.
	 *
	 * @param array<string,mixed>  $existing Existing response descriptions.
	 * @param array<string,string> $custom   Explicit administrator descriptions.
	 * @return array<string,mixed>
	 */
	private static function merge_description_maps( array $existing, array $custom ): array {
		$merged = $existing;

		foreach ( $custom as $pointer => $description ) {
			$legacy_key = self::pointer_to_legacy_key( (string) $pointer );
			if ( null !== $legacy_key && array_key_exists( $legacy_key, $merged ) ) {
				$merged[ $legacy_key ] = $description;
			}

			$merged[ (string) $pointer ] = $description;
		}

		return $merged;
	}

	/** Maps simple canonical paths to current Blog/Service response keys. */
	private static function pointer_to_legacy_key( string $pointer ) {
		$segments = self::decode_json_pointer( $pointer );
		if ( null === $segments ) {
			return null;
		}

		if ( 1 === count( $segments ) && in_array( $segments[0], [ 'title', 'content', 'excerpt' ], true ) ) {
			return $segments[0];
		}

		if ( 2 === count( $segments ) && 'meta' === $segments[0] && '' !== $segments[1] ) {
			return $segments[1];
		}

		return null;
	}

	/** Decodes a previously validated JSON Pointer into segments. */
	private static function decode_json_pointer( string $pointer ) {
		if ( ! self::is_valid_json_pointer( $pointer ) ) {
			return null;
		}

		$segments = explode( '/', substr( $pointer, 1 ) );
		foreach ( $segments as &$segment ) {
			$segment = str_replace( [ '~1', '~0' ], [ '/', '~' ], $segment );
		}
		unset( $segment );

		return $segments;
	}

	/** Whether private context may be exposed for a post or post type. */
	private static function can_expose_post_context( string $post_type, int $post_id = 0 ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post instanceof WP_Post && $post_type === (string) $post->post_type ) {
				return current_user_can( 'edit_post', $post_id );
			}
		}

		$object = get_post_type_object( $post_type );

		return $object instanceof WP_Post_Type && self::can_edit_post_type( $object );
	}

	/** Whether private context may be exposed for a term or taxonomy collection. */
	private static function can_expose_taxonomy_context( string $taxonomy, int $term_id = 0 ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( $term_id > 0 && get_term( $term_id, $taxonomy ) instanceof WP_Term ) {
			return current_user_can( 'edit_term', $term_id );
		}

		$object = get_taxonomy( $taxonomy );
		if ( ! $object instanceof WP_Taxonomy ) {
			return false;
		}

		$capability = isset( $object->cap->edit_terms ) && is_string( $object->cap->edit_terms )
			? $object->cap->edit_terms
			: ( isset( $object->cap->manage_terms ) ? (string) $object->cap->manage_terms : 'manage_categories' );

		return current_user_can( $capability );
	}

	/**
	 * Discovers the small set of logical publishing endpoints NOVA can target.
	 *
	 * This method only reads the already-initialized REST server. It never invokes
	 * rest_api_init and therefore cannot recursively bootstrap REST during admin.
	 *
	 * @return array<string,mixed>
	 */
	public static function discover_resources(): array {
		$empty = [
			'version'      => self::SCHEMA_VERSION,
			'generated_at' => gmdate( 'c' ),
			'resources'    => [],
		];

		if ( self::$discovering ) {
			return $empty;
		}

		self::$discovering = true;

		try {
			$saved      = self::get_option();
			$routes     = self::get_registered_routes();
			$resources  = [];
			$post_types = get_post_types( [], 'objects' );

			if ( is_array( $post_types ) ) {
				foreach ( $post_types as $post_type ) {
					if ( ! $post_type instanceof WP_Post_Type || ! self::can_include_post_type( $post_type ) ) {
						continue;
					}

					$resource = self::build_post_type_resource( $post_type, $routes, $saved );
					$resources[ $resource['id'] ] = $resource;
				}
			}

			$product_categories = self::build_product_category_resource( $routes, $saved );
			if ( is_array( $product_categories ) ) {
				$resources[ $product_categories['id'] ] = $product_categories;
			}

			ksort( $resources, SORT_NATURAL | SORT_FLAG_CASE );

			return [
				'version'      => self::SCHEMA_VERSION,
				'generated_at' => gmdate( 'c' ),
				'resources'    => array_values( $resources ),
			];
		} finally {
			self::$discovering = false;
		}
	}

	/**
	 * Reads routes only when a REST server is already available.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function get_registered_routes(): array {
		global $wp_rest_server;

		if ( ! $wp_rest_server instanceof WP_REST_Server ) {
			return [];
		}

		$routes = $wp_rest_server->get_routes();

		return is_array( $routes ) ? $routes : [];
	}

	/** Whether a post type has its own dedicated NOVA module context contract. */
	private static function is_suite_owned_context_post_type( string $post_type ): bool {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return false;
		}

		if ( 'service_page' === $post_type && class_exists( 'SEORAI\\ServicePageCPT\\Plugin', false ) ) {
			return true;
		}

		if ( ! class_exists( 'SEORAI\\BodycleanCPT\\Plugin', false ) ) {
			return false;
		}

		$managed = [];
		if ( function_exists( 'nova_bridge_suite_get_managed_blog_post_types' ) ) {
			try {
				$managed = array_merge( $managed, (array) nova_bridge_suite_get_managed_blog_post_types() );
			} catch ( Throwable $exception ) {
				// Continue with REST bases for legacy options whose type and slug differ.
			}
		}
		if ( function_exists( 'nova_bridge_suite_get_managed_blog_rest_bases' ) ) {
			try {
				$managed = array_merge( $managed, (array) nova_bridge_suite_get_managed_blog_rest_bases() );
			} catch ( Throwable $exception ) {
				// The registered module class still prevents false ownership claims.
			}
		}

		return in_array( $post_type, array_map( 'sanitize_key', $managed ), true );
	}

	/** Whether a REST route belongs to a CPT module with its own context contract. */
	private static function is_suite_owned_context_route( string $route ): bool {
		$route          = '/' . trim( $route, '/' );
		$core_rest_base = '';
		if ( preg_match( '#^/wp/v[0-9]+/([^/]+)(?:/|$)#i', $route, $matches ) ) {
			$core_rest_base = sanitize_title_with_dashes( rawurldecode( (string) $matches[1] ) );
		}

		if ( class_exists( 'SEORAI\\ServicePageCPT\\Plugin', false ) ) {
			if ( 1 === preg_match( '#^/service-pages/v[0-9]+(?:/|$)#i', $route ) ) {
				return true;
			}

			if ( '' !== $core_rest_base && function_exists( 'nova_bridge_suite_get_service_page_rest_base' ) ) {
				try {
					$service_rest_base = sanitize_title_with_dashes( nova_bridge_suite_get_service_page_rest_base() );
				} catch ( Throwable $exception ) {
					$service_rest_base = '';
				}
				if ( '' !== $service_rest_base && $service_rest_base === $core_rest_base ) {
					return true;
				}
			}
		}

		if ( ! class_exists( 'SEORAI\\BodycleanCPT\\Plugin', false ) ) {
			return false;
		}
		if ( 1 === preg_match( '#^/nova-blog/v[0-9]+(?:/|$)#i', $route ) ) {
			return true;
		}
		if ( '' === $core_rest_base || ! function_exists( 'nova_bridge_suite_get_managed_blog_rest_bases' ) ) {
			return false;
		}

		try {
			$blog_rest_bases = nova_bridge_suite_get_managed_blog_rest_bases();
		} catch ( Throwable $exception ) {
			return false;
		}

		return in_array( $core_rest_base, array_map( 'sanitize_title_with_dashes', (array) $blog_rest_bases ), true );
	}

	/** Whether a post type is a client-facing editorial publishing model. */
	private static function can_include_post_type( WP_Post_Type $post_type ): bool {
		$name = (string) $post_type->name;

		if ( in_array( $name, [ 'post', 'page' ], true ) ) {
			return true;
		}
		if ( ! empty( $post_type->_builtin ) ) {
			return false;
		}

		$deny = in_array(
			$name,
			[ 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_webhook', 'shop_subscription' ],
			true
		) || 1 === preg_match( '/^(?:acf|acfe|elementor|woocommerce|wc)[_-]/i', $name )
			|| 0 === strpos( $name, 'e-' )
			|| 1 === preg_match( '/(?:^|[_-])(?:nav|navigation|menus?|orders?|payments?|countr(?:y|ies)|shipping|tax|logs?|transactions?|issues?|tickets?|fonts?|icons?|snippets?)$/i', $name );

		$acf         = self::post_type_has_applicable_acf_group( $name );
		$builder     = self::post_type_has_enabled_builder( $name );
		$suite_owned = self::is_suite_owned_context_post_type( $name );
		$signals = [
			'public'             => ! empty( $post_type->public ),
			'publicly_queryable' => ! empty( $post_type->publicly_queryable ),
			'show_in_rest'       => ! empty( $post_type->show_in_rest ),
			'show_ui'            => ! empty( $post_type->show_ui ),
			'title'              => post_type_supports( $name, 'title' ),
			'editor'             => post_type_supports( $name, 'editor' ),
			'excerpt'            => post_type_supports( $name, 'excerpt' ),
			'custom_fields'      => post_type_supports( $name, 'custom-fields' ),
			'acf'                => $acf,
			'builder'            => $builder,
			'suite_owned'        => $suite_owned,
			'denied_family'      => $deny,
		];
		if ( $suite_owned ) {
			return false;
		}

		$include = ! $deny
			&& $signals['show_ui']
			&& ( $signals['editor'] || $signals['excerpt'] || $signals['custom_fields'] || $acf || $builder );

		/**
		 * Lets a site explicitly include or exclude an unusual editorial CPT.
		 *
		 * @param bool         $include   Calculated inclusion decision.
		 * @param WP_Post_Type $post_type Registered post type.
		 * @param array        $signals   Detection facts used by the decision.
		 */
		return (bool) apply_filters( 'nova_bridge_suite_content_context_include_post_type', $include, $post_type, $signals );
	}

	/** Whether an ACF field group can apply to the post type. */
	private static function post_type_has_applicable_acf_group( string $post_type ): bool {
		if ( ! function_exists( 'acf_get_field_groups' ) ) {
			return false;
		}

		try {
			$groups = acf_get_field_groups();
		} catch ( Throwable $exception ) {
			return false;
		}

		foreach ( (array) $groups as $group ) {
			if ( is_array( $group ) && 'none' !== self::acf_group_applicability( $group, $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/** Whether an installed builder is configured for the post type. */
	private static function post_type_has_enabled_builder( string $post_type ): bool {
		if ( post_type_supports( $post_type, 'elementor' ) ) {
			return true;
		}

		try {
			if ( function_exists( 'vc_editor_post_types' ) && in_array( $post_type, (array) vc_editor_post_types(), true ) ) {
				return true;
			}
			if ( function_exists( 'et_builder_enabled_for_post_type' ) && et_builder_enabled_for_post_type( $post_type ) ) {
				return true;
			}
			if ( class_exists( 'FLBuilderModel' ) && method_exists( 'FLBuilderModel', 'is_post_type_enabled' ) && FLBuilderModel::is_post_type_enabled( $post_type ) ) {
				return true;
			}
		} catch ( Throwable $exception ) {
			return false;
		}

		return false;
	}

	/** Whether the current user has the post type's edit-posts capability. */
	private static function can_edit_post_type( WP_Post_Type $post_type ): bool {
		$capability = isset( $post_type->cap->edit_posts ) && is_string( $post_type->cap->edit_posts )
			? $post_type->cap->edit_posts
			: 'edit_posts';

		return current_user_can( $capability );
	}

	/**
	 * Classifies an already-approved publishing post type.
	 *
	 * Site integrations can override the result with the documented filter. The
	 * two stable keys are `content_kind` and `scope`.
	 *
	 * @return array{content_kind:string,scope:string}
	 */
	private static function classify_post_type_resource( WP_Post_Type $post_type ): array {
		$result = [
			'content_kind' => 'editorial',
			'scope'        => 'content_model',
		];

		/**
		 * Filters content-model relevance classification for a registered CPT.
		 *
		 * @param array        $result    Classification with content_kind/scope.
		 * @param WP_Post_Type $post_type Registered post type object.
		 */
		$filtered = apply_filters( 'nova_bridge_suite_content_context_post_type_classification', $result, $post_type );
		if ( is_array( $filtered ) ) {
			$filtered_kind  = isset( $filtered['content_kind'] ) ? sanitize_key( (string) $filtered['content_kind'] ) : '';
			$filtered_scope = isset( $filtered['scope'] ) ? sanitize_key( (string) $filtered['scope'] ) : '';
			if ( in_array( $filtered_kind, [ 'editorial', 'commerce', 'media', 'application_data' ], true ) ) {
				$result['content_kind'] = $filtered_kind;
			}
			if ( in_array( $filtered_scope, [ 'content_model', 'auxiliary_model', 'advanced_model' ], true ) ) {
				$result['scope'] = $filtered_scope;
			}
		}

		return $result;
	}

	/**
	 * Builds one post type discovery resource.
	 *
	 * @param WP_Post_Type $post_type Post type object.
	 * @param array        $routes    Registered REST routes.
	 * @param array        $saved     Canonical saved configuration.
	 * @return array<string,mixed>
	 */
	private static function build_post_type_resource( WP_Post_Type $post_type, array $routes, array $saved ): array {
		$name          = (string) $post_type->name;
		$resource_id   = 'post_type:' . $name;
		$rest_base     = ! empty( $post_type->rest_base ) ? trim( (string) $post_type->rest_base, '/' ) : $name;
		$rest_namespace = ! empty( $post_type->rest_namespace ) ? trim( (string) $post_type->rest_namespace, '/' ) : 'wp/v2';
		$route         = '/' . $rest_namespace . '/' . $rest_base;
		$matched       = [];
		$write         = [
			'methods'     => [],
			'args'        => [],
			'controllers' => [],
		];

		if ( ! empty( $post_type->show_in_rest ) ) {
			foreach ( $routes as $registered_route => $handlers ) {
				if ( ! self::is_primary_post_type_write_route( (string) $registered_route, $route ) ) {
					continue;
				}

				$route_write = self::collect_write_handler_data( is_array( $handlers ) ? $handlers : [] );
				if ( empty( $route_write['methods'] ) ) {
					continue;
				}

				$matched[] = (string) $registered_route;
				$write     = self::merge_write_data( $write, $route_write );
			}
		}

		$current_user_can_edit = self::can_edit_post_type( $post_type );
		if ( ! $current_user_can_edit ) {
			$status  = 'unavailable';
			$reason  = 'missing_edit_capability';
			$message = __( 'The current administrator does not have this post type\'s edit capability.', 'nova-bridge-suite' );
		} elseif ( empty( $post_type->show_in_rest ) ) {
			$status  = 'unavailable';
			$reason  = 'show_in_rest_disabled';
			$message = __( 'This post type is not usable through the REST API because show_in_rest is disabled.', 'nova-bridge-suite' );
		} elseif ( empty( $matched ) ) {
			$status  = 'unavailable';
			$reason  = 'no_writable_route';
			$message = __( 'This REST-enabled post type has no registered POST, PUT, or PATCH route.', 'nova-bridge-suite' );
		} else {
			$status  = 'available';
			$reason  = '';
			$message = __( 'This post type has a verified writable REST route.', 'nova-bridge-suite' );
		}

		$usable         = 'available' === $status;
		$classification = self::classify_post_type_resource( $post_type );
		$saved_selection = self::resource_saved_enabled( $saved, $resource_id );
		$selected       = null === $saved_selection ? 'editorial' === $classification['content_kind'] : $saved_selection;
		$selection_source = null === $saved_selection ? 'automatic' : 'saved';

		$fields = self::discover_native_post_fields( $post_type, $write['args'], $usable, $reason, $route );

		$meta_data = self::discover_registered_meta_fields( $post_type, $write['args'], $usable, $reason );
		$fields    = self::merge_field_inventories( $fields, $meta_data['fields'] );

		$acf_data = self::discover_acf_fields( $post_type, $write['args'], $usable, $reason );
		$fields   = self::merge_field_inventories( $fields, $acf_data['fields'] );

		$template_data = self::discover_template_contexts( $post_type, $write['args'], $usable, $reason, self::resource_saved_templates( $saved, $resource_id ) );
		$seo_data      = self::discover_seo_fields( $post_type, $routes, $write['args'], $usable, $reason );
		$fields        = self::merge_field_inventories( $fields, $seo_data['fields'] );

		$builder_data = self::discover_builder_contexts( $post_type, $usable, $reason, false );
		$fields       = self::attach_saved_field_state( $fields, self::resource_saved_fields( $saved, $resource_id ) );

		$supports = get_all_post_type_supports( $name );
		$supports = is_array( $supports ) ? array_keys( $supports ) : [];
		sort( $supports, SORT_NATURAL | SORT_FLAG_CASE );
		sort( $matched, SORT_NATURAL | SORT_FLAG_CASE );

		$labels = isset( $post_type->labels ) && is_object( $post_type->labels ) ? $post_type->labels : null;

		$registered_base_route = ! empty( $post_type->show_in_rest ) ? $route : '';

		return [
			'id'                 => $resource_id,
			'type'               => 'post_type',
			'post_type'          => $name,
			'scope'              => $classification['scope'],
			'content_kind'       => $classification['content_kind'],
			'selected'           => (bool) $selected,
			'enabled'            => (bool) $selected,
			'selection_source'   => $selection_source,
			'label'              => $labels && isset( $labels->singular_name ) ? (string) $labels->singular_name : $name,
			'label_plural'       => $labels && isset( $labels->name ) ? (string) $labels->name : $name,
			'route'              => $registered_base_route,
			'expected_route'     => $route,
			'write_routes'       => $matched,
			'methods'            => array_values( $write['methods'] ),
			'write_methods'      => array_values( $write['methods'] ),
			'show_in_rest'       => (bool) $post_type->show_in_rest,
			'public'             => (bool) $post_type->public,
			'show_ui'            => (bool) $post_type->show_ui,
			'edit_capability'    => isset( $post_type->cap->edit_posts ) ? (string) $post_type->cap->edit_posts : 'edit_posts',
			'current_user_can_edit' => $current_user_can_edit,
			'writable'           => $usable,
			'usable'             => $usable,
			'status'             => $status,
			'reason'             => $reason,
			'message'            => $message,
			'supports'           => $supports,
			'schema_quality'     => self::summarize_schema_quality( $fields ),
			'manual_allowed'     => true,
			'capabilities'       => [
				'templates' => $template_data['summary'],
				'acf'       => $acf_data['summary'],
				'meta'      => $meta_data['summary'],
				'seo'       => $seo_data['summary'],
				'builders'  => $builder_data['summary'],
			],
			'transports'         => [
				[
					'id'         => 'wordpress',
					'label'      => __( 'WordPress REST API', 'nova-bridge-suite' ),
					'route'      => $registered_base_route,
					'item_route' => $registered_base_route ? $registered_base_route . '/{id}' : '',
					'methods'    => array_values( $write['methods'] ),
					'available'  => $usable,
				],
			],
			'bridge_examples'    => self::discover_bridge_examples( $post_type ),
			'bridge_fields_url'  => rest_url( self::REST_NAMESPACE . self::REST_BRIDGE_FIELDS_ROUTE ),
			'templates'          => $template_data['templates'],
			'fields'             => $fields,
			'saved_descriptions' => self::resource_saved_descriptions( $saved, $resource_id ),
		];
	}

	/** Builds the one logical WooCommerce Product Categories publishing resource. */
	private static function build_product_category_resource( array $routes, array $saved ) {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return null;
		}

		$taxonomy = get_taxonomy( 'product_cat' );
		if ( ! $taxonomy instanceof WP_Taxonomy ) {
			return null;
		}

		$resource_id = 'taxonomy:product_cat';
		$can_edit    = self::can_expose_taxonomy_context( 'product_cat' );
		$native_base = '/' . ( ! empty( $taxonomy->rest_namespace ) ? trim( (string) $taxonomy->rest_namespace, '/' ) : 'wp/v2' )
			. '/' . ( ! empty( $taxonomy->rest_base ) ? trim( (string) $taxonomy->rest_base, '/' ) : 'product_cat' );
		$native_write = self::collect_resource_write_data( $routes, $native_base );

		$wc_base  = '';
		$wc_write = [ 'methods' => [], 'args' => [], 'controllers' => [] ];
		foreach ( [ '/wc/v3/products/categories', '/wc/v2/products/categories', '/wc/v1/products/categories' ] as $candidate ) {
			$candidate_write = self::collect_resource_write_data( $routes, $candidate );
			if ( ! empty( $candidate_write['methods'] ) ) {
				$wc_base  = $candidate;
				$wc_write = $candidate_write;
				break;
			}
		}

		$usable = $can_edit && ( ! empty( $native_write['methods'] ) || ! empty( $wc_write['methods'] ) );
		if ( ! $can_edit ) {
			$reason  = 'missing_edit_capability';
			$message = __( 'The current administrator cannot edit WooCommerce product categories.', 'nova-bridge-suite' );
		} elseif ( ! $usable ) {
			$reason  = 'no_writable_route';
			$message = __( 'WooCommerce product categories have no writable REST route.', 'nova-bridge-suite' );
		} else {
			$reason  = '';
			$message = __( 'Product-category fields are combined from WooCommerce and native WordPress REST transports.', 'nova-bridge-suite' );
		}

		$fields = [];
		$definitions = [
			'name'        => [ 'Name', 'string', 'taxonomy', 'term_name', 'core' ],
			'description' => [ 'Description', 'string', 'taxonomy', 'term_description', 'core' ],
			'slug'        => [ 'Slug', 'string', 'taxonomy', 'slug', 'core' ],
			'parent'      => [ 'Parent category', 'integer', 'taxonomy', 'parent', 'media_taxonomy' ],
		];
		foreach ( $definitions as $key => $definition ) {
			$native = $can_edit && isset( $native_write['args'][ $key ] );
			$wc     = $can_edit && isset( $wc_write['args'][ $key ] );
			if ( ! $native && ! $wc ) {
				continue;
			}
			$transports = [];
			if ( $native ) {
				$transports[] = self::field_transport( 'wordpress', $native_base . '/{id}', '/' . $key, $native_write['methods'] );
			}
			if ( $wc ) {
				$transports[] = self::field_transport( 'woocommerce', $wc_base . '/{id}', '/' . $key, $wc_write['methods'] );
			}
			$pointer = '/' . $key;
			$fields[ $pointer ] = self::make_capability_field( $pointer, $definition[0], $definition[1], 'string' === $definition[1] ? 'textual' : 'structural', [
				'source'       => $definition[2],
				'group'        => $definition[4],
				'origin'       => 'rest_schema',
				'availability' => 'available',
				'writable'     => true,
				'transport'    => $wc ? 'woocommerce' : 'wordpress',
				'provider'     => $wc ? 'woocommerce' : 'wordpress',
				'role'         => $definition[3],
				'route'        => $wc ? $wc_base . '/{id}' : $native_base . '/{id}',
				'request_path' => $pointer,
				'methods'      => $wc ? $wc_write['methods'] : $native_write['methods'],
				'transports'   => $transports,
			] );
		}

		if ( isset( $wc_write['args']['display'] ) ) {
			$fields['/display'] = self::make_capability_field( '/display', 'Display type', 'string', 'structural', [
				'source' => 'taxonomy', 'group' => 'core', 'origin' => 'rest_schema', 'availability' => 'available', 'writable' => true,
				'transport' => 'woocommerce', 'provider' => 'woocommerce', 'role' => 'display',
				'route' => $wc_base . '/{id}', 'request_path' => '/display', 'methods' => $wc_write['methods'],
				'transports' => [ self::field_transport( 'woocommerce', $wc_base . '/{id}', '/display', $wc_write['methods'] ) ],
				'choices' => [ 'default', 'products', 'subcategories', 'both' ],
			] );
		}

		if ( isset( $wc_write['args']['image'] ) ) {
			$image_schema = is_array( $wc_write['args']['image'] ) ? $wc_write['args']['image'] : [];
			$image_properties = isset( $image_schema['properties'] ) && is_array( $image_schema['properties'] ) ? $image_schema['properties'] : [];
			$labels = [
				'id' => 'Image media ID', 'src' => 'Image URL', 'name' => 'Image name',
				'title' => 'Image title', 'alt' => 'Image alt text', 'caption' => 'Image caption',
			];
			foreach ( $image_properties as $key => $property_schema ) {
				$key = (string) $key;
				$property_schema = is_array( $property_schema ) ? $property_schema : [];
				if ( ! isset( $labels[ $key ] ) || ! empty( $property_schema['readonly'] ) ) {
					continue;
				}
				$types = self::schema_types( $property_schema['type'] ?? null );
				$type  = ! empty( $types ) ? implode( '|', $types ) : ( 'id' === $key ? 'integer' : 'string' );
				$pointer = '/image/' . $key;
				$fields[ $pointer ] = self::make_capability_field( $pointer, $labels[ $key ], $type, 'id' === $key ? 'structural' : 'textual', [
					'source' => 'media', 'group' => 'media_taxonomy', 'origin' => 'rest_schema', 'availability' => 'available', 'writable' => true,
					'transport' => 'woocommerce', 'provider' => 'woocommerce', 'role' => 'id' === $key ? 'featured_image' : 'image_' . $key,
					'route' => $wc_base . '/{id}', 'request_path' => $pointer, 'methods' => $wc_write['methods'],
					'transports' => [ self::field_transport( 'woocommerce', $wc_base . '/{id}', $pointer, $wc_write['methods'] ) ],
					'native_description' => isset( $property_schema['description'] ) && is_scalar( $property_schema['description'] ) ? (string) $property_schema['description'] : '',
				] );
			}
			if ( empty( $image_properties ) ) {
				$fields['/image'] = self::make_capability_field( '/image', 'Category image', 'object', 'structural', [
					'source' => 'media', 'group' => 'media_taxonomy', 'origin' => 'rest_schema', 'availability' => 'available', 'writable' => true,
					'transport' => 'woocommerce', 'provider' => 'woocommerce', 'role' => 'featured_image',
					'route' => $wc_base . '/{id}', 'request_path' => '/image', 'methods' => $wc_write['methods'],
					'transports' => [ self::field_transport( 'woocommerce', $wc_base . '/{id}', '/image', $wc_write['methods'] ) ],
					'native_description' => isset( $image_schema['description'] ) && is_scalar( $image_schema['description'] ) ? (string) $image_schema['description'] : '',
				] );
			}
		}

		if ( isset( $native_write['args']['content_below_products'] ) ) {
			$fields['/content_below_products'] = self::make_capability_field( '/content_below_products', 'Content below products', 'string', 'textual', [
				'source' => 'core', 'origin' => 'nova_extension', 'availability' => 'available', 'writable' => true,
				'transport' => 'wordpress', 'provider' => 'nova', 'role' => 'content_body',
				'route' => $native_base . '/{id}', 'request_path' => '/content_below_products', 'methods' => $native_write['methods'],
				'transports' => [ self::field_transport( 'wordpress', $native_base . '/{id}', '/content_below_products', $native_write['methods'] ) ],
			] );
		}

		$meta_data = self::discover_registered_term_meta_fields( 'product_cat', $native_write['args'], $can_edit, $reason, $native_base );
		$fields    = self::merge_field_inventories( $fields, $meta_data['fields'] );
		// NOVA exposes this value as a first-class category field. Do not also
		// advertise the registered-meta alias as a second, competing target.
		if ( isset( $fields['/content_below_products'] ) ) {
			unset( $fields['/meta/content_below_products'], $fields['/meta_all/content_below_products'] );
		}
		$acf_data  = self::discover_acf_term_fields( 'product_cat', $native_write['args'], $can_edit, $reason, $native_base );
		$fields    = self::merge_field_inventories( $fields, $acf_data['fields'] );
		$seo_data  = self::discover_term_seo_fields( $native_write['args'], $can_edit, $native_base, $native_write['methods'] );
		$fields    = self::merge_field_inventories( $fields, $seo_data['fields'] );

		$fields = self::attach_saved_field_state( $fields, self::resource_saved_fields( $saved, $resource_id ) );
		$saved_selection = self::resource_saved_enabled( $saved, $resource_id );
		$selected = null === $saved_selection ? true : $saved_selection;
		$labels   = isset( $taxonomy->labels ) && is_object( $taxonomy->labels ) ? $taxonomy->labels : null;

		$transports = [];
		if ( ! empty( $wc_write['methods'] ) ) {
			$transports[] = [ 'id' => 'woocommerce', 'label' => 'WooCommerce REST API', 'route' => $wc_base, 'item_route' => $wc_base . '/{id}', 'methods' => $wc_write['methods'], 'available' => $can_edit ];
		}
		if ( ! empty( $native_write['methods'] ) ) {
			$transports[] = [ 'id' => 'wordpress', 'label' => 'WordPress REST API', 'route' => $native_base, 'item_route' => $native_base . '/{id}', 'methods' => $native_write['methods'], 'available' => $can_edit ];
		}

		return [
			'id' => $resource_id, 'type' => 'taxonomy', 'taxonomy' => 'product_cat', 'scope' => 'content_model',
			'content_kind' => 'taxonomy', 'selected' => (bool) $selected, 'enabled' => (bool) $selected,
			'selection_source' => null === $saved_selection ? 'automatic' : 'saved',
			'label' => $labels && isset( $labels->singular_name ) ? (string) $labels->singular_name : 'Product category',
			'label_plural' => $labels && isset( $labels->name ) ? (string) $labels->name : 'Product categories',
			'route' => $wc_base ?: $native_base, 'expected_route' => $wc_base ?: $native_base,
			'write_routes' => array_values( array_filter( [ $wc_base, $native_base ] ) ),
			'methods' => array_values( array_unique( array_merge( $wc_write['methods'], $native_write['methods'] ) ) ),
			'write_methods' => array_values( array_unique( array_merge( $wc_write['methods'], $native_write['methods'] ) ) ),
			'show_in_rest' => ! empty( $taxonomy->show_in_rest ), 'writable' => $usable, 'usable' => $usable,
			'status' => $usable ? 'available' : 'unavailable', 'reason' => $reason, 'message' => $message,
			'schema_quality' => self::summarize_schema_quality( $fields ), 'manual_allowed' => true,
			'transports' => $transports,
			'capabilities' => [ 'acf' => $acf_data['summary'], 'meta' => $meta_data['summary'], 'seo' => $seo_data['summary'], 'builders' => [] ],
			'fields' => $fields, 'saved_descriptions' => self::resource_saved_descriptions( $saved, $resource_id ),
		];
	}

	/** Collects collection and direct-item writers for one exact REST base. */
	private static function collect_resource_write_data( array $routes, string $base ): array {
		$write = [ 'methods' => [], 'args' => [], 'controllers' => [] ];
		foreach ( $routes as $registered_route => $handlers ) {
			if ( ! self::is_primary_post_type_write_route( (string) $registered_route, $base ) ) {
				continue;
			}
			$data = self::collect_write_handler_data( is_array( $handlers ) ? $handlers : [] );
			if ( ! empty( $data['methods'] ) ) {
				$write = self::merge_write_data( $write, $data );
			}
		}

		return $write;
	}

	/** Compact write-transport descriptor attached to a field. */
	private static function field_transport( string $id, string $route, string $request_path, array $methods ): array {
		return [ 'id' => $id, 'route' => $route, 'request_path' => $request_path, 'methods' => array_values( $methods ), 'writable' => true ];
	}

	/** Discovers meaningful registered term-meta leaves. */
	private static function discover_registered_term_meta_fields( string $taxonomy, array $write_args, bool $usable, string $resource_reason, string $route ): array {
		$result = [ 'fields' => [], 'summary' => [ 'registered' => 0, 'available' => 0, 'potential' => 0 ] ];
		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return $result;
		}

		$registrations = get_registered_meta_keys( 'term', $taxonomy );
		$verified      = self::build_field_inventory( $write_args, false );
		$meta_bridge   = isset( $write_args['meta_all'] );
		foreach ( (array) $registrations as $meta_key => $registration ) {
			if ( ! is_string( $meta_key ) || ! is_array( $registration ) || ! self::is_relevant_registered_meta( $meta_key, $registration ) ) {
				continue;
			}

			++$result['summary']['registered'];
			$native_pointer = self::segments_to_pointer( [ 'meta', $meta_key ] );
			$native         = $usable && ! empty( $registration['show_in_rest'] ) && isset( $verified[ $native_pointer ] );
			$bridge         = $usable && ! $native && $meta_bridge;
			$pointer        = $native ? $native_pointer : self::segments_to_pointer( [ 'meta_all', $meta_key ] );
			$schema         = is_array( $registration['show_in_rest'] ?? null ) && is_array( $registration['show_in_rest']['schema'] ?? null )
				? $registration['show_in_rest']['schema']
				: [];
			$type           = isset( $schema['type'] ) ? (string) $schema['type'] : ( isset( $registration['type'] ) ? (string) $registration['type'] : 'string' );
			$available      = $native || $bridge;
			$reason         = $available ? '' : ( $usable ? 'term_meta_not_exposed' : $resource_reason );
			$result['fields'][ $pointer ] = self::make_capability_field( $pointer, $meta_key, $type, 'string' === $type ? 'textual' : 'structural', [
				'source' => 'meta', 'origin' => 'registered_meta', 'availability' => $available ? 'available' : 'potential',
				'availability_reason' => $reason, 'writable' => $available, 'could_be_enabled' => ! $available,
				'transport' => $native ? 'wordpress' : 'nova_meta_bridge', 'provider' => $native ? 'wordpress' : 'nova',
				'role' => 'custom_field', 'route' => $route . '/{id}', 'request_path' => $pointer,
				'methods' => [ 'POST', 'PUT', 'PATCH' ], 'write_status' => $native ? 'writable' : ( $bridge ? 'bridge_writable' : 'not_exposed' ),
				'native_description' => isset( $registration['description'] ) ? (string) $registration['description'] : '',
			] );
			++$result['summary'][ $available ? 'available' : 'potential' ];
		}

		return $result;
	}

	/** Discovers ACF value fields attached to product categories. */
	private static function discover_acf_term_fields( string $taxonomy, array $write_args, bool $usable, string $resource_reason, string $route ): array {
		$result = [ 'fields' => [], 'summary' => [ 'active' => false, 'groups' => 0, 'fields' => 0, 'available' => 0, 'potential' => 0 ] ];
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $result;
		}

		$result['summary']['active'] = true;
		$verified   = self::build_field_inventory( $write_args, false );
		$meta_bridge = isset( $write_args['meta_all'] );
		try {
			$groups = acf_get_field_groups();
		} catch ( Throwable $exception ) {
			return $result;
		}

		foreach ( (array) $groups as $group ) {
			if ( ! is_array( $group ) || ! self::acf_group_applies_to_taxonomy( $group, $taxonomy ) ) {
				continue;
			}
			try {
				$acf_fields = acf_get_fields( $group );
			} catch ( Throwable $exception ) {
				$acf_fields = [];
			}
			if ( empty( $acf_fields ) ) {
				continue;
			}
			++$result['summary']['groups'];
			foreach ( (array) $acf_fields as $acf_field ) {
				self::add_acf_field_capabilities( $result['fields'], is_array( $acf_field ) ? $acf_field : [], [ 'acf' ], $verified, $usable, $resource_reason, ! empty( $group['show_in_rest'] ), 'exact', isset( $group['title'] ) ? (string) $group['title'] : '' );
			}
		}

		if ( $usable && $meta_bridge ) {
			foreach ( $result['fields'] as &$field ) {
				if ( 'available' === ( $field['availability'] ?? '' ) ) {
					continue;
				}
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';
				if ( '' === $name || '*' === $name || 'acf_fc_layout' === $name ) {
					continue;
				}
				$segments = self::decode_json_pointer( (string) ( $field['path'] ?? '' ) );
				if ( ! is_array( $segments ) || count( $segments ) < 2 || 'acf' !== (string) $segments[0] ) {
					continue;
				}
				if ( 2 !== count( $segments ) ) {
					$field['availability']         = 'potential';
					$field['availability_reason']  = 'acf_nested_payload_required';
					$field['reason']               = 'acf_nested_payload_required';
					$field['writable']             = false;
					$field['could_be_enabled']     = true;
					$field['transport']            = 'nova_meta_bridge';
					$field['provider']             = 'acf';
					$field['route']                = $route . '/{id}';
					$field['request_path']         = isset( $segments[1] ) ? self::segments_to_pointer( [ 'meta_all', 'acf', (string) $segments[1] ] ) : '';
					$field['methods']              = [ 'POST', 'PUT', 'PATCH' ];
					$field['write_status']         = 'whole_parent_payload_required';
					continue;
				}
				$field['availability']        = 'available';
				$field['availability_reason'] = '';
				$field['reason']              = '';
				$field['writable']            = true;
				$field['could_be_enabled']    = false;
				$field['transport']           = 'nova_meta_bridge';
				$field['provider']            = 'nova';
				$field['route']               = $route . '/{id}';
				$field['request_path']        = '/meta_all/' . $name;
				$field['methods']             = [ 'POST', 'PUT', 'PATCH' ];
				$field['write_status']        = 'bridge_writable';
			}
			unset( $field );
		}

		$result['summary']['fields'] = count( $result['fields'] );
		foreach ( $result['fields'] as $field ) {
			++$result['summary'][ 'available' === ( $field['availability'] ?? '' ) ? 'available' : 'potential' ];
		}

		return $result;
	}

	/** Evaluates common ACF taxonomy location rules. */
	private static function acf_group_applies_to_taxonomy( array $group, string $taxonomy ): bool {
		foreach ( (array) ( $group['location'] ?? [] ) as $branch ) {
			$matches = true;
			$seen    = false;
			foreach ( (array) $branch as $rule ) {
				if ( ! is_array( $rule ) || 'taxonomy' !== (string) ( $rule['param'] ?? '' ) ) {
					continue;
				}
				$seen     = true;
				$value    = (string) ( $rule['value'] ?? '' );
				$equal    = $taxonomy === $value || 0 === strpos( $value, $taxonomy . ':' );
				$matches  = $matches && ( '!=' === (string) ( $rule['operator'] ?? '==' ) ? ! $equal : $equal );
			}
			if ( $seen && $matches ) {
				return true;
			}
		}

		return false;
	}

	/** Discovers the active SEO provider's product-category title/description. */
	private static function discover_term_seo_fields( array $write_args, bool $usable, string $route, array $methods ): array {
		$result = [ 'fields' => [], 'summary' => [ 'active' => false, 'provider' => '', 'writable' => 0 ] ];
		$provider = '';
		$keys     = [];
		if ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_activation' ) ) {
			$provider = 'seopress';
			$keys = [ 'title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc' ];
		} elseif ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			$provider = 'yoast';
			$keys = [ 'title' => 'wpseo_title', 'description' => 'wpseo_desc' ];
		} elseif ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$provider = 'rank_math';
			$keys = [ 'title' => 'rank_math_title', 'description' => 'rank_math_description' ];
		}
		if ( '' === $provider ) {
			return $result;
		}

		$bridge = $usable && isset( $write_args['meta_all'] );
		$result['summary']['active']   = true;
		$result['summary']['provider'] = $provider;
		foreach ( $keys as $key => $meta_key ) {
			$pointer = self::segments_to_pointer( [ '@seo', $provider, $key ] );
			$result['fields'][ $pointer ] = self::make_capability_field( $pointer, 'title' === $key ? 'SEO title' : 'Meta description', 'string', 'textual', [
				'source' => 'seo', 'origin' => 'seo_provider', 'availability' => $bridge ? 'available' : 'potential',
				'availability_reason' => $bridge ? '' : 'taxonomy_seo_not_writable', 'writable' => $bridge,
				'could_be_enabled' => ! $bridge, 'transport' => 'nova_meta_bridge', 'provider' => $provider,
				'role' => 'title' === $key ? 'seo_title' : 'seo_description', 'route' => $route . '/{id}',
				'request_path' => '/meta_all/' . $meta_key, 'methods' => $methods,
				'write_status' => $bridge ? 'bridge_writable' : 'not_exposed',
			] );
			$result['summary']['writable'] += $bridge ? 1 : 0;
		}

		return $result;
	}

	/** Whether a route is the collection or direct item writer for a post type. */
	private static function is_primary_post_type_write_route( string $registered_route, string $base ): bool {
		$registered_route = rtrim( $registered_route, '/' );
		$base             = rtrim( $base, '/' );
		if ( $registered_route === $base ) {
			return true;
		}

		$tail = substr( $registered_route, strlen( $base ) );

		return 0 === strpos( $tail, '/(?P<id>' ) && 1 === substr_count( $tail, '/' );
	}

	/** Tests whether a registered pattern belongs to a REST base. */
	private static function route_has_prefix( string $registered_route, string $base ): bool {
		$registered_route = rtrim( $registered_route, '/' );
		$base             = rtrim( $base, '/' );

		return $registered_route === $base || 0 === strpos( $registered_route, $base . '/' );
	}

	/** Tests whether a route is already represented by a post type resource. */
	private static function route_belongs_to_post_type( string $route, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( self::route_has_prefix( $route, (string) $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds a configured or configurable arbitrary route resource.
	 *
	 * @param string $route Route regex as registered with WordPress.
	 * @param array  $write Normalized write-handler data.
	 * @param array  $saved Canonical saved configuration.
	 * @param bool   $content_likely Whether the route could be classified automatically.
	 * @return array<string,mixed>
	 */
	private static function build_route_resource( string $route, array $write, array $saved, bool $content_likely ): array {
		$resource_id = 'route:' . substr( hash( 'sha256', $route ), 0, 24 );
		$fields      = self::build_field_inventory( isset( $write['args'] ) && is_array( $write['args'] ) ? $write['args'] : [], true );
		$fields      = self::annotate_field_inventory(
			$fields,
			[
				'availability'        => 'available',
				'availability_reason' => '',
				'writable'            => true,
				'could_be_enabled'    => false,
				'origin'               => 'rest_schema',
				'transport'            => 'custom_rest',
			]
		);
		$descriptions = self::resource_saved_descriptions( $saved, $resource_id );
		$fields       = self::attach_saved_field_state( $fields, self::resource_saved_fields( $saved, $resource_id ) );
		$quality      = self::summarize_schema_quality( $fields );

		if ( empty( $fields ) ) {
			$quality = 'opaque';
		}

		$label = trim( preg_replace( '#[\\()\[\]?+*^$|\\\\]+#', ' ', $route ) );
		$label = '' !== $label ? $label : $route;

		return [
			'id'                 => $resource_id,
			'type'               => 'route',
			'scope'              => 'advanced_route',
			'content_kind'       => $content_likely ? 'content_route' : 'application_data',
			'selected'           => true === self::resource_saved_enabled( $saved, $resource_id ),
			'enabled'            => true === self::resource_saved_enabled( $saved, $resource_id ),
			'selection_source'   => null === self::resource_saved_enabled( $saved, $resource_id ) ? 'automatic' : 'saved',
			'label'              => $label,
			'route'              => $route,
			'methods'            => array_values( isset( $write['methods'] ) ? $write['methods'] : [] ),
			'write_methods'      => array_values( isset( $write['methods'] ) ? $write['methods'] : [] ),
			'writable'           => true,
			'usable'             => true,
			'status'             => 'available',
			'reason'             => '',
			'message'            => $content_likely
				? __( 'This is a registered content-writing REST route.', 'nova-bridge-suite' )
				: __( 'This writable route could not be classified automatically. Review it and add manual field paths if it accepts content.', 'nova-bridge-suite' ),
			'content_classification' => $content_likely ? 'content' : 'review',
			'review_required'    => ! $content_likely,
			'schema_quality'     => $quality,
			'manual_allowed'     => true,
			'fields'             => $fields,
			'saved_descriptions' => $descriptions,
		];
	}

	/**
	 * Collects POST/PUT/PATCH methods, arguments, and controller schemas.
	 *
	 * @param array<int,array<string,mixed>> $handlers Route handlers.
	 * @return array{methods:string[],args:array<string,array<string,mixed>>,controllers:array<int,object>}
	 */
	private static function collect_write_handler_data( array $handlers ): array {
		$data = [
			'methods'     => [],
			'args'        => [],
			'controllers' => [],
		];

		foreach ( $handlers as $handler ) {
			if ( ! is_array( $handler ) ) {
				continue;
			}

			$methods = self::normalize_handler_methods( isset( $handler['methods'] ) ? $handler['methods'] : [] );
			$methods = array_values( array_intersect( $methods, [ 'POST', 'PUT', 'PATCH' ] ) );
			if ( empty( $methods ) ) {
				continue;
			}

			$data['methods'] = array_values( array_unique( array_merge( $data['methods'], $methods ) ) );
			if ( isset( $handler['args'] ) && is_array( $handler['args'] ) ) {
				$data['args'] = self::merge_argument_maps( $data['args'], $handler['args'] );
			}

			$callback   = isset( $handler['callback'] ) ? $handler['callback'] : null;
			$controller = is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ? $callback[0] : null;
			if ( ! is_object( $controller ) ) {
				continue;
			}

			$data['controllers'][ spl_object_hash( $controller ) ] = $controller;

			$schema_methods = $methods;
			if ( ! empty( array_intersect( $methods, [ 'PUT', 'PATCH' ] ) ) ) {
				$editable_method = class_exists( 'WP_REST_Server' ) ? WP_REST_Server::EDITABLE : 'POST, PUT, PATCH';
				$schema_methods[] = $editable_method;
			}
			$schema_methods = array_values( array_unique( $schema_methods ) );

			foreach ( $schema_methods as $method ) {
				if ( ! method_exists( $controller, 'get_endpoint_args_for_item_schema' ) ) {
					continue;
				}

				try {
					$controller_args = $controller->get_endpoint_args_for_item_schema( $method );
					if ( is_array( $controller_args ) ) {
						$data['args'] = self::merge_argument_maps( $data['args'], $controller_args );
					}
				} catch ( Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Third-party controllers occasionally expose an incompatible method.
				}
			}

			if ( method_exists( $controller, 'get_item_schema' ) ) {
				try {
					$item_schema = $controller->get_item_schema();
					if ( is_array( $item_schema ) && isset( $item_schema['properties'] ) && is_array( $item_schema['properties'] ) ) {
						foreach ( $data['args'] as $argument => $argument_schema ) {
							if ( isset( $item_schema['properties'][ $argument ] ) && is_array( $item_schema['properties'][ $argument ] ) ) {
								$data['args'][ $argument ] = self::merge_schema( $item_schema['properties'][ $argument ], is_array( $argument_schema ) ? $argument_schema : [] );
							}
						}
					}
				} catch ( Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Discovery must remain available when a controller schema fails.
				}
			}
		}

		sort( $data['methods'] );
		$data['controllers'] = array_values( $data['controllers'] );

		return $data;
	}

	/** Normalizes WordPress's supported method representations. */
	private static function normalize_handler_methods( $raw_methods ): array {
		if ( is_string( $raw_methods ) ) {
			$raw_methods = preg_split( '/[\s,|]+/', $raw_methods );
		}

		if ( ! is_array( $raw_methods ) ) {
			return [];
		}

		$methods = [];
		foreach ( $raw_methods as $key => $value ) {
			if ( is_string( $key ) && ! is_numeric( $key ) ) {
				if ( $value ) {
					$methods[] = strtoupper( $key );
				}
				continue;
			}

			if ( is_string( $value ) ) {
				foreach ( preg_split( '/[\s,|]+/', $value ) as $method ) {
					if ( '' !== $method ) {
						$methods[] = strtoupper( $method );
					}
				}
			}
		}

		return array_values( array_unique( $methods ) );
	}

	/** Merges normalized write data from multiple routes. */
	private static function merge_write_data( array $left, array $right ): array {
		$left['methods'] = array_values( array_unique( array_merge( $left['methods'], $right['methods'] ) ) );
		sort( $left['methods'] );
		$left['args'] = self::merge_argument_maps( $left['args'], $right['args'] );

		$controllers = [];
		foreach ( array_merge( $left['controllers'], $right['controllers'] ) as $controller ) {
			if ( is_object( $controller ) ) {
				$controllers[ spl_object_hash( $controller ) ] = $controller;
			}
		}
		$left['controllers'] = array_values( $controllers );

		return $left;
	}

	/** Merges root argument schemas without copying executable callbacks to output. */
	private static function merge_argument_maps( array $left, array $right ): array {
		foreach ( $right as $name => $schema ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}

			$schema = is_array( $schema ) ? $schema : [];
			$left[ $name ] = isset( $left[ $name ] ) && is_array( $left[ $name ] )
				? self::merge_schema( $left[ $name ], $schema )
				: $schema;
		}

		return $left;
	}

	/** Recursively merges schema arrays, favoring the later endpoint definition. */
	private static function merge_schema( array $left, array $right ): array {
		foreach ( $right as $key => $value ) {
			if ( is_array( $value ) && isset( $left[ $key ] ) && is_array( $left[ $key ] ) ) {
				if ( 'properties' === $key ) {
					$left[ $key ] = self::merge_argument_maps( $left[ $key ], $value );
				} elseif ( self::is_associative_array( $value ) ) {
					$left[ $key ] = self::merge_schema( $left[ $key ], $value );
				} else {
					$left[ $key ] = array_values( array_merge( $left[ $key ], $value ) );
				}
			} else {
				$left[ $key ] = $value;
			}
		}

		return $left;
	}

	/** PHP 7.4-compatible associative-array check. */
	private static function is_associative_array( array $value ): bool {
		$index = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $index ) {
				return true;
			}
			++$index;
		}

		return false;
	}

	/**
	 * Adds registered post meta and supported core fields to handler arguments.
	 *
	 * @param WP_Post_Type $post_type   Post type object.
	 * @param array        $args        Existing write arguments.
	 * @param object[]     $controllers Controllers found on matching routes.
	 * @return array<string,array<string,mixed>>
	 */
	private static function augment_post_type_args( WP_Post_Type $post_type, array $args, array $controllers ): array {
		foreach ( $controllers as $controller ) {
			if ( ! is_object( $controller ) || ! method_exists( $controller, 'get_item_schema' ) ) {
				continue;
			}

			try {
				$schema = $controller->get_item_schema();
				if ( is_array( $schema ) && isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
					foreach ( $args as $name => $argument_schema ) {
						if ( isset( $schema['properties'][ $name ] ) && is_array( $schema['properties'][ $name ] ) ) {
							$args[ $name ] = self::merge_schema( $schema['properties'][ $name ], is_array( $argument_schema ) ? $argument_schema : [] );
						}
					}
				}
			} catch ( Throwable $exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// A third-party schema failure must not break inventory discovery.
			}
		}

		$core_fallbacks = self::supported_post_field_schemas( $post_type );
		foreach ( $core_fallbacks as $name => $schema ) {
			$args[ $name ] = isset( $args[ $name ] ) && is_array( $args[ $name ] )
				? self::merge_schema( $args[ $name ], $schema )
				: $schema;
		}

		$meta_properties = [];
		if ( function_exists( 'get_registered_meta_keys' ) ) {
			$registered_meta = get_registered_meta_keys( 'post', (string) $post_type->name );
			if ( is_array( $registered_meta ) ) {
				foreach ( $registered_meta as $meta_key => $registration ) {
					if ( ! is_array( $registration ) || empty( $registration['show_in_rest'] ) ) {
						continue;
					}

					$schema = [];
					if ( is_array( $registration['show_in_rest'] ) && isset( $registration['show_in_rest']['schema'] ) && is_array( $registration['show_in_rest']['schema'] ) ) {
						$schema = $registration['show_in_rest']['schema'];
					}

					if ( empty( $schema['type'] ) && ! empty( $registration['type'] ) ) {
						$schema['type'] = (string) $registration['type'];
					}
					if ( empty( $schema['description'] ) && ! empty( $registration['description'] ) ) {
						$schema['description'] = (string) $registration['description'];
					}

					$meta_properties[ (string) $meta_key ] = $schema;
				}
			}
		}

		if ( ! empty( $meta_properties ) ) {
			$meta_schema = [
				'type'       => 'object',
				'properties' => $meta_properties,
			];
			$args['meta'] = isset( $args['meta'] ) && is_array( $args['meta'] ) ? self::merge_schema( $args['meta'], $meta_schema ) : $meta_schema;
		}

		return $args;
	}

	/** Returns minimal schemas implied by post type supports. */
	private static function supported_post_field_schemas( WP_Post_Type $post_type ): array {
		$name    = (string) $post_type->name;
		$schemas = [];

		if ( post_type_supports( $name, 'title' ) ) {
			$schemas['title'] = [
				'type'        => [ 'string', 'object' ],
				'description' => __( 'The title for the post.', 'nova-bridge-suite' ),
			];
		}
		if ( post_type_supports( $name, 'editor' ) ) {
			$schemas['content'] = [
				'type'        => [ 'string', 'object' ],
				'description' => __( 'The main content for the post.', 'nova-bridge-suite' ),
			];
		}
		if ( post_type_supports( $name, 'excerpt' ) ) {
			$schemas['excerpt'] = [
				'type'        => [ 'string', 'object' ],
				'description' => __( 'The excerpt for the post.', 'nova-bridge-suite' ),
			];
		}

		if ( 'attachment' === $name ) {
			$schemas['caption']     = [ 'type' => [ 'string', 'object' ] ];
			$schemas['description'] = [ 'type' => [ 'string', 'object' ] ];
			$schemas['alt_text']    = [ 'type' => 'string' ];
		}

		return $schemas;
	}

	/** Builds fields directly from supports when no writable schema is registered. */
	private static function build_supported_post_field_inventory( WP_Post_Type $post_type ): array {
		return self::build_field_inventory( self::supported_post_field_schemas( $post_type ), false );
	}

	/**
	 * Builds the intentionally small native publishing field set for a post type.
	 *
	 * Response-only schema properties and administrative controls such as author,
	 * dates, passwords, comment state and sticky state are deliberately omitted.
	 */
	private static function discover_native_post_fields( WP_Post_Type $post_type, array $write_args, bool $usable, string $resource_reason, string $route ): array {
		$name       = (string) $post_type->name;
		$fields     = [];
		$definitions = [
			'title' => [
				'label' => __( 'Title', 'nova-bridge-suite' ),
				'type'  => 'string',
				'role'  => 'content_title',
				'needed' => post_type_supports( $name, 'title' ),
			],
			'content' => [
				'label' => __( 'Content', 'nova-bridge-suite' ),
				'type'  => 'string',
				'role'  => 'content_body',
				'needed' => post_type_supports( $name, 'editor' ),
			],
			'excerpt' => [
				'label' => __( 'Excerpt', 'nova-bridge-suite' ),
				'type'  => 'string',
				'role'  => 'content_excerpt',
				'needed' => post_type_supports( $name, 'excerpt' ),
			],
			'slug' => [
				'label' => __( 'Slug', 'nova-bridge-suite' ),
				'type'  => 'string',
				'role'  => 'slug',
				'needed' => true,
			],
			'featured_media' => [
				'label' => __( 'Featured image', 'nova-bridge-suite' ),
				'type'  => 'integer',
				'role'  => 'featured_image',
				'needed' => post_type_supports( $name, 'thumbnail' ),
				'source' => 'media',
			],
			'template' => [
				'label' => __( 'Page template', 'nova-bridge-suite' ),
				'type'  => 'string',
				'role'  => 'template',
				'needed' => post_type_supports( $name, 'page-attributes' ) || isset( $write_args['template'] ),
			],
			'parent' => [
				'label' => __( 'Parent', 'nova-bridge-suite' ),
				'type'  => 'integer',
				'role'  => 'parent',
				'needed' => ! empty( $post_type->hierarchical ),
			],
		];

		foreach ( $definitions as $key => $definition ) {
			if ( empty( $definition['needed'] ) && ! isset( $write_args[ $key ] ) ) {
				continue;
			}

			$verified = $usable && isset( $write_args[ $key ] ) && empty( $write_args[ $key ]['readonly'] );
			$reason   = $verified ? '' : ( $usable ? 'not_in_write_schema' : $resource_reason );
			$pointer  = '/' . $key;
			$schema   = isset( $write_args[ $key ] ) && is_array( $write_args[ $key ] ) ? $write_args[ $key ] : [];
			$fields[ $pointer ] = self::make_capability_field(
				$pointer,
				(string) $definition['label'],
				(string) $definition['type'],
				in_array( $definition['type'], [ 'string' ], true ) ? 'textual' : 'structural',
				[
					'source'              => isset( $definition['source'] ) ? (string) $definition['source'] : 'core',
					'origin'              => 'rest_schema',
					'availability'        => $verified ? 'available' : 'potential',
					'availability_reason' => $reason,
					'writable'            => $verified,
					'could_be_enabled'    => ! $verified,
					'transport'           => 'wordpress',
					'provider'            => 'wordpress',
					'role'                => (string) $definition['role'],
					'route'               => $route ? $route . '/{id}' : '',
					'request_path'        => $pointer,
					'methods'             => [ 'POST', 'PUT', 'PATCH' ],
					'native_description'  => isset( $schema['description'] ) && is_scalar( $schema['description'] ) ? (string) $schema['description'] : '',
					'choices'             => isset( $schema['enum'] ) && is_array( $schema['enum'] ) ? $schema['enum'] : [],
				]
			);
		}

		$taxonomies = get_object_taxonomies( $name, 'objects' );
		foreach ( (array) $taxonomies as $taxonomy ) {
			if ( ! $taxonomy instanceof WP_Taxonomy || 'post_format' === $taxonomy->name ) {
				continue;
			}

			$rest_base = ! empty( $taxonomy->rest_base ) ? (string) $taxonomy->rest_base : (string) $taxonomy->name;
			if ( empty( $taxonomy->public ) && empty( $taxonomy->show_ui ) && empty( $taxonomy->show_in_rest ) ) {
				continue;
			}
			$in_write_schema = isset( $write_args[ $rest_base ] ) && empty( $write_args[ $rest_base ]['readonly'] );
			$assign_cap      = isset( $taxonomy->cap->assign_terms ) ? (string) $taxonomy->cap->assign_terms : '';
			$can_assign      = '' !== $assign_cap && current_user_can( $assign_cap );
			$verified        = $usable && ! empty( $taxonomy->show_in_rest ) && $in_write_schema && $can_assign;
			if ( ! $usable ) {
				$reason = $resource_reason;
			} elseif ( empty( $taxonomy->show_in_rest ) ) {
				$reason = 'taxonomy_show_in_rest_disabled';
			} elseif ( ! $in_write_schema ) {
				$reason = 'taxonomy_not_in_write_schema';
			} elseif ( ! $can_assign ) {
				$reason = 'taxonomy_assignment_forbidden';
			} else {
				$reason = '';
			}
			$pointer   = self::segments_to_pointer( [ $rest_base ] );
			$label     = isset( $taxonomy->labels->name ) ? (string) $taxonomy->labels->name : (string) $taxonomy->label;
			$fields[ $pointer ] = self::make_capability_field( $pointer, $label, 'array<integer>', 'structural', [
				'source'              => 'taxonomy',
				'origin'              => 'registered_taxonomy',
				'availability'        => $verified ? 'available' : 'potential',
				'availability_reason' => $reason,
				'writable'            => $verified,
				'could_be_enabled'    => ! $verified,
				'transport'           => 'wordpress',
				'provider'            => 'wordpress',
				'role'                => 'term_assignment',
				'route'               => $route ? $route . '/{id}' : '',
				'request_path'        => $pointer,
				'methods'             => [ 'POST', 'PUT', 'PATCH' ],
			] );
		}

		ksort( $fields, SORT_NATURAL | SORT_FLAG_CASE );

		return $fields;
	}

	/** Discovers registered post meta, including keys that could be REST-enabled. */
	private static function discover_registered_meta_fields( WP_Post_Type $post_type, array $verified_args, bool $usable, string $resource_reason ): array {
		$result = [
			'fields'  => [],
			'summary' => [
				'registered' => 0,
				'available'  => 0,
				'potential'  => 0,
			],
		];

		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return $result;
		}

		$registrations = get_registered_meta_keys( 'post', (string) $post_type->name );
		if ( ! is_array( $registrations ) ) {
			return $result;
		}

		$verified_fields = self::build_field_inventory( $verified_args, false );
		$has_custom      = post_type_supports( (string) $post_type->name, 'custom-fields' );
		$meta_bridge     = isset( $verified_args['meta_all'] );
		$rest_base       = ! empty( $post_type->rest_base ) ? trim( (string) $post_type->rest_base, '/' ) : (string) $post_type->name;
		$rest_namespace  = ! empty( $post_type->rest_namespace ) ? trim( (string) $post_type->rest_namespace, '/' ) : 'wp/v2';
		$item_route      = '/' . $rest_namespace . '/' . $rest_base . '/{id}';

		foreach ( $registrations as $meta_key => $registration ) {
			if ( ! is_string( $meta_key ) || '' === $meta_key || ! is_array( $registration ) ) {
				continue;
			}
			if ( ! self::is_relevant_registered_meta( $meta_key, $registration ) ) {
				continue;
			}

			$schema = [];
			if ( is_array( $registration['show_in_rest'] ?? null ) && is_array( $registration['show_in_rest']['schema'] ?? null ) ) {
				$schema = $registration['show_in_rest']['schema'];
			}
			if ( empty( $schema['type'] ) && ! empty( $registration['type'] ) ) {
				$schema['type'] = (string) $registration['type'];
			}
			if ( empty( $schema['description'] ) && ! empty( $registration['description'] ) ) {
				$schema['description'] = (string) $registration['description'];
			}

			$inventory = self::build_field_inventory(
				[
					'meta' => [
						'type'       => 'object',
						'properties' => [ $meta_key => $schema ],
					],
				],
				false
			);
			$show_in_rest = ! empty( $registration['show_in_rest'] );

			foreach ( $inventory as $path => &$field ) {
				$bridge_writable = false;
				if ( ! $usable ) {
					$availability = 'potential';
					$reason       = $resource_reason;
				} elseif ( ! $show_in_rest ) {
					$availability = 'potential';
					$reason       = 'meta_show_in_rest_disabled';
				} elseif ( ! $has_custom ) {
					$availability = 'potential';
					$reason       = 'post_type_lacks_custom_fields_support';
				} elseif ( ! isset( $verified_fields[ $path ] ) ) {
					$availability    = $meta_bridge ? 'available' : 'potential';
					$reason          = $meta_bridge ? '' : 'not_in_write_schema';
					$bridge_writable = $meta_bridge;
				} else {
					$availability = 'available';
					$reason       = '';
				}

				$field['source']              = 'meta';
				$field['group']               = 'meta';
				$field['origin']              = 'registered_meta';
				$field['availability']        = $availability;
				$field['availability_reason'] = $reason;
				$field['reason']              = $reason;
				if ( 'available' !== $availability && $usable && $meta_bridge ) {
					$availability    = 'available';
					$reason          = '';
					$bridge_writable = true;
					$field['availability']        = $availability;
					$field['availability_reason'] = $reason;
					$field['reason']              = $reason;
				}
				$segments = self::decode_json_pointer( (string) $path );
				if ( is_array( $segments ) && count( $segments ) > 2 ) {
					$field['availability']         = 'potential';
					$field['availability_reason']  = 'meta_nested_payload_required';
					$field['reason']               = 'meta_nested_payload_required';
					$field['writable']             = false;
					$field['could_be_enabled']     = true;
					$field['transport']            = $bridge_writable ? 'nova_meta_bridge' : 'wordpress';
					$field['provider']             = $bridge_writable ? 'nova' : 'wordpress';
					$field['route']                = $item_route;
					$field['request_path']         = $bridge_writable ? '/meta_all/' . $meta_key : self::segments_to_pointer( [ 'meta', $meta_key ] );
					$field['methods']              = [ 'POST', 'PUT', 'PATCH' ];
					$field['write_status']         = 'whole_parent_payload_required';
					$field['label']                = $meta_key . ' · ' . (string) end( $segments );
					continue;
				}
				$field['writable']            = 'available' === $availability;
				$field['could_be_enabled']    = 'available' !== $availability;
				$field['transport']           = $bridge_writable ? 'nova_meta_bridge' : 'wordpress';
				$field['provider']            = $bridge_writable ? 'nova' : 'wordpress';
				$field['route']               = $item_route;
				$field['request_path']        = $bridge_writable ? '/meta_all/' . $meta_key : $path;
				$field['methods']             = [ 'POST', 'PUT', 'PATCH' ];
				$field['write_status']        = $bridge_writable ? 'bridge_writable' : ( 'available' === $availability ? 'writable' : 'not_exposed' );
				$field['label']               = $meta_key;
			}
			unset( $field );

			$result['fields'] = self::merge_field_inventories( $result['fields'], $inventory );
		}

		$result['summary']['registered'] = count( $result['fields'] );
		foreach ( $result['fields'] as $field ) {
			$key = 'available' === ( $field['availability'] ?? '' ) ? 'available' : 'potential';
			++$result['summary'][ $key ];
		}

		return $result;
	}

	/** Keeps client-facing registered meta while excluding framework storage. */
	private static function is_relevant_registered_meta( string $meta_key, array $registration ): bool {
		if ( 1 === preg_match( '/^(?:_elementor_|_edit_|_wp_|_oembed_|_fl_builder_|_et_pb_|_fusion_|_breakdance_)/i', $meta_key ) ) {
			return false;
		}

		$description = isset( $registration['description'] ) && is_scalar( $registration['description'] )
			? trim( (string) $registration['description'] )
			: '';
		$public_name = 1 === preg_match( '/^(?:sp_|blog_|nova_|seo_|hero_|intro_|content_|faq_|cta_|subtitle|summary|caption|description)/i', ltrim( $meta_key, '_' ) );

		return '' !== $description || $public_name || 0 !== strpos( $meta_key, '_' );
	}

	/** Discovers ACF groups and text-capable fields that may apply to a CPT. */
	private static function discover_acf_fields( WP_Post_Type $post_type, array $verified_args, bool $usable, string $resource_reason ): array {
		$result = [
			'fields'  => [],
			'summary' => [
				'active'    => false,
				'groups'    => 0,
				'fields'    => 0,
				'available' => 0,
				'potential' => 0,
			],
		];

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $result;
		}

		$result['summary']['active'] = true;
		$verified_fields             = self::build_field_inventory( $verified_args, false );

		try {
			$groups = acf_get_field_groups();
		} catch ( Throwable $exception ) {
			return $result;
		}

		if ( ! is_array( $groups ) ) {
			return $result;
		}

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$applicability = self::acf_group_applicability( $group, (string) $post_type->name );
			if ( 'none' === $applicability ) {
				continue;
			}

			try {
				$acf_fields = acf_get_fields( $group );
			} catch ( Throwable $exception ) {
				$acf_fields = [];
			}
			if ( ! is_array( $acf_fields ) ) {
				continue;
			}

			++$result['summary']['groups'];
			$group_rest = ! empty( $group['show_in_rest'] );
			foreach ( $acf_fields as $acf_field ) {
				self::add_acf_field_capabilities(
					$result['fields'],
					is_array( $acf_field ) ? $acf_field : [],
					[ 'acf' ],
					$verified_fields,
					$usable,
					$resource_reason,
					$group_rest,
					$applicability,
					isset( $group['title'] ) ? (string) $group['title'] : ''
				);
			}
		}

		if ( $usable && isset( $verified_args['meta_all'] ) ) {
			$rest_base      = ! empty( $post_type->rest_base ) ? trim( (string) $post_type->rest_base, '/' ) : (string) $post_type->name;
			$rest_namespace = ! empty( $post_type->rest_namespace ) ? trim( (string) $post_type->rest_namespace, '/' ) : 'wp/v2';
			foreach ( $result['fields'] as &$field ) {
				if ( 'available' === ( $field['availability'] ?? '' ) ) {
					continue;
				}
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';
				if ( '' === $name || '*' === $name || 'acf_fc_layout' === $name ) {
					continue;
				}
				$segments = self::decode_json_pointer( (string) ( $field['path'] ?? '' ) );
				if ( ! is_array( $segments ) || count( $segments ) < 2 || 'acf' !== (string) $segments[0] ) {
					continue;
				}
				if ( 2 !== count( $segments ) ) {
					$field['availability']         = 'potential';
					$field['availability_reason']  = 'acf_nested_payload_required';
					$field['reason']               = 'acf_nested_payload_required';
					$field['writable']             = false;
					$field['could_be_enabled']     = true;
					$field['transport']            = 'nova_meta_bridge';
					$field['provider']             = 'acf';
					$field['route']                = '/' . $rest_namespace . '/' . $rest_base . '/{id}';
					$field['request_path']         = isset( $segments[1] ) ? self::segments_to_pointer( [ 'meta_all', 'acf', (string) $segments[1] ] ) : '';
					$field['methods']              = [ 'POST', 'PUT', 'PATCH' ];
					$field['write_status']         = 'whole_parent_payload_required';
					continue;
				}
				$field['availability']        = 'available';
				$field['availability_reason'] = '';
				$field['reason']              = '';
				$field['writable']            = true;
				$field['could_be_enabled']    = false;
				$field['transport']           = 'nova_meta_bridge';
				$field['provider']            = 'nova';
				$field['route']               = '/' . $rest_namespace . '/' . $rest_base . '/{id}';
				$field['request_path']        = '/meta_all/' . $name;
				$field['methods']             = [ 'POST', 'PUT', 'PATCH' ];
				$field['write_status']        = 'bridge_writable';
			}
			unset( $field );
		}

		$rest_base      = ! empty( $post_type->rest_base ) ? trim( (string) $post_type->rest_base, '/' ) : (string) $post_type->name;
		$rest_namespace = ! empty( $post_type->rest_namespace ) ? trim( (string) $post_type->rest_namespace, '/' ) : 'wp/v2';
		foreach ( $result['fields'] as &$field ) {
			if ( empty( $field['provider'] ) ) {
				$field['provider'] = 'acf';
			}
			if ( empty( $field['route'] ) ) {
				$field['route'] = '/' . $rest_namespace . '/' . $rest_base . '/{id}';
			}
			if ( empty( $field['request_path'] ) ) {
				$field['request_path'] = (string) ( $field['path'] ?? '' );
			}
			if ( empty( $field['methods'] ) && ! empty( $field['writable'] ) ) {
				$field['methods'] = [ 'POST', 'PUT', 'PATCH' ];
			}
			if ( empty( $field['role'] ) ) {
				$field['role'] = 'custom_field';
			}
		}
		unset( $field );

		$result['summary']['fields'] = count( $result['fields'] );
		foreach ( $result['fields'] as $field ) {
			$key = 'available' === ( $field['availability'] ?? '' ) ? 'available' : 'potential';
			++$result['summary'][ $key ];
		}

		return $result;
	}

	/** Evaluates whether an ACF location group can apply to this post type. */
	private static function acf_group_applicability( array $group, string $post_type ): string {
		$locations = isset( $group['location'] ) && is_array( $group['location'] ) ? $group['location'] : [];
		foreach ( $locations as $branch ) {
			if ( ! is_array( $branch ) ) {
				continue;
			}

			$matches        = true;
			$has_pt_rule    = false;
			$has_post_scope = false;
			$conditional    = false;
			foreach ( $branch as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}
				$param    = isset( $rule['param'] ) ? (string) $rule['param'] : '';
				$operator = isset( $rule['operator'] ) ? (string) $rule['operator'] : '==';
				$value    = isset( $rule['value'] ) ? (string) $rule['value'] : '';
				if ( 'post_type' === $param ) {
					$has_pt_rule = true;
					$rule_match  = '!=' === $operator ? $post_type !== $value : $post_type === $value;
					$matches     = $matches && $rule_match;
				} else {
					$conditional = true;
					$compatibility = self::acf_location_rule_post_type_compatibility( $param, $operator, $value, $post_type );
					if ( false === $compatibility ) {
						$matches = false;
					} elseif ( true === $compatibility ) {
						$has_post_scope = true;
					}
				}
			}

			if ( $matches && ( $has_pt_rule || $has_post_scope ) ) {
				return $conditional ? 'conditional' : 'exact';
			}
		}

		return 'none';
	}

	/** Whether a non-post_type ACF rule can target records of this post type. */
	private static function acf_location_rule_post_type_compatibility( string $param, string $operator, string $value, string $post_type ) {
		if ( in_array( $param, [ 'taxonomy', 'user_form', 'user_role', 'comment', 'widget', 'nav_menu', 'nav_menu_item', 'block', 'options_page', 'attachment' ], true ) ) {
			return false;
		}
		if ( in_array( $param, [ 'page', 'page_type', 'page_parent', 'page_template' ], true ) ) {
			return 'page' === $post_type;
		}
		if ( 'post' === $param ) {
			if ( '!=' === $operator ) {
				return true;
			}
			$target = get_post( absint( $value ) );

			return $target instanceof WP_Post && $post_type === (string) $target->post_type;
		}
		if ( 'post_format' === $param ) {
			return post_type_supports( $post_type, 'post-formats' );
		}
		if ( 'post_taxonomy' === $param ) {
			$taxonomy = strstr( $value, ':', true );
			$taxonomy = false === $taxonomy ? $value : $taxonomy;

			return '' !== $taxonomy && is_object_in_taxonomy( $post_type, $taxonomy );
		}
		if ( 'post_template' === $param ) {
			return in_array( $post_type, [ 'post', 'page' ], true ) || post_type_supports( $post_type, 'page-attributes' );
		}
		if ( 'post_status' === $param ) {
			return true;
		}

		// User/session rules can refine an otherwise post-scoped branch, but do
		// not make a field group a post-field candidate on their own.
		return null;
	}

	/** Recursively converts an ACF field tree into capability records. */
	private static function add_acf_field_capabilities( array &$fields, array $field, array $parents, array $verified_fields, bool $usable, string $resource_reason, bool $group_rest, string $applicability, string $group_label ): void {
		if ( count( $fields ) >= self::MAX_FIELDS_PER_RESOURCE ) {
			return;
		}

		$name  = isset( $field['name'] ) ? (string) $field['name'] : '';
		$key   = isset( $field['key'] ) ? (string) $field['key'] : '';
		$type  = isset( $field['type'] ) ? (string) $field['type'] : 'unknown';
		$label = isset( $field['label'] ) && '' !== (string) $field['label'] ? (string) $field['label'] : $name;
		if ( in_array( $type, [ 'tab', 'accordion', 'message' ], true ) ) {
			return;
		}
		if ( '' === $name && '' === $key ) {
			return;
		}

		if ( 'clone' === $type && empty( $field['prefix_name'] ) ) {
			$pointer = self::segments_to_pointer( [ '@acf_fields', '' !== $key ? $key : $name ] );
			$fields[ $pointer ] = self::make_capability_field(
				$pointer,
				$label,
				'unknown',
				'textual',
				[
					'source'              => 'acf',
					'origin'              => 'acf',
					'availability'        => 'potential',
					'availability_reason' => 'acf_clone_schema_required',
					'native_description'  => isset( $field['instructions'] ) ? (string) $field['instructions'] : '',
					'acf_group'           => $group_label,
					'acf_field_key'       => $key,
				]
			);
			return;
		}

		$segments = array_merge( $parents, [ '' !== $name ? $name : $key ] );
		if ( in_array( $type, [ 'group' ], true ) ) {
			foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub_field ) {
				self::add_acf_field_capabilities( $fields, is_array( $sub_field ) ? $sub_field : [], $segments, $verified_fields, $usable, $resource_reason, $group_rest, $applicability, $group_label );
			}
			return;
		}

		if ( in_array( $type, [ 'repeater', 'flexible_content' ], true ) ) {
			$row_segments = array_merge( $segments, [ '*' ] );
			if ( 'flexible_content' === $type ) {
				$layout_pointer = self::segments_to_pointer( array_merge( $row_segments, [ 'acf_fc_layout' ] ) );
				$fields[ $layout_pointer ] = self::make_capability_field( $layout_pointer, 'Layout', 'string', 'structural', [
					'source'              => 'acf',
					'origin'              => 'acf',
					'availability'        => 'potential',
					'availability_reason' => 'layout_choice',
					'acf_group'           => $group_label,
					'acf_field_key'       => $key,
				] );
				foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) {
					foreach ( (array) ( is_array( $layout ) ? ( $layout['sub_fields'] ?? [] ) : [] ) as $sub_field ) {
						self::add_acf_field_capabilities( $fields, is_array( $sub_field ) ? $sub_field : [], $row_segments, $verified_fields, $usable, $resource_reason, $group_rest, 'conditional', $group_label );
					}
				}
			} else {
				foreach ( (array) ( $field['sub_fields'] ?? [] ) as $sub_field ) {
					self::add_acf_field_capabilities( $fields, is_array( $sub_field ) ? $sub_field : [], $row_segments, $verified_fields, $usable, $resource_reason, $group_rest, $applicability, $group_label );
				}
			}
			return;
		}

		$pointer       = self::segments_to_pointer( $segments );
		$verified      = isset( $verified_fields[ $pointer ] );
		$textual_types = [ 'text', 'textarea', 'wysiwyg', 'email', 'url', 'password', 'oembed', 'color_picker', 'date_picker', 'date_time_picker', 'time_picker' ];
		$classification = in_array( $type, $textual_types, true ) ? 'textual' : ( in_array( $type, [ 'select', 'radio', 'checkbox', 'button_group', 'true_false' ], true ) ? 'choice' : 'structural' );

		if ( ! $usable ) {
			$availability = 'potential';
			$reason       = $resource_reason;
		} elseif ( ! $group_rest ) {
			$availability = 'potential';
			$reason       = 'acf_group_show_in_rest_disabled';
		} elseif ( ! $verified ) {
			$availability = 'potential';
			$reason       = 'not_in_write_schema';
		} else {
			$availability = 'available';
			$reason       = '';
		}

		$fields[ $pointer ] = self::make_capability_field(
			$pointer,
			$label,
			in_array( $type, $textual_types, true ) ? 'string' : $type,
			$classification,
			[
				'source'              => 'acf',
				'origin'              => 'acf',
				'availability'        => $availability,
				'availability_reason' => $reason,
				'writable'            => 'available' === $availability,
				'could_be_enabled'    => 'available' !== $availability,
				'transport'           => 'acf_rest',
				'native_description'  => isset( $field['instructions'] ) ? (string) $field['instructions'] : '',
				'acf_group'           => $group_label,
				'acf_field_key'       => $key,
				'applicability'       => $applicability,
			]
		);
	}

	/** Discovers only the active SEO provider's title and description writers. */
	private static function discover_seo_fields( WP_Post_Type $post_type, array $routes, array $write_args, bool $usable, string $resource_reason ): array {
		$result = [
			'fields'  => [],
			'summary' => [
				'active'   => false,
				'provider' => '',
				'writable' => 0,
			],
		];

		$seopress_pattern = '';
		foreach ( array_keys( $routes ) as $registered_route ) {
			if ( 1 === preg_match( '#^/seopress/v1/posts/\(\?P<id>[^/]+\)/title-description-metas$#', (string) $registered_route ) ) {
				$write = self::collect_write_handler_data( is_array( $routes[ $registered_route ] ) ? $routes[ $registered_route ] : [] );
				if ( ! empty( $write['methods'] ) ) {
					$seopress_pattern = (string) $registered_route;
					break;
				}
			}
		}

		if ( '' !== $seopress_pattern ) {
			$result['summary']['active']   = true;
			$result['summary']['provider'] = 'seopress';
			$route = '/seopress/v1/posts/{id}/title-description-metas';
			foreach ( [ 'title' => [ 'SEO title', 'seo_title' ], 'description' => [ 'Meta description', 'seo_description' ] ] as $key => $definition ) {
				$pointer = self::segments_to_pointer( [ '@seo', 'seopress', $key ] );
				$result['fields'][ $pointer ] = self::make_capability_field( $pointer, (string) $definition[0], 'string', 'textual', [
					'source'              => 'seo',
					'origin'              => 'seo_provider',
					'availability'        => 'available',
					'availability_reason' => '',
					'writable'            => true,
					'could_be_enabled'    => false,
					'transport'           => 'seopress',
					'provider'            => 'seopress',
					'role'                => (string) $definition[1],
					'route'               => $route,
					'request_path'        => '/' . $key,
					'methods'             => [ 'PUT' ],
					'write_status'        => 'bridge_writable',
					'native_description'  => 'title' === $key
						? __( 'SEO title written through the active SEOPress REST API.', 'nova-bridge-suite' )
						: __( 'Meta description written through the active SEOPress REST API.', 'nova-bridge-suite' ),
				] );
				++$result['summary']['writable'];
			}

			return $result;
		}

		$aioseo_active = defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' );
		if ( $aioseo_active && isset( $write_args['aioseo_meta_data'] ) ) {
			$result['summary']['active']   = true;
			$result['summary']['provider'] = 'aioseo';
			foreach ( [ 'title' => [ 'SEO title', 'seo_title' ], 'description' => [ 'Meta description', 'seo_description' ] ] as $key => $definition ) {
				$pointer = self::segments_to_pointer( [ '@seo', 'aioseo', $key ] );
				$result['fields'][ $pointer ] = self::make_capability_field( $pointer, (string) $definition[0], 'string', 'textual', [
					'source'       => 'seo',
					'origin'       => 'seo_provider',
					'availability' => $usable ? 'available' : 'potential',
					'availability_reason' => $usable ? '' : $resource_reason,
					'writable'     => $usable,
					'transport'    => 'wordpress',
					'provider'     => 'aioseo',
					'role'         => (string) $definition[1],
					'request_path' => '/aioseo_meta_data/' . $key,
					'methods'      => [ 'POST', 'PUT', 'PATCH' ],
				] );
				++$result['summary']['writable'];
			}

			return $result;
		}

		$meta_bridge = isset( $write_args['meta_all'] );
		$provider    = '';
		$keys        = [];
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			$provider = 'yoast';
			$keys     = [ 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc' ];
		} elseif ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$provider = 'rank_math';
			$keys     = [ 'title' => 'rank_math_title', 'description' => 'rank_math_description' ];
		}

		if ( '' === $provider ) {
			return $result;
		}

		$result['summary']['active']   = true;
		$result['summary']['provider'] = $provider;
		foreach ( $keys as $key => $meta_key ) {
			$pointer = self::segments_to_pointer( [ '@seo', $provider, $key ] );
			$available = $usable && $meta_bridge;
			$result['fields'][ $pointer ] = self::make_capability_field( $pointer, 'title' === $key ? 'SEO title' : 'Meta description', 'string', 'textual', [
				'source'              => 'seo',
				'origin'              => 'seo_provider',
				'availability'        => $available ? 'available' : 'potential',
				'availability_reason' => $available ? '' : ( $usable ? 'nova_meta_bridge_unavailable' : $resource_reason ),
				'writable'            => $available,
				'could_be_enabled'    => ! $available,
				'transport'           => 'nova_meta_bridge',
				'provider'            => $provider,
				'role'                => 'title' === $key ? 'seo_title' : 'seo_description',
				'request_path'        => '/meta_all/' . $meta_key,
				'methods'             => [ 'POST', 'PUT', 'PATCH' ],
				'write_status'        => $available ? 'bridge_writable' : 'not_exposed',
			] );
			$result['summary']['writable'] += $available ? 1 : 0;
		}

		return $result;
	}

	/** Discovers per-post-type theme templates as endpoint-level choices. */
	private static function discover_template_contexts( WP_Post_Type $post_type, array $verified_args, bool $usable, string $resource_reason, array $saved_templates = [] ): array {
		$result = [
			'templates' => [],
			'summary'   => [ 'total' => 0, 'available' => 0, 'potential' => 0, 'selected' => 0, 'field_contexts' => 0 ],
		];

		try {
			$theme     = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
			$templates = is_object( $theme ) && method_exists( $theme, 'get_page_templates' )
				? $theme->get_page_templates( null, (string) $post_type->name )
				: [];
		} catch ( Throwable $exception ) {
			$templates = [];
		}

		$templates = is_array( $templates ) ? $templates : [];
		$verified  = self::build_field_inventory( $verified_args, false );
		$saved_items    = isset( $saved_templates['items'] ) && is_array( $saved_templates['items'] ) ? $saved_templates['items'] : [];
		$saved_selected = isset( $saved_templates['selected'] ) && is_array( $saved_templates['selected'] ) ? $saved_templates['selected'] : [];
		$saved_primary  = isset( $saved_templates['primary'] ) && is_string( $saved_templates['primary'] ) ? $saved_templates['primary'] : '';
		$has_template_field = isset( $verified['/template'] );
		if ( empty( $templates ) && ! $has_template_field && empty( $saved_items ) ) {
			return $result;
		}

		if ( ! empty( $templates ) || $has_template_field ) {
			$templates = [ '' => __( 'Default template', 'nova-bridge-suite' ) ] + $templates;
		}
		$template_is_writable = $usable && $has_template_field;
		$records              = [];
		$saved_ids_by_slug    = [];
		foreach ( $saved_items as $saved_id => $saved_item ) {
			if ( is_array( $saved_item ) && isset( $saved_item['slug'] ) && is_string( $saved_item['slug'] ) ) {
				$saved_ids_by_slug[ $saved_item['slug'] ] = (string) $saved_id;
			}
		}
		foreach ( $templates as $slug => $label ) {
			$slug = self::sanitize_template_slug( $slug );
			if ( null === $slug ) {
				continue;
			}
			$template_id  = isset( $saved_ids_by_slug[ $slug ] ) ? $saved_ids_by_slug[ $slug ] : self::template_id_from_slug( $slug );
			$availability = $template_is_writable ? 'available' : 'potential';
			$reason       = $template_is_writable ? '' : ( $usable ? 'template_not_in_write_schema' : $resource_reason );
			$saved_item   = isset( $saved_items[ $template_id ] ) && is_array( $saved_items[ $template_id ] ) ? $saved_items[ $template_id ] : [];
			$records[ $template_id ] = [
				'id'                  => $template_id,
				'slug'                => $slug,
				'label'               => (string) $label,
				'availability'        => $availability,
				'availability_reason' => $reason,
				'writable'            => $template_is_writable,
				'selected'            => in_array( $template_id, $saved_selected, true ),
				'primary'             => '' !== $saved_primary && $template_id === $saved_primary,
				'saved_description'   => isset( $saved_item['description'] ) ? (string) $saved_item['description'] : '',
				'saved_mapping'       => isset( $saved_item['mapping'] ) ? (string) $saved_item['mapping'] : '',
				'stale'               => false,
			];
			++$result['summary'][ $availability ];
		}

		foreach ( $saved_items as $template_id => $saved_item ) {
			if ( isset( $records[ $template_id ] ) || ! is_array( $saved_item ) ) {
				continue;
			}
			$slug = isset( $saved_item['slug'] ) ? (string) $saved_item['slug'] : ( 'default' === $template_id ? '' : (string) $template_id );
			$records[ (string) $template_id ] = [
				'id'                  => (string) $template_id,
				'slug'                => $slug,
				'label'               => 'default' === $template_id ? __( 'Default template', 'nova-bridge-suite' ) : (string) $template_id,
				'availability'        => 'unavailable',
				'availability_reason' => 'template_no_longer_discovered',
				'writable'            => false,
				'selected'            => in_array( (string) $template_id, $saved_selected, true ),
				'primary'             => '' !== $saved_primary && (string) $template_id === $saved_primary,
				'saved_description'   => isset( $saved_item['description'] ) ? (string) $saved_item['description'] : '',
				'saved_mapping'       => isset( $saved_item['mapping'] ) ? (string) $saved_item['mapping'] : '',
				'stale'               => true,
			];
		}

		ksort( $records, SORT_NATURAL | SORT_FLAG_CASE );
		$result['templates']           = array_values( $records );
		$result['summary']['total']    = count( $records );
		$result['summary']['selected'] = count( array_filter( $records, static function ( $record ): bool { return ! empty( $record['selected'] ); } ) );

		return $result;
	}

	/** Returns the stable template ID used by settings and discovery. */
	private static function template_id_from_slug( string $slug ): string {
		return '' === $slug ? 'default' : $slug;
	}

	/** Discovers safe page-builder applicability and text-control catalogs. */
	private static function discover_builder_contexts( WP_Post_Type $post_type, bool $usable, string $resource_reason, bool $include_catalog ): array {
		$result = [ 'fields' => [], 'summary' => [] ];
		$name   = (string) $post_type->name;

		$gutenberg_detected = class_exists( 'WP_Block_Type_Registry' );
		$gutenberg_enabled  = false;
		if ( $gutenberg_detected && function_exists( 'use_block_editor_for_post_type' ) ) {
			try {
				$gutenberg_enabled = (bool) use_block_editor_for_post_type( $name );
			} catch ( Throwable $exception ) {
				$gutenberg_enabled = false;
			}
		}
		$gutenberg = self::builder_summary( 'gutenberg', 'Gutenberg', $gutenberg_detected, $gutenberg_enabled, $usable );
		if ( $gutenberg_enabled && $include_catalog ) {
			try {
				$blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();
			} catch ( Throwable $exception ) {
				$blocks = [];
			}
			if ( is_array( $blocks ) ) {
				foreach ( $blocks as $block_name => $block ) {
					$attributes = is_object( $block ) && isset( $block->attributes ) && is_array( $block->attributes ) ? $block->attributes : [];
					foreach ( $attributes as $attribute_name => $attribute_schema ) {
						if ( count( $result['fields'] ) >= self::MAX_FIELDS_PER_RESOURCE ) {
							break 2;
						}
						$attribute_schema = is_array( $attribute_schema ) ? $attribute_schema : [];
						$type   = isset( $attribute_schema['type'] ) ? (string) $attribute_schema['type'] : '';
						$source = isset( $attribute_schema['source'] ) ? (string) $attribute_schema['source'] : '';
						if (
							( 'string' !== $type && ! in_array( $source, [ 'html', 'text', 'attribute' ], true ) )
							|| ! self::is_builder_text_control( (string) $attribute_name, (string) $attribute_name, $type )
						) {
							continue;
						}
						$pointer = self::segments_to_pointer( [ '@builders', 'gutenberg', 'elements', (string) $block_name, 'attributes', (string) $attribute_name ] );
						$result['fields'][ $pointer ] = self::make_capability_field( $pointer, (string) $block_name . ': ' . (string) $attribute_name, 'string', 'textual', [
							'source'              => 'builder',
							'origin'              => 'builder_catalog',
							'availability'        => 'potential',
							'availability_reason' => $usable ? 'builder_allowlist_unknown' : $resource_reason,
							'writable'            => false,
							'could_be_enabled'    => true,
							'transport'           => 'native_rest',
							'builder'             => 'gutenberg',
							'element'             => (string) $block_name,
							'control'             => (string) $attribute_name,
							'native_description'  => __( 'Text attribute supported by this Gutenberg block.', 'nova-bridge-suite' ),
						] );
					}
				}
			}
		}
		$gutenberg['field_count']   = self::count_builder_fields( $result['fields'], 'gutenberg' );
		$gutenberg['element_count'] = self::count_builder_elements( $result['fields'], 'gutenberg' );
		$gutenberg['catalog_quality'] = $gutenberg_enabled ? 'possible' : 'unknown';
		$result['summary'][] = $gutenberg;

		$elementor_detected = class_exists( 'Elementor\\Plugin' );
		$elementor_enabled  = $elementor_detected && post_type_supports( $name, 'elementor' );
		$elementor          = self::builder_summary( 'elementor', 'Elementor', $elementor_detected, $elementor_enabled, $usable );
		if ( $elementor_enabled && $include_catalog ) {
			try {
				$manager = Elementor\Plugin::$instance->widgets_manager;
				$widgets = is_object( $manager ) && method_exists( $manager, 'get_widget_types' ) ? $manager->get_widget_types() : [];
			} catch ( Throwable $exception ) {
				$widgets = [];
			}
			if ( is_array( $widgets ) ) {
				foreach ( $widgets as $widget_name => $widget ) {
					try {
						$controls = is_object( $widget ) && method_exists( $widget, 'get_controls' ) ? $widget->get_controls() : [];
					} catch ( Throwable $exception ) {
						$controls = [];
					}
					foreach ( (array) $controls as $control_name => $control ) {
						if ( count( $result['fields'] ) >= self::MAX_FIELDS_PER_RESOURCE ) {
							break 2;
						}
						$control = is_array( $control ) ? $control : [];
						$control_type = (string) ( $control['type'] ?? '' );
						$control_tab  = (string) ( $control['tab'] ?? 'content' );
						$control_label = (string) ( $control['label'] ?? '' );
						if (
							0 === strpos( (string) $control_name, '_' )
							|| ! in_array( $control_type, [ 'text', 'textarea', 'wysiwyg' ], true )
							|| ( '' !== $control_tab && 'content' !== $control_tab )
							|| ! self::is_builder_text_control( (string) $control_name, $control_label, $control_type )
						) {
							continue;
						}
						$pointer = self::segments_to_pointer( [ '@builders', 'elementor', 'elements', (string) $widget_name, 'controls', (string) $control_name ] );
						$result['fields'][ $pointer ] = self::make_capability_field( $pointer, (string) ( $control['label'] ?? ( $widget_name . ': ' . $control_name ) ), 'string', 'textual', [
							'source'              => 'builder',
							'origin'              => 'builder_catalog',
							'availability'        => $usable ? 'available' : 'potential',
							'availability_reason' => $usable ? '' : $resource_reason,
							'writable'            => $usable,
							'could_be_enabled'    => ! $usable,
							'transport'           => 'nova_builder',
							'builder'             => 'elementor',
							'element'             => (string) $widget_name,
							'control'             => (string) $control_name,
							'native_description'  => isset( $control['description'] ) ? (string) $control['description'] : '',
						] );
					}
				}
			}
		}
		$elementor['field_count']     = self::count_builder_fields( $result['fields'], 'elementor' );
		$elementor['element_count']   = self::count_builder_elements( $result['fields'], 'elementor' );
		$elementor['catalog_quality'] = $elementor_enabled ? 'partial' : 'unknown';
		$result['summary'][] = $elementor;

		$wpbakery_detected = function_exists( 'vc_editor_post_types' );
		$wpbakery_types    = [];
		if ( $wpbakery_detected ) {
			try {
				$wpbakery_types = (array) vc_editor_post_types();
			} catch ( Throwable $exception ) {
				$wpbakery_types = [];
			}
		}

		$other_builders = [
			[ 'wpbakery', 'WPBakery', $wpbakery_detected, $wpbakery_types ],
			[ 'divi', 'Divi', function_exists( 'et_builder_enabled_for_post_type' ) || function_exists( 'et_builder_get_builder_post_types' ), [] ],
			[ 'beaver', 'Beaver Builder', class_exists( 'FLBuilderModel' ), [] ],
			[ 'breakdance', 'Breakdance', defined( 'BREAKDANCE_VERSION' ) || class_exists( 'Breakdance\\Plugin' ), [ 'post', 'page' ] ],
			[ 'avada', 'Avada', defined( 'AVADA_VERSION' ) || class_exists( 'FusionBuilder' ), [] ],
		];
		foreach ( $other_builders as $builder ) {
			$detected = (bool) $builder[2];
			$enabled  = false;
			try {
				if ( 'wpbakery' === $builder[0] ) {
					$enabled = $detected && in_array( $name, $builder[3], true );
				} elseif ( 'divi' === $builder[0] && function_exists( 'et_builder_enabled_for_post_type' ) ) {
					$enabled = (bool) et_builder_enabled_for_post_type( $name );
				} elseif ( 'divi' === $builder[0] && function_exists( 'et_builder_get_builder_post_types' ) ) {
					$enabled = in_array( $name, (array) et_builder_get_builder_post_types(), true );
				} elseif ( 'beaver' === $builder[0] && method_exists( 'FLBuilderModel', 'is_post_type_enabled' ) ) {
					$enabled = (bool) FLBuilderModel::is_post_type_enabled( $name );
				} elseif ( 'breakdance' === $builder[0] ) {
					$enabled = $detected && in_array( $name, $builder[3], true );
				}
			} catch ( Throwable $exception ) {
				$enabled = false;
			}

			$summary                    = self::builder_summary( (string) $builder[0], (string) $builder[1], $detected, $enabled, $usable );
			$summary['catalog_quality'] = 'unknown';
			$result['summary'][]         = $summary;
		}

		$filtered = apply_filters( 'nova_bridge_suite_content_context_builder_catalog', $result, $post_type );

		return is_array( $filtered ) && isset( $filtered['fields'], $filtered['summary'] ) ? $filtered : $result;
	}

	/** Creates one builder summary with the UI's stable count/availability shape. */
	private static function builder_summary( string $id, string $label, bool $detected, bool $enabled, bool $usable ): array {
		return [
			'id'            => $id,
			'label'         => $label,
			'detected'      => $detected,
			'enabled'       => $enabled,
			'availability'  => $enabled && $usable ? 'available' : ( $detected ? 'potential' : 'unavailable' ),
			'element_count' => 0,
			'field_count'   => 0,
		];
	}

	/** Whether a builder control is author-facing copy rather than structure. */
	private static function is_builder_text_control( string $name, string $label, string $type ): bool {
		$haystack = strtolower( $name . ' ' . $label );
		if ( preg_match( '/(?:^|[_\s-])(?:css|class|id|selector|attribute|query|taxonomy|tag|animation|code)(?:$|[_\s-])/', $haystack ) ) {
			return false;
		}

		if ( 'wysiwyg' === $type ) {
			return true;
		}

		return 1 === preg_match( '/(?:title|text|content|description|caption|heading|label|button|message|quote|author|name|subtitle|placeholder|prefix|suffix|before|after|testimonial|question|answer|summary|citation|alt)/', $haystack );
	}

	/** Counts catalog fields belonging to one builder. */
	private static function count_builder_fields( array $fields, string $builder ): int {
		$count = 0;
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && $builder === ( $field['builder'] ?? '' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/** Counts unique builder elements represented by catalog fields. */
	private static function count_builder_elements( array $fields, string $builder ): int {
		$elements = [];
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && $builder === ( $field['builder'] ?? '' ) && ! empty( $field['element'] ) ) {
				$elements[ (string) $field['element'] ] = true;
			}
		}

		return count( $elements );
	}

	/** Returns bounded, concrete source documents that contain builder data. */
	private static function discover_bridge_examples( WP_Post_Type $post_type ): array {
		global $wpdb;

		if ( ! isset( $wpdb->posts, $wpdb->postmeta ) ) {
			return [];
		}

		$meta_keys = [
			'_elementor_data', '_elementor_edit_mode', '_wpb_vc_js_status', '_et_pb_use_builder',
			'_fl_builder_enabled', '_fl_builder_data', '_breakdance_data', 'breakdance_data',
			'_fusion_builder_status', '_fusion_builder_shortcodes', '_fusion_builder_content', 'fusion_builder_content',
		];
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$sql = "SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ({$placeholders}) AND pm.meta_value <> ''
			WHERE p.post_type = %s
			AND p.post_status IN ('publish','draft','pending','private','future')
			AND (
				pm.meta_id IS NOT NULL
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
				OR p.post_content LIKE %s
			)
			ORDER BY p.post_modified_gmt DESC, p.ID DESC
			LIMIT 30";
		$args = array_merge(
			$meta_keys,
			[
				(string) $post_type->name,
				'%' . $wpdb->esc_like( '<!-- wp:' ) . '%',
				'%' . $wpdb->esc_like( '[vc_' ) . '%',
				'%' . $wpdb->esc_like( '[et_pb_' ) . '%',
				'%' . $wpdb->esc_like( '<!-- wp:divi/' ) . '%',
				'%' . $wpdb->esc_like( '[fusion_' ) . '%',
			]
		);

		try {
			$prepared = $wpdb->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ids      = $wpdb->get_col( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} catch ( Throwable $exception ) {
			return [];
		}

		$examples = [];
		foreach ( (array) $ids as $post_id ) {
			$post = get_post( absint( $post_id ) );
			if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}

			foreach ( self::detect_actual_builders( $post ) as $builder ) {
				$examples[] = [
					'post_id'   => (int) $post->ID,
					'title'     => '' !== trim( (string) $post->post_title ) ? (string) $post->post_title : sprintf( __( 'Untitled #%d', 'nova-bridge-suite' ), $post->ID ),
					'post_type' => (string) $post->post_type,
					'template'  => function_exists( 'get_page_template_slug' ) ? (string) get_page_template_slug( $post->ID ) : '',
					'builder'   => $builder,
					'label'     => self::builder_label( $builder ),
				];
				if ( count( $examples ) >= 30 ) {
					break 2;
				}
			}
		}

		return $examples;
	}

	/** Detects builders from persisted data on one concrete document. */
	private static function detect_actual_builders( WP_Post $post ): array {
		$content  = (string) $post->post_content;
		$builders = [];
		$meta     = static function ( string $key ) use ( $post ) {
			$value = get_post_meta( $post->ID, $key, true );
			return is_array( $value ) ? ! empty( $value ) : '' !== trim( (string) $value );
		};

		if ( $meta( '_elementor_data' ) || 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			$builders[] = 'elementor';
		}
		if ( $meta( '_wpb_vc_js_status' ) || false !== strpos( $content, '[vc_' ) ) {
			$builders[] = 'wpbakery';
		}
		if ( 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) || false !== strpos( $content, '[et_pb_' ) || false !== strpos( $content, '<!-- wp:divi/' ) ) {
			$builders[] = 'divi';
		}
		if ( $meta( '_fl_builder_enabled' ) || $meta( '_fl_builder_data' ) ) {
			$builders[] = 'beaver';
		}
		if ( $meta( '_breakdance_data' ) || $meta( 'breakdance_data' ) ) {
			$builders[] = 'breakdance';
		}
		if ( $meta( '_fusion_builder_status' ) || $meta( '_fusion_builder_shortcodes' ) || $meta( '_fusion_builder_content' ) || $meta( 'fusion_builder_content' ) || false !== strpos( $content, '[fusion_' ) ) {
			$builders[] = 'avada';
		}
		if ( function_exists( 'has_blocks' ) ? has_blocks( $content ) : false !== strpos( $content, '<!-- wp:' ) ) {
			$builders[] = 'gutenberg';
		}

		return array_values( array_unique( $builders ) );
	}

	/** Human label for a NOVA bridge provider. */
	private static function builder_label( string $builder ): string {
		$labels = [
			'elementor' => 'Elementor', 'wpbakery' => 'WPBakery', 'divi' => 'Divi',
			'beaver' => 'Beaver Builder', 'breakdance' => 'Breakdance', 'avada' => 'Avada', 'gutenberg' => 'Gutenberg',
		];

		return isset( $labels[ $builder ] ) ? $labels[ $builder ] : ucfirst( $builder );
	}

	/** Extracts a normalized text map from the selected document's own bridge. */
	private static function extract_bridge_fields( string $builder, WP_Post $post ) {
		switch ( $builder ) {
			case 'elementor':
				return self::extract_elementor_bridge_fields( $post );
			case 'wpbakery':
				return self::extract_wpbakery_bridge_fields( $post );
			case 'divi':
				return self::extract_divi_bridge_fields( $post );
			case 'beaver':
				return self::extract_beaver_bridge_fields( $post );
			case 'breakdance':
				return self::extract_breakdance_bridge_fields( $post );
			case 'avada':
				return self::extract_avada_bridge_fields( $post );
			case 'gutenberg':
				return self::extract_gutenberg_bridge_fields( $post );
		}

		return new WP_Error( 'builder_not_supported', __( 'This builder does not have a NOVA field mapper.', 'nova-bridge-suite' ) );
	}

	/** Returns the verified native REST write contract for a concrete post. */
	private static function native_post_write_contract( WP_Post $post ): array {
		$post_type = get_post_type_object( (string) $post->post_type );
		if ( ! $post_type instanceof WP_Post_Type ) {
			return [ 'available' => false, 'route' => '', 'methods' => [], 'args' => [] ];
		}

		$base      = ! empty( $post_type->rest_base ) ? trim( (string) $post_type->rest_base, '/' ) : (string) $post_type->name;
		$namespace = ! empty( $post_type->rest_namespace ) ? trim( (string) $post_type->rest_namespace, '/' ) : 'wp/v2';
		$route     = '/' . $namespace . '/' . $base;
		if ( empty( $post_type->show_in_rest ) ) {
			return [ 'available' => false, 'route' => $route . '/{id}', 'methods' => [], 'args' => [] ];
		}

		$write = self::collect_resource_write_data( self::get_registered_routes(), $route );

		return [
			'available' => ! empty( $write['methods'] ),
			'route'     => $route . '/{id}',
			'methods'   => (array) $write['methods'],
			'args'      => (array) $write['args'],
		];
	}

	/** Creates one provenance-safe field record for the admin mapping UI. */
	private static function make_bridge_field_record( WP_Post $post, string $builder, array $data ): array {
		$selector = isset( $data['selector'] ) && is_array( $data['selector'] ) ? $data['selector'] : [];
		$encoded  = wp_json_encode( $selector, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$hash     = substr( sha1( (string) $encoded ), 0, 20 );
		$pointer  = self::segments_to_pointer( [ '@builders', $builder, 'documents', (string) $post->ID, 'fields', $hash ] );
		$element  = isset( $data['element'] ) ? (string) $data['element'] : 'element';
		$logical  = isset( $data['logical_field'] ) ? (string) $data['logical_field'] : 'text';
		$label    = isset( $data['label'] ) && '' !== trim( (string) $data['label'] )
			? (string) $data['label']
			: self::builder_label( $builder ) . ' ' . $element . ' · ' . $logical;
		$value    = isset( $data['value'] ) && is_scalar( $data['value'] ) ? (string) $data['value'] : '';
		$writable = ! empty( $data['writable'] );

		$record = self::make_capability_field( $pointer, $label, 'string', 'textual', [
			'source'              => 'builder',
			'origin'              => 'actual_document',
			'availability'        => $writable ? 'available' : 'unavailable',
			'availability_reason' => $writable ? '' : (string) ( $data['reason'] ?? 'bridge_field_read_only' ),
			'writable'            => $writable,
			'could_be_enabled'    => false,
			'transport'           => 'nova_builder',
			'provider'            => $builder,
			'role'                => (string) ( $data['role'] ?? 'builder_text' ),
			'route'               => (string) ( $data['route'] ?? '' ),
			'request_path'        => (string) ( $data['request_path'] ?? '' ),
			'methods'             => $writable ? [ 'PATCH' ] : [],
			'write_status'        => $writable ? 'conditional' : 'read_only',
			'builder'             => $builder,
			'element'             => $element,
			'control'             => $logical,
			'source_post_id'      => (string) $post->ID,
			'source_post_title'   => (string) $post->post_title,
			'template'            => function_exists( 'get_page_template_slug' ) ? (string) get_page_template_slug( $post->ID ) : '',
			'native_description'  => __( 'Actual field returned by the NOVA bridge for the selected source document.', 'nova-bridge-suite' ),
		] );
		$record['id']               = $builder . ':' . $post->ID . ':' . $hash;
		$record['selector']         = $encoded;
		$record['selector_data']    = $selector;
		$record['logical_field']    = $logical;
		$record['context']          = isset( $data['context'] ) ? (string) $data['context'] : '';
		$record['format']           = isset( $data['format'] ) ? (string) $data['format'] : 'unknown';
		$record['current_value']    = self::limit_characters( $value, 600 );
		$record['source_post_type'] = (string) $post->post_type;
		$record['read']             = isset( $data['read'] ) && is_array( $data['read'] ) ? $data['read'] : [];
		$record['write']            = isset( $data['write'] ) && is_array( $data['write'] ) ? $data['write'] : [];
		$record['precondition']     = isset( $data['precondition'] ) && is_array( $data['precondition'] ) ? $data['precondition'] : null;
		$record['configurable']     = true;

		return $record;
	}

	/** Elementor's field_key-qualified bridge map. */
	private static function extract_elementor_bridge_fields( WP_Post $post ) {
		$class = '\\SEOR_Elementor_Bridge\\Elementor_Service';
		if ( ! class_exists( $class ) ) {
			return new WP_Error( 'elementor_bridge_unavailable', __( 'The Elementor bridge is not loaded.', 'nova-bridge-suite' ) );
		}

		$service = new $class();
		$payload = $service->get_page_payload( $post->ID, [ 'include_fields' => true, 'include_element_map' => true, 'include_document' => false ] );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$records = [];
		foreach ( (array) ( $payload['fields'] ?? [] ) as $field ) {
			if ( ! is_array( $field ) || empty( $field['field_key'] ) ) {
				continue;
			}
			$key     = (string) ( $field['key'] ?? 'text' );
			$element = (string) ( $field['widget_type'] ?? 'widget' );
			$records[] = self::make_bridge_field_record( $post, 'elementor', [
				'selector' => [ 'field_key' => (string) $field['field_key'] ], 'element' => $element,
				'logical_field' => $key, 'label' => self::builder_label( 'elementor' ) . ' · ' . $element . ' · ' . $key,
				'context' => implode( '.', array_map( 'strval', (array) ( $field['path'] ?? [] ) ) ),
				'value' => (string) ( $field['value'] ?? '' ), 'format' => 'unknown', 'writable' => true,
				'route' => '/seor-bridge/v1/pages/{id}', 'request_path' => '/fields/*/value',
				'read' => [ 'route' => '/seor-bridge/v1/pages/{id}/fields', 'response_path' => '/fields/*' ],
				'write' => [ 'route' => '/seor-bridge/v1/pages/{id}', 'method' => 'PATCH', 'collection' => 'fields', 'payload_item' => [ 'field_key' => (string) $field['field_key'], 'value' => '{value}' ] ],
			] );
		}

		return $records;
	}

	/** WPBakery's path+field map with document-hash protection. */
	private static function extract_wpbakery_bridge_fields( WP_Post $post ) {
		if ( ! function_exists( 'nova_wpb_build_meta_all_payload' ) ) {
			return new WP_Error( 'wpbakery_bridge_unavailable', __( 'The WPBakery bridge is not loaded.', 'nova-bridge-suite' ) );
		}
		$payload = nova_wpb_build_meta_all_payload( $post );
		$outline = self::index_bridge_outline( (array) ( $payload['outline'] ?? [] ) );
		$hash    = (string) ( $payload['document_hash'] ?? '' );
		$direct  = in_array( $post->post_type, [ 'post', 'page' ], true );
		$native  = self::native_post_write_contract( $post );
		$native_writable = ! empty( $native['available'] ) && isset( $native['args']['meta_all'] );
		$route   = $direct ? '/nova-wpbakery/v1/pages/{id}' : (string) $native['route'];
		$records = [];
		foreach ( (array) ( $payload['text_map'] ?? [] ) as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['path'], $entry['field'] ) ) {
				continue;
			}
			$path = (string) $entry['path'];
			$node = (array) ( $outline[ $path ] ?? [] );
			$item = [ 'path' => $path, 'field' => (string) $entry['field'], 'text' => '{value}' ];
			$write = $direct
				? [ 'route' => $route, 'method' => 'PATCH', 'collection' => 'text_updates', 'payload_item' => $item ]
				: [ 'route' => $route, 'method' => 'PATCH', 'collection' => 'meta_all.wpbakery.text_updates', 'payload_item' => $item ];
			$entry_writable = ! empty( $entry['editable'] );
			$transport_writable = $direct || $native_writable;
			$records[] = self::make_bridge_field_record( $post, 'wpbakery', [
				'selector' => [ 'path' => $path, 'field' => (string) $entry['field'] ], 'element' => (string) ( $node['tag'] ?? 'element' ),
				'logical_field' => (string) $entry['field'], 'label' => (string) ( $node['label'] ?? 'WPBakery field' ),
				'context' => (string) ( $node['context'] ?? '' ), 'value' => (string) ( $entry['text'] ?? '' ),
				'format' => (string) ( $entry['format'] ?? 'unknown' ), 'writable' => $entry_writable && $transport_writable,
				'reason' => ! $entry_writable ? 'bridge_field_read_only' : ( $transport_writable ? '' : 'bridge_write_transport_unavailable' ),
				'route' => $route, 'request_path' => $direct ? '/text_updates/*/text' : '/meta_all/wpbakery/text_updates/*/text',
				'read' => [ 'route' => $route, 'response_path' => $direct ? '/layout/text_map/*' : '/meta_all/wpbakery/text_map/*' ],
				'write' => $write, 'precondition' => [ 'name' => 'document_hash', 'value' => $hash, 'required' => true ],
			] );
		}

		return $records;
	}

	/** Divi 4/5 bridge map. */
	private static function extract_divi_bridge_fields( WP_Post $post ) {
		if ( ! function_exists( 'nova_divi_content_format' ) ) {
			return new WP_Error( 'divi_bridge_unavailable', __( 'The Divi bridge is not loaded.', 'nova-bridge-suite' ) );
		}
		$format  = nova_divi_content_format( $post->post_content );
		$route   = '/nova-divi/v1/pages/{id}';
		$records = [];
		if ( 'divi5-blocks' === $format || 'hybrid' === $format ) {
			if ( ! function_exists( 'nova_divi5_build_outline' ) || ! function_exists( 'nova_divi5_build_text_map' ) ) {
				return new WP_Error( 'divi5_bridge_unavailable', __( 'The Divi 5 field mapper is unavailable.', 'nova-bridge-suite' ) );
			}
			$outline_raw = nova_divi5_build_outline( $post->post_content, $post->post_title );
			if ( is_wp_error( $outline_raw ) ) {
				return $outline_raw;
			}
			$outline = self::index_bridge_outline( (array) $outline_raw );
			$map     = nova_divi5_build_text_map( (array) $outline_raw );
			$hash    = function_exists( 'nova_divi5_document_hash' ) ? nova_divi5_document_hash( $post->post_content ) : 'sha256:' . hash( 'sha256', (string) $post->post_content );
			foreach ( (array) $map as $entry ) {
				$path = (string) ( $entry['path'] ?? '' );
				$field = (string) ( $entry['field'] ?? 'text' );
				$node = (array) ( $outline[ $path ] ?? [] );
				$item = [ 'path' => $path, 'field' => $field, 'text' => '{value}' ];
				if ( ! empty( $entry['requires_sync_responsive'] ) ) {
					$item['sync_responsive'] = true;
				}
				$records[] = self::make_bridge_field_record( $post, 'divi', [
					'selector' => [ 'path' => $path, 'field' => $field ], 'element' => (string) ( $node['tag'] ?? 'divi' ),
					'logical_field' => $field, 'label' => (string) ( $node['label'] ?? 'Divi field' ), 'context' => (string) ( $node['context'] ?? '' ),
					'value' => (string) ( $entry['text'] ?? '' ), 'format' => (string) ( $entry['format'] ?? 'unknown' ),
					'writable' => ! empty( $entry['editable'] ) && empty( $entry['dynamic'] ) && empty( $entry['protected'] ),
					'reason' => ! empty( $entry['dynamic'] ) ? 'dynamic_content' : ( ! empty( $entry['protected'] ) ? (string) $entry['protected'] : 'bridge_field_read_only' ),
					'route' => $route, 'request_path' => '/text_updates/*/text', 'read' => [ 'route' => $route, 'response_path' => '/layout/text_map/*' ],
					'write' => [ 'route' => $route, 'method' => 'PATCH', 'collection' => 'text_updates', 'payload_item' => $item ],
					'precondition' => [ 'name' => 'document_hash', 'value' => $hash, 'required' => true ],
				] );
			}
			return $records;
		}

		if ( ! function_exists( 'nova_divi_parse_shortcodes_to_compact' ) || ! function_exists( 'nova_divi_build_outline_from_compact' ) || ! function_exists( 'nova_divi_build_text_map_from_compact' ) ) {
			return new WP_Error( 'divi4_bridge_unavailable', __( 'The Divi 4 field mapper is unavailable.', 'nova-bridge-suite' ) );
		}
		$compact = nova_divi_parse_shortcodes_to_compact( $post->post_content );
		$outline = self::index_bridge_outline( nova_divi_build_outline_from_compact( $compact, false ) );
		foreach ( nova_divi_build_text_map_from_compact( $compact ) as $entry ) {
			$path = (string) ( $entry['path'] ?? '' );
			$node = (array) ( $outline[ $path ] ?? [] );
			$tag  = (string) ( $node['tag'] ?? 'divi' );
			$field = function_exists( 'nova_divi_default_text_field_for_tag' ) ? nova_divi_default_text_field_for_tag( $tag ) : null;
			$writable = ! in_array( $tag, [ 'et_pb_image', 'et_pb_fullwidth_image' ], true );
			$records[] = self::make_bridge_field_record( $post, 'divi', [
				'selector' => [ 'path' => $path ], 'element' => $tag, 'logical_field' => $field ?: 'body',
				'label' => (string) ( $node['label'] ?? 'Divi field' ), 'context' => (string) ( $node['context'] ?? '' ),
				'value' => (string) ( $entry['text'] ?? '' ), 'format' => 'body' === $field || null === $field ? 'html' : 'text',
				'writable' => $writable, 'reason' => $writable ? '' : 'image_display_only', 'route' => $route,
				'request_path' => '/text_updates/*/text', 'read' => [ 'route' => $route, 'response_path' => '/layout/text_map/*' ],
				'write' => [ 'route' => $route, 'method' => 'PATCH', 'collection' => 'text_updates', 'payload_item' => [ 'path' => $path, 'text' => '{value}' ] ],
			] );
		}
		return $records;
	}

	/** Beaver Builder's primary text-map targets. */
	private static function extract_beaver_bridge_fields( WP_Post $post ) {
		if ( ! function_exists( 'nova_bb_get_layout_nodes' ) || ! function_exists( 'nova_bb_flat_to_tree' ) || ! function_exists( 'nova_bb_build_outline_from_tree' ) ) {
			return new WP_Error( 'beaver_bridge_unavailable', __( 'The Beaver Builder bridge is not loaded.', 'nova-bridge-suite' ) );
		}
		$tree    = nova_bb_flat_to_tree( nova_bb_get_layout_nodes( $post->ID ) );
		$outline = self::index_bridge_outline( nova_bb_build_outline_from_tree( $tree ) );
		$map     = function_exists( 'nova_bb_build_text_map_from_tree' ) ? nova_bb_build_text_map_from_tree( $tree ) : [];
		$route   = '/nova-beaver/v1/pages/{id}';
		$records = [];
		foreach ( (array) $map as $entry ) {
			$path = (string) ( $entry['path'] ?? '' );
			$node = (array) ( $outline[ $path ] ?? [] );
			$tag  = (string) ( $node['tag'] ?? 'module' );
			$field = false !== strpos( $path, '@' ) ? 'label' : ( function_exists( 'nova_bb_default_text_field_for_module' ) ? nova_bb_default_text_field_for_module( $tag ) : null );
			$writable = 'photo' !== $tag;
			$records[] = self::make_bridge_field_record( $post, 'beaver', [
				'selector' => [ 'path' => $path ], 'element' => $tag, 'logical_field' => $field ?: 'text',
				'label' => (string) ( $node['label'] ?? 'Beaver field' ), 'context' => (string) ( $node['context'] ?? '' ),
				'value' => (string) ( $entry['text'] ?? '' ), 'format' => $field && function_exists( 'nova_bb_field_is_rich' ) && nova_bb_field_is_rich( $tag, $field ) ? 'html' : 'text',
				'writable' => $writable, 'reason' => $writable ? '' : 'photo_display_only', 'route' => $route,
				'request_path' => '/text_updates/*/text', 'read' => [ 'route' => $route, 'response_path' => '/layout/text_map/*' ],
				'write' => [ 'route' => $route, 'method' => 'PATCH', 'collection' => 'text_updates', 'payload_item' => [ 'path' => $path, 'text' => '{value}' ] ],
			] );
		}
		return $records;
	}

	/** Breakdance's field_key-qualified content map. */
	private static function extract_breakdance_bridge_fields( WP_Post $post ) {
		if ( ! class_exists( 'Nova_BD_Utils' ) ) {
			return new WP_Error( 'breakdance_bridge_unavailable', __( 'The Breakdance bridge is not loaded.', 'nova-bridge-suite' ) );
		}
		$wrapper = Nova_BD_Utils::decode_breakdance_tree( Nova_BD_Utils::get_raw_breakdance_data( $post->ID ) );
		if ( ! is_array( $wrapper ) || empty( $wrapper['root'] ) ) {
			return new WP_Error( 'breakdance_document_invalid', __( 'The Breakdance document could not be decoded.', 'nova-bridge-suite' ) );
		}
		$outline = [];
		$map     = [];
		Nova_BD_Utils::build_outline_and_text_map_from_tree( $wrapper['root'], $outline, $map, 'full', 0, 'content', false );
		$route   = '/nova-breakdance/v1/' . ( 'post' === $post->post_type ? 'posts' : 'pages' ) . '/{id}';
		$records = [];
		foreach ( $map as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['field_key'] ) ) {
				continue;
			}
			$records[] = self::make_bridge_field_record( $post, 'breakdance', [
				'selector' => [ 'field_key' => (string) $entry['field_key'] ], 'element' => (string) ( $entry['type'] ?? 'element' ),
				'logical_field' => (string) ( $entry['prop_path'] ?? 'text' ), 'label' => (string) ( $entry['label'] ?? 'Breakdance field' ),
				'context' => (string) ( $entry['context'] ?? '' ), 'value' => (string) ( $entry['text'] ?? '' ),
				'format' => (string) ( $entry['kind'] ?? 'text' ), 'writable' => in_array( $post->post_type, [ 'post', 'page' ], true ),
				'reason' => in_array( $post->post_type, [ 'post', 'page' ], true ) ? '' : 'bridge_post_type_unsupported',
				'route' => $route, 'request_path' => '/text_updates/*/value', 'read' => [ 'route' => $route, 'response_path' => '/text_map/*' ],
				'write' => [ 'route' => $route, 'method' => 'PATCH', 'collection' => 'text_updates', 'payload_item' => [ 'field_key' => (string) $entry['field_key'], 'value' => '{value}' ] ],
			] );
		}
		return $records;
	}

	/** Avada leaf-only path map. */
	private static function extract_avada_bridge_fields( WP_Post $post ) {
		$transformer_class = '\\NovaAvadaBridge\\Layout_Transformer';
		$service_class     = '\\NovaAvadaBridge\\Page_Service';
		if ( ! class_exists( $transformer_class ) || ! class_exists( $service_class ) ) {
			return new WP_Error( 'avada_bridge_unavailable', __( 'The Avada bridge is not loaded.', 'nova-bridge-suite' ) );
		}
		$transformer = new $transformer_class();
		$service     = new $service_class( $transformer );
		$payload     = $service->build_page_payload( $post );
		$compact     = (array) ( $payload['layout']['compact'] ?? [] );
		$outline     = self::index_bridge_outline( $transformer->to_outline_summary( $compact ) );
		$map         = $transformer->extract_text_map( $compact );
		$route       = '/nova-avada/v1/pages/{id}';
		$records     = [];
		foreach ( (array) $map as $entry ) {
			$path = (string) ( $entry['path'] ?? '' );
			$node = (array) ( $outline[ $path ] ?? [] );
			$leaf = empty( $node['has_children'] );
			$records[] = self::make_bridge_field_record( $post, 'avada', [
				'selector' => [ 'path' => $path ], 'element' => (string) ( $entry['tag'] ?? $node['tag'] ?? 'fusion_text' ),
				'logical_field' => 'body', 'label' => (string) ( $entry['label'] ?? $node['label'] ?? 'Avada field' ),
				'context' => (string) ( $entry['context'] ?? $node['context'] ?? '' ), 'value' => (string) ( $entry['text'] ?? '' ),
				'format' => 'html', 'writable' => $leaf, 'reason' => $leaf ? '' : 'bridge_leaf_only', 'route' => $route,
				'request_path' => '/text_updates/*/text', 'read' => [ 'route' => $route, 'response_path' => '/layout/text_map/*' ],
				'write' => [ 'route' => $route, 'method' => 'PATCH', 'collection' => 'text_updates', 'payload_item' => [ 'path' => $path, 'text' => '{value}' ] ],
			] );
		}
		return $records;
	}

	/** Gutenberg has a document writer, not a truthful per-block writer. */
	private static function extract_gutenberg_bridge_fields( WP_Post $post ): array {
		$direct   = in_array( $post->post_type, [ 'post', 'page' ], true );
		$native   = self::native_post_write_contract( $post );
		$writable = $direct || ( ! empty( $native['available'] ) && isset( $native['args']['content'] ) && empty( $native['args']['content']['readonly'] ) );
		$route = $direct
			? '/nova-gutenberg/v1/' . ( 'post' === $post->post_type ? 'posts' : 'pages' ) . '/{id}'
			: (string) $native['route'];

		return [ self::make_bridge_field_record( $post, 'gutenberg', [
			'selector' => [ 'field' => 'content' ], 'element' => 'document', 'logical_field' => 'content',
			'label' => __( 'Gutenberg document content', 'nova-bridge-suite' ), 'context' => __( 'The bridge updates the complete block document; individual blocks are not independent write targets.', 'nova-bridge-suite' ),
			'value' => (string) $post->post_content, 'format' => 'block_markup', 'writable' => $writable, 'role' => 'content_body',
			'reason' => $writable ? '' : 'bridge_write_transport_unavailable',
			'route' => $route, 'request_path' => '/content', 'read' => [ 'route' => $route, 'response_path' => '/content' ],
			'write' => [ 'route' => $route, 'method' => 'PATCH', 'collection' => '', 'payload_item' => [ 'content' => '{value}' ] ],
		] ) ];
	}

	/** Indexes bridge outline records by their exact path. */
	private static function index_bridge_outline( array $outline ): array {
		$indexed = [];
		foreach ( $outline as $item ) {
			if ( is_array( $item ) && isset( $item['path'] ) ) {
				$indexed[ (string) $item['path'] ] = $item;
			}
		}
		return $indexed;
	}

	/** Creates a full field record for non-schema capabilities. */
	private static function make_capability_field( string $pointer, string $label, string $type, string $classification, array $data ): array {
		$segments = self::decode_json_pointer( $pointer );
		$name     = is_array( $segments ) && ! empty( $segments ) ? (string) end( $segments ) : $pointer;
		$source   = isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'other';
		$group    = isset( $data['group'] ) ? sanitize_key( (string) $data['group'] ) : $source;
		$record   = [
			'path'                => $pointer,
			'name'                => $name,
			'label'               => $label,
			'type'                => $type,
			'classification'      => $classification,
			'configurable'        => true,
			'manual_allowed'      => true,
			'schema_quality'      => 'partial',
			'native_description'  => isset( $data['native_description'] ) ? (string) $data['native_description'] : '',
			'schema'              => [],
			'saved_description'   => '',
			'saved_mapping'       => '',
			'manual'              => false,
			'stale'               => false,
			'source'              => $source,
			'group'               => '' !== $group ? $group : $source,
			'origin'              => isset( $data['origin'] ) ? (string) $data['origin'] : $source,
			'availability'        => isset( $data['availability'] ) ? (string) $data['availability'] : 'potential',
			'availability_reason' => isset( $data['availability_reason'] ) ? (string) $data['availability_reason'] : '',
			'writable'            => ! empty( $data['writable'] ),
			'could_be_enabled'    => ! empty( $data['could_be_enabled'] ),
			'transport'           => isset( $data['transport'] ) ? (string) $data['transport'] : 'native_rest',
		];
		$record['reason'] = $record['availability_reason'];

		foreach ( [ 'template', 'builder', 'element', 'control', 'acf_group', 'acf_field_key', 'applicability', 'selector', 'provider', 'role', 'route', 'request_path', 'target_path', 'base_availability', 'write_status', 'source_post_id', 'source_post_title' ] as $key ) {
			if ( array_key_exists( $key, $data ) && is_scalar( $data[ $key ] ) ) {
				$record[ $key ] = (string) $data[ $key ];
			}
		}

		foreach ( [ 'methods', 'choices', 'transports', 'write' ] as $key ) {
			if ( array_key_exists( $key, $data ) && is_array( $data[ $key ] ) ) {
				$record[ $key ] = $data[ $key ];
			}
		}

		return $record;
	}

	/** Whether an unquestionably administrative write route should be omitted. */
	private static function is_ignored_write_route( string $route ): bool {
		return 1 === preg_match(
			'#^/(?:wp/v\d+/(?:settings|users|plugins|themes|application-passwords)|batch/v\d+|wp-site-health/v\d+)(?:/|$)#i',
			$route
		);
	}

	/** Whether a non-post-type route is plausibly intended to write content. */
	private static function is_content_route( string $route, array $args ): bool {
		if ( preg_match( '#^/(?:nova-(?:wpbakery|gutenberg|breakdance|avada|divi|beaver)|seor-bridge)/v\d+(?:/|$)#i', $route ) ) {
			return true;
		}

		if ( preg_match( '#/(?:posts?|pages?|articles?|content|documents?|layouts?|blocks?|templates?|products?|categories?|terms?|media|services?|blogs?)(?:/|$)#i', $route ) ) {
			return true;
		}

		$content_keys = [
			'title',
			'content',
			'excerpt',
			'description',
			'name',
			'caption',
			'alt_text',
			'meta',
			'acf',
			'fields',
			'layout',
			'document',
			'blocks',
			'payload',
			'body',
		];

		foreach ( $args as $key => $schema ) {
			if ( in_array( strtolower( (string) $key ), $content_keys, true ) || self::schema_contains_content_key( is_array( $schema ) ? $schema : [], $content_keys ) ) {
				return true;
			}
		}

		return false;
	}

	/** Recursively looks for content-like property names in a route schema. */
	private static function schema_contains_content_key( array $schema, array $keys ): bool {
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $name => $property ) {
				if ( in_array( strtolower( (string) $name ), $keys, true ) || self::schema_contains_content_key( is_array( $property ) ? $property : [], $keys ) ) {
					return true;
				}
			}
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) && self::schema_contains_content_key( $schema['items'], $keys ) ) {
			return true;
		}

		foreach ( [ 'oneOf', 'anyOf', 'allOf' ] as $composition ) {
			if ( empty( $schema[ $composition ] ) || ! is_array( $schema[ $composition ] ) ) {
				continue;
			}
			foreach ( $schema[ $composition ] as $variant ) {
				if ( is_array( $variant ) && self::schema_contains_content_key( $variant, $keys ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Builds JSON-Pointer-keyed field records from endpoint argument schemas.
	 *
	 * @param array $args          Root endpoint arguments.
	 * @param bool  $route_context Whether opaque fields should be emphasized.
	 * @return array<string,array<string,mixed>>
	 */
	private static function build_field_inventory( array $args, bool $route_context ): array {
		$fields = [];

		foreach ( $args as $name => $schema ) {
			$name = (string) $name;
			if ( '' === $name || in_array( strtolower( $name ), [ 'id', 'post_id', 'id_or_slug', 'identifier' ], true ) ) {
				continue;
			}

			self::walk_field_schema( is_array( $schema ) ? $schema : [], [ $name ], $fields, $route_context, false );
			if ( count( $fields ) >= self::MAX_FIELDS_PER_RESOURCE ) {
				break;
			}
		}

		ksort( $fields, SORT_NATURAL | SORT_FLAG_CASE );

		return $fields;
	}

	/** Adds explicit provenance and availability to every discovered field. */
	private static function annotate_field_inventory( array $fields, array $defaults ): array {
		foreach ( $fields as $pointer => &$field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$segments = self::decode_json_pointer( (string) $pointer );
			$root     = is_array( $segments ) && ! empty( $segments ) ? strtolower( (string) $segments[0] ) : '';
			if ( 'meta' === $root ) {
				$source = 'meta';
			} elseif ( 'acf' === $root ) {
				$source = 'acf';
			} elseif ( '@templates' === $root ) {
				$source = 'template';
			} elseif ( '@builders' === $root ) {
				$source = 'builder';
			} elseif ( 'rest_schema' === ( $defaults['origin'] ?? '' ) && in_array( $root, [ 'title', 'content', 'excerpt', 'caption', 'description', 'alt_text', 'template' ], true ) ) {
				$source = 'core';
			} else {
				$source = isset( $defaults['source'] ) ? sanitize_key( (string) $defaults['source'] ) : 'other';
			}

			$field['source']              = $source;
			$field['group']               = $source;
			$field['origin']              = isset( $defaults['origin'] ) ? (string) $defaults['origin'] : 'unknown';
			$field['availability']        = isset( $defaults['availability'] ) ? (string) $defaults['availability'] : 'potential';
			$field['availability_reason'] = isset( $defaults['availability_reason'] ) ? (string) $defaults['availability_reason'] : '';
			$field['reason']              = $field['availability_reason'];
			$field['writable']            = ! empty( $defaults['writable'] );
			$field['could_be_enabled']    = ! empty( $defaults['could_be_enabled'] );
			$field['transport']           = isset( $defaults['transport'] ) ? (string) $defaults['transport'] : 'native_rest';
		}
		unset( $field );

		return $fields;
	}

	/** Merges capability records while never downgrading verified availability. */
	private static function merge_field_inventories( array $current, array $incoming ): array {
		foreach ( $incoming as $pointer => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			if ( ! isset( $current[ $pointer ] ) ) {
				$current[ $pointer ] = $field;
				continue;
			}

			$current_available  = 'available' === ( $current[ $pointer ]['availability'] ?? '' );
			$incoming_available = 'available' === ( $field['availability'] ?? '' );
			if ( ! $current_available && $incoming_available ) {
				$current[ $pointer ] = array_merge( $current[ $pointer ], $field );
				continue;
			}

			if ( empty( $current[ $pointer ]['native_description'] ) && ! empty( $field['native_description'] ) ) {
				$current[ $pointer ]['native_description'] = $field['native_description'];
			}
			if ( empty( $current[ $pointer ]['label'] ) && ! empty( $field['label'] ) ) {
				$current[ $pointer ]['label'] = $field['label'];
			}

			if ( ! empty( $field['transports'] ) && is_array( $field['transports'] ) ) {
				$transports = [];
				foreach ( array_merge( (array) ( $current[ $pointer ]['transports'] ?? [] ), $field['transports'] ) as $transport ) {
					if ( ! is_array( $transport ) ) {
						continue;
					}
					$key = (string) ( $transport['id'] ?? '' ) . '|' . (string) ( $transport['route'] ?? '' ) . '|' . (string) ( $transport['request_path'] ?? '' );
					$transports[ $key ] = $transport;
				}
				$current[ $pointer ]['transports'] = array_values( $transports );
			}
		}

		ksort( $current, SORT_NATURAL | SORT_FLAG_CASE );

		return $current;
	}

	/** Recursive schema walker supporting properties, items, and compositions. */
	private static function walk_field_schema( array $schema, array $segments, array &$fields, bool $route_context, bool $composed ): void {
		if ( count( $fields ) >= self::MAX_FIELDS_PER_RESOURCE || count( $segments ) > 20 ) {
			return;
		}
		if ( ! empty( $schema['readonly'] ) ) {
			return;
		}

		foreach ( [ 'oneOf', 'anyOf', 'allOf' ] as $composition ) {
			if ( empty( $schema[ $composition ] ) || ! is_array( $schema[ $composition ] ) ) {
				continue;
			}
			$base_schema = $schema;
			unset( $base_schema['oneOf'], $base_schema['anyOf'], $base_schema['allOf'] );

			foreach ( $schema[ $composition ] as $variant ) {
				if ( is_array( $variant ) ) {
					self::walk_field_schema( self::merge_schema( $base_schema, $variant ), $segments, $fields, $route_context, true );
				}
			}
		}

		$types = self::schema_types( isset( $schema['type'] ) ? $schema['type'] : null );
		if ( empty( $types ) && isset( $schema['properties'] ) ) {
			$types[] = 'object';
		}
		if ( empty( $types ) && isset( $schema['items'] ) ) {
			$types[] = 'array';
		}

		if ( in_array( 'string', $types, true ) ) {
			self::add_field_record( $fields, $segments, $schema, 'string', $composed ? 'partial' : 'complete', $route_context );
		}

		if ( in_array( 'array', $types, true ) ) {
			$items      = isset( $schema['items'] ) && is_array( $schema['items'] ) ? $schema['items'] : [];
			$item_types = self::schema_types( isset( $items['type'] ) ? $items['type'] : null );
			if ( in_array( 'string', $item_types, true ) ) {
				self::add_field_record( $fields, $segments, $schema, 'array<string>', $composed ? 'partial' : 'complete', $route_context );
			} elseif ( ! empty( $items ) ) {
				self::walk_field_schema( $items, array_merge( $segments, [ '*' ] ), $fields, $route_context, $composed );
			} else {
				self::add_field_record( $fields, $segments, $schema, 'array', 'opaque', true );
			}
		}

		if ( in_array( 'object', $types, true ) || isset( $schema['properties'] ) ) {
			$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];
			if ( empty( $properties ) ) {
				if ( ! in_array( 'string', $types, true ) ) {
					self::add_field_record( $fields, $segments, $schema, 'object', 'opaque', true );
				}
			} else {
				foreach ( $properties as $name => $property_schema ) {
					self::walk_field_schema( is_array( $property_schema ) ? $property_schema : [], array_merge( $segments, [ (string) $name ] ), $fields, $route_context, $composed );
				}
			}
		}

		$primitive_types = array_values( array_intersect( $types, [ 'integer', 'number', 'boolean', 'null' ] ) );
		if ( ! empty( $primitive_types ) ) {
			self::add_field_record( $fields, $segments, $schema, implode( '|', $primitive_types ), $composed ? 'partial' : 'complete', $route_context );
		}

		if ( empty( $types ) && empty( $schema['oneOf'] ) && empty( $schema['anyOf'] ) && empty( $schema['allOf'] ) ) {
			self::add_field_record( $fields, $segments, $schema, 'unknown', 'opaque', true );
		}
	}

	/** Normalizes a schema type scalar or array. */
	private static function schema_types( $type ): array {
		$type = is_array( $type ) ? $type : ( is_scalar( $type ) ? [ $type ] : [] );
		$types = [];
		foreach ( $type as $value ) {
			if ( is_scalar( $value ) ) {
				$value = strtolower( (string) $value );
				if ( in_array( $value, [ 'string', 'object', 'array', 'integer', 'number', 'boolean', 'null' ], true ) ) {
					$types[] = $value;
				}
			}
		}

		return array_values( array_unique( $types ) );
	}

	/** Adds or merges one leaf record. */
	private static function add_field_record( array &$fields, array $segments, array $schema, string $type, string $quality, bool $route_context ): void {
		$pointer       = self::segments_to_pointer( $segments );
		$classification = self::classify_field( $segments, $type );
		$textual       = 'textual' === $classification;
		$record        = [
			'path'               => $pointer,
			'name'               => (string) end( $segments ),
			'type'               => $type,
			'classification'     => $classification,
			'configurable'       => true,
			'manual_allowed'     => true,
			'schema_quality'     => $quality,
			'native_description' => isset( $schema['description'] ) && is_scalar( $schema['description'] ) ? (string) $schema['description'] : '',
			'schema'             => self::public_schema_snapshot( $schema ),
			'saved_description'  => '',
			'saved_mapping'      => '',
			'manual'             => false,
			'stale'              => false,
		];

		if ( ! isset( $fields[ $pointer ] ) ) {
			$fields[ $pointer ] = $record;
			return;
		}

		if ( $textual ) {
			$fields[ $pointer ]['classification'] = 'textual';
			$fields[ $pointer ]['configurable']   = true;
		}
		if ( '' === $fields[ $pointer ]['native_description'] && '' !== $record['native_description'] ) {
			$fields[ $pointer ]['native_description'] = $record['native_description'];
		}
		if ( 'complete' === $quality ) {
			$fields[ $pointer ]['schema_quality'] = 'complete';
		}
	}

	/** Classifies a leaf as author-facing text or structural data. */
	private static function classify_field( array $segments, string $type ): string {
		$lower = array_map( 'strtolower', $segments );
		$name  = (string) end( $lower );

		if ( false === strpos( $type, 'string' ) ) {
			return 'structural';
		}

		if ( array_intersect( $lower, [ 'meta', 'acf', 'fields' ] ) ) {
			return 'textual';
		}

		$text_names = [ 'title', 'content', 'excerpt', 'description', 'name', 'caption', 'alt_text', 'text', 'copy', 'heading', 'question', 'answer', 'label' ];

		return in_array( $name, $text_names, true ) ? 'textual' : 'structural';
	}

	/** Encodes field path segments as RFC 6901 JSON Pointer. */
	private static function segments_to_pointer( array $segments ): string {
		$encoded = [];
		foreach ( $segments as $segment ) {
			$encoded[] = str_replace( [ '~', '/' ], [ '~0', '~1' ], (string) $segment );
		}

		return '/' . implode( '/', $encoded );
	}

	/** Returns a callback-free, bounded schema snapshot for the discovery UI. */
	private static function public_schema_snapshot( array $schema ): array {
		$snapshot = [];
		foreach ( [ 'type', 'format', 'required', 'readonly', 'default', 'enum', 'minimum', 'maximum', 'minLength', 'maxLength' ] as $key ) {
			if ( isset( $schema[ $key ] ) && ( is_scalar( $schema[ $key ] ) || is_array( $schema[ $key ] ) ) ) {
				$snapshot[ $key ] = $schema[ $key ];
			}
		}

		return $snapshot;
	}

	/** Returns only saved description strings for a canonical resource. */
	private static function resource_saved_descriptions( array $config, string $resource_id ): array {
		$fields = self::resource_saved_fields( $config, $resource_id );
		$result = [];
		foreach ( $fields as $path => $field ) {
			if ( is_array( $field ) && ! empty( $field['description'] ) ) {
				$result[ (string) $path ] = (string) $field['description'];
			}
		}

		return $result;
	}

	/** Returns canonical saved field objects for one resource. */
	private static function resource_saved_fields( array $config, string $resource_id ): array {
		return isset( $config['resources'][ $resource_id ]['fields'] ) && is_array( $config['resources'][ $resource_id ]['fields'] )
			? $config['resources'][ $resource_id ]['fields']
			: [];
	}

	/** Returns canonical saved template configuration for one post-type resource. */
	private static function resource_saved_templates( array $config, string $resource_id ): array {
		return isset( $config['resources'][ $resource_id ]['templates'] ) && is_array( $config['resources'][ $resource_id ]['templates'] )
			? $config['resources'][ $resource_id ]['templates']
			: [];
	}

	/** Returns an explicit saved endpoint selection, or null when still automatic. */
	private static function resource_saved_enabled( array $config, string $resource_id ) {
		if (
			! isset( $config['resources'][ $resource_id ] )
			|| ! is_array( $config['resources'][ $resource_id ] )
			|| ! array_key_exists( 'enabled', $config['resources'][ $resource_id ] )
		) {
			return null;
		}

		return (bool) $config['resources'][ $resource_id ]['enabled'];
	}

	/** Adds saved/stale state to discovered fields. */
	private static function attach_saved_field_state( array $fields, array $saved ): array {
		foreach ( $saved as $pointer => $saved_field ) {
			$description = is_array( $saved_field ) && isset( $saved_field['description'] )
				? (string) $saved_field['description']
				: ( is_string( $saved_field ) ? $saved_field : '' );
			$manual      = is_array( $saved_field ) && ! empty( $saved_field['manual'] );
			$mapping     = is_array( $saved_field ) && isset( $saved_field['mapping'] )
				? (string) $saved_field['mapping']
				: '';

			if ( isset( $fields[ $pointer ] ) ) {
				$fields[ $pointer ]['saved_description'] = $description;
				$fields[ $pointer ]['saved_mapping']     = $mapping;
				if ( $manual ) {
					$fields[ $pointer ]['manual']         = true;
					$fields[ $pointer ]['configurable']   = true;
					$fields[ $pointer ]['manual_allowed'] = true;
				}
				continue;
			}

			$segments = self::decode_json_pointer( $pointer );
			$name     = is_array( $segments ) && ! empty( $segments ) ? (string) end( $segments ) : $pointer;
			$fields[ $pointer ] = [
				'path'               => $pointer,
				'name'               => $name,
				'label'              => $name,
				'type'               => 'unknown',
				'classification'     => 'structural',
				'configurable'       => true,
				'manual_allowed'     => true,
				'schema_quality'     => 'opaque',
				'native_description' => '',
				'schema'             => [],
				'saved_description'  => $description,
				'saved_mapping'      => $mapping,
				'manual'             => true,
				'stale'              => true,
				'source'             => 'other',
				'group'              => 'other',
				'origin'             => 'saved_manual',
				'availability'       => 'unavailable',
				'availability_reason' => 'no_longer_discovered',
				'reason'             => 'no_longer_discovered',
				'writable'           => false,
				'could_be_enabled'   => false,
				'transport'          => 'unknown',
			];
		}

		ksort( $fields, SORT_NATURAL | SORT_FLAG_CASE );

		return $fields;
	}

	/** Summarizes per-field schema quality for a resource card. */
	private static function summarize_schema_quality( array $fields ): string {
		if ( empty( $fields ) ) {
			return 'opaque';
		}

		$qualities = [];
		foreach ( $fields as $field ) {
			$qualities[] = is_array( $field ) && isset( $field['schema_quality'] ) ? (string) $field['schema_quality'] : 'opaque';
		}

		if ( 1 === count( array_unique( $qualities ) ) ) {
			return $qualities[0];
		}

		return 'partial';
	}

	/**
	 * Adds configured context to successful arbitrary-route and OPTIONS responses.
	 *
	 * @param mixed $result  Dispatched response.
	 * @param mixed $server  REST server.
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public static function filter_rest_post_dispatch( $result, $server, $request ) {
		if (
			! $result instanceof WP_REST_Response ||
			! $server instanceof WP_REST_Server ||
			! $request instanceof WP_REST_Request ||
			! is_user_logged_in()
		) {
			return $result;
		}

		$status = (int) $result->get_status();
		if ( $status < 200 || $status >= 300 ) {
			return $result;
		}

		$route    = (string) $request->get_route();
		if ( self::is_suite_owned_context_route( $route ) ) {
			return $result;
		}

		$contexts = self::contexts_matching_request( $route, $server );
		if ( empty( $contexts ) ) {
			return $result;
		}

		$is_options  = 'OPTIONS' === strtoupper( (string) $request->get_method() );
		$descriptions = [];
		foreach ( $contexts as $resource ) {
			if (
				'post_type' === ( $resource['type'] ?? '' )
				&& ! empty( $resource['post_type'] )
				&& self::is_suite_owned_context_post_type( (string) $resource['post_type'] )
			) {
				continue;
			}

			// Core post-type controllers prepare every collection item separately,
			// where filter_post_type_response() can enforce edit_post per item. Do
			// not re-add the same context broadly after a collection dispatch.
			if ( ! $is_options && in_array( (string) ( $resource['type'] ?? '' ), [ 'post_type', 'taxonomy' ], true ) ) {
				continue;
			}

			if (
				(
					'post_type' === ( $resource['type'] ?? '' )
					&& ! empty( $resource['post_type'] )
					&& ! self::post_type_allows_meta_descriptions_merge( (string) $resource['post_type'] )
				)
				|| (
					'taxonomy' === ( $resource['type'] ?? '' )
					&& ! empty( $resource['taxonomy'] )
					&& ! self::post_type_allows_meta_descriptions_merge( (string) $resource['taxonomy'] )
				)
			) {
				continue;
			}

			if ( ! self::can_expose_resource_context( $resource, $request ) ) {
				continue;
			}

			foreach ( self::fields_to_description_map( $resource ) as $pointer => $description ) {
				$descriptions[ $pointer ] = $description;
			}
		}

		if ( empty( $descriptions ) ) {
			return $result;
		}

		$data = $result->get_data();
		if ( ! is_array( $data ) ) {
			return $result;
		}

		if ( $is_options ) {
			$data = self::decorate_options_data( $data, $descriptions );
		} else {
			$data = self::decorate_response_data( $data, $descriptions );
		}

		$result->set_data( $data );

		return $result;
	}

	/** Finds saved resources whose verified registered pattern matches a request. */
	private static function contexts_matching_request( string $request_route, WP_REST_Server $server ): array {
		$config     = self::get_option();
		$registered = $server->get_routes();
		$matched    = [];

		foreach ( $config['resources'] as $resource ) {
			if ( ! is_array( $resource ) || empty( $resource['enabled'] ) || empty( $resource['fields'] ) ) {
				continue;
			}

			$patterns = [];
			if ( 'route' === ( $resource['type'] ?? '' ) && ! empty( $resource['route'] ) ) {
				$saved_route = (string) $resource['route'];
				if ( isset( $registered[ $saved_route ] ) ) {
					$patterns[] = $saved_route;
				}
			} elseif ( 'post_type' === ( $resource['type'] ?? '' ) && ! empty( $resource['post_type'] ) ) {
				$post_type = get_post_type_object( (string) $resource['post_type'] );
				if ( $post_type instanceof WP_Post_Type && ! empty( $post_type->show_in_rest ) ) {
					$base      = ! empty( $post_type->rest_base ) ? trim( (string) $post_type->rest_base, '/' ) : (string) $post_type->name;
					$namespace = ! empty( $post_type->rest_namespace ) ? trim( (string) $post_type->rest_namespace, '/' ) : 'wp/v2';
					$prefix    = '/' . $namespace . '/' . $base;
					foreach ( $registered as $pattern => $handlers ) {
						if ( self::route_has_prefix( (string) $pattern, $prefix ) ) {
							$patterns[] = (string) $pattern;
						}
					}
				}
			} elseif ( 'taxonomy' === ( $resource['type'] ?? '' ) && 'product_cat' === ( $resource['taxonomy'] ?? '' ) ) {
				$taxonomy = get_taxonomy( 'product_cat' );
				$prefixes = [];
				if ( $taxonomy instanceof WP_Taxonomy && ! empty( $taxonomy->show_in_rest ) ) {
					$base      = ! empty( $taxonomy->rest_base ) ? trim( (string) $taxonomy->rest_base, '/' ) : 'product_cat';
					$namespace = ! empty( $taxonomy->rest_namespace ) ? trim( (string) $taxonomy->rest_namespace, '/' ) : 'wp/v2';
					$prefixes[] = '/' . $namespace . '/' . $base;
				}
				foreach ( [ '/wc/v3/products/categories', '/wc/v2/products/categories', '/wc/v1/products/categories' ] as $wc_prefix ) {
					if ( isset( $registered[ $wc_prefix ] ) ) {
						$prefixes[] = $wc_prefix;
						break;
					}
				}
				foreach ( $registered as $pattern => $handlers ) {
					foreach ( $prefixes as $prefix ) {
						if ( self::is_primary_post_type_write_route( (string) $pattern, $prefix ) ) {
							$patterns[] = (string) $pattern;
							break;
						}
					}
				}
			}

			foreach ( $patterns as $pattern ) {
				if ( self::registered_pattern_matches( $pattern, $request_route ) ) {
					$matched[] = $resource;
					break;
				}
			}
		}

		return $matched;
	}

	/** Matches only patterns obtained from the live REST server, not user regex. */
	private static function registered_pattern_matches( string $pattern, string $route ): bool {
		$delimiter = '~';
		$regex     = $delimiter . '^' . str_replace( $delimiter, '\\' . $delimiter, $pattern ) . '$' . $delimiter . 'i';
		$result    = @preg_match( $regex, $route ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return 1 === $result;
	}

	/** Checks a resource-specific edit capability before exposing guidance. */
	private static function can_expose_resource_context( array $resource, WP_REST_Request $request ): bool {
		if ( 'post_type' === ( $resource['type'] ?? '' ) && ! empty( $resource['post_type'] ) ) {
			$post_id = absint( $request->get_param( 'id' ) );

			return self::can_expose_post_context( (string) $resource['post_type'], $post_id );
		}
		if ( 'taxonomy' === ( $resource['type'] ?? '' ) && ! empty( $resource['taxonomy'] ) ) {
			$term_id = absint( $request->get_param( 'id' ) );

			return self::can_expose_taxonomy_context( (string) $resource['taxonomy'], $term_id );
		}

		$method = strtoupper( (string) $request->get_method() );
		if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			// This filter runs only after a successful dispatch, so the exact route's
			// own permission callback has already authorized the write.
			return true;
		}

		if ( 'OPTIONS' === $method ) {
			return current_user_can( 'manage_options' );
		}

		// These suite-owned builder GET routes all apply an editing capability in
		// their permission_callback. This filter runs only after that callback and
		// a successful dispatch, so editors may receive route-level posting guidance
		// for collection and slug/path responses without gaining broader discovery.
		$route = isset( $resource['route'] ) ? (string) $resource['route'] : '';
		if ( 'GET' === $method && self::is_editor_authorized_nova_bridge_route( $route ) ) {
			return true;
		}

		return current_user_can( 'manage_options' );
	}

	/** Identifies suite-owned builder namespaces with edit-gated GET handlers. */
	private static function is_editor_authorized_nova_bridge_route( string $route ): bool {
		$prefixes = [
			'/nova-wpbakery/v1/',
			'/nova-divi/v1/',
			'/nova-beaver/v1/',
			'/nova-breakdance/v1/',
			'/nova-avada/v1/',
			'/seor-bridge/v1/',
		];

		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/** Converts canonical saved field objects into a description map. */
	private static function fields_to_description_map( array $resource ): array {
		$result = [];
		$fields = isset( $resource['fields'] ) && is_array( $resource['fields'] ) ? $resource['fields'] : [];
		foreach ( $fields as $pointer => $field ) {
			if ( is_array( $field ) && ! empty( $field['description'] ) ) {
				$result[ (string) $pointer ] = (string) $field['description'];
			}
		}

		return $result;
	}

	/** Adds descriptions to an item or to every direct item in a collection. */
	private static function decorate_response_data( array $data, array $descriptions ): array {
		if ( self::is_list_array( $data ) ) {
			foreach ( $data as $index => $item ) {
				if ( is_array( $item ) ) {
					$data[ $index ] = self::add_meta_descriptions_to_record( $item, $descriptions );
				}
			}

			return $data;
		}

		foreach ( [ 'items', 'posts', 'pages', 'data', 'results', 'entries', 'records' ] as $collection_key ) {
			if ( isset( $data[ $collection_key ] ) && is_array( $data[ $collection_key ] ) && self::is_list_array( $data[ $collection_key ] ) ) {
				$data[ $collection_key ] = self::decorate_response_data( $data[ $collection_key ], $descriptions );

				return $data;
			}
		}

		return self::add_meta_descriptions_to_record( $data, $descriptions );
	}

	/** Merges only when an existing field is absent or has a compatible shape. */
	private static function add_meta_descriptions_to_record( array $record, array $descriptions ): array {
		if ( array_key_exists( 'meta_descriptions', $record ) && ! is_array( $record['meta_descriptions'] ) ) {
			return $record;
		}
		if (
			isset( $record['meta_descriptions'] )
			&& is_array( $record['meta_descriptions'] )
			&& ! empty( $record['meta_descriptions'] )
			&& self::is_list_array( $record['meta_descriptions'] )
		) {
			return $record;
		}

		$existing                    = isset( $record['meta_descriptions'] ) ? $record['meta_descriptions'] : [];
		$record['meta_descriptions'] = self::merge_description_maps( $existing, $descriptions );

		return $record;
	}

	/** PHP 7.4-compatible list-array check. */
	private static function is_list_array( array $value ): bool {
		$index = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $index ) {
				return false;
			}
			++$index;
		}

		return true;
	}

	/** Decorates an authenticated OPTIONS response without changing validation. */
	private static function decorate_options_data( array $data, array $descriptions ): array {
		$data = self::add_meta_descriptions_to_record( $data, $descriptions );

		if ( ! empty( $data['endpoints'] ) && is_array( $data['endpoints'] ) ) {
			foreach ( $data['endpoints'] as $index => $endpoint ) {
				if ( ! is_array( $endpoint ) || empty( $endpoint['args'] ) || ! is_array( $endpoint['args'] ) ) {
					continue;
				}
				foreach ( $descriptions as $pointer => $description ) {
					self::apply_description_to_args( $endpoint['args'], $pointer, $description );
				}
				$data['endpoints'][ $index ] = $endpoint;
			}
		}

		return $data;
	}

	/** Applies one JSON Pointer description to an OPTIONS args schema. */
	private static function apply_description_to_args( array &$args, string $pointer, string $description ): void {
		$segments = self::decode_json_pointer( $pointer );
		if ( empty( $segments ) ) {
			return;
		}

		$root = array_shift( $segments );
		if ( ! isset( $args[ $root ] ) || ! is_array( $args[ $root ] ) ) {
			return;
		}

		$schema =& $args[ $root ];
		foreach ( $segments as $segment ) {
			if ( in_array( $segment, [ '*', '0' ], true ) && isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
				$schema =& $schema['items'];
				continue;
			}

			if ( ! isset( $schema['properties'][ $segment ] ) || ! is_array( $schema['properties'][ $segment ] ) ) {
				return;
			}
			$schema =& $schema['properties'][ $segment ];
		}

		$schema['description'] = $description;
	}

	/**
	 * Enqueues the module-owned settings application.
	 */
	public static function enqueue_admin_assets( string $hook ): void {
		if ( 'settings_page_nova-settings' !== $hook && false === strpos( $hook, 'nova-settings' ) ) {
			return;
		}

		$current_tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] )
			? sanitize_key( (string) wp_unslash( $_GET['tab'] ) )
			: 'modules';
		if ( 'content-context' === $current_tab ) {
			$current_tab = 'api-mapping-context';
		}
		if ( 'api-mapping-context' !== $current_tab ) {
			return;
		}

		$module_dir = defined( 'NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR' )
			? NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR
			: trailingslashit( dirname( __DIR__ ) );
		$module_url = defined( 'NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_URL' )
			? NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_URL
			: ( defined( 'NOVA_BRIDGE_SUITE_PLUGIN_FILE' )
				? plugins_url( 'modules/api-mapping-context/', NOVA_BRIDGE_SUITE_PLUGIN_FILE )
				: plugin_dir_url( dirname( __FILE__ ) ) );
		$version    = defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : self::SCHEMA_VERSION;
		$script     = $module_dir . 'assets/content-context-admin.js';
		$style      = $module_dir . 'assets/content-context-admin.css';

		if ( file_exists( $style ) ) {
			wp_enqueue_style(
				'nova-bridge-suite-content-context',
				$module_url . 'assets/content-context-admin.css',
				[],
				(string) $version
			);
		}

		if ( ! file_exists( $script ) ) {
			return;
		}

		wp_enqueue_script(
			'nova-bridge-suite-content-context',
			$module_url . 'assets/content-context-admin.js',
			[],
			(string) $version,
			true
		);

		wp_localize_script(
			'nova-bridge-suite-content-context',
			'NovaContentContextAdmin',
			[
				'discoveryUrl' => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_DISCOVERY_ROUTE ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'savedConfig'  => self::get_option(),
			]
		);
	}

	/**
	 * Renders the independent Settings API form used by the NOVA settings tab.
	 */
	public static function render_settings_tab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$config = self::get_option();
		?>
		<div class="nova-content-context-settings">
			<?php settings_errors( self::OPTION_NAME ); ?>

			<form method="post" action="options.php" id="nova-content-context-form">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>

				<div id="nova-content-context-app" aria-live="polite">
					<p id="nova-content-context-status" class="nova-content-context-status">
						<?php echo esc_html__( 'Loading content endpoints…', 'nova-bridge-suite' ); ?>
					</p>
					<p id="nova-content-context-loading" class="nova-content-context-loading">
						<span class="spinner is-active" aria-hidden="true"></span>
						<?php echo esc_html__( 'Inspecting registered post types and REST routes.', 'nova-bridge-suite' ); ?>
					</p>
					<div id="nova-content-context-error" class="notice notice-error inline" role="alert" hidden></div>
					<div id="nova-content-context-resources"></div>
				</div>

				<textarea
					id="nova-content-context-payload"
					name="<?php echo esc_attr( self::OPTION_NAME ); ?>[payload]"
					hidden
					aria-hidden="true"
				><?php echo esc_textarea( wp_json_encode( $config ) ); ?></textarea>

				<?php submit_button( __( 'Save API Mapping Context', 'nova-bridge-suite' ) ); ?>
			</form>
		</div>
		<?php
	}
}
