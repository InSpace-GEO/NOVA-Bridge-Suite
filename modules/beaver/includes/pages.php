<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function nova_bb_pages_maybe_require_dependencies() {
    $try_files = array(
        __DIR__ . '/helpers.php',
        __DIR__ . '/layout.php',
        __DIR__ . '/transformations.php',
    );

    foreach ( $try_files as $f ) {
        if ( file_exists( $f ) ) {
            require_once $f;
        }
    }
}
nova_bb_pages_maybe_require_dependencies();

/* ----------------------------------------------------------------------------
 * Safe JSON parameter extraction (prevents 500 fatals on odd client bodies)
 * ------------------------------------------------------------------------- */
function nova_bb_get_request_json_params_safe( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    if ( is_array( $params ) ) {
        return $params;
    }

    // Try raw body parse (handles clients that send Content-Type but set json:false / streaming).
    $raw      = (string) $request->get_body();
    $raw_trim = trim( $raw );

    if ( '' === $raw_trim ) {
        return array();
    }

    $decoded = json_decode( $raw_trim, true );
    if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
        return $decoded;
    }

    return new WP_Error(
        'invalid_json',
        'Request body must be valid JSON.',
        array(
            'status'          => 400,
            'hint'            => 'Ensure your client sends a JSON body (e.g. n8n json:true, useStream:false).',
            'raw_body_prefix' => substr( $raw_trim, 0, 200 ),
        )
    );
}

/**
 * Resolve the effective post type from request params (handles the `type`
 * alias and the `service` convenience mapping the same way create does).
 */
function nova_bb_resolve_request_post_type( $params ) {
    $post_type = isset( $params['post_type'] ) ? $params['post_type'] : null;
    if ( ( null === $post_type || '' === trim( (string) $post_type ) ) && isset( $params['type'] ) ) {
        $post_type = $params['type'];
    }
    if ( ! is_string( $post_type ) || '' === trim( $post_type ) ) {
        $post_type = 'page';
    }
    if ( 'service' === strtolower( trim( $post_type ) ) ) {
        $post_type = 'page';
    }
    return $post_type;
}

/**
 * Validate post_type / status / author against the current user's
 * capabilities. The route permission callback can't see values smuggled in
 * via the `type` alias or the nested `content` JSON payload, so this runs
 * again server-side after the payload is fully merged.
 *
 * @param  array        $params Merged request params.
 * @param  WP_Post|null $post   Existing post on update, null on create.
 * @return array|WP_Error       Possibly-adjusted params, or an error.
 */
function nova_bb_validate_write_request( $params, $post = null ) {
    if ( $post instanceof WP_Post ) {
        $post_type = $post->post_type;
    } else {
        $post_type = nova_bb_resolve_request_post_type( $params );
    }

    if ( ! post_type_exists( $post_type ) ) {
        return new WP_Error(
            'nova_bb_invalid_post_type',
            sprintf( 'Unknown post type "%s".', $post_type ),
            array( 'status' => 400 )
        );
    }

    $pto = get_post_type_object( $post_type );

    if ( $post instanceof WP_Post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return new WP_Error(
                'nova_bb_forbidden',
                'You are not allowed to edit this post.',
                array( 'status' => 403 )
            );
        }
    } elseif ( ! current_user_can( $pto->cap->edit_posts ) ) {
        return new WP_Error(
            'nova_bb_forbidden',
            sprintf( 'You are not allowed to create content of type "%s".', $post_type ),
            array( 'status' => 403 )
        );
    }

    if ( isset( $params['status'] ) && '' !== trim( (string) $params['status'] ) ) {
        $status  = (string) $params['status'];
        $allowed = array( 'draft', 'publish', 'pending', 'private', 'future' );

        if ( ! in_array( $status, $allowed, true ) ) {
            return new WP_Error(
                'nova_bb_invalid_status',
                sprintf( 'Invalid status "%s". Allowed: %s.', $status, implode( ', ', $allowed ) ),
                array( 'status' => 400 )
            );
        }

        if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $pto->cap->publish_posts ) ) {
            return new WP_Error(
                'nova_bb_forbidden_publish',
                sprintf( 'You are not allowed to publish content of type "%s".', $post_type ),
                array( 'status' => 403 )
            );
        }
    }

    // Assigning another author requires edit_others capability; drop silently.
    if ( ! empty( $params['author'] ) && is_numeric( $params['author'] )
        && (int) $params['author'] !== get_current_user_id()
        && ! current_user_can( $pto->cap->edit_others_posts )
    ) {
        unset( $params['author'] );
    }

    return $params;
}

