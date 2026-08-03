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
		unset( $node );
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
 *
 * $section_shell (optional) carries row/column attributes captured from a template
 * slot so overflow sections inherit the template's styling instead of landing in a
 * bare [vc_row][vc_column]. See nova_wpb_capture_row_shell().
 */
function nova_wpb_apply_transformations( $shortcodes, $remove_paths, $text_updates, $append_html, $append_sections, $section_shell = null ) {
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

	/*
	 * text_updates MUST run before remove_paths. Both are keyed to the same
	 * layout.outline the caller was shown, but removal re-indexes siblings
	 * (nova_wpb_remove_paths_from_compact rebuilds arrays with $result[]), so
	 * updates applied afterwards would land on the wrong nodes. (NOVA-268)
	 */
	if ( ! empty( $text_updates ) ) {
		$compact = nova_wpb_apply_text_updates_to_compact( $compact, $text_updates );
	}
	if ( ! empty( $remove_paths ) ) {
		$compact = nova_wpb_remove_paths_from_compact( $compact, $remove_paths );
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
		$shell_row = ( is_array( $section_shell ) && isset( $section_shell['row'] ) ) ? $section_shell['row'] : array();
		$shell_col = ( is_array( $section_shell ) && isset( $section_shell['column'] ) ) ? $section_shell['column'] : array();

		$row_open = function_exists( 'nova_wpb_attrs_to_string' )
			? '[vc_row' . nova_wpb_attrs_to_string( $shell_row ) . '][vc_column' . nova_wpb_attrs_to_string( $shell_col ) . ']'
			: '[vc_row][vc_column]';

		foreach ( $append_sections as $section ) {
			// FAQ section type support.
			if ( isset( $section['type'] ) && 'faq' === strtolower( (string) $section['type'] ) ) {
				$faq_title = isset( $section['title'] ) ? wp_strip_all_tags( (string) $section['title'] ) : '';
				$faq_body  = isset( $section['body'] ) ? wp_kses_post( (string) $section['body'] ) : '';
				if ( '' !== trim( $faq_title ) && '' !== trim( $faq_body ) ) {
					// Put FAQs inside a text container so nested shortcodes render reliably.
					$shortcodes .= $row_open . '[vc_column_text]'
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

			$shortcodes .= $row_open;

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
		unset( $node );

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
 * Is this top-level row allowed to receive generated section content?
 *
 * Slot filling is opt-in per row. Anything that looks like chrome — hero/banner,
 * call-to-action, image or button rows — is excluded, because overwriting it is
 * what scrambled generated posts built from content-filled templates (NOVA-268).
 * When in doubt the row is ineligible: the section then appends cleanly instead of
 * destroying template copy.
 */
function nova_wpb_row_is_slot_eligible( $row ) {
	if ( ! is_array( $row ) ) {
		return false;
	}
	if ( empty( $row['tag'] ) || 'vc_row' !== (string) $row['tag'] ) {
		return false;
	}

	if ( nova_wpb_is_banner_row( $row ) ) {
		return false;
	}
	if ( nova_wpb_is_call_to_action_row( $row ) ) {
		return false;
	}
	if ( nova_wpb_is_secondary_cta_row( $row ) ) {
		return false;
	}

	$excluded = array( 'vc_single_image', 'button', 'vc_btn', 'vc_btn2', 'vc_cta', 'info_apps2', 'ot_faqs' );
	foreach ( $excluded as $tag ) {
		if ( nova_wpb_node_has_tag( $row, $tag ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Path helpers. Paths are dot-separated child indices into the compact tree,
 * the same scheme layout.outline exposes.
 */
function nova_wpb_path_parent( $path ) {
	$path = (string) $path;
	$pos  = strrpos( $path, '.' );
	return ( false === $pos ) ? '' : substr( $path, 0, $pos );
}

function nova_wpb_path_index( $path ) {
	$path = (string) $path;
	$pos  = strrpos( $path, '.' );
	return ( false === $pos ) ? (int) $path : (int) substr( $path, $pos + 1 );
}

function nova_wpb_compare_paths( $a, $b ) {
	$pa = array_map( 'intval', explode( '.', (string) $a ) );
	$pb = array_map( 'intval', explode( '.', (string) $b ) );
	$n  = min( count( $pa ), count( $pb ) );

	for ( $i = 0; $i < $n; $i++ ) {
		if ( $pa[ $i ] !== $pb[ $i ] ) {
			return ( $pa[ $i ] < $pb[ $i ] ) ? -1 : 1;
		}
	}

	if ( count( $pa ) === count( $pb ) ) {
		return 0;
	}
	return ( count( $pa ) < count( $pb ) ) ? -1 : 1;
}

/**
 * Run $callback( &$node ) against the node at $path. Returns false if absent.
 */
function nova_wpb_walk_to_path( &$nodes, $path, $callback ) {
	$parts = explode( '.', (string) $path );
	$last  = count( $parts ) - 1;
	$cur   = &$nodes;

	foreach ( $parts as $i => $raw ) {
		$idx = (int) $raw;

		if ( ! isset( $cur[ $idx ] ) || ! is_array( $cur[ $idx ] ) ) {
			return false;
		}
		if ( $i === $last ) {
			$callback( $cur[ $idx ] );
			return true;
		}
		if ( ! isset( $cur[ $idx ]['children'] ) || ! is_array( $cur[ $idx ]['children'] ) ) {
			return false;
		}

		$cur = &$cur[ $idx ]['children'];
	}

	return false;
}

/**
 * Insert $new_node as child #$index of the node at $parent_path.
 */
function nova_wpb_insert_child_at( &$nodes, $parent_path, $index, $new_node ) {
	if ( '' === (string) $parent_path ) {
		$i = max( 0, min( (int) $index, count( $nodes ) ) );
		array_splice( $nodes, $i, 0, array( $new_node ) );
		return true;
	}

	return nova_wpb_walk_to_path(
		$nodes,
		$parent_path,
		function ( &$parent ) use ( $index, $new_node ) {
			if ( ! isset( $parent['children'] ) || ! is_array( $parent['children'] ) ) {
				$parent['children'] = array();
			}
			$i = max( 0, min( (int) $index, count( $parent['children'] ) ) );
			array_splice( $parent['children'], $i, 0, array( $new_node ) );

			// A container that now holds children can never be self-closing.
			$parent['self_closing'] = false;
		}
	);
}

/**
 * Nodes used when a slot is missing its heading or its body container.
 */
function nova_wpb_make_heading_node( $title, $title_tag ) {
	return array(
		'tag'          => 'vc_custom_heading',
		'attributes'   => array(
			'text'            => wp_strip_all_tags( (string) $title ),
			'use_theme_fonts' => 'yes',
			'font_container'  => 'tag:' . $title_tag,
		),
		'text'         => '',
		'self_closing' => true,
		'children'     => array(),
		'__nova_keep'  => true,
	);
}

function nova_wpb_make_text_node( $body ) {
	return array(
		'tag'          => 'vc_column_text',
		'attributes'   => array(),
		'text'         => (string) $body,
		'self_closing' => false,
		'children'     => array(),
		'__nova_keep'  => true,
	);
}

/**
 * Write a section title into an existing heading node, in place.
 */
function nova_wpb_write_heading_node( &$node, $title, $title_tag ) {
	$tag = isset( $node['tag'] ) ? (string) $node['tag'] : '';

	if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
		$node['attributes'] = array();
	}

	if ( 'vc_custom_heading' === $tag ) {
		$node['attributes']['text']            = wp_strip_all_tags( (string) $title );
		$node['attributes']['use_theme_fonts'] = isset( $node['attributes']['use_theme_fonts'] ) ? $node['attributes']['use_theme_fonts'] : 'yes';
		$node['attributes']['font_container']  = 'tag:' . $title_tag;
		$node['text']                          = '';
	} else {
		$node['attributes']['text'] = wp_strip_all_tags( (string) $title );
		if ( array_key_exists( 'tag', $node['attributes'] ) ) {
			$node['attributes']['tag'] = $title_tag;
		}
	}

	$node['__nova_keep'] = true;
}

/**
 * Strip attributes that must not be duplicated when a row is cloned as a shell.
 */
function nova_wpb_sanitize_shell_attrs( $attrs ) {
	if ( ! is_array( $attrs ) ) {
		return array();
	}

	$out = array();
	foreach ( $attrs as $key => $value ) {
		$key = (string) $key;

		// Cloning a DOM id would produce duplicate ids / broken anchor links.
		if ( 'id' === $key || 'el_id' === $key || preg_match( '/_id$/', $key ) ) {
			continue;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			continue;
		}

		$out[ $key ] = (string) $value;
	}

	return $out;
}

/**
 * Capture row + first-column attributes so overflow sections can reuse the
 * styling of a slot that was actually filled.
 */
function nova_wpb_capture_row_shell( $compact, $row_index ) {
	if ( ! isset( $compact[ $row_index ] ) || ! is_array( $compact[ $row_index ] ) ) {
		return null;
	}

	$row = $compact[ $row_index ];
	$col = nova_wpb_node_collect_first( $row, 'vc_column' );

	return array(
		'row'    => nova_wpb_sanitize_shell_attrs( isset( $row['attributes'] ) ? $row['attributes'] : array() ),
		'column' => nova_wpb_sanitize_shell_attrs( ( is_array( $col ) && isset( $col['attributes'] ) ) ? $col['attributes'] : array() ),
	);
}

/**
 * Enumerate fillable slots, scoped per column.
 *
 * A slot is one (heading, text) pair inside a single column. Pairing state resets
 * at every column boundary, so a heading can never claim a text block belonging to
 * another column or row — that cross-container pairing was the NOVA-268 desync.
 * Either half may be null; the caller injects the missing node.
 */
function nova_wpb_collect_slot_candidates( $compact, &$eligible_rows = null, &$ineligible_rows = null ) {
	$slots      = array();
	$eligible   = array();
	$ineligible = 0;

	foreach ( (array) $compact as $ri => $row ) {
		if ( ! nova_wpb_row_is_slot_eligible( $row ) ) {
			if ( is_array( $row ) && ! empty( $row['tag'] ) && 'vc_row' === (string) $row['tag'] ) {
				$ineligible++;
			}
			continue;
		}

		$eligible[] = (int) $ri;

		// Collect contentish nodes in document order, tagged with their innermost column.
		$items = array();
		$walk  = function ( $node, $path, $column_path ) use ( &$walk, &$items ) {
			if ( empty( $node['children'] ) || ! is_array( $node['children'] ) ) {
				return;
			}

			foreach ( $node['children'] as $i => $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}

				$cpath = $path . '.' . $i;
				$ctag  = isset( $child['tag'] ) ? (string) $child['tag'] : '';
				$scope = $column_path;

				if ( 'vc_column' === $ctag || 'vc_column_inner' === $ctag ) {
					$scope = $cpath;
				} elseif ( 'heading' === $ctag || 'vc_custom_heading' === $ctag ) {
					$items[] = array(
						'path'   => $cpath,
						'kind'   => 'heading',
						'column' => $column_path,
					);
				} elseif ( 'vc_column_text' === $ctag ) {
					$items[] = array(
						'path'   => $cpath,
						'kind'   => 'text',
						'column' => $column_path,
					);
				}

				$walk( $child, $cpath, $scope );
			}
		};
		$walk( $row, (string) $ri, '' );

		$pending = null;
		$current = null;

		foreach ( $items as $item ) {
			// A contentish node outside any column cannot host an injected sibling.
			if ( '' === $item['column'] ) {
				continue;
			}

			if ( $current !== $item['column'] ) {
				if ( null !== $pending ) {
					$slots[] = array(
						'row'     => (int) $ri,
						'column'  => $current,
						'heading' => $pending,
						'text'    => null,
					);
				}
				$pending = null;
				$current = $item['column'];
			}

			if ( 'heading' === $item['kind'] ) {
				if ( null !== $pending ) {
					$slots[] = array(
						'row'     => (int) $ri,
						'column'  => $current,
						'heading' => $pending,
						'text'    => null,
					);
				}
				$pending = $item['path'];
			} else {
				$slots[] = array(
					'row'     => (int) $ri,
					'column'  => $current,
					'heading' => $pending,
					'text'    => $item['path'],
				);
				$pending = null;
			}
		}

		if ( null !== $pending ) {
			$slots[] = array(
				'row'     => (int) $ri,
				'column'  => $current,
				'heading' => $pending,
				'text'    => null,
			);
		}
	}

	$eligible_rows   = $eligible;
	$ineligible_rows = $ineligible;

	return $slots;
}

/**
 * Replace template slots with sections while preserving template layout.
 *
 * $report (by reference, optional) receives per-run diagnostics; see
 * nova_wpb_collect_slot_candidates() for the slot model.
 */
function nova_wpb_replace_template_slots_with_sections( $shortcodes, $sections, $page_title = '', $clear_remaining = true, &$report = null ) {
	$shortcodes = (string) $shortcodes;
	$sections   = is_array( $sections ) ? array_values( $sections ) : array();

	$report = array(
		'slots_found'          => 0,
		'slots_filled'         => 0,
		'sections_total'       => count( $sections ),
		'sections_appended'    => count( $sections ),
		'headings_injected'    => 0,
		'text_blocks_injected' => 0,
		'rows_eligible'        => 0,
		'rows_ineligible'      => 0,
		'shell'                => null,
	);

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

	$eligible_rows   = array();
	$ineligible_rows = 0;
	$slots           = nova_wpb_collect_slot_candidates( $compact, $eligible_rows, $ineligible_rows );

	$report['slots_found']     = count( $slots );
	$report['rows_eligible']   = count( $eligible_rows );
	$report['rows_ineligible'] = (int) $ineligible_rows;

	$section_i  = 0;
	$injections = array();
	$total      = count( $sections );

	foreach ( $slots as $slot_index => $slot ) {
		if ( $section_i >= $total ) {
			break;
		}

		$sec_title = $sections[ $section_i ]['title'];
		$sec_body  = $sections[ $section_i ]['body'];
		$sec_tag   = $sections[ $section_i ]['title_tag'];

		/*
		 * Suppress a duplicate heading only on the very first slot, and only when the
		 * section title repeats the page H1 the theme already renders. Keying this on
		 * "first heading seen anywhere" used to blank the hero instead (NOVA-268).
		 */
		$set_title      = $sec_title;
		$sec_title_norm = strtolower( trim( $sec_title ) );
		if ( 0 === $slot_index && '' !== $page_title_norm && '' !== $sec_title_norm && $sec_title_norm === $page_title_norm ) {
			$set_title = '';
		}

		// Title.
		if ( null !== $slot['heading'] ) {
			nova_wpb_walk_to_path(
				$compact,
				$slot['heading'],
				function ( &$node ) use ( $set_title, $sec_tag ) {
					nova_wpb_write_heading_node( $node, $set_title, $sec_tag );
				}
			);
		} else {
			// Text block with no heading: put one immediately before it.
			$injections[] = array(
				'parent' => nova_wpb_path_parent( $slot['text'] ),
				'index'  => nova_wpb_path_index( $slot['text'] ),
				'node'   => nova_wpb_make_heading_node( $set_title, $sec_tag ),
				'kind'   => 'heading',
			);
		}

		// Body.
		if ( null !== $slot['text'] ) {
			nova_wpb_walk_to_path(
				$compact,
				$slot['text'],
				function ( &$node ) use ( $sec_body ) {
					$node['children']    = array();
					$node['text']        = $sec_body;
					$node['__nova_keep'] = true;
				}
			);
		} else {
			// Heading with no text block: put one immediately after it, same column.
			$injections[] = array(
				'parent' => nova_wpb_path_parent( $slot['heading'] ),
				'index'  => nova_wpb_path_index( $slot['heading'] ) + 1,
				'node'   => nova_wpb_make_text_node( $sec_body ),
				'kind'   => 'text',
			);
		}

		$shell = nova_wpb_capture_row_shell( $compact, $slot['row'] );
		if ( null !== $shell ) {
			$report['shell'] = $shell;
		}

		$report['slots_filled']++;
		$section_i++;
	}

	/*
	 * Injections run after every in-place write, in descending document order, so an
	 * earlier splice can never shift a path that has not been resolved yet.
	 */
	usort(
		$injections,
		function ( $a, $b ) {
			return -nova_wpb_compare_paths( $a['parent'] . '.' . $a['index'], $b['parent'] . '.' . $b['index'] );
		}
	);

	foreach ( $injections as $injection ) {
		if ( ! nova_wpb_insert_child_at( $compact, $injection['parent'], $injection['index'], $injection['node'] ) ) {
			continue;
		}
		if ( 'heading' === $injection['kind'] ) {
			$report['headings_injected']++;
		} else {
			$report['text_blocks_injected']++;
		}
	}

	if ( $clear_remaining ) {
		$walk_clear = function ( &$nodes ) use ( &$walk_clear ) {
			foreach ( $nodes as &$node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}

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
			unset( $node );
		};

		/*
		 * Clearing is scoped to rows that were slot candidates. Walking the whole
		 * document wiped hero/CTA/USP copy on content-filled templates (NOVA-268).
		 */
		foreach ( $eligible_rows as $row_index ) {
			if ( isset( $compact[ $row_index ]['children'] ) && is_array( $compact[ $row_index ]['children'] ) ) {
				$walk_clear( $compact[ $row_index ]['children'] );
			}
		}

		// Prune only eligible rows that ended up empty; never template chrome.
		$kept = array();
		foreach ( $compact as $row_index => $node ) {
			if ( in_array( (int) $row_index, $eligible_rows, true ) && nova_wpb_is_placeholder_slot_row( $node ) ) {
				continue;
			}
			$kept[] = $node;
		}
		$compact = $kept;
	}

	// Normalize before serialize.
	$compact = nova_wpb_normalize_compact_tree( $compact );

	$new_shortcodes = nova_wpb_compact_to_shortcodes( $compact );
	$remaining      = array();

	if ( $section_i < count( $sections ) ) {
		$remaining = array_slice( $sections, $section_i );
	}

	$report['sections_appended'] = count( $remaining );

	return array( $new_shortcodes, $remaining );
}
