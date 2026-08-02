<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ensure et_pb_* shortcodes exist in $shortcode_tags so get_shortcode_regex()
 * can see them, even if the Divi theme/plugin hasn't fully bootstrapped in
 * this (REST) request.
 */
if ( ! function_exists( 'nova_divi_ensure_et_shortcodes_for_regex' ) ) {
    function nova_divi_ensure_et_shortcodes_for_regex() {
        global $shortcode_tags;

        if ( ! is_array( $shortcode_tags ) ) {
            $shortcode_tags = array();
        }

        $et_tags = array(
            // Structure.
            'et_pb_section',
            'et_pb_row',
            'et_pb_row_inner',
            'et_pb_column',
            'et_pb_column_inner',

            // Content modules.
            'et_pb_text',
            'et_pb_image',
            'et_pb_button',
            'et_pb_blurb',
            'et_pb_cta',
            'et_pb_accordion',
            'et_pb_accordion_item',
            'et_pb_toggle',
            'et_pb_divider',
            'et_pb_code',
            'et_pb_testimonial',
            'et_pb_video',
            'et_pb_audio',
            'et_pb_gallery',
            'et_pb_slider',
            'et_pb_slide',
            'et_pb_number_counter',
            'et_pb_counters',
            'et_pb_counter',
            'et_pb_circle_counter',
            'et_pb_pricing_tables',
            'et_pb_pricing_table',
            'et_pb_contact_form',
            'et_pb_contact_field',
            'et_pb_social_media_follow',
            'et_pb_social_media_follow_network',
            'et_pb_sidebar',
            'et_pb_login',
            'et_pb_signup',
            'et_pb_search',
            'et_pb_team_member',
            'et_pb_map',
            'et_pb_map_pin',
            'et_pb_tabs',
            'et_pb_tab',
            'et_pb_countdown_timer',
            'et_pb_post_title',
            'et_pb_post_content',
            'et_pb_post_nav',
            'et_pb_comments',
            'et_pb_blog',
            'et_pb_portfolio',
            'et_pb_filterable_portfolio',
            'et_pb_shop',
            'et_pb_menu',
            'et_pb_heading',
            'et_pb_icon',
            'et_pb_video_slider',
            'et_pb_video_slider_item',

            // Fullwidth modules.
            'et_pb_fullwidth_header',
            'et_pb_fullwidth_image',
            'et_pb_fullwidth_slider',
            'et_pb_fullwidth_menu',
            'et_pb_fullwidth_map',
            'et_pb_fullwidth_portfolio',
            'et_pb_fullwidth_post_title',
            'et_pb_fullwidth_post_content',
            'et_pb_fullwidth_code',
        );

        foreach ( $et_tags as $tag ) {
            if ( ! isset( $shortcode_tags[ $tag ] ) ) {
                $shortcode_tags[ $tag ] = '__return_empty_string';
            }
        }
    }
}

/**
 * Recursively parse shortcodes to a compact tree.
 *
 * NOTE: We intentionally do NOT preserve raw text between sibling shortcodes.
 * For Divi layouts, structure is the priority and "real text" lives inside
 * module bodies (leaf nodes such as et_pb_text).
 */
if ( ! function_exists( 'nova_divi_parse_shortcodes_to_compact' ) ) {
    function nova_divi_parse_shortcodes_to_compact( $content ) {
        $content = (string) $content;
        $nodes   = array();

        if ( '' === $content ) {
            return $nodes;
        }

        if ( false !== strpos( $content, '[et_pb_' ) ) {
            nova_divi_ensure_et_shortcodes_for_regex();
        }

        $pattern = get_shortcode_regex();

        if ( ! preg_match_all( '/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER ) ) {
            return $nodes;
        }

        foreach ( $matches as $m ) {
            // WP core uses:
            // [1] = '[' escape, [2] = tag, [3] = attrs, [4] = selfclosing '/', [5] = inner, [6] = ']' escape
            $tag      = isset( $m[2] ) ? (string) $m[2] : '';
            $atts_str = isset( $m[3] ) ? (string) $m[3] : '';
            $inner    = isset( $m[5] ) ? (string) $m[5] : '';

            // Ignore escaped shortcodes like [[et_pb_section]].
            if ( isset( $m[1], $m[6] ) && '[' === $m[1] && ']' === $m[6] ) {
                continue;
            }

            $attributes = shortcode_parse_atts( $atts_str );
            if ( ! is_array( $attributes ) ) {
                $attributes = array();
            }

            $self_closing = ( isset( $m[4] ) && '/' === $m[4] );

            // Only structural containers get their inner content parsed into
            // child nodes. Module bodies (et_pb_text etc.) are kept as opaque
            // text so nested third-party shortcodes and the HTML around them
            // survive re-serialization untouched.
            $children = array();
            $text     = '';

            if ( nova_divi_is_structural_container_tag( $tag ) ) {
                $children = nova_divi_parse_shortcodes_to_compact( $inner );

                // Keep text only when the container has no parsed children.
                if ( empty( $children ) && '' !== trim( $inner ) ) {
                    $text = trim( $inner );
                }
            } elseif ( '' !== trim( $inner ) ) {
                $text = trim( $inner );
            }

            $nodes[] = array(
                'tag'          => $tag,
                'attributes'   => $attributes,
                'text'         => $text,
                'self_closing' => $self_closing,
                'children'     => $children,
            );
        }

        return $nodes;
    }
}

/**
 * Build outline from compact tree.
 *
 * Divi 4 has no dedicated heading module: headings live as <h2>/<h3> HTML
 * inside et_pb_text bodies, so text modules are the primary targets.
 */
if ( ! function_exists( 'nova_divi_build_outline_from_compact' ) ) {
    function nova_divi_build_outline_from_compact( $compact, $tree = false ) {
        $outline = array();
        $path    = array();

        $walk = function ( $nodes, $parent_context = '', $depth = 0 ) use ( &$walk, &$outline, &$path ) {
            foreach ( $nodes as $idx => $node ) {
                $path[ $depth ] = $idx;
                $path_str       = implode( '.', array_slice( $path, 0, $depth + 1 ) );
                $tag            = isset( $node['tag'] ) ? (string) $node['tag'] : '';
                $attributes     = ( isset( $node['attributes'] ) && is_array( $node['attributes'] ) ) ? $node['attributes'] : array();

                $context = $parent_context;
                if ( 'et_pb_section' === $tag ) {
                    $context = ( $context ? $context . ' > ' : '' ) . 'Section';
                } elseif ( 'et_pb_row' === $tag || 'et_pb_row_inner' === $tag ) {
                    $context = ( $context ? $context . ' > ' : '' ) . 'Row';
                } elseif ( 'et_pb_column' === $tag || 'et_pb_column_inner' === $tag ) {
                    $context = ( $context ? $context . ' > ' : '' ) . 'Column';
                }
                if ( '' === $context ) {
                    $context = 'Divi';
                }

                $is_text_node = in_array(
                    $tag,
                    array(
                        'et_pb_text',
                        'et_pb_heading',
                        'et_pb_blurb',
                        'et_pb_button',
                        'et_pb_cta',
                        'et_pb_accordion_item',
                        'et_pb_toggle',
                        'et_pb_fullwidth_header',
                        'et_pb_testimonial',
                        'et_pb_slide',
                        'et_pb_image',
                        'et_pb_fullwidth_image',
                        'et_pb_number_counter',
                        'et_pb_pricing_table',
                        'et_pb_team_member',
                        'et_pb_code',
                    ),
                    true
                );

                if ( $is_text_node ) {
                    $text = isset( $node['text'] ) ? (string) $node['text'] : '';

                    // Visible text carried in attributes (Divi encodes " [ ] as
                    // %22 %91 %93). The default field map is shared with
                    // text_updates so what the client sees here is the field an
                    // update for this path writes back to.
                    $attr_text_candidates = array();

                    $default_field = nova_divi_default_text_field_for_tag( $tag );
                    if ( null !== $default_field ) {
                        $attr_text_candidates = array( $default_field );
                        if ( 'et_pb_fullwidth_header' === $tag ) {
                            $attr_text_candidates[] = 'subhead';
                        }
                    } elseif ( 'et_pb_image' === $tag || 'et_pb_fullwidth_image' === $tag ) {
                        // Display-only: images have no updatable text.
                        $attr_text_candidates = array( 'title_text', 'alt' );
                    }

                    foreach ( $attr_text_candidates as $attr_key ) {
                        if ( isset( $attributes[ $attr_key ] ) && '' !== (string) $attributes[ $attr_key ] ) {
                            $text = nova_divi_decode_attr( (string) $attributes[ $attr_key ] );
                            break;
                        }
                    }

                    $outline[] = array(
                        'path'    => $path_str,
                        'tag'     => $tag,
                        'label'   => nova_divi_guess_label_for_tag( $tag, $node ),
                        'context' => $context,
                        'text'    => $text,
                    );
                }

                if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                    $walk( $node['children'], $context, $depth + 1 );
                }
            }
        };

        $walk( $compact );

        return $outline;
    }
}