/* ----------------------------------------------------------------------------
 * Beaver Builder meta / finalization
 * ------------------------------------------------------------------------- */

/**
 * Persist a layout tree to a post's Beaver Builder meta.
 *
 * Beaver Builder specifics, all of which matter:
 * - `_fl_builder_data` is the render source (published nodes).
 * - `_fl_builder_draft`(+`_settings`) is a builder-session draft. It MUST be
 *   deleted on every bridge write: the builder UI loads the draft when one
 *   exists, so a stale draft would show old content instead of what we wrote
 *   — and publishing from the UI would then clobber our layout with it.
 * - `_fl_builder_enabled` is the builder on/off switch for the post.
 * - post_content gets a plain-HTML fallback of the layout text (BB's own
 *   saves do the same; feeds search, excerpts, and SEO analyzers).
 * - BB caches generated CSS/JS per post under /uploads/bb-plugin/cache/ —
 *   cleared after every write so changes show immediately.
 *
 * @param int   $post_id        Target post.
 * @param array $tree           Layout tree (see layout.php).
 * @param array $params         Request params (publish_builder flag).
 * @param bool  $regenerate_ids Fresh node IDs (clone mode) vs keep (update).
 * @return array                The flat node array written (may be empty).
 */
function nova_bb_finalize_builder_meta( $post_id, $tree, $params = array(), $regenerate_ids = false ) {
    $post_id = (int) $post_id;
    $flat    = nova_bb_tree_to_flat( is_array( $tree ) ? $tree : array(), $regenerate_ids );

    if ( ! empty( $flat ) || ! empty( $params['publish_builder'] ) ) {
        update_post_meta( $post_id, '_fl_builder_data', nova_bb_slash_layout_data( $flat ) );
        update_post_meta( $post_id, '_fl_builder_enabled', true );

        delete_post_meta( $post_id, '_fl_builder_draft' );
        delete_post_meta( $post_id, '_fl_builder_draft_settings' );

        // Plain-HTML fallback (never parsed back).
        $fallback = nova_bb_tree_to_fallback_html( is_array( $tree ) ? $tree : array() );
        wp_update_post(
            array(
                'ID'           => $post_id,
                'post_content' => wp_slash( $fallback ),
            )
        );
    }

    nova_bb_clear_bb_caches( $post_id );

    return $flat;
}

/**
 * Clear Beaver Builder's per-post CSS/JS asset cache so changes render
 * immediately. Defensive — BB may not be active in this REST request.
 */
function nova_bb_clear_bb_caches( $post_id ) {
    if ( ! class_exists( 'FLBuilderModel' ) ) {
        return;
    }

    if ( method_exists( 'FLBuilderModel', 'delete_asset_cache' ) ) {
        try {
            FLBuilderModel::delete_asset_cache( (int) $post_id );
        } catch ( \Throwable $e ) { // phpcs:ignore
            // Never let cache clearing break the write.
        }
    }

    if ( method_exists( 'FLBuilderModel', 'delete_all_transients' ) ) {
        try {
            FLBuilderModel::delete_all_transients();
        } catch ( \Throwable $e ) { // phpcs:ignore
            // Ignore.
        }
    }
}

/**
 * Info block returned with responses so callers can see how the site treats
 * the content.
 */
function nova_bb_response_info() {
    return array(
        'bb_active'  => nova_bb_builder_active(),
        'bb_version' => nova_bb_builder_version(),
        'format'     => 'fl_builder_data nodes (post meta)',
    );
}

/* ----------------------------------------------------------------------------
 * Featured image handling (parity with the Gutenberg/Divi modules)
 * ------------------------------------------------------------------------- */

/**
 * Normalize the two accepted shapes into one payload:
 *   "featured_image": { "attachment_id": 12 | "url": "...", "alt": "...", "caption": "..." }
 *   "featured_image_url": "https://..."
 */
function nova_bb_extract_featured_image_payload( $params ) {
    if ( isset( $params['featured_image'] ) && is_array( $params['featured_image'] ) ) {
        return $params['featured_image'];
    }

    if ( isset( $params['featured_image_url'] ) && is_string( $params['featured_image_url'] ) && '' !== trim( $params['featured_image_url'] ) ) {
        return array( 'url' => trim( (string) $params['featured_image_url'] ) );
    }

    return null;
}

/**
 * Process the featured_image payload: set by attachment_id or sideload by URL.
 * Non-fatal: the post is still created/updated even if the image fails.
 *
 * @return array { featured_image_id: int|null, warning: string|null }
 */
