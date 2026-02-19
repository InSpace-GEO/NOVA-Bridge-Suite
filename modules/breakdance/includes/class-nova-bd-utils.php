<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Nova_BD_Utils {

    /**
     * Get raw Breakdance data from meta.
     *
     * IMPORTANT: we now return the value as-is (array OR string),
     * instead of forcing it to be a string.
     */
    public static function get_raw_breakdance_data( $post_id ) {
        $raw = get_post_meta( $post_id, '_breakdance_data', true );
        if ( ! $raw ) {
            $raw = get_post_meta( $post_id, 'breakdance_data', true );
        }
        return $raw; // can be array or string
    }

    /**
     * Normalize Breakdance meta value to the JSON string format Breakdance expects.
     *
     * Breakdance stores JSON-encoded (and slashed) strings in post meta. If we
     * write arrays or unserialized strings, the builder can't decode the data
     * and the editing canvas appears empty. This helper converts any supported
     * input (array, serialized array, JSON string) to the expected JSON string
     * and applies wp_slash so it mirrors Breakdance\Data\set_meta().
     */
    public static function normalize_breakdance_meta_value( $value ) {
        // Convert arrays or serialized arrays to JSON.
        if ( is_array( $value ) ) {
            $value = wp_json_encode( $value );
        } elseif ( is_string( $value ) ) {
            $maybe_unserialized = maybe_unserialize( $value );

            if ( is_array( $maybe_unserialized ) ) {
                $value = wp_json_encode( $maybe_unserialized );
            } else {
                $decoded = json_decode( $value, true );
                if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
                    $value = wp_json_encode( $decoded );
                }
            }
        }

        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return '';
        }

        return wp_slash( $value );
    }

    /**
     * Update both legacy and underscored Breakdance meta keys with normalized data.
     */
    public static function update_breakdance_meta( $post_id, $raw_value ) {
        $normalized = self::normalize_breakdance_meta_value( $raw_value );

        if ( '' === $normalized ) {
            return;
        }

        update_post_meta( $post_id, '_breakdance_data', $normalized );
        update_post_meta( $post_id, 'breakdance_data', $normalized );
    }

    /**
     * Decode Breakdance tree wrapper.
     *
     * Returns:
     *   [
     *     'raw'         => (mixed) original meta value (array|string),
     *     'decoded'     => (array) meta value normalized to array,
     *     'inner'       => (array) inner tree (for tree_json_string),
     *     'root'        => (array) root node,
     *     'root_source' => 'tree_json_string' | 'root' | 'element',
     *   ] or null.
     */
    public static function decode_breakdance_tree( $raw ) {
        if ( empty( $raw ) ) {
            return null;
        }

        $original_raw = $raw;
        $decoded      = null;

        // 1) If it's already an array, trust it.
        if ( is_array( $raw ) ) {
            $decoded = $raw;
        } else {
            // 2) Try WordPress-style serialization.
            $maybe_unserialized = maybe_unserialize( $raw );
            if ( is_array( $maybe_unserialized ) ) {
                $decoded = $maybe_unserialized;
            } else {
                // 3) Treat as JSON (with some forgiveness).
                $json_candidate = (string) $maybe_unserialized;
                if ( '' === trim( $json_candidate ) ) {
                    return null;
                }

                $decoded = json_decode( $json_candidate, true );

                if ( ! is_array( $decoded ) ) {
                    // Try again after un-slashing and trimming quotes.
                    $stripped = trim( $json_candidate, "\"'" );
                    $stripped = stripslashes( $stripped );
                    $decoded  = json_decode( $stripped, true );
                }

                if ( ! is_array( $decoded ) ) {
                    return null;
                }
            }
        }

        // Case 1: tree_json_string wrapper (most common).
        if ( isset( $decoded['tree_json_string'] ) && is_string( $decoded['tree_json_string'] ) ) {
            $inner_json = $decoded['tree_json_string'];
            $inner      = json_decode( $inner_json, true );

            if ( ! is_array( $inner ) ) {
                $inner = json_decode( stripslashes( $inner_json ), true );
            }

            if ( ! is_array( $inner ) || ! isset( $inner['root'] ) || ! is_array( $inner['root'] ) ) {
                return null;
            }

            return array(
                'raw'         => $original_raw,
                'decoded'     => $decoded,
                'inner'       => $inner,
                'root'        => $inner['root'],
                'root_source' => 'tree_json_string',
            );
        }

        // Case 2: plain { "root": { ... } }.
        if ( isset( $decoded['root'] ) && is_array( $decoded['root'] ) ) {
            return array(
                'raw'         => $original_raw,
                'decoded'     => $decoded,
                'root'        => $decoded['root'],
                'root_source' => 'root',
            );
        }

        // Case 3: single element export.
        if ( isset( $decoded['element'] ) && is_array( $decoded['element'] ) ) {
            $root = array(
                'id'       => 1,
                'data'     => array(
                    'type'       => 'root',
                    'properties' => array(),
                ),
                'children' => $decoded['element'],
            );

            return array(
                'raw'         => $original_raw,
                'decoded'     => $decoded,
                'root'        => $root,
                'root_source' => 'element',
            );
        }

        return null;
    }

    /**
     * Build a fresh, empty Breakdance document wrapper in tree_json_string format.
     * We store 'raw' as ARRAY so encode() can preserve that type.
     */
    public static function build_empty_document_wrapper() {
        $root = array(
            'id'       => 1,
            'data'     => array(
                'type'       => 'root',
                'properties' => array(),
            ),
            'children' => array(),
        );

        $inner = array(
            'root'        => $root,
            '_nextNodeId' => 2,
            'status'      => 'exported',
        );

        $decoded = array(
            'tree_json_string' => wp_json_encode( $inner ),
        );

        return array(
            'raw'         => $decoded, // note: array
            'decoded'     => $decoded,
            'inner'       => $inner,
            'root'        => $root,
            'root_source' => 'tree_json_string',
        );
    }

    /**
     * Encode Breakdance tree back to meta.
     *
     * IMPORTANT: preserves the original type:
     *  - if original meta was an array, returns array
     *  - if original meta was a string, returns JSON string
     */
    public static function encode_breakdance_tree( $wrapper, $new_root ) {
        if ( ! is_array( $wrapper ) || ! isset( $wrapper['root_source'] ) ) {
            $wrapper = self::build_empty_document_wrapper();
        }

        $decoded = isset( $wrapper['decoded'] ) && is_array( $wrapper['decoded'] ) ? $wrapper['decoded'] : array();
        $raw     = isset( $wrapper['raw'] ) ? $wrapper['raw'] : null;
        $source  = $wrapper['root_source'];

        if ( 'tree_json_string' === $source ) {
            $inner = isset( $wrapper['inner'] ) && is_array( $wrapper['inner'] )
                ? $wrapper['inner']
                : array(
                    'root'        => $new_root,
                    '_nextNodeId' => 2,
                    'status'      => 'exported',
                );

            $inner['root']               = $new_root;
            $decoded['tree_json_string'] = wp_json_encode( $inner );

        } elseif ( 'root' === $source ) {
            $decoded['root'] = $new_root;

        } elseif ( 'element' === $source ) {
            $decoded = array(
                'root' => $new_root,
            );

        } else {
            // Fallback.
            $decoded = array(
                'root' => $new_root,
            );
        }

        // Preserve original type.
        if ( is_array( $raw ) ) {
            return $decoded;
        }

        return wp_json_encode( $decoded );
    }

    /**
     * Build outline + text_map from Breakdance root node.
     */
    public static function build_outline_and_text_map_from_tree( $root, &$outline, &$text_map, $text_map_style = 'compact', $text_map_max_chars = 0, $text_map_scope = 'content', $text_map_include_media = false ) {
        $outline  = array();
        $text_map = array();

        $style            = self::normalize_text_map_style( $text_map_style );
        $include_text_map = ( '' !== $style );
        $max_chars        = is_numeric( $text_map_max_chars ) ? (int) $text_map_max_chars : 0;
        $scope            = self::normalize_text_map_scope( $text_map_scope );
        $include_media    = self::to_bool_value( $text_map_include_media );
        $seen             = array();

        $walk = function ( $nodes, $parent_context = '', $prefix = '' ) use ( &$walk, &$outline, &$text_map, $include_text_map, $style, $max_chars, $scope, $include_media, &$seen ) {
            if ( ! is_array( $nodes ) ) {
                return;
            }

            foreach ( $nodes as $idx => $node ) {
                if ( ! is_array( $node ) ) {
                    continue;
                }

                $path = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;

                $data       = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();
                $type       = isset( $data['type'] ) ? (string) $data['type'] : '';
                $properties = isset( $data['properties'] ) && is_array( $data['properties'] ) ? $data['properties'] : array();

                $context = $parent_context;
                if ( 'EssentialElements\\Section' === $type ) {
                    $context = ( $context ? $context . ' > ' : '' ) . 'Section';
                } elseif ( 'EssentialElements\\Div' === $type || 'EssentialElements\\Container' === $type ) {
                    $context = ( $context ? $context . ' > ' : '' ) . 'Div';
                }
                if ( '' === $context ) {
                    $context = 'Breakdance';
                }

                list( $text, $is_text_element ) = self::extract_text_from_properties( $type, $properties );

                if ( $is_text_element && '' !== trim( $text ) ) {
                    $outline[] = array(
                        'path'    => $path,
                        'type'    => $type,
                        'label'   => self::guess_label_for_type( $type, $properties ),
                        'context' => $context,
                        'text'    => $text,
                    );

                }

                if ( $include_text_map ) {
                    self::collect_content_text_fields(
                        $properties,
                        $path,
                        $type,
                        $context,
                        $style,
                        $max_chars,
                        $scope,
                        $include_media,
                        $text_map,
                        $seen
                    );
                }

                if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                    $walk( $node['children'], $context, $path );
                }
            }
        };

        $children = isset( $root['children'] ) && is_array( $root['children'] ) ? $root['children'] : array();
        $walk( $children );
    }

    protected static function normalize_text_map_scope( $scope ) {
        if ( ! is_string( $scope ) ) {
            return 'content';
        }

        $scope = strtolower( trim( $scope ) );

        if ( 'all' === $scope || 'properties' === $scope ) {
            return 'all';
        }

        return 'content';
    }

    protected static function to_bool_value( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_string( $value ) ) {
            $value = strtolower( trim( $value ) );
            if ( 'true' === $value || '1' === $value || 'yes' === $value ) {
                return true;
            }
            if ( 'false' === $value || '0' === $value || 'no' === $value ) {
                return false;
            }
        }

        return (bool) $value;
    }

    protected static function normalize_text_map_style( $style ) {
        if ( ! is_string( $style ) ) {
            return 'compact';
        }

        $style = strtolower( trim( $style ) );

        if ( '' === $style || 'none' === $style || 'false' === $style ) {
            return '';
        }

        if ( 'full' === $style ) {
            return 'full';
        }

        if ( 'keys' === $style || 'minimal' === $style ) {
            return 'keys';
        }

        return 'compact';
    }

    protected static function collect_content_text_fields( $properties, $node_path, $type, $context, $style, $max_chars, $scope, $include_media, &$text_map, &$seen ) {
        if ( ! is_array( $properties ) ) {
            return;
        }

        $skip_prefixes = array();
        if ( 'EssentialElements\\FrequentlyAskedQuestions' === $type ) {
            if (
                isset( $properties['content']['settings']['items'] )
                && isset( $properties['content']['settings']['questions'] )
            ) {
                $skip_prefixes[] = 'content.settings.questions';
            }
        }

        $label = self::guess_label_for_type( $type, $properties );

        if ( 'all' === $scope ) {
            foreach ( $properties as $key => $value ) {
                $key_string = (string) $key;

                if ( '' === $key_string ) {
                    continue;
                }

                if ( self::should_skip_content_branch( $key_string, $value, $include_media ) ) {
                    continue;
                }

                self::walk_content_fields(
                    $value,
                    $key_string,
                    $node_path,
                    $type,
                    $label,
                    $context,
                    $style,
                    $max_chars,
                    $include_media,
                    $text_map,
                    $seen,
                    $skip_prefixes
                );
            }
        } elseif ( isset( $properties['content'] ) && is_array( $properties['content'] ) ) {
            self::walk_content_fields(
                $properties['content'],
                'content',
                $node_path,
                $type,
                $label,
                $context,
                $style,
                $max_chars,
                $include_media,
                $text_map,
                $seen,
                $skip_prefixes
            );
        }
    }

    protected static function walk_content_fields( $value, $prop_path, $node_path, $type, $label, $context, $style, $max_chars, $include_media, &$text_map, &$seen, $skip_prefixes = array() ) {
        if ( is_string( $value ) ) {
            if ( self::should_include_content_text( $prop_path, $value ) ) {
                self::append_text_map_entry(
                    $node_path,
                    $prop_path,
                    $value,
                    $type,
                    $label,
                    $context,
                    $style,
                    $max_chars,
                    $text_map,
                    $seen
                );
            }
            return;
        }

        if ( ! is_array( $value ) ) {
            return;
        }

        foreach ( $value as $key => $child ) {
            $key_string = (string) $key;

            if ( self::should_skip_content_branch( $key_string, $child, $include_media ) ) {
                continue;
            }

            $child_path = '' === $prop_path ? $key_string : $prop_path . '.' . $key_string;

            if ( self::should_skip_prefix( $child_path, $skip_prefixes ) ) {
                continue;
            }

            self::walk_content_fields(
                $child,
                $child_path,
                $node_path,
                $type,
                $label,
                $context,
                $style,
                $max_chars,
                $include_media,
                $text_map,
                $seen,
                $skip_prefixes
            );
        }
    }

    protected static function should_skip_content_branch( $key, $value, $include_media ) {
        if ( ! is_array( $value ) ) {
            return false;
        }

        $blocked = array(
            'sizes',
            'attributes',
            'srcset',
            'source',
            'sources',
            'icon',
            'icons',
            'svg',
            'background',
            'style',
            'styles',
            'design',
            'layout',
            'spacing',
            'typography',
            'shadow',
            'border',
            'color',
            'colors',
            'filters',
            'animation',
            'responsive',
            'breakpoints',
            'tokens',
        );

        $key = strtolower( $key );

        if ( ! $include_media ) {
            $blocked = array_merge(
                $blocked,
                array( 'media', 'image', 'images', 'gallery' )
            );
        }

        return in_array( $key, $blocked, true );
    }

    protected static function should_include_content_text( $prop_path, $value ) {
        $value = (string) $value;

        if ( '' === trim( $value ) ) {
            return false;
        }

        if ( false !== stripos( $value, '<svg' ) ) {
            return false;
        }

        $segments = explode( '.', $prop_path );
        $leaf     = strtolower( (string) end( $segments ) );

        $blocked_leaf = array(
            'id',
            'slug',
            'type',
            'tag',
            'tags',
            'rel',
            'target',
            'mime',
            'filename',
            'extension',
            'class',
            'classes',
            'size',
            'width',
            'height',
            'unit',
            'style',
            'styles',
            'color',
            'colors',
            'background',
            'position',
            'align',
            'alignment',
        );

        if ( in_array( $leaf, $blocked_leaf, true ) ) {
            return false;
        }

        if ( ! preg_match( '/[\p{L}]/u', $value ) && false === strpos( $value, '<' ) ) {
            if ( preg_match( '/\d/', $value ) && self::prop_path_is_textish( $prop_path ) ) {
                return true;
            }
            return false;
        }

        return true;
    }

    protected static function prop_path_is_textish( $prop_path ) {
        $segments = explode( '.', (string) $prop_path );
        $leaf     = strtolower( (string) end( $segments ) );

        $textish = array(
            'text',
            'content',
            'title',
            'label',
            'heading',
            'subheading',
            'subtitle',
            'caption',
            'description',
            'desc',
            'question',
            'answer',
            'cta',
            'button',
            'name',
            'quote',
            'price',
            'value',
            'amount',
            'number',
            'stat',
            'stats',
        );

        return in_array( $leaf, $textish, true );
    }

    protected static function append_text_map_entry( $node_path, $prop_path, $text, $type, $label, $context, $style, $max_chars, &$text_map, &$seen ) {
        $field_key = $node_path . '|' . $prop_path;

        if ( isset( $seen[ $field_key ] ) ) {
            return;
        }

        $seen[ $field_key ] = true;

        $entry = array(
            'field_key' => $field_key,
            'path'      => $node_path,
            'prop_path' => $prop_path,
            'kind'      => self::infer_text_kind( $prop_path, $text ),
        );

        if ( 'keys' !== $style ) {
            $entry['text'] = self::truncate_text_map_value( $text, $max_chars );
        }

        if ( 'full' === $style ) {
            $entry['type']    = $type;
            $entry['label']   = $label;
            $entry['context'] = $context;
        }

        $text_map[] = $entry;
    }

    protected static function truncate_text_map_value( $text, $max_chars ) {
        $text = (string) $text;

        if ( $max_chars <= 0 ) {
            return $text;
        }

        if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
            if ( mb_strlen( $text ) > $max_chars ) {
                return mb_substr( $text, 0, $max_chars );
            }

            return $text;
        }

        if ( strlen( $text ) > $max_chars ) {
            return substr( $text, 0, $max_chars );
        }

        return $text;
    }

    protected static function should_skip_prefix( $path, $prefixes ) {
        if ( empty( $prefixes ) ) {
            return false;
        }

        foreach ( $prefixes as $prefix ) {
            if ( 0 === strpos( $path, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    protected static function infer_text_kind( $prop_path, $value ) {
        $value = (string) $value;

        if ( false !== strpos( $value, '<' ) && false !== strpos( $value, '>' ) ) {
            return 'html';
        }

        $normalized = strtolower( $prop_path );

        if ( false !== strpos( $normalized, 'url' ) || false !== strpos( $normalized, 'href' ) ) {
            return 'url';
        }

        if ( preg_match( '#^(https?:)?//#i', $value ) || '/' === substr( $value, 0, 1 ) ) {
            return 'url';
        }

        return 'text';
    }

    public static function clone_breakdance_meta( $source_id, $dest_id, $options = array() ) {
        $source_id = (int) $source_id;
        $dest_id   = (int) $dest_id;

        if ( $source_id <= 0 || $dest_id <= 0 || $source_id === $dest_id ) {
            return;
        }

        $prefixes = array( '_breakdance_', 'breakdance_' );
        $allow    = array();
        $skip     = array(
            '_breakdance_data',
            'breakdance_data',
            '_breakdance_dependency_cache',
            'breakdance_dependency_cache',
            '_breakdance_css_file_paths_cache',
            'breakdance_css_file_paths_cache',
            '_breakdance_template_last_previewed_item',
            'template_last_previewed_item',
        );

        if ( is_array( $options ) ) {
            if ( ! empty( $options['clone_meta_prefixes'] ) && is_array( $options['clone_meta_prefixes'] ) ) {
                $prefixes = array_merge( $prefixes, $options['clone_meta_prefixes'] );
            }
            if ( ! empty( $options['clone_meta_keys'] ) && is_array( $options['clone_meta_keys'] ) ) {
                $allow = array_merge( $allow, $options['clone_meta_keys'] );
            }
            if ( ! empty( $options['skip_meta_keys'] ) && is_array( $options['skip_meta_keys'] ) ) {
                $skip = array_merge( $skip, $options['skip_meta_keys'] );
            }
        }

        $prefixes = apply_filters( 'nova_bd_clone_meta_prefixes', $prefixes, $source_id, $dest_id, $options );
        $allow    = apply_filters( 'nova_bd_clone_meta_keys', $allow, $source_id, $dest_id, $options );
        $skip     = apply_filters( 'nova_bd_clone_meta_skip_keys', $skip, $source_id, $dest_id, $options );

        $prefixes = array_values( array_filter( array_map( 'strval', (array) $prefixes ) ) );
        $allow    = array_values( array_filter( array_map( 'strval', (array) $allow ) ) );
        $skip     = array_values( array_filter( array_map( 'strval', (array) $skip ) ) );

        $all_meta = get_post_meta( $source_id );
        if ( empty( $all_meta ) || ! is_array( $all_meta ) ) {
            return;
        }

        foreach ( $all_meta as $key => $values ) {
            $key = (string) $key;

            if ( in_array( $key, $skip, true ) ) {
                continue;
            }

            $allowed = in_array( $key, $allow, true );

            if ( ! $allowed ) {
                foreach ( $prefixes as $prefix ) {
                    if ( '' !== $prefix && 0 === strpos( $key, $prefix ) ) {
                        $allowed = true;
                        break;
                    }
                }
            }

            if ( ! $allowed ) {
                continue;
            }

            if ( ! is_array( $values ) ) {
                $values = array( $values );
            }

            delete_post_meta( $dest_id, $key );

            foreach ( $values as $value ) {
                add_post_meta( $dest_id, $key, $value );
            }
        }
    }

    /**
     * Extract text from Breakdance element properties.
     */
    public static function extract_text_from_properties( $type, $properties ) {
        $text            = '';
        $is_text_element = false;

        if ( isset( $properties['content']['content']['text'] ) && is_string( $properties['content']['content']['text'] ) ) {
            $text            = (string) $properties['content']['content']['text'];
            $is_text_element = true;
        }

        return array( $text, $is_text_element );
    }

    /**
     * Apply remove_paths / text_updates / append_html / append_sections.
     */
    public static function apply_transformations( $raw_bd_data, $remove_paths, $text_updates, $append_html, $append_sections ) {
        $wrapper = null;

        if ( ! empty( $raw_bd_data ) ) {
            $wrapper = self::decode_breakdance_tree( $raw_bd_data );
        }

        // If no existing tree but some transform requested, start with an empty document.
        if ( ! $wrapper && ( ! empty( $remove_paths ) || ! empty( $text_updates ) || '' !== $append_html || ! empty( $append_sections ) ) ) {
            $wrapper = self::build_empty_document_wrapper();
        }

        if ( ! $wrapper ) {
            return $raw_bd_data;
        }

        $root = $wrapper['root'];

        // 1) Remove paths.
        if ( ! empty( $remove_paths ) ) {
            $paths            = array_map( 'strval', (array) $remove_paths );
            $root['children'] = self::remove_paths_from_children(
                isset( $root['children'] ) ? $root['children'] : array(),
                '',
                $paths
            );
        }

        // 2) Apply text_updates (node text or property paths).
        if ( ! empty( $text_updates ) ) {
            list( $node_map, $prop_map ) = self::normalize_text_updates( $text_updates );

            if ( ! empty( $node_map ) ) {
                $root['children'] = self::apply_text_updates_to_children(
                    isset( $root['children'] ) ? $root['children'] : array(),
                    '',
                    $node_map
                );
            }

            if ( ! empty( $prop_map ) ) {
                $root['children'] = self::apply_property_updates_to_children(
                    isset( $root['children'] ) ? $root['children'] : array(),
                    '',
                    $prop_map
                );
            }
        }

        // 2.5) If we have a template + append_sections, try to blend content into
        // the existing layout before appending new blocks to the bottom.
        if ( ! empty( $append_sections ) && is_array( $append_sections ) ) {
            list( $root, $append_sections ) = self::merge_sections_into_template( $root, $append_sections );
        }

        // 3) Append raw HTML as a simple Section > Div > Text block.
        if ( '' !== $append_html ) {
            $root['children'][] = self::build_simple_text_section( $append_html );
        }

        // 4) Append structured sections (Heading + Text).
        if ( ! empty( $append_sections ) && is_array( $append_sections ) ) {
            foreach ( $append_sections as $section ) {
                $title     = isset( $section['title'] ) ? $section['title'] : '';
                $body      = isset( $section['body'] ) ? $section['body'] : '';
                $title_tag = isset( $section['title_tag'] ) ? $section['title_tag'] : 'h2';

                $root['children'][] = self::build_heading_text_section( $title, $body, $title_tag );
            }
        }

        $wrapper['root'] = $root;

        return self::encode_breakdance_tree( $wrapper, $root );
    }

    /**
     * Attempt to blend append_sections into existing template sections.
     *
     * We walk top-level Section nodes and replace/update their heading/text
     * content with the provided sections. Any remaining sections are returned
     * so the caller can append them after the template layout.
     */
    protected static function merge_sections_into_template( $root, $append_sections ) {
        if ( empty( $append_sections ) || ! isset( $root['children'] ) || ! is_array( $root['children'] ) ) {
            return array( $root, $append_sections );
        }

        $remaining = $append_sections;

        // First pass: fill every detected column slot across all template
        // sections, so existing grids are populated before any fallbacks.
        $sections = array();

        foreach ( $root['children'] as $idx => &$child ) {
            if ( self::is_section_node( $child ) ) {
                $sections[] = array(
                    'index' => $idx,
                    'node'  => &$child,
                    'used'  => false,
                );
            }
        }

        if ( empty( $sections ) ) {
            return array( $root, $remaining );
        }

        foreach ( $sections as &$section_ref ) {
            if ( empty( $remaining ) ) {
                break;
            }

            $slots = self::find_column_slots( $section_ref['node'] );

            if ( empty( $slots ) ) {
                continue;
            }

            foreach ( $slots as &$slot_node ) {
                if ( empty( $remaining ) ) {
                    break 2;
                }

                $section_data = array_shift( $remaining );
                $filled       = self::fill_node_with_content( $slot_node, $section_data );

                if ( $filled ) {
                    $section_ref['used'] = true;
                }
            }
        }

        // Second pass: if content remains, distribute through broader column
        // groups (rows) so deeper layouts also get filled before appending.
        if ( ! empty( $remaining ) ) {
            foreach ( $sections as &$section_ref ) {
                if ( empty( $remaining ) ) {
                    break;
                }

                $groups = self::find_column_groups( $section_ref['node'] );

                if ( empty( $groups ) ) {
                    continue;
                }

                foreach ( $groups as &$targets ) {
                    foreach ( $targets as &$target_node ) {
                        if ( empty( $remaining ) ) {
                            break 3;
                        }

                        $section_data = array_shift( $remaining );
                        $filled       = self::fill_node_with_content( $target_node, $section_data );

                        if ( $filled ) {
                            $section_ref['used'] = true;
                        }
                    }
                }
            }
        }

        // Final pass: if no layout slots were found in a section but content
        // remains, at least seed the section container so nothing is skipped.
        if ( ! empty( $remaining ) ) {
            foreach ( $sections as &$section_ref ) {
                if ( empty( $remaining ) ) {
                    break;
                }

                $section_data = array_shift( $remaining );
                $filled       = self::fill_node_with_content( $section_ref['node'], $section_data );

                if ( $filled ) {
                    $section_ref['used'] = true;
                }
            }
        }

        // Remove unused template sections so placeholder elements don't linger
        // when fewer sections are supplied than the template expects.
        if ( ! empty( $sections ) ) {
            foreach ( array_reverse( $sections ) as $section_ref ) {
                if ( ! empty( $section_ref['used'] ) ) {
                    continue;
                }

                unset( $root['children'][ $section_ref['index'] ] );
            }

            $root['children'] = array_values( $root['children'] );
        }

        return array( $root, $remaining );
    }

    /**
     * Fill a Section node with heading + body content, injecting nodes when
     * placeholders aren't present.
     *
     * When a Section contains multiple column containers (e.g., Div/Container
     * siblings), we distribute consecutive section payloads across those
     * columns so template layouts with grids stay populated instead of falling
     * back to a single column. The remaining queue is mutated as items are
     * consumed.
     */
    protected static function fill_section_with_content( &$section_node, &$remaining_sections ) {
        if ( empty( $remaining_sections ) ) {
            return 0;
        }

        $slots    = self::find_column_slots( $section_node );
        $consumed = 0;

        if ( ! empty( $slots ) ) {
            foreach ( $slots as &$slot_node ) {
                if ( empty( $remaining_sections ) ) {
                    break;
                }

                $section_data = array_shift( $remaining_sections );
                self::fill_node_with_content( $slot_node, $section_data );
                $consumed++;
            }
        }

        if ( $consumed > 0 && empty( $remaining_sections ) ) {
            return $consumed;
        }

        $groups = self::find_column_groups( $section_node );

        if ( ! empty( $groups ) ) {
            foreach ( $groups as &$targets ) {
                foreach ( $targets as &$target_node ) {
                    if ( empty( $remaining_sections ) ) {
                        break 2;
                    }

                    $section_data = array_shift( $remaining_sections );
                    self::fill_node_with_content( $target_node, $section_data );
                    $consumed++;
                }
            }

            if ( $consumed > 0 ) {
                return $consumed;
            }
        }

        $section_data = array_shift( $remaining_sections );
        self::fill_node_with_content( $section_node, $section_data );

        return 1;
    }

    /**
     * Collect ordered column groups inside a Section, capturing each level
     * that exposes multiple layout containers. This allows distribution across
     * several rows instead of only the first detected group.
     */
    protected static function find_column_groups( &$section_node ) {
        if ( ! isset( $section_node['children'] ) || ! is_array( $section_node['children'] ) ) {
            return array();
        }

        $groups = array();

        $search = function ( &$nodes ) use ( &$search, &$groups ) {
            if ( ! is_array( $nodes ) || empty( $nodes ) ) {
                return;
            }

            $column_candidates = array();

            foreach ( $nodes as &$node ) {
                if ( self::is_column_container( $node ) ) {
                    $column_candidates[] = &$node;
                }
            }

            if ( count( $column_candidates ) > 1 ) {
                $groups[] = $column_candidates;

                foreach ( $column_candidates as &$candidate_node ) {
                    if ( ! empty( $candidate_node['children'] ) ) {
                        $search( $candidate_node['children'] );
                    }
                }
            }

            $layout_candidates = array();

            foreach ( $nodes as &$node ) {
                if ( self::is_layout_container( $node ) ) {
                    $layout_candidates[] = &$node;
                }
            }

            if ( count( $layout_candidates ) > 1 ) {
                $groups[] = $layout_candidates;

                foreach ( $layout_candidates as &$candidate_node ) {
                    if ( ! empty( $candidate_node['children'] ) ) {
                        $search( $candidate_node['children'] );
                    }
                }
            } elseif ( 1 === count( $layout_candidates ) && ! empty( $layout_candidates[0]['children'] ) ) {
                $search( $layout_candidates[0]['children'] );
            }

            foreach ( $nodes as &$node ) {
                if ( ! empty( $node['children'] ) ) {
                    $search( $node['children'] );
                }
            }
        };

        $search( $section_node['children'] );

        return $groups;
    }

    /**
     * Collect leaf column-like containers so we can prioritize filling existing
     * layout slots before appending any extra sections to the template.
     *
     * A "slot" is the deepest column-type node in a branch; if a node contains
     * nested column containers, we keep descending until we reach the leaves so
     * we inject content into the actual grid cells rather than their wrappers.
     */
    protected static function find_column_slots( &$section_node ) {
        $slots = array();

        $walk = function ( &$node ) use ( &$walk, &$slots ) {
            if ( ! is_array( $node ) || empty( $node['children'] ) ) {
                return;
            }

            $children = &$node['children'];

            foreach ( $children as &$child ) {
                if ( self::is_column_container( $child ) ) {
                    $child_has_columns = false;

                    if ( ! empty( $child['children'] ) ) {
                        foreach ( $child['children'] as &$grandchild ) {
                            if ( self::is_column_container( $grandchild ) ) {
                                $child_has_columns = true;
                                break;
                            }
                        }
                    }

                    if ( $child_has_columns ) {
                        $walk( $child );
                    } else {
                        $slots[] = &$child;
                    }
                } else {
                    $walk( $child );
                }
            }
        };

        $walk( $section_node );

        return $slots;
    }

    /**
     * Fill the provided node (Section/Div/Container) with heading + text
     * content, injecting nodes when placeholders aren't present.
     */
    protected static function fill_node_with_content( &$node, $section_data ) {
        $title     = isset( $section_data['title'] ) ? $section_data['title'] : '';
        $body      = isset( $section_data['body'] ) ? $section_data['body'] : '';
        $title_tag = isset( $section_data['title_tag'] ) ? $section_data['title_tag'] : 'h2';

        $inserted = false;

        if ( ! isset( $node['children'] ) || ! is_array( $node['children'] ) ) {
            $node['children'] = array();
        }

        if ( '' !== $title ) {
            $updated_heading = self::update_first_node_by_type(
                $node['children'],
                array( 'EssentialElements\\Heading' ),
                function ( &$heading_node ) use ( $title, $title_tag ) {
                    self::set_heading_content( $heading_node, $title, $title_tag );
                }
            );

            if ( ! $updated_heading ) {
                array_unshift( $node['children'], self::build_heading_node( $title, $title_tag ) );
            }

            $inserted = true;
        }

        if ( '' !== $body ) {
            $updated_text = self::update_first_node_by_type(
                $node['children'],
                array( 'EssentialElements\\Text' ),
                function ( &$text_node ) use ( $body ) {
                    self::set_text_content( $text_node, $body );
                }
            );

            if ( ! $updated_text ) {
                $node['children'][] = self::build_text_node( $body );
            }

            $inserted = true;
        }

        return $inserted;
    }

    protected static function is_section_node( $node ) {
        if ( ! is_array( $node ) ) {
            return false;
        }

        $type = isset( $node['data']['type'] ) ? (string) $node['data']['type'] : '';

        return 'EssentialElements\\Section' === $type;
    }

    protected static function is_column_container( $node ) {
        if ( ! is_array( $node ) ) {
            return false;
        }

        $type = isset( $node['data']['type'] ) ? (string) $node['data']['type'] : '';

        // Treat only explicit column/grid-like nodes as column containers so we
        // don't prematurely group generic Div/Container wrappers and skip the
        // actual grid columns nested inside them.
        $explicit_column_container = in_array(
            $type,
            array(
                'EssentialElements\\Columns',
                'EssentialElements\\Column',
                'EssentialElements\\Row',
                'EssentialElements\\Grid',
                'EssentialElements\\GridItem',
            ),
            true
        );

        if ( $explicit_column_container ) {
            return true;
        }

        return self::node_declares_layout_columns( $node );
    }

    /**
     * Detect layout hints encoded in element properties so we can treat
     * Sections (or generic containers) that are configured as grids/rows as
     * column containers even when they don't use explicit Grid/Column
     * elements.
     */
    protected static function node_declares_layout_columns( $node ) {
        if ( ! is_array( $node ) || empty( $node['children'] ) ) {
            return false;
        }

        if ( isset( $node['data']['properties'] ) && is_array( $node['data']['properties'] ) ) {
            return self::properties_indicate_layout_columns( $node['data']['properties'] );
        }

        return false;
    }

    protected static function properties_indicate_layout_columns( $properties ) {
        foreach ( $properties as $key => $value ) {
            $normalized_key = strtolower( (string) $key );

            if ( is_string( $value ) ) {
                $normalized_value = strtolower( $value );

                if ( self::value_suggests_multi_column_layout( $normalized_key, $normalized_value ) ) {
                    return true;
                }
            } elseif ( is_array( $value ) ) {
                if ( self::properties_indicate_layout_columns( $value ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    protected static function value_suggests_multi_column_layout( $key, $value ) {
        $has_layout_key = in_array( $key, array( 'layout', 'layouttype', 'layout_type', 'display' ), true );

        if ( $has_layout_key ) {
            return ( false !== strpos( $value, 'grid' ) )
                || ( false !== strpos( $value, 'column' ) )
                || ( false !== strpos( $value, 'row' ) );
        }

        // Fall back to scanning generic string values for explicit layout modes
        // used by Breakdance controls, e.g. "grid" or "columns" stored deeper
        // in nested property arrays.
        return in_array( $value, array( 'grid', 'columns', 'column', 'rows', 'row' ), true );
    }

    /**
     * Identify layout containers that can house content. We consider common
     * Breakdance grid wrappers as well as any element with children that is
     * not a simple text/heading node.
     */
    protected static function is_layout_container( $node ) {
        if ( self::is_column_container( $node ) ) {
            return true;
        }

        if ( ! is_array( $node ) ) {
            return false;
        }

        $type = isset( $node['data']['type'] ) ? (string) $node['data']['type'] : '';

        if ( ! empty( $node['children'] ) && ! self::is_textual_element_type( $type ) ) {
            return true;
        }

        return false;
    }

    protected static function is_textual_element_type( $type ) {
        return in_array(
            $type,
            array(
                'EssentialElements\\Heading',
                'EssentialElements\\Text',
                'EssentialElements\\Button',
                'EssentialElements\\Icon',
            ),
            true
        );
    }

    /**
     * Walk children depth-first and update the first node that matches any of
     * the provided types. Returns true when updated.
     */
    protected static function update_first_node_by_type( &$nodes, $target_types, $callback ) {
        if ( ! is_array( $nodes ) ) {
            return false;
        }

        foreach ( $nodes as &$node ) {
            $type = isset( $node['data']['type'] ) ? (string) $node['data']['type'] : '';

            if ( in_array( $type, (array) $target_types, true ) ) {
                $callback( $node );
                return true;
            }

            if ( ! empty( $node['children'] ) ) {
                $updated = self::update_first_node_by_type( $node['children'], $target_types, $callback );
                if ( $updated ) {
                    return true;
                }
            }
        }

        return false;
    }

    protected static function set_heading_content( &$node, $text, $tag ) {
        if ( ! isset( $node['data']['properties'] ) || ! is_array( $node['data']['properties'] ) ) {
            $node['data']['properties'] = array();
        }

        if ( ! isset( $node['data']['properties']['content'] ) || ! is_array( $node['data']['properties']['content'] ) ) {
            $node['data']['properties']['content'] = array();
        }

        if ( ! isset( $node['data']['properties']['content']['content'] ) || ! is_array( $node['data']['properties']['content']['content'] ) ) {
            $node['data']['properties']['content']['content'] = array();
        }

        $node['data']['properties']['content']['content']['text'] = wp_strip_all_tags( $text );
        $node['data']['properties']['content']['content']['tags'] = $tag;
    }

    protected static function set_text_content( &$node, $text ) {
        if ( ! isset( $node['data']['properties'] ) || ! is_array( $node['data']['properties'] ) ) {
            $node['data']['properties'] = array();
        }

        if ( ! isset( $node['data']['properties']['content'] ) || ! is_array( $node['data']['properties']['content'] ) ) {
            $node['data']['properties']['content'] = array();
        }

        if ( ! isset( $node['data']['properties']['content']['content'] ) || ! is_array( $node['data']['properties']['content']['content'] ) ) {
            $node['data']['properties']['content']['content'] = array();
        }

        $node['data']['properties']['content']['content']['text'] = $text;
    }

    protected static function build_heading_node( $text, $tag = 'h2' ) {
        static $id_counter = 250000;
        $heading_id       = $id_counter++;

        return array(
            'id'       => $heading_id,
            'data'     => array(
                'type'       => 'EssentialElements\\Heading',
                'properties' => array(
                    'content' => array(
                        'content' => array(
                            'text' => wp_strip_all_tags( $text ),
                            'tags' => $tag,
                        ),
                    ),
                ),
            ),
            'children' => array(),
        );
    }

    protected static function build_text_node( $text ) {
        static $id_counter = 260000;
        $text_id          = $id_counter++;

        return array(
            'id'       => $text_id,
            'data'     => array(
                'type'       => 'EssentialElements\\Text',
                'properties' => array(
                    'content' => array(
                        'content' => array(
                            'text' => $text,
                        ),
                    ),
                ),
            ),
            'children' => array(),
        );
    }

    protected static function remove_paths_from_children( $nodes, $prefix, $paths ) {
        $result = array();

        foreach ( $nodes as $idx => $node ) {
            $path = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;

            if ( in_array( $path, $paths, true ) ) {
                continue;
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $node['children'] = self::remove_paths_from_children( $node['children'], $path, $paths );
            }

            $result[] = $node;
        }

        return $result;
    }

    protected static function apply_text_updates_to_children( $nodes, $prefix, $map ) {
        foreach ( $nodes as $idx => &$node ) {
            $path = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;

            if ( array_key_exists( $path, $map ) ) {
                $text = $map[ $path ];

                if ( isset( $node['data']['properties'] ) && is_array( $node['data']['properties'] ) ) {
                    $props = &$node['data']['properties'];

                    if ( isset( $props['content']['content']['text'] ) ) {
                        $props['content']['content']['text'] = $text;
                    } else {
                        $string_keys = array();
                        foreach ( $props as $k => $v ) {
                            if ( is_string( $v ) ) {
                                $string_keys[] = $k;
                            }
                        }
                        if ( 1 === count( $string_keys ) ) {
                            $props[ $string_keys[0] ] = $text;
                        } else {
                            if ( ! isset( $props['content'] ) || ! is_array( $props['content'] ) ) {
                                $props['content'] = array();
                            }
                            if ( ! isset( $props['content']['content'] ) || ! is_array( $props['content']['content'] ) ) {
                                $props['content']['content'] = array();
                            }
                            $props['content']['content']['text'] = $text;
                        }
                    }
                }
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $node['children'] = self::apply_text_updates_to_children( $node['children'], $path, $map );
            }
        }

        return $nodes;
    }

    protected static function normalize_text_updates( $updates ) {
        $node_map = array();
        $prop_map = array();

        foreach ( $updates as $update ) {
            if ( ! is_array( $update ) ) {
                continue;
            }

            $text = '';
            if ( array_key_exists( 'text', $update ) ) {
                $text = (string) $update['text'];
            } elseif ( array_key_exists( 'value', $update ) ) {
                $text = (string) $update['value'];
            } else {
                continue;
            }

            $path      = '';
            $prop_path = '';

            if ( ! empty( $update['field_key'] ) && is_string( $update['field_key'] ) ) {
                $field_key = (string) $update['field_key'];
                if ( false !== strpos( $field_key, '|' ) ) {
                    list( $path, $prop_path ) = explode( '|', $field_key, 2 );
                } else {
                    $path = $field_key;
                }
            }

            if ( '' === $path && array_key_exists( 'path', $update ) ) {
                $path = (string) $update['path'];
            }

            if ( array_key_exists( 'prop', $update ) ) {
                $prop_path = (string) $update['prop'];
            } elseif ( array_key_exists( 'prop_path', $update ) ) {
                $prop_path = (string) $update['prop_path'];
            }

            if ( false !== strpos( $path, '|' ) ) {
                list( $path, $prop_path ) = explode( '|', $path, 2 );
            }

            $path      = trim( $path );
            $prop_path = trim( $prop_path );

            if ( '' === $path ) {
                continue;
            }

            if ( '' !== $prop_path ) {
                if ( ! isset( $prop_map[ $path ] ) ) {
                    $prop_map[ $path ] = array();
                }
                $prop_map[ $path ][ $prop_path ] = $text;
            } else {
                $node_map[ $path ] = $text;
            }
        }

        return array( $node_map, $prop_map );
    }

    protected static function apply_property_updates_to_children( $nodes, $prefix, $updates ) {
        foreach ( $nodes as $idx => &$node ) {
            $path = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;

            if ( isset( $updates[ $path ] ) && is_array( $updates[ $path ] ) ) {
                if ( isset( $node['data']['properties'] ) && is_array( $node['data']['properties'] ) ) {
                    $node_type = isset( $node['data']['type'] ) ? (string) $node['data']['type'] : '';

                    foreach ( $updates[ $path ] as $prop_path => $value ) {
                        self::set_property_path_value(
                            $node['data']['properties'],
                            $prop_path,
                            $value
                        );

                        if ( 'EssentialElements\\FrequentlyAskedQuestions' === $node_type ) {
                            $alias_path = self::get_faq_alias_prop_path( $prop_path );
                            if ( '' !== $alias_path && $alias_path !== $prop_path ) {
                                self::set_property_path_value(
                                    $node['data']['properties'],
                                    $alias_path,
                                    $value
                                );
                            }
                        }
                    }
                }
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $node['children'] = self::apply_property_updates_to_children( $node['children'], $path, $updates );
            }
        }

        return $nodes;
    }

    protected static function get_faq_alias_prop_path( $prop_path ) {
        if ( ! is_string( $prop_path ) || '' === $prop_path ) {
            return '';
        }

        if ( false !== strpos( $prop_path, 'content.settings.questions.' ) ) {
            return str_replace( 'content.settings.questions.', 'content.settings.items.', $prop_path );
        }

        if ( false !== strpos( $prop_path, 'content.settings.items.' ) ) {
            return str_replace( 'content.settings.items.', 'content.settings.questions.', $prop_path );
        }

        return '';
    }

    protected static function set_property_path_value( &$properties, $prop_path, $value ) {
        if ( ! is_array( $properties ) ) {
            return false;
        }

        $segments = explode( '.', (string) $prop_path );
        $last_idx = count( $segments ) - 1;
        $ref      = &$properties;

        foreach ( $segments as $idx => $segment ) {
            $key = ctype_digit( $segment ) ? (int) $segment : $segment;

            if ( $idx === $last_idx ) {
                if ( is_array( $ref ) && array_key_exists( $key, $ref ) ) {
                    $ref[ $key ] = $value;
                    return true;
                }
                return false;
            }

            if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
                return false;
            }

            $ref = &$ref[ $key ];
        }

        return false;
    }

    protected static function build_simple_text_section( $html ) {
        static $id_counter = 100000;

        $section_id = $id_counter++;
        $div_id     = $id_counter++;
        $text_id    = $id_counter++;

        return array(
            'id'       => $section_id,
            'data'     => array(
                'type'       => 'EssentialElements\\Section',
                'properties' => array(),
            ),
            'children' => array(
                array(
                    'id'       => $div_id,
                    'data'     => array(
                        'type'       => 'EssentialElements\\Div',
                        'properties' => array(),
                    ),
                    'children' => array(
                        array(
                            'id'       => $text_id,
                            'data'     => array(
                                'type'       => 'EssentialElements\\Text',
                                'properties' => array(
                                    'content' => array(
                                        'content' => array(
                                            'text' => $html,
                                        ),
                                    ),
                                ),
                            ),
                            'children' => array(),
                        ),
                    ),
                ),
            ),
        );
    }

    protected static function build_heading_text_section( $title, $body, $title_tag = 'h2' ) {
        static $id_counter = 200000;

        $section_id = $id_counter++;
        $div_id     = $id_counter++;
        $heading_id = $id_counter++;
        $text_id    = $id_counter++;

        $children = array();

        if ( '' !== $title ) {
            $children[] = array(
                'id'       => $heading_id,
                'data'     => array(
                    'type'       => 'EssentialElements\\Heading',
                    'properties' => array(
                        'content' => array(
                            'content' => array(
                                'text' => wp_strip_all_tags( $title ),
                                'tags' => $title_tag,
                            ),
                        ),
                    ),
                ),
                'children' => array(),
            );
        }

        if ( '' !== $body ) {
            $children[] = array(
                'id'       => $text_id,
                'data'     => array(
                    'type'       => 'EssentialElements\\Text',
                    'properties' => array(
                        'content' => array(
                            'content' => array(
                                'text' => $body,
                            ),
                        ),
                    ),
                ),
                'children' => array(),
            );
        }

        return array(
            'id'       => $section_id,
            'data'     => array(
                'type'       => 'EssentialElements\\Section',
                'properties' => array(),
            ),
            'children' => array(
                array(
                    'id'       => $div_id,
                    'data'     => array(
                        'type'       => 'EssentialElements\\Div',
                        'properties' => array(),
                    ),
                    'children' => $children,
                ),
            ),
        );
    }

    protected static function guess_label_for_type( $type, $properties ) {
        if ( ! empty( $properties['meta']['friendlyName'] ) ) {
            return $properties['meta']['friendlyName'];
        }

        switch ( $type ) {
            case 'EssentialElements\\Heading':
                return 'Heading';
            case 'EssentialElements\\Text':
                return 'Text';
            case 'EssentialElements\\Button':
                return 'Button';
            case 'EssentialElements\\Section':
                return 'Section';
            case 'EssentialElements\\Div':
            case 'EssentialElements\\Container':
                return 'Container';
            default:
                return $type;
        }
    }
}