/**
 * Build text_map = [{path, text}] from compact tree.
 */
if ( ! function_exists( 'nova_divi_build_text_map_from_compact' ) ) {
    function nova_divi_build_text_map_from_compact( $compact ) {
        $outline = nova_divi_build_outline_from_compact( $compact, false );
        $map     = array();

        foreach ( $outline as $node ) {
            $map[] = array(
                'path' => $node['path'],
                'text' => $node['text'],
            );
        }

        return $map;
    }
}

if ( ! function_exists( 'nova_divi5_block_is_locked' ) ) {
    function nova_divi5_block_is_locked( $attributes ) {
        $lock_sets = array();
        if ( isset( $attributes['locked'] ) && is_array( $attributes['locked'] ) ) {
            $lock_sets[] = $attributes['locked'];
        }
        if ( isset( $attributes['module']['locked'] ) && is_array( $attributes['module']['locked'] ) ) {
            $lock_sets[] = $attributes['module']['locked'];
        }

        foreach ( $lock_sets as $lock_set ) {
            foreach ( $lock_set as $breakpoint ) {
                if ( is_array( $breakpoint ) && isset( $breakpoint['value'] ) && 'on' === $breakpoint['value'] ) {
                    return true;
                }
            }
        }
        return false;
    }
}

/**
 * Scan a native Divi 5 block document without reserializing untouched blocks.
 * Paths count every nested WordPress block, including third-party blocks.
 */
