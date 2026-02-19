<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize compact tree:
 * - If vc_empty_space contains text/children, convert it to vc_column_text
 *   (otherwise VC parsing can go off the rails and push later blocks down).
 */
function nova_wpb_normalize_compact_tree( $compact ) {
	$walk = function ( &$nodes ) use ( &$walk ) {
		foreach ( $nodes as &$node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$tag = isset( $node['tag'] ) ? (string) $node['tag'] : '';

			$has_children = ( ! empty( $node['children'] ) && is_array( $node['children'] ) );
			$has_text     = ( isset( $node['text'] ) && '' !== trim( (string) $node['text'] ) );

			if ( 'vc_empty_space' === $tag && ( $has_children || $has_text ) ) {
				// Convert spacer-with-content into a real text container.
				$node['tag']          = 'vc_column_text';
				$node['self_closing'] = false;

				// Spacer attrs like height are meaningless for text blocks; drop them.
				if ( isset( $node['attributes'] ) && is_array( $node['attributes'] ) ) {
					unset( $node['attributes']['height'] );
				}
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
 * Convert FAQ HTML inside a section:
 * <h3>Q</h3><p>A</p> => [ot_faqs title="Q"]A[/ot_faqs]
 */
function nova_wpb_convert_faq_html_to_ot_faqs( $html ) {
	$html = (string) $html;

	// Capture h3 blocks and everything until next h3 (or end).
	if ( ! preg_match_all( '/<h3\b[^>]*>(.*?)<\/h3>\s*([\s\S]*?)(?=<h3\b|$)/i', $html, $ms, PREG_SET_ORDER ) ) {
		return $html;
	}

	$out = '';
	foreach ( $ms as $m ) {
		$q = trim( wp_strip_all_tags( (string) $m[1] ) );
		$a = trim( (string) $m[2] );

		if ( '' === $q || '' === $a ) {
			continue;
		}

		// If answer is wrapped in a single <p>...</p>, unwrap it.
		$a = preg_replace( '/^\s*<p\b[^>]*>/i', '', $a );
		$a = preg_replace( '/<\/p>\s*$/i', '', $a );

		// Keep safe HTML in answers.
		$a = wp_kses_post( $a );

		$out .= '[ot_faqs title="' . esc_attr( $q ) . '"]' . $a . '[/ot_faqs]' . "\n\n";
	}

	return ( '' !== trim( $out ) ) ? trim( $out ) : $html;
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
 *     {title:"B", body:"...", title_tag:"h2"},
 *     ...
 *   ]
 *
 * Also converts the FAQ section (Veelgestelde vragen) into ot_faqs shortcodes.
 */
function nova_wpb_expand_single_html_section_to_multiple( $sections, $page_title = '' ) {
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

			// Convert FAQ section.
			if ( '' !== $title && false !== stripos( $title, 'veelgestelde vragen' ) ) {
				$chunk = nova_wpb_convert_faq_html_to_ot_faqs( $chunk );
			}

			$new[] = array(
				'title'     => $title,
				'body'      => $chunk,
				'title_tag' => 'h2',
			);
		}
	}

	return ! empty( $new ) ? $new : $sections;
}

/**
 * Apply transformations: remove_paths / text_updates / append_*.
 */
function nova_wpb_apply_transformations( $shortcodes, $remove_paths, $text_updates, $append_html, $append_sections ) {
	$shortcodes = (string) $shortcodes;

	if (
		empty( $remove_paths )
		&& empty( $text_updates )
		&& '' === $append_html
		&& empty( $append_sections )
	) {
		return $shortcodes;
	}

	// Defensive: if dependencies aren't loaded, don't fatal.
	if (
		! function_exists( 'nova_wpb_parse_shortcodes_to_compact' )
		|| ! function_exists( 'nova_wpb_compact_to_shortcodes' )
	) {
		return $shortcodes;
	}

	$compact = nova_wpb_parse_shortcodes_to_compact( $shortcodes );

	if ( ! empty( $remove_paths ) ) {
		$compact = nova_wpb_remove_paths_from_compact( $compact, $remove_paths );
	}
	if ( ! empty( $text_updates ) ) {
		$compact = nova_wpb_apply_text_updates_to_compact( $compact, $text_updates );
	}

	// ✅ Normalize (fix spacer-with-content issues that break layout downstream).
	$compact = nova_wpb_normalize_compact_tree( $compact );

	$shortcodes = nova_wpb_compact_to_shortcodes( $compact );

	// Append HTML as one extra Text Block.
	if ( '' !== $append_html ) {
		$safe_html = (string) $append_html;
		$safe_html = str_ireplace( array( '<h1', '</h1>' ), array( '<h2', '</h2>' ), $safe_html );
		$safe_html = wp_kses_post( $safe_html );

		$shortcodes .= '[vc_row][vc_column][vc_column_text]' . $safe_html . '[/vc_column_text][/vc_column][/vc_row]';
	}

	// Append sections.
	if ( ! empty( $append_sections ) && is_array( $append_sections ) ) {
		foreach ( $append_sections as $section ) {
			// FAQ section type support.
			if ( isset( $section['type'] ) && 'faq' === strtolower( (string) $section['type'] ) ) {
				$faq_title = isset( $section['title'] ) ? wp_strip_all_tags( (string) $section['title'] ) : '';
				$faq_body  = isset( $section['body'] ) ? wp_kses_post( (string) $section['body'] ) : '';
				if ( '' !== trim( $faq_title ) && '' !== trim( $faq_body ) ) {
					// Put FAQs inside a text container so nested shortcodes render reliably.
					$shortcodes .= '[vc_row][vc_column][vc_column_text]'
						. '[ot_faqs title="' . esc_attr( $faq_title ) . '"]' . $faq_body . '[/ot_faqs]'
						. '[/vc_column_text][/vc_column][/vc_row]';
				}
				continue;
			}

			$title     = isset( $section['title'] ) ? (string) $section['title'] : '';
			$body      = isset( $section['body'] ) ? (string) $section['body'] : '';
			$title_tag = isset( $section['title_tag'] ) ? (string) $section['title_tag'] : 'h2';

			$title_tag = strtolower( trim( $title_tag ) );
			if ( ! in_array( $title_tag, array( 'h2', 'h3', 'h4' ), true ) ) {
				$title_tag = 'h2';
			}

			$body = str_ireplace( array( '<h1', '</h1>' ), array( '<h2', '</h2>' ), $body );
			$body = wp_kses_post( $body );

			$shortcodes .= '[vc_row][vc_column]';

			if ( '' !== trim( $title ) ) {
				// Self-close to avoid swallowing later shortcodes.
				$shortcodes .= '[vc_custom_heading text="'
					. esc_attr( wp_strip_all_tags( $title ) )
					. '" use_theme_fonts="yes" font_container="tag:' . esc_attr( $title_tag ) . '" /]';
			}

			if ( '' !== trim( $body ) ) {
				$shortcodes .= '[vc_column_text]' . $body . '[/vc_column_text]';
			}

			$shortcodes .= '[/vc_column][/vc_row]';
		}
	}

	return $shortcodes;
}

/**
 * Remove nodes whose path is in remove_paths.
 */
function nova_wpb_remove_paths_from_compact( $compact, $paths ) {
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
 * - vc_custom_heading: update attributes["text"]
 * - theme heading: update attributes["text"]
 * - theme button: update attributes["btntext"]
 * - everything else: update inner text (EXCEPT vc_empty_space)
 */
function nova_wpb_apply_text_updates_to_compact( $compact, $updates ) {
	$map = array();

	foreach ( $updates as $update ) {
		if ( empty( $update['path'] ) ) {
			continue;
		}
		$path         = (string) $update['path'];
		$map[ $path ] = isset( $update['text'] ) ? (string) $update['text'] : '';
	}

	$walk = function ( $nodes, $prefix = '' ) use ( &$walk, $map ) {
		foreach ( $nodes as $idx => &$node ) {
			$path = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;

			if ( array_key_exists( $path, $map ) ) {
				$new_text = $map[ $path ];
				$tag      = isset( $node['tag'] ) ? (string) $node['tag'] : '';

				if ( 'vc_custom_heading' === $tag ) {
					if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
						$node['attributes'] = array();
					}
					$node['attributes']['text'] = wp_strip_all_tags( $new_text );
					$node['text']               = '';
				} elseif ( 'heading' === $tag ) {
					if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
						$node['attributes'] = array();
					}
					$node['attributes']['text'] = wp_strip_all_tags( $new_text );
				} elseif ( 'button' === $tag ) {
					if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
						$node['attributes'] = array();
					}
					$node['attributes']['btntext'] = wp_strip_all_tags( $new_text );
					$node['text']                  = '';
				} else {
					// Never inject content into spacer shortcodes.
					if ( 'vc_empty_space' !== $tag ) {
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
 * Clear visible text from a node (keeps structure/attributes not related to text).
 */
function nova_wpb_clear_visible_text_in_node( &$node ) {
	if ( ! is_array( $node ) ) {
		return;
	}

	if ( isset( $node['text'] ) ) {
		$node['text'] = '';
	}

	if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
		return;
	}

	$keys = array(
		'text',
		'title',
		'desc',
		'description',
		'btntext',
		'button_text',
		'label',
		'heading',
		'subheading',
		'subtitle',
	);

	foreach ( $keys as $k ) {
		if ( array_key_exists( $k, $node['attributes'] ) ) {
			$node['attributes'][ $k ] = '';
		}
	}
}

/**
 * Helpers for slot relocation.
 */
function nova_wpb_node_has_tag( $node, $tag ) {
	if ( ! is_array( $node ) ) {
		return false;
	}
	if ( isset( $node['tag'] ) && $tag === (string) $node['tag'] ) {
		return true;
	}
	if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
		foreach ( $node['children'] as $c ) {
			if ( nova_wpb_node_has_tag( $c, $tag ) ) {
				return true;
			}
		}
	}
	return false;
}

function nova_wpb_node_collect_first( $node, $tag ) {
	if ( ! is_array( $node ) ) {
		return null;
	}
	if ( isset( $node['tag'] ) && $tag === (string) $node['tag'] ) {
		return $node;
	}
	if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
		foreach ( $node['children'] as $c ) {
			$found = nova_wpb_node_collect_first( $c, $tag );
			if ( $found ) {
				return $found;
			}
		}
	}
	return null;
}

function nova_wpb_is_banner_row( $row ) {
	if ( ! is_array( $row ) || empty( $row['tag'] ) || 'vc_row' !== (string) $row['tag'] ) {
		return false;
	}

	if ( nova_wpb_node_has_tag( $row, 'info_apps2' ) ) {
		return true;
	}

	if ( ! empty( $row['attributes']['css'] ) && is_string( $row['attributes']['css'] ) ) {
		$css = $row['attributes']['css'];
		if ( false !== stripos( $css, 'background-image' ) && false !== stripos( $css, 'pattern-full' ) ) {
			return true;
		}
	}

	return false;
}

function nova_wpb_is_call_to_action_row( $row ) {
	if ( ! is_array( $row ) || empty( $row['tag'] ) || 'vc_row' !== (string) $row['tag'] ) {
		return false;
	}
	if ( ! empty( $row['attributes']['el_class'] ) && is_string( $row['attributes']['el_class'] ) ) {
		return ( false !== stripos( $row['attributes']['el_class'], 'call-to-action' ) );
	}
	return false;
}

function nova_wpb_is_secondary_cta_row( $row ) {
	if ( ! is_array( $row ) || empty( $row['tag'] ) || 'vc_row' !== (string) $row['tag'] ) {
		return false;
	}
	if ( nova_wpb_is_call_to_action_row( $row ) ) {
		return false;
	}
	if ( nova_wpb_node_has_tag( $row, 'vc_single_image' ) ) {
		return false;
	}
	if ( nova_wpb_node_has_tag( $row, 'info_apps2' ) ) {
		return false;
	}

	$has_heading = nova_wpb_node_has_tag( $row, 'heading' ) || nova_wpb_node_has_tag( $row, 'vc_custom_heading' );
	$has_text    = nova_wpb_node_has_tag( $row, 'vc_column_text' );
	$has_button  = nova_wpb_node_has_tag( $row, 'button' );

	return ( $has_heading && $has_text && $has_button );
}

function nova_wpb_is_placeholder_slot_row( $row ) {
	if ( ! is_array( $row ) || empty( $row['tag'] ) || 'vc_row' !== (string) $row['tag'] ) {
		return false;
	}

	if ( nova_wpb_is_call_to_action_row( $row ) ) {
		return false;
	}
	if ( nova_wpb_node_has_tag( $row, 'vc_single_image' ) || nova_wpb_node_has_tag( $row, 'info_apps2' ) ) {
		return false;
	}
	if ( nova_wpb_node_has_tag( $row, 'button' ) ) {
		return false;
	}

	$h = null;
	if ( nova_wpb_node_has_tag( $row, 'vc_custom_heading' ) ) {
		$h  = nova_wpb_node_collect_first( $row, 'vc_custom_heading' );
		$ht = isset( $h['attributes']['text'] ) ? (string) $h['attributes']['text'] : '';
		if ( '' !== trim( $ht ) ) {
			return false;
		}
	} elseif ( nova_wpb_node_has_tag( $row, 'heading' ) ) {
		$h  = nova_wpb_node_collect_first( $row, 'heading' );
		$ht = isset( $h['attributes']['text'] ) ? (string) $h['attributes']['text'] : '';
		if ( '' !== trim( $ht ) ) {
			return false;
		}
	} else {
		return false;
	}

	$t = nova_wpb_node_collect_first( $row, 'vc_column_text' );
	if ( ! $t ) {
		return false;
	}
	$tt = isset( $t['text'] ) ? trim( wp_strip_all_tags( (string) $t['text'] ) ) : '';
	return ( '' === $tt );
}

function nova_wpb_reposition_placeholder_rows( $compact ) {
	if ( ! is_array( $compact ) || empty( $compact ) ) {
		return $compact;
	}

	$banner_idx = -1;
	foreach ( $compact as $i => $node ) {
		if ( nova_wpb_is_banner_row( $node ) ) {
			$banner_idx = (int) $i;
			break;
		}
	}
	if ( $banner_idx < 0 ) {
		return $compact;
	}

	$to_move = array();
	$kept    = array();

	foreach ( $compact as $i => $node ) {
		if ( $i > $banner_idx && nova_wpb_is_placeholder_slot_row( $node ) ) {
			$to_move[] = $node;
		} else {
			$kept[] = $node;
		}
	}

	if ( empty( $to_move ) ) {
		return $compact;
	}

	$banner_idx2 = -1;
	foreach ( $kept as $i => $node ) {
		if ( nova_wpb_is_banner_row( $node ) ) {
			$banner_idx2 = (int) $i;
			break;
		}
	}
	if ( $banner_idx2 < 0 ) {
		return $kept;
	}

	$insert_before2 = $banner_idx2;
	for ( $i = 0; $i < $banner_idx2; $i++ ) {
		if ( nova_wpb_is_secondary_cta_row( $kept[ $i ] ) ) {
			$insert_before2 = (int) $i;
			break;
		}
	}

	$out = array();
	foreach ( $kept as $i => $node ) {
		if ( $i === $insert_before2 ) {
			foreach ( $to_move as $m ) {
				$out[] = $m;
			}
		}
		$out[] = $node;
	}

	return $out;
}

function nova_wpb_prune_placeholder_rows( $compact ) {
	if ( ! is_array( $compact ) ) {
		return $compact;
	}
	$out = array();
	foreach ( $compact as $node ) {
		if ( nova_wpb_is_placeholder_slot_row( $node ) ) {
			continue;
		}
		$out[] = $node;
	}
	return $out;
}

/**
 * Replace template slots with sections while preserving template layout.
 */
function nova_wpb_replace_template_slots_with_sections( $shortcodes, $sections, $page_title = '', $clear_remaining = true ) {
	$shortcodes = (string) $shortcodes;
	$sections   = is_array( $sections ) ? array_values( $sections ) : array();

	if ( '' === $shortcodes || empty( $sections ) ) {
		return array( $shortcodes, $sections );
	}

	$page_title_norm = strtolower( trim( wp_strip_all_tags( (string) $page_title ) ) );

	foreach ( $sections as &$s ) {
		$s['title'] = isset( $s['title'] ) ? trim( wp_strip_all_tags( (string) $s['title'] ) ) : '';
		$body       = isset( $s['body'] ) ? (string) $s['body'] : '';

		$body = str_ireplace( array( '<h1', '</h1>' ), array( '<h2', '</h2>' ), $body );
		$body = wp_kses_post( $body );
		$s['body'] = $body;

		$tag = isset( $s['title_tag'] ) ? strtolower( trim( (string) $s['title_tag'] ) ) : 'h2';
		if ( ! in_array( $tag, array( 'h2', 'h3', 'h4' ), true ) ) {
			$tag = 'h2';
		}
		$s['title_tag'] = $tag;
	}
	unset( $s );

	if ( ! function_exists( 'nova_wpb_parse_shortcodes_to_compact' ) || ! function_exists( 'nova_wpb_compact_to_shortcodes' ) ) {
		return array( $shortcodes, $sections );
	}

	$compact = nova_wpb_parse_shortcodes_to_compact( $shortcodes );

	$compact = nova_wpb_reposition_placeholder_rows( $compact );

	$section_i          = 0;
	$waiting_for_body   = false;
	$first_heading_seen = false;

	$walk_fill = function ( &$nodes ) use ( &$walk_fill, $sections, &$section_i, &$waiting_for_body, $page_title_norm, &$first_heading_seen ) {
		foreach ( $nodes as &$node ) {
			$tag = isset( $node['tag'] ) ? (string) $node['tag'] : '';

			if ( $section_i < count( $sections ) ) {
				$sec_title = $sections[ $section_i ]['title'];
				$sec_body  = $sections[ $section_i ]['body'];
				$sec_tag   = $sections[ $section_i ]['title_tag'];

				if ( 'heading' === $tag && ! $waiting_for_body ) {
					if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
						$node['attributes'] = array();
					}

					$set_title      = $sec_title;
					$sec_title_norm = strtolower( trim( $sec_title ) );
					if ( ! $first_heading_seen && '' !== $page_title_norm && '' !== $sec_title_norm && $sec_title_norm === $page_title_norm ) {
						$set_title = '';
					}

					$node['attributes']['text'] = $set_title;
					if ( array_key_exists( 'tag', $node['attributes'] ) ) {
						$node['attributes']['tag'] = $sec_tag;
					}

					$node['__nova_keep'] = true;

					$first_heading_seen = true;
					$waiting_for_body   = true;
				} elseif ( 'vc_custom_heading' === $tag && ! $waiting_for_body ) {
					if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
						$node['attributes'] = array();
					}

					$set_title      = $sec_title;
					$sec_title_norm = strtolower( trim( $sec_title ) );
					if ( ! $first_heading_seen && '' !== $page_title_norm && '' !== $sec_title_norm && $sec_title_norm === $page_title_norm ) {
						$set_title = '';
					}

					$node['attributes']['text']            = $set_title;
					$node['attributes']['use_theme_fonts'] = isset( $node['attributes']['use_theme_fonts'] ) ? $node['attributes']['use_theme_fonts'] : 'yes';
					$node['attributes']['font_container']  = 'tag:' . $sec_tag;
					$node['text']                          = '';

					$node['__nova_keep'] = true;

					$first_heading_seen = true;
					$waiting_for_body   = true;
				} elseif ( $waiting_for_body && 'vc_column_text' === $tag ) {
					$node['children'] = array();
					$node['text']     = $sec_body;

					$node['__nova_keep'] = true;

					$waiting_for_body = false;
					$section_i++;
				}
			}

			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$walk_fill( $node['children'] );
			}
		}
	};

	$walk_fill( $compact );

	if ( $clear_remaining ) {
		$walk_clear = function ( &$nodes ) use ( &$walk_clear ) {
			foreach ( $nodes as &$node ) {
				$tag = isset( $node['tag'] ) ? (string) $node['tag'] : '';

				$contentish = in_array(
					$tag,
					array(
						'vc_custom_heading',
						'vc_column_text',
						'heading',
					),
					true
				);

				if ( $contentish && empty( $node['__nova_keep'] ) ) {
					nova_wpb_clear_visible_text_in_node( $node );
				}

				if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
					$walk_clear( $node['children'] );
				}
			}
		};

		$walk_clear( $compact );

		$compact = nova_wpb_prune_placeholder_rows( $compact );
	}

	// Normalize before serialize.
	$compact = nova_wpb_normalize_compact_tree( $compact );

	$new_shortcodes = nova_wpb_compact_to_shortcodes( $compact );
	$remaining      = array();

	if ( $section_i < count( $sections ) ) {
		$remaining = array_slice( $sections, $section_i );
	}

	return array( $new_shortcodes, $remaining );
}
