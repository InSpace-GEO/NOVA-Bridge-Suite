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
				$node['syntax']       = 'paired';

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
 * Index every current compact-tree path.
 */
function nova_wpb_collect_compact_paths( $compact ) {
	$paths = array();
	$walk  = function ( $nodes, $prefix = '' ) use ( &$walk, &$paths ) {
		foreach ( (array) $nodes as $idx => $node ) {
			$path           = '' === $prefix ? (string) $idx : $prefix . '.' . $idx;
			$paths[ $path ] = true;
			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$walk( $node['children'], $path );
			}
		}
	};

	$walk( $compact );
	return $paths;
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

	if ( function_exists( 'nova_wpb_validate_roundtrip_coverage' ) ) {
		$coverage = nova_wpb_validate_roundtrip_coverage( $shortcodes );
		if ( is_wp_error( $coverage ) ) {
			return $coverage;
		}
	}

	$compact = nova_wpb_parse_shortcodes_to_compact( $shortcodes );
	$paths   = nova_wpb_collect_compact_paths( $compact );
	foreach ( (array) $remove_paths as $remove_path ) {
		$remove_path = (string) $remove_path;
		if ( '' === $remove_path || ! isset( $paths[ $remove_path ] ) ) {
			return new WP_Error(
				'nova_wpb_invalid_path',
				__( 'A requested WPBakery element path does not exist.', 'nova-bridge' ),
				array( 'status' => 400, 'path' => $remove_path )
			);
		}
	}

	if ( ! empty( $text_updates ) ) {
		$compact = nova_wpb_apply_text_updates_to_compact( $compact, $text_updates );
		if ( is_wp_error( $compact ) ) {
			return $compact;
		}
	}
	if ( ! empty( $remove_paths ) ) {
		$compact = nova_wpb_remove_paths_from_compact( $compact, $remove_paths );
	}

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

	$walk = function ( $nodes, $prefix = '', &$orphaned_raw = '' ) use ( &$walk, $paths ) {
		$result      = array();
		$pending_raw = '';

		foreach ( $nodes as $idx => $node ) {
			$path = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;

			if ( in_array( $path, $paths, true ) ) {
				$pending_raw .= isset( $node['raw_before'] ) ? (string) $node['raw_before'] : '';
				$pending_raw .= isset( $node['raw_after'] ) ? (string) $node['raw_after'] : '';
				continue;
			}

			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$child_raw        = '';
				$node['children'] = $walk( $node['children'], $path, $child_raw );
				if ( empty( $node['children'] ) && '' !== $child_raw ) {
					$node['text'] = ( isset( $node['text'] ) ? (string) $node['text'] : '' ) . $child_raw;
				}
			}

			if ( '' !== $pending_raw ) {
				$node['raw_before'] = $pending_raw . ( isset( $node['raw_before'] ) ? (string) $node['raw_before'] : '' );
				$pending_raw        = '';
			}

			$result[] = $node;
		}

		if ( '' !== $pending_raw ) {
			if ( ! empty( $result ) ) {
				$last_index = count( $result ) - 1;
				$result[ $last_index ]['raw_after'] = ( isset( $result[ $last_index ]['raw_after'] ) ? (string) $result[ $last_index ]['raw_after'] : '' ) . $pending_raw;
			} else {
				$orphaned_raw .= $pending_raw;
			}
		}

		return $result;
	};

	$orphaned_raw = '';
	return $walk( $compact, '', $orphaned_raw );
}

/**
 * Sanitize one field update. Returns null through $valid for rejected URLs.
 */
