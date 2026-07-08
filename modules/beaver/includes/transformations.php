<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Split an outline path into its node path and optional item index.
 * "0.2.1" => ["0.2.1", null]; "0.2.1@3" => ["0.2.1", 3] (accordion/tab item).
 */
function nova_bb_parse_outline_path( $path ) {
    $path = (string) $path;

    if ( false === strpos( $path, '@' ) ) {
        return array( $path, null );
    }

    list( $node_path, $item ) = explode( '@', $path, 2 );

    return array( $node_path, is_numeric( $item ) ? (int) $item : null );
}

/**
 * Tree-node factories for generated layout.
 */
function nova_bb_clone_layout_value( $value ) {
    if ( is_object( $value ) ) {
        $copy = clone $value;
        foreach ( get_object_vars( $copy ) as $key => $child ) {
            $copy->{$key} = nova_bb_clone_layout_value( $child );
        }
        return $copy;
    }

    if ( is_array( $value ) ) {
        return array_map( 'nova_bb_clone_layout_value', $value );
    }

    return $value;
}

function nova_bb_append_prototypes_from_tree( $tree ) {
    $prototypes = array(
        'row'          => null,
        'column-group' => '',
        'column'       => null,
        'modules'      => array(),
    );

    $is_plain_row = function ( $settings ) {
        return 'none' === (string) nova_bb_setting_get( $settings, 'bg_type', '' )
            && in_array( (string) nova_bb_setting_get( $settings, 'full_height', '' ), array( '', 'default' ), true );
    };

    $walk = function ( $nodes ) use ( &$walk, &$prototypes, $is_plain_row ) {
        foreach ( (array) $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $type     = isset( $node['type'] ) ? (string) $node['type'] : '';
            $settings = array_key_exists( 'settings', $node ) ? $node['settings'] : null;

            if ( 'row' === $type ) {
                if ( null === $prototypes['row'] || ( ! $is_plain_row( $prototypes['row'] ) && $is_plain_row( $settings ) ) ) {
                    $prototypes['row'] = nova_bb_clone_layout_value( $settings );
                }
            } elseif ( 'column-group' === $type ) {
                if ( '' === $prototypes['column-group'] ) {
                    $prototypes['column-group'] = nova_bb_clone_layout_value( $settings );
                }
            } elseif ( 'column' === $type ) {
                $size = (string) nova_bb_setting_get( $settings, 'size', '' );
                if ( null === $prototypes['column'] || '100' === $size ) {
                    $prototypes['column'] = nova_bb_clone_layout_value( $settings );
                }
            } elseif ( 'module' === $type ) {
                $module = nova_bb_module_slug( $settings );
                if ( '' !== $module && ! isset( $prototypes['modules'][ $module ] ) ) {
                    $prototypes['modules'][ $module ] = nova_bb_clone_layout_value( $settings );
                }
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $walk( $node['children'] );
            }
        }
    };

    $walk( $tree );

    return $prototypes;
}

function nova_bb_has_module_prototype( $prototypes, $module ) {
    return isset( $prototypes['modules'][ $module ] ) && is_object( $prototypes['modules'][ $module ] );
}

function nova_bb_build_module_node( $module, $settings_pairs = array(), $prototypes = array() ) {
    $settings = isset( $prototypes['modules'][ $module ] )
        ? nova_bb_clone_layout_value( $prototypes['modules'][ $module ] )
        : new stdClass();

    if ( ! is_object( $settings ) ) {
        $settings = new stdClass();
    }

    $settings->type = (string) $module;

    foreach ( (array) $settings_pairs as $key => $value ) {
        $settings->{$key} = $value;
    }

    return array(
        'node_id'  => null,
        'type'     => 'module',
        'settings' => $settings,
        'children' => array(),
    );
}

function nova_bb_build_text_module_node( $html, $prototypes = array() ) {
    return nova_bb_build_module_node( 'rich-text', array( 'text' => (string) $html ), $prototypes );
}