if ( ! function_exists( 'nova_divi5_scan_document' ) ) {
    function nova_divi5_scan_document( $document ) {
        $document = (string) $document;
        $parser   = new WP_Block_Parser();
        $parser->document = $document;
        $parser->offset   = 0;

        $tokens         = array();
        $checked_offset = 0;
        while ( true ) {
            $token = $parser->next_token();
            if ( 'no-more-tokens' === $token[0] ) {
                break;
            }
            if (
                ! in_array( $token[0], array( 'block-opener', 'block-closer', 'void-block' ), true )
                || ! is_int( $token[3] )
                || ! is_int( $token[4] )
                || $token[3] < $parser->offset
                || $token[4] <= 0
            ) {
                return new WP_Error( 'nova_divi5_invalid_document', 'Invalid native block token.', array( 'status' => 422 ) );
            }

            $gap = substr( $document, $checked_offset, $token[3] - $checked_offset );
            if ( preg_match( '/<!--\s+\/?wp:/i', $gap ) ) {
                return new WP_Error( 'nova_divi5_invalid_document', 'Malformed native block delimiter.', array( 'status' => 422 ) );
            }

            $raw_token   = substr( $document, $token[3], $token[4] );
            $quoted_name = preg_quote( (string) $token[1], '/' );
            if ( 'block-closer' === $token[0] ) {
                $valid_delimiter = preg_match( '/\A<!--\s+\/wp:' . $quoted_name . '\s+-->\z/i', $raw_token );
            } elseif ( 'void-block' === $token[0] ) {
                $valid_delimiter = preg_match( '/\A<!--\s+wp:' . $quoted_name . '(?:\s+\{[\s\S]*\})?\s+\/-->\z/i', $raw_token );
            } else {
                $valid_delimiter = preg_match( '/\A<!--\s+wp:' . $quoted_name . '(?:\s+\{[\s\S]*\})?\s+-->\z/i', $raw_token );
            }
            if ( 1 !== $valid_delimiter ) {
                return new WP_Error( 'nova_divi5_invalid_document', 'Malformed native block delimiter.', array( 'status' => 422 ) );
            }

            $tokens[]       = $token;
            $parser->offset = $token[3] + $token[4];
            $checked_offset = $parser->offset;
        }

        $trailing = substr( $document, $checked_offset );
        if ( preg_match( '/<!--\s+\/?wp:/i', $trailing ) ) {
            return new WP_Error( 'nova_divi5_invalid_document', 'Malformed native block delimiter.', array( 'status' => 422 ) );
        }

        if ( empty( $tokens ) ) {
            return new WP_Error(
                'nova_divi5_invalid_document',
                'No native WordPress blocks were found in the Divi 5 document.',
                array( 'status' => 422 )
            );
        }

        $blocks = array();
        $stack  = array(
            array(
                'name'        => '',
                'path'        => '',
                'next_child'  => 0,
                'context'     => '',
                'protected'   => false,
                'block_index' => null,
            ),
        );

        foreach ( $tokens as $parsed_token ) {
            list( $token_type, $name, $attributes, $token_start, $token_length ) = $parsed_token;
            $name        = strtolower( (string) $name );
            $token       = substr( $document, $token_start, $token_length );
            $token_end   = $token_start + $token_length;
            $closing     = 'block-closer' === $token_type;

            if ( $closing ) {
                $frame_index = count( $stack ) - 1;
                if ( $frame_index < 1 || $stack[ $frame_index ]['name'] !== $name ) {
                    return new WP_Error(
                        'nova_divi5_invalid_document',
                        'Native Divi 5 block nesting is malformed at ' . $name . '.',
                        array( 'status' => 422 )
                    );
                }

                $open_index = $stack[ $frame_index ]['block_index'];
                $blocks[ $open_index ]['end'] = $token_end;
                array_pop( $stack );
                continue;
            }

            $frame_index = count( $stack ) - 1;
            $child_index = $stack[ $frame_index ]['next_child'];
            $stack[ $frame_index ]['next_child']++;
            $parent_path = $stack[ $frame_index ]['path'];
            $path        = '' === $parent_path ? (string) $child_index : $parent_path . '.' . $child_index;

            $attributes       = is_array( $attributes ) ? $attributes : array();
            $attributes_start = -1;
            $attributes_length = 0;
            $brace_start       = strpos( $token, '{' );
            if ( false !== $brace_start ) {
                $brace_end = strrpos( $token, '}' );
                if ( false === $brace_end || $brace_end < $brace_start || ! is_array( $parsed_token[2] ) ) {
                    return new WP_Error(
                        'nova_divi5_invalid_document',
                        'Native Divi 5 block attributes are invalid JSON at path ' . $path . '.',
                        array( 'status' => 422 )
                    );
                }
                $attributes_start  = $token_start + $brace_start;
                $attributes_length = $brace_end - $brace_start + 1;
            }

            $parent_context = $stack[ $frame_index ]['context'];
            $context        = '' !== $parent_context ? $parent_context : 'Divi 5';
            $self_closing   = 'void-block' === $token_type;
            $block_index    = count( $blocks );
            $locked         = nova_divi5_block_is_locked( $attributes );
            $protected      = ! empty( $stack[ $frame_index ]['protected'] ) || 'divi/global-layout' === $name || $locked;

            $blocks[] = array(
                'path'              => $path,
                'name'              => $name,
                'attributes'        => $attributes,
                'attributes_start'  => $attributes_start,
                'attributes_length' => $attributes_length,
                'start'             => $token_start,
                'end'               => $self_closing ? $token_end : null,
                'context'           => $context,
                'parent_path'       => $parent_path,
                'token_type'        => $token_type,
                'self_closing'      => $self_closing,
                'protected'         => $protected,
                'protection_reason' => $locked ? 'locked' : ( $protected ? 'global_layout' : '' ),
            );

            if ( ! $self_closing ) {
                $label = '';
                if (
                    isset( $attributes['module']['meta']['adminLabel']['desktop']['value'] )
                    && is_scalar( $attributes['module']['meta']['adminLabel']['desktop']['value'] )
                ) {
                    $label = trim( (string) $attributes['module']['meta']['adminLabel']['desktop']['value'] );
                }
                if ( '' === $label ) {
                    $name_parts = explode( '/', $name, 2 );
                    $label      = ucwords( str_replace( '-', ' ', end( $name_parts ) ) );
                }

                $child_context = 'Divi 5' === $context ? $label : $context . ' > ' . $label;
                $stack[]       = array(
                    'name'        => $name,
                    'path'        => $path,
                    'next_child'  => 0,
                    'context'     => $child_context,
                    'protected'   => $protected,
                    'block_index' => $block_index,
                );
            }
        }

        if ( 1 !== count( $stack ) ) {
            $unclosed = end( $stack );
            return new WP_Error(
                'nova_divi5_invalid_document',
                'Native Divi 5 block ' . $unclosed['name'] . ' is not closed.',
                array( 'status' => 422 )
            );
        }

        $root_blocks = array_values(
            array_filter(
                $blocks,
                function ( $block ) {
                    return '' === $block['parent_path'];
                }
            )
        );
        if ( 1 !== count( $root_blocks ) || 'divi/placeholder' !== $root_blocks[0]['name'] ) {
            return new WP_Error(
                'nova_divi5_invalid_document',
                'A native Divi 5 document must contain one top-level divi/placeholder block.',
                array( 'status' => 422 )
            );
        }

        return $blocks;
    }
}

if ( ! function_exists( 'nova_divi5_get_nested_value' ) ) {
    function nova_divi5_get_nested_value( $value, $path, &$found = null ) {
        $found = false;
        foreach ( $path as $segment ) {
            if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                return null;
            }
            $value = $value[ $segment ];
        }
        $found = true;
        return $value;
    }
}

if ( ! function_exists( 'nova_divi5_json_skip_whitespace' ) ) {
    function nova_divi5_json_skip_whitespace( $json, &$position ) {
        $length = strlen( $json );
        while ( $position < $length && false !== strpos( " \t\r\n", $json[ $position ] ) ) {
            $position++;
        }
    }
}

if ( ! function_exists( 'nova_divi5_json_error' ) ) {
    function nova_divi5_json_error( $message ) {
        return new WP_Error( 'nova_divi5_invalid_json', $message, array( 'status' => 422 ) );
    }
}

if ( ! function_exists( 'nova_divi5_json_parse_string' ) ) {
    function nova_divi5_json_parse_string( $json, &$position ) {
        $start  = $position;
        $length = strlen( $json );
        if ( $position >= $length || '"' !== $json[ $position ] ) {
            return nova_divi5_json_error( 'Expected a JSON string.' );
        }

        $position++;
        while ( $position < $length ) {
            $character = $json[ $position ];
            if ( '"' === $character ) {
                $position++;
                $raw   = substr( $json, $start, $position - $start );
                $value = json_decode( $raw, true );
                if ( JSON_ERROR_NONE !== json_last_error() || ! is_string( $value ) ) {
                    return nova_divi5_json_error( 'Invalid JSON string encoding.' );
                }
                return array(
                    'type'  => 'string',
                    'start' => $start,
                    'end'   => $position,
                    'value' => $value,
                );
            }

            if ( '\\' === $character ) {
                $position++;
                if ( $position >= $length || false === strpos( '"\\/bfnrtu', $json[ $position ] ) ) {
                    return nova_divi5_json_error( 'Invalid JSON string escape.' );
                }
                if ( 'u' === $json[ $position ] ) {
                    $hex = substr( $json, $position + 1, 4 );
                    if ( 1 !== preg_match( '/\A[0-9A-Fa-f]{4}\z/', $hex ) ) {
                        return nova_divi5_json_error( 'Invalid JSON Unicode escape.' );
                    }
                    $position += 5;
                    continue;
                }
                $position++;
                continue;
            }

            if ( ord( $character ) < 0x20 ) {
                return nova_divi5_json_error( 'Unescaped control byte in JSON string.' );
            }
            $position++;
        }

        return nova_divi5_json_error( 'Unterminated JSON string.' );
    }
}

