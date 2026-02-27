<?php
/**
 * NOVA Gutenberg Bridge – helpers.
 *
 * JSON-safe parameter extraction, SEO meta mapping, featured-image sideloading,
 * and Gutenberg block serialization helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------------
 * Debug logging  (enabled via: define('NOVA_GUT_DEBUG', true) in wp-config.php)
 * ------------------------------------------------------------------------ */

/**
 * Log a debug entry when NOVA_GUT_DEBUG is enabled.
 *
 * @param string $label  Short label for the log entry.
 * @param mixed  $data   Data to log (string, array, scalar).
 */
function nova_gut_debug_log( string $label, $data ): void {
	if ( ! defined( 'NOVA_GUT_DEBUG' ) || ! NOVA_GUT_DEBUG ) {
		return;
	}

	$entry = '[NOVA-GUT-DEBUG] ' . $label . ': ';

	if ( is_string( $data ) ) {
		$entry .= '(len=' . strlen( $data ) . ') ' . substr( $data, 0, 500 );
	} elseif ( is_array( $data ) || is_object( $data ) ) {
		$entry .= wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	} else {
		$entry .= var_export( $data, true );
	}

	error_log( $entry );
}

/* ---------------------------------------------------------------------------
 * Content normalization  (supports string, object {raw}, and object {rendered})
 * ------------------------------------------------------------------------ */

/**
 * Normalize the raw "content" value from the request payload.
 *
 * Supports:
 *   - String:          "content": "<p>Hello</p>"            → returns the string.
 *   - Object (raw):    "content": { "raw": "<p>…</p>" }     → returns content.raw.
 *   - Object (rendered): "content": { "rendered": "<p>…</p>" } → returns content.rendered.
 *
 * @param  mixed  $content_param  The "content" value from decoded JSON.
 * @return string                  Raw content string.
 */
function nova_gut_normalize_content_param( $content_param ): string {
	if ( is_string( $content_param ) ) {
		return $content_param;
	}

	if ( is_array( $content_param ) ) {
		// Prefer raw, but only if it's non-empty. An empty raw with a valid
		// rendered value should fall through so we don't silently discard content.
		if ( isset( $content_param['raw'] ) && is_string( $content_param['raw'] ) && '' !== $content_param['raw'] ) {
			return $content_param['raw'];
		}
		if ( isset( $content_param['rendered'] ) && is_string( $content_param['rendered'] ) && '' !== $content_param['rendered'] ) {
			return $content_param['rendered'];
		}
		// Both empty or missing — return raw if it was set (preserves explicit empty intent).
		if ( isset( $content_param['raw'] ) && is_string( $content_param['raw'] ) ) {
			return $content_param['raw'];
		}
	}

	return '';
}

/* ---------------------------------------------------------------------------
 * Safe JSON parameter extraction  (mirrors nova_wpb_get_request_json_params_safe)
 * ------------------------------------------------------------------------ */

/**
 * Extract JSON parameters from a REST request with graceful fallback.
 *
 * @param  WP_REST_Request       $request
 * @return array|WP_Error        Parsed array on success, WP_Error on invalid JSON.
 */
function nova_gut_get_request_json_params_safe( WP_REST_Request $request ) {
	$params = $request->get_json_params();
	if ( is_array( $params ) ) {
		return $params;
	}

	$raw      = (string) $request->get_body();
	$raw_trim = trim( $raw );

	if ( '' === $raw_trim ) {
		return array();
	}

	$decoded = json_decode( $raw_trim, true );
	if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
		return $decoded;
	}

	return new WP_Error(
		'invalid_json',
		'Request body must be valid JSON.',
		array(
			'status'          => 400,
			'hint'            => 'Ensure your client sends a JSON body with Content-Type: application/json.',
			'raw_body_prefix' => substr( $raw_trim, 0, 200 ),
		)
	);
}

/* ---------------------------------------------------------------------------
 * SEO meta mapping  (Yoast + Rank Math + AIOSEO – mirrors nova_wpb pattern)
 * ------------------------------------------------------------------------ */

/**
 * Build a flat meta-key → value map from the request payload.
 *
 * Accepts SEO values in two forms:
 *   - Top-level:  meta_title / meta_description
 *   - Nested:     meta.meta_title / meta.meta_description / meta.title / meta.description
 *
 * Maps the friendly keys to Yoast, Rank Math, and AIOSEO post-meta keys.
 *
 * @param  array $params  Decoded request payload.
 * @return array           key => value pairs ready for update_post_meta().
 */
