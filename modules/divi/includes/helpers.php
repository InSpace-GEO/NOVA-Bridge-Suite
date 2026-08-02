<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get a slug/path for a post.
 * - For pages: use hierarchical path (parent/child).
 * - For posts: use plain post_name.
 */
function nova_divi_get_slug_for_post( $post ) {
    if ( 'page' === $post->post_type ) {
        return get_page_uri( $post );
    }
    return $post->post_name;
}

/**
 * Convert value to bool with default.
 */
function nova_divi_to_bool( $value, $default = false ) {
    if ( null === $value || '' === $value ) {
        return $default;
    }
    return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
}

/**
 * The Divi Builder version to stamp onto generated elements.
 * Divi uses `_builder_version` to decide whether legacy-default migrations
 * must run; stamping the site's current version avoids styling surprises.
 */
function nova_divi_builder_version() {
    if ( defined( 'ET_BUILDER_VERSION' ) && '' !== (string) ET_BUILDER_VERSION ) {
        return (string) ET_BUILDER_VERSION;
    }
    return '4.27.4';
}

/**
 * Whether the site runs Divi 5 (renders our Divi 4 shortcodes via its
 * backwards-compatibility layer until the page is migrated).
 */
function nova_divi_is_divi5() {
    if ( defined( 'ET_BUILDER_5' ) ) {
        return true;
    }
    if ( defined( 'ET_BUILDER_VERSION' ) && version_compare( (string) ET_BUILDER_VERSION, '5.0', '>=' ) ) {
        return true;
    }
    return false;
}

/**
 * Encode a value for use inside a Divi shortcode attribute.
 *
 * Divi percent-encodes special characters inside attribute values and decodes
 * them in ET_Builder_Element: `"` => %22, `\` => %92, `[` => %91, `]` => %93.
 * A raw double quote inside an attribute breaks shortcode parsing entirely,
 * so this MUST be applied to every generated attribute value. Idempotent for
 * already-encoded values (they contain none of the raw characters).
 */
function nova_divi_encode_attr( $value ) {
    return str_replace(
        array( '\\', '"', '[', ']' ),
        array( '%92', '%22', '%91', '%93' ),
        (string) $value
    );
}

/**
 * Decode a Divi-encoded attribute value for display (e.g. in layout outlines).
 */
function nova_divi_decode_attr( $value ) {
    return str_replace(
        array( '%22', '%92', '%91', '%93' ),
        array( '"', '\\', '[', ']' ),
        (string) $value
    );
}

/**
 * Simple heuristic to check if post has a Divi Builder layout.
 */
function nova_divi_has_divi_layout( $post ) {
    $flag = get_post_meta( $post->ID, '_et_pb_use_builder', true );
    if ( 'on' === $flag ) {
        return true;
    }
    $content = (string) $post->post_content;
    return ( false !== strpos( $content, '[et_pb_' ) || false !== strpos( $content, '<!-- wp:divi/' ) );
}

/**
 * Identify the stored Divi document format before selecting a parser.
 */
function nova_divi_content_format( $content ) {
    $content        = (string) $content;
    $has_shortcodes = false !== strpos( $content, '[et_pb_' );
    $has_blocks     = false !== strpos( $content, '<!-- wp:divi/' );

    if ( $has_shortcodes && $has_blocks ) {
        return 'hybrid';
    }
    if ( $has_blocks ) {
        return 'divi5-blocks';
    }
    if ( $has_shortcodes ) {
        return 'divi4-shortcodes';
    }
    return 'plain';
}

/**
 * Whether a request asks the shortcode transformation layer to edit layout.
 */
function nova_divi_request_mutates_layout( $params ) {
    foreach ( array( 'text_updates', 'remove_paths', 'append_sections' ) as $key ) {
        if ( ! empty( $params[ $key ] ) ) {
            return true;
        }
    }

    if ( array_key_exists( 'append_html', $params ) ) {
        if ( ! is_scalar( $params['append_html'] ) && null !== $params['append_html'] ) {
            return true;
        }
        if ( '' !== trim( (string) $params['append_html'] ) ) {
            return true;
        }
    }

    return isset( $params['layout'] )
        && is_array( $params['layout'] )
        && ( array_key_exists( 'raw_shortcodes', $params['layout'] ) || array_key_exists( 'compact', $params['layout'] ) );
}

/**
 * Native Divi 5 text fields can be updated in place. Structural mutations
 * still require a dedicated writer and must fail before any post is changed.
 */
