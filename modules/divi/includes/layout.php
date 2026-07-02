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

            $children = nova_divi_parse_shortcodes_to_compact( $inner );

            // Keep text only on leaf nodes (no children).
            $text = '';
            if ( empty( $children ) && '' !== trim( $inner ) ) {
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

                    // Visible text carried in attributes (Divi encodes " [ ] as %22 %91 %93).
                    $attr_text_candidates = array();
                    switch ( $tag ) {
                        case 'et_pb_button':
                            $attr_text_candidates = array( 'button_text' );
                            break;
                        case 'et_pb_heading':
                            $attr_text_candidates = array( 'title' );
                            break;
                        case 'et_pb_blurb':
                        case 'et_pb_cta':
                        case 'et_pb_accordion_item':
                        case 'et_pb_toggle':
                        case 'et_pb_number_counter':
                        case 'et_pb_pricing_table':
                            $attr_text_candidates = array( 'title' );
                            break;
                        case 'et_pb_fullwidth_header':
                            $attr_text_candidates = array( 'title', 'subhead' );
                            break;
                        case 'et_pb_testimonial':
                            $attr_text_candidates = array( 'author' );
                            break;
                        case 'et_pb_slide':
                            $attr_text_candidates = array( 'heading' );
                            break;
                        case 'et_pb_image':
                        case 'et_pb_fullwidth_image':
                            $attr_text_candidates = array( 'title_text', 'alt' );
                            break;
                        case 'et_pb_team_member':
                            $attr_text_candidates = array( 'name' );
                            break;
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