function nova_bb_build_heading_node( $text, $tag = 'h2', $prototypes = array() ) {
    $tag = strtolower( trim( (string) $tag ) );
    if ( ! in_array( $tag, array( 'h2', 'h3', 'h4' ), true ) ) {
        $tag = 'h2';
    }

    return nova_bb_build_module_node(
        'heading',
        array(
            'heading' => wp_strip_all_tags( (string) $text ),
            'tag'     => $tag,
        ),
        $prototypes
    );
}

/**
 * Wrap module nodes in a full row > column-group > column skeleton — the
 * hierarchy BB itself creates (FLBuilderModel::add_row inserts the
 * column-group layer between row and column).
 */
function nova_bb_wrap_in_row( $module_nodes, $prototypes = array() ) {
    $row_settings          = array_key_exists( 'row', $prototypes ) && null !== $prototypes['row']
        ? nova_bb_clone_layout_value( $prototypes['row'] )
        : new stdClass();
    $column_group_settings = array_key_exists( 'column-group', $prototypes )
        ? nova_bb_clone_layout_value( $prototypes['column-group'] )
        : '';
    $column_settings       = array_key_exists( 'column', $prototypes ) && null !== $prototypes['column']
        ? nova_bb_clone_layout_value( $prototypes['column'] )
        : (object) array( 'size' => 100 );

    if ( ! is_object( $column_settings ) ) {
        $column_settings = (object) array( 'size' => 100 );
    }
    nova_bb_setting_set( $column_settings, 'size', 100 );

    $column = array(
        'node_id'  => null,
        'type'     => 'column',
        'settings' => $column_settings,
        'children' => array_values( (array) $module_nodes ),
    );
    $column_group = array(
        'node_id'  => null,
        'type'     => 'column-group',
        'settings' => $column_group_settings,
        'children' => array( $column ),
    );

    return array(
        'node_id'  => null,
        'type'     => 'row',
        'settings' => $row_settings,
        'children' => array( $column_group ),
    );
}

/**
 * Parse FAQ HTML (<h3>Question</h3><p>Answer</p> pairs) into items.
 *
 * @return array [ [ 'title' => string, 'body' => string ], ... ]
 */
function nova_bb_parse_faq_items_from_html( $html ) {
    $html  = (string) $html;
    $items = array();

    if ( ! preg_match_all( '/<h3\b[^>]*>(.*?)<\/h3>\s*([\s\S]*?)(?=<h3\b|$)/i', $html, $ms, PREG_SET_ORDER ) ) {
        return $items;
    }

    foreach ( $ms as $m ) {
        $q = trim( wp_strip_all_tags( (string) $m[1] ) );
        $a = trim( (string) $m[2] );

        if ( '' === $q || '' === $a ) {
            continue;
        }

        $a = wp_kses_post( $a );

        $items[] = array(
            'title' => $q,
            'body'  => $a,
        );
    }

    return $items;
}

/**
 * Build an accordion module node from FAQ items. `open_first` makes BB expand
 * the first item by default (the per-item open flag Divi uses doesn't exist —
 * BB accordions configure this on the module).
 */
function nova_bb_build_accordion_node( $items, $prototypes = array() ) {
    if ( empty( $items ) || ! is_array( $items ) ) {
        return null;
    }

    $bb_items = array();
    foreach ( $items as $item ) {
        $title = isset( $item['title'] ) ? wp_strip_all_tags( (string) $item['title'] ) : '';
        $body  = isset( $item['body'] ) ? (string) $item['body'] : '';

        if ( '' === trim( $title ) || '' === trim( $body ) ) {
            continue;
        }

        $bb_items[] = (object) array(
            'label'   => $title,
            'content' => $body,
        );
    }

    if ( empty( $bb_items ) ) {
        return null;
    }

    return nova_bb_build_module_node(
        'accordion',
        array(
            'items'      => $bb_items,
            'open_first' => 'yes',
        ),
        $prototypes
    );
}