function nova_gut_prepare_meta_updates( array $params ): array {
	$meta = array();

	if ( isset( $params['meta'] ) && is_array( $params['meta'] ) ) {
		$meta = $params['meta'];
	}

	// Direct passthrough for underscore-prefixed keys provided in meta{}.
	$out = array();
	foreach ( $meta as $k => $v ) {
		$k = (string) $k;
		if ( '_' === substr( $k, 0, 1 ) ) {
			$out[ $k ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
		}
	}

	// Resolve SEO title.
	$overwrite_title = array_key_exists( 'meta_title', $params );
	$seo_title       = null;

	if ( $overwrite_title ) {
		$seo_title = $params['meta_title'];
	} elseif ( isset( $meta['meta_title'] ) ) {
		$seo_title = $meta['meta_title'];
	} elseif ( isset( $meta['title'] ) ) {
		$seo_title = $meta['title'];
	}

	// Resolve SEO description.
	$overwrite_desc  = array_key_exists( 'meta_description', $params );
	$seo_description = null;

	if ( $overwrite_desc ) {
		$seo_description = $params['meta_description'];
	} elseif ( isset( $meta['meta_description'] ) ) {
		$seo_description = $meta['meta_description'];
	} elseif ( isset( $meta['description'] ) ) {
		$seo_description = $meta['description'];
	}

	// Map to SEO plugin keys.
	if ( null !== $seo_title ) {
		$seo_title = (string) $seo_title;
		foreach ( array( '_yoast_wpseo_title', '_aioseo_title', 'rank_math_title' ) as $key ) {
			if ( $overwrite_title || ! array_key_exists( $key, $meta ) ) {
				$out[ $key ] = $seo_title;
			}
		}
	}

	if ( null !== $seo_description ) {
		$seo_description = (string) $seo_description;
		foreach ( array( '_yoast_wpseo_metadesc', '_aioseo_description', 'rank_math_description' ) as $key ) {
			if ( $overwrite_desc || ! array_key_exists( $key, $meta ) ) {
				$out[ $key ] = $seo_description;
			}
		}
	}

	return $out;
}

/* ---------------------------------------------------------------------------
 * Gutenberg block serialization
 * ------------------------------------------------------------------------ */

/**
 * Convert a structured blocks array into Gutenberg block-markup HTML.
 *
 * Each block in the array should follow the shape returned by parse_blocks():
 *   { "blockName": "core/paragraph", "attrs": {...}, "innerHTML": "<p>…</p>", "innerBlocks": [...] }
 *
 * Uses WP core serialize_block() / serialize_blocks() when available (WP ≥ 5.3.1).
 * Falls back to a simple serializer for older installs.
 *
 * @param  array  $blocks  Array of block definitions.
 * @return string           Serialized Gutenberg markup.
 */
function nova_gut_serialize_blocks( array $blocks ): string {
	// WP ≥ 5.3.1 has serialize_blocks() in wp-includes/blocks.php.
	if ( function_exists( 'serialize_blocks' ) ) {
		// Normalize each block to ensure the shape serialize_block() expects.
		$normalized = array_map( 'nova_gut_normalize_block', $blocks );
		return serialize_blocks( $normalized );
	}

	// Fallback serializer.
	$out = '';
	foreach ( $blocks as $block ) {
		$out .= nova_gut_serialize_block_fallback( $block );
	}
	return $out;
}

/**
 * Normalize a single block array to the shape WP core expects.
 *
 * @param  array $block
 * @return array
 */
function nova_gut_normalize_block( array $block ): array {
	$name  = $block['blockName'] ?? ( $block['name'] ?? null );
	$attrs = $block['attrs'] ?? ( $block['attributes'] ?? array() );

	if ( ! is_array( $attrs ) ) {
		$attrs = array();
	}

	$inner_html    = $block['innerHTML'] ?? '';
	$inner_blocks  = $block['innerBlocks'] ?? array();
	$inner_content = $block['innerContent'] ?? null;

	if ( is_array( $inner_blocks ) && ! empty( $inner_blocks ) ) {
		$inner_blocks = array_map( 'nova_gut_normalize_block', $inner_blocks );
	}

	// Build innerContent if not provided.
	if ( null === $inner_content ) {
		if ( ! empty( $inner_blocks ) ) {
			// Each inner block placeholder is null in innerContent.
			$inner_content = array();
			$parts         = preg_split( '/(<\!--\s+wp:[^\s].*?\/-->|<\!--\s+wp:[^\s].*?-->[\s\S]*?<\!--\s+\/wp:[^\s]+\s+-->)/s', $inner_html );
			if ( ! empty( $parts ) ) {
				$inner_content[] = $parts[0] ?? '';
				for ( $i = 0, $len = count( $inner_blocks ); $i < $len; $i++ ) {
					$inner_content[] = null;
					$inner_content[] = $parts[ $i + 1 ] ?? '';
				}
			} else {
				$inner_content[] = '';
				foreach ( $inner_blocks as $ignored ) {
					$inner_content[] = null;
					$inner_content[] = '';
				}
			}
		} else {
			$inner_content = array( $inner_html );
		}
	}

	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => $inner_blocks,
		'innerHTML'    => $inner_html,
		'innerContent' => $inner_content,
	);
}

/**
 * Fallback single-block serializer for WP < 5.3.1.
 *
 * @param  array  $block
 * @return string
 */
