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
    return ( false !== strpos( (string) $post->post_content, '[et_pb_' ) );
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