/**
 * Expand a single section containing many <h2> blocks into multiple sections.
 *
 * Input:
 *   [{ title: "...", body: "<h2>A</h2>...<h2>B</h2>...", title_tag: "h2" }]
 *
 * Output:
 *   [
 *     {title:"A", body:"...", title_tag:"h2"},
 *     {title:"B", body:"...", title_tag:"h2", type:"faq"?},
 *     ...
 *   ]
 *
 * FAQ sections ("Veelgestelde vragen") are tagged type=faq so the section
 * builder renders them as an accordion module.
 */
function nova_bb_expand_single_html_section_to_multiple( $sections, $page_title = '' ) {
    if ( ! is_array( $sections ) ) {
        return $sections;
    }
    $sections = array_values( $sections );

    if ( 1 !== count( $sections ) ) {
        return $sections;
    }

    $s = $sections[0];

    $body = isset( $s['body'] ) ? (string) $s['body'] : '';
    $body = trim( $body );

    if ( '' === $body ) {
        return $sections;
    }

    // Must contain multiple h2s to be worth splitting.
    if ( preg_match_all( '/<h2\b[^>]*>.*?<\/h2>/is', $body ) < 2 ) {
        return $sections;
    }

    $new = array();

    // Preserve any preamble before the first <h2>.
    $first_h2_pos = stripos( $body, '<h2' );
    if ( false !== $first_h2_pos && $first_h2_pos > 0 ) {
        $preamble = trim( substr( $body, 0, $first_h2_pos ) );
        if ( '' !== trim( wp_strip_all_tags( $preamble ) ) ) {
            $new[] = array(
                'title'     => isset( $s['title'] ) && '' !== trim( (string) $s['title'] ) ? wp_strip_all_tags( (string) $s['title'] ) : wp_strip_all_tags( (string) $page_title ),
                'body'      => $preamble,
                'title_tag' => 'h2',
            );
        }
    }

    // Split into <h2>Title</h2> + following content until next <h2>.
    if ( preg_match_all( '/<h2\b[^>]*>(.*?)<\/h2>\s*([\s\S]*?)(?=<h2\b|$)/i', $body, $ms, PREG_SET_ORDER ) ) {
        foreach ( $ms as $m ) {
            $title = trim( wp_strip_all_tags( (string) $m[1] ) );
            $chunk = trim( (string) $m[2] );

            if ( '' === $title && '' === trim( wp_strip_all_tags( $chunk ) ) ) {
                continue;
            }

            $section = array(
                'title'     => $title,
                'body'      => $chunk,
                'title_tag' => 'h2',
            );

            // FAQ section => render as accordion downstream.
            if ( '' !== $title && ( false !== stripos( $title, 'veelgestelde vragen' ) || false !== stripos( $title, 'faq' ) ) ) {
                $section['type'] = 'faq';
            }

            $new[] = $section;
        }
    }

    return ! empty( $new ) ? $new : $sections;
}

/**
 * Build the row (tree node) for one appended section: a heading module for
 * the title (BB has a real heading module, unlike Divi 4) plus a rich-text
 * module for the body — or heading + accordion for FAQ sections.
 *
 * @return array|null One row tree node, or null when the section is empty.
 */
