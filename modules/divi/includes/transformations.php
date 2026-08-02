<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Normalize compact tree:
 * - If et_pb_divider contains text/children, convert it to et_pb_text
 *   (a divider is not a container; content inside it would be lost or
 *   break the builder).
 */
function nova_divi_normalize_compact_tree( $compact ) {
    $walk = function ( &$nodes ) use ( &$walk ) {
        foreach ( $nodes as &$node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }

            $tag = isset( $node['tag'] ) ? (string) $node['tag'] : '';

            $has_children = ( ! empty( $node['children'] ) && is_array( $node['children'] ) );
            $has_text     = ( isset( $node['text'] ) && '' !== trim( (string) $node['text'] ) );

            if ( 'et_pb_divider' === $tag && ( $has_children || $has_text ) ) {
                // Convert divider-with-content into a real text module.
                $node['tag']          = 'et_pb_text';
                $node['self_closing'] = false;
            }

            if ( $has_children ) {
                $walk( $node['children'] );
            }
        }
    };

    if ( is_array( $compact ) ) {
        $walk( $compact );
    }

    return $compact;
}

/**
 * Structural building blocks for generated Divi markup.
 */
function nova_divi_build_text_module( $html, $admin_label = '' ) {
    $bv    = nova_divi_builder_version();
    $label = '' !== trim( (string) $admin_label )
        ? ' admin_label="' . nova_divi_encode_attr( wp_strip_all_tags( $admin_label ) ) . '"'
        : '';

    return '[et_pb_text' . $label . ' _builder_version="' . nova_divi_encode_attr( $bv ) . '"]'
        . $html
        . '[/et_pb_text]';
}

/**
 * Wrap module markup in a full section > row > column skeleton.
 * `fb_built="1"` marks the section as built by the builder — without it the
 * Visual Builder treats the page as not built with Divi.
 */
function nova_divi_wrap_in_section( $modules_shortcodes, $admin_label = '' ) {
    $bv    = nova_divi_encode_attr( nova_divi_builder_version() );
    $label = '' !== trim( (string) $admin_label )
        ? ' admin_label="' . nova_divi_encode_attr( wp_strip_all_tags( $admin_label ) ) . '"'
        : '';

    return '[et_pb_section fb_built="1"' . $label . ' _builder_version="' . $bv . '"]'
        . '[et_pb_row _builder_version="' . $bv . '"]'
        . '[et_pb_column type="4_4" _builder_version="' . $bv . '"]'
        . $modules_shortcodes
        . '[/et_pb_column][/et_pb_row][/et_pb_section]';
}

/**
 * Parse FAQ HTML (<h3>Question</h3><p>Answer</p> pairs) into items.
 *
 * @return array [ [ 'title' => string, 'body' => string ], ... ]
 */
function nova_divi_parse_faq_items_from_html( $html ) {
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

        // If answer is wrapped in a single <p>...</p>, keep it — Divi accordion
        // bodies are regular HTML. Just sanitize.
        $a = wp_kses_post( $a );

        $items[] = array(
            'title' => $q,
            'body'  => $a,
        );
    }

    return $items;
}

/**
 * Build an et_pb_accordion from FAQ items. Divi opens the first accordion
 * item by default, so the first item gets open="on" and the rest open="off".
 * The accordion sits directly in the column — never inside et_pb_text.
 */
