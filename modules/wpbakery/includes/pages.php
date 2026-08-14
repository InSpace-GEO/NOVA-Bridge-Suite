<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function nova_wpb_pages_maybe_require_dependencies() {
	$try_files = array(
		__DIR__ . '/layout.php',
		__DIR__ . '/transformations.php',
		dirname( __DIR__ ) . '/layout.php',
		dirname( __DIR__ ) . '/transformations.php',
	);

	foreach ( $try_files as $f ) {
		if ( file_exists( $f ) ) {
			require_once $f;
		}
	}
}
nova_wpb_pages_maybe_require_dependencies();

/* ----------------------------------------------------------------------------
 * Small helpers (safe fallbacks) — wrapped to avoid redeclare fatals
 * ------------------------------------------------------------------------- */
if ( ! function_exists( 'nova_wpb_to_bool' ) ) {
	function nova_wpb_to_bool( $value, $default = false ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( null === $value ) {
			return (bool) $default;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		$s = strtolower( trim( (string) $value ) );
		if ( '' === $s ) {
			return (bool) $default;
		}
		return in_array( $s, array( '1', 'true', 'yes', 'y', 'on' ), true );
	}
}

if ( ! function_exists( 'nova_wpb_resolve_request_post_type' ) ) {
	function nova_wpb_resolve_request_post_type( $params ) {
		$post_type = array_key_exists( 'post_type', $params ) ? $params['post_type'] : ( $params['type'] ?? 'page' );
		if ( is_string( $post_type ) && '' === trim( $post_type ) && array_key_exists( 'type', $params ) ) {
			$post_type = $params['type'];
		}
		if ( ! is_string( $post_type ) || '' === trim( $post_type ) ) {
			return '';
		}

		$post_type = strtolower( trim( $post_type ) );
		return 'service' === $post_type ? 'page' : $post_type;
	}
}

if ( ! function_exists( 'nova_wpb_validate_write_request' ) ) {
	function nova_wpb_validate_write_request( $params, $post = null ) {
		$requested_type = nova_wpb_resolve_request_post_type( $params );
		$post_type      = $post instanceof WP_Post ? $post->post_type : $requested_type;

		if ( $post instanceof WP_Post && ( array_key_exists( 'post_type', $params ) || array_key_exists( 'type', $params ) ) && $requested_type !== $post_type ) {
			return new WP_Error( 'nova_wpb_invalid_post_type', 'An existing post cannot be changed to another post type.', array( 'status' => 400 ) );
		}
		if ( '' === $post_type || sanitize_key( $post_type ) !== $post_type || ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'nova_wpb_invalid_post_type', sprintf( 'Unknown post type "%s".', $post_type ), array( 'status' => 400 ) );
		}

		$pto = get_post_type_object( $post_type );
		if ( ! $pto || ( empty( $pto->public ) && empty( $pto->show_in_rest ) ) ) {
			return new WP_Error( 'nova_wpb_invalid_post_type', sprintf( 'Post type "%s" is not available through REST.', $post_type ), array( 'status' => 400 ) );
		}

		if ( $post instanceof WP_Post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return new WP_Error( 'nova_wpb_forbidden', 'You are not allowed to edit this post.', array( 'status' => 403 ) );
			}
		} else {
			$create_cap = isset( $pto->cap->create_posts ) ? $pto->cap->create_posts : $pto->cap->edit_posts;
			if ( ! current_user_can( $create_cap ) ) {
				return new WP_Error( 'nova_wpb_forbidden', sprintf( 'You are not allowed to create content of type "%s".', $post_type ), array( 'status' => 403 ) );
			}
		}

		if ( array_key_exists( 'status', $params ) ) {
			if ( ! is_string( $params['status'] ) ) {
				return new WP_Error( 'nova_wpb_invalid_status', 'Post status must be a string.', array( 'status' => 400 ) );
			}
			$status  = trim( $params['status'] );
			$allowed = array( 'draft', 'publish', 'pending', 'private', 'future' );
			if ( ! in_array( $status, $allowed, true ) ) {
				return new WP_Error( 'nova_wpb_invalid_status', sprintf( 'Invalid status "%s".', $status ), array( 'status' => 400 ) );
			}
			if ( in_array( $status, array( 'publish', 'private', 'future' ), true ) && ! current_user_can( $pto->cap->publish_posts ) ) {
				return new WP_Error( 'nova_wpb_forbidden_publish', sprintf( 'You are not allowed to publish content of type "%s".', $post_type ), array( 'status' => 403 ) );
			}
			$params['status'] = $status;
		}

		if ( array_key_exists( 'meta_all', $params ) && ! function_exists( 'cf_tmrb_update_post_meta_all_payload' ) ) {
			return new WP_Error( 'missing_dependency', 'Core meta_all handler not loaded.', array( 'status' => 500 ) );
		}

		if ( ! ( $post instanceof WP_Post ) ) {
			$params['post_type'] = $post_type;
		}
		return $params;
	}
}

if ( ! function_exists( 'nova_wpb_validate_requested_template' ) ) {
	function nova_wpb_validate_requested_template( $params, $post_type, $post = null ) {
		if ( array_key_exists( 'template', $params ) ) {
			$template = $params['template'];
			if ( ! is_scalar( $template ) ) {
				return new WP_Error( 'nova_wpb_invalid_template', 'Page template must be a scalar value.', array( 'status' => 400 ) );
			}

			$template = trim( (string) $template );
			if ( ctype_digit( $template ) ) {
				$source_value = $params['source_page_id'] ?? null;
				$source_id    = ( is_int( $source_value ) || is_string( $source_value ) ) && ctype_digit( trim( (string) $source_value ) )
					? absint( $source_value )
					: 0;
				if ( $source_id <= 0 || absint( $template ) !== $source_id ) {
					return new WP_Error( 'nova_wpb_invalid_template', 'Numeric template must match source_page_id.', array( 'status' => 400 ) );
				}

				// Legacy n8n clone marker; source_page_id already selects the document to clone.
				if ( ! array_key_exists( '_wp_page_template', $params ) ) {
					return null;
				}
				$template = $params['_wp_page_template'];
			}
		} elseif ( array_key_exists( '_wp_page_template', $params ) ) {
			$template = $params['_wp_page_template'];
		} else {
			return null;
		}

		if ( ! is_scalar( $template ) ) {
			return new WP_Error( 'nova_wpb_invalid_template', 'Page template must be a scalar value.', array( 'status' => 400 ) );
		}

		$template = trim( (string) $template );
		if ( 'default' === $template ) {
			return $template;
		}
		if ( '' === $template || ! function_exists( 'get_page_templates' ) ) {
			return new WP_Error( 'nova_wpb_invalid_template', 'Unknown page template.', array( 'status' => 400 ) );
		}

		$templates = get_page_templates( $post instanceof WP_Post ? $post : null, $post_type );
		if ( ! in_array( $template, array_values( (array) $templates ), true ) ) {
			return new WP_Error( 'nova_wpb_invalid_template', 'Unknown page template.', array( 'status' => 400 ) );
		}

		return $template;
	}
}

