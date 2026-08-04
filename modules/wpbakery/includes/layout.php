<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure vc_* + theme shortcodes exist in $shortcode_tags so get_shortcode_regex()
 * can see them, even if WPBakery hasn't fully bootstrapped in this request.
 */
if ( ! function_exists( 'nova_wpb_ensure_vc_shortcodes_for_regex' ) ) {
	function nova_wpb_ensure_vc_shortcodes_for_regex() {
		global $shortcode_tags;

		if ( ! is_array( $shortcode_tags ) ) {
			$shortcode_tags = array();
		}

		// WPBakery + common theme shortcodes found in templates.
		$vc_tags = array(
			'vc_row',
			'vc_row_inner',
			'vc_column',
			'vc_column_inner',
			'vc_column_text',
			'vc_custom_heading',
			'vc_single_image',
			'vc_empty_space',
			'vc_btn',
			'vc_btn2',
			'vc_cta',
			'vc_message',
			'vc_toggle',

			// Theme / custom (seen in your content/templates)
			'heading',
			'button',
			'line_solid',
			'info_apps2',
			'ot_faqs',
		);

		foreach ( $vc_tags as $tag ) {
			if ( ! isset( $shortcode_tags[ $tag ] ) ) {
				$shortcode_tags[ $tag ] = '__return_empty_string';
			}
		}
	}
}

/**
 * Discover shortcode tag names from the document itself.
 *
 * WP's shortcode regex accepts an explicit tag list, so page-builder and
 * theme elements do not need to be registered during a REST request. Escaped
 * forms such as [[tag]] are intentionally excluded.
 */
if ( ! function_exists( 'nova_wpb_discover_shortcode_tags' ) ) {
	function nova_wpb_discover_shortcode_tags( $content ) {
		$content = (string) $content;
		if ( '' === $content ) {
			return array();
		}

		if ( ! preg_match_all( '/(?<!\[)\[([^\x00-\x20<>&\/\[\]=]+)(?=[\x00-\x20\/\]])/', $content, $matches ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'strval', $matches[1] ) ) );
	}
}

/**
 * Count raw opening shortcode tags for fail-closed parser coverage checks.
 */
if ( ! function_exists( 'nova_wpb_count_shortcode_tags_in_content' ) ) {
	function nova_wpb_count_shortcode_tags_in_content( $content ) {
		$content = (string) $content;
		$counts  = array();

		if ( preg_match_all( '/(?<!\[)\[([^\x00-\x20<>&\/\[\]=]+)(?=[\x00-\x20\/\]])/', $content, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				$tag = (string) $tag;
				$counts[ $tag ] = isset( $counts[ $tag ] ) ? $counts[ $tag ] + 1 : 1;
			}
		}

		ksort( $counts );
		return $counts;
	}
}

/**
 * Tags that should be serialized as self-closing if they have no inner content.
 * (WPBakery is tolerant, but this avoids accidental container behavior.)
 */
if ( ! function_exists( 'nova_wpb_is_known_self_closing_tag' ) ) {
	function nova_wpb_is_known_self_closing_tag( $tag ) {
		$tag = (string) $tag;
		return in_array(
			$tag,
			array(
				'vc_empty_space',
				'vc_single_image',
				'vc_custom_heading',
				'line_solid',
				'info_apps2',
				'heading',
				'button',
			),
			true
		);
	}
}

/**
 * Safe fallback: label guesser (prevents fatals if not defined elsewhere).
 */
if ( ! function_exists( 'nova_wpb_guess_label_for_tag' ) ) {
	function nova_wpb_guess_label_for_tag( $tag, $node = array() ) {
		$tag = (string) $tag;
		$map = array(
			'vc_column_text'    => 'Text',
			'vc_custom_heading' => 'Heading',
			'heading'           => 'Heading',
			'button'            => 'Button',
			'vc_single_image'   => 'Image',
			'ot_faqs'           => 'FAQ',
			'info_apps2'        => 'Info Block',
			'vc_empty_space'    => 'Spacer',
			'line_solid'        => 'Divider',
		);
		return isset( $map[ $tag ] ) ? $map[ $tag ] : $tag;
	}
}

