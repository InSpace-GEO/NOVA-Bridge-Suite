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
 * NOTE: We intentionally do NOT preserve raw text between sibling shortcodes.
 * For WPBakery layouts, structure is the priority and "real text" typically
 * lives inside vc_column_text / ot_faqs bodies (leaf nodes).
 */
if ( ! function_exists( 'nova_wpb_parse_shortcodes_to_compact' ) ) {
	function nova_wpb_parse_shortcodes_to_compact( $content ) {
		$content = (string) $content;
		$nodes   = array();

		if ( '' === $content ) {
			return $nodes;
		}

		// If there are vc_* or known theme shortcodes, ensure regex sees them.
		if (
			false !== strpos( $content, '[vc_' )
			|| false !== strpos( $content, '[heading' )
			|| false !== strpos( $content, '[button' )
			|| false !== strpos( $content, '[ot_faqs' )
			|| false !== strpos( $content, '[info_apps2' )
			|| false !== strpos( $content, '[line_solid' )
		) {
			nova_wpb_ensure_vc_shortcodes_for_regex();
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

			// Ignore escaped shortcodes like [[vc_row]] (rare in builder content).
			if ( isset( $m[1], $m[6] ) && '[' === $m[1] && ']' === $m[6] ) {
				continue;
			}

			$attributes = shortcode_parse_atts( $atts_str );
			if ( ! is_array( $attributes ) ) {
				$attributes = array();
			}

			// ✅ Correct self-closing detection: group 4 (NOT group 6).
			$self_closing = ( isset( $m[4] ) && '/' === $m[4] );

			$children = nova_wpb_parse_shortcodes_to_compact( $inner );

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

				$is_text_node = in_array(
					$tag,
					array(
						'vc_column_text',
						'vc_custom_heading',
						'vc_btn',
						'vc_btn2',
						'vc_cta',
						'vc_message',
						'vc_toggle',
						'heading',
						'button',
						'ot_faqs',
						'info_apps2',
					),
					true
				);

				if ( $is_text_node ) {
					$text = isset( $node['text'] ) ? (string) $node['text'] : '';

					// vc_custom_heading visible text is in attributes["text"]
					if (
						'vc_custom_heading' === $tag
						&& ! empty( $node['attributes'] )
						&& is_array( $node['attributes'] )
						&& array_key_exists( 'text', $node['attributes'] )
					) {
						$text = (string) $node['attributes']['text'];
					}

					// theme [heading] often uses attributes["text"]
					if (
						'heading' === $tag
						&& ! empty( $node['attributes'] )
						&& is_array( $node['attributes'] )
						&& array_key_exists( 'text', $node['attributes'] )
						&& '' !== (string) $node['attributes']['text']
					) {
						$text = (string) $node['attributes']['text'];
					}

					// theme [button] uses attributes["btntext"]
					if (
						'button' === $tag
						&& ! empty( $node['attributes'] )
						&& is_array( $node['attributes'] )
						&& array_key_exists( 'btntext', $node['attributes'] )
						&& '' !== (string) $node['attributes']['btntext']
					) {
						$text = (string) $node['attributes']['btntext'];
					}

					// FAQ title lives in attributes["title"]
					if (
						'ot_faqs' === $tag
						&& ! empty( $node['attributes'] )
						&& is_array( $node['attributes'] )
						&& array_key_exists( 'title', $node['attributes'] )
						&& '' !== (string) $node['attributes']['title']
					) {
						$text = (string) $node['attributes']['title'];
					}

					$outline[] = array(
						'path'    => $path_str,
						'tag'     => $tag,
						'label'   => nova_wpb_guess_label_for_tag( $tag, $node ),
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
if ( ! function_exists( 'nova_wpb_build_text_map_from_compact' ) ) {
	function nova_wpb_build_text_map_from_compact( $compact ) {
		$outline = nova_wpb_build_outline_from_compact( $compact, false );
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

				$atts_str = '';
				foreach ( $attributes as $key => $value ) {
					$key = (string) $key;

					// Normalize non-scalar attribute values.
					if ( is_array( $value ) || is_object( $value ) ) {
						$value = wp_json_encode( $value );
					} elseif ( is_bool( $value ) ) {
						$value = $value ? 'true' : 'false';
					} elseif ( null === $value ) {
						$value = '';
					} else {
						$value = (string) $value;
					}

					$atts_str .= ' ' . $key . '="' . esc_attr( $value ) . '"';
				}

				$inner = '';
				if ( ! empty( $children ) ) {
					$inner .= $build( $children );
				}
				if ( '' !== $text ) {
					$inner .= $text;
				}

				// Prefer explicit self-closing flag; otherwise infer for known self-closing tags when empty.
				$self_closing = ! empty( $node['self_closing'] );
				if ( ! $self_closing && '' === $inner && nova_wpb_is_known_self_closing_tag( $tag ) ) {
					$self_closing = true;
				}

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