function nova_bb_build_section_row( $section, $page_title = '', $prototypes = array() ) {
    $title     = isset( $section['title'] ) ? (string) $section['title'] : '';
    $body      = isset( $section['body'] ) ? (string) $section['body'] : '';
    $title_tag = isset( $section['title_tag'] ) ? strtolower( trim( (string) $section['title_tag'] ) ) : 'h2';
    $type      = isset( $section['type'] ) ? strtolower( (string) $section['type'] ) : '';

    if ( ! in_array( $title_tag, array( 'h2', 'h3', 'h4' ), true ) ) {
        $title_tag = 'h2';
    }

    // Never emit H1 inside module bodies — the post title is the H1.
    $body = str_ireplace( array( '<h1', '</h1>' ), array( '<h2', '</h2>' ), $body );
    $body = wp_kses_post( $body );

    $title_clean = trim( wp_strip_all_tags( $title ) );

    // FAQ section type: use accordion only when the template proves it renders safely.
    if ( 'faq' === $type && nova_bb_has_module_prototype( $prototypes, 'accordion' ) ) {
        $items = nova_bb_parse_faq_items_from_html( $body );

        if ( empty( $items ) && '' !== trim( $body ) ) {
            // No h3 Q&A structure — fall back to a single item.
            $items = array(
                array(
                    'title' => '' !== $title_clean ? $title_clean : 'FAQ',
                    'body'  => $body,
                ),
            );
        }

        $accordion = nova_bb_build_accordion_node( $items, $prototypes );
        if ( null === $accordion ) {
            return null;
        }

        $modules = array();
        if ( '' !== $title_clean ) {
            $modules[] = nova_bb_build_heading_node( $title_clean, $title_tag, $prototypes );
        }
        $modules[] = $accordion;

        return nova_bb_wrap_in_row( $modules, $prototypes );
    }

    if ( '' === trim( $body ) && '' === $title_clean ) {
        return null;
    }

    $modules = array();
    if ( '' !== $title_clean ) {
        $modules[] = nova_bb_build_heading_node( $title_clean, $title_tag, $prototypes );
    }
    if ( '' !== trim( $body ) ) {
        $modules[] = nova_bb_build_text_module_node( $body, $prototypes );
    }

    return nova_bb_wrap_in_row( $modules, $prototypes );
}

/**
 * Apply transformations to a layout tree: remove_paths / text_updates /
 * append_*. Tree in, tree out.
 */
function nova_bb_apply_transformations( $tree, $remove_paths, $text_updates, $append_html, $append_sections ) {
    $tree = is_array( $tree ) ? $tree : array();

    if (
        empty( $remove_paths )
        && empty( $text_updates )
        && '' === $append_html
        && empty( $append_sections )
    ) {
        return $tree;
    }

    // Text updates FIRST: both path sets come from the same GET outline, and
    // removing nodes re-indexes siblings — applying removals first would make
    // the text_update paths land on the wrong nodes.
    if ( ! empty( $text_updates ) ) {
        $tree = nova_bb_apply_text_updates_to_tree( $tree, $text_updates );
    }
    if ( ! empty( $remove_paths ) ) {
        $tree = nova_bb_remove_paths_from_tree( $tree, $remove_paths );
    }

    $append_prototypes = ( '' !== $append_html || ! empty( $append_sections ) )
        ? nova_bb_append_prototypes_from_tree( $tree )
        : array();

    // Append HTML as one extra text module in its own row.
    if ( '' !== $append_html ) {
        $safe_html = (string) $append_html;
        $safe_html = str_ireplace( array( '<h1', '</h1>' ), array( '<h2', '</h2>' ), $safe_html );
        $safe_html = wp_kses_post( $safe_html );

        $tree[] = nova_bb_wrap_in_row( array( nova_bb_build_text_module_node( $safe_html, $append_prototypes ) ), $append_prototypes );
    }

    // Append sections.
    if ( ! empty( $append_sections ) && is_array( $append_sections ) ) {
        foreach ( $append_sections as $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }
            $row = nova_bb_build_section_row( $section, '', $append_prototypes );
            if ( null !== $row ) {
                $tree[] = $row;
            }
        }
    }

    return $tree;
}

/**
 * Remove nodes (or accordion/tab items, via "path@i") whose path is in
 * remove_paths.
 */