if ( ! function_exists( 'nova_wpb_split_slug_path' ) ) {
	/**
	 * Split "parent/child" into [child, "parent"].
	 */
	function nova_wpb_split_slug_path( $slug_path ) {
		$slug_path = trim( (string) $slug_path );
		$slug_path = trim( $slug_path, '/' );

		if ( '' === $slug_path ) {
			return array( '', '' );
		}

		$parts = explode( '/', $slug_path );
		$child = array_pop( $parts );
		$parent = implode( '/', $parts );

		return array( $child, $parent );
	}
}

if ( ! function_exists( 'nova_wpb_get_slug_for_post' ) ) {
	/**
	 * Best-effort hierarchical slug for pages; fallback to post_name for posts.
	 */
	function nova_wpb_get_slug_for_post( $post ) {
		if ( ! $post || ! isset( $post->ID ) ) {
			return '';
		}
		if ( 'page' === $post->post_type ) {
			// Includes hierarchy.
			return (string) get_page_uri( $post->ID );
		}
		return (string) $post->post_name;
	}
}

if ( ! function_exists( 'nova_wpb_has_wpbakery_layout' ) ) {
	function nova_wpb_has_wpbakery_layout( $post ) {
		if ( ! $post ) {
			return false;
		}
		$flag = get_post_meta( $post->ID, '_wpb_vc_js_status', true );
		if ( '' !== (string) $flag ) {
			return true;
		}
		return false !== strpos( (string) $post->post_content, '[vc_' );
	}
}

if ( ! function_exists( 'nova_wpb_clone_post_meta' ) ) {
	/**
	 * Clone all post meta except keys in $skip_keys.
	 */
	function nova_wpb_clone_post_meta( $from_post_id, $to_post_id, $skip_keys = array() ) {
		$skip_keys = array_map( 'strval', (array) $skip_keys );

		$all = get_post_meta( (int) $from_post_id );
		if ( empty( $all ) || ! is_array( $all ) ) {
			return;
		}

		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( in_array( $key, $skip_keys, true ) ) {
				continue;
			}

			// Remove existing meta for clean clone.
			delete_post_meta( (int) $to_post_id, $key );

			if ( is_array( $values ) ) {
				foreach ( $values as $v ) {
					// Values are stored as strings by WP; still safe.
					add_post_meta( (int) $to_post_id, $key, maybe_unserialize( $v ) );
				}
			} else {
				add_post_meta( (int) $to_post_id, $key, maybe_unserialize( $values ) );
			}
		}
	}
}

if ( ! function_exists( 'nova_wpb_prepare_meta_updates' ) ) {
	/**
	 * Map request meta into common SEO plugin keys.
	 *
	 * Accepts:
	 * {
	 *   "meta": {
	 *     "meta_title": "...",
	 *     "meta_description": "...",
	 *     "_some_custom_key": "..."
	 *   }
	 * }
	 */
	function nova_wpb_prepare_meta_updates( $params ) {
		$out = array();

		if ( ! is_array( $params ) ) {
			return $out;
		}
		if ( empty( $params['meta'] ) || ! is_array( $params['meta'] ) ) {
			return $out;
		}

		$meta = $params['meta'];

		// Direct passthrough for underscore keys.
		foreach ( $meta as $k => $v ) {
			$k = (string) $k;

			if ( '_' === substr( $k, 0, 1 ) ) {
				$out[ $k ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
			}
		}

		// Friendly keys → common SEO plugins.
		if ( isset( $meta['meta_title'] ) ) {
			$title = (string) $meta['meta_title'];
			$out['_yoast_wpseo_title']   = $title;
			$out['_aioseo_title']        = $title;
			$out['rank_math_title']      = $title;
		}
		if ( isset( $meta['meta_description'] ) ) {
			$desc = (string) $meta['meta_description'];
			$out['_yoast_wpseo_metadesc'] = $desc;
			$out['_aioseo_description']   = $desc;
			$out['rank_math_description'] = $desc;
		}

		return $out;
	}
}

/* ----------------------------------------------------------------------------
 * Post-save normalization + WPBakery CSS meta regeneration
 * ------------------------------------------------------------------------- */
if ( ! function_exists( 'nova_wpb_normalize_empty_space_with_content' ) ) {
	/**
	 * If vc_empty_space is incorrectly used as a container, move its inner HTML
	 * into a vc_column_text and keep the spacer (attrs preserved).
	 *
	 * Example:
	 *   [vc_empty_space height="52px"]<p>Hi</p>[/vc_empty_space]
	 * becomes:
	 *   [vc_column_text]<p>Hi</p>[/vc_column_text][vc_empty_space height="52px"][/vc_empty_space]
	 */
	function nova_wpb_normalize_empty_space_with_content( $shortcodes ) {
		$shortcodes = (string) $shortcodes;

		return preg_replace_callback(
			'/\[vc_empty_space([^\]]*)\]([\s\S]*?)\[\/vc_empty_space\]/',
			function( $m ) {
				$attrs   = isset( $m[1] ) ? (string) $m[1] : '';
				$content = isset( $m[2] ) ? (string) $m[2] : '';

				// If truly empty, keep as-is.
				if ( '' === trim( $content ) ) {
					return $m[0];
				}

				// If only whitespace/linebreaks, keep as-is.
				$stripped = trim( wp_strip_all_tags( $content ) );
				if ( '' === $stripped ) {
					return $m[0];
				}

				// Move content into a proper text container, keep the spacer.
				$spacer = '[vc_empty_space' . $attrs . '][/vc_empty_space]';
				return '[vc_column_text]' . $content . '[/vc_column_text]' . $spacer;
			},
			$shortcodes
		);
	}
}

if ( ! function_exists( 'nova_wpb_regenerate_shortcodes_custom_css_meta' ) ) {
	/**
	 * Regenerate WPBakery shortcode custom CSS meta from embedded .vc_custom_*{...} rules.
	 * This helps when transformations move/remove/add vc_custom rules.
	 */
	function nova_wpb_regenerate_shortcodes_custom_css_meta( $post_id, $shortcodes ) {
		$post_id    = (int) $post_id;
		$shortcodes = (string) $shortcodes;

		preg_match_all( '/\.vc_custom_\d+\s*\{[^}]*\}/s', $shortcodes, $m );
		$rules = array_values( array_unique( $m[0] ?? array() ) );
		$css   = implode( "\n", $rules );

		update_post_meta( $post_id, '_wpb_shortcodes_custom_css', $css );
	}
}

if ( ! function_exists( 'nova_wpb_document_hash' ) ) {
	function nova_wpb_document_hash( $content ) {
		return hash( 'sha256', (string) $content );
	}
}

if ( ! function_exists( 'nova_wpb_build_meta_all_payload' ) ) {
	/**
	 * Builder view embedded in authenticated core REST meta_all responses.
	 */
	function nova_wpb_build_meta_all_payload( $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return array();
		}

		$content     = (string) $post->post_content;
		$has_builder = nova_wpb_has_wpbakery_layout( $post );
		$outline     = array();
		$text_map    = array();

		if (
			$has_builder
			&& function_exists( 'nova_wpb_parse_shortcodes_to_compact' )
			&& function_exists( 'nova_wpb_build_outline_from_compact' )
		) {
			$compact = nova_wpb_parse_shortcodes_to_compact( $content );
			$outline = nova_wpb_build_outline_from_compact( $compact, false );
			if ( function_exists( 'nova_wpb_build_text_map_from_compact' ) ) {
				$text_map = nova_wpb_build_text_map_from_compact( $compact );
			}
		}

		return array(
			'has_builder'  => $has_builder,
			'document_hash' => nova_wpb_document_hash( $content ),
			'outline'      => $outline,
			'text_map'     => $text_map,
		);
	}
}

