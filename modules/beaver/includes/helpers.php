<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get a slug/path for a post.
 * - For pages: use hierarchical path (parent/child).
 * - For posts: use plain post_name.
 */
function nova_bb_get_slug_for_post( $post ) {
    if ( 'page' === $post->post_type ) {
        return get_page_uri( $post );
    }
    return $post->post_name;
}

/**
 * Convert value to bool with default.
 */
function nova_bb_to_bool( $value, $default = false ) {
    if ( null === $value || '' === $value ) {
        return $default;
    }
    return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
}

/**
 * The Beaver Builder version, when the plugin is active in this request.
 */
function nova_bb_builder_version() {
    if ( defined( 'FL_BUILDER_VERSION' ) && '' !== (string) FL_BUILDER_VERSION ) {
        return (string) FL_BUILDER_VERSION;
    }
    return null;
}

/**
 * Whether Beaver Builder is active (its classes are loadable in this request).
 */
function nova_bb_builder_active() {
    return class_exists( 'FLBuilderModel' );
}

/**
 * Generate a Beaver Builder style node ID: ~13 lowercase alphanumerics,
 * unique within this request. Uses FLBuilderModel when available so IDs match
 * whatever collision rules BB applies.
 */
function nova_bb_generate_node_id( &$used = null ) {
    $tries = 0;

    do {
        if ( nova_bb_builder_active() && method_exists( 'FLBuilderModel', 'generate_node_id' ) ) {
            $id = (string) FLBuilderModel::generate_node_id();
        } else {
            $id = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 13 );
        }
        $tries++;
    } while ( is_array( $used ) && isset( $used[ $id ] ) && $tries < 100 );

    if ( is_array( $used ) ) {
        $used[ $id ] = true;
    }

    return $id;
}

/**
 * Read a key from a BB settings container (stdClass in stored layouts, assoc
 * array when a layout arrives as JSON). Never assume one shape.
 */
function nova_bb_setting_get( $settings, $key, $default = '' ) {
    if ( is_object( $settings ) && isset( $settings->{$key} ) ) {
        return $settings->{$key};
    }
    if ( is_array( $settings ) && isset( $settings[ $key ] ) ) {
        return $settings[ $key ];
    }
    return $default;
}

/**
 * Write a key into a BB settings container in place, preserving its shape.
 */
function nova_bb_setting_set( &$settings, $key, $value ) {
    if ( is_array( $settings ) ) {
        $settings[ $key ] = $value;
        return;
    }
    if ( ! is_object( $settings ) ) {
        $settings = new stdClass();
    }
    $settings->{$key} = $value;
}

/**
 * Convert JSON-decoded settings (assoc arrays) into the stdClass shape BB
 * stores: associative arrays become objects, sequential arrays (e.g. an
 * accordion's `items`) stay arrays with converted elements.
 */
function nova_bb_settings_from_json( $value ) {
    if ( is_object( $value ) ) {
        $value = get_object_vars( $value );
        $obj   = new stdClass();
        foreach ( $value as $k => $v ) {
            $obj->{$k} = nova_bb_settings_from_json( $v );
        }
        return $obj;
    }

    if ( is_array( $value ) ) {
        if ( array() === $value ) {
            return array(); // Empty lists (e.g. `items`) must stay arrays.
        }

        $is_list = ( array_keys( $value ) === range( 0, count( $value ) - 1 ) );

        if ( $is_list ) {
            return array_map( 'nova_bb_settings_from_json', $value );
        }

        $obj = new stdClass();
        foreach ( $value as $k => $v ) {
            $obj->{$k} = nova_bb_settings_from_json( $v );
        }
        return $obj;
    }

    return $value;
}

/**
 * Recursively addslashes() every string in a layout structure before
 * update_post_meta(). WP unslashes meta values with map_deep — which DOES
 * descend into stdClass — but wp_slash() does NOT, so without this any
 * backslash inside module text would be silently stripped on save. BB's own
 * saves slash settings for the same reason (FLBuilderModel::slash_settings).
 */