function nova_bb_remove_paths_from_tree( $tree, $paths ) {
    $node_paths = array();
    $item_paths = array(); // node path => [item indices]

    foreach ( (array) $paths as $path ) {
        list( $node_path, $item ) = nova_bb_parse_outline_path( $path );

        if ( null === $item ) {
            $node_paths[] = $node_path;
        } else {
            $item_paths[ $node_path ][] = $item;
        }
    }

    $walk = function ( $nodes, $prefix = '' ) use ( &$walk, $node_paths, $item_paths ) {
        $result = array();

        foreach ( $nodes as $idx => $node ) {
            $path = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;

            if ( in_array( $path, $node_paths, true ) ) {
                continue;
            }

            if ( isset( $item_paths[ $path ] ) && isset( $node['settings'] ) ) {
                $items = nova_bb_get_module_items( $node['settings'] );
                $keep  = array();
                foreach ( $items as $i => $item ) {
                    if ( ! in_array( $i, $item_paths[ $path ], true ) ) {
                        $keep[] = $item;
                    }
                }
                nova_bb_setting_set( $node['settings'], 'items', $keep );
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $node['children'] = $walk( $node['children'], $path );
            }

            $result[] = $node;
        }

        return $result;
    };

    return $walk( $tree );
}

/**
 * Apply text_updates to a tree via outline paths.
 *
 * The default target field per module comes from
 * nova_bb_default_text_field_for_module() — the SAME map the outline/text_map
 * extraction uses, so an update always writes to the field the client saw:
 * - rich-text: settings->text (rich HTML)
 * - heading: settings->heading (plain), button: settings->text (plain),
 *   callout/cta: settings->title (plain), html: settings->html
 * - "path@i" item paths: the item's label; {field:"body"} its content
 * - explicit {field: "..."} overrides: "body"/"content" targets the module's
 *   body field, any other name targets that settings key (plain text)
 * - photo/separator/unknown modules without a text field: skipped
 */
function nova_bb_apply_text_updates_to_tree( $tree, $updates ) {
    $node_map = array();
    $item_map = array(); // node path => [item index => update]

    foreach ( (array) $updates as $update ) {
        if ( ! is_array( $update ) || empty( $update['path'] ) ) {
            continue;
        }

        list( $node_path, $item ) = nova_bb_parse_outline_path( $update['path'] );

        $entry = array(
            'text'  => isset( $update['text'] ) ? (string) $update['text'] : '',
            'field' => isset( $update['field'] ) ? strtolower( trim( (string) $update['field'] ) ) : '',
        );

        if ( null === $item ) {
            $node_map[ $node_path ] = $entry;
        } else {
            $item_map[ $node_path ][ $item ] = $entry;
        }
    }

    $apply_to_node = function ( &$node, $entry ) {
        if ( ! isset( $node['settings'] ) || null === $node['settings'] ) {
            $node['settings'] = new stdClass();
        }

        $module = nova_bb_module_slug( $node['settings'] );
        $field  = $entry['field'];
        $text   = $entry['text'];

        if ( 'body' === $field || 'content' === $field ) {
            $target = nova_bb_body_field_for_module( $module );
            if ( null === $target ) {
                $target = nova_bb_default_text_field_for_module( $module );
            }
            if ( null !== $target ) {
                nova_bb_setting_set( $node['settings'], $target, wp_kses_post( $text ) );
            }
            return;
        }

        if ( '' !== $field ) {
            $key = sanitize_key( $field );
            if ( 'type' !== $key && '' !== $key ) { // Never let an update change the module type.
                $value = nova_bb_field_is_rich( $module, $key ) ? wp_kses_post( $text ) : wp_strip_all_tags( $text );
                nova_bb_setting_set( $node['settings'], $key, $value );
            }
            return;
        }

        $target = nova_bb_default_text_field_for_module( $module );
        if ( null !== $target ) {
            $value = nova_bb_field_is_rich( $module, $target ) ? wp_kses_post( $text ) : wp_strip_all_tags( $text );
            nova_bb_setting_set( $node['settings'], $target, $value );
        }
        // Modules without a text field (photo, separator, unknown): skipped.
    };

    $apply_to_items = function ( &$node, $updates_by_index ) {
        if ( ! isset( $node['settings'] ) ) {
            return;
        }

        $items = nova_bb_get_module_items( $node['settings'] );

        foreach ( $updates_by_index as $i => $entry ) {
            if ( ! isset( $items[ $i ] ) ) {
                continue;
            }

            if ( 'body' === $entry['field'] || 'content' === $entry['field'] ) {
                nova_bb_setting_set( $items[ $i ], 'content', wp_kses_post( $entry['text'] ) );
            } elseif ( '' !== $entry['field'] ) {
                $key = sanitize_key( $entry['field'] );
                if ( '' !== $key ) {
                    nova_bb_setting_set( $items[ $i ], $key, wp_strip_all_tags( $entry['text'] ) );
                }
            } else {
                nova_bb_setting_set( $items[ $i ], 'label', wp_strip_all_tags( $entry['text'] ) );
            }
        }

        nova_bb_setting_set( $node['settings'], 'items', $items );
    };

    $walk = function ( $nodes, $prefix = '' ) use ( &$walk, $node_map, $item_map, $apply_to_node, $apply_to_items ) {
        foreach ( $nodes as $idx => &$node ) {
            $path = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;

            if ( array_key_exists( $path, $node_map ) ) {
                $apply_to_node( $node, $node_map[ $path ] );
            }
            if ( isset( $item_map[ $path ] ) ) {
                $apply_to_items( $node, $item_map[ $path ] );
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $node['children'] = $walk( $node['children'], $path );
            }
        }

        return $nodes;
    };

    return $walk( $tree );
}