if ( ! function_exists( 'nova_wpb_validate_builder_document' ) ) {
	function nova_wpb_validate_builder_document( $payload, $content, $has_builder = true ) {
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'rest_invalid_param', 'meta_all.wpbakery must be an object.', array( 'status' => 400 ) );
		}
		if ( ! $has_builder ) {
			return new WP_Error( 'nova_wpb_not_builder_content', 'The target post does not contain a WPBakery layout.', array( 'status' => 422 ) );
		}

		$unsupported = array_diff( array_keys( $payload ), array( 'document_hash', 'text_updates' ) );
		if ( ! empty( $unsupported ) ) {
			return new WP_Error(
				'nova_wpb_unsupported_field',
				'Unsupported WPBakery field: ' . implode( ', ', array_map( 'strval', $unsupported ) ),
				array( 'status' => 422, 'unsupported_fields' => array_values( $unsupported ) )
			);
		}

		$content = (string) $content;
		if ( array_key_exists( 'document_hash', $payload ) ) {
			if ( ! is_string( $payload['document_hash'] ) || ! hash_equals( nova_wpb_document_hash( $content ), $payload['document_hash'] ) ) {
				return new WP_Error(
					'nova_wpb_document_changed',
					'The WPBakery document changed after it was inspected. Fetch a fresh outline before writing.',
					array( 'status' => 409, 'document_hash' => nova_wpb_document_hash( $content ) )
				);
			}
		}

		$updates = $payload['text_updates'] ?? array();
		if ( ! is_array( $updates ) ) {
			return new WP_Error( 'rest_invalid_param', 'wpbakery.text_updates must be an array.', array( 'status' => 400 ) );
		}
		if ( empty( $updates ) ) {
			return array();
		}
		if ( ! function_exists( 'nova_wpb_parse_shortcodes_to_compact' ) || ! function_exists( 'nova_wpb_build_outline_from_compact' ) ) {
			return new WP_Error( 'missing_dependency', 'WPBakery layout parser not loaded.', array( 'status' => 500 ) );
		}

		$compact = nova_wpb_parse_shortcodes_to_compact( $content );
		if ( function_exists( 'nova_wpb_validate_roundtrip_coverage' ) ) {
			$coverage = nova_wpb_validate_roundtrip_coverage( $content, $compact );
			if ( is_wp_error( $coverage ) ) {
				return $coverage;
			}
		}

		$paths = array();
		foreach ( nova_wpb_build_outline_from_compact( $compact, false ) as $item ) {
			$paths[ (string) $item['path'] ] = $item;
		}

		$normalized = array();
		foreach ( $updates as $index => $update ) {
			if ( ! is_array( $update ) ) {
				return new WP_Error( 'rest_invalid_param', "wpbakery.text_updates[$index] must be an object.", array( 'status' => 400 ) );
			}
			$unknown = array_diff( array_keys( $update ), array( 'path', 'field', 'text' ) );
			if ( ! empty( $unknown ) ) {
				return new WP_Error( 'nova_wpb_unsupported_field', 'Unsupported text update field: ' . implode( ', ', $unknown ), array( 'status' => 422 ) );
			}
			if ( ! array_key_exists( 'path', $update ) || ! is_scalar( $update['path'] ) || ! preg_match( '/^\d+(?:\.\d+)*$/', (string) $update['path'] ) ) {
				return new WP_Error( 'rest_invalid_param', "wpbakery.text_updates[$index].path is invalid.", array( 'status' => 400 ) );
			}
			if ( array_key_exists( 'field', $update ) && ( ! is_scalar( $update['field'] ) || '' === sanitize_key( (string) $update['field'] ) ) ) {
				return new WP_Error( 'rest_invalid_param', "wpbakery.text_updates[$index].field is invalid.", array( 'status' => 400 ) );
			}

			$path = (string) $update['path'];
			if ( ! isset( $paths[ $path ] ) ) {
				return new WP_Error( 'nova_wpb_path_not_found', "WPBakery path $path no longer exists.", array( 'status' => 409, 'path' => $path ) );
			}

			$tag     = (string) ( $paths[ $path ]['tag'] ?? '' );
			$fields  = isset( $paths[ $path ]['fields'] ) && is_array( $paths[ $path ]['fields'] ) ? $paths[ $path ]['fields'] : array();
			$primary = isset( $paths[ $path ]['field'] ) ? (string) $paths[ $path ]['field'] : '';
			$field   = isset( $update['field'] ) && is_scalar( $update['field'] ) ? sanitize_key( (string) $update['field'] ) : $primary;
			if ( in_array( $field, array( 'content', 'text' ), true ) && ! isset( $fields[ $field ] ) && isset( $fields['body'] ) ) {
				$field = 'body';
			}
			if ( '' === $field || ! isset( $fields[ $field ] ) || empty( $fields[ $field ]['editable'] ) ) {
				return new WP_Error(
					'nova_wpb_unsupported_field',
					"Field $field is not writable for $tag at $path.",
					array( 'status' => 422, 'path' => $path, 'supported_fields' => array_keys( $fields ) )
				);
			}
			if ( ! array_key_exists( 'text', $update ) || ! is_scalar( $update['text'] ) ) {
				return new WP_Error( 'rest_invalid_param', "wpbakery.text_updates[$index].text must be a scalar value.", array( 'status' => 400 ) );
			}
			if ( function_exists( 'nova_wpb_sanitize_editable_field_value' ) ) {
				$valid = false;
				nova_wpb_sanitize_editable_field_value( $field, (string) $update['text'], $valid );
				if ( ! $valid ) {
					return new WP_Error(
						'nova_wpb_unsafe_value',
						"Field $field contains an unsafe value.",
						array( 'status' => 422, 'path' => $path, 'field' => $field )
					);
				}
			}

			$normalized[] = array( 'path' => $path, 'field' => $field, 'text' => (string) $update['text'] );
		}

		return $normalized;
	}
}

