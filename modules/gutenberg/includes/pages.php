<?php
/**
 * NOVA Gutenberg Bridge – list / get / create / update handlers.
 *
 * /posts routes default to type "post" (blogs/articles).
 * /pages routes default to type "page".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------------
 * Permission callback  (mirrors nova_wpb_permission_check)
 * ------------------------------------------------------------------------ */

/**
 * REST permission callback.
 *
 * - For update routes where {id} is in the URL, checks edit_post for that ID.
 * - For create routes, checks edit_pages or edit_posts based on the "type" field.
 *
 * @param  WP_REST_Request $request
 * @return bool|WP_Error
 */
function nova_gut_permission_check( WP_REST_Request $request ) {
	// GET requests only need read capabilities — allows authenticated API
	// consumers (application passwords, GPT actions) to query content without
	// full edit permissions.
	if ( 'GET' === $request->get_method() ) {
		if ( $request->has_param( 'id' ) ) {
			$post_id = absint( $request->get_param( 'id' ) );
			if ( $post_id > 0 ) {
				return current_user_can( 'read_post', $post_id );
			}
		}
		return current_user_can( 'read' );
	}

	// Update route: check capability for the specific post.
	if ( $request->has_param( 'id' ) ) {
		$post_id = absint( $request->get_param( 'id' ) );
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post ) {
				return current_user_can( 'edit_post', $post->ID );
			}
		}
	}

	// Determine type from body or route.
	$type = nova_gut_infer_type( $request );

	if ( 'page' === $type ) {
		return current_user_can( 'edit_pages' );
	}

	return current_user_can( 'edit_posts' );
}

/* ---------------------------------------------------------------------------
 * Shared response builder
 * ------------------------------------------------------------------------ */

/**
 * Build the standard success response for a created or updated post.
 *
 * @param  int      $post_id           Post ID.
 * @param  int|null $featured_image_id Attachment ID of the featured image (may be null).
 * @param  array    $warnings          Non-fatal warning messages.
 * @param  int      $http_status       HTTP status code (201 for create, 200 for update).
 * @return WP_REST_Response
 */
function nova_gut_build_response( int $post_id, ?int $featured_image_id, array $warnings, int $http_status ): WP_REST_Response {
	$post = get_post( $post_id );

	$content_len = $post ? strlen( $post->post_content ) : 0;

	$data = array(
		'success'           => true,
		'post_id'           => $post_id,
		'type'              => $post ? $post->post_type : 'post',
		'status'            => $post ? $post->post_status : 'draft',
		'permalink'         => (string) get_permalink( $post_id ),
		'edit_link'         => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		'featured_image_id' => $featured_image_id,
		'content_length'    => $content_len,
		'has_blocks'        => $post ? ( false !== strpos( $post->post_content, '<!-- wp:' ) ) : false,
		'warnings'          => $warnings,
	);

	// For pages, include template info so callers can diagnose rendering issues.
	if ( $post && 'page' === $post->post_type ) {
		$template = get_page_template_slug( $post_id );
		$data['template'] = '' !== $template ? $template : 'default';

		// Warn if content is non-empty but the page template may not render it.
		if ( $content_len > 0 ) {
			$template_warning = nova_gut_check_page_template_renders_content( $post_id );
			if ( $template_warning ) {
				$warnings[]        = $template_warning;
				$data['warnings']  = $warnings;
			}
		}
	}

	return new WP_REST_Response( $data, $http_status );
}

/* ---------------------------------------------------------------------------
 * GET /pages – list / lookup by slug
 * ------------------------------------------------------------------------ */

/**
 * List posts/pages or look up by slug.
 *
 * @param  WP_REST_Request          $request
 * @return WP_REST_Response|WP_Error
 */
function nova_gut_list_pages( WP_REST_Request $request ) {
	$per_page = min( max( 1, (int) $request->get_param( 'per_page' ) ), 50 );
	$page_num = max( 1, (int) $request->get_param( 'page' ) );
	$status   = $request->get_param( 'status' );

	// Default post_type from route: /posts → post, /pages → page.
	$route_type = nova_gut_infer_type( $request );
	$post_types = array( $route_type );

	// Allow explicit override via query param.
	$pt_param = $request->get_param( 'post_type' );
	if ( is_string( $pt_param ) && '' !== $pt_param ) {
		$post_types = array( $pt_param );
	}

	// Slug lookup – return full page detail (useful as example/template fetch).
	$slug = $request->get_param( 'slug' );
	if ( is_string( $slug ) && '' !== trim( $slug ) ) {
		$slug = sanitize_title( trim( $slug ) );
		$args = array(
			'name'           => $slug,
			'post_type'      => $post_types,
			'post_status'    => 'any',
			'posts_per_page' => 1,
		);

		$query = new WP_Query( $args );
		$items = array();

		if ( $query->have_posts() ) {
			$post = $query->posts[0];
			$item = nova_gut_format_list_item( $post );

			// Include full content + meta so the result can serve as an example.
			$item['content']          = $post->post_content;
			$item['meta_title']       = get_post_meta( $post->ID, '_yoast_wpseo_title', true )
			                            ?: get_post_meta( $post->ID, 'rank_math_title', true );
			$item['meta_description'] = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true )
			                            ?: get_post_meta( $post->ID, 'rank_math_description', true );

			if ( 'post' === $post->post_type ) {
				$item['categories'] = wp_get_post_categories( $post->ID );
				$item['tags']       = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );
			}

			$items[] = $item;
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', count( $items ) );
		$response->header( 'X-WP-TotalPages', 1 );
		return $response;
	}

	// General listing.
	$args = array(
		'post_type'      => $post_types,
		'post_status'    => $status ? $status : 'any',
		'posts_per_page' => $per_page,
		'paged'          => $page_num,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);

	$search = $request->get_param( 'search' );
	if ( is_string( $search ) && '' !== $search ) {
		$args['s'] = $search;
	}

	$include_content = (bool) $request->get_param( 'include_content' );

	$query = new WP_Query( $args );
	$items = array();

	foreach ( $query->posts as $post ) {
		$item = nova_gut_format_list_item( $post );

		if ( $include_content ) {
			$item['content']          = $post->post_content;
			$item['meta_title']       = get_post_meta( $post->ID, '_yoast_wpseo_title', true )
			                            ?: get_post_meta( $post->ID, 'rank_math_title', true );
			$item['meta_description'] = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true )
			                            ?: get_post_meta( $post->ID, 'rank_math_description', true );

			if ( 'post' === $post->post_type ) {
				$item['categories'] = wp_get_post_categories( $post->ID );
				$item['tags']       = wp_get_post_tags( $post->ID, array( 'fields' => 'ids' ) );
			}
		}

		$items[] = $item;
	}

	$response = new WP_REST_Response( $items );
	$response->header( 'X-WP-Total', (int) $query->found_posts );
	$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );
	return $response;
}

/**
 * GET /pages/{id} – get a single post/page by ID.
 *
 * @param  WP_REST_Request          $request
 * @return WP_REST_Response|WP_Error
 */
function nova_gut_get_page( WP_REST_Request $request ) {
	$post_id = absint( $request->get_param( 'id' ) );
	$post    = get_post( $post_id );

	if ( ! $post || 'trash' === $post->post_status ) {
		return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
	}

	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return new WP_Error( 'invalid_post_type', 'This endpoint only supports posts and pages.', array( 'status' => 400 ) );
	}

	$data = nova_gut_format_list_item( $post );
	$data['content'] = $post->post_content;

	return new WP_REST_Response( $data );
}

/**
 * Format a post object for list/get responses.
 *
 * @param  WP_Post $post
 * @return array
 */
function nova_gut_format_list_item( WP_Post $post ): array {
	$thumb_id = get_post_thumbnail_id( $post->ID );

	return array(
		'id'                => $post->ID,
		'title'             => get_the_title( $post ),
		'slug'              => $post->post_name,
		'status'            => $post->post_status,
		'type'              => $post->post_type,
		'excerpt'           => $post->post_excerpt,
		'permalink'         => (string) get_permalink( $post ),
		'edit_link'         => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
		'modified_gmt'      => get_post_modified_time( 'c', true, $post ),
		'featured_image_id' => $thumb_id ? (int) $thumb_id : null,
	);
}

/* ---------------------------------------------------------------------------
 * POST /pages – create post or page
 * ------------------------------------------------------------------------ */

/**
 * Create a Gutenberg post or page.
 *
 * @param  WP_REST_Request          $request
 * @return WP_REST_Response|WP_Error
 */
