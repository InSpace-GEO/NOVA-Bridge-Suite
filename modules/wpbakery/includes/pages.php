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

/**
 * GET /pages – list pages/posts.
 */
function nova_wpb_list_pages( $request ) {
	$per_page = min( max( 1, (int) $request->get_param( 'per_page' ) ), 50 );
	$page_num = max( 1, (int) $request->get_param( 'page' ) );
	$status   = $request->get_param( 'status' );

	$post_types = array( 'page', 'post' );
	if ( $request->get_param( 'post_type' ) ) {
		$post_types = (array) $request->get_param( 'post_type' );
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
			$items[] = array(
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
		$items[] = array(
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

	$layout_mode      = $request->get_param( 'layout_mode' ) ?: 'outline';
	$outline_style    = $request->get_param( 'outline_style' ) ?: 'summary';
	$include_meta     = nova_wpb_to_bool( $request->get_param( 'include_meta' ), true );
	$include_document = nova_wpb_to_bool( $request->get_param( 'include_document' ), false );
	$text_map_flag    = nova_wpb_to_bool( $request->get_param( 'text_map' ), false );

	$raw_shortcodes = (string) $post->post_content;

	$layout = array(
		'outline'     => array(),
		'has_builder' => nova_wpb_has_wpbakery_layout( $post ),
	);

	$compact       = array();
	$text_map_data = array();

	// Dependencies required only for outline/full.
	if ( in_array( $layout_mode, array( 'outline', 'full' ), true ) ) {
		if ( ! function_exists( 'nova_wpb_parse_shortcodes_to_compact' ) ) {
			return new WP_Error(
				'missing_dependency',
				'Layout parser not loaded (nova_wpb_parse_shortcodes_to_compact). Ensure layout.php is included.',
				array( 'status' => 500 )
			);
		}

		$compact = nova_wpb_parse_shortcodes_to_compact( $raw_shortcodes );

		if ( 'outline' === $layout_mode ) {
			if ( ! function_exists( 'nova_wpb_build_outline_from_compact' ) ) {
				return new WP_Error(
					'missing_dependency',
					'Outline builder not loaded (nova_wpb_build_outline_from_compact). Ensure layout.php is included.',
					array( 'status' => 500 )
				);
			}
			$layout['outline'] = nova_wpb_build_outline_from_compact( $compact, ( 'tree' === $outline_style ) );
		} else {
			$layout['compact'] = $compact;
		}

		if ( $text_map_flag ) {
			if ( ! function_exists( 'nova_wpb_build_text_map_from_compact' ) ) {
				return new WP_Error(
					'missing_dependency',
					'Text-map builder not loaded (nova_wpb_build_text_map_from_compact). Ensure layout.php is included.',
					array( 'status' => 500 )
				);
			}
			$text_map_data = nova_wpb_build_text_map_from_compact( $compact );
		}
	}

	$data = array(
		'id'           => $post->ID,
		'title'        => get_the_title( $post ),
		'slug'         => nova_wpb_get_slug_for_post( $post ),
		'status'       => $post->post_status,
		'modified_gmt' => get_post_modified_time( 'c', true, $post ),
		'permalink'    => get_permalink( $post ),
		'excerpt'      => $post->post_excerpt,
		'layout'       => $layout,
	);

	if ( $include_meta ) {
		$data['meta'] = array(
			'_wpb_vc_js_status'          => get_post_meta( $post->ID, '_wpb_vc_js_status', true ),
			'_wpb_shortcodes_custom_css' => get_post_meta( $post->ID, '_wpb_shortcodes_custom_css', true ),
			'_wpb_post_custom_css'       => get_post_meta( $post->ID, '_wpb_post_custom_css', true ),
		);
	}

	$data['document'] = $include_document ? $raw_shortcodes : null;

	if ( $text_map_flag ) {
		$data['text_map'] = $text_map_data;
	}

	return new WP_REST_Response( $data );
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

	$clone_mode  = ! empty( $params['source_page_id'] ) || ! empty( $params['source_page'] );
	$source_post = null;

	$post_type = isset( $params['post_type'] ) ? $params['post_type'] : 'page';
	if ( isset( $params['type'] ) && '' === trim( (string) $post_type ) ) {
		$post_type = $params['type'];
	} elseif ( isset( $params['type'] ) && ! isset( $params['post_type'] ) ) {
		$post_type = $params['type'];
	}

	if ( is_string( $post_type ) && 'service' === strtolower( $post_type ) ) {
		$post_type = 'page';
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
	}

	$base_shortcodes = '';
	$using_template  = false;

	if ( null !== $requested_content ) {
		$base_shortcodes = $requested_content;
	} elseif ( $clone_mode && $source_post ) {
		$base_shortcodes = (string) $source_post->post_content;
		$using_template  = true;
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
			true
		);
	}

	$postarr['post_content'] = $base_shortcodes;

	$post_id = wp_insert_post( $postarr, true );
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

	// Meta from request.
	$meta_updates = nova_wpb_prepare_meta_updates( $params );
	if ( ! empty( $meta_updates ) ) {
		foreach ( $meta_updates as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	// Force builder flag on clone layouts so WPBakery/theme CSS loads reliably.
	if ( $using_template || false !== strpos( (string) $base_shortcodes, '[vc_' ) ) {
		update_post_meta( $post_id, '_wpb_vc_js_status', 'true' );
	}

	// Append remaining sections + append_html.
	$shortcodes = (string) get_post_field( 'post_content', $post_id );

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

	// Defensive: prevent invalid container usage from being saved.
	$shortcodes = nova_wpb_normalize_empty_space_with_content( $shortcodes );

	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $shortcodes,
		)
	);

	// Ensure VC Design Options CSS remains consistent with final post_content.
	nova_wpb_regenerate_shortcodes_custom_css_meta( $post_id, $shortcodes );

	if ( ! empty( $params['publish_builder'] ) ) {
		update_post_meta( $post_id, '_wpb_vc_js_status', 'true' );
	}

	return new WP_REST_Response( array( 'id' => $post_id ), 201 );
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

	if ( count( $postarr ) > 1 ) {
		wp_update_post( $postarr );
	}

	// Meta.
	$meta_updates = nova_wpb_prepare_meta_updates( $params );
	if ( ! empty( $meta_updates ) ) {
		foreach ( $meta_updates as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	$shortcodes = (string) get_post_field( 'post_content', $post_id );

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

	// Defensive: prevent invalid container usage from being saved.
	$shortcodes = nova_wpb_normalize_empty_space_with_content( $shortcodes );

	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $shortcodes,
		)
	);

	// Ensure VC Design Options CSS remains consistent with final post_content.
	nova_wpb_regenerate_shortcodes_custom_css_meta( $post_id, $shortcodes );

	if ( ! empty( $params['publish_builder'] ) || false !== strpos( (string) $shortcodes, '[vc_' ) ) {
		update_post_meta( $post_id, '_wpb_vc_js_status', 'true' );
	}

	return new WP_REST_Response( array( 'id' => $post_id ), 200 );
}
