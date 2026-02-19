<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get a slug/path for a post.
 * - For pages: use hierarchical path (parent/child).
 * - For posts: use plain post_name.
 */
function nova_wpb_get_slug_for_post( $post ) {
    if ( 'page' === $post->post_type ) {
        return get_page_uri( $post );
    }
    return $post->post_name;
}

/**
 * Convert value to bool with default.
 */
function nova_wpb_to_bool( $value, $default = false ) {
    if ( null === $value || '' === $value ) {
        return $default;
    }
    return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
}

/**
 * Simple heuristic to check if post has WPBakery layout.
 */
function nova_wpb_has_wpbakery_layout( $post ) {
    $status = get_post_meta( $post->ID, '_wpb_vc_js_status', true );
    if ( 'true' === $status ) {
        return true;
    }
    return ( false !== strpos( $post->post_content, '[vc_' ) );
}

/**
 * Clone all post_meta from one post to another (except some internal keys).
 */
function nova_wpb_clone_post_meta( $source_id, $target_id, $skip_keys = array() ) {
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
function nova_wpb_split_slug_path( $slug_path ) {
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
function nova_wpb_prepare_meta_updates( $params ) {
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
 * Guess label from tag/attributes.
 */
function nova_wpb_guess_label_for_tag( $tag, $node ) {
    if ( ! empty( $node['attributes']['el_class'] ) ) {
        return $node['attributes']['el_class'];
    }
    if ( ! empty( $node['attributes']['title'] ) ) {
        return $node['attributes']['title'];
    }

    switch ( $tag ) {
        case 'vc_column_text':
            return 'Text Block';
        case 'vc_custom_heading':
            return 'Heading';
        case 'vc_btn':
        case 'vc_btn2':
            return 'Button';
        default:
            return $tag;
    }
}