function nova_divi_validate_native_write_request( $params ) {
    foreach ( array( 'remove_paths', 'append_sections', 'text_updates' ) as $key ) {
        if ( array_key_exists( $key, $params ) && ! is_array( $params[ $key ] ) ) {
            return new WP_Error( 'rest_invalid_param', $key . ' must be an array.', array( 'status' => 400 ) );
        }
    }
    if ( array_key_exists( 'layout', $params ) && ! is_array( $params['layout'] ) ) {
        return new WP_Error( 'rest_invalid_param', 'layout must be an object.', array( 'status' => 400 ) );
    }
    if ( array_key_exists( 'append_html', $params ) && ! is_scalar( $params['append_html'] ) && null !== $params['append_html'] ) {
        return new WP_Error( 'rest_invalid_param', 'append_html must be a string.', array( 'status' => 400 ) );
    }

    $unsupported = array();

    foreach ( array( 'remove_paths', 'append_sections' ) as $key ) {
        if ( ! empty( $params[ $key ] ) ) {
            $unsupported[] = $key;
        }
    }

    if ( isset( $params['append_html'] ) && '' !== trim( (string) $params['append_html'] ) ) {
        $unsupported[] = 'append_html';
    }

    if (
        isset( $params['layout'] )
        && is_array( $params['layout'] )
        && ( array_key_exists( 'raw_shortcodes', $params['layout'] ) || array_key_exists( 'compact', $params['layout'] ) )
    ) {
        $unsupported[] = 'layout';
    }

    if ( empty( $unsupported ) ) {
        return true;
    }

    return new WP_Error(
        'nova_divi5_unsupported_operation',
        'Native Divi 5 layouts support cloning and text_updates, but not structural removal, append, or wholesale layout replacement.',
        array(
            'status'             => 422,
            'content_format'     => 'divi5-blocks',
            'unsupported_fields' => array_values( array_unique( $unsupported ) ),
        )
    );
}

function nova_divi5_document_hash( $content ) {
    return 'sha256:' . hash( 'sha256', (string) $content );
}

function nova_divi5_validate_document_hash( $provided, $content, $parameter ) {
    if ( ! is_string( $provided ) || '' === trim( $provided ) ) {
        return new WP_Error(
            'nova_divi5_precondition_required',
            $parameter . ' is required for native Divi 5 text updates.',
            array( 'status' => 428, 'required' => $parameter )
        );
    }

    $actual = nova_divi5_document_hash( $content );
    if ( ! hash_equals( $actual, trim( $provided ) ) ) {
        return new WP_Error(
            'nova_divi5_document_changed',
            'The native Divi 5 document changed after it was inspected. Fetch a fresh outline before writing.',
            array( 'status' => 409 )
        );
    }

    return true;
}

/**
 * Mixed Divi 4/5 documents have no unambiguous transformation model.
 */
function nova_divi_unsupported_content_format_error( $format ) {
    return new WP_Error(
        'nova_divi_unsupported_content_format',
        'Mixed Divi 4 shortcode and native Divi 5 block layouts cannot be transformed safely.',
        array(
            'status'         => 422,
            'content_format' => (string) $format,
        )
    );
}

/**
 * Clone all post_meta from one post to another (except some internal keys).
 */
function nova_divi_clone_post_meta( $source_id, $target_id, $skip_keys = array() ) {
    $all_meta = get_post_meta( $source_id );
    if ( empty( $all_meta ) || ! is_array( $all_meta ) ) {
        return;
    }

    $default_skip_keys = array(
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
    );

    if ( ! empty( $skip_keys ) ) {
        $skip_keys = array_merge( $default_skip_keys, (array) $skip_keys );
    } else {
        $skip_keys = $default_skip_keys;
    }

    $skip_keys = array_unique( $skip_keys );

    foreach ( $all_meta as $key => $values ) {
        if ( in_array( $key, $skip_keys, true ) ) {
            continue;
        }

        delete_post_meta( $target_id, $key );

        foreach ( $values as $value ) {
            add_post_meta( $target_id, $key, maybe_unserialize( $value ) );
        }
    }
}

/**
 * Split a slug/path into a child slug and optional parent path.
 */
function nova_divi_split_slug_path( $slug_path ) {
    $slug_path = trim( (string) $slug_path, '/' );
    if ( '' === $slug_path ) {
        return array( '', '' );
    }

    $parts      = array_values( array_filter( explode( '/', $slug_path ), 'strlen' ) );
    $child_slug = array_pop( $parts );
    $parent     = $parts ? implode( '/', $parts ) : '';

    return array( $child_slug, $parent );
}

/**
 * Normalize meta input and map meta title/description to SEO plugin keys.
 */