function nova_gut_serialize_block_fallback( array $block ): string {
	$name  = $block['blockName'] ?? ( $block['name'] ?? null );
	$attrs = $block['attrs'] ?? ( $block['attributes'] ?? array() );
	$html  = $block['innerHTML'] ?? '';

	if ( ! is_array( $attrs ) ) {
		$attrs = array();
	}

	// Freeform (classic) block.
	if ( null === $name || '' === $name ) {
		return $html . "\n\n";
	}

	$attrs_json = ! empty( $attrs ) ? ' ' . wp_json_encode( $attrs ) : '';

	$inner_blocks = $block['innerBlocks'] ?? array();
	if ( is_array( $inner_blocks ) && ! empty( $inner_blocks ) ) {
		$children = '';
		foreach ( $inner_blocks as $child ) {
			$children .= nova_gut_serialize_block_fallback( $child );
		}
		return '<!-- wp:' . $name . $attrs_json . " -->\n" . $html . $children . '<!-- /wp:' . $name . " -->\n\n";
	}

	// Self-closing (no content) vs. wrapping.
	if ( '' === trim( $html ) ) {
		return '<!-- wp:' . $name . $attrs_json . " /-->\n\n";
	}

	return '<!-- wp:' . $name . $attrs_json . " -->\n" . $html . "\n" . '<!-- /wp:' . $name . " -->\n\n";
}

/* ---------------------------------------------------------------------------
 * Featured image handling
 * ------------------------------------------------------------------------ */

/**
 * Process the featured_image payload: set by attachment_id or sideload by URL.
 *
 * Returns an array with 'featured_image_id' on success or 'warning' on
 * non-fatal failure. The post is still created/updated even if the image fails.
 *
 * @param  int   $post_id          The post to attach the featured image to.
 * @param  array $featured_image   The featured_image payload from the request.
 * @return array { featured_image_id: int|null, warning: string|null }
 */
function nova_gut_process_featured_image( int $post_id, array $featured_image ): array {
	$result = array(
		'featured_image_id' => null,
		'warning'           => null,
	);

	$attachment_id = isset( $featured_image['attachment_id'] ) ? absint( $featured_image['attachment_id'] ) : 0;
	$url           = isset( $featured_image['url'] ) ? esc_url_raw( $featured_image['url'] ) : '';
	$alt           = isset( $featured_image['alt'] ) ? sanitize_text_field( $featured_image['alt'] ) : '';
	$caption       = isset( $featured_image['caption'] ) ? sanitize_text_field( $featured_image['caption'] ) : '';

	// Prefer attachment_id if both are provided.
	if ( $attachment_id > 0 ) {
		$attachment = get_post( $attachment_id );
		if ( $attachment && 'attachment' === $attachment->post_type ) {
			set_post_thumbnail( $post_id, $attachment_id );
			$result['featured_image_id'] = $attachment_id;

			if ( '' !== $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			}
			if ( '' !== $caption ) {
				wp_update_post( array(
					'ID'           => $attachment_id,
					'post_excerpt' => $caption,
				) );
			}

			return $result;
		}

		// Invalid attachment_id but URL might still work.
		if ( '' === $url ) {
			$result['warning'] = sprintf( 'Attachment ID %d not found or not an attachment.', $attachment_id );
			return $result;
		}
	}

	// Sideload from URL.
	if ( '' !== $url ) {
		$sideload = nova_gut_sideload_image( $url, $post_id );

		if ( is_wp_error( $sideload ) ) {
			$result['warning'] = 'Featured image sideload failed: ' . $sideload->get_error_message();
			return $result;
		}

		$new_attachment_id = (int) $sideload;
		set_post_thumbnail( $post_id, $new_attachment_id );
		$result['featured_image_id'] = $new_attachment_id;

		if ( '' !== $alt ) {
			update_post_meta( $new_attachment_id, '_wp_attachment_image_alt', $alt );
		}
		if ( '' !== $caption ) {
			wp_update_post( array(
				'ID'           => $new_attachment_id,
				'post_excerpt' => $caption,
			) );
		}

		return $result;
	}

	return $result;
}

/**
 * Download an image from a URL and sideload it into the WP Media Library.
 *
 * Uses WP core media helpers (download_url + media_handle_sideload).
 *
 * @param  string     $url      Image URL.
 * @param  int        $post_id  Parent post ID.
 * @return int|WP_Error         Attachment ID on success, WP_Error on failure.
 */
function nova_gut_sideload_image( string $url, int $post_id ) {
	// Ensure required functions are available.
	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}
	if ( ! function_exists( 'wp_read_image_metadata' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$tmp = download_url( $url );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}

	// Determine filename from URL.
	$url_path = wp_parse_url( $url, PHP_URL_PATH );
	$filename = $url_path ? basename( $url_path ) : 'image.jpg';

	// Strip query strings from filename.
	if ( false !== strpos( $filename, '?' ) ) {
		$filename = strtok( $filename, '?' );
	}

	// Ensure a valid extension.
	$ext = pathinfo( $filename, PATHINFO_EXTENSION );
	if ( '' === $ext ) {
		$filename .= '.jpg';
	}

	$file_array = array(
		'name'     => sanitize_file_name( $filename ),
		'tmp_name' => $tmp,
	);

	$attachment_id = media_handle_sideload( $file_array, $post_id );

	// Clean up temp file on failure.
	if ( is_wp_error( $attachment_id ) ) {
		@unlink( $tmp );
		return $attachment_id;
	}

	return (int) $attachment_id;
}