function nova_wpb_sanitize_editable_field_value( $field, $value, &$valid ) {
	$field  = sanitize_key( (string) $field );
	$value  = (string) $value;
	$format = function_exists( 'nova_wpb_field_format' ) ? nova_wpb_field_format( $field ) : 'text';
	$valid  = true;

	if ( 'html' === $format ) {
		return wp_kses_post( $value );
	}

	if ( 'url' === $format || 'image' === $format ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( 'image' === $format && preg_match( '/^\d+(?:,\d+)*$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			$valid = false;
			return null;
		}

		$protocol_safe = wp_kses_bad_protocol( $value, array( 'http', 'https', 'mailto', 'tel' ) );
		if ( $protocol_safe !== $value ) {
			$valid = false;
			return null;
		}

		// Relative URLs and fragments must not be turned into absolute URLs.
		if ( ! preg_match( '/^[a-z][a-z0-9+.-]*:/i', $value ) ) {
			return $value;
		}

		$value = esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
		if ( '' === $value ) {
			$valid = false;
			return null;
		}

		return $value;
	}

	return wp_strip_all_tags( $value );
}

/**
 * Apply field-qualified text_updates to a compact tree via path.
 *
 * Legacy {path,text} updates continue to target each tag's primary text field.
 */
function nova_wpb_apply_text_updates_to_compact( $compact, $updates ) {
	$map = array();

	foreach ( (array) $updates as $update ) {
		if ( ! is_array( $update ) || ! isset( $update['path'] ) || '' === (string) $update['path'] ) {
			return new WP_Error(
				'nova_wpb_invalid_text_update',
				__( 'Every WPBakery text update requires a path.', 'nova-bridge' ),
				array( 'status' => 400 )
			);
		}

		$path = (string) $update['path'];
		if ( ! isset( $map[ $path ] ) ) {
			$map[ $path ] = array();
		}
		$map[ $path ][] = array(
			'field'         => isset( $update['field'] ) ? (string) $update['field'] : '',
			'field_present' => isset( $update['field'] ),
			'text'          => isset( $update['text'] ) ? (string) $update['text'] : '',
		);
	}

	$error = null;
	$seen  = array();
	$walk  = function ( $nodes, $prefix = '' ) use ( &$walk, $map, &$error, &$seen ) {
		foreach ( $nodes as $idx => &$node ) {
			if ( is_wp_error( $error ) ) {
				break;
			}
			$path = ( '' === $prefix ) ? (string) $idx : $prefix . '.' . $idx;

			if ( isset( $map[ $path ] ) ) {
				$seen[ $path ] = true;
				$tag       = isset( $node['tag'] ) ? (string) $node['tag'] : '';
				$available = function_exists( 'nova_wpb_fields_for_node' ) ? nova_wpb_fields_for_node( $node ) : array();

				foreach ( $map[ $path ] as $update ) {
					$field = sanitize_key( $update['field'] );
					if ( $update['field_present'] && strtolower( trim( $update['field'] ) ) !== $field ) {
						$error = new WP_Error(
							'nova_wpb_unsupported_field',
							__( 'A requested WPBakery field is not editable.', 'nova-bridge' ),
							array( 'status' => 400, 'path' => $path, 'field' => $update['field'] )
						);
						break;
					}
					if ( '' === $field && function_exists( 'nova_wpb_default_text_field_for_tag' ) ) {
						$field = nova_wpb_default_text_field_for_tag( $tag, $node );
					}
					if ( 'content' === $field ) {
						$field = 'body';
					}

					if ( null === $field || '' === $field || ! isset( $available[ $field ] ) || empty( $available[ $field ]['editable'] ) ) {
						$error = new WP_Error(
							'nova_wpb_unsupported_field',
							__( 'A requested WPBakery field is not editable.', 'nova-bridge' ),
							array( 'status' => 400, 'path' => $path, 'field' => (string) $field )
						);
						break;
					}

					$valid = false;
					$value = nova_wpb_sanitize_editable_field_value( $field, $update['text'], $valid );
					if ( ! $valid ) {
						$error = new WP_Error(
							'nova_wpb_invalid_field_value',
							__( 'A requested WPBakery field value is unsafe.', 'nova-bridge' ),
							array( 'status' => 400, 'path' => $path, 'field' => $field )
						);
						break;
					}

					if ( 'body' === $field ) {
						$has_children = ! empty( $node['children'] ) && is_array( $node['children'] );
						if ( ! $has_children && 'vc_empty_space' !== $tag ) {
							$node['text'] = $value;
						}
						continue;
					}

					if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
						$node['attributes'] = array();
					}
					if (
						'link_url' === $field
						&& ! array_key_exists( 'link_url', $node['attributes'] )
						&& array_key_exists( 'link', $node['attributes'] )
					) {
						nova_wpb_packed_link_url( $node['attributes']['link'], $has_packed_url );
						if ( $has_packed_url ) {
							$encoded_url = rawurlencode( $value );
							$node['attributes']['link'] = preg_replace_callback(
								'/(^|\|)url:[^|]*/',
								function ( $match ) use ( $encoded_url ) {
									return $match[1] . 'url:' . $encoded_url;
								},
								(string) $node['attributes']['link'],
								1
							);
							continue;
						}
					}

					$node['attributes'][ $field ] = $value;
				}
			}

			if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
				$node['children'] = $walk( $node['children'], $path );
			}
		}

		return $nodes;
	};

	$compact = $walk( $compact );
	if ( is_wp_error( $error ) ) {
		return $error;
	}
	foreach ( array_keys( $map ) as $path ) {
		if ( ! isset( $seen[ $path ] ) ) {
			return new WP_Error(
				'nova_wpb_invalid_path',
				__( 'A requested WPBakery element path does not exist.', 'nova-bridge' ),
				array( 'status' => 400, 'path' => $path )
			);
		}
	}

	return $compact;
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
		'text_content',
		'title',
		'desc',
		'description',
		'btntext',
		'button_text',
		'link_text',
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
 * Decide whether a node can host generated content, and which of its fields carries it.
 *
 * Driven by the same field metadata the outline is built from, rather than a fixed tag
 * list. A hardcoded list of vc_custom_heading/heading/vc_column_text finds nothing on a
 * theme that ships its own text elements — Salient writes headings as split_line_heading
 * and body copy as nectar_responsive_text — so every section overflowed and appended
 * below the template's own copy, which is what "duplicate content from the copied page"
 * actually was.
 *
 * Returns array( kind, field ) with kind 'heading'|'text', or null when the node must
 * not be written to.
 */