function nova_divi_prepare_meta_updates( $params ) {
    $meta = array();

    if ( isset( $params['meta'] ) && is_array( $params['meta'] ) ) {
        $meta = $params['meta'];
    }

    $overwrite_title = array_key_exists( 'meta_title', $params );
    $overwrite_desc  = array_key_exists( 'meta_description', $params );

    $seo_title = null;
    if ( $overwrite_title ) {
        $seo_title = $params['meta_title'];
    } elseif ( isset( $meta['meta_title'] ) ) {
        $seo_title = $meta['meta_title'];
    } elseif ( isset( $meta['title'] ) ) {
        $seo_title = $meta['title'];
    }

    $seo_description = null;
    if ( $overwrite_desc ) {
        $seo_description = $params['meta_description'];
    } elseif ( isset( $meta['meta_description'] ) ) {
        $seo_description = $meta['meta_description'];
    } elseif ( isset( $meta['description'] ) ) {
        $seo_description = $meta['description'];
    }

    if ( null !== $seo_title ) {
        $seo_title = (string) $seo_title;
        foreach ( array( '_yoast_wpseo_title', '_aioseo_title', 'rank_math_title' ) as $key ) {
            if ( $overwrite_title || ! array_key_exists( $key, $meta ) ) {
                $meta[ $key ] = $seo_title;
            }
        }
    }

    if ( null !== $seo_description ) {
        $seo_description = (string) $seo_description;
        foreach ( array( '_yoast_wpseo_metadesc', '_aioseo_description', 'rank_math_description' ) as $key ) {
            if ( $overwrite_desc || ! array_key_exists( $key, $meta ) ) {
                $meta[ $key ] = $seo_description;
            }
        }
    }

    return $meta;
}

/**
 * Apply the suite-wide meta_all contract, then the legacy Divi meta inputs.
 * Legacy/top-level values intentionally win when both forms are supplied.
 */
function nova_divi_apply_request_meta( $post_id, $params ) {
    if ( array_key_exists( 'meta_all', $params ) ) {
        if ( ! function_exists( 'cf_tmrb_update_post_meta_all_payload' ) ) {
            return new WP_Error(
                'missing_dependency',
                'Core meta_all handler not loaded.',
                array( 'status' => 500 )
            );
        }

        $result = cf_tmrb_update_post_meta_all_payload( $params['meta_all'], get_post( $post_id ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
    }

    foreach ( nova_divi_prepare_meta_updates( $params ) as $key => $value ) {
        update_post_meta( $post_id, $key, $value );
    }

    return true;
}

/**
 * The attribute that carries a module's primary visible text, or null when
 * the text lives in the module body.
 *
 * This single map keeps the outline/text_map extraction and text_updates
 * application symmetric: whatever field the client SAW in the outline is the
 * field a text_update for that path writes back to.
 */
function nova_divi_default_text_field_for_tag( $tag ) {
    switch ( (string) $tag ) {
        case 'et_pb_button':
            return 'button_text';
        case 'et_pb_heading':
        case 'et_pb_fullwidth_header':
        case 'et_pb_blurb':
        case 'et_pb_cta':
        case 'et_pb_accordion_item':
        case 'et_pb_toggle':
        case 'et_pb_number_counter':
        case 'et_pb_pricing_table':
            return 'title';
        case 'et_pb_slide':
            return 'heading';
        case 'et_pb_team_member':
            return 'name';
        default:
            return null; // Text lives in the module body (et_pb_text, et_pb_code, et_pb_testimonial, ...).
    }
}

/**
 * Divi structural containers: the only tags whose inner content is parsed
 * into child nodes. Every other module's body is kept as opaque text so
 * nested third-party shortcodes ([gallery], [caption], ...) and their
 * surrounding HTML survive a parse -> serialize round-trip untouched.
 */
function nova_divi_is_structural_container_tag( $tag ) {
    return in_array(
        (string) $tag,
        array(
            'et_pb_section',
            'et_pb_row',
            'et_pb_row_inner',
            'et_pb_column',
            'et_pb_column_inner',
            'et_pb_accordion',
            'et_pb_tabs',
            'et_pb_slider',
            'et_pb_video_slider',
            'et_pb_pricing_tables',
            'et_pb_counters',
            'et_pb_contact_form',
            'et_pb_social_media_follow',
            'et_pb_map',
            'et_pb_fullwidth_slider',
        ),
        true
    );
}

/**
 * Guess label from tag/attributes.
 */
function nova_divi_guess_label_for_tag( $tag, $node ) {
    if ( ! empty( $node['attributes']['admin_label'] ) ) {
        return nova_divi_decode_attr( $node['attributes']['admin_label'] );
    }
    if ( ! empty( $node['attributes']['title'] ) ) {
        return nova_divi_decode_attr( $node['attributes']['title'] );
    }

    switch ( $tag ) {
        case 'et_pb_text':
            return 'Text';
        case 'et_pb_button':
            return 'Button';
        case 'et_pb_blurb':
            return 'Blurb';
        case 'et_pb_cta':
            return 'Call To Action';
        case 'et_pb_accordion':
            return 'Accordion';
        case 'et_pb_accordion_item':
            return 'Accordion Item';
        case 'et_pb_toggle':
            return 'Toggle';
        case 'et_pb_image':
        case 'et_pb_fullwidth_image':
            return 'Image';
        case 'et_pb_fullwidth_header':
            return 'Fullwidth Header';
        case 'et_pb_testimonial':
            return 'Testimonial';
        case 'et_pb_slide':
            return 'Slide';
        case 'et_pb_divider':
            return 'Divider';
        case 'et_pb_code':
            return 'Code';
        default:
            return $tag;
    }
}
