<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Beaver Builder layout handling.
 *
 * BB stores a layout as a FLAT array of node objects keyed by node ID
 * (post meta `_fl_builder_data`):
 *
 *   'a1b2c3d4e5f6g' => (object) [
 *       'node'     => 'a1b2c3d4e5f6g',
 *       'type'     => 'row' | 'column-group' | 'column' | 'module',
 *       'parent'   => '<parent node id>' | null,   // null = top level
 *       'position' => 0,                            // order within parent
 *       'settings' => (object) [ ... ],             // module slug in ->type
 *   ]
 *
 * This file converts that flat array to/from a nested tree so the shared
 * path-based transformations (outline paths, text_updates, remove_paths)
 * work exactly like the WPBakery/Divi modules.
 *
 * Tree node shape (internal, mirrors the other modules' compact nodes):
 *   [
 *     'node_id'  => string|null,   // null = new node, gets an ID on serialize
 *     'type'     => 'row'|'column-group'|'column'|'module',
 *     'settings' => stdClass|array, // OPAQUE except known text fields
 *     'children' => [ ...tree nodes ],
 *   ]
 */

/**
 * Structural node types. Module nodes can also be containers (the Box
 * module): anything other nodes point at as parent is treated as one.
 */
function nova_bb_is_structural_node_type( $type ) {
    return in_array( (string) $type, array( 'row', 'column-group', 'column' ), true );
}

/**
 * Read the raw flat node array from post meta. Returns array() when the post
 * has no layout. Tolerates both stdClass values (normal) and assoc arrays.
 */
function nova_bb_get_layout_nodes( $post_id ) {
    $data = get_post_meta( (int) $post_id, '_fl_builder_data', true );

    if ( empty( $data ) || ! is_array( $data ) ) {
        return array();
    }

    return $data;
}

/**
 * Flat node array -> nested tree.
 *
 * Children are grouped by `parent` and ordered by `position`. Nodes whose
 * parent doesn't exist are lifted to the top level, and nodes unreachable
 * from any top-level node (parent cycles) are appended at the end instead of
 * being dropped — a bridge must never silently lose layout data.
 */
function nova_bb_flat_to_tree( $flat ) {
    if ( empty( $flat ) || ! is_array( $flat ) ) {
        return array();
    }

    $by_id    = array();
    $children = array();
    $insert_i = 0;

    foreach ( $flat as $key => $node ) {
        $node_id = is_string( $key ) && '' !== $key ? $key : (string) nova_bb_setting_get( $node, 'node', '' );
        if ( '' === $node_id ) {
            $node_id = 'nova-tmp-' . $insert_i;
        }

        $parent = nova_bb_setting_get( $node, 'parent', null );
        $parent = ( null === $parent || '' === $parent || false === $parent ) ? null : (string) $parent;

        $by_id[ $node_id ] = array(
            'node_id'  => $node_id,
            'type'     => (string) nova_bb_setting_get( $node, 'type', 'module' ),
            'settings' => nova_bb_setting_get( $node, 'settings', new stdClass() ),
            'orig'     => is_object( $node ) ? $node : null,
            'parent'   => $parent,
            'position' => (int) nova_bb_setting_get( $node, 'position', 0 ),
            'insert'   => $insert_i++,
        );
    }

    foreach ( $by_id as $node_id => $entry ) {
        $parent = ( null !== $entry['parent'] && isset( $by_id[ $entry['parent'] ] ) ) ? $entry['parent'] : '';
        $children[ $parent ][] = $node_id;
    }

    $sort = function ( $ids ) use ( $by_id ) {
        usort(
            $ids,
            function ( $a, $b ) use ( $by_id ) {
                $pa = $by_id[ $a ]['position'];
                $pb = $by_id[ $b ]['position'];
                if ( $pa === $pb ) {
                    return $by_id[ $a ]['insert'] <=> $by_id[ $b ]['insert'];
                }
                return $pa <=> $pb;
            }
        );
        return $ids;
    };

    $visited = array();

    $build = function ( $node_id ) use ( &$build, &$visited, $by_id, $children, $sort ) {
        $visited[ $node_id ] = true;
        $entry               = $by_id[ $node_id ];

        $kids = array();
        if ( ! empty( $children[ $node_id ] ) ) {
            foreach ( $sort( $children[ $node_id ] ) as $child_id ) {
                if ( isset( $visited[ $child_id ] ) ) {
                    continue; // Cycle guard.
                }
                $kids[] = $build( $child_id );
            }
        }

        return array(
            'node_id'  => $entry['node_id'],
            'type'     => $entry['type'],
            'settings' => $entry['settings'],
            'orig'     => $entry['orig'],
            'children' => $kids,
        );
    };

    $tree = array();
    if ( ! empty( $children[''] ) ) {
        foreach ( $sort( $children[''] ) as $root_id ) {
            if ( ! isset( $visited[ $root_id ] ) ) {
                $tree[] = $build( $root_id );
            }
        }
    }

    // Cycle leftovers: lift to top level rather than dropping them.
    foreach ( array_keys( $by_id ) as $node_id ) {
        if ( ! isset( $visited[ $node_id ] ) ) {
            $tree[] = $build( $node_id );
        }
    }

    return $tree;
}

/**
 * Nested tree -> flat node array (keyed by node ID), ready for
 * `update_post_meta(..., '_fl_builder_data', $flat)`.
 *
 * @param array $tree           Tree nodes.
 * @param bool  $regenerate_ids true = fresh IDs for EVERY node (clone mode:
 *                              a cloned page must not share node IDs with its
 *                              source — BB uses them in CSS classes/caches).
 *                              false = keep existing IDs, only new nodes get
 *                              generated ones (update mode).
 */
function nova_bb_tree_to_flat( $tree, $regenerate_ids = false ) {
    $flat = array();
    $used = array();

    // Reserve kept IDs first so generated ones can't collide with them.
    if ( ! $regenerate_ids ) {
        $reserve = function ( $nodes ) use ( &$reserve, &$used ) {
            foreach ( $nodes as $node ) {
                if ( ! empty( $node['node_id'] ) ) {
                    $used[ (string) $node['node_id'] ] = true;
                }
                if ( ! empty( $node['children'] ) ) {
                    $reserve( $node['children'] );
                }
            }
        };
        $reserve( $tree );
    }

    $walk = function ( $nodes, $parent_id ) use ( &$walk, &$flat, &$used, $regenerate_ids ) {
        $position = 0;

        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $node_id = ( ! $regenerate_ids && ! empty( $node['node_id'] ) && 0 !== strpos( (string) $node['node_id'], 'nova-tmp-' ) )
                ? (string) $node['node_id']
                : nova_bb_generate_node_id( $used );

            $node_type = isset( $node['type'] ) ? (string) $node['type'] : 'module';
            $settings  = isset( $node['settings'] ) ? $node['settings'] : new stdClass();
            if ( is_array( $settings ) ) {
                $settings = nova_bb_settings_from_json( $settings );
            }
            if ( 'column-group' === $node_type && '' === $settings ) {
                // Beaver stores column-group settings as an empty string.
            } elseif ( ! is_object( $settings ) ) {
                $settings = new stdClass();
            }

            // Preserve unknown properties from the source node object (BB may
            // add fields we don't model); ours override structure/settings.
            if ( isset( $node['orig'] ) && is_object( $node['orig'] ) ) {
                $obj = clone $node['orig'];
            } else {
                $obj = new stdClass();
            }

            $obj->node     = $node_id;
            $obj->type     = $node_type;
            $obj->parent   = $parent_id;
            $obj->position = $position;
            $obj->settings = $settings;

            $flat[ $node_id ] = $obj;
            $position++;

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $walk( $node['children'], $node_id );
            }
        }
    };

    $walk( $tree, null );

    return $flat;
}