if ( ! function_exists( 'nova_wpb_validate_builder_payload' ) ) {
	function nova_wpb_validate_builder_payload( $payload, $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return new WP_Error( 'rest_invalid', 'Invalid post object.', array( 'status' => 400 ) );
		}

		return nova_wpb_validate_builder_document(
			$payload,
			(string) $post->post_content,
			nova_wpb_has_wpbakery_layout( $post )
		);
	}
}

if ( ! function_exists( 'nova_wpb_apply_meta_all_payload' ) ) {
	function nova_wpb_apply_meta_all_payload( $payload, $post ) {
		static $applied = array();
		if ( ! ( $post instanceof WP_Post ) ) {
			return new WP_Error( 'rest_invalid', 'Invalid post object.', array( 'status' => 400 ) );
		}

		$token = $post->ID . ':' . md5( wp_json_encode( $payload ) );
		if ( isset( $applied[ $token ] ) ) {
			return true;
		}

		$validation = nova_wpb_validate_builder_payload( $payload, $post );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( empty( $validation ) ) {
			$applied[ $token ] = true;
			return true;
		}
		if ( ! function_exists( 'nova_wpb_apply_transformations' ) ) {
			return new WP_Error( 'missing_dependency', 'WPBakery transformations not loaded.', array( 'status' => 500 ) );
		}

		$original = (string) $post->post_content;
		$updated  = nova_wpb_apply_transformations( $original, array(), $validation, '', array() );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		if ( $updated === $original ) {
			$applied[ $token ] = true;
			return true;
		}

		$result = wp_update_post(
			wp_slash( array( 'ID' => $post->ID, 'post_content' => $updated ) ),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( (string) get_post_field( 'post_content', $post->ID ) !== $updated ) {
			$rollback = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $original ) ), true );
			return new WP_Error(
				'nova_wpb_storage_mismatch',
				'WordPress did not store the WPBakery document byte-for-byte.',
				array( 'status' => 500, 'rollback_failed' => is_wp_error( $rollback ) )
			);
		}

		nova_wpb_regenerate_shortcodes_custom_css_meta( $post->ID, $updated );
		update_post_meta( $post->ID, '_wpb_vc_js_status', 'true' );
		$applied[ $token ] = true;
		return true;
	}
}

if ( ! function_exists( 'nova_wpb_normalize_request_meta_all' ) ) {
	function nova_wpb_normalize_request_meta_all( $params ) {
		if ( ! array_key_exists( 'meta_all', $params ) ) {
			return $params;
		}
		if ( ! is_array( $params['meta_all'] ) ) {
			return new WP_Error( 'rest_invalid_param', 'meta_all must be an object.', array( 'status' => 400 ) );
		}
		if ( ! array_key_exists( 'wpbakery', $params['meta_all'] ) ) {
			return $params;
		}
		if ( ! is_array( $params['meta_all']['wpbakery'] ) ) {
			return new WP_Error( 'rest_invalid_param', 'meta_all.wpbakery must be an object.', array( 'status' => 400 ) );
		}

		$builder = $params['meta_all']['wpbakery'];
		$unknown = array_diff( array_keys( $builder ), array( 'document_hash', 'text_updates' ) );
		if ( ! empty( $unknown ) ) {
			return new WP_Error( 'nova_wpb_unsupported_field', 'Unsupported WPBakery field: ' . implode( ', ', $unknown ), array( 'status' => 422 ) );
		}
		foreach ( array( 'document_hash', 'text_updates' ) as $key ) {
			if ( ! array_key_exists( $key, $params ) && array_key_exists( $key, $builder ) ) {
				$params[ $key ] = $builder[ $key ];
			}
		}
		unset( $params['meta_all']['wpbakery'] );
		return $params;
	}
}

if ( ! function_exists( 'nova_wpb_builder_payload_from_params' ) ) {
	function nova_wpb_builder_payload_from_params( $params ) {
		$payload = array();
		foreach ( array( 'document_hash', 'text_updates' ) as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$payload[ $key ] = $params[ $key ];
			}
		}

		return empty( $payload ) ? null : $payload;
	}
}