/**
 * Whether a tree node is a CONTENT slot (article body) as opposed to template
 * chrome (hero subtitle, CTA copy, footer note, ...).
 *
 * A content slot is a rich-text module that is empty (placeholder), contains
 * an h2/h3/h4 heading, or holds paragraph-scale text. Short heading-less
 * texts are template chrome: they are neither filled nor cleared, so cloned
 * branding copy survives — use text_updates/remove_paths to change those.
 */
function nova_bb_is_content_slot_node( $node ) {
    if ( ! is_array( $node ) || ! isset( $node['type'] ) || 'module' !== (string) $node['type'] ) {
        return false;
    }
    if ( 'rich-text' !== nova_bb_module_slug( isset( $node['settings'] ) ? $node['settings'] : null ) ) {
        return false;
    }

    $body    = (string) nova_bb_setting_get( $node['settings'], 'text', '' );
    $visible = trim( wp_strip_all_tags( $body ) );

    if ( '' === $visible ) {
        return true; // Empty placeholder.
    }
    if ( preg_match( '/<h[234]\b/i', $body ) ) {
        return true; // Heading-led content block.
    }

    return strlen( $visible ) >= 240; // Paragraph-scale body text.
}

/**
 * Replace template content slots with sections while preserving the template
 * layout (clone mode).
 *
 * Beaver Builder version: content slots are rich-text modules that pass
 * nova_bb_is_content_slot_node(). Sections are written into them sequentially
 * (heading as <h2> HTML inside the rich-text body — pairing a template's
 * separate heading modules with their text modules is deliberately out of
 * scope; edit those via text_updates). Remaining unfilled content slots are
 * removed afterwards (and rows/column-groups/columns they leave empty are
 * pruned) so no stale example content or empty bands survive. Sections that
 * don't fit — plus FAQ sections, which need an accordion module — are
 * returned for appending.
 */