function nova_gut_create_page( WP_REST_Request $request ) {
	// Debug (a): raw request body.
	nova_gut_debug_log( 'create_raw_body', '(len=' . strlen( (string) $request->get_body() ) . ') ' . substr( (string) $request->get_body(), 0, 200 ) );

	$params = nova_gut_get_request_json_params_safe( $request );
	if ( is_wp_error( $params ) ) {
		return $params;
	}

	// Debug (b): decoded params + content type check.
	nova_gut_debug_log( 'create_params_keys', array_keys( $params ) );
	nova_gut_debug_log( 'create_content_type', isset( $params['content'] ) ? gettype( $params['content'] ) : 'NOT SET' );

	$warnings = array();

	// --- Validate required fields ----------------------------------------

	// Type is inferred from the route (/posts → post, /pages → page)
	// but can be overridden via the "type" body field.
	$type = nova_gut_infer_type( $request );

	$title = isset( $params['title'] ) ? trim( (string) $params['title'] ) : '';
	if ( '' === $title ) {
		return new WP_Error(
			'missing_title',
			'The "title" field is required when creating content.',
			array( 'status' => 400 )
		);
	}

	// --- Resolve content -------------------------------------------------

	$content = nova_gut_resolve_content( $params );

	if ( '' === $content ) {
		$warnings[] = 'Content resolved to empty. The page/post will have no body. '
		            . 'Ensure "content" (string or {raw:...}) or "blocks" is provided in the request.';
	}

	// --- Capability check for publishing ---------------------------------

	$status = isset( $params['status'] ) ? (string) $params['status'] : 'draft';
	if ( ! in_array( $status, array( 'draft', 'publish', 'private', 'pending', 'future' ), true ) ) {
		return new WP_Error(
			'invalid_status',
			'The "status" field must be one of: draft, publish, private, pending, future.',
			array( 'status' => 400 )
		);
	}

	if ( 'publish' === $status ) {
		$publish_cap = 'page' === $type ? 'publish_pages' : 'publish_posts';
		if ( ! current_user_can( $publish_cap ) ) {
			return new WP_Error(
				'rest_cannot_publish',
				'You do not have permission to publish this content type.',
				array( 'status' => 403 )
			);
		}
	}

	// --- Build post array ------------------------------------------------

	$postarr = array(
		'post_title'   => wp_strip_all_tags( $title ),
		'post_type'    => $type,
		'post_status'  => $status,
		'post_content' => $content,
		'post_excerpt' => isset( $params['excerpt'] ) ? (string) $params['excerpt'] : '',
	);

	if ( isset( $params['slug'] ) && '' !== trim( (string) $params['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( $params['slug'] );
	}

	// Author support — accepts a WordPress user ID.
	if ( isset( $params['author'] ) ) {
		$author_id = absint( $params['author'] );
		if ( $author_id > 0 && get_userdata( $author_id ) ) {
			$postarr['post_author'] = $author_id;
		} else {
			$warnings[] = 'Invalid author ID ' . $author_id . '. Falling back to current user.';
		}
	}

	// Parent page support.
	if ( 'page' === $type && isset( $params['parent_id'] ) ) {
		$postarr['post_parent'] = absint( $params['parent_id'] );
	}

	// Page template support.
	if ( 'page' === $type && isset( $params['template'] ) ) {
		$postarr['page_template'] = sanitize_text_field( $params['template'] );
	}

	// When cloning from a source page and no explicit template was given,
	// copy the source page's template so the new page matches its layout
	// (e.g. a template without wp:post-title to avoid duplicate titles).
	if ( 'page' === $type && ! isset( $params['template'] ) && isset( $params['source_page_id'] ) ) {
		$src_id       = absint( $params['source_page_id'] );
		$src_template = $src_id > 0 ? get_page_template_slug( $src_id ) : '';
		if ( '' !== $src_template ) {
			$postarr['page_template'] = $src_template;
		}
	}

	// --- Insert post -----------------------------------------------------

	// Debug (c): the $postarr about to be inserted.
	nova_gut_debug_log( 'create_postarr', array(
		'post_title_len'   => strlen( $postarr['post_title'] ),
		'post_content_len' => strlen( $postarr['post_content'] ),
		'post_type'        => $postarr['post_type'],
		'post_status'      => $postarr['post_status'],
		'content_preview'  => substr( $postarr['post_content'], 0, 300 ),
	) );

	$post_id = wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	// Debug (d): verify stored content immediately after insert.
	$saved_post = get_post( $post_id );
	nova_gut_debug_log( 'create_saved', array(
		'post_id'          => $post_id,
		'post_content_len' => $saved_post ? strlen( $saved_post->post_content ) : -1,
	) );

	// --- Taxonomies ------------------------------------------------------

	nova_gut_set_taxonomies( $post_id, $type, $params );

	// --- SEO meta --------------------------------------------------------

	$meta_updates = nova_gut_prepare_meta_updates( $params );
	if ( ! empty( $meta_updates ) ) {
		foreach ( $meta_updates as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	// --- Featured image --------------------------------------------------

	$featured_image_id = null;

	if ( isset( $params['featured_image'] ) && is_array( $params['featured_image'] ) ) {
		$img_result        = nova_gut_process_featured_image( $post_id, $params['featured_image'] );
		$featured_image_id = $img_result['featured_image_id'];

		if ( ! empty( $img_result['warning'] ) ) {
			$warnings[] = $img_result['warning'];
		}
	}

	return nova_gut_build_response( $post_id, $featured_image_id, $warnings, 201 );
}

/* ---------------------------------------------------------------------------
 * PUT|PATCH /pages/{id} – update post or page
 * ------------------------------------------------------------------------ */

/**
 * Update an existing Gutenberg post or page.
 *
 * @param  WP_REST_Request          $request
 * @return WP_REST_Response|WP_Error
 */
function nova_gut_update_page( WP_REST_Request $request ) {
	$post_id = absint( $request->get_param( 'id' ) );
	$post    = get_post( $post_id );

	if ( ! $post || 'trash' === $post->post_status ) {
		return new WP_Error(
			'not_found',
			'Post not found.',
			array( 'status' => 404 )
		);
	}

	if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return new WP_Error(
			'invalid_post_type',
			'This endpoint only supports posts and pages.',
			array( 'status' => 400 )
		);
	}

	$params = nova_gut_get_request_json_params_safe( $request );
	if ( is_wp_error( $params ) ) {
		return $params;
	}

	$warnings = array();
	$postarr  = array( 'ID' => $post_id );

	// --- Optional field updates ------------------------------------------

	if ( isset( $params['title'] ) ) {
		$postarr['post_title'] = wp_strip_all_tags( $params['title'] );
	}

	if ( isset( $params['slug'] ) && '' !== trim( (string) $params['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( $params['slug'] );
	}

	if ( isset( $params['status'] ) ) {
		$status = (string) $params['status'];
		if ( ! in_array( $status, array( 'draft', 'publish', 'private', 'pending', 'future' ), true ) ) {
			return new WP_Error(
				'invalid_status',
				'The "status" field must be one of: draft, publish, private, pending, future.',
				array( 'status' => 400 )
			);
		}

		if ( 'publish' === $status && 'publish' !== $post->post_status ) {
			$publish_cap = 'page' === $post->post_type ? 'publish_pages' : 'publish_posts';
			if ( ! current_user_can( $publish_cap ) ) {
				return new WP_Error(
					'rest_cannot_publish',
					'You do not have permission to publish this content type.',
					array( 'status' => 403 )
				);
			}
		}

		$postarr['post_status'] = $status;
	}

	if ( isset( $params['excerpt'] ) ) {
		$postarr['post_excerpt'] = (string) $params['excerpt'];
	}

	// Author support — accepts a WordPress user ID.
	if ( isset( $params['author'] ) ) {
		$author_id = absint( $params['author'] );
		if ( $author_id > 0 && get_userdata( $author_id ) ) {
			$postarr['post_author'] = $author_id;
		} else {
			$warnings[] = 'Invalid author ID ' . $author_id . '. Falling back to current author.';
		}
	}

	// Parent page support.
	if ( 'page' === $post->post_type && isset( $params['parent_id'] ) ) {
		$postarr['post_parent'] = absint( $params['parent_id'] );
	}

	// Page template support.
	if ( 'page' === $post->post_type && isset( $params['template'] ) ) {
		$postarr['page_template'] = sanitize_text_field( $params['template'] );
	}

	// Copy source page template when cloning and no explicit template given.
	if ( 'page' === $post->post_type && ! isset( $params['template'] ) && isset( $params['source_page_id'] ) ) {
		$src_id       = absint( $params['source_page_id'] );
		$src_template = $src_id > 0 ? get_page_template_slug( $src_id ) : '';
		if ( '' !== $src_template ) {
			$postarr['page_template'] = $src_template;
		}
	}

	// --- Content ---------------------------------------------------------

	if ( isset( $params['content'] ) || isset( $params['blocks'] ) || isset( $params['source_page_id'] ) || isset( $params['append_html'] ) ) {
		$content                = nova_gut_resolve_content( $params );
		$postarr['post_content'] = $content;
	}

	// --- Update post -----------------------------------------------------

	if ( count( $postarr ) > 1 ) {
		// Debug (c): the $postarr about to be updated.
		nova_gut_debug_log( 'update_postarr', array(
			'post_id'          => $post_id,
			'has_content'      => isset( $postarr['post_content'] ),
			'post_content_len' => isset( $postarr['post_content'] ) ? strlen( $postarr['post_content'] ) : 0,
			'content_preview'  => isset( $postarr['post_content'] ) ? substr( $postarr['post_content'], 0, 300 ) : '(not set)',
		) );

		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Debug (d): verify stored content immediately after update.
		$saved_post = get_post( $post_id );
		nova_gut_debug_log( 'update_saved', array(
			'post_id'          => $post_id,
			'post_content_len' => $saved_post ? strlen( $saved_post->post_content ) : -1,
		) );
	}

	// --- Taxonomies ------------------------------------------------------

	$type = $post->post_type;

	// Allow callers to pass type to override taxonomy assignment scope (not the post_type itself).
	if ( isset( $params['type'] ) ) {
		$type = $params['type'];
	}

	nova_gut_set_taxonomies( $post_id, $type, $params );

	// --- SEO meta --------------------------------------------------------

	$meta_updates = nova_gut_prepare_meta_updates( $params );
	if ( ! empty( $meta_updates ) ) {
		foreach ( $meta_updates as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	// --- Featured image --------------------------------------------------

	$featured_image_id = null;

	if ( isset( $params['featured_image'] ) && is_array( $params['featured_image'] ) ) {
		$img_result        = nova_gut_process_featured_image( $post_id, $params['featured_image'] );
		$featured_image_id = $img_result['featured_image_id'];

		if ( ! empty( $img_result['warning'] ) ) {
			$warnings[] = $img_result['warning'];
		}
	}

	// If no new featured image was processed, return the existing one.
	if ( null === $featured_image_id ) {
		$existing_thumb = get_post_thumbnail_id( $post_id );
		if ( $existing_thumb ) {
			$featured_image_id = (int) $existing_thumb;
		}
	}

	return nova_gut_build_response( $post_id, $featured_image_id, $warnings, 200 );
}

/* ---------------------------------------------------------------------------
 * Internal helpers
 * ------------------------------------------------------------------------ */

/**
 * Infer post type from the request body or the route path.
 *
 * /posts  → "post"  (blogs/articles)
 * /pages  → "page"
 *
 * An explicit "type" field in the JSON body always wins.
 *
 * @param  WP_REST_Request $request
 * @return string          "post" or "page".
 */
function nova_gut_infer_type( WP_REST_Request $request ): string {
	// Explicit body field takes priority.
	$params = $request->get_json_params();
	if ( is_array( $params ) && isset( $params['type'] ) ) {
		$type = strtolower( trim( (string) $params['type'] ) );
		if ( in_array( $type, array( 'post', 'page' ), true ) ) {
			return $type;
		}
	}

	// Fall back to route path.
	$route = $request->get_route();
	if ( false !== strpos( $route, '/pages' ) ) {
		return 'page';
	}

	return 'post';
}

/**
 * Resolve post_content from the payload.
 *
 * Priority:
 *   1. "content" (string or object)           → direct content
 *   2. "blocks" (array)                       → structured blocks
 *   3. "source_page_id" + "append_html"       → merge: copy layout, replace text, append rest
 *   4. "source_page_id" alone                 → clone content from existing page
 *   5. "append_html" alone                    → use as content (wrapped in blocks)
 *
 * @param  array  $params  Decoded request payload.
 * @return string           Post content (Gutenberg markup).
 */
function nova_gut_resolve_content( array $params ): string {
	$content = '';

	// Prefer content field (supports both string and object formats).
	if ( isset( $params['content'] ) ) {
		$content = nova_gut_normalize_content_param( $params['content'] );
	}

	// Fall back to structured blocks array.
	if ( '' === $content && isset( $params['blocks'] ) && is_array( $params['blocks'] ) && ! empty( $params['blocks'] ) ) {
		$content = nova_gut_serialize_blocks( $params['blocks'] );
	}

	// Source page + append_html: merge layout from source with new text content.
	if ( '' === $content && isset( $params['source_page_id'] ) ) {
		$source_id   = absint( $params['source_page_id'] );
		$source_post = $source_id > 0 ? get_post( $source_id ) : null;

		if ( $source_post && in_array( $source_post->post_type, array( 'post', 'page' ), true ) ) {
			$source_content = $source_post->post_content;
			$append_html    = isset( $params['append_html'] ) ? trim( (string) $params['append_html'] ) : '';

			nova_gut_debug_log( 'resolve_source_page', array(
				'source_page_id'    => $source_id,
				'source_content_len' => strlen( $source_content ),
				'append_html_len'   => strlen( $append_html ),
			) );

			if ( '' !== $append_html ) {
				// Smart merge: copy layout from source, replace text blocks with new content.
				$title   = isset( $params['title'] ) ? trim( (string) $params['title'] ) : '';
				$content = nova_gut_merge_source_with_content( $source_content, $append_html, $title );
			} else {
				// No append_html → clone source page content as-is.
				$content = $source_content;
			}
		} else {
			nova_gut_debug_log( 'resolve_source_page_not_found', $source_id );
		}
	}

	// append_html without source_page_id → use as direct content.
	if ( '' === $content && isset( $params['append_html'] ) ) {
		$append = trim( (string) $params['append_html'] );
		if ( '' !== $append ) {
			$content = nova_gut_ensure_block_markup( $append );
		}
	}

	// Ensure raw HTML is wrapped in proper Gutenberg block markup.
	if ( '' !== $content ) {
		$content = nova_gut_ensure_block_markup( $content );

		// Convert FAQ sections (heading + Q/A pairs) into collapsible core/details blocks.
		$content = nova_gut_convert_faq_to_details( $content );

		// Apply image replacements if provided (swap placeholder images with WP media IDs).
		if ( ! empty( $params['image_replacements'] ) && is_array( $params['image_replacements'] ) ) {
			$content = nova_gut_apply_image_replacements( $content, $params['image_replacements'] );
		}
	}

	nova_gut_debug_log( 'resolve_content_result', $content );

	return $content;
}

/* ---------------------------------------------------------------------------
 * Source page + new content merge
 * ------------------------------------------------------------------------ */

/**
 * Merge source page layout with new text content.
 *
 * Copies the structural layout (hero, images, spacers, CTA, etc.) from the
 * source page and replaces text-bearing blocks (paragraphs, headings h2+,
 * lists, tables) with elements from $append_html, in document order.
 *
 * Any remaining $append_html elements that don't fit into existing text
 * positions are appended before the page's footer section (FAQ, CTA, scripts).
 *
 * @param  string $source_content  Source page's post_content (Gutenberg markup).
 * @param  string $append_html     New text content (raw HTML, may have block markup).
 * @param  string $title           New page title (replaces H1 headings in source layout).
 * @return string                   Merged content (Gutenberg markup).
 */
function nova_gut_merge_source_with_content( string $source_content, string $append_html, string $title = '' ): string {
	// Wrap new content in block markup and parse into blocks.
	$new_markup = nova_gut_ensure_block_markup( $append_html );

	if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
		// WP < 5.0 fallback: simple concatenation.
		return rtrim( $source_content ) . "\n\n" . $new_markup;
	}

	$source_blocks = parse_blocks( $source_content );

	// Strip FAQ blocks from source — new content provides its own FAQ.
	// Includes core/details because previous bridge runs may have already
	// converted plugin FAQ blocks into native details blocks on the source page.
	$faq_block_names = array( 'powerkraut/faq', 'yoast/faq-block', 'rank-math/faq-block', 'core/details' );
	$source_blocks   = array_values( array_filter( $source_blocks, function ( $b ) use ( $faq_block_names ) {
		return ! in_array( $b['blockName'] ?? null, $faq_block_names, true );
	} ) );

	// Replace H1 headings in the source with the new page title.
	if ( '' !== $title ) {
		$source_blocks = nova_gut_replace_h1_text( $source_blocks, $title );
		// Also replace the heading inside cover/hero blocks (often H2, not H1).
		$source_blocks = nova_gut_replace_cover_title( $source_blocks, $title );
	}

	$new_blocks    = array_values( array_filter(
		parse_blocks( $new_markup ),
		function ( $b ) {
			return null !== ( $b['blockName'] ?? null );
		}
	) );

	nova_gut_debug_log( 'merge_new_blocks_before_dedup', array(
		'count' => count( $new_blocks ),
		'types' => array_map( function ( $b ) {
			$name = $b['blockName'] ?? '(null)';
			$txt  = substr( trim( strip_tags( $b['innerHTML'] ?? '' ) ), 0, 60 );
			return $name . ': ' . $txt;
		}, $new_blocks ),
	) );

	// Deduplicate: upstream may concatenate overlapping content (e.g. top_content + raw_html + bottom_content).
	$new_blocks = nova_gut_dedup_content_blocks( $new_blocks );

	nova_gut_debug_log( 'merge_new_blocks_after_dedup', array(
		'count' => count( $new_blocks ),
	) );

	// Strip H1 headings from new blocks — the page title is set via post_title
	// and the theme renders it. An H1 in content would duplicate it.
	$new_blocks = array_values( array_filter( $new_blocks, function ( $b ) {
		if ( 'core/heading' === ( $b['blockName'] ?? '' ) ) {
			return 1 !== ( $b['attrs']['level'] ?? 2 );
		}
		return true;
	} ) );

	if ( empty( $new_blocks ) ) {
		return $source_content;
	}

	// --- Split out FAQ section before walk-and-replace can consume it -----
	// The FAQ heading + its Q/A sub-headings (H3) and paragraphs must stay
	// together and always be appended as top-level blocks — never placed
	// inside a layout container where they'd be cut off.
	$faq_blocks        = array();
	$content_blocks    = array();
	$in_faq            = false;
	$faq_heading_level = 0;

	foreach ( $new_blocks as $nb ) {
		$nb_name = $nb['blockName'] ?? null;

		// Detect the start of an FAQ section: a heading with FAQ keywords.
		if ( ! $in_faq && 'core/heading' === $nb_name && nova_gut_is_faq_section_heading( $nb ) ) {
			$in_faq            = true;
			$faq_heading_level = $nb['attrs']['level'] ?? 2;
			$faq_blocks[]      = $nb;
			continue;
		}

		if ( $in_faq ) {
			// A heading at the same or higher level as the FAQ heading ends the
			// section (e.g. H2 "Eiken vloeren op maat…" after an H2 FAQ section).
			// Sub-headings (H3 questions) stay inside the FAQ.
			if ( 'core/heading' === $nb_name ) {
				$level = $nb['attrs']['level'] ?? 2;
				if ( $level <= $faq_heading_level ) {
					$in_faq           = false;
					$content_blocks[] = $nb;
					continue;
				}
			}
			$faq_blocks[] = $nb;
		} else {
			$content_blocks[] = $nb;
		}
	}

	// --- Protect footer/CTA from walk-and-replace ---------------------------
	// Split source blocks into content (replaceable) and footer (preserved).
	// This prevents walk-and-replace from putting new text into the CTA section.
	$footer_pos  = nova_gut_find_footer_position( $source_blocks );
	$content_src = array_slice( $source_blocks, 0, $footer_pos );
	$footer_src  = array_slice( $source_blocks, $footer_pos );

	nova_gut_debug_log( 'merge_start', array(
		'source_block_count'  => count( $source_blocks ),
		'content_src_count'   => count( $content_src ),
		'footer_src_count'    => count( $footer_src ),
		'content_block_count' => count( $content_blocks ),
		'faq_block_count'     => count( $faq_blocks ),
	) );

	// Walk only the content portion of source, replacing text blocks.
	$idx             = 0;
	$merged_content  = nova_gut_walk_and_replace( $content_src, $content_blocks, $idx );

	// Collect blocks to append between content and footer.
	$to_append = array();

	// Leftover content blocks that didn't fit into source text slots.
	if ( $idx < count( $content_blocks ) ) {
		$remaining = array_slice( $content_blocks, $idx );

		// Remove sections whose heading was already placed via replacement.
		$placed_headings = array();
		for ( $j = 0; $j < $idx; $j++ ) {
			if ( 'core/heading' === ( $content_blocks[ $j ]['blockName'] ?? '' ) ) {
				$htxt = strtolower( trim( strip_tags( $content_blocks[ $j ]['innerHTML'] ?? '' ) ) );
				if ( '' !== $htxt ) {
					$placed_headings[ $htxt ] = true;
				}
			}
		}

		$deduped = array();
		$skip    = false;
		foreach ( $remaining as $rb ) {
			$rn = $rb['blockName'] ?? null;
			if ( 'core/heading' === $rn ) {
				$rtxt = strtolower( trim( strip_tags( $rb['innerHTML'] ?? '' ) ) );
				$skip = isset( $placed_headings[ $rtxt ] );
				if ( $skip ) {
					continue;
				}
			}
			if ( $skip && in_array( $rn, array( 'core/paragraph', 'core/list', 'core/table' ), true ) ) {
				continue;
			}
			$skip     = false;
			$deduped[] = $rb;
		}

		$to_append = $deduped;
	}

	// Always append the FAQ section (it was never fed into walk-and-replace).
	if ( ! empty( $faq_blocks ) ) {
		$to_append = array_merge( $to_append, $faq_blocks );
	}

	// Reassemble: content → spacer + appended blocks → footer (untouched).
	$merged_blocks = $merged_content;

	if ( ! empty( $to_append ) ) {
		$spacer = array(
			'blockName'    => 'core/spacer',
			'attrs'        => array( 'height' => '44px' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<div style="height:44px" aria-hidden="true" class="wp-block-spacer"></div>',
			'innerContent' => array( '<div style="height:44px" aria-hidden="true" class="wp-block-spacer"></div>' ),
		);
		$merged_blocks = array_merge( $merged_blocks, array( $spacer ), $to_append );
	}

	// Append the footer section (CTA, scripts, spacers) unchanged.
	$merged_blocks = array_merge( $merged_blocks, $footer_src );

	// Clean up: remove empty paragraphs, collapse consecutive spacers,
	// strip trailing whitespace blocks.
	$merged_blocks = nova_gut_cleanup_merged_blocks( $merged_blocks );

	nova_gut_debug_log( 'merge_result', array(
		'replaced_count' => $idx,
		'appended_count' => count( $to_append ),
		'faq_count'      => count( $faq_blocks ),
		'footer_count'   => count( $footer_src ),
	) );

	return serialize_blocks( $merged_blocks );
}

/**
 * Clean up merged blocks: strip empty paragraphs, collapse spacers, trim tail.
 *
 * Applied after merge assembly to remove source-layout artifacts that create
 * unintended whitespace or visual gaps in the final output.
 *
 * Only processes top-level blocks to avoid breaking innerContent mappings
 * inside container blocks (groups, columns, covers).
 *
 * @param  array $blocks  Merged block array.
 * @return array           Cleaned block array.
 */
function nova_gut_cleanup_merged_blocks( array $blocks ): array {
	$cleaned   = array();
	$prev_name = null;

	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;

		// Skip whitespace-only freeform blocks.
		if ( null === $name ) {
			$html = trim( $block['innerHTML'] ?? '' );
			if ( '' === $html ) {
				continue;
			}
		}

		// Skip empty paragraphs (no visible text content).
		if ( 'core/paragraph' === $name ) {
			$text = trim( strip_tags( $block['innerHTML'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
		}

		// Collapse consecutive spacers — keep only the first.
		if ( 'core/spacer' === $name && 'core/spacer' === $prev_name ) {
			continue;
		}

		$cleaned[] = $block;
		$prev_name = $name;
	}

	// Strip trailing spacers and empty blocks.
	while ( ! empty( $cleaned ) ) {
		$last      = end( $cleaned );
		$last_name = $last['blockName'] ?? null;

		if ( 'core/spacer' === $last_name ) {
			array_pop( $cleaned );
			continue;
		}
		if ( null === $last_name && '' === trim( $last['innerHTML'] ?? '' ) ) {
			array_pop( $cleaned );
			continue;
		}
		if ( 'core/paragraph' === $last_name && '' === trim( strip_tags( $last['innerHTML'] ?? '' ) ) ) {
			array_pop( $cleaned );
			continue;
		}
		break;
	}

	return array_values( $cleaned );
}

/**
 * Recursively walk source blocks and replace text blocks with new content.
 *
 * Structural blocks (groups, covers, columns, spacers, images, custom blocks)
 * are kept as-is. Text blocks (paragraphs, headings h2+, lists, tables) are
 * replaced in order with blocks from $new_blocks.
 *
 * The function recurses into container blocks (groups, covers, columns) to find
 * nested text blocks, which preserves the layout nesting structure.
 *
 * @param  array $blocks     Source blocks at the current nesting level.
 * @param  array $new_blocks Replacement blocks (flat queue).
 * @param  int   &$idx       Current position in the new_blocks queue (by reference).
 * @return array              Modified blocks.
 */
function nova_gut_walk_and_replace( array $blocks, array $new_blocks, int &$idx ): array {
	for ( $i = 0, $len = count( $blocks ); $i < $len; $i++ ) {
		$name = $blocks[ $i ]['blockName'] ?? null;

		// Whitespace / freeform blocks → keep.
		if ( null === $name ) {
			continue;
		}

		// Empty paragraphs → keep in place (preserves innerContent mapping for parent).
		if ( 'core/paragraph' === $name && '' === trim( strip_tags( $blocks[ $i ]['innerHTML'] ?? '' ) ) ) {
			continue;
		}

		// Replaceable text block → swap with next new block, or mark for
		// removal if all new content has been placed (prevents leftover
		// source text from leaking into the output).
		if ( nova_gut_is_replaceable_block( $blocks[ $i ] ) ) {
			if ( $idx < count( $new_blocks ) ) {
				$blocks[ $i ] = $new_blocks[ $idx ];
				$idx++;
			} else {
				$blocks[ $i ] = null; // Mark for removal.
			}
			continue;
		}

		// Cover blocks are hero/header sections — their inner text (date,
		// title) is structural layout, not body-content slots. Skip them
		// so that body content doesn't get consumed by the hero.
		if ( 'core/cover' === $name ) {
			continue;
		}

		// Source-page images and galleries are content-specific and should
		// not carry over. Hero/banner images (inside covers, already skipped
		// above) and CTA images (in footer_src, not processed here) are safe.
		if ( in_array( $name, array( 'core/image', 'core/gallery' ), true ) ) {
			$blocks[ $i ] = null;
			continue;
		}

		// Container blocks that hold ONLY media (images, covers) and no text
		// are source-specific image sections (e.g. a 3-column photo row).
		// Remove them so source imagery doesn't leak into the new page.
		if ( ! empty( $blocks[ $i ]['innerBlocks'] ) && 0 === nova_gut_count_text_blocks( $blocks[ $i ]['innerBlocks'] ) ) {
			if ( nova_gut_has_media_blocks( $blocks[ $i ]['innerBlocks'] ) && ! nova_gut_has_button_blocks( $blocks[ $i ]['innerBlocks'] ) ) {
				$blocks[ $i ] = null;
				continue;
			}
		}

		// Containers with custom/third-party blocks (e.g. dtcmedia/grid-block)
		// are source-page-specific widgets. Remove the entire container so
		// source content (model selectors, custom widgets) doesn't leak.
		if ( ! empty( $blocks[ $i ]['innerBlocks'] ) && nova_gut_has_custom_blocks( $blocks[ $i ]['innerBlocks'] ) ) {
			$blocks[ $i ] = null;
			continue;
		}

		// Container blocks → recurse into inner blocks.
		if ( ! empty( $blocks[ $i ]['innerBlocks'] ) ) {
			$old_count = count( $blocks[ $i ]['innerBlocks'] );
			$blocks[ $i ]['innerBlocks'] = nova_gut_walk_and_replace(
				$blocks[ $i ]['innerBlocks'],
				$new_blocks,
				$idx
			);
			// If inner blocks were removed, rebuild innerContent to keep
			// the null-slot mapping in sync with the remaining innerBlocks.
			$new_count = count( $blocks[ $i ]['innerBlocks'] );
			if ( $new_count < $old_count && ! empty( $blocks[ $i ]['innerContent'] ) ) {
				$blocks[ $i ]['innerContent'] = nova_gut_rebuild_inner_content(
					$blocks[ $i ]['innerContent'],
					$new_count
				);
			}
		}
	}

	// Filter out blocks marked for removal (null entries).
	return array_values( array_filter( $blocks, function ( $b ) {
		return null !== $b;
	} ) );
}

/**
 * Rebuild a container block's innerContent after inner blocks were removed.
 *
 * WordPress innerContent uses null entries as placeholders for innerBlocks
 * (1:1 positional mapping). When inner blocks are removed, the extra null
 * slots must be collapsed to keep serialization correct.
 *
 * @param  array $inner_content  Original innerContent array.
 * @param  int   $target_count   New number of innerBlocks.
 * @return array                  Adjusted innerContent.
 */
function nova_gut_rebuild_inner_content( array $inner_content, int $target_count ): array {
	$result    = array();
	$null_seen = 0;

	foreach ( $inner_content as $entry ) {
		if ( null === $entry ) {
			$null_seen++;
			if ( $null_seen <= $target_count ) {
				$result[] = null;
			}
			// Else: skip the extra null slot (block was removed).
		} else {
			// String entry (HTML between blocks). If the previous action
			// skipped a null, also skip pure-whitespace separators to avoid
			// double newlines in the output.
			if ( $null_seen > $target_count && is_string( $entry ) && '' === trim( $entry ) ) {
				continue;
			}
			$result[] = $entry;
		}
	}

	return $result;
}

/**
 * Determine if a block is a "text" block that should be replaced with new content.
 *
 * Replaceable: paragraphs (non-empty), headings (h2+), lists, tables.
 * NOT replaceable: h1 headings (page title), buttons, images, spacers, groups,
 * covers, columns, custom/third-party blocks, empty paragraphs.
 *
 * @param  array $block  Parsed block array.
 * @return bool           True if the block should be replaced.
 */
function nova_gut_is_replaceable_block( array $block ): bool {
	$name = $block['blockName'] ?? '';
	$html = $block['innerHTML'] ?? '';

	// Paragraph with actual text content.
	if ( 'core/paragraph' === $name ) {
		return '' !== trim( strip_tags( $html ) );
	}

	// Heading at level 2+ (level 1 is the page title in the hero).
	if ( 'core/heading' === $name ) {
		$level = $block['attrs']['level'] ?? 2;
		return $level >= 2;
	}

	// Lists.
	if ( 'core/list' === $name ) {
		return true;
	}

	// Tables.
	if ( 'core/table' === $name ) {
		return true;
	}

	return false;
}

/**
 * Find the position to insert appended content (before the "footer" section).
 *
 * Walks backwards from the end of the block array looking for structural
 * footer blocks (FAQ, HTML scripts, full-width CTA groups, trailing spacers).
 * Returns the index where appended content should be inserted.
 *
 * @param  array $blocks  Top-level blocks.
 * @return int             Insert index.
 */
function nova_gut_find_footer_position( array $blocks ): int {
	$footer_start = count( $blocks );

	for ( $i = count( $blocks ) - 1; $i >= 0; $i-- ) {
		$name = $blocks[ $i ]['blockName'] ?? null;

		// Skip trailing whitespace / freeform.
		if ( null === $name ) {
			$footer_start = $i;
			continue;
		}

		// Spacers at the end → part of footer.
		if ( 'core/spacer' === $name ) {
			$footer_start = $i;
			continue;
		}

		// Empty paragraphs at the end → part of footer.
		if ( 'core/paragraph' === $name && '' === trim( strip_tags( $blocks[ $i ]['innerHTML'] ?? '' ) ) ) {
			$footer_start = $i;
			continue;
		}

		// Known footer block types.
		if ( in_array( $name, array( 'core/html', 'powerkraut/faq' ), true ) ) {
			$footer_start = $i;
			continue;
		}

		// Full-width group → likely CTA section, but only if it has very
		// few text blocks. Content sections are also full-width in block
		// themes, so we must not treat those as footer.
		if ( 'core/group' === $name ) {
			$align = $blocks[ $i ]['attrs']['align'] ?? '';
			if ( 'full' === $align ) {
				$inner = $blocks[ $i ]['innerBlocks'] ?? array();
				$text_count = nova_gut_count_text_blocks( $inner );
				if ( $text_count > 2 ) {
					// This group has substantial text content → it's a content
					// section, not a footer CTA. Stop here.
					break;
				}
				// Groups containing images/galleries WITHOUT buttons are content
				// sections (e.g. a 3-image row), not footer/CTA.
				// Groups with BOTH media and buttons are CTA sections → footer.
				if ( nova_gut_has_media_blocks( $inner ) && ! nova_gut_has_button_blocks( $inner ) ) {
					break;
				}
				$footer_start = $i;
				continue;
			}
		}

		// Hit a content block → footer starts after this.
		break;
	}

	return $footer_start;
}

/**
 * Recursively count text-bearing blocks inside a block tree.
 *
 * Counts paragraphs (non-empty), headings, and lists. Used to distinguish
 * CTA groups (1-2 text blocks) from content groups (many text blocks).
 *
 * @param  array $blocks  Inner blocks.
 * @return int             Number of text-bearing blocks.
 */
function nova_gut_count_text_blocks( array $blocks ): int {
	$count = 0;
	foreach ( $blocks as $b ) {
		$name = $b['blockName'] ?? '';
		if ( in_array( $name, array( 'core/paragraph', 'core/heading', 'core/list' ), true ) ) {
			if ( '' !== trim( strip_tags( $b['innerHTML'] ?? '' ) ) ) {
				$count++;
			}
		}
		if ( ! empty( $b['innerBlocks'] ) ) {
			$count += nova_gut_count_text_blocks( $b['innerBlocks'] );
		}
	}
	return $count;
}

/**
 * Recursively check whether a block tree contains media blocks.
 *
 * Detects core/image, core/gallery, core/cover, core/media-text,
 * and <img> tags inside container blocks. Used to distinguish image-row
 * groups (content) from text-only CTA groups (footer) in
 * nova_gut_find_footer_position().
 *
 * @param  array $blocks  Inner blocks.
 * @return bool            True if the tree contains at least one media block.
 */
function nova_gut_has_media_blocks( array $blocks ): bool {
	$media_names = array( 'core/image', 'core/gallery', 'core/media-text', 'core/cover' );
	foreach ( $blocks as $b ) {
		$name = $b['blockName'] ?? '';
		if ( in_array( $name, $media_names, true ) ) {
			return true;
		}
		// Check innerHTML for <img> tags inside container blocks.
		if ( in_array( $name, array( 'core/html', 'core/columns', 'core/group', 'core/column' ), true ) ) {
			if ( false !== strpos( $b['innerHTML'] ?? '', '<img' ) ) {
				return true;
			}
		}
		if ( ! empty( $b['innerBlocks'] ) ) {
			if ( nova_gut_has_media_blocks( $b['innerBlocks'] ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Recursively check whether a block tree contains button blocks.
 *
 * Used to distinguish CTA sections (buttons + optional images) from
 * pure image galleries. CTA sections should remain in the footer.
 *
 * @param  array $blocks  Inner blocks.
 * @return bool            True if the tree contains at least one button block.
 */
function nova_gut_has_button_blocks( array $blocks ): bool {
	foreach ( $blocks as $b ) {
		$name = $b['blockName'] ?? '';
		if ( in_array( $name, array( 'core/buttons', 'core/button' ), true ) ) {
			return true;
		}
		if ( ! empty( $b['innerBlocks'] ) ) {
			if ( nova_gut_has_button_blocks( $b['innerBlocks'] ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Check if a block list contains non-core (custom/third-party) blocks.
 *
 * Containers holding custom blocks have contextual text slots (labels,
 * captions) that should not be replaced with generic content during
 * walk-and-replace. Only checks direct children (non-recursive) to avoid
 * false positives from deeply nested core structures.
 *
 * @param  array $blocks  Inner blocks to check.
 * @return bool            True if any block has a non-core blockName.
 */
function nova_gut_has_custom_blocks( array $blocks ): bool {
	foreach ( $blocks as $b ) {
		$name = $b['blockName'] ?? null;
		if ( null === $name ) {
			continue;
		}
		// core/* blocks are safe for content replacement.
		if ( 0 !== strncmp( $name, 'core/', 5 ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Deduplicate content blocks.
 *
 * Guards against upstream workflows that concatenate overlapping content
 * (e.g. top_content + raw_html + bottom_content where raw_html already
 * includes top_content and bottom_content).
 *
 * - Headings: if the same heading text appears more than once, the second
 *   occurrence and its following content blocks are removed.
 * - Paragraphs: consecutive duplicate paragraphs are collapsed to one.
 *
 * @param  array $blocks  Parsed blocks (non-null blockNames only).
 * @return array           Deduplicated blocks.
 */
function nova_gut_dedup_content_blocks( array $blocks ): array {
	$seen_headings  = array();
	$seen_paragraphs = array();
	$result         = array();
	$i              = 0;
	$total          = count( $blocks );

	while ( $i < $total ) {
		$block = $blocks[ $i ];
		$name  = $block['blockName'] ?? null;

		// Heading dedup: skip duplicate heading + its content section.
		if ( 'core/heading' === $name ) {
			$text = strtolower( trim( strip_tags( $block['innerHTML'] ?? '' ) ) );

			if ( '' !== $text && isset( $seen_headings[ $text ] ) ) {
				// Skip this heading and all following content until the next
				// heading that hasn't been seen yet. This collapses entire
				// duplicate sections, even if the duplicate copy has extra
				// paragraphs or FAQ blocks mixed in.
				$i++;
				while ( $i < $total ) {
					$next_name = $blocks[ $i ]['blockName'] ?? null;
					if ( 'core/heading' === $next_name ) {
						$next_txt = strtolower( trim( strip_tags( $blocks[ $i ]['innerHTML'] ?? '' ) ) );
						if ( '' !== $next_txt && ! isset( $seen_headings[ $next_txt ] ) ) {
							break; // Genuinely new heading — stop skipping.
						}
						// This heading was also seen → skip it and its section.
						$i++;
						continue;
					}
					$i++;
				}
				continue;
			}

			if ( '' !== $text ) {
				$seen_headings[ $text ] = true;
			}
		}

		// Paragraph dedup: skip any paragraph whose exact text was already seen.
		// This catches duplicates even when they aren't consecutive (e.g. when
		// upstream concatenates overlapping content blocks).
		if ( 'core/paragraph' === $name ) {
			$text = trim( strip_tags( $block['innerHTML'] ?? '' ) );
			if ( '' !== $text ) {
				if ( isset( $seen_paragraphs[ $text ] ) ) {
					$i++;
					continue;
				}
				$seen_paragraphs[ $text ] = true;
			}
		}

		$result[] = $block;
		$i++;
	}

	return array_values( $result );
}

/**
 * Replace the text of H1 heading blocks with a new title.
 *
 * Recurses into container blocks (groups, covers, columns) to find H1 headings
 * nested inside hero sections.
 *
 * @param  array  $blocks  Parsed blocks (by value).
 * @param  string $title   New title text.
 * @return array            Blocks with H1 text replaced.
 */
function nova_gut_replace_h1_text( array $blocks, string $title ): array {
	for ( $i = 0, $len = count( $blocks ); $i < $len; $i++ ) {
		$name = $blocks[ $i ]['blockName'] ?? null;

		if ( 'core/heading' === $name ) {
			$level = $blocks[ $i ]['attrs']['level'] ?? 2;
			if ( 1 === $level ) {
				$html = $blocks[ $i ]['innerHTML'];

				// Extract the old visible text (all tags stripped).
				$old_text = trim( strip_tags( $html ) );

				if ( '' !== $old_text ) {
					// Replace only the visible text, preserving inner element
					// wrappers like <mark style="..." class="has-white-color">.
					$pos = strpos( $html, $old_text );
					if ( false !== $pos ) {
						$html = substr_replace( $html, esc_html( $title ), $pos, strlen( $old_text ) );
					}
				} else {
					// Fallback: no text found, replace inner HTML between H1 tags.
					$html = preg_replace(
						'/>([^<]*)<\/h1>/s',
						'>' . esc_html( $title ) . '</h1>',
						$html
					);
				}

				$blocks[ $i ]['innerHTML'] = $html;

				// Update innerContent to match.
				if ( isset( $blocks[ $i ]['innerContent'][0] ) && is_string( $blocks[ $i ]['innerContent'][0] ) ) {
					$blocks[ $i ]['innerContent'][0] = $html;
				}
			}
		}

		// Recurse into container blocks.
		if ( ! empty( $blocks[ $i ]['innerBlocks'] ) ) {
			$blocks[ $i ]['innerBlocks'] = nova_gut_replace_h1_text(
				$blocks[ $i ]['innerBlocks'],
				$title
			);
		}
	}

	return $blocks;
}

/**
 * Replace the first heading inside each core/cover block with the page title.
 *
 * Cover blocks serve as hero/header sections. Their heading is typically a
 * placeholder (e.g. "Titel") that should display the actual page title.
 * This is complementary to nova_gut_replace_h1_text() which only targets H1s.
 *
 * @param  array  $blocks  Parsed blocks.
 * @param  string $title   New title text.
 * @return array            Blocks with cover headings replaced.
 */
function nova_gut_replace_cover_title( array $blocks, string $title ): array {
	for ( $i = 0, $len = count( $blocks ); $i < $len; $i++ ) {
		$name = $blocks[ $i ]['blockName'] ?? null;

		if ( 'core/cover' === $name && ! empty( $blocks[ $i ]['innerBlocks'] ) ) {
			// Replace the heading text with the new page title.
			$blocks[ $i ]['innerBlocks'] = nova_gut_replace_first_heading_in_tree(
				$blocks[ $i ]['innerBlocks'],
				$title
			);
			// Strip source-specific text from inside the cover (paragraphs,
			// lists, tables). These are content from the source page that
			// would leak into the hero section. Structural elements (spacers,
			// headings, buttons, images) are kept.
			$blocks[ $i ]['innerBlocks'] = nova_gut_strip_cover_text(
				$blocks[ $i ]['innerBlocks']
			);
			// Rebuild innerContent to match the updated innerBlocks.
			$blocks[ $i ] = nova_gut_rebuild_block_inner_content( $blocks[ $i ] );
			continue;
		}

		// Recurse into other containers to find nested covers.
		if ( ! empty( $blocks[ $i ]['innerBlocks'] ) ) {
			$blocks[ $i ]['innerBlocks'] = nova_gut_replace_cover_title(
				$blocks[ $i ]['innerBlocks'],
				$title
			);
		}
	}

	return $blocks;
}

/**
 * Strip text-bearing blocks from inside a cover's inner block tree.
 *
 * Removes paragraphs (non-empty), lists, and tables that are source-page
 * content. Preserves structural elements: spacers, headings, buttons,
 * images, and container blocks (groups — recursed into).
 *
 * @param  array $blocks  Inner blocks of a cover or nested container.
 * @return array           Filtered blocks.
 */
function nova_gut_strip_cover_text( array $blocks ): array {
	$result = array();

	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? null;

		// Always keep whitespace/freeform.
		if ( null === $name ) {
			$result[] = $block;
			continue;
		}

		// Remove non-empty paragraphs (source text).
		if ( 'core/paragraph' === $name && '' !== trim( strip_tags( $block['innerHTML'] ?? '' ) ) ) {
			continue;
		}

		// Remove lists and tables (source content).
		if ( in_array( $name, array( 'core/list', 'core/table' ), true ) ) {
			continue;
		}

		// Recurse into groups to strip nested source text.
		if ( 'core/group' === $name && ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = nova_gut_strip_cover_text( $block['innerBlocks'] );
			$block = nova_gut_rebuild_block_inner_content( $block );
		}

		$result[] = $block;
	}

	return array_values( $result );
}

/**
 * Rebuild a single block's innerContent array to match its current innerBlocks.
 *
 * Regenerates the innerContent by keeping string entries (HTML) and adjusting
 * null slots to match the number of remaining innerBlocks.
 *
 * @param  array $block  A parsed block.
 * @return array          Block with rebuilt innerContent.
 */
function nova_gut_rebuild_block_inner_content( array $block ): array {
	if ( empty( $block['innerContent'] ) || empty( $block['innerBlocks'] ) ) {
		return $block;
	}

	$target_count = count( $block['innerBlocks'] );
	$block['innerContent'] = nova_gut_rebuild_inner_content(
		$block['innerContent'],
		$target_count
	);

	return $block;
}

/**
 * Replace the first heading found (any level) in a block tree.
 *
 * @param  array  $blocks  Inner blocks of a container.
 * @param  string $title   Replacement text.
 * @param  bool   $found   (internal) Whether replacement already happened.
 * @return array            Modified blocks.
 */
function nova_gut_replace_first_heading_in_tree( array $blocks, string $title, bool &$found = false ): array {
	for ( $i = 0, $len = count( $blocks ); $i < $len; $i++ ) {
		if ( $found ) {
			break;
		}
		$name = $blocks[ $i ]['blockName'] ?? null;

		if ( 'core/heading' === $name ) {
			$html     = $blocks[ $i ]['innerHTML'];
			$old_text = trim( strip_tags( $html ) );

			if ( '' !== $old_text ) {
				$pos = strpos( $html, $old_text );
				if ( false !== $pos ) {
					$html = substr_replace( $html, esc_html( $title ), $pos, strlen( $old_text ) );
				}
			}

			$blocks[ $i ]['innerHTML'] = $html;
			if ( isset( $blocks[ $i ]['innerContent'][0] ) && is_string( $blocks[ $i ]['innerContent'][0] ) ) {
				$blocks[ $i ]['innerContent'][0] = $html;
			}

			$found = true;
			break;
		}

		if ( ! empty( $blocks[ $i ]['innerBlocks'] ) ) {
			$blocks[ $i ]['innerBlocks'] = nova_gut_replace_first_heading_in_tree(
				$blocks[ $i ]['innerBlocks'],
				$title,
				$found
			);
		}
	}

	return $blocks;
}

/* ---------------------------------------------------------------------------
 * FAQ → core/details conversion
 * ------------------------------------------------------------------------ */

/**
 * Detect FAQ sections in block content and convert them to core/details blocks.
 *
 * Looks for a heading containing FAQ-related keywords (e.g. "Veelgestelde vragen",
 * "FAQ", "Frequently Asked Questions"), followed by sub-heading + paragraph pairs.
 * Each Q+A pair is wrapped in a collapsible <!-- wp:details --> block.
 *
 * @param  string $content  Gutenberg block markup.
 * @return string            Content with FAQ sections converted to details blocks.
 */
function nova_gut_convert_faq_to_details( string $content ): string {
	if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
		return $content;
	}

	// Quick check: does the content even have FAQ-like text?
	$content_lower = strtolower( $content );
	$has_faq = false;
	foreach ( array( 'faq', 'veelgestelde vragen', 'frequently asked', 'veel gestelde vragen' ) as $kw ) {
		if ( false !== strpos( $content_lower, $kw ) ) {
			$has_faq = true;
			break;
		}
	}
	if ( ! $has_faq ) {
		return $content;
	}

	$blocks   = parse_blocks( $content );
	$output   = array();
	$modified = false;
	$total    = count( $blocks );
	$i        = 0;

	while ( $i < $total ) {
		$block = $blocks[ $i ];

		if ( ! nova_gut_is_faq_section_heading( $block ) ) {
			$output[] = $block;
			$i++;
			continue;
		}

		// Found FAQ heading — keep it and determine question level.
		$output[]       = $block;
		$faq_level      = $block['attrs']['level'] ?? 2;
		$i++;

		// Collect Q+A pairs: headings deeper than the FAQ heading are questions.
		while ( $i < $total ) {
			$current = $blocks[ $i ];
			$name    = $current['blockName'] ?? null;

			// Skip whitespace / freeform blocks.
			if ( null === $name ) {
				$output[] = $current;
				$i++;
				continue;
			}

			// A heading at the same or higher level ends the FAQ section.
			if ( 'core/heading' === $name ) {
				$level = $current['attrs']['level'] ?? 2;
				if ( $level <= $faq_level ) {
					break; // Back to outer loop — this block isn't consumed.
				}

				// This heading is a question.
				$question_text = trim( strip_tags( $current['innerHTML'] ?? '' ) );
				$i++;

				// Collect answer blocks (paragraphs, lists).
				$answer_blocks = array();
				while ( $i < $total ) {
					$ans_name = $blocks[ $i ]['blockName'] ?? null;
					if ( null === $ans_name ) {
						$i++;
						continue;
					}
					if ( in_array( $ans_name, array( 'core/paragraph', 'core/list' ), true ) ) {
						$answer_blocks[] = $blocks[ $i ];
						$i++;
					} else {
						break;
					}
				}

				if ( '' !== $question_text && ! empty( $answer_blocks ) ) {
					$output[] = nova_gut_build_details_block( $question_text, $answer_blocks );
					$modified = true;
				} else {
					// Can't form a valid details block — keep original blocks.
					$output[] = $current;
					foreach ( $answer_blocks as $ab ) {
						$output[] = $ab;
					}
				}

				continue;
			}

			// Non-heading block — FAQ section is over.
			break;
		}
	}

	if ( ! $modified ) {
		return $content;
	}

	return serialize_blocks( $output );
}

/**
 * Check if a block is an FAQ section heading.
 *
 * @param  array $block  Parsed block.
 * @return bool
 */
function nova_gut_is_faq_section_heading( array $block ): bool {
	if ( 'core/heading' !== ( $block['blockName'] ?? '' ) ) {
		return false;
	}

	$text = strtolower( trim( strip_tags( $block['innerHTML'] ?? '' ) ) );
	if ( '' === $text ) {
		return false;
	}

	$keywords = array( 'faq', 'veelgestelde vragen', 'frequently asked', 'veel gestelde vragen' );
	foreach ( $keywords as $kw ) {
		if ( false !== strpos( $text, $kw ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Build a core/details block from a question and answer blocks.
 *
 * Produces the same structure WordPress core's block parser returns for:
 *   <!-- wp:details -->
 *   <details class="wp-block-details"><summary>Question</summary>
 *   <!-- wp:paragraph --><p>Answer</p><!-- /wp:paragraph -->
 *   </details>
 *   <!-- /wp:details -->
 *
 * @param  string $summary       The question text (plain text, will be escaped).
 * @param  array  $inner_blocks  Answer blocks (paragraphs, lists, etc.).
 * @return array                  Parsed block array for core/details.
 */
function nova_gut_build_details_block( string $summary, array $inner_blocks ): array {
	$escaped_summary = esc_html( $summary );

	$opening = "<details class=\"wp-block-details\">\n<summary>" . $escaped_summary . "</summary>\n";
	$closing = "\n</details>";

	// innerContent: opening string, then null for each inner block with newlines between, then closing.
	$inner_content = array( $opening );
	$block_count   = count( $inner_blocks );
	for ( $j = 0; $j < $block_count; $j++ ) {
		$inner_content[] = null;
		if ( $j < $block_count - 1 ) {
			$inner_content[] = "\n\n";
		}
	}
	$inner_content[] = $closing;

	return array(
		'blockName'    => 'core/details',
		'attrs'        => array(),
		'innerBlocks'  => $inner_blocks,
		'innerHTML'    => $opening . $closing,
		'innerContent' => $inner_content,
	);
}

/**
 * Ensure content has proper Gutenberg block markup.
 *
 * If the content already contains <!-- wp: --> block delimiters, it is returned
 * as-is (assumed to be fully wrapped). Otherwise, each top-level HTML element
 * is wrapped in its corresponding Gutenberg block comment so that block-aware
 * themes and the block editor render the content correctly.
 *
 * @param  string $content  HTML content (may or may not have block markup).
 * @return string            Content with proper Gutenberg block delimiters.
 */
function nova_gut_ensure_block_markup( string $content ): string {
	// Already has block delimiters → leave as-is.
	if ( false !== strpos( $content, '<!-- wp:' ) ) {
		return $content;
	}

	$trimmed = trim( $content );
	if ( '' === $trimmed ) {
		return '';
	}

	return nova_gut_wrap_html_in_blocks( $trimmed );
}

/**
 * Parse top-level HTML elements and wrap each in its Gutenberg block.
 *
 * Recognizes: p, h1–h6, ul, ol, table, blockquote, figure, pre, div.
 * Anything between recognized elements (loose text, <hr>, etc.) is wrapped
 * in <!-- wp:html -->. Self-closing <hr> tags get <!-- wp:separator -->.
 *
 * @param  string $html  Raw HTML without block delimiters.
 * @return string         Gutenberg block markup.
 */
function nova_gut_wrap_html_in_blocks( string $html ): string {
	$output   = '';
	$offset   = 0;
	$html_len = strlen( $html );

	// Match complete top-level block elements: <tag ...>…</tag>.
	// The lazy [\s\S]*? combined with the back-reference </\1> ensures we
	// match the first closing tag of the same name.
	$block_tags = 'p|h[1-6]|ul|ol|table|blockquote|figure|pre|div';
	$pattern    = '/<(' . $block_tags . ')(\s[^>]*)?>[\s\S]*?<\/\1>/i';

	if ( preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches as $match ) {
			$full_html = $match[0][0];
			$match_pos = $match[0][1];
			$tag_name  = strtolower( $match[1][0] );

			// Handle any gap content before this element.
			if ( $match_pos > $offset ) {
				$gap     = substr( $html, $offset, $match_pos - $offset );
				$output .= nova_gut_wrap_gap_content( $gap );
			}

			// Wrap the matched element in its Gutenberg block.
			$output .= nova_gut_block_wrap_element( $tag_name, trim( $full_html ) );

			// Advance past this match.
			$offset = $match_pos + strlen( $full_html );
		}
	}

	// Handle any remaining content after the last matched element.
	if ( $offset < $html_len ) {
		$remaining = substr( $html, $offset );
		$output   .= nova_gut_wrap_gap_content( $remaining );
	}

	// Fallback: if no block elements were found, wrap everything in wp:html.
	if ( '' === $output && '' !== trim( $html ) ) {
		$output = "<!-- wp:html -->\n" . trim( $html ) . "\n<!-- /wp:html -->";
	}

	return rtrim( $output );
}

/**
 * Wrap gap content (text between recognized block elements) in blocks.
 *
 * Handles <hr> → wp:separator; everything else → wp:html.
 *
 * @param  string $gap  Content between top-level elements.
 * @return string        Block-wrapped content, or empty if gap is whitespace-only.
 */
function nova_gut_wrap_gap_content( string $gap ): string {
	$trimmed = trim( $gap );
	if ( '' === $trimmed ) {
		return '';
	}

	// Strip orphan closing tags (e.g. </div> left over from broken nested-div parsing)
	// and standalone <br> tags that would create empty blocks.
	$trimmed = preg_replace( '/^(\s*<\/[a-z][a-z0-9]*>\s*)+$/i', '', $trimmed );
	$trimmed = preg_replace( '/^(\s*<br\s*\/?>\s*)+$/i', '', $trimmed );
	$trimmed = trim( $trimmed );
	if ( '' === $trimmed ) {
		return '';
	}

	$result = '';
	$parts  = preg_split( '/(<hr\s*\/?>)/i', $trimmed, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( '' === $part ) {
			continue;
		}
		if ( preg_match( '/^<hr\s*\/?>$/i', $part ) ) {
			$result .= "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->\n\n";
		} else {
			// Skip parts that are only orphan closing tags or whitespace.
			$stripped = preg_replace( '/<\/[a-z][a-z0-9]*>/i', '', $part );
			$stripped = preg_replace( '/<br\s*\/?>/i', '', $stripped );
			if ( '' === trim( $stripped ) ) {
				continue;
			}

			// Detect image-bearing gap content and wrap as wp:image.
			if ( false !== strpos( $part, '<img' ) ) {
				// Standalone <img> or <a><img></a> patterns.
				if ( preg_match( '/^\s*(<a\s[^>]*>\s*)?<img\s[^>]*>(\s*<\/a>)?\s*$/i', $part ) ) {
					$result .= nova_gut_wrap_img_as_block( $part );
					continue;
				}
			}

			$result .= "<!-- wp:html -->\n" . $part . "\n<!-- /wp:html -->\n\n";
		}
	}

	return $result;
}

/**
 * Wrap a single HTML element in its corresponding Gutenberg block comment.
 *
 * @param  string $tag   Lowercase tag name (p, h2, ul, table, etc.).
 * @param  string $html  Complete HTML element including opening and closing tags.
 * @return string         Block-wrapped markup.
 */
function nova_gut_block_wrap_element( string $tag, string $html ): string {
	switch ( $tag ) {
		case 'p':
			// A <p> that contains ONLY an <img> (possibly linked) and no text
			// should become an image block, not a paragraph.
			if ( false !== strpos( $html, '<img' ) ) {
				$text_only = trim( strip_tags( $html ) );
				if ( '' === $text_only ) {
					$inner = preg_replace( '/^<p[^>]*>|<\/p>$/i', '', $html );
					return nova_gut_wrap_img_as_block( trim( $inner ) );
				}
			}
			// Skip empty paragraphs — they create unintended spacing.
			if ( '' === trim( strip_tags( $html ) ) && false === strpos( $html, '<img' ) ) {
				return '';
			}
			return "<!-- wp:paragraph -->\n" . $html . "\n<!-- /wp:paragraph -->\n\n";

		case 'h1':
			return "<!-- wp:heading {\"level\":1} -->\n" . $html . "\n<!-- /wp:heading -->\n\n";

		case 'h2':
			// Level 2 is the default for wp:heading — no attrs needed.
			return "<!-- wp:heading -->\n" . $html . "\n<!-- /wp:heading -->\n\n";

		case 'h3':
			return "<!-- wp:heading {\"level\":3} -->\n" . $html . "\n<!-- /wp:heading -->\n\n";

		case 'h4':
			return "<!-- wp:heading {\"level\":4} -->\n" . $html . "\n<!-- /wp:heading -->\n\n";

		case 'h5':
			return "<!-- wp:heading {\"level\":5} -->\n" . $html . "\n<!-- /wp:heading -->\n\n";

		case 'h6':
			return "<!-- wp:heading {\"level\":6} -->\n" . $html . "\n<!-- /wp:heading -->\n\n";

		case 'ul':
			return "<!-- wp:list -->\n" . $html . "\n<!-- /wp:list -->\n\n";

		case 'ol':
			return "<!-- wp:list {\"ordered\":true} -->\n" . $html . "\n<!-- /wp:list -->\n\n";

		case 'blockquote':
			return "<!-- wp:quote -->\n" . $html . "\n<!-- /wp:quote -->\n\n";

		case 'table':
			return nova_gut_build_table_block( $html );

		case 'figure':
			if ( false !== strpos( $html, 'wp-block-table' ) || false !== strpos( $html, '<table' ) ) {
				return nova_gut_build_table_block( $html );
			}
			if ( false !== strpos( $html, '<img' ) ) {
				return "<!-- wp:image -->\n" . $html . "\n<!-- /wp:image -->\n\n";
			}
			return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->\n\n";

		case 'pre':
			return "<!-- wp:preformatted -->\n" . $html . "\n<!-- /wp:preformatted -->\n\n";

		case 'div':
			// Gutenberg buttons/CTA block (outer wrapper).
			if ( false !== strpos( $html, 'wp-block-buttons' ) ) {
				return "<!-- wp:buttons -->\n" . $html . "\n<!-- /wp:buttons -->\n\n";
			}
			// Single button div without outer wrapper → wrap in buttons block.
			if ( false !== strpos( $html, 'wp-block-button' ) ) {
				return "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">\n<!-- wp:button -->\n" . $html . "\n<!-- /wp:button -->\n</div>\n<!-- /wp:buttons -->\n\n";
			}
			// Div containing an image without wp-block-* classes → image block.
			if ( false !== strpos( $html, '<img' ) && false === strpos( $html, 'wp-block-' ) ) {
				return nova_gut_wrap_img_as_block( $html );
			}
			return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->\n\n";

		default:
			return "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->\n\n";
	}
}

/* ---------------------------------------------------------------------------
 * Table → core/table block builder
 * ------------------------------------------------------------------------ */

/**
 * Build a valid Gutenberg core/table block from an HTML table.
 *
 * Uses DOMDocument to parse the table structure, extracting caption, thead,
 * tbody, and tfoot sections. Handles both bare <table> elements and
 * <figure class="wp-block-table"><table>...</table></figure> wrappers.
 *
 * @param  string $html  HTML containing a <table> (may include figure wrapper).
 * @return string         Gutenberg block markup for core/table.
 */
function nova_gut_build_table_block( string $html ): string {
	$doc = new DOMDocument();
	// Suppress warnings for malformed HTML; prepend XML encoding for UTF-8.
	$wrapped_html = '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>';
	@$doc->loadHTML( $wrapped_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR );

	$table = $doc->getElementsByTagName( 'table' )->item( 0 );
	if ( ! $table ) {
		// Fallback: wrap raw HTML as before.
		return "<!-- wp:table {\"hasFixedLayout\":false} -->\n"
		     . '<figure class="wp-block-table">' . $html . '</figure>'
		     . "\n<!-- /wp:table -->\n\n";
	}

	// Extract caption text.
	$caption      = '';
	$caption_nodes = $table->getElementsByTagName( 'caption' );
	if ( $caption_nodes->length > 0 ) {
		$caption_node = $caption_nodes->item( 0 );
		$caption      = trim( nova_gut_dom_inner_html( $doc, $caption_node ) );
	}

	// Collect rows from thead, tbody, tfoot, or directly under table.
	$head_rows = array();
	$body_rows = array();
	$foot_rows = array();
	$loose_rows = array();

	foreach ( $table->childNodes as $child ) {
		if ( XML_ELEMENT_NODE !== $child->nodeType ) {
			continue;
		}
		$tag = strtolower( $child->nodeName );

		if ( 'thead' === $tag ) {
			$head_rows = array_merge( $head_rows, nova_gut_dom_collect_rows( $doc, $child ) );
		} elseif ( 'tbody' === $tag ) {
			$body_rows = array_merge( $body_rows, nova_gut_dom_collect_rows( $doc, $child ) );
		} elseif ( 'tfoot' === $tag ) {
			$foot_rows = array_merge( $foot_rows, nova_gut_dom_collect_rows( $doc, $child ) );
		} elseif ( 'tr' === $tag ) {
			$loose_rows[] = nova_gut_dom_row_to_html( $doc, $child );
		}
	}

	// If no thead/tbody/tfoot found, treat all loose <tr> as body.
	if ( empty( $head_rows ) && empty( $body_rows ) && empty( $foot_rows ) && ! empty( $loose_rows ) ) {
		$body_rows = $loose_rows;
	} elseif ( ! empty( $loose_rows ) ) {
		$body_rows = array_merge( $body_rows, $loose_rows );
	}

	// Rebuild clean table HTML.
	$table_html = '<table>';
	if ( ! empty( $head_rows ) ) {
		$table_html .= '<thead>' . implode( '', $head_rows ) . '</thead>';
	}
	if ( ! empty( $body_rows ) ) {
		$table_html .= '<tbody>' . implode( '', $body_rows ) . '</tbody>';
	}
	if ( ! empty( $foot_rows ) ) {
		$table_html .= '<tfoot>' . implode( '', $foot_rows ) . '</tfoot>';
	}
	$table_html .= '</table>';

	$figcaption = '';
	if ( '' !== $caption ) {
		$figcaption = '<figcaption>' . $caption . '</figcaption>';
	}

	$figure_html = '<figure class="wp-block-table">' . $table_html . $figcaption . '</figure>';
	$attrs_json  = wp_json_encode( array( 'hasFixedLayout' => false ) );

	return "<!-- wp:table {$attrs_json} -->\n{$figure_html}\n<!-- /wp:table -->\n\n";
}

/**
 * Collect rows (<tr>) from a table section element (thead/tbody/tfoot).
 *
 * @param  DOMDocument $doc     The document.
 * @param  DOMNode     $section The section element.
 * @return array                Array of row HTML strings.
 */
function nova_gut_dom_collect_rows( DOMDocument $doc, DOMNode $section ): array {
	$rows = array();
	foreach ( $section->childNodes as $child ) {
		if ( XML_ELEMENT_NODE === $child->nodeType && 'tr' === strtolower( $child->nodeName ) ) {
			$rows[] = nova_gut_dom_row_to_html( $doc, $child );
		}
	}
	return $rows;
}

/**
 * Convert a <tr> DOMNode back to HTML, preserving cell content.
 *
 * @param  DOMDocument $doc The document.
 * @param  DOMNode     $tr  The <tr> element.
 * @return string            HTML for the row.
 */
function nova_gut_dom_row_to_html( DOMDocument $doc, DOMNode $tr ): string {
	$html = '<tr>';
	foreach ( $tr->childNodes as $cell ) {
		if ( XML_ELEMENT_NODE !== $cell->nodeType ) {
			continue;
		}
		$tag = strtolower( $cell->nodeName );
		if ( 'td' === $tag || 'th' === $tag ) {
			$inner = nova_gut_dom_inner_html( $doc, $cell );
			$html .= "<{$tag}>{$inner}</{$tag}>";
		}
	}
	$html .= '</tr>';
	return $html;
}

/**
 * Get the innerHTML of a DOMNode (all child nodes serialized to HTML).
 *
 * @param  DOMDocument $doc  The document.
 * @param  DOMNode     $node The parent node.
 * @return string             Inner HTML.
 */
function nova_gut_dom_inner_html( DOMDocument $doc, DOMNode $node ): string {
	$html = '';
	foreach ( $node->childNodes as $child ) {
		$html .= $doc->saveHTML( $child );
	}
	return $html;
}

/* ---------------------------------------------------------------------------
 * Image block wrapping helper
 * ------------------------------------------------------------------------ */

/**
 * Wrap image-bearing HTML in a Gutenberg wp:image block.
 *
 * If the HTML is already inside a <figure>, wraps with block comment only.
 * Otherwise adds a <figure class="wp-block-image"> wrapper.
 *
 * @param  string $html  HTML containing an <img> (standalone, linked, or div-wrapped).
 * @return string         Gutenberg block markup for core/image.
 */
function nova_gut_wrap_img_as_block( string $html ): string {
	$html = trim( $html );
	if ( '' === $html ) {
		return '';
	}

	// Already in a <figure>? Just wrap with block comment.
	if ( preg_match( '/^<figure/i', $html ) ) {
		// Ensure wp-block-image class is present.
		if ( false === strpos( $html, 'wp-block-image' ) ) {
			$html = preg_replace( '/^<figure/i', '<figure class="wp-block-image"', $html, 1 );
		}
		return "<!-- wp:image -->\n" . $html . "\n<!-- /wp:image -->\n\n";
	}

	// Wrap in <figure class="wp-block-image">.
	return "<!-- wp:image -->\n<figure class=\"wp-block-image\">"
	     . $html
	     . "</figure>\n<!-- /wp:image -->\n\n";
}

/* ---------------------------------------------------------------------------
 * Image replacement mapping (API-facing)
 * ------------------------------------------------------------------------ */

/**
 * Apply image replacement mapping to block-wrapped content.
 *
 * Finds <img> tags in wp:image and wp:html blocks and matches them against
 * the replacements map. When matched, rewrites the block comment attributes
 * and updates src/alt/class/caption in the HTML.
 *
 * @param  string $content       Block-wrapped Gutenberg markup.
 * @param  array  $replacements  Map of key => { id, url?, alt?, caption? }.
 * @return string                Content with image blocks updated.
 */
function nova_gut_apply_image_replacements( string $content, array $replacements ): string {
	if ( empty( $replacements ) ) {
		return $content;
	}

	// Process existing wp:image blocks.
	$content = preg_replace_callback(
		'/<!-- wp:image(\s+\{[^}]*\})?\s*-->\s*([\s\S]*?)\s*<!-- \/wp:image -->/',
		function ( $match ) use ( $replacements ) {
			$inner_html = $match[2];
			return nova_gut_rebuild_image_block( $inner_html, $replacements, $match[0] );
		},
		$content
	);

	// Also check wp:html blocks containing images and upgrade if matched.
	$content = preg_replace_callback(
		'/<!-- wp:html -->\s*([\s\S]*?)\s*<!-- \/wp:html -->/',
		function ( $match ) use ( $replacements ) {
			$inner = $match[1];
			if ( false === strpos( $inner, '<img' ) ) {
				return $match[0];
			}
			return nova_gut_rebuild_image_block( $inner, $replacements, $match[0] );
		},
		$content
	);

	return $content;
}

/**
 * Try to match and rebuild an image block from its inner HTML.
 *
 * @param  string $inner_html    The HTML inside the block (containing <img>).
 * @param  array  $replacements  The image_replacements map.
 * @param  string $original      The original full block markup (returned if no match).
 * @return string                 Rebuilt block or original.
 */
function nova_gut_rebuild_image_block( string $inner_html, array $replacements, string $original ): string {
	if ( ! preg_match( '/<img\s([^>]*)>/i', $inner_html, $img_match ) ) {
		return $original;
	}

	$replacement = nova_gut_find_image_replacement( $img_match[1], $replacements );
	if ( null === $replacement ) {
		return $original;
	}

	$media_id = (int) ( $replacement['id'] ?? 0 );
	if ( 0 === $media_id ) {
		return $original;
	}

	$block_attrs = array(
		'id'              => $media_id,
		'sizeSlug'        => 'full',
		'linkDestination' => 'none',
	);

	$new_inner = $inner_html;

	// Update src if URL provided.
	if ( ! empty( $replacement['url'] ) ) {
		$new_src   = esc_url( $replacement['url'] );
		$new_inner = preg_replace( '/\ssrc=["\'][^"\']*["\']/', ' src="' . $new_src . '"', $new_inner, 1 );
	}

	// Update alt if provided.
	if ( isset( $replacement['alt'] ) ) {
		$new_alt = esc_attr( $replacement['alt'] );
		if ( preg_match( '/\salt=["\']/', $new_inner ) ) {
			$new_inner = preg_replace( '/\salt=["\'][^"\']*["\']/', ' alt="' . $new_alt . '"', $new_inner, 1 );
		} else {
			$new_inner = preg_replace( '/<img\s/i', '<img alt="' . $new_alt . '" ', $new_inner, 1 );
		}
	}

	// Add/update wp-image-{id} class on the <img>.
	$class_str = 'wp-image-' . $media_id;
	if ( preg_match( '/(<img\s[^>]*)\sclass=["\']([^"\']*)["\']/', $new_inner, $cls_match ) ) {
		$existing  = preg_replace( '/wp-image-\d+/', '', $cls_match[2] );
		$new_class = trim( $existing . ' ' . $class_str );
		$new_inner = str_replace( $cls_match[0], $cls_match[1] . ' class="' . $new_class . '"', $new_inner );
	} else {
		$new_inner = preg_replace( '/<img\s/i', '<img class="' . $class_str . '" ', $new_inner, 1 );
	}

	// Handle caption.
	$caption = isset( $replacement['caption'] ) ? trim( (string) $replacement['caption'] ) : '';
	if ( '' !== $caption ) {
		if ( false !== strpos( $new_inner, '<figcaption' ) ) {
			$new_inner = preg_replace(
				'/<figcaption[^>]*>.*?<\/figcaption>/s',
				'<figcaption>' . esc_html( $caption ) . '</figcaption>',
				$new_inner
			);
		} elseif ( false !== strpos( $new_inner, '</figure>' ) ) {
			$new_inner = str_replace(
				'</figure>',
				'<figcaption>' . esc_html( $caption ) . '</figcaption></figure>',
				$new_inner
			);
		}
	}

	// Ensure content is wrapped in <figure class="wp-block-image">.
	if ( false === strpos( $new_inner, '<figure' ) ) {
		$new_inner = '<figure class="wp-block-image">' . $new_inner . '</figure>';
	} elseif ( false === strpos( $new_inner, 'wp-block-image' ) ) {
		$new_inner = preg_replace( '/^<figure/i', '<figure class="wp-block-image"', $new_inner, 1 );
	}

	$attrs_json = wp_json_encode( $block_attrs );
	return "<!-- wp:image {$attrs_json} -->\n{$new_inner}\n<!-- /wp:image -->";
}

/**
 * Try to match an <img> tag's attributes against the replacement map.
 *
 * Matching priority:
 *   1. data-wp-media-key attribute
 *   2. id attribute
 *   3. data-media-id attribute
 *   4. src URL (exact match)
 *
 * @param  string $img_attrs_str  Attribute string from the <img> tag.
 * @param  array  $replacements   The image_replacements map (key => { id, ... }).
 * @return array|null              Matched replacement entry, or null.
 */
function nova_gut_find_image_replacement( string $img_attrs_str, array $replacements ): ?array {
	// Priority 1: data-wp-media-key.
	if ( preg_match( '/data-wp-media-key=["\']([^"\']*)["\']/', $img_attrs_str, $m ) ) {
		if ( isset( $replacements[ $m[1] ] ) ) {
			return $replacements[ $m[1] ];
		}
	}

	// Priority 2: id attribute.
	if ( preg_match( '/\bid=["\']([^"\']*)["\']/', $img_attrs_str, $m ) ) {
		if ( isset( $replacements[ $m[1] ] ) ) {
			return $replacements[ $m[1] ];
		}
	}

	// Priority 3: data-media-id (numeric).
	if ( preg_match( '/data-media-id=["\'](\d+)["\']/', $img_attrs_str, $m ) ) {
		if ( isset( $replacements[ $m[1] ] ) ) {
			return $replacements[ $m[1] ];
		}
	}

	// Priority 4: src URL exact match.
	if ( preg_match( '/\bsrc=["\']([^"\']*)["\']/', $img_attrs_str, $m ) ) {
		if ( isset( $replacements[ $m[1] ] ) ) {
			return $replacements[ $m[1] ];
		}
	}

	return null;
}

/**
 * Set categories and tags on a post.
 *
 * Categories and tags are only relevant for the "post" post type.
 * Silently skips if the post type is "page".
 *
 * @param int    $post_id  Post ID.
 * @param string $type     Post type (post or page).
 * @param array  $params   Decoded request payload.
 */
function nova_gut_set_taxonomies( int $post_id, string $type, array $params ): void {
	if ( 'post' !== $type ) {
		return;
	}

	if ( isset( $params['categories'] ) && is_array( $params['categories'] ) ) {
		$category_ids = array_map( 'absint', $params['categories'] );
		$category_ids = array_filter( $category_ids );
		if ( ! empty( $category_ids ) ) {
			wp_set_post_categories( $post_id, $category_ids );
		}
	}

	if ( isset( $params['tags'] ) && is_array( $params['tags'] ) ) {
		$tag_ids = array_map( 'absint', $params['tags'] );
		$tag_ids = array_filter( $tag_ids );
		if ( ! empty( $tag_ids ) ) {
			wp_set_post_terms( $post_id, $tag_ids, 'post_tag' );
		}
	}
}

/* ---------------------------------------------------------------------------
 * Page template rendering check
 * ------------------------------------------------------------------------ */

/**
 * Check whether the active page template is likely to render post_content.
 *
 * For block themes (FSE): reads the resolved template and checks for
 * <!-- wp:post-content -->. For classic themes: checks if the template
 * file calls the_content().
 *
 * Returns a warning string if post_content will likely NOT render,
 * or null if everything looks fine (or if the check is inconclusive).
 *
 * @param  int         $post_id  Page ID.
 * @return string|null            Warning message, or null.
 */
function nova_gut_check_page_template_renders_content( int $post_id ): ?string {
	// Block theme (FSE): check for wp:post-content in the resolved template.
	if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
		$template_slug = get_page_template_slug( $post_id );

		// Resolve which block template applies to this page.
		$template_content = '';

		if ( '' !== $template_slug ) {
			// Custom template: look it up as a wp_template post or file.
			$template_content = nova_gut_get_block_template_content( $template_slug );
		}

		if ( '' === $template_content ) {
			// Fall back to the default "page" template.
			$template_content = nova_gut_get_block_template_content( 'page' );
		}

		if ( '' === $template_content ) {
			// Ultimate fallback: "singular" or "index" template.
			foreach ( array( 'singular', 'index' ) as $fallback ) {
				$template_content = nova_gut_get_block_template_content( $fallback );
				if ( '' !== $template_content ) {
					break;
				}
			}
		}

		if ( '' !== $template_content && false === strpos( $template_content, 'wp:post-content' ) ) {
			return 'Page template does not contain a Post Content block (<!-- wp:post-content -->). '
			     . 'The page body will not render on the frontend. '
			     . 'Add a Post Content block to your page template in the Site Editor, '
			     . 'or set a different template via the "template" field.';
		}

		return null;
	}

	// Classic theme: check if the template file calls the_content().
	$template_file = get_page_template();
	if ( $template_file && is_readable( $template_file ) ) {
		$file_content = file_get_contents( $template_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== $file_content && false === strpos( $file_content, 'the_content' ) ) {
			return sprintf(
				'Page template file (%s) does not appear to call the_content(). The page body may not render.',
				basename( $template_file )
			);
		}
	}

	return null;
}

/**
 * Retrieve the markup content of a block template by slug.
 *
 * Checks both DB-customized templates and theme file templates.
 *
 * @param  string $slug  Template slug (e.g. "page", "singular", "index").
 * @return string         Template content, or empty string if not found.
 */
function nova_gut_get_block_template_content( string $slug ): string {
	if ( ! function_exists( 'get_block_templates' ) ) {
		return '';
	}

	$templates = get_block_templates( array( 'slug__in' => array( $slug ) ) );
	if ( ! empty( $templates ) && isset( $templates[0]->content ) ) {
		return $templates[0]->content;
	}

	return '';
}
