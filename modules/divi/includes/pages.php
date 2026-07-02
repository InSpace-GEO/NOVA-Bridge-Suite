<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function nova_divi_pages_maybe_require_dependencies() {
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
nova_divi_pages_maybe_require_dependencies();

/* ----------------------------------------------------------------------------
 * Safe JSON parameter extraction (prevents 500 fatals on odd client bodies)
 * ------------------------------------------------------------------------- */
function nova_divi_get_request_json_params_safe( WP_REST_Request $request ) {
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

/* ----------------------------------------------------------------------------
 * Divi meta / finalization
 * ------------------------------------------------------------------------- */

/**
 * Set the Divi builder meta on a post whose content is an et_pb_* layout.
 *
 * `_et_pb_use_builder = 'on'` is THE switch (et_pb_is_pagebuilder_used()
 * checks exactly this). Without it the Visual Builder won't open the page as
 * a builder page and wp-admin shows raw shortcode soup.
 */
function nova_divi_finalize_builder_meta( $post_id, $shortcodes, $params = array() ) {
    $post_id    = (int) $post_id;
    $shortcodes = (string) $shortcodes;

    $has_layout = ( false !== strpos( $shortcodes, '[et_pb_' ) );

    if ( $has_layout || ! empty( $params['publish_builder'] ) ) {
        update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
    }

    // Plain-HTML fallback shown if the user disables the builder.
    if ( isset( $params['old_content'] ) && is_string( $params['old_content'] ) && '' !== trim( $params['old_content'] ) ) {
        update_post_meta( $post_id, '_et_pb_old_content', wp_kses_post( $params['old_content'] ) );
    }

    // Sidebar/width of Divi's default templates. Ignored when a Theme Builder
    // template with a custom body is assigned — harmless to set either way.
    if ( isset( $params['page_layout'] ) ) {
        $layouts = array( 'et_full_width_page', 'et_right_sidebar', 'et_left_sidebar', 'et_no_sidebar' );
        $layout  = (string) $params['page_layout'];
        if ( in_array( $layout, $layouts, true ) ) {
            update_post_meta( $post_id, '_et_pb_page_layout', $layout );
        }
    }

    if ( isset( $params['show_title'] ) ) {
        update_post_meta( $post_id, '_et_pb_show_title', nova_divi_to_bool( $params['show_title'], true ) ? 'on' : 'off' );
    }

    nova_divi_clear_divi_caches();
}

/**
 * Clear Divi's static CSS/resource caches so changes show immediately.
 * Everything is defensive — Divi may not be fully loaded in a REST request.
 */
function nova_divi_clear_divi_caches() {
    if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
        try {
            ET_Core_PageResource::remove_static_resources( 'all', 'all' );
        } catch ( \Throwable $e ) { // phpcs:ignore
            // Never let cache clearing break the write.
        }
    }

    if ( function_exists( 'et_core_clear_transients' ) ) {
        try {
            et_core_clear_transients();
        } catch ( \Throwable $e ) { // phpcs:ignore
            // Ignore.
        }
    }
}

/**
 * Info block returned with write responses so callers can see how the site
 * will treat the content.
 */
function nova_divi_response_info() {
    return array(
        'builder_version' => nova_divi_builder_version(),
        'divi5_active'    => nova_divi_is_divi5(),
        'format'          => nova_divi_is_divi5() ? 'divi4-shortcodes (rendered via Divi 5 backwards compatibility)' : 'divi4-shortcodes',
    );
}

/* ----------------------------------------------------------------------------
 * Featured image handling (parity with the Gutenberg module)
 * ------------------------------------------------------------------------- */

/**
 * Normalize the two accepted shapes into one payload:
 *   "featured_image": { "attachment_id": 12 | "url": "...", "alt": "...", "caption": "..." }
 *   "featured_image_url": "https://..."
 */
function nova_divi_extract_featured_image_payload( $params ) {
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
function nova_divi_process_featured_image( $post_id, $featured_image ) {
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
        $sideload = nova_divi_sideload_image( $url, $post_id );

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
function nova_divi_sideload_image( $url, $post_id ) {
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
 * Resolve a page by ID or slug/path (supports hierarchical page paths).
 */
function nova_divi_resolve_page( $id_or_slug, $post_types = array( 'page', 'post' ) ) {
    $id_or_slug = trim( (string) $id_or_slug );
    $post_types = (array) $post_types;

    // Numeric ID.
    if ( '' !== $id_or_slug && ctype_digit( $id_or_slug ) ) {
        $post = get_post( (int) $id_or_slug );
        if ( $post && 'trash' !== $post->post_status && in_array( $post->post_type, $post_types, true ) ) {
            return $post;
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
function nova_divi_list_pages( $request ) {
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
            $post            = nova_divi_resolve_page( $slug_filter, $slug_post_types );
        } else {
            $post = nova_divi_resolve_page( $slug_filter, array( 'page' ) );
            if ( ! $post ) {
                $post = nova_divi_resolve_page( $slug_filter, array( 'post' ) );
            }
        }

        $items = array();
        if ( $post ) {
            $items[] = array(
                'id'           => $post->ID,
                'title'        => get_the_title( $post ),
                'slug'         => nova_divi_get_slug_for_post( $post ),
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
            'slug'         => nova_divi_get_slug_for_post( $post ),
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
 * GET /pages/{id-or-slug} – single page + outline.
 */
function nova_divi_get_page( $request ) {
    $post = nova_divi_resolve_page( $request['id_or_slug'] );
    if ( ! $post ) {
        return new WP_Error( 'not_found', 'Page not found', array( 'status' => 404 ) );
    }

    $layout_mode      = $request->get_param( 'layout_mode' ) ? $request->get_param( 'layout_mode' ) : 'outline';
    $outline_style    = $request->get_param( 'outline_style' ) ? $request->get_param( 'outline_style' ) : 'summary';
    $include_meta     = nova_divi_to_bool( $request->get_param( 'include_meta' ), true );
    $include_document = nova_divi_to_bool( $request->get_param( 'include_document' ), false );
    $text_map_flag    = nova_divi_to_bool( $request->get_param( 'text_map' ), false );

    $raw_shortcodes = (string) $post->post_content;

    $layout = array(
        'outline'     => array(),
        'has_builder' => nova_divi_has_divi_layout( $post ),
    );

    $compact       = array();
    $text_map_data = array();

    if ( in_array( $layout_mode, array( 'outline', 'full' ), true ) ) {
        if ( ! function_exists( 'nova_divi_parse_shortcodes_to_compact' ) ) {
            return new WP_Error(
                'missing_dependency',
                'Layout parser not loaded (nova_divi_parse_shortcodes_to_compact). Ensure layout.php is included.',
                array( 'status' => 500 )
            );
        }

        $compact = nova_divi_parse_shortcodes_to_compact( $raw_shortcodes );

        if ( 'outline' === $layout_mode ) {
            if ( ! function_exists( 'nova_divi_build_outline_from_compact' ) ) {
                return new WP_Error(
                    'missing_dependency',
                    'Outline builder not loaded (nova_divi_build_outline_from_compact). Ensure layout.php is included.',
                    array( 'status' => 500 )
                );
            }
            $layout['outline'] = nova_divi_build_outline_from_compact( $compact, ( 'tree' === $outline_style ) );
        } else {
            $layout['compact'] = $compact;
        }

        if ( $text_map_flag ) {
            if ( ! function_exists( 'nova_divi_build_text_map_from_compact' ) ) {
                return new WP_Error(
                    'missing_dependency',
                    'Text-map builder not loaded (nova_divi_build_text_map_from_compact). Ensure layout.php is included.',
                    array( 'status' => 500 )
                );
            }
            $text_map_data = nova_divi_build_text_map_from_compact( $compact );
        }
    }

    $data = array(
        'id'           => $post->ID,
        'title'        => get_the_title( $post ),
        'slug'         => nova_divi_get_slug_for_post( $post ),
        'status'       => $post->post_status,
        'modified_gmt' => get_post_modified_time( 'c', true, $post ),
        'permalink'    => get_permalink( $post ),
        'excerpt'      => $post->post_excerpt,
        'layout'       => $layout,
        'divi'         => nova_divi_response_info(),
    );

    if ( $include_meta ) {
        $data['meta'] = array(
            '_et_pb_use_builder' => get_post_meta( $post->ID, '_et_pb_use_builder', true ),
            '_et_pb_page_layout' => get_post_meta( $post->ID, '_et_pb_page_layout', true ),
            '_et_pb_show_title'  => get_post_meta( $post->ID, '_et_pb_show_title', true ),
            '_et_builder_version' => get_post_meta( $post->ID, '_et_builder_version', true ),
        );
    }

    $data['document'] = $include_document ? $raw_shortcodes : null;

    if ( $text_map_flag ) {
        $data['text_map'] = $text_map_data;
    }

    return new WP_REST_Response( $data );
}

/**
 * POST /pages – create (clone + replace template content slots + transforms,
 * or build from scratch via append_sections / layout.raw_shortcodes).
 */
function nova_divi_create_page( $request ) {
    $params = nova_divi_get_request_json_params_safe( $request );
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

    $clone_mode  = ! empty( $params['source_page_id'] ) || ! empty( $params['source_page'] );
    $source_post = null;

    $post_type = isset( $params['post_type'] ) ? $params['post_type'] : 'page';
    if ( isset( $params['type'] ) && '' === trim( (string) $post_type ) ) {
        $post_type = $params['type'];
    } elseif ( isset( $params['type'] ) && ! isset( $params['post_type'] ) ) {
        $post_type = $params['type'];
    }

    if ( is_string( $post_type ) && 'service' === strtolower( $post_type ) ) {
        $post_type = 'page';
    }

    $remove_paths    = ! empty( $params['remove_paths'] ) ? (array) $params['remove_paths'] : array();
    $text_updates    = ! empty( $params['text_updates'] ) ? (array) $params['text_updates'] : array();
    $append_html     = ! empty( $params['append_html'] ) ? (string) $params['append_html'] : '';
    $append_sections = ! empty( $params['append_sections'] ) ? (array) $params['append_sections'] : array();

    $keep_source_content = false;
    if ( is_array( $params ) && array_key_exists( 'keep_source_content', $params ) ) {
        $keep_source_content = nova_divi_to_bool( $params['keep_source_content'], false );
    }

    $postarr = array(
        'post_title'   => isset( $params['title'] ) ? wp_strip_all_tags( $params['title'] ) : '',
        'post_status'  => isset( $params['status'] ) ? $params['status'] : 'draft',
        'post_type'    => $post_type,
        'post_excerpt' => isset( $params['excerpt'] ) ? (string) $params['excerpt'] : '',
    );

    if ( isset( $params['slug'] ) && '' !== trim( (string) $params['slug'] ) ) {
        list( $child_slug, $parent_path ) = nova_divi_split_slug_path( $params['slug'] );
        $postarr['post_name']             = sanitize_title( $child_slug );

        if ( '' !== $parent_path && empty( $params['parent_id'] ) && empty( $params['parent'] ) ) {
            $parent_post = nova_divi_resolve_page( $parent_path );
            if ( $parent_post ) {
                $postarr['post_parent'] = $parent_post->ID;
            }
        }
    }

    if ( ! empty( $params['parent_id'] ) ) {
        $postarr['post_parent'] = (int) $params['parent_id'];
    } elseif ( isset( $params['parent'] ) && '' !== trim( (string) $params['parent'] ) ) {
        $parent_post = nova_divi_resolve_page( $params['parent'] );
        if ( $parent_post ) {
            $postarr['post_parent'] = $parent_post->ID;
        }
    }

    if ( ! empty( $params['author'] ) && is_numeric( $params['author'] ) ) {
        $postarr['post_author'] = (int) $params['author'];
    }

    $requested_content = null;
    if ( isset( $params['layout'] ) && is_array( $params['layout'] ) ) {
        if ( ! empty( $params['layout']['raw_shortcodes'] ) ) {
            $requested_content = (string) $params['layout']['raw_shortcodes'];
        } elseif ( ! empty( $params['layout']['compact'] ) ) {
            if ( ! function_exists( 'nova_divi_compact_to_shortcodes' ) ) {
                return new WP_Error(
                    'missing_dependency',
                    'Shortcode serializer not loaded (nova_divi_compact_to_shortcodes). Ensure layout.php is included.',
                    array( 'status' => 500 )
                );
            }
            $requested_content = nova_divi_compact_to_shortcodes( $params['layout']['compact'] );
        }
    }

    if ( $clone_mode ) {
        if ( ! empty( $params['source_page_id'] ) ) {
            $source_post = get_post( (int) $params['source_page_id'] );
        }
        if ( ! $source_post && ! empty( $params['source_page'] ) ) {
            $source_post = nova_divi_resolve_page( $params['source_page'] );
        }
    }

    $base_shortcodes = '';
    $using_template  = false;

    if ( null !== $requested_content ) {
        $base_shortcodes = $requested_content;
    } elseif ( $clone_mode && $source_post ) {
        $base_shortcodes = (string) $source_post->post_content;
        $using_template  = true;
    }

    // If template: allow path-based cleanup first (optional).
    if ( $using_template && ( ! empty( $remove_paths ) || ! empty( $text_updates ) ) ) {
        if ( ! function_exists( 'nova_divi_apply_transformations' ) ) {
            return new WP_Error(
                'missing_dependency',
                'Transformations not loaded (nova_divi_apply_transformations). Ensure transformations.php is included.',
                array( 'status' => 500 )
            );
        }

        $base_shortcodes = nova_divi_apply_transformations( $base_shortcodes, $remove_paths, $text_updates, '', array() );
        $remove_paths    = array();
        $text_updates    = array();
    }

    // Auto-split single huge section into multiple <h2>-based sections.
    if ( ! empty( $append_sections ) && is_array( $append_sections ) && function_exists( 'nova_divi_expand_single_html_section_to_multiple' ) ) {
        $append_sections = nova_divi_expand_single_html_section_to_multiple( $append_sections, $postarr['post_title'] );
    }

    // Replace template slots instead of appending duplicates.
    if ( $using_template && ! $keep_source_content && ! empty( $append_sections ) && is_array( $append_sections ) ) {
        if ( ! function_exists( 'nova_divi_replace_template_slots_with_sections' ) ) {
            return new WP_Error(
                'missing_dependency',
                'Template slot replacer not loaded (nova_divi_replace_template_slots_with_sections). Ensure transformations.php is included.',
                array( 'status' => 500 )
            );
        }

        list( $base_shortcodes, $append_sections ) = nova_divi_replace_template_slots_with_sections(
            $base_shortcodes,
            $append_sections,
            $postarr['post_title'],
            true
        );
    }

    $postarr['post_content'] = $base_shortcodes;

    $post_id = wp_insert_post( wp_slash( $postarr ), true );
    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    // Clone meta if in clone mode.
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

        nova_divi_clone_post_meta( $source_post->ID, $post_id, $clone_skip_keys );
    }

    // Meta from request.
    $meta_updates = nova_divi_prepare_meta_updates( $params );
    if ( ! empty( $meta_updates ) ) {
        foreach ( $meta_updates as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    // Append remaining sections + append_html.
    $shortcodes = (string) get_post_field( 'post_content', $post_id );

    if ( ! function_exists( 'nova_divi_apply_transformations' ) ) {
        return new WP_Error(
            'missing_dependency',
            'Transformations not loaded (nova_divi_apply_transformations). Ensure transformations.php is included.',
            array( 'status' => 500 )
        );
    }

    $shortcodes = nova_divi_apply_transformations(
        $shortcodes,
        $remove_paths,
        $text_updates,
        $append_html,
        $append_sections
    );

    wp_update_post(
        array(
            'ID'           => $post_id,
            'post_content' => wp_slash( $shortcodes ),
        )
    );

    // Divi builder meta + cache clear.
    nova_divi_finalize_builder_meta( $post_id, $shortcodes, $params );

    // Featured image (non-fatal).
    $response_body = array( 'id' => $post_id, 'divi' => nova_divi_response_info() );

    $featured_payload = nova_divi_extract_featured_image_payload( $params );
    if ( null !== $featured_payload ) {
        $image_result = nova_divi_process_featured_image( $post_id, $featured_payload );
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
function nova_divi_update_page( $request ) {
    $post = nova_divi_resolve_page( $request['id_or_slug'] );
    if ( ! $post ) {
        return new WP_Error( 'not_found', 'Page not found', array( 'status' => 404 ) );
    }

    $params = nova_divi_get_request_json_params_safe( $request );
    if ( is_wp_error( $params ) ) {
        return $params; // 400 instead of fatal 500.
    }

    $post_id = $post->ID;
    $postarr = array( 'ID' => $post_id );

    if ( isset( $params['title'] ) ) {
        $postarr['post_title'] = wp_strip_all_tags( $params['title'] );
    }
    if ( isset( $params['slug'] ) && '' !== trim( (string) $params['slug'] ) ) {
        list( $child_slug, $parent_path ) = nova_divi_split_slug_path( $params['slug'] );
        $postarr['post_name']             = sanitize_title( $child_slug );

        if ( '' !== $parent_path && ! isset( $params['parent_id'] ) && ! isset( $params['parent'] ) ) {
            $parent_post = nova_divi_resolve_page( $parent_path );
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
        $parent_post = nova_divi_resolve_page( $params['parent'] );
        if ( $parent_post ) {
            $postarr['post_parent'] = $parent_post->ID;
        }
    }

    if ( count( $postarr ) > 1 ) {
        wp_update_post( wp_slash( $postarr ) );
    }

    // Meta.
    $meta_updates = nova_divi_prepare_meta_updates( $params );
    if ( ! empty( $meta_updates ) ) {
        foreach ( $meta_updates as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    $shortcodes = (string) get_post_field( 'post_content', $post_id );

    if ( isset( $params['layout'] ) && is_array( $params['layout'] ) ) {
        if ( array_key_exists( 'raw_shortcodes', $params['layout'] ) ) {
            $shortcodes = (string) $params['layout']['raw_shortcodes'];
        } elseif ( array_key_exists( 'compact', $params['layout'] ) ) {
            if ( ! function_exists( 'nova_divi_compact_to_shortcodes' ) ) {
                return new WP_Error(
                    'missing_dependency',
                    'Shortcode serializer not loaded (nova_divi_compact_to_shortcodes). Ensure layout.php is included.',
                    array( 'status' => 500 )
                );
            }
            $shortcodes = nova_divi_compact_to_shortcodes( $params['layout']['compact'] );
        }
    }

    $remove_paths    = ! empty( $params['remove_paths'] ) ? (array) $params['remove_paths'] : array();
    $text_updates    = ! empty( $params['text_updates'] ) ? (array) $params['text_updates'] : array();
    $append_html     = ! empty( $params['append_html'] ) ? (string) $params['append_html'] : '';
    $append_sections = ! empty( $params['append_sections'] ) ? (array) $params['append_sections'] : array();

    // Auto-split single huge HTML section.
    if ( ! empty( $append_sections ) && function_exists( 'nova_divi_expand_single_html_section_to_multiple' ) ) {
        $append_sections = nova_divi_expand_single_html_section_to_multiple( $append_sections, get_the_title( $post ) );
    }

    if ( ! function_exists( 'nova_divi_apply_transformations' ) ) {
        return new WP_Error(
            'missing_dependency',
            'Transformations not loaded (nova_divi_apply_transformations). Ensure transformations.php is included.',
            array( 'status' => 500 )
        );
    }

    $shortcodes = nova_divi_apply_transformations(
        $shortcodes,
        $remove_paths,
        $text_updates,
        $append_html,
        $append_sections
    );

    wp_update_post(
        array(
            'ID'           => $post_id,
            'post_content' => wp_slash( $shortcodes ),
        )
    );

    // Divi builder meta + cache clear.
    nova_divi_finalize_builder_meta( $post_id, $shortcodes, $params );

    // Featured image (non-fatal).
    $response_body = array( 'id' => $post_id, 'divi' => nova_divi_response_info() );

    $featured_payload = nova_divi_extract_featured_image_payload( $params );
    if ( null !== $featured_payload ) {
        $image_result = nova_divi_process_featured_image( $post_id, $featured_payload );
        if ( ! empty( $image_result['featured_image_id'] ) ) {
            $response_body['featured_image_id'] = $image_result['featured_image_id'];
        }
        if ( ! empty( $image_result['warning'] ) ) {
            $response_body['warning'] = $image_result['warning'];
        }
    }

    return new WP_REST_Response( $response_body, 200 );
}