if ( ! function_exists( 'nova_wpb_apply_request_meta' ) ) {
	function nova_wpb_apply_request_meta( $post_id, $params ) {
		if ( array_key_exists( 'meta_all', $params ) ) {
			if ( ! function_exists( 'cf_tmrb_update_post_meta_all_payload' ) ) {
				return new WP_Error( 'missing_dependency', 'Core meta_all handler not loaded.', array( 'status' => 500 ) );
			}
			$result = cf_tmrb_update_post_meta_all_payload( $params['meta_all'], get_post( $post_id ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		foreach ( nova_wpb_prepare_meta_updates( $params ) as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		return true;
	}
}

/* ----------------------------------------------------------------------------
 * Critical fix: safe JSON parameter extraction (prevents 500 fatals)
 * ------------------------------------------------------------------------- */
function nova_wpb_get_request_json_params_safe( WP_REST_Request $request ) {
	$params = $request->get_json_params();
	if ( is_array( $params ) ) {
		return $params;
	}

	// Try raw body parse (handles clients that send Content-Type but set json:false / streaming).
	$raw = (string) $request->get_body();
	$raw_trim = trim( $raw );

	if ( '' === $raw_trim ) {
		return array();
	}

	$decoded = json_decode( $raw_trim, true );
	if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
		return $decoded;
	}

	return new WP_Error(
		'invalid_json',
		'Request body must be valid JSON.',
		array(
			'status' => 400,
			'hint'   => 'Ensure your client sends a JSON body (e.g. n8n json:true, useStream:false).',
			'raw_body_prefix' => substr( $raw_trim, 0, 200 ),
		)
	);
}

/* ----------------------------------------------------------------------------
 * Core: Resolve / list / get / create / update
 * ------------------------------------------------------------------------- */

/**
 * Resolve a page by ID or slug/path (supports hierarchical page paths).
 */
function nova_wpb_resolve_page( $id_or_slug, $post_types = array( 'page', 'post' ) ) {
	$id_or_slug = trim( (string) $id_or_slug );
	$post_types = (array) $post_types;

	// Numeric ID.
	if ( '' !== $id_or_slug && ctype_digit( $id_or_slug ) ) {
		$post = get_post( (int) $id_or_slug );
		if ( $post && 'trash' !== $post->post_status && in_array( $post->post_type, $post_types, true ) ) {
			return $post;
		}
	}

	if ( '' === $id_or_slug ) {
		return null;
	}

	$path = trim( $id_or_slug, '/' );

	// 1) Try hierarchical page path, e.g. "parent/child".
	if ( in_array( 'page', $post_types, true ) ) {
		$post = get_page_by_path( $path, OBJECT, 'page' );
		if ( $post && 'trash' !== $post->post_status ) {
			return $post;
		}
	}

	// 2) Fallback: try simple slug for pages and posts.
	$slug = basename( $path );
	$args = array(
		'name'           => sanitize_title( $slug ),
		'post_type'      => $post_types,
		'post_status'    => 'any',
		'posts_per_page' => 1,
	);

	$query = new WP_Query( $args );
	if ( $query->have_posts() ) {
		$post = $query->posts[0];
		if ( 'trash' !== $post->post_status ) {
			return $post;
		}
	}

	return null;
}

if ( ! function_exists( 'nova_wpb_page_summary' ) ) {
	function nova_wpb_page_summary( $post ) {
		return array(
			'id'           => $post->ID,
			'title'        => get_the_title( $post ),
			'slug'         => nova_wpb_get_slug_for_post( $post ),
			'status'       => $post->post_status,
			'modified_gmt' => get_post_modified_time( 'c', true, $post ),
			'permalink'    => get_permalink( $post ),
			'excerpt'      => $post->post_excerpt,
			'post_type'    => $post->post_type,
		);
	}
}

if ( ! function_exists( 'nova_wpb_build_page_response_data' ) ) {
	function nova_wpb_build_page_response_data( $post, $options = array() ) {
		$layout_mode      = $options['layout_mode'] ?? 'outline';
		$outline_style    = $options['outline_style'] ?? 'summary';
		$include_meta     = array_key_exists( 'include_meta', $options ) ? (bool) $options['include_meta'] : true;
		$include_document = ! empty( $options['include_document'] );
		$text_map_flag    = ! empty( $options['text_map'] );
		$raw_shortcodes   = (string) $post->post_content;

		$layout = array(
			'outline'       => array(),
			'has_builder'   => nova_wpb_has_wpbakery_layout( $post ),
			'document_hash' => nova_wpb_document_hash( $raw_shortcodes ),
		);
		$compact       = array();
		$text_map_data = array();

		if ( in_array( $layout_mode, array( 'outline', 'full' ), true ) ) {
			if ( ! function_exists( 'nova_wpb_parse_shortcodes_to_compact' ) ) {
				return new WP_Error( 'missing_dependency', 'WPBakery layout parser not loaded.', array( 'status' => 500 ) );
			}
			$compact = nova_wpb_parse_shortcodes_to_compact( $raw_shortcodes );

			if ( 'outline' === $layout_mode ) {
				if ( ! function_exists( 'nova_wpb_build_outline_from_compact' ) ) {
					return new WP_Error( 'missing_dependency', 'WPBakery outline builder not loaded.', array( 'status' => 500 ) );
				}
				$layout['outline'] = nova_wpb_build_outline_from_compact( $compact, 'tree' === $outline_style );
			} else {
				$layout['compact'] = $compact;
			}

			if ( $text_map_flag ) {
				$text_map_data = nova_wpb_build_meta_all_payload( $post )['text_map'];
			}
		}

		$data           = nova_wpb_page_summary( $post );
		$data['layout'] = $layout;

		if ( $include_meta ) {
			$data['meta'] = array(
				'_wpb_vc_js_status'          => get_post_meta( $post->ID, '_wpb_vc_js_status', true ),
				'_wpb_shortcodes_custom_css' => get_post_meta( $post->ID, '_wpb_shortcodes_custom_css', true ),
				'_wpb_post_custom_css'       => get_post_meta( $post->ID, '_wpb_post_custom_css', true ),
			);
			$data['meta_all'] = function_exists( 'cf_tmrb_get_post_meta_all_payload' )
				? cf_tmrb_get_post_meta_all_payload( $post->ID, current_user_can( 'edit_post', $post->ID ) )
				: array();
		}

		$data['document'] = $include_document ? $raw_shortcodes : null;
		if ( $text_map_flag ) {
			$data['text_map'] = $text_map_data;
		}

		return $data;
	}
}

/**
 * GET /pages – list pages/posts.
 */
function nova_wpb_list_pages( $request ) {
	$per_page = min( max( 1, (int) $request->get_param( 'per_page' ) ), 50 );
	$page_num = max( 1, (int) $request->get_param( 'page' ) );
	$status   = $request->get_param( 'status' );
	$context  = (string) $request->get_param( 'context' );
	$enriched = (bool) preg_match( '/^edit(?:[?&]|$)/', $context )
		|| nova_wpb_to_bool( $request->get_param( 'include_fields' ), false );
	if ( preg_match( '/[?&]per_page=(\d+)/', $context, $legacy_per_page ) ) {
		$per_page = min( max( 1, (int) $legacy_per_page[1] ), 50 );
	}

	$post_types = array( 'page', 'post' );
	if ( $request->get_param( 'post_type' ) ) {
		$post_types = (array) $request->get_param( 'post_type' );
	}

	$prepare_item = function ( $post ) use ( $enriched ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return null;
		}
		if ( ! $enriched ) {
			return nova_wpb_page_summary( $post );
		}

		return nova_wpb_build_page_response_data(
			$post,
			array( 'layout_mode' => 'outline', 'include_meta' => true, 'text_map' => true )
		);
	};

	$id_filter = absint( $request->get_param( 'id' ) );
	if ( $id_filter > 0 ) {
		$post  = nova_wpb_resolve_page( (string) $id_filter, $post_types );
		$items = array();
		if ( $post ) {
			$item = $prepare_item( $post );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', count( $items ) );
		$response->header( 'X-WP-TotalPages', 1 );
		return $response;
	}

	// Exact slug filter.
	$slug_filter = $request->get_param( 'slug' );
	if ( is_string( $slug_filter ) && '' !== trim( $slug_filter ) ) {
		$post = null;

		if ( $request->get_param( 'post_type' ) ) {
			$slug_post_types = (array) $request->get_param( 'post_type' );
			$post            = nova_wpb_resolve_page( $slug_filter, $slug_post_types );
		} else {
			$post = nova_wpb_resolve_page( $slug_filter, array( 'page' ) );
			if ( ! $post ) {
				$post = nova_wpb_resolve_page( $slug_filter, array( 'post' ) );
			}
		}

		$items = array();
		if ( $post ) {
			$item = $prepare_item( $post );
			if ( is_wp_error( $item ) ) {
				return $item;
			}
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', count( $items ) );
		$response->header( 'X-WP-TotalPages', 1 );
		return $response;
	}

	$args = array(
		'post_type'      => $post_types,
		'post_status'    => $status ? $status : 'any',
		'posts_per_page' => $per_page,
		'paged'          => $page_num,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'perm'           => 'editable',
	);

	if ( $request->get_param( 'search' ) ) {
		$args['s'] = $request->get_param( 'search' );
	}

	$include = $request->get_param( 'include' );
	if ( is_array( $include ) ) {
		$args['post__in'] = array_map( 'intval', $include );
	} elseif ( is_string( $include ) && '' !== trim( $include ) ) {
		// Allow CSV.
		$args['post__in'] = array_map( 'intval', preg_split( '/\s*,\s*/', trim( $include ) ) );
	}

	if ( $request->get_param( 'parent_id' ) ) {
		$args['post_parent'] = (int) $request->get_param( 'parent_id' );
	}

	$query = new WP_Query( $args );
	$items = array();

	foreach ( $query->posts as $post ) {
		$item = $prepare_item( $post );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		if ( null !== $item ) {
			$items[] = $item;
		}
	}

	$response = new WP_REST_Response( $items );
	$response->header( 'X-WP-Total', (int) $query->found_posts );
	$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );
	return $response;
}

/**
 * GET /pages/{id-or-slug} – single page + outline.
 */
function nova_wpb_get_page( $request ) {
	$post = nova_wpb_resolve_page( $request['id_or_slug'] );
	if ( ! $post ) {
		return new WP_Error( 'not_found', 'Page not found', array( 'status' => 404 ) );
	}

	$data = nova_wpb_build_page_response_data(
		$post,
		array(
			'layout_mode'      => $request->get_param( 'layout_mode' ) ?: 'outline',
			'outline_style'    => $request->get_param( 'outline_style' ) ?: 'summary',
			'include_meta'     => nova_wpb_to_bool( $request->get_param( 'include_meta' ), true ),
			'include_document' => nova_wpb_to_bool( $request->get_param( 'include_document' ), false ),
			'text_map'         => nova_wpb_to_bool( $request->get_param( 'text_map' ), false ),
		)
	);

	return is_wp_error( $data ) ? $data : new WP_REST_Response( $data );
}

/**
 * POST /pages – create (clone + replace template content slots + transforms).
 */
function nova_wpb_create_page( $request ) {
	$params = nova_wpb_get_request_json_params_safe( $request );
	if ( is_wp_error( $params ) ) {
		return $params; // 400 instead of fatal 500
	}

	// If "content" is a JSON string, merge its keys into $params.
	if ( isset( $params['content'] ) && is_string( $params['content'] ) ) {
		$trimmed = trim( $params['content'] );
		if ( '' !== $trimmed && '{' === $trimmed[0] ) {
			$decoded = json_decode( $trimmed, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				$params = array_merge( $params, $decoded );
			}
		}
	}
	$params = nova_wpb_normalize_request_meta_all( $params );
	if ( is_wp_error( $params ) ) {
		return $params;
	}
	$params = nova_wpb_validate_write_request( $params );
	if ( is_wp_error( $params ) ) {
		return $params;
	}

	$clone_mode  = ! empty( $params['source_page_id'] ) || ! empty( $params['source_page'] );
	$source_post = null;
	$post_type   = $params['post_type'];
	$page_template = nova_wpb_validate_requested_template( $params, $post_type );
	if ( is_wp_error( $page_template ) ) {
		return $page_template;
	}

	$remove_paths    = ! empty( $params['remove_paths'] ) ? (array) $params['remove_paths'] : array();
	$text_updates    = ! empty( $params['text_updates'] ) ? (array) $params['text_updates'] : array();
	$append_html     = ! empty( $params['append_html'] ) ? (string) $params['append_html'] : '';
	$append_sections = ! empty( $params['append_sections'] ) ? (array) $params['append_sections'] : array();

	$keep_source_content = false;
	if ( is_array( $params ) && array_key_exists( 'keep_source_content', $params ) ) {
		$keep_source_content = nova_wpb_to_bool( $params['keep_source_content'], false );
	}

	$postarr = array(
		'post_title'   => isset( $params['title'] ) ? wp_strip_all_tags( $params['title'] ) : '',
		'post_status'  => isset( $params['status'] ) ? $params['status'] : 'draft',
		'post_type'    => $post_type,
		'post_excerpt' => isset( $params['excerpt'] ) ? (string) $params['excerpt'] : '',
	);

	if ( isset( $params['slug'] ) && '' !== trim( (string) $params['slug'] ) ) {
		list( $child_slug, $parent_path ) = nova_wpb_split_slug_path( $params['slug'] );
		$postarr['post_name']             = sanitize_title( $child_slug );

		if ( '' !== $parent_path && empty( $params['parent_id'] ) && empty( $params['parent'] ) ) {
			$parent_post = nova_wpb_resolve_page( $parent_path );
			if ( $parent_post ) {
				$postarr['post_parent'] = $parent_post->ID;
			}
		}
	}

	if ( ! empty( $params['parent_id'] ) ) {
		$postarr['post_parent'] = (int) $params['parent_id'];
	} elseif ( isset( $params['parent'] ) && '' !== trim( (string) $params['parent'] ) ) {
		$parent_post = nova_wpb_resolve_page( $params['parent'] );
		if ( $parent_post ) {
			$postarr['post_parent'] = $parent_post->ID;
		}
	}

	$requested_content = null;
	if ( isset( $params['layout'] ) && is_array( $params['layout'] ) ) {
		if ( ! empty( $params['layout']['raw_shortcodes'] ) ) {
			$requested_content = (string) $params['layout']['raw_shortcodes'];
		} elseif ( ! empty( $params['layout']['compact'] ) ) {
			if ( ! function_exists( 'nova_wpb_compact_to_shortcodes' ) ) {
				return new WP_Error(
					'missing_dependency',
					'Shortcode serializer not loaded (nova_wpb_compact_to_shortcodes). Ensure layout.php is included.',
					array( 'status' => 500 )
				);
			}
			$requested_content = nova_wpb_compact_to_shortcodes( $params['layout']['compact'] );
		}
	}

	if ( $clone_mode ) {
		if ( ! empty( $params['source_page_id'] ) ) {
			$source_post = get_post( (int) $params['source_page_id'] );
		}
		if ( ! $source_post && ! empty( $params['source_page'] ) ) {
			$source_post = nova_wpb_resolve_page( $params['source_page'] );
		}
		if ( ! $source_post ) {
			return new WP_Error( 'not_found', 'Source page not found.', array( 'status' => 404 ) );
		}

		$allowed_source_types = array( 'page', 'post' );
		if ( is_string( $post_type ) && '' !== $post_type ) {
			$allowed_source_types[] = $post_type;
		}
		$source_type   = get_post_type_object( $source_post->post_type );
		$source_status = get_post_status_object( $source_post->post_status );
		if (
			! $source_type
			|| empty( $source_type->show_in_rest )
			|| ! in_array( $source_post->post_type, array_unique( $allowed_source_types ), true )
			|| ! $source_status
			|| in_array( $source_post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true )
		) {
			return new WP_Error( 'not_found', 'Source page not found.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $source_post->ID ) ) {
			return new WP_Error( 'nova_wpb_forbidden', 'You are not allowed to clone this source page.', array( 'status' => 403 ) );
		}
	}

	$base_shortcodes = '';
	$using_template  = false;

	if ( null !== $requested_content ) {
		$base_shortcodes = $requested_content;
	} elseif ( $clone_mode && $source_post ) {
		$base_shortcodes = (string) $source_post->post_content;
		$using_template  = true;
	}

	$builder_payload = nova_wpb_builder_payload_from_params( $params );
	if ( null !== $builder_payload ) {
		$validated_updates = nova_wpb_validate_builder_document(
			$builder_payload,
			$base_shortcodes,
			false !== strpos( $base_shortcodes, '[vc_' ) || ( $using_template && nova_wpb_has_wpbakery_layout( $source_post ) )
		);
		if ( is_wp_error( $validated_updates ) ) {
			return $validated_updates;
		}
		$text_updates = $validated_updates;
	}

	// If template: allow path-based cleanup first (optional).
	if ( $using_template && ( ! empty( $remove_paths ) || ! empty( $text_updates ) ) ) {
		if ( ! function_exists( 'nova_wpb_apply_transformations' ) ) {
			return new WP_Error(
				'missing_dependency',
				'Transformations not loaded (nova_wpb_apply_transformations). Ensure transformations.php is included.',
				array( 'status' => 500 )
			);
		}

		$base_shortcodes = nova_wpb_apply_transformations( $base_shortcodes, $remove_paths, $text_updates, '', array() );
		if ( is_wp_error( $base_shortcodes ) ) {
			return $base_shortcodes;
		}
		$remove_paths = array();
		$text_updates = array();
	}

	// Auto-split single huge section into multiple <h2>-based sections.
	if ( $using_template && ! empty( $append_sections ) && is_array( $append_sections ) ) {
		if ( function_exists( 'nova_wpb_expand_single_html_section_to_multiple' ) ) {
			$append_sections = nova_wpb_expand_single_html_section_to_multiple( $append_sections, $postarr['post_title'] );
		}
	}

	// Replace template slots instead of appending duplicates.
	$nova_slot_report = null;
	if ( $using_template && ! $keep_source_content && ! empty( $append_sections ) && is_array( $append_sections ) ) {
		if ( ! function_exists( 'nova_wpb_replace_template_slots_with_sections' ) ) {
			return new WP_Error(
				'missing_dependency',
				'Template slot replacer not loaded (nova_wpb_replace_template_slots_with_sections). Ensure transformations.php is included.',
				array( 'status' => 500 )
			);
		}

		list( $base_shortcodes, $append_sections ) = nova_wpb_replace_template_slots_with_sections(
			$base_shortcodes,
			$append_sections,
			$postarr['post_title'],
			true,
			$nova_slot_report
		);
	}

	if ( ! function_exists( 'nova_wpb_apply_transformations' ) ) {
		return new WP_Error(
			'missing_dependency',
			'Transformations not loaded (nova_wpb_apply_transformations). Ensure transformations.php is included.',
			array( 'status' => 500 )
		);
	}

	$shortcodes = nova_wpb_apply_transformations(
		$base_shortcodes,
		$remove_paths,
		$text_updates,
		$append_html,
		$append_sections
	);
	if ( is_wp_error( $shortcodes ) ) {
		return $shortcodes;
	}

	// Only normalize content supplied or generated for this new post; clones stay byte-local.
	if ( null !== $requested_content || '' !== $append_html || ! empty( $append_sections ) ) {
		$shortcodes = nova_wpb_normalize_empty_space_with_content( $shortcodes );
	}
	$postarr['post_content'] = $shortcodes;

	$post_id = wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	// Clone meta if in clone mode.
	if ( $clone_mode && $source_post ) {
		$clone_skip_keys = array(
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_aioseo_title',
			'_aioseo_description',
			'rank_math_title',
			'rank_math_description',
		);

		if ( isset( $params['meta'] ) && is_array( $params['meta'] ) ) {
			$clone_skip_keys = array_merge( $clone_skip_keys, array_keys( $params['meta'] ) );
		}

		nova_wpb_clone_post_meta( $source_post->ID, $post_id, $clone_skip_keys );
	}

	// Force builder flag on clone layouts so WPBakery/theme CSS loads reliably.
	if ( $using_template || false !== strpos( (string) $shortcodes, '[vc_' ) ) {
		update_post_meta( $post_id, '_wpb_vc_js_status', 'true' );
	}

	$meta_result = nova_wpb_apply_request_meta( $post_id, $params );
	if ( is_wp_error( $meta_result ) ) {
		return $meta_result;
	}
	if ( null !== $page_template ) {
		update_post_meta( $post_id, '_wp_page_template', $page_template );
	}

	// Ensure VC Design Options CSS remains consistent with final post_content.
	nova_wpb_regenerate_shortcodes_custom_css_meta( $post_id, $shortcodes );

	if ( ! empty( $params['publish_builder'] ) ) {
		update_post_meta( $post_id, '_wpb_vc_js_status', 'true' );
	}

	/*
	 * Additive diagnostics. Existing consumers read .id; this exists so a run that
	 * filled nothing and appended everything is visible in the response instead of
	 * only on the rendered page.
	 */
	$response_body = array( 'id' => $post_id );
	if ( is_array( $nova_slot_report ) ) {
		unset( $nova_slot_report['shell'] );
		$response_body['nova'] = $nova_slot_report;
	}

	return new WP_REST_Response( $response_body, 201 );
}

/**
 * PUT/PATCH /pages/{id-or-slug} – update.
 */
function nova_wpb_update_page( $request ) {
	$post = nova_wpb_resolve_page( $request['id_or_slug'] );
	if ( ! $post ) {
		return new WP_Error( 'not_found', 'Page not found', array( 'status' => 404 ) );
	}

	$params = nova_wpb_get_request_json_params_safe( $request );
	if ( is_wp_error( $params ) ) {
		return $params; // 400 instead of fatal 500
	}
	$params = nova_wpb_normalize_request_meta_all( $params );
	if ( is_wp_error( $params ) ) {
		return $params;
	}
	$params = nova_wpb_validate_write_request( $params, $post );
	if ( is_wp_error( $params ) ) {
		return $params;
	}
	$page_template = nova_wpb_validate_requested_template( $params, $post->post_type, $post );
	if ( is_wp_error( $page_template ) ) {
		return $page_template;
	}

	$post_id = $post->ID;
	$postarr = array( 'ID' => $post_id );

	if ( isset( $params['title'] ) ) {
		$postarr['post_title'] = wp_strip_all_tags( $params['title'] );
	}
	if ( isset( $params['slug'] ) && '' !== trim( (string) $params['slug'] ) ) {
		list( $child_slug, $parent_path ) = nova_wpb_split_slug_path( $params['slug'] );
		$postarr['post_name']             = sanitize_title( $child_slug );

		if ( '' !== $parent_path && ! isset( $params['parent_id'] ) && ! isset( $params['parent'] ) ) {
			$parent_post = nova_wpb_resolve_page( $parent_path );
			if ( $parent_post ) {
				$postarr['post_parent'] = $parent_post->ID;
			}
		}
	}
	if ( isset( $params['status'] ) ) {
		$postarr['post_status'] = $params['status'];
	}
	if ( isset( $params['excerpt'] ) ) {
		$postarr['post_excerpt'] = (string) $params['excerpt'];
	}

	if ( isset( $params['parent_id'] ) ) {
		$postarr['post_parent'] = (int) $params['parent_id'];
	} elseif ( isset( $params['parent'] ) && '' !== trim( (string) $params['parent'] ) ) {
		$parent_post = nova_wpb_resolve_page( $params['parent'] );
		if ( $parent_post ) {
			$postarr['post_parent'] = $parent_post->ID;
		}
	}

	$shortcodes = (string) $post->post_content;

	if ( isset( $params['layout'] ) && is_array( $params['layout'] ) ) {
		if ( array_key_exists( 'raw_shortcodes', $params['layout'] ) ) {
			$shortcodes = (string) $params['layout']['raw_shortcodes'];
		} elseif ( array_key_exists( 'compact', $params['layout'] ) ) {
			if ( ! function_exists( 'nova_wpb_compact_to_shortcodes' ) ) {
				return new WP_Error(
					'missing_dependency',
					'Shortcode serializer not loaded (nova_wpb_compact_to_shortcodes). Ensure layout.php is included.',
					array( 'status' => 500 )
				);
			}
			$shortcodes = nova_wpb_compact_to_shortcodes( $params['layout']['compact'] );
		}
	}

	$remove_paths    = ! empty( $params['remove_paths'] ) ? (array) $params['remove_paths'] : array();
	$text_updates    = ! empty( $params['text_updates'] ) ? (array) $params['text_updates'] : array();
	$append_html     = ! empty( $params['append_html'] ) ? (string) $params['append_html'] : '';
	$append_sections = ! empty( $params['append_sections'] ) ? (array) $params['append_sections'] : array();

	$builder_payload = nova_wpb_builder_payload_from_params( $params );
	if ( null !== $builder_payload ) {
		$validated_updates = nova_wpb_validate_builder_document(
			$builder_payload,
			$shortcodes,
			false !== strpos( $shortcodes, '[vc_' ) || nova_wpb_has_wpbakery_layout( $post )
		);
		if ( is_wp_error( $validated_updates ) ) {
			return $validated_updates;
		}
		$text_updates = $validated_updates;
	}

	// Auto-split single huge HTML section.
	if ( ! empty( $append_sections ) && function_exists( 'nova_wpb_expand_single_html_section_to_multiple' ) ) {
		$append_sections = nova_wpb_expand_single_html_section_to_multiple( $append_sections, get_the_title( $post ) );
	}

	if ( ! function_exists( 'nova_wpb_apply_transformations' ) ) {
		return new WP_Error(
			'missing_dependency',
			'Transformations not loaded (nova_wpb_apply_transformations). Ensure transformations.php is included.',
			array( 'status' => 500 )
		);
	}

	$shortcodes = nova_wpb_apply_transformations(
		$shortcodes,
		$remove_paths,
		$text_updates,
		$append_html,
		$append_sections
	);
	if ( is_wp_error( $shortcodes ) ) {
		return $shortcodes;
	}

	// Only normalize content the caller replaced or generated. Targeted edits must stay byte-local.
	$replace_layout = isset( $params['layout'] )
		&& is_array( $params['layout'] )
		&& ( array_key_exists( 'raw_shortcodes', $params['layout'] ) || array_key_exists( 'compact', $params['layout'] ) );
	if ( $replace_layout || '' !== $append_html || ! empty( $append_sections ) ) {
		$shortcodes = nova_wpb_normalize_empty_space_with_content( $shortcodes );
	}

	$postarr['post_content'] = $shortcodes;
	$post_result = wp_update_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $post_result ) ) {
		return $post_result;
	}
	if ( (string) get_post_field( 'post_content', $post_id ) !== $shortcodes ) {
		$rollback = array( 'ID' => $post_id );
		foreach ( array( 'post_title', 'post_name', 'post_status', 'post_excerpt', 'post_parent', 'post_content' ) as $field ) {
			if ( array_key_exists( $field, $postarr ) ) {
				$rollback[ $field ] = $post->$field;
			}
		}
		$rollback_result = wp_update_post( wp_slash( $rollback ), true );
		return new WP_Error(
			'nova_wpb_storage_mismatch',
			'WordPress did not store the WPBakery document byte-for-byte.',
			array( 'status' => 500, 'rollback_failed' => is_wp_error( $rollback_result ) )
		);
	}

	$meta_result = nova_wpb_apply_request_meta( $post_id, $params );
	if ( is_wp_error( $meta_result ) ) {
		return $meta_result;
	}
	if ( null !== $page_template ) {
		update_post_meta( $post_id, '_wp_page_template', $page_template );
	}

	// Ensure VC Design Options CSS remains consistent with final post_content.
	nova_wpb_regenerate_shortcodes_custom_css_meta( $post_id, $shortcodes );

	if ( ! empty( $params['publish_builder'] ) || false !== strpos( (string) $shortcodes, '[vc_' ) ) {
		update_post_meta( $post_id, '_wpb_vc_js_status', 'true' );
	}

	return new WP_REST_Response(
		array( 'id' => $post_id, 'wpbakery' => nova_wpb_build_meta_all_payload( get_post( $post_id ) ) ),
		200
	);
}