if ( ! function_exists( 'nova_divi5_json_parse_value' ) ) {
    function nova_divi5_json_parse_value( $json, &$position, $depth = 0 ) {
        if ( $depth > 64 ) {
            return nova_divi5_json_error( 'Native block JSON exceeds the nesting limit.' );
        }

        nova_divi5_json_skip_whitespace( $json, $position );
        $start  = $position;
        $length = strlen( $json );
        if ( $position >= $length ) {
            return nova_divi5_json_error( 'Unexpected end of native block JSON.' );
        }

        if ( '"' === $json[ $position ] ) {
            return nova_divi5_json_parse_string( $json, $position );
        }

        if ( '{' === $json[ $position ] ) {
            $position++;
            $members = array();
            nova_divi5_json_skip_whitespace( $json, $position );
            if ( $position < $length && '}' === $json[ $position ] ) {
                $position++;
                return array( 'type' => 'object', 'start' => $start, 'end' => $position, 'members' => $members );
            }

            while ( $position < $length ) {
                $key_node = nova_divi5_json_parse_string( $json, $position );
                if ( is_wp_error( $key_node ) ) {
                    return $key_node;
                }
                $key       = $key_node['value'];
                $stored_key = 'k:' . strlen( $key ) . ':' . $key;
                if ( array_key_exists( $stored_key, $members ) ) {
                    return nova_divi5_json_error( 'Duplicate JSON object key: ' . $key . '.' );
                }

                nova_divi5_json_skip_whitespace( $json, $position );
                if ( $position >= $length || ':' !== $json[ $position ] ) {
                    return nova_divi5_json_error( 'Expected a colon after a JSON object key.' );
                }
                $position++;

                $value_node = nova_divi5_json_parse_value( $json, $position, $depth + 1 );
                if ( is_wp_error( $value_node ) ) {
                    return $value_node;
                }
                $members[ $stored_key ] = $value_node;

                nova_divi5_json_skip_whitespace( $json, $position );
                if ( $position < $length && '}' === $json[ $position ] ) {
                    $position++;
                    return array( 'type' => 'object', 'start' => $start, 'end' => $position, 'members' => $members );
                }
                if ( $position >= $length || ',' !== $json[ $position ] ) {
                    return nova_divi5_json_error( 'Expected a comma in a JSON object.' );
                }
                $position++;
                nova_divi5_json_skip_whitespace( $json, $position );
            }

            return nova_divi5_json_error( 'Unterminated JSON object.' );
        }

        if ( '[' === $json[ $position ] ) {
            $position++;
            $items = array();
            nova_divi5_json_skip_whitespace( $json, $position );
            if ( $position < $length && ']' === $json[ $position ] ) {
                $position++;
                return array( 'type' => 'array', 'start' => $start, 'end' => $position, 'items' => $items );
            }

            while ( $position < $length ) {
                $value_node = nova_divi5_json_parse_value( $json, $position, $depth + 1 );
                if ( is_wp_error( $value_node ) ) {
                    return $value_node;
                }
                $items[] = $value_node;

                nova_divi5_json_skip_whitespace( $json, $position );
                if ( $position < $length && ']' === $json[ $position ] ) {
                    $position++;
                    return array( 'type' => 'array', 'start' => $start, 'end' => $position, 'items' => $items );
                }
                if ( $position >= $length || ',' !== $json[ $position ] ) {
                    return nova_divi5_json_error( 'Expected a comma in a JSON array.' );
                }
                $position++;
                nova_divi5_json_skip_whitespace( $json, $position );
            }

            return nova_divi5_json_error( 'Unterminated JSON array.' );
        }

        foreach ( array( 'true', 'false', 'null' ) as $literal ) {
            if ( 0 === substr_compare( $json, $literal, $position, strlen( $literal ) ) ) {
                $position += strlen( $literal );
                return array( 'type' => 'literal', 'start' => $start, 'end' => $position );
            }
        }

        if ( preg_match( '/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', substr( $json, $position ), $number ) ) {
            $position += strlen( $number[0] );
            return array( 'type' => 'number', 'start' => $start, 'end' => $position );
        }

        return nova_divi5_json_error( 'Invalid JSON value.' );
    }
}

if ( ! function_exists( 'nova_divi5_json_parse' ) ) {
    function nova_divi5_json_parse( $json ) {
        if ( strlen( $json ) > 1048576 ) {
            return nova_divi5_json_error( 'A native block attribute object exceeds 1 MiB.' );
        }

        $position = 0;
        $node     = nova_divi5_json_parse_value( $json, $position, 0 );
        if ( is_wp_error( $node ) ) {
            return $node;
        }
        nova_divi5_json_skip_whitespace( $json, $position );
        if ( $position !== strlen( $json ) || 'object' !== $node['type'] ) {
            return nova_divi5_json_error( 'Native block attributes must be one JSON object.' );
        }
        return $node;
    }
}

if ( ! function_exists( 'nova_divi5_json_locate_string' ) ) {
    function nova_divi5_json_locate_string( $root, $path ) {
        $node = $root;
        foreach ( $path as $segment ) {
            if ( 'object' === $node['type'] ) {
                $key = (string) $segment;
                $key = 'k:' . strlen( $key ) . ':' . $key;
                if ( ! isset( $node['members'][ $key ] ) ) {
                    return null;
                }
                $node = $node['members'][ $key ];
                continue;
            }
            if ( 'array' === $node['type'] && is_numeric( $segment ) ) {
                $index = (int) $segment;
                if ( ! isset( $node['items'][ $index ] ) ) {
                    return null;
                }
                $node = $node['items'][ $index ];
                continue;
            }
            return null;
        }

        return 'string' === $node['type'] ? $node : null;
    }
}

/**
 * Known native Divi 5 text schemas. Unknown modules remain opaque.
 */