/**
 * Recursively parse shortcodes to a compact tree.
 *
 * Raw text between sibling shortcodes is carried on adjacent nodes so parsing
 * and serializing a mixed WPBakery/theme document does not discard content.
 */
if ( ! function_exists( 'nova_wpb_parse_shortcodes_to_compact' ) ) {
	function nova_wpb_parse_shortcodes_to_compact( $content ) {
		$content = (string) $content;
		$nodes   = array();

		if ( '' === $content ) {
			return $nodes;
		}

		$tags = nova_wpb_discover_shortcode_tags( $content );
		if ( empty( $tags ) ) {
			return $nodes;
		}

		$pattern = get_shortcode_regex( $tags );

		if ( ! preg_match_all( '/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $nodes;
		}

		$cursor = 0;

		foreach ( $matches as $m ) {
			// WP core uses:
			// [1] = '[' escape, [2] = tag, [3] = attrs, [4] = selfclosing '/', [5] = inner, [6] = ']' escape
			$full         = isset( $m[0][0] ) ? (string) $m[0][0] : '';
			$match_offset = isset( $m[0][1] ) ? (int) $m[0][1] : $cursor;
			$tag          = isset( $m[2][0] ) ? (string) $m[2][0] : '';
			$atts_str     = isset( $m[3][0] ) ? (string) $m[3][0] : '';
			$inner        = isset( $m[5][0] ) ? (string) $m[5][0] : '';

			// Ignore escaped shortcodes like [[vc_row]] (rare in builder content).
			if ( isset( $m[1][0], $m[6][0] ) && '[' === $m[1][0] && ']' === $m[6][0] ) {
				continue;
			}
			if ( '' === $tag || '' === $full ) {
				continue;
			}

			$attributes = shortcode_parse_atts( $atts_str );
			if ( ! is_array( $attributes ) ) {
				$attributes = array();
			}

			// ✅ Correct self-closing detection: group 4 (NOT group 6).
			$explicit_self_closing = isset( $m[4][0] ) && '/' === $m[4][0];
			$has_closing_tag       = (bool) preg_match( '/\[\/' . preg_quote( $tag, '/' ) . '\]\]?$/', $full );
			$syntax                = $has_closing_tag ? 'paired' : ( $explicit_self_closing ? 'self_closing' : 'standalone' );
			$children              = nova_wpb_parse_shortcodes_to_compact( $inner );
			$raw_before            = substr( $content, $cursor, max( 0, $match_offset - $cursor ) );
			$raw_open              = $full;
			$raw_close             = '';
			if ( $has_closing_tag && preg_match( '/\[\/' . preg_quote( $tag, '/' ) . '\]\]?$/', $full, $closing, PREG_OFFSET_CAPTURE ) ) {
				$raw_close   = (string) $closing[0][0];
				$open_length = (int) $closing[0][1] - strlen( $inner );
				if ( $open_length >= 0 ) {
					$raw_open = substr( $full, 0, $open_length );
				}
			}
			$cursor                = $match_offset + strlen( $full );

			$nodes[] = array(
				'tag'                 => $tag,
				'attributes'          => $attributes,
				'text'                => empty( $children ) ? $inner : '',
				'self_closing'        => $explicit_self_closing,
				'syntax'              => $syntax,
				'children'            => $children,
				'raw_before'          => $raw_before,
				'raw_after'           => '',
				'raw_open'            => $raw_open,
				'raw_close'           => $raw_close,
				'original_tag'        => $tag,
				'original_attributes' => $attributes,
				'original_syntax'     => $syntax,
			);
		}

		if ( ! empty( $nodes ) ) {
			$last_index = count( $nodes ) - 1;
			$nodes[ $last_index ]['raw_after'] = substr( $content, $cursor );
		}

		return $nodes;
	}
}

/**
 * Editable shortcode attributes and their value formats.
 */
if ( ! function_exists( 'nova_wpb_editable_attribute_formats' ) ) {
	function nova_wpb_editable_attribute_formats() {
		return array(
			'text'         => 'text',
			'text_content' => 'text',
			'title'        => 'text',
			'btntext'      => 'text',
			'button_text'  => 'text',
			'link_text'    => 'text',
			'label'        => 'text',
			'heading'      => 'text',
			'subheading'   => 'text',
			'subtitle'     => 'text',
			'desc'         => 'text',
			'description'  => 'text',
			'alt'          => 'text',
			'caption'      => 'text',
			'url'          => 'url',
			'href'         => 'url',
			'link_url'     => 'url',
			'button_url'   => 'url',
			'image_link'   => 'url',
			'image'        => 'image',
			'image_id'     => 'image',
			'image_url'    => 'image',
			'img'          => 'image',
			'src'          => 'image',
			'icon_image'   => 'image',
		);
	}
}

/**
 * Read the URL component from WPBakery's packed link attribute.
 */
if ( ! function_exists( 'nova_wpb_packed_link_url' ) ) {
	function nova_wpb_packed_link_url( $value, &$found = null ) {
		$found = false;
		if ( ! is_scalar( $value ) || ! preg_match( '/(^|\|)url:([^|]*)/', (string) $value, $match ) ) {
			return '';
		}

		$found = true;
		return urldecode( (string) $match[2] );
	}
}

/**
 * Primary editable field used by legacy {path,text} updates.
 */
if ( ! function_exists( 'nova_wpb_default_text_field_for_tag' ) ) {
	function nova_wpb_default_text_field_for_tag( $tag, $node = array() ) {
		$map = array(
			'vc_column_text'       => 'body',
			'vc_cta'               => 'body',
			'vc_message'           => 'body',
			'nectar_responsive_text' => 'body',
			'vc_custom_heading'    => 'text',
			'heading'              => 'text',
			'button'               => 'btntext',
			'vc_btn'               => 'title',
			'vc_btn2'              => 'title',
			'vc_toggle'            => 'title',
			'toggle'               => 'title',
			'ot_faqs'              => 'title',
			'split_line_heading'   => 'text_content',
			'nectar_btn'           => 'text',
			'nectar_cta'           => 'link_text',
			'vc_single_image'      => 'image',
			'image_with_animation' => 'image_url',
		);

		$tag = (string) $tag;
		if ( 'vc_empty_space' === $tag ) {
			return null;
		}
		if ( isset( $map[ $tag ] ) ) {
			return $map[ $tag ];
		}

		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
		$text     = isset( $node['text'] ) ? (string) $node['text'] : '';
		if ( empty( $children ) && '' !== $text ) {
			return 'body';
		}

		$attributes = isset( $node['attributes'] ) && is_array( $node['attributes'] ) ? $node['attributes'] : array();
		foreach ( nova_wpb_editable_attribute_formats() as $field => $format ) {
			if ( array_key_exists( $field, $attributes ) ) {
				return $field;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'nova_wpb_is_editable_field' ) ) {
	function nova_wpb_is_editable_field( $field ) {
		$field = sanitize_key( (string) $field );
		return in_array( $field, array( 'body', 'content' ), true )
			|| array_key_exists( $field, nova_wpb_editable_attribute_formats() );
	}
}

if ( ! function_exists( 'nova_wpb_field_format' ) ) {
	function nova_wpb_field_format( $field ) {
		$field = sanitize_key( (string) $field );
		if ( in_array( $field, array( 'body', 'content' ), true ) ) {
			return 'html';
		}

		$formats = nova_wpb_editable_attribute_formats();
		return isset( $formats[ $field ] ) ? $formats[ $field ] : 'text';
	}
}

/**
 * All editable text/link/image carriers for one compact node.
 */
if ( ! function_exists( 'nova_wpb_fields_for_node' ) ) {
	function nova_wpb_fields_for_node( $node ) {
		if ( ! is_array( $node ) ) {
			return array();
		}

		$tag        = isset( $node['tag'] ) ? (string) $node['tag'] : '';
		$attributes = isset( $node['attributes'] ) && is_array( $node['attributes'] ) ? $node['attributes'] : array();
		$children   = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
		$default    = nova_wpb_default_text_field_for_tag( $tag, $node );
		$fields     = array();
		if ( 'vc_empty_space' === $tag ) {
			return $fields;
		}

		$text = isset( $node['text'] ) ? (string) $node['text'] : '';
		if ( empty( $children ) && ( '' !== $text || 'body' === $default ) ) {
			$fields['body'] = array(
				'value'    => $text,
				'format'   => 'html',
				'editable' => true,
			);
		}

		foreach ( nova_wpb_editable_attribute_formats() as $field => $format ) {
			if ( ! array_key_exists( $field, $attributes ) && $field !== $default ) {
				continue;
			}

			$value = array_key_exists( $field, $attributes ) ? $attributes[ $field ] : '';
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			}

			$fields[ $field ] = array(
				'value'    => (string) $value,
				'format'   => $format,
				'editable' => true,
			);
		}

		if ( ! isset( $fields['link_url'] ) && ! array_key_exists( 'link_url', $attributes ) && array_key_exists( 'link', $attributes ) ) {
			$packed_url = nova_wpb_packed_link_url( $attributes['link'], $has_packed_url );
			if ( $has_packed_url ) {
				$fields['link_url'] = array(
					'value'    => $packed_url,
					'format'   => 'url',
					'editable' => true,
				);
			}
		}

		return $fields;
	}
}

/**
 * Build outline from compact tree.
 */
if ( ! function_exists( 'nova_wpb_build_outline_from_compact' ) ) {
	function nova_wpb_build_outline_from_compact( $compact, $tree = false ) {
		$outline = array();
		$path    = array();

		$walk = function ( $nodes, $parent_context = '', $depth = 0 ) use ( &$walk, &$outline, &$path ) {
			foreach ( $nodes as $idx => $node ) {
				$path[ $depth ] = $idx;
				$path_str       = implode( '.', array_slice( $path, 0, $depth + 1 ) );
				$tag            = isset( $node['tag'] ) ? (string) $node['tag'] : '';

				$context = $parent_context;
				if ( 'vc_row' === $tag || 'vc_row_inner' === $tag ) {
					$context = ( $context ? $context . ' > ' : '' ) . 'Row';
				} elseif ( 'vc_column' === $tag || 'vc_column_inner' === $tag ) {
					$context = ( $context ? $context . ' > ' : '' ) . 'Column';
				}
				if ( '' === $context ) {
					$context = 'WPBakery';
				}

				$fields        = nova_wpb_fields_for_node( $node );
				$primary_field = nova_wpb_default_text_field_for_tag( $tag, $node );

				if ( ! empty( $fields ) ) {
					if ( null === $primary_field || ! isset( $fields[ $primary_field ] ) ) {
						$primary_field = (string) array_key_first( $fields );
					}

					$outline[] = array(
						'path'     => $path_str,
						'tag'      => $tag,
						'label'    => nova_wpb_guess_label_for_tag( $tag, $node ),
						'context'  => $context,
						'field'    => $primary_field,
						'text'     => (string) $fields[ $primary_field ]['value'],
						'fields'   => $fields,
						'editable' => true,
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
 * Build a field-qualified editable text/link/image map.
 */
if ( ! function_exists( 'nova_wpb_build_text_map_from_compact' ) ) {
	function nova_wpb_build_text_map_from_compact( $compact ) {
		$outline = nova_wpb_build_outline_from_compact( $compact, false );
		$map     = array();

		foreach ( $outline as $node ) {
			foreach ( $node['fields'] as $field => $details ) {
				$map[] = array(
					'path'     => $node['path'],
					'field'    => $field,
					'text'     => (string) $details['value'],
					'format'   => $details['format'],
					'editable' => ! empty( $details['editable'] ),
				);
			}
		}

		return $map;
	}
}

/**
 * Normalize a shortcode attribute to the scalar form used for comparisons.
 */
if ( ! function_exists( 'nova_wpb_scalar_attribute_value' ) ) {
	function nova_wpb_scalar_attribute_value( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return null === $value ? '' : (string) $value;
	}
}

/**
 * Locate named attribute value spans without normalizing surrounding bytes.
 */
if ( ! function_exists( 'nova_wpb_raw_shortcode_attribute_spans' ) ) {
	function nova_wpb_raw_shortcode_attribute_spans( $raw_open, $tag, $syntax ) {
		$raw_open = (string) $raw_open;
		$tag      = (string) $tag;
		$prefix   = '[' . $tag;

		if ( 0 !== strpos( $raw_open, $prefix ) ) {
			return null;
		}
		$prefix_length = strlen( $prefix );
		$boundary      = isset( $raw_open[ $prefix_length ] ) ? $raw_open[ $prefix_length ] : '';
		if ( '' !== $boundary && ! preg_match( '/[\s\/\]]/', $boundary ) ) {
			return null;
		}

		$end = strrpos( $raw_open, ']' );
		if ( false === $end ) {
			return null;
		}
		if ( 'self_closing' === $syntax && preg_match( '/\/\s*\]$/', $raw_open, $slash, PREG_OFFSET_CAPTURE ) ) {
			$end = (int) $slash[0][1];
		}

		$spans  = array();
		$length = strlen( $raw_open );
		$i      = $prefix_length;
		while ( $i < $end && $i < $length ) {
			while ( $i < $end && preg_match( '/\s/', $raw_open[ $i ] ) ) {
				$i++;
			}
			if ( $i >= $end ) {
				break;
			}

			$key_start = $i;
			while ( $i < $end && preg_match( '/[A-Za-z0-9_-]/', $raw_open[ $i ] ) ) {
				$i++;
			}
			if ( $i === $key_start ) {
				$quote = in_array( $raw_open[ $i ], array( '"', "'" ), true ) ? $raw_open[ $i ] : '';
				$i++;
				while ( $i < $end && ( '' !== $quote ? $raw_open[ $i ] !== $quote : ! preg_match( '/\s/', $raw_open[ $i ] ) ) ) {
					$i++;
				}
				if ( '' !== $quote && $i < $end ) {
					$i++;
				}
				continue;
			}

			$key = substr( $raw_open, $key_start, $i - $key_start );
			while ( $i < $end && preg_match( '/\s/', $raw_open[ $i ] ) ) {
				$i++;
			}
			if ( $i >= $end || '=' !== $raw_open[ $i ] ) {
				while ( $i < $end && ! preg_match( '/\s/', $raw_open[ $i ] ) ) {
					$i++;
				}
				continue;
			}

			$i++;
			while ( $i < $end && preg_match( '/\s/', $raw_open[ $i ] ) ) {
				$i++;
			}
			$quote       = ( $i < $end && in_array( $raw_open[ $i ], array( '"', "'" ), true ) ) ? $raw_open[ $i ] : '';
			$value_start = '' !== $quote ? ++$i : $i;
			while ( $i < $end && ( '' !== $quote ? $raw_open[ $i ] !== $quote : ! preg_match( '/\s/', $raw_open[ $i ] ) ) ) {
				$i++;
			}
			$value_end = $i;
			if ( '' !== $quote && $i < $end ) {
				$i++;
			}

			$spans[ $key ] = array(
				'key_start'   => $key_start,
				'value_start' => $value_start,
				'value_end'   => $value_end,
				'token_end'   => $i,
				'quote'       => $quote,
			);
		}

		return array(
			'attributes_end' => $end,
			'spans'          => $spans,
		);
	}
}

/**
 * Patch only changed attribute tokens in an original shortcode opening tag.
 */
if ( ! function_exists( 'nova_wpb_patch_raw_shortcode_opening' ) ) {
	function nova_wpb_patch_raw_shortcode_opening( $raw_open, $tag, $syntax, $original_attributes, $attributes ) {
		$index = nova_wpb_raw_shortcode_attribute_spans( $raw_open, $tag, $syntax );
		if ( null === $index || ! is_array( $original_attributes ) || ! is_array( $attributes ) ) {
			return null;
		}

		$replacements = array();
		$additions    = array();
		$keys         = array_unique( array_merge( array_keys( $original_attributes ), array_keys( $attributes ) ) );
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
				$had_original = array_key_exists( $key, $original_attributes );
				$has_current  = array_key_exists( $key, $attributes );
				if (
					$had_original !== $has_current
					|| ( $had_original && nova_wpb_scalar_attribute_value( $original_attributes[ $key ] ) !== nova_wpb_scalar_attribute_value( $attributes[ $key ] ) )
				) {
					return null;
				}
				continue;
			}

			$had_original = array_key_exists( $key, $original_attributes );
			$has_current  = array_key_exists( $key, $attributes );
			$old_value    = $had_original ? nova_wpb_scalar_attribute_value( $original_attributes[ $key ] ) : null;
			$new_value    = $has_current ? nova_wpb_scalar_attribute_value( $attributes[ $key ] ) : null;
			if ( $had_original === $has_current && $old_value === $new_value ) {
				continue;
			}

			if ( $had_original ) {
				if ( ! isset( $index['spans'][ $key ] ) ) {
					return null;
				}
				$span = $index['spans'][ $key ];
				if ( ! $has_current ) {
					$replacements[] = array( $span['key_start'], $span['token_end'], '' );
					continue;
				}

				$encoded = esc_attr( $new_value );
				if ( '' === $span['quote'] && ( '' === $encoded || preg_match( '/[\s\'"=<>`\]]/', $encoded ) ) ) {
					$encoded = '"' . $encoded . '"';
				}
				$replacements[] = array( $span['value_start'], $span['value_end'], $encoded );
				continue;
			}

			$additions[] = $key . '="' . esc_attr( $new_value ) . '"';
		}

		if ( ! empty( $additions ) ) {
			$insert_at  = (int) $index['attributes_end'];
			$has_space  = $insert_at > 0 && preg_match( '/\s/', $raw_open[ $insert_at - 1 ] );
			$is_closing = 'self_closing' === $syntax;
			$insert     = ( $has_space ? '' : ' ' ) . implode( ' ', $additions ) . ( $is_closing && $has_space ? ' ' : '' );
			$replacements[] = array( $insert_at, $insert_at, $insert );
		}

		usort(
			$replacements,
			function ( $a, $b ) {
				return $b[0] <=> $a[0];
			}
		);
		foreach ( $replacements as $replacement ) {
			$raw_open = substr_replace( $raw_open, $replacement[2], $replacement[0], $replacement[1] - $replacement[0] );
		}

		return $raw_open;
	}
}

/**
 * Compact tree to shortcode string.
 */
if ( ! function_exists( 'nova_wpb_compact_to_shortcodes' ) ) {
	function nova_wpb_compact_to_shortcodes( $compact ) {
		$build = function ( $nodes ) use ( &$build ) {
			$out = '';

			foreach ( $nodes as $node ) {
				$tag        = isset( $node['tag'] ) ? (string) $node['tag'] : '';
				$attributes = isset( $node['attributes'] ) && is_array( $node['attributes'] ) ? $node['attributes'] : array();
				$children   = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : array();
				$text       = isset( $node['text'] ) ? (string) $node['text'] : '';
				$raw_before = isset( $node['raw_before'] ) ? (string) $node['raw_before'] : '';
				$raw_after  = isset( $node['raw_after'] ) ? (string) $node['raw_after'] : '';

				$out .= $raw_before;
				if ( '' === $tag || ! preg_match( '/^[^\x00-\x20<>&\/\[\]=]+$/', $tag ) ) {
					$out .= $raw_after;
					continue;
				}

				$atts_str = '';
				foreach ( $attributes as $key => $value ) {
					$key = (string) $key;
					if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) {
						continue;
					}
					$value = nova_wpb_scalar_attribute_value( $value );
					$atts_str .= ' ' . $key . '="' . esc_attr( $value ) . '"';
				}

				$inner = '';
				if ( ! empty( $children ) ) {
					$inner .= $build( $children );
				}
				if ( '' !== $text ) {
					$inner .= $text;
				}

				$syntax = isset( $node['syntax'] ) ? (string) $node['syntax'] : '';
				if ( '' !== $inner || 'paired' === $syntax ) {
					$target_syntax = 'paired';
				} elseif ( 'standalone' === $syntax ) {
					$target_syntax = 'standalone';
				} elseif ( 'self_closing' === $syntax || ! empty( $node['self_closing'] ) || nova_wpb_is_known_self_closing_tag( $tag ) ) {
					$target_syntax = 'self_closing';
				} else {
					$target_syntax = 'paired';
				}

				$opening         = null;
				$original_tag    = isset( $node['original_tag'] ) ? (string) $node['original_tag'] : '';
				$original_syntax = isset( $node['original_syntax'] ) ? (string) $node['original_syntax'] : '';
				if (
					$tag === $original_tag
					&& $target_syntax === $original_syntax
					&& isset( $node['raw_open'], $node['original_attributes'] )
				) {
					$opening = nova_wpb_patch_raw_shortcode_opening(
						(string) $node['raw_open'],
						$tag,
						$target_syntax,
						$node['original_attributes'],
						$attributes
					);
				}
				if ( null === $opening ) {
					$opening = '[' . $tag . $atts_str . ( 'self_closing' === $target_syntax ? ' /]' : ']' );
				}

				$out .= $opening;
				if ( 'paired' === $target_syntax ) {
					$out .= $inner;
					if ( $tag === $original_tag && 'paired' === $original_syntax && isset( $node['raw_close'] ) && '' !== (string) $node['raw_close'] ) {
						$out .= (string) $node['raw_close'];
					} else {
						$out .= '[/' . $tag . ']';
					}
				}

				$out .= $raw_after;
			}

			return $out;
		};

		return $build( $compact );
	}
}

/**
 * Count shortcode nodes in a compact tree.
 */
if ( ! function_exists( 'nova_wpb_count_shortcode_tags_in_compact' ) ) {
	function nova_wpb_count_shortcode_tags_in_compact( $compact ) {
		$counts = array();
		$walk   = function ( $nodes ) use ( &$walk, &$counts ) {
			if ( ! is_array( $nodes ) ) {
				return;
			}

			foreach ( $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}

				$tag = isset( $node['tag'] ) ? (string) $node['tag'] : '';
				if ( '' !== $tag ) {
					$counts[ $tag ] = isset( $counts[ $tag ] ) ? $counts[ $tag ] + 1 : 1;
				}

				if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
					$walk( $node['children'] );
				}
			}
		};

		$walk( $compact );
		ksort( $counts );
		return $counts;
	}
}

/**
 * Verify that parsing and serialization retain every shortcode node.
 *
 * Callers should return this WP_Error instead of writing when coverage fails.
 */
if ( ! function_exists( 'nova_wpb_validate_roundtrip_coverage' ) ) {
	function nova_wpb_validate_roundtrip_coverage( $content, $compact = null ) {
		$source_counts = nova_wpb_count_shortcode_tags_in_content( $content );
		if ( null === $compact ) {
			$compact = nova_wpb_parse_shortcodes_to_compact( $content );
		}

		$compact_counts    = nova_wpb_count_shortcode_tags_in_compact( $compact );
		$serialized        = nova_wpb_compact_to_shortcodes( is_array( $compact ) ? $compact : array() );
		$serialized_counts = nova_wpb_count_shortcode_tags_in_content( $serialized );

		if (
			$source_counts === $compact_counts
			&& $source_counts === $serialized_counts
			&& (string) $content === $serialized
		) {
			return true;
		}

		return new WP_Error(
			'nova_wpb_unsafe_roundtrip',
			__( 'The WPBakery document could not be represented byte-for-byte without data loss.', 'nova-bridge' ),
			array(
				'status'     => 422,
				'byte_exact' => (string) $content === $serialized,
				'source'     => $source_counts,
				'parsed'     => $compact_counts,
				'serialized' => $serialized_counts,
			)
		);
	}
}
