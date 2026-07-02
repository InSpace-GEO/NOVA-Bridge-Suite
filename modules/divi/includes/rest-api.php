<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Permission: require user who can edit pages.
 * Falls back to per-post checks when a target post can be resolved.
 */
function nova_divi_permission_check( $request ) {
    if ( $request instanceof WP_REST_Request && $request->has_param( 'id_or_slug' ) ) {
        $post = nova_divi_resolve_page( $request['id_or_slug'] );
        if ( $post ) {
            return current_user_can( 'edit_post', $post->ID );
        }
    }

    $post_type = '';
    if ( $request instanceof WP_REST_Request ) {
        $post_type = $request->get_param( 'post_type' );
        if ( null === $post_type || '' === $post_type ) {
            $post_type = $request->get_param( 'type' ); // Body alias.
        }
    }

    if ( is_array( $post_type ) ? in_array( 'post', $post_type, true ) : 'post' === $post_type ) {
        return current_user_can( 'edit_posts' );
    }

    // Coarse gate only — nova_divi_validate_write_request() re-checks the
    // exact post type, status, and author caps against the merged payload.
    return current_user_can( 'edit_pages' );
}

/**
 * Register REST routes.
 */
add_action(
    'rest_api_init',
    function () {

        // GET /pages and POST /pages.
        register_rest_route(
            'nova-divi/v1',
            '/pages',
            array(
                // GET: list pages/posts.
                array(
                    'methods'             => 'GET',
                    'callback'            => 'nova_divi_list_pages',
                    'permission_callback' => 'nova_divi_permission_check',
                    'args'                => array(
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
                        'status'    => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                        'search'    => array(
                            'type' => 'string',
                        ),
                        'include'   => array(
                            'type'  => 'array',
                            'items' => array(
                                'type' => 'integer',
                            ),
                        ),
                        'post_type' => array(
                            'type' => 'string',
                        ),
                        // Exact slug or hierarchical path (e.g. "parent/child").
                        'slug'      => array(
                            'type' => 'string',
                        ),
                        // Optional parent filter.
                        'parent_id' => array(
                            'type' => 'integer',
                        ),
                    ),
                ),
                // POST: create new page/post.
                array(
                    'methods'             => 'POST',
                    'callback'            => 'nova_divi_create_page',
                    'permission_callback' => 'nova_divi_permission_check',
                ),
            )
        );

        // GET /pages/{id-or-slug} and PUT/PATCH /pages/{id-or-slug}.
        register_rest_route(
            'nova-divi/v1',
            '/pages/(?P<id_or_slug>[^\/]+(?:\/[^\/]+)*)',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => 'nova_divi_get_page',
                    'permission_callback' => 'nova_divi_permission_check',
                    'args'                => array(
                        'layout_mode'      => array(
                            'type'    => 'string',
                            'default' => 'outline', // outline|full
                        ),
                        'outline_style'    => array(
                            'type'    => 'string',
                            'default' => 'summary', // summary|tree (tree still flat)
                        ),
                        'include_meta'     => array(
                            'type'    => 'boolean',
                            'default' => true,
                        ),
                        'include_document' => array(
                            'type'    => 'boolean',
                            'default' => false,
                        ),
                        'text_map'         => array(
                            'type'    => 'boolean',
                            'default' => false,
                        ),
                    ),
                ),
                array(
                    'methods'             => array( 'PUT', 'PATCH' ),
                    'callback'            => 'nova_divi_update_page',
                    'permission_callback' => 'nova_divi_permission_check',
                ),
            )
        );
    }
);