if ( ! function_exists( 'nova_divi5_field_definitions' ) ) {
    function nova_divi5_field_definitions( $name ) {
        $inner_content = array( 'innerContent', '{breakpoint}', 'value' );

        switch ( (string) $name ) {
            case 'divi/text':
                return array(
                    'default' => 'body',
                    'fields'  => array(
                        'body' => array( 'path' => array_merge( array( 'content' ), $inner_content ), 'kind' => 'html' ),
                    ),
                );

            case 'divi/blurb':
                return array(
                    'default' => 'title',
                    'fields'  => array(
                        'title'     => array( 'path' => array_merge( array( 'title' ), $inner_content ), 'kind' => 'text' ),
                        'body'      => array( 'path' => array_merge( array( 'content' ), $inner_content ), 'kind' => 'html' ),
                        'link_url'  => array( 'path' => array( 'module', 'advanced', 'link', 'desktop', 'value', 'url' ), 'kind' => 'url' ),
                        'anchor_id' => array( 'special' => 'anchor_id', 'kind' => 'id' ),
                    ),
                );

            case 'divi/heading':
                return array(
                    'default' => 'title',
                    'fields'  => array(
                        'title' => array( 'path' => array_merge( array( 'title' ), $inner_content ), 'kind' => 'html' ),
                    ),
                );

            case 'divi/accordion-item':
            case 'divi/toggle':
                return array(
                    'default' => 'title',
                    'fields'  => array(
                        'title' => array( 'path' => array_merge( array( 'title' ), $inner_content ), 'kind' => 'text' ),
                        'body'  => array( 'path' => array_merge( array( 'content' ), $inner_content ), 'kind' => 'html' ),
                    ),
                );
        }

        return null;
    }
}

if ( ! function_exists( 'nova_divi5_resolve_field' ) ) {
    function nova_divi5_resolve_field( $block, $requested_field = '' ) {
        if ( empty( $block['self_closing'] ) ) {
            return null;
        }

        $definitions = nova_divi5_field_definitions( $block['name'] );
        if ( ! is_array( $definitions ) ) {
            return null;
        }

        $field = strtolower( trim( (string) $requested_field ) );
        if ( '' === $field ) {
            $field = $definitions['default'];
        } elseif ( in_array( $field, array( 'content', 'text' ), true ) ) {
            $field = 'body';
        } elseif ( in_array( $field, array( 'heading', 'name' ), true ) ) {
            $field = 'title';
        } elseif ( in_array( $field, array( 'url', 'link' ), true ) ) {
            $field = 'link_url';
        } elseif ( in_array( $field, array( 'id', 'anchor' ), true ) ) {
            $field = 'anchor_id';
        }

        if ( empty( $definitions['fields'][ $field ] ) ) {
            return null;
        }

        $definition = $definitions['fields'][ $field ];
        $variants   = array();

        if ( isset( $definition['special'] ) && 'anchor_id' === $definition['special'] ) {
            $attribute_path = array( 'module', 'decoration', 'attributes', 'desktop', 'value', 'attributes' );
            $found          = false;
            $attribute_list = nova_divi5_get_nested_value( $block['attributes'], $attribute_path, $found );
            if ( $found && is_array( $attribute_list ) ) {
                $id_matches = array();
                foreach ( $attribute_list as $index => $attribute ) {
                    if (
                        is_array( $attribute )
                        && isset( $attribute['name'] )
                        && 'id' === strtolower( (string) $attribute['name'] )
                        && isset( $attribute['value'] )
                        && is_string( $attribute['value'] )
                        && isset( $attribute['targetElement'] )
                        && '' === $attribute['targetElement']
                    ) {
                        $id_matches[] = array(
                            'path'  => array_merge( $attribute_path, array( $index, 'value' ) ),
                            'value' => (string) $attribute['value'],
                        );
                    }
                }
                if ( 1 === count( $id_matches ) ) {
                    $variants['desktop'] = $id_matches[0];
                }
            }
        } else {
            $breakpoint_index = array_search( '{breakpoint}', $definition['path'], true );
            if ( false === $breakpoint_index ) {
                $found   = false;
                $current = nova_divi5_get_nested_value( $block['attributes'], $definition['path'], $found );
                if ( $found && is_string( $current ) ) {
                    $variants['desktop'] = array( 'path' => $definition['path'], 'value' => (string) $current );
                }
            } else {
                $container_path = array_slice( $definition['path'], 0, $breakpoint_index );
                $found          = false;
                $breakpoints    = nova_divi5_get_nested_value( $block['attributes'], $container_path, $found );
                if ( $found && is_array( $breakpoints ) ) {
                    foreach ( array_keys( $breakpoints ) as $breakpoint ) {
                        if ( ! in_array( $breakpoint, array( 'desktop', 'tablet', 'phone' ), true ) ) {
                            return null;
                        }
                        $path                        = $definition['path'];
                        $path[ $breakpoint_index ]   = $breakpoint;
                        $value_found                 = false;
                        $current                     = nova_divi5_get_nested_value( $block['attributes'], $path, $value_found );
                        if ( $value_found && is_array( $current ) && isset( $current['text'] ) && is_string( $current['text'] ) ) {
                            $path[]   = 'text';
                            $current = $current['text'];
                        }
                        if ( $value_found && is_string( $current ) ) {
                            $variants[ (string) $breakpoint ] = array( 'path' => $path, 'value' => (string) $current );
                        }
                    }
                }
            }
        }

        if ( empty( $variants ) || ! isset( $variants['desktop'] ) ) {
            return null;
        }

        $primary               = $variants['desktop'];
        $definition['field']   = $field;
        $definition['value']   = $primary['value'];
        $definition['variants'] = $variants;
        return $definition;
    }
}

if ( ! function_exists( 'nova_divi5_dynamic_name' ) ) {
    function nova_divi5_dynamic_name( $value ) {
        if ( false === strpos( (string) $value, '$variable(' ) ) {
            return '';
        }
        if ( preg_match( '/"name"\s*:\s*"([^"]+)"/', (string) $value, $matches ) ) {
            return sanitize_key( $matches[1] );
        }
        return 'variable';
    }
}