function nova_bb_process_featured_image( $post_id, $featured_image ) {
    $post_id = (int) $post_id;
    $result  = array(
        'featured_image_id' => null,
        'warning'           => null,
    );

    if ( ! is_array( $featured_image ) ) {
        return $result;
    }

    $attachment_id = isset( $featured_image['attachment_id'] ) ? absint( $featured_image['attachment_id'] ) : 0;
    $url           = isset( $featured_image['url'] ) ? esc_url_raw( $featured_image['url'] ) : '';
    $alt           = isset( $featured_image['alt'] ) ? sanitize_text_field( $featured_image['alt'] ) : '';
    $caption       = isset( $featured_image['caption'] ) ? sanitize_text_field( $featured_image['caption'] ) : '';

    // Prefer attachment_id if both are provided.
    if ( $attachment_id > 0 ) {
        $attachment = get_post( $attachment_id );
        if ( $attachment && 'attachment' === $attachment->post_type ) {
            set_post_thumbnail( $post_id, $attachment_id );
            $result['featured_image_id'] = $attachment_id;

            if ( '' !== $alt ) {
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
            }
            if ( '' !== $caption ) {
                wp_update_post(
                    array(
                        'ID'           => $attachment_id,
                        'post_excerpt' => $caption,
                    )
                );
            }

            return $result;
        }

        if ( '' === $url ) {
            $result['warning'] = sprintf( 'Attachment ID %d not found or not an attachment.', $attachment_id );
            return $result;
        }
    }

    // Sideload from URL.
    if ( '' !== $url ) {
        $sideload = nova_bb_sideload_image( $url, $post_id );

        if ( is_wp_error( $sideload ) ) {
            $result['warning'] = 'Featured image sideload failed: ' . $sideload->get_error_message();
            return $result;
        }

        $new_attachment_id = (int) $sideload;
        set_post_thumbnail( $post_id, $new_attachment_id );
        $result['featured_image_id'] = $new_attachment_id;

        if ( '' !== $alt ) {
            update_post_meta( $new_attachment_id, '_wp_attachment_image_alt', $alt );
        }
        if ( '' !== $caption ) {
            wp_update_post(
                array(
                    'ID'           => $new_attachment_id,
                    'post_excerpt' => $caption,
                )
            );
        }

        return $result;
    }

    return $result;
}

/**
 * Download an image from a URL and sideload it into the WP Media Library.
 *
 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
 */
