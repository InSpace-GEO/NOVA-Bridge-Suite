<?php
/**
 * NOVA content-context discovery and REST privacy canary.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/nova-bridge-suite/tests/content-context-regression.php
 *
 * @package NOVA_Bridge_Suite
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/** Throws on failure and prints each successful assertion. */
function nova_content_context_test_assert( $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}

	WP_CLI::line( 'PASS  ' . $message );
}

/** Finds a discovery resource by stable identifier. */
function nova_content_context_test_resource( array $discovery, string $resource_id ) {
	$resources = isset( $discovery['resources'] ) && is_array( $discovery['resources'] ) ? $discovery['resources'] : [];

	foreach ( $resources as $resource ) {
		if ( is_array( $resource ) && isset( $resource['id'] ) && $resource_id === $resource['id'] ) {
			return $resource;
		}
	}

	return null;
}

/** Returns whether a discovery field exists. */
function nova_content_context_test_has_field( array $resource, string $pointer ): bool {
	return isset( $resource['fields'] ) && is_array( $resource['fields'] ) && isset( $resource['fields'][ $pointer ] );
}

/** Finds one selected-template REST context record by stable template ID. */
function nova_content_context_test_template_record( array $contexts, string $template_id ) {
	foreach ( (array) ( $contexts['selected'] ?? [] ) as $record ) {
		if ( is_array( $record ) && $template_id === (string) ( $record['id'] ?? '' ) ) {
			return $record;
		}
	}

	return null;
}

/** Finds one template choice in a discovery resource by stable template ID. */
function nova_content_context_test_discovered_template( array $resource, string $template_id ) {
	foreach ( (array) ( $resource['templates'] ?? [] ) as $record ) {
		if ( is_array( $record ) && $template_id === (string) ( $record['id'] ?? '' ) ) {
			return $record;
		}
	}

	return null;
}

/**
 * Returns active CPTs owned by NOVA's managed Blog module.
 *
 * The CPT names are client-configurable, so identify the module's own
 * meta_descriptions callback instead of relying on a site-specific slug.
 *
 * @return array<string,WP_Post_Type>
 */
function nova_content_context_test_managed_blog_post_types(): array {
	global $wp_rest_additional_fields;

	$managed = [];
	$fields  = is_array( $wp_rest_additional_fields ?? null ) ? $wp_rest_additional_fields : [];

	foreach ( get_post_types( [], 'objects' ) as $post_type ) {
		if ( ! $post_type instanceof WP_Post_Type || empty( $post_type->show_in_rest ) ) {
			continue;
		}

		$registration = $fields[ $post_type->name ]['meta_descriptions'] ?? [];
		$callback     = is_array( $registration ) ? ( $registration['get_callback'] ?? null ) : null;

		if (
			! is_array( $callback )
			|| ! isset( $callback[0], $callback[1] )
			|| ! is_object( $callback[0] )
			|| ! is_a( $callback[0], 'SEORAI\\BodycleanCPT\\Plugin' )
			|| 'get_blog_meta_descriptions_field' !== $callback[1]
		) {
			continue;
		}

		$managed[ $post_type->name ] = $post_type;
	}

	ksort( $managed, SORT_NATURAL | SORT_FLAG_CASE );

	return $managed;
}

if ( ! class_exists( 'Nova_Content_Context_Editable_Schema_Fixture' ) ) {
	/** Controller that intentionally exposes update args only for EDITABLE. */
	final class Nova_Content_Context_Editable_Schema_Fixture {
		public function update_item() {
			return rest_ensure_response( [ 'saved' => true ] );
		}

		public function permission_check(): bool {
			return current_user_can( 'manage_options' );
		}

		public function get_endpoint_args_for_item_schema( $method ): array {
			if ( WP_REST_Server::EDITABLE !== $method ) {
				return [];
			}

			return [
				'editable_copy' => [
					'type'        => 'string',
					'description' => 'Update-only fixture copy.',
				],
			];
		}
	}
}

$visible_post_type = 'nova_ctx_visible';
$hidden_post_type  = 'nova_ctx_hidden';
$collision_post_type = 'nova_ctx_collision';
$application_post_type = 'nova_ctx_country';
$infrastructure_post_type = 'nova_ctx_payment';
$private_post_type = 'nova_ctx_private';
$private_hidden_post_type = 'nova_ctx_prv_hidden';
$private_taxonomy = 'nova_ctx_private_tax';
$locked_taxonomy = 'nova_ctx_locked_tax';
$locked_assign_cap = 'nova_assign_locked_terms';
$subtitle_meta     = 'nova_subtitle';
$faq_meta          = 'nova_faqs';
$hidden_meta       = 'nova_hidden_copy';
$opaque_namespace  = 'vendor-fixture/v1';
$opaque_path       = '/publish';
$opaque_route      = '/' . $opaque_namespace . $opaque_path;
$opaque_resource_id = 'route:' . substr( hash( 'sha256', $opaque_route ), 0, 24 );
$editable_namespace = 'editable-fixture/v1';
$editable_path      = '/documents/(?P<id>[\d]+)';
$editable_route     = '/' . $editable_namespace . $editable_path;
$editable_resource_id = 'route:' . substr( hash( 'sha256', $editable_route ), 0, 24 );
$acf_fixture_group_key = 'group_nova_content_context_fixture';
$acf_fixture_added = false;
$matching_builder_pointer = '';
$other_builder_pointer = '';
$default_template_id = 'default';
$landing_template_slug = 'templates/landing.php';
$resource_template_slug = 'templates/resource.php';
$template_filter_hook = 'theme_' . $visible_post_type . '_templates';
$template_filter = null;
$template_filter_added = false;
$fixture_post_id   = 0;
$hidden_post_id    = 0;
$collision_post_id = 0;
$service_post_id   = 0;
$blog_post_ids      = [];
$managed_blog_types = [];
$failure           = null;
$old_user_id       = get_current_user_id();
$option_name       = class_exists( 'Nova_Bridge_Suite_Content_Context' )
	? Nova_Bridge_Suite_Content_Context::OPTION_NAME
	: 'nova_bridge_suite_content_contexts';
$option_existed    = false !== get_option( $option_name, false );
$old_option        = $option_existed ? get_option( $option_name ) : null;
$rest_init_at_start = did_action( 'rest_api_init' );

$admins = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);

if ( empty( $admins ) ) {
	WP_CLI::error( 'No administrator is available for the content-context canary.' );
}

$admin_id = (int) $admins[0];
wp_set_current_user( $admin_id );