if ( ! function_exists( 'nova_divi5_build_outline' ) ) {
    function nova_divi5_build_outline( $document, $post_title = '' ) {
        $blocks = nova_divi5_scan_document( $document );
        if ( is_wp_error( $blocks ) ) {
            return $blocks;
        }

        $outline = array();
        foreach ( $blocks as $block ) {
            $definitions = nova_divi5_field_definitions( $block['name'] );
            if ( ! is_array( $definitions ) ) {
                continue;
            }

            $fields = array();
            foreach ( array_keys( $definitions['fields'] ) as $field ) {
                $resolved = nova_divi5_resolve_field( $block, $field );
                if ( is_array( $resolved ) ) {
                    $responsive = array();
                    $dynamic   = '';
                    foreach ( $resolved['variants'] as $breakpoint => $variant ) {
                        $responsive[ $breakpoint ] = $variant['value'];
                        if ( '' === $dynamic ) {
                            $dynamic = nova_divi5_dynamic_name( $variant['value'] );
                        }
                    }

                    $embedded_shortcode = false;
                    if ( 'html' === $resolved['kind'] ) {
                        foreach ( $resolved['variants'] as $variant ) {
                            if ( preg_match( '/\[(?:gravityform|contact-form-7|wpforms|forminator_form)\b/i', $variant['value'] ) ) {
                                $embedded_shortcode = true;
                                break;
                            }
                        }
                    }
                    $non_desktop         = array_filter(
                        $responsive,
                        function ( $value, $breakpoint ) {
                            return 'desktop' !== $breakpoint && '' !== trim( (string) $value );
                        },
                        ARRAY_FILTER_USE_BOTH
                    );

                    $field_value = $resolved['value'];
                    if ( 'post_title' === $dynamic && '' !== (string) $post_title ) {
                        $field_value = (string) $post_title;
                    }

                    $fields[ $field ] = array(
                        'value'                 => $field_value,
                        'format'                => $resolved['kind'],
                        'json_path'             => implode( '.', array_map( 'strval', $resolved['variants']['desktop']['path'] ) ),
                        'responsive'            => $responsive,
                        'requires_sync_responsive' => ! empty( $non_desktop ),
                        'editable'              => ! $block['protected'] && '' === $dynamic && ! $embedded_shortcode,
                    );
                    if ( '' !== $dynamic ) {
                        $fields[ $field ]['dynamic'] = $dynamic;
                    }
                    if ( $embedded_shortcode ) {
                        $fields[ $field ]['protected'] = 'embedded_form';
                    } elseif ( $block['protected'] ) {
                        $fields[ $field ]['protected'] = $block['protection_reason'];
                    }
                }
            }

            $default = nova_divi5_resolve_field( $block, $definitions['default'] );
            if ( ! is_array( $default ) ) {
                continue;
            }

            $default_field = $definitions['default'];
            $default_info  = isset( $fields[ $default_field ] ) ? $fields[ $default_field ] : null;
            if ( ! is_array( $default_info ) ) {
                continue;
            }

            $dynamic = isset( $default_info['dynamic'] ) ? $default_info['dynamic'] : '';
            $text    = 'post_title' === $dynamic && '' !== (string) $post_title
                ? '<h1>' . esc_html( $post_title ) . '</h1>'
                : $default_info['value'];
            $label   = ucwords( str_replace( array( 'divi/', '-' ), array( '', ' ' ), $block['name'] ) );
            $role    = 'content';
            if ( 'post_title' === $dynamic ) {
                $role = 'post_title';
            } elseif ( in_array( $block['name'], array( 'divi/accordion-item', 'divi/toggle' ), true ) ) {
                $role = 'faq_item';
            } elseif ( 'divi/blurb' === $block['name'] && isset( $fields['anchor_id'] ) ) {
                $role = 'section_heading';
            } elseif (
                'divi/blurb' === $block['name']
                && isset( $fields['link_url']['value'] )
                && 0 === strpos( $fields['link_url']['value'], '#' )
            ) {
                $role = 'toc_item';
            } elseif ( false !== stripos( $block['context'], 'hero' ) ) {
                $role = 'hero';
            } elseif ( strlen( wp_strip_all_tags( (string) $text ) ) >= 240 ) {
                $role = 'article_body';
            } elseif ( preg_match( '/<h[1-6]\b/i', (string) $text ) ) {
                $role = 'heading';
            }

            $item = array(
                'path'     => $block['path'],
                'tag'      => $block['name'],
                'label'    => $label,
                'context'  => $block['context'],
                'role'     => $role,
                'text'     => $text,
                'fields'   => $fields,
                'editable' => ! empty( $default_info['editable'] ),
            );
            if ( '' !== $dynamic ) {
                $item['dynamic'] = $dynamic;
            }
            if ( $block['protected'] ) {
                $item['protected'] = $block['protection_reason'];
            }
            $outline[] = $item;
        }

        return $outline;
    }
}

if ( ! function_exists( 'nova_divi5_build_text_map' ) ) {
    function nova_divi5_build_text_map( $outline ) {
        $map = array();
        foreach ( $outline as $item ) {
            foreach ( $item['fields'] as $field => $details ) {
                $entry = array(
                    'path'     => $item['path'],
                    'field'    => $field,
                    'text'     => $details['value'],
                    'format'   => $details['format'],
                    'editable' => $details['editable'],
                );
                if ( isset( $details['dynamic'] ) ) {
                    $entry['dynamic'] = $details['dynamic'];
                }
                if ( isset( $details['protected'] ) ) {
                    $entry['protected'] = $details['protected'];
                }
                $entry['responsive']               = $details['responsive'];
                $entry['requires_sync_responsive'] = $details['requires_sync_responsive'];
                $map[] = $entry;
            }
        }
        return $map;
    }
}

/**
 * Apply native text updates by replacing only whitelisted JSON string tokens.
 */