/**
 * Normalize a caller-supplied flat layout (JSON: `layout.nodes`) into the
 * stored shape: assoc-array nodes/settings become stdClass.
 */
function nova_bb_normalize_incoming_nodes( $nodes ) {
    if ( empty( $nodes ) || ! is_array( $nodes ) ) {
        return array();
    }

    $out = array();
    foreach ( $nodes as $key => $node ) {
        $out[ $key ] = nova_bb_settings_from_json( $node );
    }
    return $out;
}

/**
 * The `items` array of an accordion/tabs module, always as a PHP list.
 */
function nova_bb_get_module_items( $settings ) {
    $items = nova_bb_setting_get( $settings, 'items', array() );
    return is_array( $items ) ? array_values( $items ) : array();
}

/**
 * Whether a module keeps its text in an `items` array (accordion, tabs).
 */
function nova_bb_is_items_module( $module ) {
    return in_array( (string) $module, array( 'accordion', 'tabs' ), true );
}

/**
 * Build outline from a tree: a flat list of text-bearing modules,
 * `{path, tag, label, context, text}`.
 *
 * Paths are child-index strings ("0.2.1"). Accordion/tab ITEMS are settings
 * entries, not child nodes, so they get a virtual segment: "0.2.1@0" is the
 * first item of the module at "0.2.1". Paths are opaque to callers — they
 * echo whatever a GET returned — so the extra grammar is invisible to the
 * n8n/AI contract.
 */
