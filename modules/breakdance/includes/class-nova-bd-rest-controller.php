<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Nova_BD_REST_Controller {

    public static function register_routes() {
        self::register_collection_routes( 'pages' );
        self::register_collection_routes( 'posts' );
    }

    protected static function register_collection_routes( $base ) {
        register_rest_route(
            'nova-breakdance/v1',
            '/' . $base,
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'list_pages' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                    'args'                => array(
                        'per_page' => array(
                            'type'              => 'integer',
                            'default'           => 10,
                            'sanitize_callback' => 'absint',
                        ),
                        'page'     => array(
                            'type'              => 'integer',
                            'default'           => 1,
                            'sanitize_callback' => 'absint',
                        ),
                        'status'   => array(
                            'type'    => 'string',
                            'default' => '',
                        ),
                        'search'   => array(
                            'type' => 'string',
                        ),
                        'include'  => array(
                            'type'  => 'array',
                            'items' => array(
                                'type' => 'integer',
                            ),
                        ),
                        'post_type' => array(
                            'type' => 'string',
                        ),
                        'has_builder' => array(
                            'type'    => 'boolean',
                            'default' => false,
                        ),
                        'slug' => array(
                            'type' => 'string',
                        ),
                        'parent_id' => array(
                            'type' => 'integer',
                        ),
                    ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( __CLASS__, 'create_page' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                ),
            )
        );

        register_rest_route(
            'nova-breakdance/v1',
            '/' . $base . '/(?P<id_or_slug>[^\/]+(?:\/[^\/]+)*)',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( __CLASS__, 'get_page' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                    'args'                => array(
                        'layout_mode' => array(
                            'type'    => 'string',
                            'default' => 'outline',
                        ),
                        'outline_style' => array(
                            'type'    => 'string',
                            'default' => 'summary',
                        ),
                        'include_meta' => array(
                            'type'    => 'boolean',
                            'default' => false,
                        ),
                        'include_document' => array(
                            'type'    => 'boolean',
                            'default' => false,
                        ),
                        'text_map' => array(
                            'type'    => 'boolean',
                            'default' => false,
                        ),
                        'text_map_style' => array(
                            'type'    => 'string',
                            'default' => 'compact',
                        ),
                        'text_map_max_chars' => array(
                            'type'              => 'integer',
                            'default'           => 0,
                            'sanitize_callback' => 'absint',
                        ),
                        'text_map_scope' => array(
                            'type'    => 'string',
                            'default' => 'content',
                        ),
                        'text_map_include_media' => array(
                            'type'    => 'boolean',
                            'default' => false,
                        ),
                    ),
                ),
                array(
                    'methods'             => array( 'PUT', 'PATCH' ),
                    'callback'            => array( __CLASS__, 'update_page' ),
                    'permission_callback' => array( __CLASS__, 'permission_check' ),
                ),
            )
        );
    }

    public static function permission_check( $request ) {
        $post = self::resolve_request_post( $request );

        if ( $post instanceof WP_Post ) {
            return current_user_can( 'edit_post', $post->ID );
        }

        $route_type = self::get_route_default_post_type( $request );

        if ( 'post' === $route_type ) {
            return current_user_can( 'edit_posts' );
        }

        return current_user_can( 'edit_pages' );
    }

    /* -------------------------------------------------------------------------
     * GET /pages or /posts – list documents.
     * ---------------------------------------------------------------------- */

    public static function list_pages( $request ) {
        $per_page    = min( max( 1, (int) $request->get_param( 'per_page' ) ), 50 );
        $page        = max( 1, (int) $request->get_param( 'page' ) );
        $status      = $request->get_param( 'status' ) ? $request->get_param( 'status' ) : 'any';
        $post_types  = array();
        $has_builder = self::to_bool( $request->get_param( 'has_builder' ), false );
        $post_type_param = self::normalize_post_type_param( $request->get_param( 'post_type' ) );
        $route_type      = self::get_route_default_post_type( $request );

        if ( $route_type ) {
            if ( ! empty( $post_type_param ) && ( 1 !== count( $post_type_param ) || $post_type_param[0] !== $route_type ) ) {
                return new WP_Error(
                    'nova_bd_invalid_post_type',
                    sprintf( 'This endpoint only supports post_type "%s".', $route_type ),
                    array( 'status' => 400 )
                );
            }

            $post_types = array( $route_type );
        } elseif ( ! empty( $post_type_param ) ) {
            $post_types = $post_type_param;
        } else {
            $post_types = array( 'page', 'post' );
        }

        $slug_filter = $request->get_param( 'slug' );
        if ( is_string( $slug_filter ) && '' !== trim( $slug_filter ) ) {
            $post  = self::resolve_page( $slug_filter, $post_types );
            $items = array();

            if ( $post ) {
                $items[] = array(
                    'id'           => $post->ID,
                    'title'        => get_the_title( $post ),
                    'slug'         => $post->post_name,
                    'status'       => $post->post_status,
                    'modified_gmt' => get_post_modified_time( 'c', true, $post ),
                    'permalink'    => get_permalink( $post ),
                    'excerpt'      => $post->post_excerpt,
                    'post_type'    => $post->post_type,
                    'has_builder'  => self::has_breakdance_layout( $post ),
                );
            }

            $response = new WP_REST_Response( $items );
            $response->header( 'X-WP-Total', count( $items ) );
            $response->header( 'X-WP-TotalPages', 1 );

            return $response;
        }

        $args = array(
            'post_type'      => $post_types,
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        );

        if ( $request->get_param( 'search' ) ) {
            $args['s'] = $request->get_param( 'search' );
        }

        if ( $request->get_param( 'include' ) && is_array( $request->get_param( 'include' ) ) ) {
            $args['post__in'] = array_map( 'intval', $request->get_param( 'include' ) );
        }

        if ( $request->get_param( 'parent_id' ) ) {
            $args['post_parent'] = (int) $request->get_param( 'parent_id' );
        }

        if ( $has_builder ) {
            $args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                array(
                    'key'     => '_breakdance_data',
                    'compare' => 'EXISTS',
                ),
            );
        }

        $query = new WP_Query( $args );
        $items = array();

        foreach ( $query->posts as $post ) {
            $items[] = array(
                'id'           => $post->ID,
                'title'        => get_the_title( $post ),
                'slug'         => $post->post_name,
                'status'       => $post->post_status,
                'modified_gmt' => get_post_modified_time( 'c', true, $post ),
                'permalink'    => get_permalink( $post ),
                'excerpt'      => $post->post_excerpt,
                'post_type'    => $post->post_type,
                'has_builder'  => self::has_breakdance_layout( $post ),
            );
        }

        $response = new WP_REST_Response( $items );
        $response->header( 'X-WP-Total', (int) $query->found_posts );
        $response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );

        return $response;
    }

    /* -------------------------------------------------------------------------
     * GET /pages/{id-or-slug}
     * ---------------------------------------------------------------------- */

    public static function get_page( $request ) {
        $post_types = self::get_allowed_post_types_from_request( $request );
        if ( is_wp_error( $post_types ) ) {
            return $post_types;
        }

        $post = self::resolve_page( $request['id_or_slug'], $post_types );
        if ( ! $post ) {
            return new WP_Error( 'not_found', 'Page not found', array( 'status' => 404 ) );
        }

        $layout_mode      = $request->get_param( 'layout_mode' ) ?: 'outline';
        $include_meta     = self::to_bool( $request->get_param( 'include_meta' ), false );
        $include_document = self::to_bool( $request->get_param( 'include_document' ), false );
        $text_map_flag    = self::to_bool( $request->get_param( 'text_map' ), false );
        $text_map_style   = $request->get_param( 'text_map_style' );
        $text_map_max     = $request->get_param( 'text_map_max_chars' );
        $text_map_scope   = $request->get_param( 'text_map_scope' );
        $text_map_media   = self::to_bool( $request->get_param( 'text_map_include_media' ), false );

        $layout = array(
            'outline'     => array(),
            'has_builder' => self::has_breakdance_layout( $post ),
        );

        $raw_bd_data  = Nova_BD_Utils::get_raw_breakdance_data( $post->ID );
        $tree_wrapper = null;
        $text_map     = array();

        if ( $layout['has_builder'] && ! empty( $raw_bd_data ) ) {
            $tree_wrapper = Nova_BD_Utils::decode_breakdance_tree( $raw_bd_data );

            if ( $tree_wrapper && ! empty( $tree_wrapper['root'] ) ) {
                $root = $tree_wrapper['root'];

                $outline = array();
                $map     = array();

                Nova_BD_Utils::build_outline_and_text_map_from_tree(
                    $root,
                    $outline,
                    $map,
                    $text_map_flag ? $text_map_style : '',
                    $text_map_max,
                    $text_map_scope,
                    $text_map_media
                );

                if ( 'outline' === $layout_mode ) {
                    $layout['outline'] = $outline;
                } elseif ( 'full' === $layout_mode ) {
                    $layout['tree'] = $root;
                }

                if ( $text_map_flag ) {
                    $text_map = $map;
                }
            }
        }

        $data = array(
            'id'           => $post->ID,
            'title'        => get_the_title( $post ),
            'slug'         => $post->post_name,
            'status'       => $post->post_status,
            'modified_gmt' => get_post_modified_time( 'c', true, $post ),
            'permalink'    => get_permalink( $post ),
            'excerpt'      => $post->post_excerpt,
            'layout'       => $layout,
        );

        if ( $include_meta ) {
            $data['meta'] = array(
                '_breakdance_data' => $raw_bd_data,
                'breakdance_data'  => get_post_meta( $post->ID, 'breakdance_data', true ),
            );
        }

        $data['document'] = $include_document ? $raw_bd_data : null;

        if ( $text_map_flag ) {
            $data['text_map'] = $text_map;
        }

        return new WP_REST_Response( $data );
    }

    /* -------------------------------------------------------------------------
     * POST /pages or /posts – create (clone + transforms)
     * ---------------------------------------------------------------------- */

    public static function create_page( $request ) {
        $params = $request->get_json_params();

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $params = self::unwrap_gpt_page_payload( $params );
        $route_type = self::get_route_default_post_type( $request );
        $requested_post_type = isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : '';

        if ( $route_type && '' !== $requested_post_type && $requested_post_type !== $route_type ) {
            return new WP_Error(
                'nova_bd_invalid_post_type',
                sprintf( 'This endpoint only supports post_type "%s".', $route_type ),
                array( 'status' => 400 )
            );
        }

        $post_type = $route_type ? $route_type : ( $requested_post_type ? $requested_post_type : 'page' );

        $postarr = array(
            'post_title'   => isset( $params['title'] ) ? wp_strip_all_tags( $params['title'] ) : '',
            'post_name'    => isset( $params['slug'] ) ? sanitize_title( $params['slug'] ) : '',
            'post_status'  => isset( $params['status'] ) ? $params['status'] : 'draft',
            'post_type'    => $post_type,
            'post_excerpt' => isset( $params['excerpt'] ) ? $params['excerpt'] : '',
        );

        if ( isset( $params['content'] ) && is_string( $params['content'] ) ) {
            $postarr['post_content'] = $params['content'];
        }

        if ( isset( $params['parent'] ) && is_numeric( $params['parent'] ) ) {
            $postarr['post_parent'] = (int) $params['parent'];
        }

        if ( isset( $params['author'] ) && is_numeric( $params['author'] ) ) {
            $postarr['post_author'] = (int) $params['author'];
        }

        $post_id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // SEO/meta
        if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
            foreach ( $params['meta'] as $key => $value ) {
                update_post_meta( $post_id, $key, $value );
            }
        }

        $raw_bd_data = '';

        // Clone Breakdance layout from source_page_id if provided.
        if ( ! empty( $params['source_page_id'] ) ) {
            $source_id   = (int) $params['source_page_id'];
            $raw_bd_data = Nova_BD_Utils::get_raw_breakdance_data( $source_id );

            if ( ! empty( $raw_bd_data ) ) {
                Nova_BD_Utils::update_breakdance_meta( $post_id, $raw_bd_data );
            }

            $clone_meta = true;
            if ( array_key_exists( 'clone_breakdance_meta', $params ) ) {
                $clone_meta = self::to_bool( $params['clone_breakdance_meta'], true );
            }

            if ( $clone_meta ) {
                Nova_BD_Utils::clone_breakdance_meta( $source_id, $post_id, $params );
            }
        }

        // Explicit full JSON override (rare).
        if ( ! empty( $params['layout']['raw_data'] ) ) {
            $raw_bd_data = $params['layout']['raw_data'];
            Nova_BD_Utils::update_breakdance_meta( $post_id, $raw_bd_data );
        }

        // Transformations.
        $remove_paths    = ! empty( $params['remove_paths'] ) ? (array) $params['remove_paths'] : array();
        $text_updates    = ! empty( $params['text_updates'] ) ? (array) $params['text_updates'] : array();
        $append_html     = ! empty( $params['append_html'] ) ? (string) $params['append_html'] : '';
        $append_sections = ! empty( $params['append_sections'] ) ? (array) $params['append_sections'] : array();

        if ( ! empty( $remove_paths ) || ! empty( $text_updates ) || '' !== $append_html || ! empty( $append_sections ) ) {
            $raw_bd_data = Nova_BD_Utils::apply_transformations(
                $raw_bd_data,
                $remove_paths,
                $text_updates,
                $append_html,
                $append_sections
            );

        }

        if ( ! empty( $raw_bd_data ) ) {
            Nova_BD_Utils::update_breakdance_meta( $post_id, $raw_bd_data );
        }

        return new WP_REST_Response( array( 'id' => $post_id ), 201 );
    }

    /* -------------------------------------------------------------------------
     * PUT/PATCH /pages/{id-or-slug} or /posts/{id-or-slug} – update
     * ---------------------------------------------------------------------- */

    public static function update_page( $request ) {
        $post_types = self::get_allowed_post_types_from_request( $request );
        if ( is_wp_error( $post_types ) ) {
            return $post_types;
        }

        $post = self::resolve_page( $request['id_or_slug'], $post_types );
        if ( ! $post ) {
            return new WP_Error( 'not_found', 'Page not found', array( 'status' => 404 ) );
        }

        $params  = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            $params = array();
        }
        $params  = self::unwrap_gpt_page_payload( $params );
        $post_id = $post->ID;

        $postarr = array( 'ID' => $post_id );

        if ( isset( $params['title'] ) ) {
            $postarr['post_title'] = wp_strip_all_tags( $params['title'] );
        }
        if ( isset( $params['slug'] ) ) {
            $postarr['post_name'] = sanitize_title( $params['slug'] );
        }
        if ( isset( $params['status'] ) ) {
            $postarr['post_status'] = $params['status'];
        }
        if ( isset( $params['excerpt'] ) ) {
            $postarr['post_excerpt'] = $params['excerpt'];
        }
        if ( isset( $params['content'] ) ) {
            $postarr['post_content'] = is_string( $params['content'] ) ? $params['content'] : '';
        }
        if ( isset( $params['parent'] ) && is_numeric( $params['parent'] ) ) {
            $postarr['post_parent'] = (int) $params['parent'];
        }
        if ( isset( $params['author'] ) && is_numeric( $params['author'] ) ) {
            $postarr['post_author'] = (int) $params['author'];
        }

        if ( count( $postarr ) > 1 ) {
            wp_update_post( $postarr );
        }

        if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
            foreach ( $params['meta'] as $key => $value ) {
                update_post_meta( $post_id, $key, $value );
            }
        }

        $raw_bd_data = Nova_BD_Utils::get_raw_breakdance_data( $post_id );

        if ( ! empty( $params['layout']['raw_data'] ) ) {
            $raw_bd_data = $params['layout']['raw_data'];
        }

        $remove_paths    = ! empty( $params['remove_paths'] ) ? (array) $params['remove_paths'] : array();
        $text_updates    = ! empty( $params['text_updates'] ) ? (array) $params['text_updates'] : array();
        $append_html     = ! empty( $params['append_html'] ) ? (string) $params['append_html'] : '';
        $append_sections = ! empty( $params['append_sections'] ) ? (array) $params['append_sections'] : array();

        if ( ! empty( $remove_paths ) || ! empty( $text_updates ) || '' !== $append_html || ! empty( $append_sections ) ) {
            $raw_bd_data = Nova_BD_Utils::apply_transformations(
                $raw_bd_data,
                $remove_paths,
                $text_updates,
                $append_html,
                $append_sections
            );

        }

        if ( ! empty( $raw_bd_data ) ) {
            Nova_BD_Utils::update_breakdance_meta( $post_id, $raw_bd_data );
        }

        return new WP_REST_Response( array( 'id' => $post_id ), 200 );
    }

    /* -------------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------------- */

    protected static function unwrap_gpt_page_payload( $params ) {
        if ( is_string( $params ) ) {
            $decoded = json_decode( $params, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                $params = $decoded;
            } else {
                return array();
            }
        }

        if ( is_array( $params ) && isset( $params[0] ) && is_array( $params[0] ) && isset( $params[0]['object'] ) && 'chat.completion' === $params[0]['object'] ) {
            $params = $params[0];
        }

        if ( is_array( $params ) && isset( $params['object'] ) && 'chat.completion' === $params['object'] && ! empty( $params['choices'][0]['message']['content'] ) ) {
            $content = $params['choices'][0]['message']['content'];
            if ( is_string( $content ) ) {
                $decoded = json_decode( $content, true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                    return $decoded;
                }
            }
        }

        return is_array( $params ) ? $params : array();
    }

    protected static function resolve_page( $id_or_slug ) {
        $id_or_slug = trim( (string) $id_or_slug );
        $allowed_post_types = func_num_args() > 1 ? func_get_arg( 1 ) : null;

        if ( empty( $allowed_post_types ) ) {
            $allowed_post_types = array( 'page', 'post' );
        }

        if ( ctype_digit( $id_or_slug ) ) {
            $post = get_post( (int) $id_or_slug );
            if ( $post && 'trash' !== $post->post_status && in_array( $post->post_type, $allowed_post_types, true ) ) {
                return $post;
            }
        }

        if ( '' === $id_or_slug ) {
            return null;
        }

        $parts = explode( '/', trim( $id_or_slug, '/' ) );
        $slug  = array_pop( $parts );

        $args = array(
            'name'           => sanitize_title( $slug ),
            'post_type'      => $allowed_post_types,
            'post_status'    => 'any',
            'posts_per_page' => 1,
        );

        $query = new WP_Query( $args );
        $post  = $query->have_posts() ? $query->posts[0] : null;

        if ( ! $post || 'trash' === $post->post_status ) {
            return null;
        }

        return $post;
    }

    protected static function get_route_default_post_type( $request ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return null;
        }

        $route = $request->get_route();

        if ( 0 === strpos( $route, '/nova-breakdance/v1/posts' ) ) {
            return 'post';
        }

        if ( 0 === strpos( $route, '/nova-breakdance/v1/pages' ) ) {
            return 'page';
        }

        return null;
    }

    protected static function normalize_post_type_param( $value ) {
        if ( empty( $value ) ) {
            return array();
        }

        if ( is_array( $value ) ) {
            return array_values(
                array_filter(
                    array_map( 'sanitize_key', $value )
                )
            );
        }

        return array( sanitize_key( (string) $value ) );
    }

    protected static function get_allowed_post_types_from_request( $request ) {
        $route_type = self::get_route_default_post_type( $request );
        $post_type_param = self::normalize_post_type_param( $request->get_param( 'post_type' ) );

        if ( $route_type ) {
            if ( ! empty( $post_type_param ) && ( 1 !== count( $post_type_param ) || $post_type_param[0] !== $route_type ) ) {
                return new WP_Error(
                    'nova_bd_invalid_post_type',
                    sprintf( 'This endpoint only supports post_type "%s".', $route_type ),
                    array( 'status' => 400 )
                );
            }

            return array( $route_type );
        }

        if ( ! empty( $post_type_param ) ) {
            return $post_type_param;
        }

        return array( 'page', 'post' );
    }

    protected static function resolve_request_post( $request ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return null;
        }

        if ( ! $request->has_param( 'id_or_slug' ) ) {
            return null;
        }

        $post_types = self::get_allowed_post_types_from_request( $request );

        if ( is_wp_error( $post_types ) ) {
            return null;
        }

        return self::resolve_page( $request['id_or_slug'], $post_types );
    }

    protected static function has_breakdance_layout( $post ) {
        $val = Nova_BD_Utils::get_raw_breakdance_data( $post->ID );
        return ! empty( $val );
    }

    protected static function to_bool( $value, $default = false ) {
        if ( null === $value || '' === $value ) {
            return $default;
        }
        return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
    }
}

// Backwards compatibility for older references that used a different casing.
if ( ! class_exists( 'Nova_BD_Rest_Controller', false ) ) {
    class_alias( 'Nova_BD_REST_Controller', 'Nova_BD_Rest_Controller' );
}