function nova_bb_slash_layout_data( $value ) {
    if ( is_string( $value ) ) {
        return addslashes( $value );
    }

    if ( is_array( $value ) ) {
        return array_map( 'nova_bb_slash_layout_data', $value );
    }

    if ( is_object( $value ) ) {
        $out = clone $value;
        foreach ( get_object_vars( $out ) as $k => $v ) {
            $out->{$k} = nova_bb_slash_layout_data( $v );
        }
        return $out;
    }

    return $value;
}

/**
 * The module slug of a module node's settings (`settings->type`).
 */
function nova_bb_module_slug( $settings ) {
    return (string) nova_bb_setting_get( $settings, 'type', '' );
}

/**
 * Simple heuristic to check if a post has a Beaver Builder layout.
 */
function nova_bb_has_bb_layout( $post ) {
    if ( nova_bb_to_bool( get_post_meta( $post->ID, '_fl_builder_enabled', true ), false ) ) {
        return true;
    }
    $data = get_post_meta( $post->ID, '_fl_builder_data', true );
    return ! empty( $data );
}

/**
 * Clone all post_meta from one post to another (except some internal keys).
 * BB draft meta is always skipped: the target must not inherit a stale
 * builder session, or opening the builder shows the source's draft instead
 * of the layout this bridge writes.
 */
function nova_bb_clone_post_meta( $source_id, $target_id, $skip_keys = array() ) {
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
        '_fl_builder_draft',
        '_fl_builder_draft_settings',
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
function nova_bb_split_slug_path( $slug_path ) {
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
function nova_bb_prepare_meta_updates( $params ) {
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
 * The settings field that carries a module's primary visible text — the field
 * the outline SHOWS and the field a default (field-less) text_update writes
 * back to. Returns null for modules with no updatable primary text (photo,
 * separator, ...): those appear in outlines display-only.
 *
 * NOTE: verified against BB docs; the exact callout/cta field names
 * (`title` vs `heading`) are confirmed on the first live site before launch.
 */
function nova_bb_default_text_field_for_module( $module ) {
    switch ( (string) $module ) {
        case 'rich-text':
            return 'text';
        case 'heading':
            return 'heading';
        case 'button':
            return 'text';
        case 'callout':
        case 'cta':
            return 'title';
        case 'html':
            return 'html';
        default:
            return null;
    }
}

/**
 * The settings field an explicit {field:"body"} text_update targets: the
 * module's rich body HTML. Null when the module has no body concept.
 */
function nova_bb_body_field_for_module( $module ) {
    switch ( (string) $module ) {
        case 'rich-text':
            return 'text';
        case 'callout':
        case 'cta':
            return 'text';
        case 'html':
            return 'html';
        default:
            return null;
    }
}

/**
 * Whether a module settings field holds rich HTML (sanitized with
 * wp_kses_post) as opposed to plain text (stripped of tags).
 */
function nova_bb_field_is_rich( $module, $field ) {
    $module = (string) $module;
    $field  = (string) $field;

    if ( 'rich-text' === $module && 'text' === $field ) {
        return true;
    }
    if ( 'html' === $module && 'html' === $field ) {
        return true;
    }
    if ( in_array( $module, array( 'callout', 'cta' ), true ) && 'text' === $field ) {
        return true;
    }
    return false;
}

/**
 * Guess a human label for an outline entry.
 */
function nova_bb_guess_label_for_module( $module, $settings = null ) {
    $name = trim( (string) nova_bb_setting_get( $settings, 'name', '' ) );
    if ( '' !== $name ) {
        return wp_strip_all_tags( $name );
    }

    switch ( (string) $module ) {
        case 'rich-text':
            return 'Text';
        case 'heading':
            return 'Heading';
        case 'button':
            return 'Button';
        case 'callout':
            return 'Callout';
        case 'cta':
            return 'Call To Action';
        case 'accordion':
            return 'Accordion';
        case 'tabs':
            return 'Tabs';
        case 'photo':
            return 'Photo';
        case 'html':
            return 'HTML';
        case 'video':
            return 'Video';
        case 'separator':
            return 'Separator';
        default:
            return '' !== (string) $module ? (string) $module : 'Module';
    }
}