function nova_bb_build_outline_from_tree( $tree ) {
    $outline = array();

    $walk = function ( $nodes, $parent_context = '', $prefix = '' ) use ( &$walk, &$outline ) {
        foreach ( $nodes as $idx => $node ) {
            $path_str = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;
            $type     = isset( $node['type'] ) ? (string) $node['type'] : '';
            $settings = isset( $node['settings'] ) ? $node['settings'] : null;

            $context = $parent_context;
            if ( 'row' === $type ) {
                $context = ( $context ? $context . ' > ' : '' ) . 'Row';
            } elseif ( 'column-group' === $type ) {
                $context = ( $context ? $context . ' > ' : '' ) . 'Columns';
            } elseif ( 'column' === $type ) {
                $context = ( $context ? $context . ' > ' : '' ) . 'Column';
            }
            if ( '' === $context ) {
                $context = 'Layout';
            }

            if ( 'module' === $type ) {
                $module = nova_bb_module_slug( $settings );

                if ( nova_bb_is_items_module( $module ) ) {
                    $item_tag = $module . '-item';
                    foreach ( nova_bb_get_module_items( $settings ) as $i => $item ) {
                        $outline[] = array(
                            'path'    => $path_str . '@' . $i,
                            'tag'     => $item_tag,
                            'label'   => 'accordion' === $module ? 'Accordion Item' : 'Tab',
                            'context' => $context . ' > ' . nova_bb_guess_label_for_module( $module, $settings ),
                            'text'    => wp_strip_all_tags( (string) nova_bb_setting_get( $item, 'label', '' ) ),
                        );
                    }
                } else {
                    $field = nova_bb_default_text_field_for_module( $module );
                    $text  = '';

                    if ( null !== $field ) {
                        $text = (string) nova_bb_setting_get( $settings, $field, '' );
                    } elseif ( 'photo' === $module ) {
                        // Display-only: photos have no updatable text.
                        $text = (string) nova_bb_setting_get( $settings, 'caption', '' );
                        if ( '' === $text ) {
                            $text = (string) nova_bb_setting_get( $settings, 'alt', '' );
                        }
                    }

                    if ( null !== $field || 'photo' === $module ) {
                        $outline[] = array(
                            'path'    => $path_str,
                            'tag'     => $module,
                            'label'   => nova_bb_guess_label_for_module( $module, $settings ),
                            'context' => $context,
                            'text'    => $text,
                        );
                    }
                }

                // Container modules (Box) still descend into children below.
                if ( ! empty( $node['children'] ) ) {
                    $context .= ' > ' . nova_bb_guess_label_for_module( $module, $settings );
                }
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $walk( $node['children'], $context, $path_str );
            }
        }
    };

    $walk( $tree );

    return $outline;
}

/**
 * Build text_map = [{path, text}] from a tree.
 */
function nova_bb_build_text_map_from_tree( $tree ) {
    $outline = nova_bb_build_outline_from_tree( $tree );
    $map     = array();

    foreach ( $outline as $node ) {
        $map[] = array(
            'path' => $node['path'],
            'text' => $node['text'],
        );
    }

    return $map;
}

/**
 * Render a plain-HTML fallback of the layout's text content.
 *
 * BB itself writes such a fallback into post_content on every builder save
 * (used when the plugin is deactivated, and read by search / SEO analyzers).
 * This mirrors that for layouts written through the bridge. Never parsed
 * back — `_fl_builder_data` is the single source of truth.
 */
function nova_bb_tree_to_fallback_html( $tree ) {
    $parts = array();

    $walk = function ( $nodes ) use ( &$walk, &$parts ) {
        foreach ( $nodes as $node ) {
            if ( isset( $node['type'] ) && 'module' === $node['type'] ) {
                $settings = isset( $node['settings'] ) ? $node['settings'] : null;
                $module   = nova_bb_module_slug( $settings );

                switch ( $module ) {
                    case 'heading':
                        $tag  = strtolower( (string) nova_bb_setting_get( $settings, 'tag', 'h2' ) );
                        $tag  = preg_match( '/^h[1-6]$/', $tag ) ? $tag : 'h2';
                        $text = trim( wp_strip_all_tags( (string) nova_bb_setting_get( $settings, 'heading', '' ) ) );
                        if ( '' !== $text ) {
                            $parts[] = '<' . $tag . '>' . esc_html( $text ) . '</' . $tag . '>';
                        }
                        break;

                    case 'rich-text':
                        $html = trim( (string) nova_bb_setting_get( $settings, 'text', '' ) );
                        if ( '' !== $html ) {
                            $parts[] = $html;
                        }
                        break;

                    case 'callout':
                    case 'cta':
                        $title = trim( wp_strip_all_tags( (string) nova_bb_setting_get( $settings, 'title', '' ) ) );
                        $body  = trim( (string) nova_bb_setting_get( $settings, 'text', '' ) );
                        if ( '' !== $title ) {
                            $parts[] = '<h3>' . esc_html( $title ) . '</h3>';
                        }
                        if ( '' !== $body ) {
                            $parts[] = $body;
                        }
                        break;

                    case 'accordion':
                    case 'tabs':
                        foreach ( nova_bb_get_module_items( $settings ) as $item ) {
                            $label   = trim( wp_strip_all_tags( (string) nova_bb_setting_get( $item, 'label', '' ) ) );
                            $content = trim( (string) nova_bb_setting_get( $item, 'content', '' ) );
                            if ( '' !== $label ) {
                                $parts[] = '<h3>' . esc_html( $label ) . '</h3>';
                            }
                            if ( '' !== $content ) {
                                $parts[] = $content;
                            }
                        }
                        break;
                }
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $walk( $node['children'] );
            }
        }
    };

    $walk( $tree );

    return implode( "\n", $parts );
}