function nova_wpb_slot_carrier_for_node( $node ) {
	if ( ! is_array( $node ) || empty( $node['tag'] ) ) {
		return null;
	}
	if ( ! function_exists( 'nova_wpb_fields_for_node' ) || ! function_exists( 'nova_wpb_field_format' ) ) {
		return null;
	}

	$tag = (string) $node['tag'];

	/*
	 * Structured elements own their own content model. FAQ/accordion carriers are filled
	 * by the FAQ path, and layout containers are never content.
	 */
	$never = array(
		'vc_row',
		'vc_row_inner',
		'vc_column',
		'vc_column_inner',
		'vc_empty_space',
		'vc_separator',
		'toggle',
		'vc_toggle',
		'toggles',
		'ot_faqs',
		'info_apps2',
	);
	if ( in_array( $tag, $never, true ) ) {
		return null;
	}

	$fields = nova_wpb_fields_for_node( $node );
	if ( empty( $fields ) ) {
		return null;
	}

	/*
	 * A text carrier that also carries a link is a button or CTA, not a heading.
	 * Writing a section title into it would silently retarget a conversion element.
	 */
	foreach ( $fields as $meta ) {
		if ( isset( $meta['format'] ) && 'url' === $meta['format'] ) {
			return null;
		}
	}

	$field = nova_wpb_default_text_field_for_tag( $tag, $node );
	if ( null === $field || '' === $field || ! isset( $fields[ $field ] ) ) {
		return null;
	}
	if ( empty( $fields[ $field ]['editable'] ) ) {
		return null;
	}

	$format = nova_wpb_field_format( $field );
	if ( 'html' === $format ) {
		return array( 'kind' => 'text', 'field' => $field );
	}
	if ( 'text' === $format ) {
		return array( 'kind' => 'heading', 'field' => $field );
	}

	// url/image carriers are never content slots.
	return null;
}

/**
 * A row carrying the page's H1 is the hero, whatever the theme calls its elements.
 * Filling it overwrites the page title area, which is one of the ways NOVA-268 showed up.
 */