function nova_divi_build_accordion_shortcode( $items, $admin_label = 'FAQ' ) {
    if ( empty( $items ) || ! is_array( $items ) ) {
        return '';
    }

    $bv  = nova_divi_encode_attr( nova_divi_builder_version() );
    $out = '[et_pb_accordion admin_label="' . nova_divi_encode_attr( wp_strip_all_tags( $admin_label ) ) . '" _builder_version="' . $bv . '"]';

    $first = true;
    foreach ( $items as $item ) {
        $title = isset( $item['title'] ) ? wp_strip_all_tags( (string) $item['title'] ) : '';
        $body  = isset( $item['body'] ) ? (string) $item['body'] : '';

        if ( '' === trim( $title ) || '' === trim( $body ) ) {
            continue;
        }

        $out .= '[et_pb_accordion_item title="' . nova_divi_encode_attr( $title ) . '"'
            . ' open="' . ( $first ? 'on' : 'off' ) . '"'
            . ' _builder_version="' . $bv . '"]'
            . $body
            . '[/et_pb_accordion_item]';

        $first = false;
    }

    $out .= '[/et_pb_accordion]';

    return $first ? '' : $out; // No valid items => empty string.
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
 * builder renders them as an et_pb_accordion.
 */
function nova_divi_expand_single_html_section_to_multiple( $sections, $page_title = '' ) {
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
 * Build the Divi shortcode markup for one appended section.
 */
function nova_divi_build_section_shortcodes( $section, $page_title = '' ) {
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

    // FAQ section type: heading text module + accordion, directly in the column.
    if ( 'faq' === $type ) {
        $items = nova_divi_parse_faq_items_from_html( $body );

        if ( empty( $items ) && '' !== trim( $body ) ) {
            // No h3 Q&A structure — fall back to a single item.
            $items = array(
                array(
                    'title' => '' !== $title_clean ? $title_clean : 'FAQ',
                    'body'  => $body,
                ),
            );
        }

        $accordion = nova_divi_build_accordion_shortcode( $items, '' !== $title_clean ? $title_clean : 'FAQ' );
        if ( '' === $accordion ) {
            return '';
        }

        $modules = '';
        if ( '' !== $title_clean ) {
            $modules .= nova_divi_build_text_module(
                '<' . $title_tag . '>' . esc_html( $title_clean ) . '</' . $title_tag . '>',
                $title_clean
            );
        }
        $modules .= $accordion;

        return nova_divi_wrap_in_section( $modules, '' !== $title_clean ? $title_clean : 'FAQ' );
    }

    if ( '' === trim( $body ) && '' === $title_clean ) {
        return '';
    }

    // Regular section: heading is HTML inside the text module (Divi 4 has no
    // heading module on older sites).
    $html = '';
    if ( '' !== $title_clean ) {
        $html .= '<' . $title_tag . '>' . esc_html( $title_clean ) . '</' . $title_tag . '>';
    }
    if ( '' !== trim( $body ) ) {
        $html .= $body;
    }

    return nova_divi_wrap_in_section(
        nova_divi_build_text_module( $html, $title_clean ),
        $title_clean
    );
}

/**
 * Apply transformations: remove_paths / text_updates / append_*.
 */
function nova_divi_apply_transformations( $shortcodes, $remove_paths, $text_updates, $append_html, $append_sections ) {
    $shortcodes = (string) $shortcodes;

    if (
        empty( $remove_paths )
        && empty( $text_updates )
        && '' === $append_html
        && empty( $append_sections )
    ) {
        return $shortcodes;
    }

    if ( in_array( nova_divi_content_format( $shortcodes ), array( 'divi5-blocks', 'hybrid' ), true ) ) {
        return $shortcodes;
    }

    // Defensive: if dependencies aren't loaded, don't fatal.
    if (
        ! function_exists( 'nova_divi_parse_shortcodes_to_compact' )
        || ! function_exists( 'nova_divi_compact_to_shortcodes' )
    ) {
        return $shortcodes;
    }

    $compact = nova_divi_parse_shortcodes_to_compact( $shortcodes );

    // Text updates FIRST: both path sets come from the same GET outline, and
    // removing nodes re-indexes siblings — applying removals first would make
    // the text_update paths land on the wrong nodes.
    if ( ! empty( $text_updates ) ) {
        $compact = nova_divi_apply_text_updates_to_compact( $compact, $text_updates );
    }
    if ( ! empty( $remove_paths ) ) {
        $compact = nova_divi_remove_paths_from_compact( $compact, $remove_paths );
    }

    // Normalize (fix divider-with-content issues before serializing).
    $compact = nova_divi_normalize_compact_tree( $compact );

    $shortcodes = nova_divi_compact_to_shortcodes( $compact );

    // Append HTML as one extra text module in its own section.
    if ( '' !== $append_html ) {
        $safe_html = (string) $append_html;
        $safe_html = str_ireplace( array( '<h1', '</h1>' ), array( '<h2', '</h2>' ), $safe_html );
        $safe_html = wp_kses_post( $safe_html );

        $shortcodes .= nova_divi_wrap_in_section( nova_divi_build_text_module( $safe_html ) );
    }

    // Append sections.
    if ( ! empty( $append_sections ) && is_array( $append_sections ) ) {
        foreach ( $append_sections as $section ) {
            if ( ! is_array( $section ) ) {
                continue;
            }
            $shortcodes .= nova_divi_build_section_shortcodes( $section );
        }
    }

    return $shortcodes;
}

/**
 * Remove nodes whose path is in remove_paths.
 */
function nova_divi_remove_paths_from_compact( $compact, $paths ) {
    $paths = array_map( 'strval', (array) $paths );

    $walk = function ( $nodes, $prefix = '' ) use ( &$walk, $paths ) {
        $result = array();

        foreach ( $nodes as $idx => $node ) {
            $path = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;

            if ( in_array( $path, $paths, true ) ) {
                continue;
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $node['children'] = $walk( $node['children'], $path );
            }

            $result[] = $node;
        }

        return $result;
    };

    return $walk( $compact );
}

/**
 * Apply text_updates to compact tree via path.
 *
 * The default target field per tag comes from
 * nova_divi_default_text_field_for_tag() — the SAME map the outline/text_map
 * extraction uses, so an update always writes to the field the client saw:
 * - et_pb_button: attributes["button_text"]
 * - et_pb_accordion_item / et_pb_toggle / et_pb_blurb / et_pb_cta /
 *   et_pb_heading / et_pb_fullwidth_header / ...: attributes["title"] etc.
 * - body-text modules (et_pb_text, ...): inner body HTML
 * - explicit {field: "..."} overrides: "body"/"content" targets the inner
 *   body (e.g. an accordion item's answer), any other name targets that
 *   attribute (plain text)
 * - image/divider modules: skipped (no text)
 *
 * Attribute values are stored raw here; the serializer applies Divi's
 * percent-encoding (%22 / %91 / %93) when building the shortcode string.
 */
function nova_divi_apply_text_updates_to_compact( $compact, $updates ) {
    $map = array();

    foreach ( $updates as $update ) {
        if ( empty( $update['path'] ) ) {
            continue;
        }
        $path         = (string) $update['path'];
        $map[ $path ][] = array(
            'text'  => isset( $update['text'] ) ? (string) $update['text'] : '',
            'field' => isset( $update['field'] ) ? (string) $update['field'] : '',
        );
    }

    $walk = function ( $nodes, $prefix = '' ) use ( &$walk, $map ) {
        foreach ( $nodes as $idx => &$node ) {
            $path = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;

            if ( array_key_exists( $path, $map ) ) {
                foreach ( $map[ $path ] as $change ) {
                    $new_text = $change['text'];
                    $field    = strtolower( trim( $change['field'] ) );
                    $tag      = isset( $node['tag'] ) ? (string) $node['tag'] : '';

                    if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
                        $node['attributes'] = array();
                    }

                    $default_field = nova_divi_default_text_field_for_tag( $tag );

                    if ( 'body' === $field || 'content' === $field ) {
                        $node['text'] = wp_kses_post( (string) $new_text );
                    } elseif ( '' !== $field ) {
                        $node['attributes'][ sanitize_key( $field ) ] = wp_strip_all_tags( $new_text );
                    } elseif ( null !== $default_field ) {
                        $node['attributes'][ $default_field ] = wp_strip_all_tags( $new_text );
                    } elseif ( in_array( $tag, array( 'et_pb_image', 'et_pb_fullwidth_image', 'et_pb_divider' ), true ) ) {
                        // Never inject body content into images/dividers.
                    } else {
                        $node['text'] = wp_kses_post( (string) $new_text );
                    }
                }
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $node['children'] = $walk( $node['children'], $path );
            }
        }

        return $nodes;
    };

    return $walk( $compact );
}

/**
 * Whether an et_pb_text node is a CONTENT slot (article body) as opposed to
 * template chrome (hero subtitle, CTA copy, footer note, ...).
 *
 * A content slot is an et_pb_text that is empty (placeholder), contains an
 * h2/h3/h4 heading, or holds paragraph-scale text. Short heading-less texts
 * are template chrome: they are neither filled nor cleared, so cloned
 * branding copy survives — use text_updates/remove_paths to change those.
 */
function nova_divi_is_content_slot_node( $node ) {
    if ( ! is_array( $node ) || ! isset( $node['tag'] ) || 'et_pb_text' !== (string) $node['tag'] ) {
        return false;
    }

    $body    = isset( $node['text'] ) ? (string) $node['text'] : '';
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
 * Divi version: content slots are et_pb_text modules that pass
 * nova_divi_is_content_slot_node(). Sections are written into them
 * sequentially (heading as <h2> HTML inside the body). Remaining unfilled
 * content slots are removed afterwards (and containers they leave empty are
 * pruned) so no stale example content or empty bands survive. Sections that
 * don't fit — plus FAQ sections, which need an accordion — are returned for
 * appending.
 */
function nova_divi_replace_template_slots_with_sections( $shortcodes, $sections, $page_title = '', $clear_remaining = true ) {
    $shortcodes = (string) $shortcodes;
    $sections   = is_array( $sections ) ? array_values( $sections ) : array();

    if ( '' === $shortcodes || empty( $sections ) ) {
        return array( $shortcodes, $sections );
    }

    if ( in_array( nova_divi_content_format( $shortcodes ), array( 'divi5-blocks', 'hybrid' ), true ) ) {
        return array( $shortcodes, $sections );
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

    if ( ! function_exists( 'nova_divi_parse_shortcodes_to_compact' ) || ! function_exists( 'nova_divi_compact_to_shortcodes' ) ) {
        return array( $shortcodes, $sections );
    }

    $compact = nova_divi_parse_shortcodes_to_compact( $shortcodes );

    $section_i          = 0;
    $first_slot_filled  = false;

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
            if ( nova_divi_is_content_slot_node( $node ) && $section_i < count( $fillable ) ) {
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

                $node['text']         = $html;
                $node['children']     = array();
                $node['self_closing'] = false;
                $node['__nova_keep']  = true;

                $first_slot_filled = true;
                $section_i++;
            }

            if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                $walk_fill( $node['children'] );
            }
        }
    };

    $walk_fill( $compact );

    if ( $clear_remaining ) {
        // Remove unfilled content slots entirely (stale example article text),
        // then prune containers that end up with no modules so the page
        // doesn't render empty section bands. Template chrome (short texts,
        // blurbs, CTAs, buttons, images) is intentionally left untouched.
        $walk_prune = function ( $nodes ) use ( &$walk_prune ) {
            $result = array();

            foreach ( $nodes as $node ) {
                if ( nova_divi_is_content_slot_node( $node ) && empty( $node['__nova_keep'] ) ) {
                    continue;
                }

                if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
                    $node['children'] = $walk_prune( $node['children'] );

                    // Drop structural containers left without any children.
                    $tag      = isset( $node['tag'] ) ? (string) $node['tag'] : '';
                    $has_text = isset( $node['text'] ) && '' !== trim( (string) $node['text'] );
                    if ( empty( $node['children'] ) && ! $has_text && nova_divi_is_structural_container_tag( $tag ) ) {
                        continue;
                    }
                }

                $result[] = $node;
            }

            return $result;
        };

        $compact = $walk_prune( $compact );
    }

    // Normalize before serialize.
    $compact = nova_divi_normalize_compact_tree( $compact );

    $new_shortcodes = nova_divi_compact_to_shortcodes( $compact );

    $remaining = $deferred;
    if ( $section_i < count( $fillable ) ) {
        $remaining = array_merge( array_slice( $fillable, $section_i ), $deferred );
    }

    return array( $new_shortcodes, $remaining );
}