if ( ! function_exists( 'nova_divi5_apply_text_updates' ) ) {
    function nova_divi5_apply_text_updates( $document, $updates ) {
        if ( empty( $updates ) ) {
            return (string) $document;
        }
        if ( ! is_array( $updates ) ) {
            return new WP_Error( 'rest_invalid_param', 'text_updates must be an array.', array( 'status' => 400 ) );
        }
        if ( ! function_exists( 'serialize_block_attributes' ) ) {
            return new WP_Error( 'missing_dependency', 'WordPress block serialization is unavailable.', array( 'status' => 500 ) );
        }

        $blocks = nova_divi5_scan_document( $document );
        if ( is_wp_error( $blocks ) ) {
            return $blocks;
        }

        $by_path = array();
        foreach ( $blocks as $index => $block ) {
            $by_path[ $block['path'] ] = $index;
        }

        $json_cache   = array();
        $replacements = array();
        $logical_keys = array();

        foreach ( $updates as $update ) {
            if (
                ! is_array( $update )
                || ! isset( $update['path'] )
                || ( ! array_key_exists( 'text', $update ) && ! array_key_exists( 'responsive_text', $update ) )
            ) {
                return new WP_Error( 'rest_invalid_param', 'Each text update requires path and text.', array( 'status' => 400 ) );
            }
            if ( array_key_exists( 'text', $update ) && ! is_string( $update['text'] ) ) {
                return new WP_Error( 'rest_invalid_param', 'Text update values must be strings.', array( 'status' => 400 ) );
            }
            if ( array_key_exists( 'text', $update ) && array_key_exists( 'responsive_text', $update ) ) {
                return new WP_Error( 'rest_invalid_param', 'Use either text or responsive_text, not both.', array( 'status' => 400 ) );
            }
            if ( array_key_exists( 'responsive_text', $update ) && array_key_exists( 'sync_responsive', $update ) ) {
                return new WP_Error( 'rest_invalid_param', 'Use either responsive_text or sync_responsive, not both.', array( 'status' => 400 ) );
            }

            $path = (string) $update['path'];
            if ( ! array_key_exists( $path, $by_path ) ) {
                return new WP_Error(
                    'nova_divi5_unknown_path',
                    'Native Divi 5 path ' . $path . ' does not exist.',
                    array( 'status' => 422, 'path' => $path )
                );
            }

            $index      = $by_path[ $path ];
            $field      = isset( $update['field'] ) ? (string) $update['field'] : '';
            $definition = nova_divi5_resolve_field( $blocks[ $index ], $field );
            if ( ! is_array( $definition ) ) {
                return new WP_Error(
                    'nova_divi5_unsupported_field',
                    'The requested native Divi 5 field is not writable at path ' . $path . '.',
                    array( 'status' => 422, 'path' => $path, 'field' => $field, 'tag' => $blocks[ $index ]['name'] )
                );
            }
            if ( $blocks[ $index ]['protected'] ) {
                return new WP_Error(
                    'nova_divi5_protected_block',
                    'This native Divi 5 block is protected or locked.',
                    array( 'status' => 422, 'path' => $path, 'reason' => $blocks[ $index ]['protection_reason'] )
                );
            }

            $embedded_form = false;
            foreach ( $definition['variants'] as $variant ) {
                if ( '' !== nova_divi5_dynamic_name( $variant['value'] ) ) {
                    return new WP_Error(
                        'nova_divi5_dynamic_content',
                        'This field uses dynamic Divi content and cannot be replaced directly.',
                        array( 'status' => 422, 'path' => $path, 'field' => $definition['field'] )
                    );
                }
                if (
                    'html' === $definition['kind']
                    && preg_match( '/\[(?:gravityform|contact-form-7|wpforms|forminator_form)\b/i', $variant['value'] )
                ) {
                    $embedded_form = true;
                }
            }
            if ( $embedded_form ) {
                return new WP_Error(
                    'nova_divi5_protected_content',
                    'Embedded form content cannot be replaced through text_updates.',
                    array( 'status' => 422, 'path' => $path, 'field' => $definition['field'] )
                );
            }

            $logical_key = $path . '|' . $definition['field'];
            if ( isset( $logical_keys[ $logical_key ] ) ) {
                return new WP_Error(
                    'nova_divi5_duplicate_target',
                    'The same native field is targeted more than once.',
                    array( 'status' => 422, 'path' => $path, 'field' => $definition['field'] )
                );
            }
            $logical_keys[ $logical_key ] = true;

            if ( $blocks[ $index ]['attributes_start'] < 0 ) {
                return new WP_Error( 'nova_divi5_schema_mismatch', 'Writable native block has no attributes.', array( 'status' => 422 ) );
            }

            $responsive = array_key_exists( 'responsive_text', $update ) ? $update['responsive_text'] : null;
            if ( null !== $responsive && ! is_array( $responsive ) ) {
                return new WP_Error( 'rest_invalid_param', 'responsive_text must be an object.', array( 'status' => 400 ) );
            }

            $non_empty_overrides = array();
            foreach ( $definition['variants'] as $breakpoint => $variant ) {
                if ( 'desktop' !== $breakpoint && '' !== trim( $variant['value'] ) ) {
                    $non_empty_overrides[ $breakpoint ] = $variant;
                }
            }
            $sync_responsive = isset( $update['sync_responsive'] ) && nova_divi_to_bool( $update['sync_responsive'], false );
            if ( ! empty( $non_empty_overrides ) && ! $sync_responsive && null === $responsive ) {
                return new WP_Error(
                    'nova_divi5_responsive_precondition',
                    'This field has responsive text overrides; set sync_responsive or responsive_text explicitly.',
                    array( 'status' => 422, 'path' => $path, 'field' => $definition['field'], 'breakpoints' => array_keys( $non_empty_overrides ) )
                );
            }

            $targets = array();
            foreach ( $definition['variants'] as $breakpoint => $variant ) {
                if ( 'desktop' !== $breakpoint && '' === trim( $variant['value'] ) ) {
                    continue;
                }

                if ( null !== $responsive ) {
                    if ( ! array_key_exists( $breakpoint, $responsive ) ) {
                        return new WP_Error(
                            'nova_divi5_responsive_precondition',
                            'responsive_text must cover desktop and every non-empty existing override.',
                            array( 'status' => 422, 'path' => $path, 'field' => $definition['field'], 'missing' => $breakpoint )
                        );
                    }
                    $targets[ $breakpoint ] = $responsive[ $breakpoint ];
                } elseif ( 'desktop' === $breakpoint || $sync_responsive ) {
                    $targets[ $breakpoint ] = isset( $update['text'] ) ? $update['text'] : '';
                }
            }
            if ( null !== $responsive ) {
                foreach ( array_keys( $responsive ) as $breakpoint ) {
                    if ( ! isset( $targets[ $breakpoint ] ) ) {
                        return new WP_Error(
                            'nova_divi5_responsive_precondition',
                            'responsive_text cannot create or replace an empty breakpoint override.',
                            array( 'status' => 422, 'path' => $path, 'field' => $definition['field'], 'breakpoint' => $breakpoint )
                        );
                    }
                }
            }

            $attributes_json = substr( $document, $blocks[ $index ]['attributes_start'], $blocks[ $index ]['attributes_length'] );
            if ( ! isset( $json_cache[ $index ] ) ) {
                $json_cache[ $index ] = nova_divi5_json_parse( $attributes_json );
            }
            if ( is_wp_error( $json_cache[ $index ] ) ) {
                return $json_cache[ $index ];
            }

            foreach ( $targets as $breakpoint => $incoming ) {
                if ( ! is_string( $incoming ) ) {
                    return new WP_Error( 'rest_invalid_param', 'Responsive text values must be strings.', array( 'status' => 400 ) );
                }

                $raw_value = (string) $incoming;
                if ( wp_check_invalid_utf8( $raw_value, true ) !== $raw_value || strlen( $raw_value ) > 25000 ) {
                    return new WP_Error( 'rest_invalid_param', 'Native text must be valid UTF-8 and at most 25,000 bytes.', array( 'status' => 400 ) );
                }
                if ( false !== strpos( $raw_value, '$variable(' ) ) {
                    return new WP_Error( 'rest_invalid_param', 'Dynamic Divi variables cannot be injected.', array( 'status' => 400 ) );
                }

                switch ( $definition['kind'] ) {
                    case 'html':
                        $safe_value = wp_kses_post( $raw_value );
                        break;
                    case 'url':
                        if ( 0 === strpos( $raw_value, '#' ) ) {
                            $safe_value = '#' . sanitize_title( substr( $raw_value, 1 ) );
                        } else {
                            $safe_value = esc_url_raw( $raw_value );
                        }
                        if ( '' !== $raw_value && '' === $safe_value ) {
                            return new WP_Error( 'rest_invalid_param', 'Invalid native link URL.', array( 'status' => 400 ) );
                        }
                        break;
                    case 'id':
                        $safe_value = sanitize_title( $raw_value );
                        break;
                    default:
                        $safe_value = wp_strip_all_tags( $raw_value );
                        break;
                }

                $variant = $definition['variants'][ $breakpoint ];
                $node    = nova_divi5_json_locate_string( $json_cache[ $index ], $variant['path'] );
                if ( ! is_array( $node ) || $node['value'] !== $variant['value'] ) {
                    return new WP_Error(
                        'nova_divi5_schema_mismatch',
                        'The native Divi 5 field schema changed at path ' . $path . '.',
                        array( 'status' => 422, 'path' => $path, 'field' => $definition['field'] )
                    );
                }

                $wrapper = serialize_block_attributes( array( 'v' => $safe_value ) );
                if ( 0 !== strpos( $wrapper, '{"v":' ) || '}' !== substr( $wrapper, -1 ) ) {
                    return new WP_Error( 'nova_divi5_serialization_failed', 'WordPress could not serialize the native text value.', array( 'status' => 422 ) );
                }
                $encoded = substr( $wrapper, 5, -1 );
                if ( json_decode( $encoded, true ) !== $safe_value || JSON_ERROR_NONE !== json_last_error() ) {
                    return new WP_Error( 'nova_divi5_serialization_failed', 'The native text value failed round-trip validation.', array( 'status' => 422 ) );
                }

                $absolute_start = $blocks[ $index ]['attributes_start'] + $node['start'];
                $pointer        = $absolute_start . ':' . ( $node['end'] - $node['start'] );
                if ( isset( $replacements[ $pointer ] ) ) {
                    return new WP_Error( 'nova_divi5_duplicate_target', 'Two updates resolve to the same native JSON value.', array( 'status' => 422 ) );
                }
                $replacements[ $pointer ] = array(
                    'start'  => $absolute_start,
                    'length' => $node['end'] - $node['start'],
                    'value'  => $encoded,
                );
            }
        }

        $replacements = array_values( $replacements );
        usort(
            $replacements,
            function ( $a, $b ) {
                return $a['start'] <=> $b['start'];
            }
        );

        $result         = '';
        $cursor         = 0;
        $previous_end   = 0;
        foreach ( $replacements as $replacement ) {
            if ( $replacement['start'] < $previous_end ) {
                return new WP_Error( 'nova_divi5_overlapping_targets', 'Native text replacements overlap.', array( 'status' => 422 ) );
            }
            $result       .= substr( $document, $cursor, $replacement['start'] - $cursor ) . $replacement['value'];
            $cursor        = $replacement['start'] + $replacement['length'];
            $previous_end  = $cursor;
        }
        $result .= substr( $document, $cursor );

        $validation = nova_divi5_scan_document( $result );
        if ( is_wp_error( $validation ) || count( $validation ) !== count( $blocks ) ) {
            return new WP_Error(
                'nova_divi5_serialization_failed',
                'The native Divi 5 update could not be serialized safely.',
                array( 'status' => 422 )
            );
        }
        foreach ( $blocks as $index => $block ) {
            if (
                $validation[ $index ]['path'] !== $block['path']
                || $validation[ $index ]['name'] !== $block['name']
                || $validation[ $index ]['token_type'] !== $block['token_type']
                || $validation[ $index ]['parent_path'] !== $block['parent_path']
            ) {
                return new WP_Error(
                    'nova_divi5_serialization_failed',
                    'The native Divi 5 block structure changed during a text-only update.',
                    array( 'status' => 422 )
                );
            }
        }

        return $result;
    }
}