function nova_wpb_row_contains_h1( $node ) {
	if ( ! is_array( $node ) ) {
		return false;
	}

	$attributes = isset( $node['attributes'] ) && is_array( $node['attributes'] ) ? $node['attributes'] : array();

	if ( isset( $attributes['font_container'] ) && false !== stripos( (string) $attributes['font_container'], 'tag:h1' ) ) {
		return true;
	}
	if ( isset( $attributes['tag'] ) && 'h1' === strtolower( trim( (string) $attributes['tag'] ) ) ) {
		return true;
	}
	if ( isset( $node['text'] ) && false !== stripos( (string) $node['text'], '<h1' ) ) {
		return true;
	}

	if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
		foreach ( $node['children'] as $child ) {
			if ( nova_wpb_row_contains_h1( $child ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Slot filling is opt-in per row. Hero/banner and call-to-action rows are excluded,
 * because overwriting them is what scrambled generated posts built from content-filled
 * templates (NOVA-268).
 *
 * Individual buttons, images and link carriers no longer need a blanket row exclusion:
 * nova_wpb_slot_carrier_for_node() refuses to treat them as slots and the clearing pass
 * uses the same test, so they survive inside an otherwise fillable row. Excluding a whole
 * row because it contains one button is what left real content rows unfilled, and an
 * unfilled row means the template's own copy stays on the page with ours appended below
 * it — the duplicate content this fix exists to remove.
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
	if ( nova_wpb_row_contains_h1( $row ) ) {
		return false;
	}

	$excluded = array( 'info_apps2', 'ot_faqs' );
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
 * Write a section title into an existing heading node, in place, using the field that
 * node actually carries its text in (text, text_content, title, ...).
 */
function nova_wpb_write_heading_node( &$node, $title, $title_tag, $field = 'text' ) {
	$tag   = isset( $node['tag'] ) ? (string) $node['tag'] : '';
	$field = ( '' === (string) $field ) ? 'text' : (string) $field;

	if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
		$node['attributes'] = array();
	}

	$node['attributes'][ $field ] = wp_strip_all_tags( (string) $title );

	if ( 'vc_custom_heading' === $tag ) {
		$node['attributes']['use_theme_fonts'] = isset( $node['attributes']['use_theme_fonts'] ) ? $node['attributes']['use_theme_fonts'] : 'yes';
		$node['attributes']['font_container']  = 'tag:' . $title_tag;
		$node['text']                          = '';
	} elseif ( array_key_exists( 'tag', $node['attributes'] ) ) {
		$node['attributes']['tag'] = $title_tag;
	}

	$node['__nova_keep'] = true;
}

/**
 * Write a section body into an existing text node, in place.
 */
function nova_wpb_write_text_node( &$node, $body, $field = 'body' ) {
	if ( 'body' === (string) $field || '' === (string) $field ) {
		$node['children'] = array();
		$node['text']     = (string) $body;
	} else {
		if ( ! isset( $node['attributes'] ) || ! is_array( $node['attributes'] ) ) {
			$node['attributes'] = array();
		}
		$node['attributes'][ (string) $field ] = (string) $body;
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
 * A slot is one (heading, text) pair inside a single column. Pairing state resets at
 * every column boundary, so a heading can never claim a text block belonging to another
 * column or row — that cross-container pairing was the NOVA-268 desync. Either half may
 * be null; the caller injects the missing node.
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
				} else {
					$carrier = nova_wpb_slot_carrier_for_node( $child );
					if ( null !== $carrier ) {
						$items[] = array(
							'path'   => $cpath,
							'kind'   => $carrier['kind'],
							'field'  => $carrier['field'],
							'column' => $column_path,
						);
					}
				}

				$walk( $child, $cpath, $scope );
			}
		};
		$walk( $row, (string) $ri, '' );

		$pending       = null;
		$pending_field = 'text';
		$current       = null;

		foreach ( $items as $item ) {
			// A contentish node outside any column cannot host an injected sibling.
			if ( '' === $item['column'] ) {
				continue;
			}

			if ( $current !== $item['column'] ) {
				if ( null !== $pending ) {
					$slots[] = array(
						'row'           => (int) $ri,
						'column'        => $current,
						'heading'       => $pending,
						'heading_field' => $pending_field,
						'text'          => null,
						'text_field'    => 'body',
					);
				}
				$pending = null;
				$current = $item['column'];
			}

			if ( 'heading' === $item['kind'] ) {
				if ( null !== $pending ) {
					$slots[] = array(
						'row'           => (int) $ri,
						'column'        => $current,
						'heading'       => $pending,
						'heading_field' => $pending_field,
						'text'          => null,
						'text_field'    => 'body',
					);
				}
				$pending       = $item['path'];
				$pending_field = $item['field'];
			} else {
				$slots[] = array(
					'row'           => (int) $ri,
					'column'        => $current,
					'heading'       => $pending,
					'heading_field' => $pending_field,
					'text'          => $item['path'],
					'text_field'    => $item['field'],
				);
				$pending       = null;
				$pending_field = 'text';
			}
		}

		if ( null !== $pending ) {
			$slots[] = array(
				'row'           => (int) $ri,
				'column'        => $current,
				'heading'       => $pending,
				'heading_field' => $pending_field,
				'text'          => null,
				'text_field'    => 'body',
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
		'skipped'              => '',
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
		$report['skipped'] = 'parser_unavailable';
		return array( $shortcodes, $sections );
	}
	/*
	 * Fail safe: a document we cannot re-serialize losslessly is left alone and every
	 * section appends instead. That is deliberate, but it is indistinguishable from a
	 * successful post on the public page, so it is reported rather than silent.
	 */
	if (
		function_exists( 'nova_wpb_validate_roundtrip_coverage' )
		&& is_wp_error( nova_wpb_validate_roundtrip_coverage( $shortcodes ) )
	) {
		$report['skipped'] = 'unsafe_roundtrip';
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
			$heading_field = isset( $slot['heading_field'] ) ? $slot['heading_field'] : 'text';
			nova_wpb_walk_to_path(
				$compact,
				$slot['heading'],
				function ( &$node ) use ( $set_title, $sec_tag, $heading_field ) {
					nova_wpb_write_heading_node( $node, $set_title, $sec_tag, $heading_field );
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
			$text_field = isset( $slot['text_field'] ) ? $slot['text_field'] : 'body';
			nova_wpb_walk_to_path(
				$compact,
				$slot['text'],
				function ( &$node ) use ( $sec_body, $text_field ) {
					nova_wpb_write_text_node( $node, $sec_body, $text_field );
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

				/*
				 * Cleared using the same test that decides what may be filled, so a
				 * theme's own text elements are cleared too. Anything that is not a
				 * slot — buttons, images, CTAs — keeps its copy.
				 */
				if ( empty( $node['__nova_keep'] ) && null !== nova_wpb_slot_carrier_for_node( $node ) ) {
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
