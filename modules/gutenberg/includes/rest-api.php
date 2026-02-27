<?php
/**
 * NOVA Gutenberg Bridge – REST route registration.
 *
 * Namespace: nova-gutenberg/v1
 *
 * Routes:
 *   GET        /posts       → list / lookup blog posts by slug
 *   POST       /posts       → create blog post   (type defaults to "post")
 *   GET        /posts/{id}  → get single blog post
 *   PUT|PATCH  /posts/{id}  → update blog post
 *
 *   GET        /pages       → list / lookup pages by slug
 *   POST       /pages       → create page         (type defaults to "page")
 *   GET        /pages/{id}  → get single page
 *   PUT|PATCH  /pages/{id}  → update page
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	function () {

		$collection_args = array(
			'slug'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
			),
			'post_type' => array(
				'type'    => 'string',
				'default' => '',
			),
			'status'    => array(
				'type'    => 'string',
				'default' => '',
			),
			'search'    => array(
				'type' => 'string',
			),
			'per_page'  => array(
				'type'              => 'integer',
				'default'           => 10,
				'sanitize_callback' => 'absint',
			),
			'page'      => array(
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'include_content' => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);

		$id_arg = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
		);

		// Register identical route sets for /posts and /pages.
		foreach ( array( 'posts', 'pages' ) as $resource ) {

			// Collection: GET (list) + POST (create).
			register_rest_route(
				'nova-gutenberg/v1',
				'/' . $resource,
				array(
					array(
						'methods'             => 'GET',
						'callback'            => 'nova_gut_list_pages',
						'permission_callback' => 'nova_gut_permission_check',
						'args'                => $collection_args,
					),
					array(
						'methods'             => 'POST',
						'callback'            => 'nova_gut_create_page',
						'permission_callback' => 'nova_gut_permission_check',
					),
				)
			);

			// Single item: GET (read) + PUT|PATCH (update).
			register_rest_route(
				'nova-gutenberg/v1',
				'/' . $resource . '/(?P<id>\d+)',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => 'nova_gut_get_page',
						'permission_callback' => 'nova_gut_permission_check',
						'args'                => $id_arg,
					),
					array(
						'methods'             => array( 'PUT', 'PATCH' ),
						'callback'            => 'nova_gut_update_page',
						'permission_callback' => 'nova_gut_permission_check',
						'args'                => $id_arg,
					),
				)
			);
		}
	}
);