/**
 * Compact → shortcode string.
 *
 * Divi specifics:
 * - Attribute values must use Divi's percent-encoding (" => %22, [ => %91,
 *   ] => %93) instead of HTML entities: esc_attr would break the builder.
 *   nova_divi_encode_attr() is idempotent, so values parsed from existing
 *   content pass through unchanged.
 * - Divi always writes paired tags ([et_pb_x]...[/et_pb_x]); we only emit a
 *   self-closing tag when the source explicitly used one.
 */
if ( ! function_exists( 'nova_divi_compact_to_shortcodes' ) ) {
    function nova_divi_compact_to_shortcodes( $compact ) {
        $build = function ( $nodes ) use ( &$build ) {
            $out = '';

            foreach ( $nodes as $node ) {
                $tag        = isset( $node['tag'] ) ? (string) $node['tag'] : '';
                $attributes = isset( $node['attributes'] ) && is_array( $node['attributes'] ) ? $node['attributes'] : array();
                $children   = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
                $text       = isset( $node['text'] ) ? (string) $node['text'] : '';

                $atts_str = '';
                foreach ( $attributes as $key => $value ) {
                    $key = (string) $key;

                    // Normalize non-scalar attribute values.
                    if ( is_array( $value ) || is_object( $value ) ) {
                        $value = wp_json_encode( $value );
                    } elseif ( is_bool( $value ) ) {
                        $value = $value ? 'on' : 'off';
                    } elseif ( null === $value ) {
                        $value = '';
                    } else {
                        $value = (string) $value;
                    }

                    $atts_str .= ' ' . $key . '="' . nova_divi_encode_attr( $value ) . '"';
                }

                $inner = '';
                if ( ! empty( $children ) ) {
                    $inner .= $build( $children );
                }
                if ( '' !== $text ) {
                    $inner .= $text;
                }

                $self_closing = ! empty( $node['self_closing'] );

                if ( $self_closing && '' === $inner ) {
                    $out .= '[' . $tag . $atts_str . ' /]';
                } else {
                    $out .= '[' . $tag . $atts_str . ']' . $inner . '[/' . $tag . ']';
                }
            }

            return $out;
        };

        return $build( $compact );
    }
}