function nova_bb_sideload_image( $url, $post_id ) {
    if ( ! function_exists( 'download_url' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if ( ! function_exists( 'media_handle_sideload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }
    if ( ! function_exists( 'wp_read_image_metadata' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $tmp = download_url( $url );
    if ( is_wp_error( $tmp ) ) {
        return $tmp;
    }

    $url_path = wp_parse_url( $url, PHP_URL_PATH );
    $filename = $url_path ? basename( $url_path ) : 'image.jpg';

    if ( false !== strpos( $filename, '?' ) ) {
        $filename = strtok( $filename, '?' );
    }

    $ext = pathinfo( $filename, PATHINFO_EXTENSION );
    if ( '' === $ext ) {
        $filename .= '.jpg';
    }

    $file_array = array(
        'name'     => sanitize_file_name( $filename ),
        'tmp_name' => $tmp,
    );

    $attachment_id = media_handle_sideload( $file_array, $post_id );

    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $tmp ); // phpcs:ignore
        return $attachment_id;
    }

    return (int) $attachment_id;
}

/* ----------------------------------------------------------------------------
 * Core: Resolve / list / get / create / update
 * ------------------------------------------------------------------------- */

/**
 * Whether a post type is a legitimate bridge target (filters out revisions,
 * nav items, and other internal types when resolving by bare numeric ID).
 */
function nova_bb_is_bridge_manageable_post_type( $post_type ) {
    $obj = get_post_type_object( (string) $post_type );
    return $obj && ( $obj->public || $obj->show_in_rest );
}

/**
 * Resolve a page by ID or slug/path (supports hierarchical page paths).
 *
 * @param string|int        $id_or_slug Numeric ID, slug, or hierarchical path.
 * @param array|string|null $post_types Explicit post type(s) to match. When
 *                                      null, slug lookups default to
 *                                      page/post but numeric IDs resolve any
 *                                      public/REST-visible post type, so CPT
 *                                      entries created through this bridge
 *                                      stay readable and updatable by ID.
 */
function nova_bb_resolve_page( $id_or_slug, $post_types = null ) {
    $id_or_slug     = trim( (string) $id_or_slug );
    $explicit_types = null !== $post_types && array() !== (array) $post_types;
    $post_types     = $explicit_types ? (array) $post_types : array( 'page', 'post' );

    // Numeric ID.
    if ( '' !== $id_or_slug && ctype_digit( $id_or_slug ) ) {
        $post = get_post( (int) $id_or_slug );
        if ( $post && 'trash' !== $post->post_status ) {
            $type_ok = $explicit_types
                ? in_array( $post->post_type, $post_types, true )
                : ( in_array( $post->post_type, $post_types, true ) || nova_bb_is_bridge_manageable_post_type( $post->post_type ) );

            if ( $type_ok ) {
                return $post;
            }
        }
    }

    if ( '' === $id_or_slug ) {
        return null;
    }

    $path = trim( $id_or_slug, '/' );

    // 1) Try hierarchical page path, e.g. "parent/child".
    if ( in_array( 'page', $post_types, true ) ) {
        $post = get_page_by_path( $path, OBJECT, 'page' );
        if ( $post && 'trash' !== $post->post_status ) {
            return $post;
        }
    }

    // 2) Fallback: try simple slug for pages and posts.
    $slug  = basename( $path );
    $args  = array(
        'name'           => sanitize_title( $slug ),
        'post_type'      => $post_types,
        'post_status'    => 'any',
        'posts_per_page' => 1,
    );
    $query = new WP_Query( $args );

    if ( $query->have_posts() ) {
        $post = $query->posts[0];
        if ( 'trash' !== $post->post_status ) {
            return $post;
        }
    }

    return null;
}

/**
 * GET /pages – list pages/posts.
 */
function nova_bb_list_pages( $request ) {
    $per_page = min( max( 1, (int) $request->get_param( 'per_page' ) ), 50 );
    $page_num = max( 1, (int) $request->get_param( 'page' ) );
    $status   = $request->get_param( 'status' );

    $post_types = array( 'page', 'post' );
    if ( $request->get_param( 'post_type' ) ) {
        $post_types = (array) $request->get_param( 'post_type' );
    }

    // Exact slug filter.
    $slug_filter = $request->get_param( 'slug' );
    if ( is_string( $slug_filter ) && '' !== trim( $slug_filter ) ) {
        $post = null;

        if ( $request->get_param( 'post_type' ) ) {
            $slug_post_types = (array) $request->get_param( 'post_type' );
            $post            = nova_bb_resolve_page( $slug_filter, $slug_post_types );
        } else {
            $post = nova_bb_resolve_page( $slug_filter, array( 'page' ) );
            if ( ! $post ) {
                $post = nova_bb_resolve_page( $slug_filter, array( 'post' ) );
            }
        }

        $items = array();
        if ( $post ) {
            $items[] = array(
                'id'           => $post->ID,
                'title'        => get_the_title( $post ),
                'slug'         => nova_bb_get_slug_for_post( $post ),
                'status'       => $post->post_status,
                'modified_gmt' => get_post_modified_time( 'c', true, $post ),
                'permalink'    => get_permalink( $post ),
                'excerpt'      => $post->post_excerpt,
                'post_type'    => $post->post_type,
            );
        }

        $response = new WP_REST_Response( $items );
        $response->header( 'X-WP-Total', count( $items ) );
        $response->header( 'X-WP-TotalPages', 1 );
        return $response;
    }

    $args = array(
        'post_type'      => $post_types,
        'post_status'    => $status ? $status : 'any',
        'posts_per_page' => $per_page,
        'paged'          => $page_num,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    );

    if ( $request->get_param( 'search' ) ) {
        $args['s'] = $request->get_param( 'search' );
    }

    $include = $request->get_param( 'include' );
    if ( is_array( $include ) ) {
        $args['post__in'] = array_map( 'intval', $include );
    } elseif ( is_string( $include ) && '' !== trim( $include ) ) {
        // Allow CSV.
        $args['post__in'] = array_map( 'intval', preg_split( '/\s*,\s*/', trim( $include ) ) );
    }

    if ( $request->get_param( 'parent_id' ) ) {
        $args['post_parent'] = (int) $request->get_param( 'parent_id' );
    }

    $query = new WP_Query( $args );
    $items = array();

    foreach ( $query->posts as $post ) {
        $items[] = array(
            'id'           => $post->ID,
            'title'        => get_the_title( $post ),
            'slug'         => nova_bb_get_slug_for_post( $post ),
            'status'       => $post->post_status,
            'modified_gmt' => get_post_modified_time( 'c', true, $post ),
            'permalink'    => get_permalink( $post ),
            'excerpt'      => $post->post_excerpt,
            'post_type'    => $post->post_type,
        );
    }

    $response = new WP_REST_Response( $items );
    $response->header( 'X-WP-Total', (int) $query->found_posts );
    $response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );
    return $response;
}

/**
 * Strip internal bookkeeping ('orig' node objects, keep flags) from a tree
 * before returning it in a response.
 */
function nova_bb_tree_for_output( $tree ) {
    $out = array();

    foreach ( (array) $tree as $node ) {
        if ( ! is_array( $node ) ) {
            continue;
        }

        $entry = array(
            'node_id'  => isset( $node['node_id'] ) ? $node['node_id'] : null,
            'type'     => isset( $node['type'] ) ? $node['type'] : 'module',
            'settings' => isset( $node['settings'] ) ? $node['settings'] : new stdClass(),
            'children' => ! empty( $node['children'] ) && is_array( $node['children'] ) ? nova_bb_tree_for_output( $node['children'] ) : array(),
        );

        $out[] = $entry;
    }

    return $out;
}

/**
 * GET /pages/{id-or-slug} – single page + outline.
 */
function nova_bb_get_page( $request ) {
    $requested_types = $request->get_param( 'post_type' );
    $post            = nova_bb_resolve_page( $request['id_or_slug'], $requested_types ? (array) $requested_types : null );
    if ( ! $post ) {
        return new WP_Error( 'not_found', 'Page not found', array( 'status' => 404 ) );
    }

    $layout_mode      = $request->get_param( 'layout_mode' ) ? $request->get_param( 'layout_mode' ) : 'outline';
    $include_meta     = nova_bb_to_bool( $request->get_param( 'include_meta' ), true );
    $include_document = nova_bb_to_bool( $request->get_param( 'include_document' ), false );
    $text_map_flag    = nova_bb_to_bool( $request->get_param( 'text_map' ), false );

    $flat = nova_bb_get_layout_nodes( $post->ID );
    $tree = nova_bb_flat_to_tree( $flat );

    $layout = array(
        'outline'     => array(),
        'has_builder' => nova_bb_has_bb_layout( $post ),
    );

    if ( 'full' === $layout_mode ) {
        $layout['compact'] = nova_bb_tree_for_output( $tree );
    } else {
        $layout['outline'] = nova_bb_build_outline_from_tree( $tree );
    }

    $data = array(
        'id'           => $post->ID,
        'title'        => get_the_title( $post ),
        'slug'         => nova_bb_get_slug_for_post( $post ),
        'status'       => $post->post_status,
        'modified_gmt' => get_post_modified_time( 'c', true, $post ),
        'permalink'    => get_permalink( $post ),
        'excerpt'      => $post->post_excerpt,
        'layout'       => $layout,
        'beaver'       => nova_bb_response_info(),
    );

    if ( $include_meta ) {
        $data['meta'] = array(
            '_fl_builder_enabled' => (bool) get_post_meta( $post->ID, '_fl_builder_enabled', true ),
            'node_count'          => count( $flat ),
            'has_draft'           => metadata_exists( 'post', $post->ID, '_fl_builder_draft' ),
        );
    }

    // The raw flat node map — the exact `_fl_builder_data` value. This is the
    // ground truth for verifying module settings field names on a new site.
    $data['document'] = $include_document ? ( empty( $flat ) ? new stdClass() : $flat ) : null;

    if ( $text_map_flag ) {
        $data['text_map'] = nova_bb_build_text_map_from_tree( $tree );
    }

    return new WP_REST_Response( $data );
}

/**
 * Resolve a caller-supplied layout payload into a tree, or null when the
 * request doesn't replace the layout. Accepts:
 *   layout.nodes   — flat node map, the `document` shape a GET returns
 *   layout.compact — nested tree, the `layout.compact` shape a GET returns
 */
function nova_bb_tree_from_layout_param( $params ) {
    if ( ! isset( $params['layout'] ) || ! is_array( $params['layout'] ) ) {
        return null;
    }

    if ( ! empty( $params['layout']['nodes'] ) && is_array( $params['layout']['nodes'] ) ) {
        return nova_bb_flat_to_tree( nova_bb_normalize_incoming_nodes( $params['layout']['nodes'] ) );
    }

    if ( ! empty( $params['layout']['compact'] ) && is_array( $params['layout']['compact'] ) ) {
        return array_values( $params['layout']['compact'] );
    }

    return null;
}

/**
 * POST /pages – create (clone + replace template content slots + transforms,
 * or build from scratch via append_sections / layout.nodes).
 */
function nova_bb_create_page( $request ) {
    $params = nova_bb_get_request_json_params_safe( $request );
    if ( is_wp_error( $params ) ) {
        return $params; // 400 instead of fatal 500.
    }

    // If "content" is a JSON string, merge its keys into $params.
    if ( isset( $params['content'] ) && is_string( $params['content'] ) ) {
        $trimmed = trim( $params['content'] );
        if ( '' !== $trimmed && '{' === $trimmed[0] ) {
            $decoded = json_decode( $trimmed, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                $params = array_merge( $params, $decoded );
            }
        }
    }

    // Re-validate capabilities against the fully-merged payload (the route
    // permission callback can't see `type` or nested-content values).
    $params = nova_bb_validate_write_request( $params, null );
    if ( is_wp_error( $params ) ) {
        return $params;
    }

    $clone_mode  = ! empty( $params['source_page_id'] ) || ! empty( $params['source_page'] );
    $source_post = null;

    $post_type = nova_bb_resolve_request_post_type( $params );

    $remove_paths    = ! empty( $params['remove_paths'] ) ? (array) $params['remove_paths'] : array();
    $text_updates    = ! empty( $params['text_updates'] ) ? (array) $params['text_updates'] : array();
    $append_html     = ! empty( $params['append_html'] ) ? (string) $params['append_html'] : '';
    $append_sections = ! empty( $params['append_sections'] ) ? (array) $params['append_sections'] : array();

    $keep_source_content = false;
    if ( is_array( $params ) && array_key_exists( 'keep_source_content', $params ) ) {
        $keep_source_content = nova_bb_to_bool( $params['keep_source_content'], false );
    }

    $postarr = array(
        'post_title'   => isset( $params['title'] ) ? wp_strip_all_tags( $params['title'] ) : '',
        'post_status'  => isset( $params['status'] ) ? $params['status'] : 'draft',
        'post_type'    => $post_type,
        'post_excerpt' => isset( $params['excerpt'] ) ? (string) $params['excerpt'] : '',
    );

    if ( isset( $params['slug'] ) && '' !== trim( (string) $params['slug'] ) ) {
        list( $child_slug, $parent_path ) = nova_bb_split_slug_path( $params['slug'] );
        $postarr['post_name']             = sanitize_title( $child_slug );

        if ( '' !== $parent_path && empty( $params['parent_id'] ) && empty( $params['parent'] ) ) {
            $parent_post = nova_bb_resolve_page( $parent_path );
            if ( $parent_post ) {
                $postarr['post_parent'] = $parent_post->ID;
            }
        }
    }

    if ( ! empty( $params['parent_id'] ) ) {
        $postarr['post_parent'] = (int) $params['parent_id'];
    } elseif ( isset( $params['parent'] ) && '' !== trim( (string) $params['parent'] ) ) {
        $parent_post = nova_bb_resolve_page( $params['parent'] );
        if ( $parent_post ) {
            $postarr['post_parent'] = $parent_post->ID;
        }
    }

    if ( ! empty( $params['author'] ) && is_numeric( $params['author'] ) ) {
        $postarr['post_author'] = (int) $params['author'];
    }

    if ( $clone_mode ) {
        if ( ! empty( $params['source_page_id'] ) ) {
            $source_post = get_post( (int) $params['source_page_id'] );
        }
        if ( ! $source_post && ! empty( $params['source_page'] ) ) {
            $source_post = nova_bb_resolve_page( $params['source_page'] );
        }
    }

    $requested_tree = nova_bb_tree_from_layout_param( $params );

    $tree           = array();
    $using_template = false;

    if ( null !== $requested_tree ) {
        $tree = $requested_tree;
    } elseif ( $clone_mode && $source_post ) {
        $tree           = nova_bb_flat_to_tree( nova_bb_get_layout_nodes( $source_post->ID ) );
        $using_template = true;
    }

    // If template: allow path-based cleanup first (optional).
    if ( $using_template && ( ! empty( $remove_paths ) || ! empty( $text_updates ) ) ) {
        $tree         = nova_bb_apply_transformations( $tree, $remove_paths, $text_updates, '', array() );
        $remove_paths = array();
        $text_updates = array();
    }

    // Auto-split single huge section into multiple <h2>-based sections.
    // Template mode only (parity with the WPBakery/Divi modules) unless the
    // caller opts in with split_sections — from-scratch creates keep their
    // sections exactly as sent.
    $should_split = $using_template || ( isset( $params['split_sections'] ) && nova_bb_to_bool( $params['split_sections'], false ) );
    if ( $should_split && ! empty( $append_sections ) && is_array( $append_sections ) ) {
        $append_sections = nova_bb_expand_single_html_section_to_multiple( $append_sections, $postarr['post_title'] );
    }

    // Replace template slots instead of appending duplicates.
    if ( $using_template && ! $keep_source_content && ! empty( $append_sections ) && is_array( $append_sections ) ) {
        list( $tree, $append_sections ) = nova_bb_replace_template_slots_with_sections(
            $tree,
            $append_sections,
            $postarr['post_title'],
            true
        );
    }

    // Layout lives in meta; post_content only ever holds the HTML fallback
    // written by nova_bb_finalize_builder_meta().
    $postarr['post_content'] = '';

    $post_id = wp_insert_post( wp_slash( $postarr ), true );
    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    // Clone meta if in clone mode (skips BB draft meta; `_fl_builder_data`
    // comes along too but is overwritten below with remapped node IDs).
    if ( $clone_mode && $source_post ) {
        $clone_skip_keys = array(
            '_yoast_wpseo_title',
            '_yoast_wpseo_metadesc',
            '_aioseo_title',
            '_aioseo_description',
            'rank_math_title',
            'rank_math_description',
        );

        if ( isset( $params['meta'] ) && is_array( $params['meta'] ) ) {
            $clone_skip_keys = array_merge( $clone_skip_keys, array_keys( $params['meta'] ) );
        }

        nova_bb_clone_post_meta( $source_post->ID, $post_id, $clone_skip_keys );
    }

    // Meta from request.
    $meta_updates = nova_bb_prepare_meta_updates( $params );
    if ( ! empty( $meta_updates ) ) {
        foreach ( $meta_updates as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    // Append remaining sections + append_html.
    $tree = nova_bb_apply_transformations(
        $tree,
        $remove_paths,
        $text_updates,
        $append_html,
        $append_sections
    );

    // Builder meta + fallback + cache clear. A cloned layout gets fresh node
    // IDs so the new page never shares them with its source (BB uses node IDs
    // in CSS classes and per-post caches).
    $flat = nova_bb_finalize_builder_meta( $post_id, $tree, $params, $clone_mode );

    // Featured image (non-fatal).
    $response_body = array(
        'id'         => $post_id,
        'node_count' => count( $flat ),
        'beaver'     => nova_bb_response_info(),
    );

    $featured_payload = nova_bb_extract_featured_image_payload( $params );
    if ( null !== $featured_payload ) {
        $image_result = nova_bb_process_featured_image( $post_id, $featured_payload );
        if ( ! empty( $image_result['featured_image_id'] ) ) {
            $response_body['featured_image_id'] = $image_result['featured_image_id'];
        }
        if ( ! empty( $image_result['warning'] ) ) {
            $response_body['warning'] = $image_result['warning'];
        }
    }

    return new WP_REST_Response( $response_body, 201 );
}

/**
 * PUT/PATCH /pages/{id-or-slug} – update.
 */
function nova_bb_update_page( $request ) {
    $requested_types = $request->get_param( 'post_type' );
    $post            = nova_bb_resolve_page( $request['id_or_slug'], $requested_types ? (array) $requested_types : null );
    if ( ! $post ) {
        return new WP_Error( 'not_found', 'Page not found', array( 'status' => 404 ) );
    }

    $params = nova_bb_get_request_json_params_safe( $request );
    if ( is_wp_error( $params ) ) {
        return $params; // 400 instead of fatal 500.
    }

    $params = nova_bb_validate_write_request( $params, $post );
    if ( is_wp_error( $params ) ) {
        return $params;
    }

    $post_id = $post->ID;
    $postarr = array( 'ID' => $post_id );

    if ( isset( $params['title'] ) ) {
        $postarr['post_title'] = wp_strip_all_tags( $params['title'] );
    }
    if ( isset( $params['slug'] ) && '' !== trim( (string) $params['slug'] ) ) {
        list( $child_slug, $parent_path ) = nova_bb_split_slug_path( $params['slug'] );
        $postarr['post_name']             = sanitize_title( $child_slug );

        if ( '' !== $parent_path && ! isset( $params['parent_id'] ) && ! isset( $params['parent'] ) ) {
            $parent_post = nova_bb_resolve_page( $parent_path );
            if ( $parent_post ) {
                $postarr['post_parent'] = $parent_post->ID;
            }
        }
    }
    if ( isset( $params['status'] ) ) {
        $postarr['post_status'] = $params['status'];
    }
    if ( isset( $params['excerpt'] ) ) {
        $postarr['post_excerpt'] = (string) $params['excerpt'];
    }

    if ( isset( $params['parent_id'] ) ) {
        $postarr['post_parent'] = (int) $params['parent_id'];
    } elseif ( isset( $params['parent'] ) && '' !== trim( (string) $params['parent'] ) ) {
        $parent_post = nova_bb_resolve_page( $params['parent'] );
        if ( $parent_post ) {
            $postarr['post_parent'] = $parent_post->ID;
        }
    }

    if ( count( $postarr ) > 1 ) {
        wp_update_post( wp_slash( $postarr ) );
    }

    // Meta.
    $meta_updates = nova_bb_prepare_meta_updates( $params );
    if ( ! empty( $meta_updates ) ) {
        foreach ( $meta_updates as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    $clone_mode  = ! empty( $params['source_page_id'] ) || ! empty( $params['source_page'] );
    $source_post = null;

    if ( $clone_mode ) {
        if ( ! empty( $params['source_page_id'] ) ) {
            $source_post = get_post( (int) $params['source_page_id'] );
        }
        if ( ! $source_post && ! empty( $params['source_page'] ) ) {
            $source_post = nova_bb_resolve_page( $params['source_page'] );
        }
    }

    $requested_tree = nova_bb_tree_from_layout_param( $params );
    $using_template = false;
    if ( null !== $requested_tree ) {
        $tree = $requested_tree;
    } elseif ( $clone_mode && $source_post ) {
        $tree           = nova_bb_flat_to_tree( nova_bb_get_layout_nodes( $source_post->ID ) );
        $using_template = true;
    } else {
        $tree = nova_bb_flat_to_tree( nova_bb_get_layout_nodes( $post_id ) );
    }

    $remove_paths    = ! empty( $params['remove_paths'] ) ? (array) $params['remove_paths'] : array();
    $text_updates    = ! empty( $params['text_updates'] ) ? (array) $params['text_updates'] : array();
    $append_html     = ! empty( $params['append_html'] ) ? (string) $params['append_html'] : '';
    $append_sections = ! empty( $params['append_sections'] ) ? (array) $params['append_sections'] : array();
    $keep_source_content = false;
    if ( is_array( $params ) && array_key_exists( 'keep_source_content', $params ) ) {
        $keep_source_content = nova_bb_to_bool( $params['keep_source_content'], false );
    }

    if ( $using_template && ( ! empty( $remove_paths ) || ! empty( $text_updates ) ) ) {
        $tree         = nova_bb_apply_transformations( $tree, $remove_paths, $text_updates, '', array() );
        $remove_paths = array();
        $text_updates = array();
    }

    // Auto-split single huge HTML section.
    if ( ! empty( $append_sections ) ) {
        $append_sections = nova_bb_expand_single_html_section_to_multiple( $append_sections, get_the_title( $post ) );
    }

    if ( $using_template && ! $keep_source_content && ! empty( $append_sections ) && is_array( $append_sections ) ) {
        list( $tree, $append_sections ) = nova_bb_replace_template_slots_with_sections(
            $tree,
            $append_sections,
            isset( $postarr['post_title'] ) ? $postarr['post_title'] : get_the_title( $post ),
            true
        );
    }

    $tree = nova_bb_apply_transformations(
        $tree,
        $remove_paths,
        $text_updates,
        $append_html,
        $append_sections
    );

    // Existing node IDs are kept on normal update. Re-cloning from a source page
    // gets fresh IDs so translations never share Beaver node CSS/cache handles.
    $flat = nova_bb_finalize_builder_meta( $post_id, $tree, $params, $using_template );

    // Featured image (non-fatal).
    $response_body = array(
        'id'         => $post_id,
        'node_count' => count( $flat ),
        'beaver'     => nova_bb_response_info(),
    );

    $featured_payload = nova_bb_extract_featured_image_payload( $params );
    if ( null !== $featured_payload ) {
        $image_result = nova_bb_process_featured_image( $post_id, $featured_payload );
        if ( ! empty( $image_result['featured_image_id'] ) ) {
            $response_body['featured_image_id'] = $image_result['featured_image_id'];
        }
        if ( ! empty( $image_result['warning'] ) ) {
            $response_body['warning'] = $image_result['warning'];
        }
    }

    return new WP_REST_Response( $response_body, 200 );
}