function nova_bb_replace_template_slots_with_sections( $tree, $sections, $page_title = '', $clear_remaining = true ) {
    $tree     = is_array( $tree ) ? $tree : array();
    $sections = is_array( $sections ) ? array_values( $sections ) : array();

    if ( empty( $tree ) || empty( $sections ) ) {
        return array( $tree, $sections );
    }

    $page_title_norm = strtolower( trim( wp_strip_all_tags( (string) $page_title ) ) );

    foreach ( $sections as &$s ) {
        $s['title'] = isset( $s['title'] ) ? trim( wp_strip_all_tags( (string) $s['title'] ) ) : '';
        $body       = isset( $s['body'] ) ? (string) $s['body'] : '';

        $body      = str_ireplace( array( '<h1', '</h1>' ), array( '<h2', '</h2>' ), $body );
        $body      = wp_kses_post( $body );
        $s['body'] = $body;

        $tag = isset( $s['title_tag'] ) ? strtolower( trim( (string) $s['title_tag'] ) ) : 'h2';
        if ( ! in_array( $tag, array( 'h2', 'h3', 'h4' ), true ) ) {
            $tag = 'h2';
        }
        $s['title_tag'] = $tag;
    }
    unset( $s );

    $section_i         = 0;
    $first_slot_filled = false;

    // FAQ sections can't live in a text module — keep them for appending.
    $fillable = array();
    $deferred = array();
    foreach ( $sections as $section ) {
        if ( isset( $section['type'] ) && 'faq' === strtolower( (string) $section['type'] ) ) {
            $deferred[] = $section;
        } else {
            $fillable[] = $section;
        }
    }

    $walk_fill = function ( &$nodes ) use ( &$walk_fill, $fillable, &$section_i, $page_title_norm, &$first_slot_filled ) {
        foreach ( $nodes as &$node ) {
            if ( nova_bb_is_content_slot_node( $node ) && $section_i < count( $fillable ) ) {
                $sec_title = $fillable[ $section_i ]['title'];
                $sec_body  = $fillable[ $section_i ]['body'];
                $sec_tag   = $fillable[ $section_i ]['title_tag'];

                $html = '';

                // Don't repeat the page title as a heading in the first slot.
                $sec_title_norm = strtolower( trim( $sec_title ) );
                $emit_title     = ( '' !== $sec_title );
                if ( ! $first_slot_filled && '' !== $page_title_norm && $sec_title_norm === $page_title_norm ) {
                    $emit_title = false;
                }

                if ( $emit_title ) {
                    $html .= '<' . $sec_tag . '>' . esc_html( $sec_title ) . '</' . $sec_tag . '>';
                }
                $html .= $sec_body;

                nova_bb_setting_set( $node['settings'], 'text', $html );
                $node['__nova_keep'] = true;

                $first_slot_filled = true;
                $section_i++;
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $walk_fill( $node['children'] );
            }
        }
    };

    $walk_fill( $tree );

    if ( $clear_remaining ) {
        // Remove unfilled content slots entirely (stale example article text),
        // then prune rows/column-groups/columns that end up with no children
        // so the page doesn't render empty bands. Template chrome (headings,
        // short texts, callouts, buttons, photos) is intentionally untouched.
        $walk_prune = function ( $nodes ) use ( &$walk_prune ) {
            $result = array();

            foreach ( $nodes as $node ) {
                if ( nova_bb_is_content_slot_node( $node ) && empty( $node['__nova_keep'] ) ) {
                    continue;
                }

                if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                    $node['children'] = $walk_prune( $node['children'] );
                }

                $type = isset( $node['type'] ) ? (string) $node['type'] : '';
                if ( nova_bb_is_structural_node_type( $type ) && empty( $node['children'] ) ) {
                    continue;
                }

                $result[] = $node;
            }

            return $result;
        };

        $tree = $walk_prune( $tree );
    }

    $remaining = $deferred;
    if ( $section_i < count( $fillable ) ) {
        $remaining = array_merge( array_slice( $fillable, $section_i ), $deferred );
    }

    return array( $tree, $remaining );
}