try {
	nova_content_context_test_assert( class_exists( 'Nova_Bridge_Suite_Content_Context' ), 'The content-context core class is loaded.' );
	nova_content_context_test_assert( ! post_type_exists( $visible_post_type ), 'The visible fixture post type name is available.' );
	nova_content_context_test_assert( ! post_type_exists( $hidden_post_type ), 'The hidden fixture post type name is available.' );
	nova_content_context_test_assert( ! post_type_exists( $collision_post_type ), 'The collision fixture post type name is available.' );
	nova_content_context_test_assert( ! post_type_exists( $application_post_type ), 'The application-data fixture post type name is available.' );
	nova_content_context_test_assert( ! post_type_exists( $infrastructure_post_type ), 'The infrastructure fixture post type name is available.' );
	nova_content_context_test_assert( ! post_type_exists( $private_post_type ), 'The private editorial fixture post type name is available.' );
	nova_content_context_test_assert( ! post_type_exists( $private_hidden_post_type ), 'The private REST-disabled editorial fixture post type name is available.' );
	nova_content_context_test_assert( ! taxonomy_exists( $locked_taxonomy ), 'The restricted-assignment taxonomy fixture name is available.' );
	nova_content_context_test_assert( ! current_user_can( $locked_assign_cap ), 'The current administrator lacks the restricted taxonomy assignment capability.' );
	nova_content_context_test_assert( $rest_init_at_start >= 0, 'The initial REST bootstrap state was recorded.' );

	$visible_result = register_post_type(
		$visible_post_type,
		[
			'label'          => 'NOVA Context Articles',
			'public'         => true,
			'show_ui'        => true,
			'show_in_rest'   => true,
			'rest_namespace' => 'nova-fixture/v1',
			'rest_base'      => 'articles',
			'map_meta_cap'   => true,
			'supports'       => [ 'title', 'editor', 'excerpt', 'custom-fields', 'page-attributes' ],
		]
	);
	nova_content_context_test_assert( $visible_result instanceof WP_Post_Type, 'The visible REST fixture post type was registered.' );

	$template_filter = static function ( $templates ) use ( $landing_template_slug, $resource_template_slug ): array {
		$templates = is_array( $templates ) ? $templates : [];
		$templates[ $landing_template_slug ]  = 'NOVA Landing';
		$templates[ $resource_template_slug ] = 'NOVA Resource';

		return $templates;
	};
	add_filter( $template_filter_hook, $template_filter, 10, 4 );
	$template_filter_added = true;

	$hidden_result = register_post_type(
		$hidden_post_type,
		[
			'label'        => 'NOVA Hidden Context Articles',
			'public'       => true,
			'show_ui'      => true,
			'show_in_rest' => false,
			'map_meta_cap' => true,
			'supports'     => [ 'title', 'editor', 'excerpt', 'custom-fields' ],
		]
	);
	nova_content_context_test_assert( $hidden_result instanceof WP_Post_Type, 'The REST-disabled fixture post type was registered.' );

	$collision_result = register_post_type(
		$collision_post_type,
		[
			'label'          => 'NOVA Collision Context Articles',
			'public'         => true,
			'show_ui'        => true,
			'show_in_rest'   => true,
			'rest_namespace' => 'nova-fixture/v1',
			'rest_base'      => 'collision-articles',
			'map_meta_cap'   => true,
			'supports'       => [ 'title', 'editor', 'custom-fields' ],
		]
	);
	nova_content_context_test_assert( $collision_result instanceof WP_Post_Type, 'The incompatible-field collision fixture was registered.' );

	$application_result = register_post_type(
		$application_post_type,
		[
			'label'        => 'NOVA Countries',
			'public'       => true,
			'show_ui'      => true,
			'show_in_rest' => true,
			'map_meta_cap' => true,
			'supports'     => [ 'title' ],
		]
	);
	nova_content_context_test_assert( $application_result instanceof WP_Post_Type, 'The title-only country fixture post type was registered.' );

	$infrastructure_result = register_post_type(
		$infrastructure_post_type,
		[
			'label'          => 'NOVA Payments',
			'public'         => true,
			'show_ui'        => true,
			'show_in_rest'   => true,
			'map_meta_cap'   => true,
			'supports'       => [ 'title', 'editor', 'excerpt', 'custom-fields' ],
		]
	);
	nova_content_context_test_assert( $infrastructure_result instanceof WP_Post_Type, 'The infrastructure-style fixture post type was registered.' );

	$private_result = register_post_type(
		$private_post_type,
		[
			'label'          => 'NOVA Private Editorial Content',
			'public'         => false,
			'show_ui'        => true,
			'show_in_rest'   => true,
			'rest_namespace' => 'nova-fixture/v1',
			'rest_base'      => 'private-editorial',
			'map_meta_cap'   => true,
			'supports'       => [ 'editor', 'custom-fields' ],
		]
	);
	nova_content_context_test_assert( $private_result instanceof WP_Post_Type, 'The private REST-writable titleless editorial fixture was registered.' );

	$private_hidden_result = register_post_type(
		$private_hidden_post_type,
		[
			'label'        => 'NOVA Private Hidden Editorial Content',
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => false,
			'map_meta_cap' => true,
			'supports'     => [ 'editor', 'custom-fields' ],
		]
	);
	nova_content_context_test_assert( $private_hidden_result instanceof WP_Post_Type, 'The private REST-disabled editorial fixture was registered.' );

	$private_taxonomy_result = register_taxonomy(
		$private_taxonomy,
		[ $visible_post_type ],
		[
			'label'        => 'Private editorial topics',
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'rest_base'    => 'private_topics',
		]
	);
	nova_content_context_test_assert( $private_taxonomy_result instanceof WP_Taxonomy, 'The private REST-writable taxonomy fixture was registered.' );

	$locked_taxonomy_result = register_taxonomy(
		$locked_taxonomy,
		[ $visible_post_type ],
		[
			'label'        => 'Restricted editorial topics',
			'public'       => true,
			'show_ui'      => true,
			'show_in_rest' => true,
			'rest_base'    => 'locked_topics',
			'capabilities' => [
				'manage_terms' => 'manage_categories',
				'edit_terms'   => 'manage_categories',
				'delete_terms' => 'manage_categories',
				'assign_terms' => $locked_assign_cap,
			],
		]
	);
	nova_content_context_test_assert( $locked_taxonomy_result instanceof WP_Taxonomy, 'The restricted-assignment REST taxonomy fixture was registered.' );

	$subtitle_registered = register_post_meta(
		$visible_post_type,
		$subtitle_meta,
		[
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => static function (): bool {
				return current_user_can( 'edit_posts' );
			},
		]
	);
	nova_content_context_test_assert( true === $subtitle_registered, 'The string fixture meta field was registered.' );

	$faq_registered = register_post_meta(
		$visible_post_type,
		$faq_meta,
		[
			'type'          => 'array',
			'single'        => true,
			'show_in_rest'  => [
				'schema' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'question' => [ 'type' => 'string' ],
							'answer'   => [ 'type' => 'string' ],
						],
					],
				],
			],
			'auth_callback' => static function (): bool {
				return current_user_can( 'edit_posts' );
			},
		]
	);
	nova_content_context_test_assert( true === $faq_registered, 'The nested FAQ fixture meta field was registered.' );

	if ( function_exists( 'acf_add_local_field_group' ) ) {
		acf_add_local_field_group(
			[
				'key'          => $acf_fixture_group_key,
				'title'        => 'NOVA content-context ACF fixture',
				'show_in_rest' => false,
				'fields'       => [
					[
						'key'   => 'field_nova_context_simple',
						'name'  => 'nova_acf_simple',
						'label' => 'Simple ACF copy',
						'type'  => 'text',
					],
					[
						'key'        => 'field_nova_context_group',
						'name'       => 'nova_acf_group',
						'label'      => 'Grouped ACF copy',
						'type'       => 'group',
						'sub_fields' => [
							[
								'key'   => 'field_nova_context_group_heading',
								'name'  => 'heading',
								'label' => 'Heading',
								'type'  => 'text',
							],
						],
					],
					[
						'key'        => 'field_nova_context_rows',
						'name'       => 'nova_acf_rows',
						'label'      => 'Repeater copy',
						'type'       => 'repeater',
						'sub_fields' => [
							[
								'key'   => 'field_nova_context_rows_copy',
								'name'  => 'copy',
								'label' => 'Copy',
								'type'  => 'textarea',
							],
						],
					],
				],
				'location'     => [
					[
						[
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => $visible_post_type,
						],
					],
				],
			]
		);
		$acf_fixture_added = true;
		WP_CLI::line( 'PASS  The in-memory ACF field-shape fixture was registered.' );
	}

	$hidden_meta_registered = register_post_meta(
		$visible_post_type,
		$hidden_meta,
		[
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => false,
		]
	);
	nova_content_context_test_assert( true === $hidden_meta_registered, 'The non-REST fixture meta field was registered.' );

	$register_opaque_route = static function () use ( $opaque_namespace, $opaque_path ): void {
		register_rest_route(
			$opaque_namespace,
			$opaque_path,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => static function () {
					return rest_ensure_response( [ 'saved' => true ] );
				},
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
			]
		);
	};
	$editable_controller = new Nova_Content_Context_Editable_Schema_Fixture();
	$register_editable_route = static function () use ( $editable_namespace, $editable_path, $editable_controller ): void {
		register_rest_route(
			$editable_namespace,
			$editable_path,
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $editable_controller, 'update_item' ],
				'permission_callback' => [ $editable_controller, 'permission_check' ],
			]
		);
	};
	$register_collision_field = static function () use ( $collision_post_type ): void {
		register_rest_field(
			$collision_post_type,
			'meta_descriptions',
			[
				'get_callback' => static function (): array {
					return [ 'third_party' => 'shape' ];
				},
				'schema'       => [
					'type'                 => 'object',
					'context'              => [ 'view', 'edit' ],
					'additionalProperties' => false,
					'properties'           => [
						'third_party' => [ 'type' => 'string' ],
					],
				],
			]
		);
	};

	if ( $rest_init_at_start > 0 ) {
		$register_opaque_route();
		$register_editable_route();
		$register_collision_field();
	} else {
		add_action( 'rest_api_init', $register_opaque_route, 5 );
		add_action( 'rest_api_init', $register_editable_route, 5 );
		add_action( 'rest_api_init', $register_collision_field, 5 );
	}

	$raw_title_description = "Schrijf een cafétitel met nuance.\nTweede regel met <strong>nadruk</strong>.";
	$title_description     = "Schrijf een cafétitel met nuance.\nTweede regel met nadruk.";
	$content_description   = 'Gebruik heldere alinea\'s en tussenkoppen.';
	$subtitle_description  = 'Korte ondersteunende regel onder de titel.';
	$default_template_description = 'Use the standard editorial structure.';
	$landing_template_description = 'Use a conversion-focused landing-page structure.';
	$resource_template_description = 'Use the long-form resource structure.';
	$default_content_description = 'Write balanced default-template body copy.';
	$landing_content_description = 'Write concise landing-page body copy.';
	$resource_content_description = 'Write comprehensive resource body copy.';
	$regex_route           = '/nova-fixture/v1/articles/(?P<id>[\d]+)';
	$regex_resource_id     = 'route:' . substr( hash( 'sha256', $regex_route ), 0, 24 );
	$version_one_migration = Nova_Bridge_Suite_Content_Context::sanitize_settings(
		[
			'version'   => 1,
			'resources' => [
				'post_type:' . $visible_post_type => [
					'type'      => 'post_type',
					'post_type' => $visible_post_type,
					'fields'    => [ '/title' => 'Legacy context.' ],
				],
			],
		]
	);
	nova_content_context_test_assert( 4 === ( $version_one_migration['version'] ?? 0 ) && ! empty( $version_one_migration['resources'][ 'post_type:' . $visible_post_type ]['enabled'] ), 'Version 1 settings migrate to enabled schema version 4 resources.' );
	nova_content_context_test_assert( 'Legacy context.' === ( $version_one_migration['resources'][ 'post_type:' . $visible_post_type ]['fields']['/title']['description'] ?? '' ), 'Version 1 descriptions survive migration.' );
	nova_content_context_test_assert( '' === ( $version_one_migration['resources'][ 'post_type:' . $visible_post_type ]['fields']['/title']['mapping'] ?? null ), 'Version 1 fields receive an empty version 4 mapping.' );

	$version_two_migration = Nova_Bridge_Suite_Content_Context::sanitize_settings(
		[
			'version'   => 2,
			'resources' => [
				'post_type:' . $visible_post_type => [
					'id'        => 'post_type:' . $visible_post_type,
					'type'      => 'post_type',
					'post_type' => $visible_post_type,
					'enabled'   => false,
					'fields'    => [
						'/excerpt' => [ 'description' => 'Version 2 context.', 'manual' => true ],
					],
				],
				$opaque_resource_id => [
					'id'      => $opaque_resource_id,
					'type'    => 'route',
					'route'   => $opaque_route,
					'methods' => [ 'POST' ],
					'fields'  => [
						'/content' => [ 'description' => 'Legacy route context.', 'manual' => false ],
					],
				],
			],
		]
	);
	nova_content_context_test_assert( 4 === ( $version_two_migration['version'] ?? 0 ), 'Version 2 settings migrate to schema version 4.' );
	nova_content_context_test_assert( empty( $version_two_migration['resources'][ 'post_type:' . $visible_post_type ]['enabled'] ) && ! empty( $version_two_migration['resources'][ 'post_type:' . $visible_post_type ]['fields']['/excerpt']['manual'] ), 'Version 2 selection and manual-field state survive migration.' );
	nova_content_context_test_assert( '' === ( $version_two_migration['resources'][ 'post_type:' . $visible_post_type ]['fields']['/excerpt']['mapping'] ?? null ), 'Version 2 fields receive an empty version 4 mapping.' );
	nova_content_context_test_assert( $opaque_route === ( $version_two_migration['resources'][ $opaque_resource_id ]['route'] ?? '' ) && [ 'POST' ] === ( $version_two_migration['resources'][ $opaque_resource_id ]['methods'] ?? [] ), 'A legacy arbitrary-route configuration still normalizes and preserves its exact route and methods.' );

	$version_three_migration = Nova_Bridge_Suite_Content_Context::sanitize_settings(
		[
			'version'   => 3,
			'resources' => [
				'post_type:' . $visible_post_type => [
					'id'        => 'post_type:' . $visible_post_type,
					'type'      => 'post_type',
					'post_type' => $visible_post_type,
					'enabled'   => true,
					'fields'    => [
						'/title' => [ 'description' => 'Version 3 base title.', 'mapping' => 'content.title', 'manual' => false ],
						'/@templates/default' => [ 'description' => 'Version 3 default context.', 'mapping' => 'template.default', 'manual' => false ],
						'/@templates/templates~1landing.php' => [ 'description' => 'Version 3 landing context.', 'mapping' => 'template.landing', 'manual' => false ],
						'/@templates/templates~1landing.php/fields/~1content' => [ 'description' => 'Version 3 landing body.', 'mapping' => 'template.landing.body', 'manual' => true ],
					],
				],
			],
		]
	);
	$version_three_resource = $version_three_migration['resources'][ 'post_type:' . $visible_post_type ] ?? [];
	$version_three_templates = $version_three_resource['templates'] ?? [];
	nova_content_context_test_assert( 4 === ( $version_three_migration['version'] ?? 0 ), 'Version 3 settings migrate to schema version 4.' );
	nova_content_context_test_assert(
		[ $default_template_id, $landing_template_slug ] === ( $version_three_templates['selected'] ?? [] )
		&& $default_template_id === ( $version_three_templates['primary'] ?? '' ),
		'Version 3 template contexts migrate to selected templates with the default template as primary.'
	);
	nova_content_context_test_assert(
		'' === ( $version_three_templates['items'][ $default_template_id ]['slug'] ?? null )
		&& 'Version 3 default context.' === ( $version_three_templates['items'][ $default_template_id ]['description'] ?? '' )
		&& 'template.default' === ( $version_three_templates['items'][ $default_template_id ]['mapping'] ?? '' ),
		'The version 3 default-template context migrates into its version 4 template item.'
	);
	nova_content_context_test_assert(
		$landing_template_slug === ( $version_three_templates['items'][ $landing_template_slug ]['slug'] ?? '' )
		&& 'Version 3 landing body.' === ( $version_three_templates['items'][ $landing_template_slug ]['fields']['/content']['description'] ?? '' )
		&& 'template.landing.body' === ( $version_three_templates['items'][ $landing_template_slug ]['fields']['/content']['mapping'] ?? '' )
		&& ! empty( $version_three_templates['items'][ $landing_template_slug ]['fields']['/content']['manual'] ),
		'Version 3 template-field overrides migrate to the matching version 4 template item.'
	);
	$legacy_template_pointers = array_filter(
		array_keys( (array) ( $version_three_resource['fields'] ?? [] ) ),
		static function ( $pointer ): bool {
			return 0 === strpos( (string) $pointer, '/@templates' );
		}
	);
	nova_content_context_test_assert( [] === array_values( $legacy_template_pointers ), 'Migrated version 3 template pseudo-fields are removed from the base field map.' );

	$valid_input           = [
		'version'   => 4,
		'resources' => [
			'post_type:' . $visible_post_type => [
				'id'        => 'post_type:' . $visible_post_type,
				'type'      => 'post_type',
				'post_type' => $visible_post_type,
				'enabled'   => true,
				'fields'    => [
					'/title'                       => [ 'description' => $raw_title_description, 'mapping' => 'content.title', 'manual' => false ],
					'/content'                     => [ 'description' => $content_description, 'mapping' => 'content.body', 'manual' => false ],
					'/excerpt'                     => [ 'description' => 'Vat de kern samen.', 'mapping' => 'content.summary', 'manual' => false ],
					'/slug'                        => [ 'description' => '', 'mapping' => 'routing.slug', 'manual' => false ],
					'/meta/' . $subtitle_meta      => [ 'description' => $subtitle_description, 'manual' => false ],
					'/meta/' . $faq_meta . '/*/question' => [ 'description' => 'Formuleer een concrete vraag.', 'manual' => false ],
					'/meta/' . $faq_meta . '/*/answer'   => [ 'description' => 'Geef een volledig antwoord.', 'manual' => false ],
				],
				'templates' => [
					'selected' => [ $default_template_id, $landing_template_slug, $resource_template_slug ],
					'primary'  => $landing_template_slug,
					'items'    => [
						$default_template_id => [
							'slug'        => '',
							'description' => $default_template_description,
							'mapping'     => 'template.default',
							'fields'      => [
								'/content' => [ 'description' => $default_content_description, 'mapping' => 'template.default.body', 'manual' => false ],
							],
						],
						$landing_template_slug => [
							'slug'        => $landing_template_slug,
							'description' => $landing_template_description,
							'mapping'     => 'template.landing',
							'fields'      => [
								'/content' => [ 'description' => $landing_content_description, 'mapping' => 'template.landing.body', 'manual' => false ],
							],
						],
						$resource_template_slug => [
							'slug'        => $resource_template_slug,
							'description' => $resource_template_description,
							'mapping'     => 'template.resource',
							'fields'      => [
								'/content' => [ 'description' => $resource_content_description, 'mapping' => 'template.resource.body', 'manual' => false ],
							],
						],
					],
				],
			],
			'post_type:' . $collision_post_type => [
				'id'        => 'post_type:' . $collision_post_type,
				'type'      => 'post_type',
				'post_type' => $collision_post_type,
				'enabled'   => true,
				'fields'    => [
					'/title' => [ 'description' => 'This must never replace a third-party field shape.', 'manual' => false ],
				],
			],
			'post_type:' . $application_post_type => [
				'id'        => 'post_type:' . $application_post_type,
				'type'      => 'post_type',
				'post_type' => $application_post_type,
				'enabled'   => false,
				'fields'    => [],
			],
			$regex_resource_id => [
				'id'      => $regex_resource_id,
				'type'    => 'route',
				'route'   => $regex_route,
				'methods' => [ 'PATCH' ],
				'fields'  => [
					'/content' => [ 'description' => $content_description, 'manual' => false ],
				],
			],
			$opaque_resource_id => [
				'id'      => $opaque_resource_id,
				'type'    => 'route',
				'route'   => $opaque_route,
				'methods' => [ 'POST' ],
				'fields'  => [
					'/content' => [ 'description' => 'Legacy arbitrary route context.', 'mapping' => 'legacy.body', 'manual' => false ],
				],
			],
		],
	];

	$sanitized = Nova_Bridge_Suite_Content_Context::sanitize_settings(
		[
			'payload' => wp_slash( wp_json_encode( $valid_input ) ),
		]
	);

	$sanitized_title = $sanitized['resources'][ 'post_type:' . $visible_post_type ]['fields']['/title']['description'] ?? '';
	nova_content_context_test_assert( $title_description === $sanitized_title, 'The sanitizer preserves Unicode and line breaks while stripping HTML.' );
	nova_content_context_test_assert( 4 === ( $sanitized['version'] ?? 0 ), 'The canonical saved configuration uses schema version 4.' );
	nova_content_context_test_assert( 'content.title' === ( $sanitized['resources'][ 'post_type:' . $visible_post_type ]['fields']['/title']['mapping'] ?? '' ), 'A NOVA content mapping persists beside its field description.' );
	nova_content_context_test_assert( '' === ( $sanitized['resources'][ 'post_type:' . $visible_post_type ]['fields']['/slug']['description'] ?? null ) && 'routing.slug' === ( $sanitized['resources'][ 'post_type:' . $visible_post_type ]['fields']['/slug']['mapping'] ?? '' ), 'A mapping-only field remains persisted.' );
	nova_content_context_test_assert( isset( $sanitized['resources'][ 'post_type:' . $application_post_type ] ) && empty( $sanitized['resources'][ 'post_type:' . $application_post_type ]['enabled'] ), 'A disabled post type with no descriptions remains persisted.' );
	nova_content_context_test_assert( $regex_route === ( $sanitized['resources'][ $regex_resource_id ]['route'] ?? '' ), 'The sanitizer preserves registered REST regex routes exactly.' );
	nova_content_context_test_assert( $opaque_route === ( $sanitized['resources'][ $opaque_resource_id ]['route'] ?? '' ) && 'legacy.body' === ( $sanitized['resources'][ $opaque_resource_id ]['fields']['/content']['mapping'] ?? '' ), 'An arbitrary legacy route remains persisted even though lean discovery does not display it.' );
	$sanitized_templates = $sanitized['resources'][ 'post_type:' . $visible_post_type ]['templates'] ?? [];
	nova_content_context_test_assert(
		[ $default_template_id, $landing_template_slug, $resource_template_slug ] === ( $sanitized_templates['selected'] ?? [] )
		&& $landing_template_slug === ( $sanitized_templates['primary'] ?? '' ),
		'Multiple selected templates and their primary template survive schema version 4 normalization.'
	);
	nova_content_context_test_assert(
		$landing_content_description === ( $sanitized_templates['items'][ $landing_template_slug ]['fields']['/content']['description'] ?? '' )
		&& 'template.landing.body' === ( $sanitized_templates['items'][ $landing_template_slug ]['fields']['/content']['mapping'] ?? '' ),
		'Template-specific field guidance and mapping survive schema version 4 normalization.'
	);

	if ( $option_existed ) {
		update_option( $option_name, $sanitized );
	} else {
		add_option( $option_name, $sanitized, '', 'no' );
	}

	nova_content_context_test_assert( $sanitized === Nova_Bridge_Suite_Content_Context::get_option(), 'The canonical configuration round-trips through get_option().' );

	$empty_primary_input = $valid_input;
	$empty_primary_input['resources'][ 'post_type:' . $visible_post_type ]['templates']['primary'] = '';
	$empty_primary_result = Nova_Bridge_Suite_Content_Context::sanitize_settings(
		[
			'payload' => wp_slash( wp_json_encode( $empty_primary_input ) ),
		]
	);
	nova_content_context_test_assert(
		$default_template_id === ( $empty_primary_result['resources'][ 'post_type:' . $visible_post_type ]['templates']['primary'] ?? '' ),
		'An explicitly empty primary is deterministically inferred when selected templates are present.'
	);

	$mismatched_template_id_input = $valid_input;
	$mismatched_template_id_input['resources'][ 'post_type:' . $visible_post_type ]['templates']['items'][ $landing_template_slug ]['id'] = 'templates/not-landing.php';
	$mismatched_template_id_result = Nova_Bridge_Suite_Content_Context::sanitize_settings(
		[
			'payload' => wp_slash( wp_json_encode( $mismatched_template_id_input ) ),
		]
	);
	nova_content_context_test_assert( $sanitized === $mismatched_template_id_result, 'A template identifier that differs from its slug-derived identifier rejects the complete save.' );
	nova_content_context_test_assert( $sanitized === Nova_Bridge_Suite_Content_Context::get_option(), 'Rejected mismatched template identifiers do not replace the canonical option.' );

	$blank_template_context_input = $valid_input;
	$blank_template_context_input['resources'][ 'post_type:' . $visible_post_type ]['templates']['items'][ $resource_template_slug ]['description'] = '';
	$blank_template_context_result = Nova_Bridge_Suite_Content_Context::sanitize_settings(
		[
			'payload' => wp_slash( wp_json_encode( $blank_template_context_input ) ),
		]
	);
	nova_content_context_test_assert( $sanitized === $blank_template_context_result, 'Multiple selected templates reject the complete save when any selected template lacks usage guidance.' );
	nova_content_context_test_assert( $sanitized === Nova_Bridge_Suite_Content_Context::get_option(), 'Rejected blank multi-template guidance does not replace the canonical option.' );

	$invalid_input = $valid_input;
	$invalid_input['resources'][ 'post_type:' . $visible_post_type ]['fields']['/meta/~invalid'] = [
		'description' => 'This must be rejected.',
		'manual'      => true,
	];
	$invalid_result = Nova_Bridge_Suite_Content_Context::sanitize_settings(
		[
			'payload' => wp_slash( wp_json_encode( $invalid_input ) ),
		]
	);
	nova_content_context_test_assert( $sanitized === $invalid_result, 'An invalid JSON Pointer rejects the complete save and preserves the old option.' );
	nova_content_context_test_assert( $sanitized === Nova_Bridge_Suite_Content_Context::get_option(), 'Rejected settings do not mutate the stored option.' );

	$invalid_primary_input = $valid_input;
	$invalid_primary_input['resources'][ 'post_type:' . $visible_post_type ]['templates']['primary'] = 'templates/not-selected.php';
	$invalid_primary_result = Nova_Bridge_Suite_Content_Context::sanitize_settings(
		[
			'payload' => wp_slash( wp_json_encode( $invalid_primary_input ) ),
		]
	);
	nova_content_context_test_assert( $sanitized === $invalid_primary_result, 'A primary template that is not selected rejects the complete save.' );
	nova_content_context_test_assert( $sanitized === Nova_Bridge_Suite_Content_Context::get_option(), 'Rejected primary-template settings preserve the canonical option.' );

	$fixture_post_id = wp_insert_post(
		[
			'post_type'    => $visible_post_type,
			'post_status'  => 'publish',
			'post_title'   => 'NOVA context fixture',
			'post_content' => 'Fixture body.',
			'post_excerpt' => 'Fixture excerpt.',
			'post_author'  => $admin_id,
		],
		true
	);
	nova_content_context_test_assert( ! is_wp_error( $fixture_post_id ) && $fixture_post_id > 0, 'The visible fixture post was created.' );
	$fixture_post_id = (int) $fixture_post_id;
	update_post_meta( $fixture_post_id, '_wp_page_template', $landing_template_slug );
	nova_content_context_test_assert( $landing_template_slug === get_page_template_slug( $fixture_post_id ), 'The visible fixture uses the selected landing template.' );
	$matching_builder_pointer = '/@builders/gutenberg/documents/' . $fixture_post_id . '/fields/scoped-fixture';
	$other_builder_pointer = '/@builders/gutenberg/documents/999999999/fields/other-fixture';
	$template_matching_builder_description = 'Landing-template guidance for this concrete builder document.';
	$template_matching_builder_mapping = 'template.landing.current_document';
	$template_other_builder_description = 'Landing-template guidance for a different builder document.';
	$template_other_builder_mapping = 'template.landing.other_document';
	$scoped_config = Nova_Bridge_Suite_Content_Context::get_option();
	$scoped_config['resources'][ 'post_type:' . $visible_post_type ]['fields'][ $matching_builder_pointer ] = [
		'description' => 'Guidance for this concrete builder document.',
		'mapping'     => 'builder.current_document',
		'manual'      => false,
	];
	$scoped_config['resources'][ 'post_type:' . $visible_post_type ]['fields'][ $other_builder_pointer ] = [
		'description' => 'Guidance for a different concrete builder document.',
		'mapping'     => 'builder.other_document',
		'manual'      => false,
	];
	$scoped_config['resources'][ 'post_type:' . $visible_post_type ]['templates']['items'][ $landing_template_slug ]['fields'][ $matching_builder_pointer ] = [
		'description' => $template_matching_builder_description,
		'mapping'     => $template_matching_builder_mapping,
		'manual'      => false,
	];
	$scoped_config['resources'][ 'post_type:' . $visible_post_type ]['templates']['items'][ $landing_template_slug ]['fields'][ $other_builder_pointer ] = [
		'description' => $template_other_builder_description,
		'mapping'     => $template_other_builder_mapping,
		'manual'      => false,
	];
	update_option( $option_name, Nova_Bridge_Suite_Content_Context::sanitize_settings( $scoped_config ) );

	$hidden_post_id = wp_insert_post(
		[
			'post_type'    => $hidden_post_type,
			'post_status'  => 'draft',
			'post_title'   => 'NOVA hidden Gutenberg fixture',
			'post_content' => '<!-- wp:paragraph --><p>Hidden block content.</p><!-- /wp:paragraph -->',
			'post_author'  => $admin_id,
		],
		true
	);
	nova_content_context_test_assert( ! is_wp_error( $hidden_post_id ) && $hidden_post_id > 0, 'The REST-disabled Gutenberg fixture post was created.' );
	$hidden_post_id = (int) $hidden_post_id;

	$collision_post_id = wp_insert_post(
		[
			'post_type'    => $collision_post_type,
			'post_status'  => 'publish',
			'post_title'   => 'NOVA collision fixture',
			'post_content' => 'Collision fixture body.',
			'post_author'  => $admin_id,
		],
		true
	);
	nova_content_context_test_assert( ! is_wp_error( $collision_post_id ) && $collision_post_id > 0, 'The collision fixture post was created.' );
	$collision_post_id = (int) $collision_post_id;

	update_post_meta( $fixture_post_id, $subtitle_meta, 'Fixture subtitle' );
	update_post_meta(
		$fixture_post_id,
		$faq_meta,
		[
			[
				'question' => 'Fixture question?',
				'answer'   => 'Fixture answer.',
			],
		]
	);

	$server = rest_get_server();

	// Some WP-CLI stacks initialize REST before eval-file runs. In that case the
	// normal rest_api_init pass could not have seen this temporary post type, so
	// register only its standard controller and refresh NOVA's late hooks.
	if ( $rest_init_at_start > 0 ) {
		foreach ( [ $visible_result, $collision_result, $private_result ] as $late_post_type ) {
			$controller_class = ! empty( $late_post_type->rest_controller_class )
				? (string) $late_post_type->rest_controller_class
				: 'WP_REST_Posts_Controller';

			nova_content_context_test_assert( class_exists( $controller_class ), 'A fixture REST controller is available for late WP-CLI registration.' );
			$fixture_controller = new $controller_class( $late_post_type->name );
			$fixture_controller->register_routes();
		}
		Nova_Bridge_Suite_Content_Context::register_post_type_prepare_filters();
		Nova_Bridge_Suite_Content_Context::register_rest_api();
	}

	nova_content_context_test_assert( $server instanceof WP_REST_Server, 'The WordPress REST server initialized.' );
	global $wp_rest_additional_fields;
	foreach ( [ 'meta_descriptions', 'nova_content_mappings', 'nova_template_contexts' ] as $readonly_field_name ) {
		$readonly_field_registration = $wp_rest_additional_fields[ $visible_post_type ][ $readonly_field_name ] ?? [];
		$readonly_field_schema       = is_array( $readonly_field_registration['schema'] ?? null ) ? $readonly_field_registration['schema'] : [];
		nova_content_context_test_assert(
			is_array( $readonly_field_registration )
			&& is_callable( $readonly_field_registration['get_callback'] ?? null )
			&& empty( $readonly_field_registration['update_callback'] )
			&& true === ( $readonly_field_schema['readonly'] ?? null )
			&& 'object' === ( $readonly_field_schema['type'] ?? null )
			&& [ 'edit' ] === ( $readonly_field_schema['context'] ?? null ),
			$readonly_field_name . ' is an object available only in edit context, with a strict read-only schema and no write callback.'
		);
	}
	$rest_init_after_server = did_action( 'rest_api_init' );
	$expected_rest_init_count = $rest_init_at_start > 0 ? $rest_init_at_start : 1;
	nova_content_context_test_assert( $expected_rest_init_count === $rest_init_after_server, 'rest_api_init ran exactly once in this process.' );
	nova_content_context_test_assert( $server === rest_get_server() && $rest_init_after_server === did_action( 'rest_api_init' ), 'Repeated server access does not initialize REST routes again.' );

	$discovery_rest_route = '/nova-bridge/v1/content-endpoints';
	$registered_routes    = $server->get_routes();
	if ( isset( $registered_routes[ $discovery_rest_route ] ) ) {
		$admin_discovery_response = rest_do_request( new WP_REST_Request( 'GET', $discovery_rest_route ) );
		$admin_discovery_data     = $admin_discovery_response->get_data();
		nova_content_context_test_assert(
			200 === $admin_discovery_response->get_status()
			&& 4 === ( $admin_discovery_data['version'] ?? 0 )
			&& is_array( $admin_discovery_data['resources'] ?? null ),
			'An administrator can read the registered content-endpoint discovery route.'
		);

		wp_set_current_user( 0 );
		$anonymous_discovery_response = rest_do_request( new WP_REST_Request( 'GET', $discovery_rest_route ) );
		$anonymous_discovery_data     = $anonymous_discovery_response->get_data();
		nova_content_context_test_assert(
			401 === $anonymous_discovery_response->get_status()
			&& 'nova_content_context_unauthenticated' === ( $anonymous_discovery_data['code'] ?? '' ),
			'Anonymous users cannot read the registered content-endpoint discovery route.'
		);
		wp_set_current_user( $admin_id );
	} else {
		WP_CLI::line( 'SKIP  The content-endpoint discovery REST route is not registered in this bootstrap.' );
	}

	$managed_blog_types = nova_content_context_test_managed_blog_post_types();
	$force_owned_include = static function ( $include, $post_type, $signals ) {
		return ! empty( $signals['suite_owned'] ) ? true : $include;
	};
	add_filter( 'nova_bridge_suite_content_context_include_post_type', $force_owned_include, 999, 3 );
	try {
		$discovery = Nova_Bridge_Suite_Content_Context::discover_resources();
	} finally {
		remove_filter( 'nova_bridge_suite_content_context_include_post_type', $force_owned_include, 999 );
	}
	$visible   = nova_content_context_test_resource( $discovery, 'post_type:' . $visible_post_type );
	$hidden    = nova_content_context_test_resource( $discovery, 'post_type:' . $hidden_post_type );
	$opaque    = nova_content_context_test_resource( $discovery, $opaque_resource_id );
	$editable  = nova_content_context_test_resource( $discovery, $editable_resource_id );
	$application = nova_content_context_test_resource( $discovery, 'post_type:' . $application_post_type );
	$infrastructure = nova_content_context_test_resource( $discovery, 'post_type:' . $infrastructure_post_type );
	$private_editorial = nova_content_context_test_resource( $discovery, 'post_type:' . $private_post_type );
	$private_hidden_editorial = nova_content_context_test_resource( $discovery, 'post_type:' . $private_hidden_post_type );

	nova_content_context_test_assert( 4 === ( $discovery['version'] ?? 0 ), 'Discovery reports schema version 4.' );

	nova_content_context_test_assert( is_array( $visible ), 'Discovery includes the REST-enabled fixture post type.' );
	nova_content_context_test_assert( is_array( nova_content_context_test_resource( $discovery, 'post_type:post' ) ), 'Discovery includes the native Posts endpoint.' );
	nova_content_context_test_assert( is_array( nova_content_context_test_resource( $discovery, 'post_type:page' ) ), 'Discovery includes the native Pages endpoint.' );
	nova_content_context_test_assert( 'content_model' === ( $visible['scope'] ?? '' ) && 'editorial' === ( $visible['content_kind'] ?? '' ) && ! empty( $visible['selected'] ), 'An editorial CPT is selected as a primary content model.' );
	nova_content_context_test_assert( '/nova-fixture/v1/articles' === ( $visible['route'] ?? '' ), 'Discovery reports the fixture custom namespace and REST base.' );
	$visible_methods = isset( $visible['methods'] ) && is_array( $visible['methods'] ) ? $visible['methods'] : ( $visible['write_methods'] ?? [] );
	nova_content_context_test_assert( in_array( 'POST', $visible_methods, true ), 'Discovery verifies the fixture collection POST method.' );
	nova_content_context_test_assert( in_array( 'PUT', $visible_methods, true ) || in_array( 'PATCH', $visible_methods, true ), 'Discovery verifies an item update method.' );

	$expected_native_fields = [
		'/content',
		'/excerpt',
		'/meta/' . $faq_meta . '/*/answer',
		'/meta/' . $faq_meta . '/*/question',
		'/meta/' . $hidden_meta,
		'/meta/' . $subtitle_meta,
		'/slug',
		'/template',
		'/title',
	];
	sort( $expected_native_fields, SORT_NATURAL | SORT_FLAG_CASE );
	$actual_native_fields = [];
	foreach ( (array) ( $visible['fields'] ?? [] ) as $pointer => $field ) {
		if ( is_array( $field ) && in_array( (string) ( $field['source'] ?? '' ), [ 'core', 'meta' ], true ) ) {
			$actual_native_fields[] = (string) $pointer;
		}
	}
	sort( $actual_native_fields, SORT_NATURAL | SORT_FLAG_CASE );
	nova_content_context_test_assert(
		$expected_native_fields === $actual_native_fields,
		'The editorial fixture exposes exactly title, content, excerpt, slug, template, and its relevant registered meta leaves. Expected ' . wp_json_encode( $expected_native_fields ) . '; got ' . wp_json_encode( $actual_native_fields ) . '.'
	);
	foreach ( [ '/status', '/date', '/date_gmt', '/author', '/password', '/comment_status', '/ping_status', '/sticky' ] as $excluded_pointer ) {
		nova_content_context_test_assert( ! nova_content_context_test_has_field( $visible, $excluded_pointer ), 'Discovery omits non-content control field ' . $excluded_pointer . '.' );
	}
	nova_content_context_test_assert( 'content.title' === ( $visible['fields']['/title']['saved_mapping'] ?? '' ) && 'routing.slug' === ( $visible['fields']['/slug']['saved_mapping'] ?? '' ), 'Discovery attaches saved NOVA mappings to their lean field records.' );
	$discovered_templates = is_array( $visible['templates'] ?? null ) ? $visible['templates'] : [];
	$discovered_default_template = nova_content_context_test_discovered_template( $visible, $default_template_id );
	$discovered_landing_template = nova_content_context_test_discovered_template( $visible, $landing_template_slug );
	$discovered_resource_template = nova_content_context_test_discovered_template( $visible, $resource_template_slug );
	nova_content_context_test_assert(
		is_array( $discovered_default_template )
		&& is_array( $discovered_landing_template )
		&& is_array( $discovered_resource_template ),
		'Discovery reports every fixture theme template as an available selection.'
	);
	nova_content_context_test_assert(
		'' === ( $discovered_default_template['slug'] ?? null )
		&& 'NOVA Landing' === ( $discovered_landing_template['label'] ?? '' )
		&& 'NOVA Resource' === ( $discovered_resource_template['label'] ?? '' )
		&& ! empty( $discovered_default_template['selected'] )
		&& ! empty( $discovered_landing_template['selected'] )
		&& ! empty( $discovered_resource_template['selected'] )
		&& ! empty( $discovered_landing_template['primary'] )
		&& empty( $discovered_default_template['primary'] )
		&& empty( $discovered_resource_template['primary'] ),
		'Discovery attaches the saved multiple-template selection and primary template to the matching live choices.'
	);
	nova_content_context_test_assert(
		$default_template_description === ( $discovered_default_template['saved_description'] ?? '' )
		&& $landing_template_description === ( $discovered_landing_template['saved_description'] ?? '' )
		&& 'template.resource' === ( $discovered_resource_template['saved_mapping'] ?? '' ),
		'Discovery attaches saved endpoint-level context and mappings to selected template choices.'
	);
	nova_content_context_test_assert(
		3 <= count( $discovered_templates )
		&& 3 === (int) ( $visible['capabilities']['templates']['selected'] ?? 0 ),
		'Discovery summarizes all three selected template choices without turning them into fields.'
	);
	$discovered_template_pointers = array_filter(
		array_keys( (array) ( $visible['fields'] ?? [] ) ),
		static function ( $pointer ): bool {
			return 0 === strpos( (string) $pointer, '/@templates' );
		}
	);
	nova_content_context_test_assert( [] === array_values( $discovered_template_pointers ), 'Discovery does not inflate the field inventory with an @templates cross-product.' );
	if ( $acf_fixture_added ) {
		$simple_acf = $visible['fields']['/acf/nova_acf_simple'] ?? [];
		$nested_acf = $visible['fields']['/acf/nova_acf_group/heading'] ?? [];
		$repeater_acf = $visible['fields']['/acf/nova_acf_rows/*/copy'] ?? [];
		nova_content_context_test_assert(
			'available' === ( $simple_acf['availability'] ?? '' )
			&& 'nova_meta_bridge' === ( $simple_acf['transport'] ?? '' )
			&& '/meta_all/nova_acf_simple' === ( $simple_acf['request_path'] ?? '' ),
			'A simple top-level ACF field is writable through its deterministic NOVA meta bridge path.'
		);
		foreach ( [ $nested_acf, $repeater_acf ] as $complex_acf ) {
			nova_content_context_test_assert(
				'potential' === ( $complex_acf['availability'] ?? '' )
				&& empty( $complex_acf['writable'] )
				&& 'acf_nested_payload_required' === ( $complex_acf['reason'] ?? '' )
				&& 0 === strpos( (string) ( $complex_acf['request_path'] ?? '' ), '/meta_all/acf/' ),
				'A nested ACF leaf requires a deterministic whole-parent payload and is never advertised as directly writable.'
			);
		}
	}
	$catalog_builder_fields = array_filter(
		(array) ( $visible['fields'] ?? [] ),
		static function ( $field ): bool {
			return is_array( $field ) && 'builder' === ( $field['source'] ?? '' );
		}
	);
	nova_content_context_test_assert( [] === array_values( $catalog_builder_fields ), 'Endpoint discovery does not inflate cards with global builder catalogs.' );
	$hidden_meta_field = $visible['fields'][ '/meta/' . $hidden_meta ] ?? [];
	nova_content_context_test_assert(
		'available' === ( $hidden_meta_field['availability'] ?? '' )
		&& 'nova_meta_bridge' === ( $hidden_meta_field['transport'] ?? '' )
		&& '/meta_all/' . $hidden_meta === ( $hidden_meta_field['request_path'] ?? '' ),
		'Registered meta excluded from core REST is shown as writable through NOVA\'s meta bridge.'
	);
	$nested_meta_field = $visible['fields'][ '/meta/' . $faq_meta . '/*/question' ] ?? [];
	nova_content_context_test_assert(
		'potential' === ( $nested_meta_field['availability'] ?? '' )
		&& empty( $nested_meta_field['writable'] )
		&& 'meta_nested_payload_required' === ( $nested_meta_field['reason'] ?? '' )
		&& '/meta/' . $faq_meta === ( $nested_meta_field['request_path'] ?? '' ),
		'A nested registered-meta leaf requires the complete parent value and is never advertised as an independent patch target.'
	);

	nova_content_context_test_assert( is_array( $hidden ), 'Discovery includes the REST-disabled fixture post type.' );
	nova_content_context_test_assert( 'show_in_rest_disabled' === ( $hidden['reason'] ?? '' ), 'The hidden fixture has the exact show_in_rest_disabled reason.' );
	nova_content_context_test_assert( empty( $hidden['route'] ) && empty( $hidden['write_routes'] ), 'The hidden fixture does not advertise an invented writable route.' );
	nova_content_context_test_assert( empty( $hidden['usable'] ) && empty( $hidden['writable'] ), 'The hidden fixture is explicitly unavailable for API writes.' );
	nova_content_context_test_assert( null === $opaque && null === $editable, 'Arbitrary writable routes are absent from lean endpoint discovery, regardless of schema quality.' );
	nova_content_context_test_assert( null === $application, 'A title-only country-style CPT is absent from endpoint discovery.' );
	nova_content_context_test_assert( null === $infrastructure, 'An infrastructure-style payment CPT is absent from endpoint discovery.' );
	nova_content_context_test_assert( is_array( $private_editorial ) && ! empty( $private_editorial['usable'] ), 'A private, titleless, REST-writable editorial CPT remains available for endpoint selection.' );
	nova_content_context_test_assert(
		is_array( $private_hidden_editorial )
		&& empty( $private_hidden_editorial['usable'] )
		&& empty( $private_hidden_editorial['writable'] )
		&& 'show_in_rest_disabled' === ( $private_hidden_editorial['reason'] ?? '' ),
		'A private show_ui editorial CPT remains discoverable as unusable when show_in_rest is disabled.'
	);
	nova_content_context_test_assert( nova_content_context_test_has_field( $visible, '/private_topics' ), 'A private taxonomy with a verified REST assignment field remains in the endpoint inventory.' );
	$locked_taxonomy_field = $visible['fields']['/locked_topics'] ?? [];
	nova_content_context_test_assert(
		nova_content_context_test_has_field( $visible, '/locked_topics' )
		&& empty( $locked_taxonomy_field['writable'] )
		&& 'potential' === ( $locked_taxonomy_field['availability'] ?? '' )
		&& 'taxonomy_assignment_forbidden' === ( $locked_taxonomy_field['reason'] ?? '' ),
		'A REST taxonomy is not advertised as writable when the current administrator lacks its assign_terms capability.'
	);
	nova_content_context_test_assert( null === nova_content_context_test_resource( $discovery, 'post_type:attachment' ), 'The built-in attachment post type is absent from endpoint discovery.' );
	nova_content_context_test_assert( null === nova_content_context_test_resource( $discovery, 'post_type:product' ), 'The WooCommerce product post type is absent from endpoint discovery.' );
	if ( class_exists( 'SEORAI\\ServicePageCPT\\Plugin', false ) && post_type_exists( 'service_page' ) ) {
		nova_content_context_test_assert(
			null === nova_content_context_test_resource( $discovery, 'post_type:service_page' ),
			'NOVA\'s Service CPT is absent from lean discovery because its dedicated API context is already built in.'
		);
	}
	foreach ( $managed_blog_types as $managed_blog_type ) {
		nova_content_context_test_assert(
			null === nova_content_context_test_resource( $discovery, 'post_type:' . $managed_blog_type->name ),
			'NOVA-managed Blog CPT ' . $managed_blog_type->name . ' is absent from lean discovery because its dedicated API context is already built in.'
		);
	}
	$route_cards = array_filter(
		(array) ( $discovery['resources'] ?? [] ),
		static function ( $resource ): bool {
			return is_array( $resource ) && 'route' === ( $resource['type'] ?? '' );
		}
	);
	nova_content_context_test_assert( [] === array_values( $route_cards ), 'Lean discovery contains no standalone route cards.' );

	if ( taxonomy_exists( 'product_cat' ) ) {
		$product_category_cards = array_values(
			array_filter(
				(array) ( $discovery['resources'] ?? [] ),
				static function ( $resource ): bool {
					return is_array( $resource ) && 'taxonomy:product_cat' === ( $resource['id'] ?? '' );
				}
			)
		);
		nova_content_context_test_assert( 1 === count( $product_category_cards ), 'WooCommerce product categories appear as exactly one logical endpoint card.' );
		$product_categories = $product_category_cards[0];
		nova_content_context_test_assert( 'taxonomy' === ( $product_categories['type'] ?? '' ) && 'product_cat' === ( $product_categories['taxonomy'] ?? '' ), 'The product-category card has canonical taxonomy identity.' );
		foreach ( [ '/name', '/description', '/slug', '/parent' ] as $pointer ) {
			nova_content_context_test_assert( nova_content_context_test_has_field( $product_categories, $pointer ), 'Product-category discovery includes native field ' . $pointer . '.' );
		}
		foreach ( [ '/name', '/description', '/slug' ] as $pointer ) {
			nova_content_context_test_assert( 'core' === ( $product_categories['fields'][ $pointer ]['group'] ?? '' ), 'Product-category field ' . $pointer . ' is grouped as the category\'s core content.' );
		}
		nova_content_context_test_assert(
			'taxonomy' === ( $product_categories['fields']['/name']['source'] ?? '' )
			&& 'core' === ( $product_categories['fields']['/name']['group'] ?? '' ),
			'Product-category grouping is independent from the field\'s taxonomy provenance.'
		);
		nova_content_context_test_assert( 'media_taxonomy' === ( $product_categories['fields']['/parent']['group'] ?? '' ), 'The parent category is grouped as category structure.' );

		$woocommerce_transport = null;
		foreach ( (array) ( $product_categories['transports'] ?? [] ) as $transport ) {
			if ( is_array( $transport ) && 'woocommerce' === ( $transport['id'] ?? '' ) ) {
				$woocommerce_transport = $transport;
				break;
			}
		}
		if ( is_array( $woocommerce_transport ) && ! empty( $woocommerce_transport['available'] ) ) {
			if ( nova_content_context_test_has_field( $product_categories, '/display' ) ) {
				nova_content_context_test_assert( 'core' === ( $product_categories['fields']['/display']['group'] ?? '' ), 'The WooCommerce display type is grouped with core category settings.' );
			}
			foreach ( [ '/display', '/image/id', '/image/src', '/image/name', '/image/alt' ] as $pointer ) {
				nova_content_context_test_assert( nova_content_context_test_has_field( $product_categories, $pointer ), 'The writable WooCommerce transport exposes ' . $pointer . '.' );
			}
			foreach ( [ '/image/id', '/image/src', '/image/name', '/image/alt' ] as $pointer ) {
				nova_content_context_test_assert( 'media_taxonomy' === ( $product_categories['fields'][ $pointer ]['group'] ?? '' ), 'Product-category image field ' . $pointer . ' remains grouped as media.' );
			}
		}
		foreach ( [ '/id', '/date_created', '/date_modified', '/batch' ] as $pointer ) {
			nova_content_context_test_assert( ! nova_content_context_test_has_field( $product_categories, $pointer ), 'Product-category discovery omits response/control field ' . $pointer . '.' );
		}
		nova_content_context_test_assert(
			! nova_content_context_test_has_field( $product_categories, '/meta/content_below_products' )
			&& ! nova_content_context_test_has_field( $product_categories, '/meta_all/content_below_products' ),
			'The native content-below-products field is not duplicated as a competing meta alias.'
		);

		$taxonomy_normalization = Nova_Bridge_Suite_Content_Context::sanitize_settings(
			[
				'version'   => 4,
				'resources' => [
					'taxonomy:not-canonical' => [
						'id'       => 'taxonomy:not-canonical',
						'type'     => 'taxonomy',
						'taxonomy' => 'product_cat',
						'enabled'  => true,
						'fields'   => [
							'/description' => [ 'description' => 'Category copy.', 'mapping' => 'taxonomy.description', 'manual' => false ],
						],
					],
				],
			]
		);
		nova_content_context_test_assert( isset( $taxonomy_normalization['resources']['taxonomy:product_cat'] ) && ! isset( $taxonomy_normalization['resources']['taxonomy:not-canonical'] ), 'Taxonomy settings normalize to the stable taxonomy:product_cat resource ID.' );
		nova_content_context_test_assert( 'taxonomy.description' === ( $taxonomy_normalization['resources']['taxonomy:product_cat']['fields']['/description']['mapping'] ?? '' ), 'Taxonomy field mappings survive normalization.' );
	}

	$item_route  = '/nova-fixture/v1/articles/' . $fixture_post_id;
	$admin_view  = new WP_REST_Request( 'GET', $item_route );
	$admin_view->set_param( 'context', 'view' );
	$admin_view_result = rest_do_request( $admin_view );
	$admin_view_data   = $admin_view_result->get_data();
	nova_content_context_test_assert(
		200 === $admin_view_result->get_status()
		&& ! array_key_exists( 'meta_descriptions', (array) $admin_view_data )
		&& ! array_key_exists( 'nova_content_mappings', (array) $admin_view_data )
		&& ! array_key_exists( 'nova_template_contexts', (array) $admin_view_data ),
		'An authenticated context=view response omits all private NOVA guidance, mappings, and template context.'
	);

	$admin_read  = new WP_REST_Request( 'GET', $item_route );
	$admin_read->set_param( 'context', 'edit' );
	$admin_result = rest_do_request( $admin_read );
	$admin_data   = $admin_result->get_data();
	nova_content_context_test_assert( 200 === $admin_result->get_status(), 'An administrator can read the fixture item through REST.' );
	nova_content_context_test_assert(
		isset( $admin_data['meta_descriptions']['/title'] ) && $title_description === $admin_data['meta_descriptions']['/title'],
		'An authenticated editable item receives its configured meta_descriptions.'
	);
	nova_content_context_test_assert(
		isset( $admin_data['nova_content_mappings']['/title'] ) && 'content.title' === $admin_data['nova_content_mappings']['/title'],
		'An authenticated editable item receives its configured NOVA content mappings.'
	);
	nova_content_context_test_assert(
		$landing_content_description === ( $admin_data['meta_descriptions']['/content'] ?? '' )
		&& 'template.landing.body' === ( $admin_data['nova_content_mappings']['/content'] ?? '' ),
		'The active landing template overrides only its configured real API field in authenticated guidance and mappings.'
	);
	$template_contexts = is_array( $admin_data['nova_template_contexts'] ?? null ) ? $admin_data['nova_template_contexts'] : [];
	$selected_template_records = $template_contexts['selected'] ?? null;
	$default_template_record = nova_content_context_test_template_record( $template_contexts, $default_template_id );
	$landing_template_record = nova_content_context_test_template_record( $template_contexts, $landing_template_slug );
	$resource_template_record = nova_content_context_test_template_record( $template_contexts, $resource_template_slug );
	$template_context_keys = array_keys( $template_contexts );
	$template_record_keys  = is_array( $landing_template_record ) ? array_keys( $landing_template_record ) : [];
	sort( $template_context_keys, SORT_STRING );
	sort( $template_record_keys, SORT_STRING );
	nova_content_context_test_assert(
		[ 'current', 'primary', 'selected' ] === $template_context_keys
		&& [ 'context', 'current', 'id', 'label', 'mapping', 'primary', 'slug' ] === $template_record_keys,
		'nova_template_contexts exposes only the documented top-level keys and selected-record fields.'
	);
	nova_content_context_test_assert(
		is_array( $selected_template_records )
		&& array_values( $selected_template_records ) === $selected_template_records,
		'nova_template_contexts.selected is a sequential JSON-style list.'
	);
	$strict_template_flags = true;
	foreach ( (array) $selected_template_records as $selected_template_record ) {
		if (
			! is_array( $selected_template_record )
			|| ! array_key_exists( 'primary', $selected_template_record )
			|| ! array_key_exists( 'current', $selected_template_record )
			|| ! is_bool( $selected_template_record['primary'] )
			|| ! is_bool( $selected_template_record['current'] )
		) {
			$strict_template_flags = false;
			break;
		}
	}
	nova_content_context_test_assert( $strict_template_flags, 'Every selected-template primary/current flag is a strict boolean.' );
	nova_content_context_test_assert(
		$landing_template_slug === ( $template_contexts['primary'] ?? '' )
		&& $landing_template_slug === ( $template_contexts['current'] ?? '' )
		&& 3 === count( (array) ( $template_contexts['selected'] ?? [] ) ),
		'Authenticated edit context identifies the primary, current, and complete selected-template set.'
	);
	nova_content_context_test_assert(
		is_array( $default_template_record )
		&& '' === ( $default_template_record['slug'] ?? null )
		&& $default_template_description === ( $default_template_record['context'] ?? '' )
		&& 'template.default' === ( $default_template_record['mapping'] ?? '' )
		&& empty( $default_template_record['primary'] )
		&& empty( $default_template_record['current'] ),
		'The default template appears as a non-current selected context record.'
	);
	nova_content_context_test_assert(
		is_array( $landing_template_record )
		&& $landing_template_slug === ( $landing_template_record['slug'] ?? '' )
		&& 'NOVA Landing' === ( $landing_template_record['label'] ?? '' )
		&& $landing_template_description === ( $landing_template_record['context'] ?? '' )
		&& 'template.landing' === ( $landing_template_record['mapping'] ?? '' )
		&& ! empty( $landing_template_record['primary'] )
		&& ! empty( $landing_template_record['current'] ),
		'The active landing template is marked as both primary and current with its configured context.'
	);
	nova_content_context_test_assert(
		is_array( $resource_template_record )
		&& $resource_template_slug === ( $resource_template_record['slug'] ?? '' )
		&& 'NOVA Resource' === ( $resource_template_record['label'] ?? '' )
		&& empty( $resource_template_record['primary'] )
		&& empty( $resource_template_record['current'] ),
		'The second selected theme template is present without inheriting active-template flags.'
	);
	nova_content_context_test_assert(
		$template_matching_builder_description === ( $admin_data['meta_descriptions'][ $matching_builder_pointer ] ?? '' )
		&& $template_matching_builder_mapping === ( $admin_data['nova_content_mappings'][ $matching_builder_pointer ] ?? '' ),
		'The active template applies its builder guidance and mapping when the concrete source document matches.'
	);
	nova_content_context_test_assert(
		! isset( $admin_data['meta_descriptions'][ $other_builder_pointer ] )
		&& ! isset( $admin_data['nova_content_mappings'][ $other_builder_pointer ] ),
		'Template-scoped builder guidance and mappings for a different concrete document remain excluded.'
	);

	update_post_meta( $fixture_post_id, '_wp_page_template', $resource_template_slug );
	$resource_template_read = new WP_REST_Request( 'GET', $item_route );
	$resource_template_read->set_param( 'context', 'edit' );
	$resource_template_result = rest_do_request( $resource_template_read );
	$resource_template_data = $resource_template_result->get_data();
	nova_content_context_test_assert(
		200 === $resource_template_result->get_status()
		&& $resource_content_description === ( $resource_template_data['meta_descriptions']['/content'] ?? '' )
		&& 'template.resource.body' === ( $resource_template_data['nova_content_mappings']['/content'] ?? '' )
		&& $resource_template_slug === ( $resource_template_data['nova_template_contexts']['current'] ?? '' ),
		'Switching to the selected resource template applies only that template\'s field overrides and current-template context.'
	);
	nova_content_context_test_assert(
		$landing_content_description !== ( $resource_template_data['meta_descriptions']['/content'] ?? '' )
		&& 'template.landing.body' !== ( $resource_template_data['nova_content_mappings']['/content'] ?? '' ),
		'Landing-template overrides do not leak into a post using another selected template.'
	);
	update_post_meta( $fixture_post_id, '_wp_page_template', $landing_template_slug );

	$bridge_fields_route = '/nova-bridge/v1/content-endpoints/bridge-fields';
	$bridge_fields_request = new WP_REST_Request( 'GET', $bridge_fields_route );
	$bridge_fields_request->set_param( 'post_id', $fixture_post_id );
	$bridge_fields_result = rest_do_request( $bridge_fields_request );
	$bridge_fields_data   = $bridge_fields_result->get_data();
	nova_content_context_test_assert( 200 === $bridge_fields_result->get_status(), 'An editor can inspect actual bridge fields for an editable source document.' );
	nova_content_context_test_assert(
		$fixture_post_id === ( $bridge_fields_data['post_id'] ?? 0 )
		&& $visible_post_type === ( $bridge_fields_data['post_type'] ?? '' )
		&& is_array( $bridge_fields_data['providers'] ?? null )
		&& is_array( $bridge_fields_data['fields'] ?? null ),
		'The bridge inspector identifies its concrete source document and returns provider and field arrays.'
	);
	$reported_bridge_fields = 0;
	foreach ( (array) $bridge_fields_data['providers'] as $provider ) {
		if ( is_array( $provider ) && ! empty( $provider['available'] ) ) {
			$reported_bridge_fields += absint( $provider['field_count'] ?? 0 );
		}
	}
	nova_content_context_test_assert( count( $bridge_fields_data['fields'] ) === $reported_bridge_fields, 'Bridge provider counts match the actual-document field collection.' );
	foreach ( $bridge_fields_data['fields'] as $bridge_field ) {
		nova_content_context_test_assert(
			is_array( $bridge_field )
			&& 'builder' === ( $bridge_field['source'] ?? '' )
			&& 'actual_document' === ( $bridge_field['origin'] ?? '' )
			&& (string) $fixture_post_id === (string) ( $bridge_field['source_post_id'] ?? '' )
			&& 0 === strpos( (string) ( $bridge_field['path'] ?? '' ), '/@builders/' ),
			'Every inspected builder field is tied to the selected document and uses a bridge-shaped path.'
		);
	}
	$hidden_bridge_request = new WP_REST_Request( 'GET', $bridge_fields_route );
	$hidden_bridge_request->set_param( 'post_id', $hidden_post_id );
	$hidden_bridge_result = rest_do_request( $hidden_bridge_request );
	$hidden_bridge_data = $hidden_bridge_result->get_data();
	$hidden_gutenberg_field = $hidden_bridge_data['fields'][0] ?? [];
	nova_content_context_test_assert(
		200 === $hidden_bridge_result->get_status()
		&& 'gutenberg' === ( $hidden_gutenberg_field['builder'] ?? '' )
		&& empty( $hidden_gutenberg_field['writable'] )
		&& 'bridge_write_transport_unavailable' === ( $hidden_gutenberg_field['reason'] ?? '' ),
		'A builder document on a REST-disabled CPT is inspectable but never claims a nonexistent write transport.'
	);

	$admin_options_request = new WP_REST_Request( 'OPTIONS', $item_route );
	$admin_options         = rest_do_request( $admin_options_request );
	// rest_do_request() dispatches internally but does not run the outer
	// rest_post_dispatch filter used by a real HTTP REST response.
	$admin_options = apply_filters( 'rest_post_dispatch', $admin_options, $server, $admin_options_request );
	$admin_options_data = $admin_options->get_data();
	nova_content_context_test_assert( $admin_options->get_status() >= 200 && $admin_options->get_status() < 300, 'The fixture OPTIONS response is available to the administrator.' );
	nova_content_context_test_assert(
		isset( $admin_options_data['meta_descriptions']['/title'] ) && $title_description === $admin_options_data['meta_descriptions']['/title'],
		'Authenticated OPTIONS includes the configured field description.'
	);

	wp_set_current_user( 0 );
	$anonymous_result = rest_do_request( new WP_REST_Request( 'GET', $item_route ) );
	$anonymous_data   = $anonymous_result->get_data();
	nova_content_context_test_assert( 200 === $anonymous_result->get_status(), 'The published fixture remains anonymously readable.' );
	nova_content_context_test_assert(
		empty( $anonymous_data['meta_descriptions']['/title'] ),
		'Anonymous item responses do not receive administrator-authored guidance.'
	);
	nova_content_context_test_assert(
		empty( $anonymous_data['nova_content_mappings']['/title'] ),
		'Anonymous item responses do not receive administrator-authored mappings.'
	);
	nova_content_context_test_assert(
		empty( $anonymous_data['nova_template_contexts'] ),
		'Anonymous item responses do not receive selected-template context or primary-template metadata.'
	);
	$anonymous_bridge_request = new WP_REST_Request( 'GET', $bridge_fields_route );
	$anonymous_bridge_request->set_param( 'post_id', $fixture_post_id );
	$anonymous_bridge_result = rest_do_request( $anonymous_bridge_request );
	nova_content_context_test_assert( 401 === $anonymous_bridge_result->get_status(), 'Anonymous users cannot inspect a document\'s bridge field map.' );

	wp_set_current_user( $admin_id );
	$collision_route  = '/nova-fixture/v1/collision-articles/' . $collision_post_id;
	$collision_read   = new WP_REST_Request( 'GET', $collision_route );
	$collision_read->set_param( 'context', 'edit' );
	$collision_result_response = rest_do_request( $collision_read );
	$collision_data   = $collision_result_response->get_data();
	nova_content_context_test_assert( 200 === $collision_result_response->get_status(), 'The incompatible-field fixture remains readable.' );
	nova_content_context_test_assert( [ 'third_party' => 'shape' ] === ( $collision_data['meta_descriptions'] ?? null ), 'NOVA preserves a strict third-party object field without adding pointer keys.' );

	wp_set_current_user( 0 );
	$anonymous_options_request = new WP_REST_Request( 'OPTIONS', $item_route );
	$anonymous_options         = rest_do_request( $anonymous_options_request );
	$anonymous_options         = apply_filters( 'rest_post_dispatch', $anonymous_options, $server, $anonymous_options_request );
	$anonymous_options_json = wp_json_encode( $anonymous_options->get_data() );
	nova_content_context_test_assert(
		false === strpos( (string) $anonymous_options_json, $title_description )
		&& false === strpos( (string) $anonymous_options_json, $landing_template_description )
		&& false === strpos( (string) $anonymous_options_json, 'template.landing.body' ),
		'Anonymous OPTIONS does not leak administrator-authored base or template-specific context.'
	);

	wp_set_current_user( $admin_id );
	$option_before_write = Nova_Bridge_Suite_Content_Context::get_option();
	$post_state_keys     = [ 'post_author', 'post_status', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_parent', 'menu_order', 'comment_status', 'ping_status', 'post_password', 'post_mime_type' ];
	$post_before_write   = wp_array_slice_assoc( (array) get_post( $fixture_post_id, ARRAY_A ), $post_state_keys );
	$post_meta_before_write = get_post_meta( $fixture_post_id );
	$description_write   = new WP_REST_Request( 'PATCH', $item_route );
	$description_write->set_param(
		'meta_descriptions',
		[
			'/title' => 'A request must never persist this value.',
		]
	);
	$description_write->set_param(
		'nova_content_mappings',
		[
			'/title' => 'A request must never persist this mapping.',
		]
	);
	$description_write->set_param(
		'nova_template_contexts',
		[
			'primary' => $resource_template_slug,
			'current' => $resource_template_slug,
			'selected' => [],
		]
	);
	$description_write_result = rest_do_request( $description_write );
	$description_write_data   = $description_write_result->get_data();
	nova_content_context_test_assert(
		200 === $description_write_result->get_status()
		&& $fixture_post_id === (int) ( $description_write_data['id'] ?? 0 ),
		'A PATCH containing only NOVA read-only context fields succeeds without treating them as writable input.'
	);
	nova_content_context_test_assert(
		$option_before_write === Nova_Bridge_Suite_Content_Context::get_option(),
		'Posting meta_descriptions, nova_content_mappings, or nova_template_contexts cannot mutate the settings option.'
	);
	$post_after_write      = wp_array_slice_assoc( (array) get_post( $fixture_post_id, ARRAY_A ), $post_state_keys );
	$post_meta_after_write = get_post_meta( $fixture_post_id );
	nova_content_context_test_assert(
		$post_before_write === $post_after_write
		&& $post_meta_before_write === $post_meta_after_write,
		'A PATCH containing only NOVA read-only context fields does not mutate semantic post fields or post meta.'
	);

	$hidden_response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . $hidden_post_type ) );
	nova_content_context_test_assert( 404 === $hidden_response->get_status(), 'The show_in_rest=false fixture has no reachable REST route.' );

	if ( class_exists( 'SEORAI\\ServicePageCPT\\Plugin', false ) && post_type_exists( 'service_page' ) ) {
		$service_type = get_post_type_object( 'service_page' );
		$service_meta = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', 'service_page' ) : [];
		$service_key  = '';
		$priorities   = [ 'sp_hero_eyebrow', 'sp_hero_title', 'sp_intro', 'sp_main_1' ];

		foreach ( $priorities as $candidate ) {
			if ( isset( $service_meta[ $candidate ] ) && is_array( $service_meta[ $candidate ] ) && ! empty( $service_meta[ $candidate ]['show_in_rest'] ) && 'string' === ( $service_meta[ $candidate ]['type'] ?? '' ) ) {
				$service_key = $candidate;
				break;
			}
		}

		if ( '' === $service_key ) {
			foreach ( $service_meta as $candidate => $registration ) {
				if ( 0 === strpos( (string) $candidate, 'sp_' ) && is_array( $registration ) && ! empty( $registration['show_in_rest'] ) && 'string' === ( $registration['type'] ?? '' ) ) {
					$service_key = (string) $candidate;
					break;
				}
			}
		}

		nova_content_context_test_assert( $service_type instanceof WP_Post_Type && '' !== $service_key, 'The optional Service CPT exposes a writable string sp_* meta field.' );

		$service_post_id = wp_insert_post(
			[
				'post_type'   => 'service_page',
				'post_status' => 'draft',
				'post_title'  => 'NOVA service meta canary',
				'post_author' => $admin_id,
			],
			true
		);
		nova_content_context_test_assert( ! is_wp_error( $service_post_id ) && $service_post_id > 0, 'The optional Service CPT draft was created.' );
		$service_post_id = (int) $service_post_id;

		$service_namespace = ! empty( $service_type->rest_namespace ) ? trim( (string) $service_type->rest_namespace, '/' ) : 'wp/v2';
		$service_base      = ! empty( $service_type->rest_base ) ? trim( (string) $service_type->rest_base, '/' ) : 'service_page';
		$service_value     = 'NOVA native meta update ' . wp_generate_uuid4();
		$service_patch     = new WP_REST_Request( 'PATCH', '/' . $service_namespace . '/' . $service_base . '/' . $service_post_id );
		$service_patch->set_param(
			'meta',
			[
				$service_key => $service_value,
			]
		);
		$service_result = rest_do_request( $service_patch );
		$service_data   = $service_result->get_data();
		nova_content_context_test_assert( 'rest_cannot_update' !== ( $service_data['code'] ?? '' ), 'Service native meta PATCH does not fail with rest_cannot_update.' );
		nova_content_context_test_assert( 200 === $service_result->get_status(), 'Service native meta PATCH returns HTTP 200.' );
		nova_content_context_test_assert(
			array_key_exists( 'meta_descriptions', $service_data ) && is_array( $service_data['meta_descriptions'] ),
			'An authenticated Service response retains the CPT module\'s dedicated meta_descriptions field.'
		);
		$service_bridge_request = new WP_REST_Request( 'GET', '/nova-bridge/v1/content-endpoints/bridge-fields' );
		$service_bridge_request->set_param( 'post_id', $service_post_id );
		$service_bridge_response = rest_do_request( $service_bridge_request );
		nova_content_context_test_assert(
			400 === $service_bridge_response->get_status()
			&& 'nova_content_context_irrelevant_post_type' === ( $service_bridge_response->get_data()['code'] ?? '' ),
			'NOVA\'s Service CPT cannot be reintroduced through the generic builder-field inspector.'
		);
		$service_stored_value = get_post_meta( $service_post_id, $service_key, true );
		nova_content_context_test_assert( $service_value === $service_stored_value, 'Service native meta PATCH persists the selected sp_* field.' );
	} else {
		WP_CLI::line( 'SKIP  The Service CPT module is not active; native service meta collision check was skipped.' );
	}

	if ( ! empty( $managed_blog_types ) ) {
		foreach ( $managed_blog_types as $blog_type ) {
			$blog_meta = function_exists( 'get_registered_meta_keys' ) ? get_registered_meta_keys( 'post', $blog_type->name ) : [];
			$blog_key  = '';

			foreach ( [ 'blog_intro', 'blog_part_1', 'blog_part_2' ] as $candidate_key ) {
				if ( isset( $blog_meta[ $candidate_key ] ) && is_array( $blog_meta[ $candidate_key ] ) && ! empty( $blog_meta[ $candidate_key ]['show_in_rest'] ) && 'string' === ( $blog_meta[ $candidate_key ]['type'] ?? '' ) ) {
					$blog_key = $candidate_key;
					break;
				}
			}

			nova_content_context_test_assert( '' !== $blog_key, 'Managed Blog CPT ' . $blog_type->name . ' exposes a writable string blog_* meta field.' );
			nova_content_context_test_assert( post_type_supports( $blog_type->name, 'custom-fields' ), 'Managed Blog CPT ' . $blog_type->name . ' enables native REST post meta.' );
			$blog_post_id = wp_insert_post(
				[
					'post_type'   => $blog_type->name,
					'post_status' => 'draft',
					'post_title'  => 'NOVA blog meta canary ' . $blog_type->name,
					'post_author' => $admin_id,
				],
				true
			);
			nova_content_context_test_assert( ! is_wp_error( $blog_post_id ) && $blog_post_id > 0, 'A draft for managed Blog CPT ' . $blog_type->name . ' was created.' );
			$blog_post_id    = (int) $blog_post_id;
			$blog_post_ids[] = $blog_post_id;

			$blog_namespace = ! empty( $blog_type->rest_namespace ) ? trim( (string) $blog_type->rest_namespace, '/' ) : 'wp/v2';
			$blog_base      = ! empty( $blog_type->rest_base ) ? trim( (string) $blog_type->rest_base, '/' ) : $blog_type->name;
			$blog_value     = '<p>NOVA native Blog meta update ' . wp_generate_uuid4() . '</p>';
			$blog_patch     = new WP_REST_Request( 'PATCH', '/' . $blog_namespace . '/' . $blog_base . '/' . $blog_post_id );
			$blog_patch->set_param( 'meta', [ $blog_key => $blog_value ] );
			$blog_result = rest_do_request( $blog_patch );
			$blog_data   = $blog_result->get_data();
			nova_content_context_test_assert( 'rest_cannot_update' !== ( $blog_data['code'] ?? '' ), 'Managed Blog CPT ' . $blog_type->name . ' native meta PATCH does not fail with rest_cannot_update.' );
			nova_content_context_test_assert( 200 === $blog_result->get_status(), 'Managed Blog CPT ' . $blog_type->name . ' native meta PATCH returns HTTP 200.' );
			nova_content_context_test_assert(
				array_key_exists( 'meta_descriptions', $blog_data ) && is_array( $blog_data['meta_descriptions'] ),
				'An authenticated ' . $blog_type->name . ' response retains the Blog module\'s dedicated meta_descriptions field.'
			);
			$blog_bridge_request = new WP_REST_Request( 'GET', '/nova-bridge/v1/content-endpoints/bridge-fields' );
			$blog_bridge_request->set_param( 'post_id', $blog_post_id );
			$blog_bridge_response = rest_do_request( $blog_bridge_request );
			nova_content_context_test_assert(
				400 === $blog_bridge_response->get_status()
				&& 'nova_content_context_irrelevant_post_type' === ( $blog_bridge_response->get_data()['code'] ?? '' ),
				'NOVA-managed Blog CPT ' . $blog_type->name . ' cannot be reintroduced through the generic builder-field inspector.'
			);
			nova_content_context_test_assert( $blog_value === get_post_meta( $blog_post_id, $blog_key, true ), 'Managed Blog CPT ' . $blog_type->name . ' native meta PATCH persists the selected blog_* field.' );
		}
	} else {
		WP_CLI::line( 'SKIP  The managed Blog CPT module is not active; native blog meta collision check was skipped.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	if ( $template_filter_added && is_callable( $template_filter ) ) {
		remove_filter( $template_filter_hook, $template_filter, 10 );
	}
	if ( $fixture_post_id > 0 ) {
		wp_delete_post( $fixture_post_id, true );
	}
	if ( $hidden_post_id > 0 ) {
		wp_delete_post( $hidden_post_id, true );
	}
	if ( $collision_post_id > 0 ) {
		wp_delete_post( $collision_post_id, true );
	}
	if ( $service_post_id > 0 ) {
		wp_delete_post( $service_post_id, true );
	}
	foreach ( $blog_post_ids as $blog_post_id ) {
		if ( $blog_post_id > 0 ) {
			wp_delete_post( $blog_post_id, true );
		}
	}

	unregister_post_meta( $visible_post_type, $subtitle_meta );
	unregister_post_meta( $visible_post_type, $faq_meta );
	unregister_post_meta( $visible_post_type, $hidden_meta );
	if ( $acf_fixture_added && function_exists( 'acf_remove_local_field_group' ) ) {
		acf_remove_local_field_group( $acf_fixture_group_key );
	}

	if ( taxonomy_exists( $private_taxonomy ) ) {
		unregister_taxonomy( $private_taxonomy );
	}
	if ( taxonomy_exists( $locked_taxonomy ) ) {
		unregister_taxonomy( $locked_taxonomy );
	}
	if ( post_type_exists( $visible_post_type ) ) {
		unregister_post_type( $visible_post_type );
	}
	if ( post_type_exists( $hidden_post_type ) ) {
		unregister_post_type( $hidden_post_type );
	}
	if ( post_type_exists( $collision_post_type ) ) {
		unregister_post_type( $collision_post_type );
	}
	if ( post_type_exists( $application_post_type ) ) {
		unregister_post_type( $application_post_type );
	}
	if ( post_type_exists( $infrastructure_post_type ) ) {
		unregister_post_type( $infrastructure_post_type );
	}
	if ( post_type_exists( $private_post_type ) ) {
		unregister_post_type( $private_post_type );
	}
	if ( post_type_exists( $private_hidden_post_type ) ) {
		unregister_post_type( $private_hidden_post_type );
	}
	$cleanup_server = rest_get_server();
	if ( method_exists( $cleanup_server, 'remove_route' ) ) {
		$cleanup_server->remove_route( $opaque_namespace, $opaque_path );
		$cleanup_server->remove_route( $editable_namespace, $editable_path );
	}
	global $wp_rest_additional_fields;
	unset( $wp_rest_additional_fields[ $collision_post_type ] );

	if ( $option_existed ) {
		update_option( $option_name, $old_option );
	} else {
		delete_option( $option_name );
	}

	wp_set_current_user( $old_user_id );
}

if ( $failure instanceof Throwable ) {
	WP_CLI::error( 'Content-context canary failed: ' . $failure->getMessage() );
}

WP_CLI::success( 'Schema v4 lean content-endpoint discovery, template mapping, privacy, and write-regression checks passed.' );
