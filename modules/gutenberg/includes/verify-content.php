<?php
/**
 * NOVA Gutenberg Bridge – content verification helper.
 *
 * Usage (WP-CLI):
 *   wp eval-file wp-content/plugins/nova-bridge-suite/modules/gutenberg/includes/verify-content.php
 *
 * Or call nova_gut_verify_content() from any WP context after the plugin is loaded.
 *
 * This script creates a test page via the bridge's own functions, verifies the
 * content was stored correctly, checks block markup, and cleans up.
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Allow running via WP-CLI eval-file.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		// OK – loaded via WP-CLI.
	} else {
		exit;
	}
}

/**
 * Verify that the Gutenberg bridge correctly saves and wraps content.
 *
 * @return array  Result with 'pass', 'checks', and optional 'errors'.
 */
function nova_gut_verify_content(): array {
	$results = array(
		'pass'   => true,
		'checks' => array(),
		'errors' => array(),
	);

	$test_html = '<p>Verification paragraph with <a href="https://example.com">a link</a>.</p>'
	           . '<h2>Test Heading H2</h2>'
	           . '<p>Another paragraph.</p>'
	           . '<h3>Test Heading H3</h3>'
	           . '<ul><li>Item one</li><li>Item two</li></ul>';

	// --- Test 1: Content normalization ---

	// String input.
	$norm_string = nova_gut_normalize_content_param( $test_html );
	$check_norm_string = ( $norm_string === $test_html );
	$results['checks']['normalize_string'] = $check_norm_string;
	if ( ! $check_norm_string ) {
		$results['pass']     = false;
		$results['errors'][] = 'normalize_content_param(string) failed.';
	}

	// Object input (raw).
	$norm_object = nova_gut_normalize_content_param( array( 'raw' => $test_html ) );
	$check_norm_object = ( $norm_object === $test_html );
	$results['checks']['normalize_object_raw'] = $check_norm_object;
	if ( ! $check_norm_object ) {
		$results['pass']     = false;
		$results['errors'][] = 'normalize_content_param({raw:...}) failed.';
	}

	// --- Test 2: Block markup wrapping ---

	$wrapped = nova_gut_ensure_block_markup( $test_html );

	$check_has_blocks = ( false !== strpos( $wrapped, '<!-- wp:paragraph -->' ) );
	$results['checks']['has_paragraph_block'] = $check_has_blocks;
	if ( ! $check_has_blocks ) {
		$results['pass']     = false;
		$results['errors'][] = 'ensure_block_markup did not produce <!-- wp:paragraph --> wrappers.';
	}

	$check_has_heading = ( false !== strpos( $wrapped, '<!-- wp:heading -->' ) );
	$results['checks']['has_heading_block'] = $check_has_heading;
	if ( ! $check_has_heading ) {
		$results['pass']     = false;
		$results['errors'][] = 'ensure_block_markup did not produce <!-- wp:heading --> wrappers.';
	}

	$check_has_h3 = ( false !== strpos( $wrapped, '<!-- wp:heading {"level":3} -->' ) );
	$results['checks']['has_h3_level'] = $check_has_h3;
	if ( ! $check_has_h3 ) {
		$results['pass']     = false;
		$results['errors'][] = 'ensure_block_markup did not produce heading level 3 attr.';
	}

	$check_has_list = ( false !== strpos( $wrapped, '<!-- wp:list -->' ) );
	$results['checks']['has_list_block'] = $check_has_list;
	if ( ! $check_has_list ) {
		$results['pass']     = false;
		$results['errors'][] = 'ensure_block_markup did not produce <!-- wp:list --> wrappers.';
	}

	// Content should be preserved inside blocks.
	$check_content_preserved = ( false !== strpos( $wrapped, 'Verification paragraph' ) );
	$results['checks']['content_preserved'] = $check_content_preserved;
	if ( ! $check_content_preserved ) {
		$results['pass']     = false;
		$results['errors'][] = 'Original content text not found in wrapped output.';
	}

	// --- Test 3: Already-wrapped content passes through ---

	$already_wrapped = "<!-- wp:paragraph -->\n<p>Already wrapped.</p>\n<!-- /wp:paragraph -->";
	$passthrough     = nova_gut_ensure_block_markup( $already_wrapped );

	$check_passthrough = ( $passthrough === $already_wrapped );
	$results['checks']['passthrough_existing_blocks'] = $check_passthrough;
	if ( ! $check_passthrough ) {
		$results['pass']     = false;
		$results['errors'][] = 'Already-wrapped content was modified (should pass through).';
	}

	// --- Test 3b: Content normalization edge cases ---

	// Empty raw should NOT override non-empty rendered.
	$norm_fallback = nova_gut_normalize_content_param( array( 'raw' => '', 'rendered' => '<p>Hello</p>' ) );
	$check_fallback = ( '<p>Hello</p>' === $norm_fallback );
	$results['checks']['normalize_empty_raw_fallback'] = $check_fallback;
	if ( ! $check_fallback ) {
		$results['pass']     = false;
		$results['errors'][] = 'normalize_content_param({raw:"", rendered:"..."}) returned empty instead of rendered.';
	}

	// Explicit empty raw with no rendered should return empty (preserve intent).
	$norm_empty = nova_gut_normalize_content_param( array( 'raw' => '' ) );
	$check_empty = ( '' === $norm_empty );
	$results['checks']['normalize_explicit_empty_raw'] = $check_empty;
	if ( ! $check_empty ) {
		$results['pass']     = false;
		$results['errors'][] = 'normalize_content_param({raw:""}) should return empty string.';
	}

	// --- Test 4: Actual post creation (if we can) ---

	if ( function_exists( 'wp_insert_post' ) && current_user_can( 'edit_pages' ) ) {
		$params = array(
			'content' => $test_html,
		);
		$resolved = nova_gut_resolve_content( $params );

		$postarr = array(
			'post_title'   => 'NOVA Bridge Verify – DELETE ME',
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_content' => $resolved,
		);

		$post_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $post_id ) ) {
			$results['pass']     = false;
			$results['errors'][] = 'wp_insert_post failed: ' . $post_id->get_error_message();
		} else {
			$saved = get_post( $post_id );

			$check_db_nonempty = ( strlen( $saved->post_content ) > 0 );
			$results['checks']['db_content_nonempty'] = $check_db_nonempty;
			if ( ! $check_db_nonempty ) {
				$results['pass']     = false;
				$results['errors'][] = 'post_content is empty in database after insert.';
			}

			$check_db_has_text = ( false !== strpos( $saved->post_content, 'Verification paragraph' ) );
			$results['checks']['db_content_has_text'] = $check_db_has_text;
			if ( ! $check_db_has_text ) {
				$results['pass']     = false;
				$results['errors'][] = 'post_content in DB does not contain expected text.';
			}

			$check_db_has_blocks = ( false !== strpos( $saved->post_content, '<!-- wp:' ) );
			$results['checks']['db_content_has_blocks'] = $check_db_has_blocks;
			if ( ! $check_db_has_blocks ) {
				$results['pass']     = false;
				$results['errors'][] = 'post_content in DB does not contain Gutenberg block markers.';
			}

			$results['checks']['test_post_id']     = $post_id;
			$results['checks']['db_content_length'] = strlen( $saved->post_content );

			// --- Test 5: Page template renders content (block themes) ---

			if ( function_exists( 'nova_gut_check_page_template_renders_content' ) ) {
				$tpl_warning = nova_gut_check_page_template_renders_content( $post_id );
				$check_tpl   = ( null === $tpl_warning );
				$results['checks']['page_template_renders_content'] = $check_tpl;
				if ( ! $check_tpl ) {
					$results['pass']     = false;
					$results['errors'][] = $tpl_warning;
				}
			}

			// --- Test 6: GET via bridge returns content ---

			$get_post = get_post( $post_id );
			$check_get_content = ( $get_post && strlen( $get_post->post_content ) > 0 );
			$results['checks']['get_content_nonempty'] = $check_get_content;
			if ( ! $check_get_content ) {
				$results['pass']     = false;
				$results['errors'][] = 'get_post() returned empty post_content for test page.';
			}

			// Clean up.
			wp_delete_post( $post_id, true );
		}
	} else {
		$results['checks']['post_creation_skipped'] = 'Insufficient permissions or not in WP context.';
	}

	return $results;
}

/**
 * Regression tests for block ordering and spacing fixes.
 *
 * These tests exercise:
 * 1. Footer position detection: image-containing groups must NOT be classified as footer.
 * 2. Merge ordering: FAQ blocks must appear AFTER image-row groups, not before.
 * 3. Spacing: empty paragraphs and orphan closing tags must not produce spurious blocks.
 *
 * Tests are self-contained (no database or WP context needed beyond parse_blocks/serialize_blocks).
 *
 * @return array  Result with 'pass', 'checks', and optional 'errors'.
 */
function nova_gut_verify_ordering_and_spacing(): array {
	$results = array(
		'pass'   => true,
		'checks' => array(),
		'errors' => array(),
	);

	// Skip if WP core block functions unavailable.
	if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
		$results['checks']['ordering_tests_skipped'] = 'parse_blocks/serialize_blocks not available.';
		return $results;
	}

	// -----------------------------------------------------------------------
	// TEST A: nova_gut_find_footer_position — image group is NOT footer
	// -----------------------------------------------------------------------
	// Simulate source layout: [heading] [paragraph] [image-row group] [CTA group]
	$source_blocks = array(
		array(
			'blockName'    => 'core/heading',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '<h2>Content Heading</h2>',
			'innerContent' => array( '<h2>Content Heading</h2>' ),
		),
		array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '<p>Content paragraph.</p>',
			'innerContent' => array( '<p>Content paragraph.</p>' ),
		),
		// 3-image row wrapped in a full-width group (the problematic pattern).
		array(
			'blockName'    => 'core/group',
			'attrs'        => array( 'align' => 'full' ),
			'innerBlocks'  => array(
				array(
					'blockName'    => 'core/columns',
					'attrs'        => array(),
					'innerBlocks'  => array(
						array(
							'blockName'    => 'core/column',
							'attrs'        => array(),
							'innerBlocks'  => array(
								array(
									'blockName'    => 'core/image',
									'attrs'        => array(),
									'innerBlocks'  => array(),
									'innerHTML'    => '<figure class="wp-block-image"><img src="img1.jpg" alt=""/></figure>',
									'innerContent' => array( '<figure class="wp-block-image"><img src="img1.jpg" alt=""/></figure>' ),
								),
							),
							'innerHTML'    => '<div class="wp-block-column"></div>',
							'innerContent' => array( '<div class="wp-block-column">', null, '</div>' ),
						),
						array(
							'blockName'    => 'core/column',
							'attrs'        => array(),
							'innerBlocks'  => array(
								array(
									'blockName'    => 'core/image',
									'attrs'        => array(),
									'innerBlocks'  => array(),
									'innerHTML'    => '<figure class="wp-block-image"><img src="img2.jpg" alt=""/></figure>',
									'innerContent' => array( '<figure class="wp-block-image"><img src="img2.jpg" alt=""/></figure>' ),
								),
							),
							'innerHTML'    => '<div class="wp-block-column"></div>',
							'innerContent' => array( '<div class="wp-block-column">', null, '</div>' ),
						),
						array(
							'blockName'    => 'core/column',
							'attrs'        => array(),
							'innerBlocks'  => array(
								array(
									'blockName'    => 'core/image',
									'attrs'        => array(),
									'innerBlocks'  => array(),
									'innerHTML'    => '<figure class="wp-block-image"><img src="img3.jpg" alt=""/></figure>',
									'innerContent' => array( '<figure class="wp-block-image"><img src="img3.jpg" alt=""/></figure>' ),
								),
							),
							'innerHTML'    => '<div class="wp-block-column"></div>',
							'innerContent' => array( '<div class="wp-block-column">', null, '</div>' ),
						),
					),
					'innerHTML'    => '<div class="wp-block-columns"></div>',
					'innerContent' => array( '<div class="wp-block-columns">', null, null, null, '</div>' ),
				),
			),
			'innerHTML'    => '<div class="wp-block-group alignfull"></div>',
			'innerContent' => array( '<div class="wp-block-group alignfull">', null, '</div>' ),
		),
		// CTA footer group (text + button → should be footer even though it has a cover image).
		array(
			'blockName'    => 'core/group',
			'attrs'        => array( 'align' => 'full' ),
			'innerBlocks'  => array(
				array(
					'blockName'    => 'core/cover',
					'attrs'        => array(),
					'innerBlocks'  => array(
						array(
							'blockName'    => 'core/paragraph',
							'attrs'        => array(),
							'innerBlocks'  => array(),
							'innerHTML'    => '<p>Call to action</p>',
							'innerContent' => array( '<p>Call to action</p>' ),
						),
						array(
							'blockName'    => 'core/buttons',
							'attrs'        => array(),
							'innerBlocks'  => array(
								array(
									'blockName'    => 'core/button',
									'attrs'        => array(),
									'innerBlocks'  => array(),
									'innerHTML'    => '<div class="wp-block-button"><a class="wp-block-button__link">Click</a></div>',
									'innerContent' => array( '<div class="wp-block-button"><a class="wp-block-button__link">Click</a></div>' ),
								),
							),
							'innerHTML'    => '<div class="wp-block-buttons"></div>',
							'innerContent' => array( '<div class="wp-block-buttons">', null, '</div>' ),
						),
					),
					'innerHTML'    => '<div class="wp-block-cover"><img src="bg.jpg" class="wp-block-cover__image-background"/></div>',
					'innerContent' => array( '<div class="wp-block-cover"><img src="bg.jpg" class="wp-block-cover__image-background"/>', null, null, '</div>' ),
				),
			),
			'innerHTML'    => '<div class="wp-block-group alignfull"></div>',
			'innerContent' => array( '<div class="wp-block-group alignfull">', null, '</div>' ),
		),
	);

	$footer_pos = nova_gut_find_footer_position( $source_blocks );

	// The image group (index 2) must NOT be in the footer. Footer should start at index 3 (CTA).
	$check_footer_pos = ( 3 === $footer_pos );
	$results['checks']['footer_excludes_image_group'] = $check_footer_pos;
	if ( ! $check_footer_pos ) {
		$results['pass']     = false;
		$results['errors'][] = "footer_position expected 3, got {$footer_pos}. Image-containing group was misclassified as footer.";
	}

	// -----------------------------------------------------------------------
	// TEST B: nova_gut_has_media_blocks detects images
	// -----------------------------------------------------------------------
	$has_media = nova_gut_has_media_blocks( $source_blocks[2]['innerBlocks'] );
	$results['checks']['has_media_blocks_detection'] = $has_media;
	if ( ! $has_media ) {
		$results['pass']     = false;
		$results['errors'][] = 'nova_gut_has_media_blocks failed to detect images inside columns.';
	}

	// -----------------------------------------------------------------------
	// TEST C: Merge ordering — FAQ after image row
	// -----------------------------------------------------------------------
	// Build a serialized source page with the blocks above.
	$source_content = serialize_blocks( $source_blocks );

	// New content: some text + FAQ section.
	$append_html = '<h2>Nieuwe heading</h2>'
	             . '<p>Nieuwe tekst over het onderwerp.</p>'
	             . '<h2>Veelgestelde vragen over deze dienst</h2>'
	             . '<h3>Wat kost het?</h3>'
	             . '<p>De kosten variëren per situatie.</p>'
	             . '<h3>Hoe lang duurt het?</h3>'
	             . '<p>Gemiddeld twee tot drie weken.</p>';

	$merged = nova_gut_merge_source_with_content( $source_content, $append_html, 'Test Pagina' );

	// Find positions of image-row and FAQ heading in the merged output.
	$img_row_pos = strpos( $merged, 'img1.jpg' );
	$faq_pos     = strpos( $merged, 'Veelgestelde vragen' );

	$check_order = ( false !== $img_row_pos && false !== $faq_pos && $img_row_pos < $faq_pos );
	$results['checks']['faq_after_image_row'] = $check_order;
	if ( ! $check_order ) {
		$results['pass']     = false;
		$results['errors'][] = 'FAQ heading appears before or at same position as image row in merged output.';
	}

	// -----------------------------------------------------------------------
	// TEST D: No empty paragraph blocks from empty <p> tags
	// -----------------------------------------------------------------------
	$html_with_empty_p = '<p>Real content.</p><p></p><p> </p><h2>Heading</h2>';
	$wrapped = nova_gut_wrap_html_in_blocks( $html_with_empty_p );

	$empty_para_count = substr_count( $wrapped, "<!-- wp:paragraph -->\n<p></p>" )
	                  + substr_count( $wrapped, "<!-- wp:paragraph -->\n<p> </p>" );
	$check_no_empty_p = ( 0 === $empty_para_count );
	$results['checks']['no_empty_paragraph_blocks'] = $check_no_empty_p;
	if ( ! $check_no_empty_p ) {
		$results['pass']     = false;
		$results['errors'][] = "Found {$empty_para_count} empty paragraph block(s) in wrapped output.";
	}

	// Real content should still be present.
	$check_real_content = ( false !== strpos( $wrapped, 'Real content.' ) );
	$results['checks']['real_content_preserved'] = $check_real_content;
	if ( ! $check_real_content ) {
		$results['pass']     = false;
		$results['errors'][] = 'Real paragraph content was lost during empty-paragraph filtering.';
	}

	// -----------------------------------------------------------------------
	// TEST E: Orphan closing tags do not produce wp:html blocks
	// -----------------------------------------------------------------------
	$gap_content = nova_gut_wrap_gap_content( "</div>\n</div>" );
	$check_no_orphan = ( '' === $gap_content );
	$results['checks']['orphan_closing_tags_stripped'] = $check_no_orphan;
	if ( ! $check_no_orphan ) {
		$results['pass']     = false;
		$results['errors'][] = 'Orphan closing tags produced wp:html blocks instead of being stripped.';
	}

	// Standalone <br> tags should also be stripped.
	$br_gap = nova_gut_wrap_gap_content( "<br>\n<br />" );
	$check_no_br = ( '' === $br_gap );
	$results['checks']['standalone_br_stripped'] = $check_no_br;
	if ( ! $check_no_br ) {
		$results['pass']     = false;
		$results['errors'][] = 'Standalone <br> tags produced wp:html blocks instead of being stripped.';
	}

	// Valid gap content should still produce blocks.
	$valid_gap = nova_gut_wrap_gap_content( '<span>Loose text</span>' );
	$check_valid = ( false !== strpos( $valid_gap, '<!-- wp:html -->' ) );
	$results['checks']['valid_gap_content_preserved'] = $check_valid;
	if ( ! $check_valid ) {
		$results['pass']     = false;
		$results['errors'][] = 'Valid gap content was incorrectly stripped.';
	}

	// -----------------------------------------------------------------------
	// TEST F: Block wrapping preserves DOM order
	// -----------------------------------------------------------------------
	$ordered_html = '<h2>Section One</h2>'
	              . '<p>Paragraph one.</p>'
	              . '<figure><img src="photo.jpg" alt="Photo"></figure>'
	              . '<h2>Section Two</h2>'
	              . '<p>Paragraph two.</p>';

	$wrapped_ordered = nova_gut_wrap_html_in_blocks( $ordered_html );

	$pos_s1 = strpos( $wrapped_ordered, 'Section One' );
	$pos_p1 = strpos( $wrapped_ordered, 'Paragraph one' );
	$pos_img = strpos( $wrapped_ordered, 'photo.jpg' );
	$pos_s2 = strpos( $wrapped_ordered, 'Section Two' );
	$pos_p2 = strpos( $wrapped_ordered, 'Paragraph two' );

	$check_dom_order = ( $pos_s1 < $pos_p1 && $pos_p1 < $pos_img && $pos_img < $pos_s2 && $pos_s2 < $pos_p2 );
	$results['checks']['block_wrapping_preserves_dom_order'] = $check_dom_order;
	if ( ! $check_dom_order ) {
		$results['pass']     = false;
		$results['errors'][] = 'Block wrapping did not preserve DOM order.';
	}

	// -----------------------------------------------------------------------
	// TEST G: CTA group with buttons stays as footer (not mistaken for gallery)
	// -----------------------------------------------------------------------
	$cta_has_buttons = nova_gut_has_button_blocks( $source_blocks[3]['innerBlocks'] );
	$results['checks']['cta_has_buttons_detected'] = $cta_has_buttons;
	if ( ! $cta_has_buttons ) {
		$results['pass']     = false;
		$results['errors'][] = 'nova_gut_has_button_blocks failed to detect buttons in CTA group.';
	}

	// CTA group has media AND buttons → should still be classified as footer.
	$cta_has_media = nova_gut_has_media_blocks( $source_blocks[3]['innerBlocks'] );
	$check_cta_footer = ( $cta_has_media && $cta_has_buttons );
	$results['checks']['cta_with_buttons_remains_footer'] = $check_cta_footer;
	if ( ! $check_cta_footer ) {
		$results['pass']     = false;
		$results['errors'][] = 'CTA group with buttons + media was not correctly identified for footer logic.';
	}

	// -----------------------------------------------------------------------
	// TEST H: Custom block detection prevents wrong text replacement
	// -----------------------------------------------------------------------
	$blocks_with_custom = array(
		array(
			'blockName'    => 'core/heading',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '<h2>Label</h2>',
			'innerContent' => array( '<h2>Label</h2>' ),
		),
		array(
			'blockName'    => 'dtcmedia/grid-block',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '<div class="grid-block">...</div>',
			'innerContent' => array( '<div class="grid-block">...</div>' ),
		),
	);

	$check_custom = nova_gut_has_custom_blocks( $blocks_with_custom );
	$results['checks']['custom_block_detection'] = $check_custom;
	if ( ! $check_custom ) {
		$results['pass']     = false;
		$results['errors'][] = 'nova_gut_has_custom_blocks failed to detect dtcmedia/grid-block.';
	}

	// Core-only blocks should NOT trigger custom detection.
	$core_only_blocks = array(
		array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<h2>H</h2>', 'innerContent' => array( '<h2>H</h2>' ) ),
		array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>P</p>', 'innerContent' => array( '<p>P</p>' ) ),
	);
	$check_core_only = ! nova_gut_has_custom_blocks( $core_only_blocks );
	$results['checks']['core_only_no_custom_detection'] = $check_core_only;
	if ( ! $check_core_only ) {
		$results['pass']     = false;
		$results['errors'][] = 'nova_gut_has_custom_blocks incorrectly triggered for core-only blocks.';
	}

	// -----------------------------------------------------------------------
	// TEST I: Post-merge cleanup removes empty paragraphs and collapses spacers
	// -----------------------------------------------------------------------
	$dirty_blocks = array(
		array( 'blockName' => 'core/heading', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<h2>Keep</h2>', 'innerContent' => array( '<h2>Keep</h2>' ) ),
		array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p class="pk-block-paragraph"></p>', 'innerContent' => array( '<p class="pk-block-paragraph"></p>' ) ),
		array( 'blockName' => 'core/spacer', 'attrs' => array( 'height' => '100px' ), 'innerBlocks' => array(), 'innerHTML' => '<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>', 'innerContent' => array( '<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>' ) ),
		array( 'blockName' => 'core/spacer', 'attrs' => array( 'height' => '100px' ), 'innerBlocks' => array(), 'innerHTML' => '<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>', 'innerContent' => array( '<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>' ) ),
		array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p>Real text</p>', 'innerContent' => array( '<p>Real text</p>' ) ),
		array( 'blockName' => 'core/spacer', 'attrs' => array( 'height' => '44px' ), 'innerBlocks' => array(), 'innerHTML' => '<div style="height:44px" aria-hidden="true" class="wp-block-spacer"></div>', 'innerContent' => array( '<div style="height:44px" aria-hidden="true" class="wp-block-spacer"></div>' ) ),
		array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<p></p>', 'innerContent' => array( '<p></p>' ) ),
	);

	$cleaned = nova_gut_cleanup_merged_blocks( $dirty_blocks );
	$cleaned_names = array_map( function( $b ) { return $b['blockName']; }, $cleaned );

	// Should have: heading, spacer (1st only), paragraph "Real text". Trailing spacer+empty stripped.
	$expected_names = array( 'core/heading', 'core/spacer', 'core/paragraph' );
	$check_cleanup = ( $cleaned_names === $expected_names );
	$results['checks']['cleanup_removes_empty_and_collapses_spacers'] = $check_cleanup;
	if ( ! $check_cleanup ) {
		$results['pass']     = false;
		$results['errors'][] = 'cleanup_merged_blocks result: [' . implode( ', ', $cleaned_names ) . '] expected: [' . implode( ', ', $expected_names ) . ']';
	}

	return $results;
}

/**
 * Regression tests for table conversion, image placement, and image replacement.
 *
 * Tests:
 * A. Table with thead/tbody and caption → valid core/table block.
 * B. Table without thead/tbody (bare rows) → body rows in core/table.
 * C. Standalone <img> → wp:image block, not wp:html.
 * D. Linked image <a><img></a> → wp:image block.
 * E. Div-wrapped image → wp:image block.
 * F. Figure-wrapped image (regression) → wp:image block.
 * G. Image replacement with two data-wp-media-key images.
 * H. No core/spacer blocks > 120px in fixtures.
 * I. Balanced block structure (every open has a close).
 *
 * @return array  Result with 'pass', 'checks', and optional 'errors'.
 */
function nova_gut_verify_tables_and_images(): array {
	$results = array(
		'pass'   => true,
		'checks' => array(),
		'errors' => array(),
	);

	// -----------------------------------------------------------------------
	// Helper: check that all <!-- wp:X --> have matching <!-- /wp:X -->.
	// -----------------------------------------------------------------------
	$assert_balanced = function ( string $content ) {
		preg_match_all( '/<!-- wp:(\S+)(?:\s[^-]*)?\s*-->/', $content, $opens );
		preg_match_all( '/<!-- \/wp:(\S+)\s*-->/', $content, $closes );
		$open_sorted  = $opens[1];
		$close_sorted = $closes[1];
		sort( $open_sorted );
		sort( $close_sorted );
		return $open_sorted === $close_sorted;
	};

	// -----------------------------------------------------------------------
	// TEST A: Table with thead/tbody/caption
	// -----------------------------------------------------------------------
	$table_html = '<table>'
	            . '<caption>Price List</caption>'
	            . '<thead><tr><th>Item</th><th>Price</th></tr></thead>'
	            . '<tbody><tr><td>Widget</td><td><strong>$10</strong></td></tr>'
	            . '<tr><td>Gadget</td><td>$20</td></tr></tbody>'
	            . '</table>';

	$wrapped_table = nova_gut_wrap_html_in_blocks( $table_html );

	$check = ( false !== strpos( $wrapped_table, '<!-- wp:table' ) );
	$results['checks']['table_has_wp_table_block'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Table HTML did not produce a <!-- wp:table --> block.';
	}

	$check = ( false !== strpos( $wrapped_table, 'hasFixedLayout' ) );
	$results['checks']['table_has_fixed_layout_attr'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Table block is missing hasFixedLayout attribute.';
	}

	$check = ( false !== strpos( $wrapped_table, '<figure class="wp-block-table">' ) );
	$results['checks']['table_has_figure_wrapper'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Table block is missing <figure class="wp-block-table"> wrapper.';
	}

	$check = ( false !== strpos( $wrapped_table, '<thead>' ) && false !== strpos( $wrapped_table, '<tbody>' ) );
	$results['checks']['table_preserves_thead_tbody'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Table block does not preserve <thead>/<tbody> structure.';
	}

	$check = ( false !== strpos( $wrapped_table, '<figcaption>Price List</figcaption>' ) );
	$results['checks']['table_preserves_caption'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Table block does not preserve caption as <figcaption>.';
	}

	$check = ( false !== strpos( $wrapped_table, '<strong>$10</strong>' ) );
	$results['checks']['table_preserves_inline_markup'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Table block does not preserve inline <strong> markup in cells.';
	}

	// -----------------------------------------------------------------------
	// TEST B: Table without thead/tbody (bare rows)
	// -----------------------------------------------------------------------
	$bare_table = '<table><tr><td>A</td><td>B</td></tr><tr><td>C</td><td>D</td></tr></table>';
	$wrapped_bare = nova_gut_wrap_html_in_blocks( $bare_table );

	$check = ( false !== strpos( $wrapped_bare, '<tbody>' ) );
	$results['checks']['bare_table_has_tbody'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Bare table rows were not placed inside <tbody>.';
	}

	$check = ( false !== strpos( $wrapped_bare, '<!-- wp:table' ) && false !== strpos( $wrapped_bare, '<!-- /wp:table -->' ) );
	$results['checks']['bare_table_valid_block'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Bare table did not produce a valid wp:table block.';
	}

	// -----------------------------------------------------------------------
	// TEST C: Standalone <img> becomes wp:image, not wp:html
	// -----------------------------------------------------------------------
	$html_standalone_img = '<h2>Title</h2><img src="photo.jpg" alt="Photo"><p>Text after.</p>';
	$wrapped_img = nova_gut_wrap_html_in_blocks( $html_standalone_img );

	$check = ( false !== strpos( $wrapped_img, '<!-- wp:image -->' ) );
	$results['checks']['standalone_img_becomes_image_block'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Standalone <img> was not wrapped in <!-- wp:image --> block.';
	}

	$pos_title = strpos( $wrapped_img, 'Title' );
	$pos_photo = strpos( $wrapped_img, 'photo.jpg' );
	$pos_text  = strpos( $wrapped_img, 'Text after' );
	$check = ( $pos_title < $pos_photo && $pos_photo < $pos_text );
	$results['checks']['standalone_img_order_preserved'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Standalone <img> reordered content: DOM order not preserved.';
	}

	// -----------------------------------------------------------------------
	// TEST D: Linked image <a><img></a> becomes wp:image
	// -----------------------------------------------------------------------
	$html_linked = '<h2>Title</h2><a href="https://example.com"><img src="photo.jpg" alt="Photo"></a><p>After.</p>';
	$wrapped_linked = nova_gut_wrap_html_in_blocks( $html_linked );

	$check = ( false !== strpos( $wrapped_linked, '<!-- wp:image -->' ) );
	$results['checks']['linked_img_becomes_image_block'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Linked <a><img></a> was not wrapped in <!-- wp:image --> block.';
	}

	$check = ( false !== strpos( $wrapped_linked, '<a href=' ) );
	$results['checks']['linked_img_preserves_anchor'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Linked image lost its <a> wrapper.';
	}

	// -----------------------------------------------------------------------
	// TEST E: Div-wrapped image becomes wp:image
	// -----------------------------------------------------------------------
	$html_div_img = '<p>Before</p><div class="image-wrap"><img src="photo.jpg" alt="Photo"></div><p>After</p>';
	$wrapped_div = nova_gut_wrap_html_in_blocks( $html_div_img );

	$check = ( false !== strpos( $wrapped_div, '<!-- wp:image -->' ) );
	$results['checks']['div_wrapped_img_becomes_image_block'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Div-wrapped <img> was not wrapped in <!-- wp:image --> block.';
	}

	$pos_before = strpos( $wrapped_div, 'Before' );
	$pos_photo  = strpos( $wrapped_div, 'photo.jpg' );
	$pos_after  = strpos( $wrapped_div, 'After' );
	$check = ( $pos_before < $pos_photo && $pos_photo < $pos_after );
	$results['checks']['div_wrapped_img_order_preserved'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Div-wrapped image reordered content.';
	}

	// -----------------------------------------------------------------------
	// TEST F: Figure-wrapped image (regression)
	// -----------------------------------------------------------------------
	$html_fig = '<p>Before</p><figure><img src="photo.jpg" alt="Photo"></figure><p>After</p>';
	$wrapped_fig = nova_gut_wrap_html_in_blocks( $html_fig );

	$check = ( false !== strpos( $wrapped_fig, '<!-- wp:image -->' ) );
	$results['checks']['figure_img_regression'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Figure-wrapped <img> is not a <!-- wp:image --> block (regression).';
	}

	$pos_before = strpos( $wrapped_fig, 'Before' );
	$pos_photo  = strpos( $wrapped_fig, 'photo.jpg' );
	$pos_after  = strpos( $wrapped_fig, 'After' );
	$check = ( $pos_before < $pos_photo && $pos_photo < $pos_after );
	$results['checks']['figure_img_order_preserved'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Figure-wrapped image reordered content.';
	}

	// -----------------------------------------------------------------------
	// TEST G: Image replacement with two data-wp-media-key images
	// -----------------------------------------------------------------------
	$html_replace = '<p>Intro text.</p>'
	              . '<figure class="wp-block-image"><img data-wp-media-key="hero-img" src="placeholder.jpg" alt="old"></figure>'
	              . '<p>More text.</p>'
	              . '<figure class="wp-block-image"><img data-wp-media-key="sidebar-img" src="placeholder2.jpg" alt="old2"></figure>';

	$wrapped_replace = nova_gut_ensure_block_markup( $html_replace );

	$replacements = array(
		'hero-img'    => array(
			'id'      => 42,
			'url'     => 'https://example.com/hero.jpg',
			'alt'     => 'Hero image',
			'caption' => 'Our hero shot',
		),
		'sidebar-img' => array(
			'id'      => 99,
			'url'     => 'https://example.com/sidebar.jpg',
			'alt'     => 'Sidebar image',
		),
	);

	$replaced = nova_gut_apply_image_replacements( $wrapped_replace, $replacements );

	$check = ( false !== strpos( $replaced, '"id":42' ) );
	$results['checks']['replacement_hero_id'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Image replacement did not set id:42 for hero-img.';
	}

	$check = ( false !== strpos( $replaced, '"id":99' ) );
	$results['checks']['replacement_sidebar_id'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Image replacement did not set id:99 for sidebar-img.';
	}

	$check = ( false !== strpos( $replaced, 'src="https://example.com/hero.jpg"' ) );
	$results['checks']['replacement_hero_src'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Image replacement did not update src for hero-img.';
	}

	$check = ( false !== strpos( $replaced, 'alt="Hero image"' ) );
	$results['checks']['replacement_hero_alt'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Image replacement did not update alt for hero-img.';
	}

	$check = ( false !== strpos( $replaced, '"sizeSlug":"full"' ) );
	$results['checks']['replacement_size_slug'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Image replacement did not set sizeSlug to "full".';
	}

	$check = ( false !== strpos( $replaced, '"linkDestination":"none"' ) );
	$results['checks']['replacement_link_dest'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Image replacement did not set linkDestination to "none".';
	}

	$check = ( false !== strpos( $replaced, 'wp-image-42' ) );
	$results['checks']['replacement_wp_image_class'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Image replacement did not add wp-image-42 class.';
	}

	$check = ( false !== strpos( $replaced, '<figcaption>Our hero shot</figcaption>' ) );
	$results['checks']['replacement_caption'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Image replacement did not add figcaption for hero-img.';
	}

	// Count how many wp:image blocks have an id attr — should be 2.
	$id_count = preg_match_all( '/<!-- wp:image \{[^}]*"id":\d+/', $replaced );
	$check = ( 2 === $id_count );
	$results['checks']['replacement_two_images_matched'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = "Expected 2 replaced image blocks with id attrs, found {$id_count}.";
	}

	// -----------------------------------------------------------------------
	// TEST H: No core/spacer > 120px in test fixtures
	// -----------------------------------------------------------------------
	$all_outputs = array( $wrapped_table, $wrapped_bare, $wrapped_img, $wrapped_linked, $wrapped_div, $wrapped_fig, $replaced );
	$has_large_spacer = false;
	foreach ( $all_outputs as $output ) {
		if ( preg_match( '/core\/spacer\s+\{[^}]*"height"\s*:\s*"(\d+)px"/', $output, $sp_match ) ) {
			if ( (int) $sp_match[1] > 120 ) {
				$has_large_spacer = true;
			}
		}
	}
	$check = ! $has_large_spacer;
	$results['checks']['no_large_spacers'] = $check;
	if ( ! $check ) {
		$results['pass']     = false;
		$results['errors'][] = 'Found a core/spacer block with height > 120px in test fixtures.';
	}

	// -----------------------------------------------------------------------
	// TEST I: Balanced block structure
	// -----------------------------------------------------------------------
	$balanced_pass = true;
	$fixture_names = array( 'table', 'bare_table', 'standalone_img', 'linked_img', 'div_img', 'figure_img', 'replacement' );
	foreach ( array_combine( $fixture_names, $all_outputs ) as $name => $output ) {
		if ( ! $assert_balanced( $output ) ) {
			$balanced_pass       = false;
			$results['pass']     = false;
			$results['errors'][] = "Unbalanced block structure in fixture: {$name}.";
		}
	}
	$results['checks']['balanced_block_structure'] = $balanced_pass;

	return $results;
}

// Auto-run when loaded via WP-CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$all_pass = true;

	// --- Suite 1: Content verification ---
	$results = nova_gut_verify_content();

	WP_CLI::line( '' );
	WP_CLI::line( '=== NOVA Gutenberg Bridge – Content Verification ===' );
	WP_CLI::line( '' );

	foreach ( $results['checks'] as $name => $value ) {
		if ( is_bool( $value ) ) {
			WP_CLI::line( ( $value ? '  PASS' : '  FAIL' ) . '  ' . $name );
		} else {
			WP_CLI::line( '  INFO  ' . $name . ' = ' . print_r( $value, true ) );
		}
	}

	if ( ! $results['pass'] ) {
		$all_pass = false;
		foreach ( $results['errors'] as $err ) {
			WP_CLI::warning( $err );
		}
	}

	// --- Suite 2: Ordering & spacing regression tests ---
	$ordering_results = nova_gut_verify_ordering_and_spacing();

	WP_CLI::line( '' );
	WP_CLI::line( '=== NOVA Gutenberg Bridge – Ordering & Spacing Tests ===' );
	WP_CLI::line( '' );

	foreach ( $ordering_results['checks'] as $name => $value ) {
		if ( is_bool( $value ) ) {
			WP_CLI::line( ( $value ? '  PASS' : '  FAIL' ) . '  ' . $name );
		} else {
			WP_CLI::line( '  INFO  ' . $name . ' = ' . print_r( $value, true ) );
		}
	}

	if ( ! $ordering_results['pass'] ) {
		$all_pass = false;
		foreach ( $ordering_results['errors'] as $err ) {
			WP_CLI::warning( $err );
		}
	}

	// --- Suite 3: Table, Image & Replacement Tests ---
	$table_image_results = nova_gut_verify_tables_and_images();

	WP_CLI::line( '' );
	WP_CLI::line( '=== NOVA Gutenberg Bridge – Table, Image & Replacement Tests ===' );
	WP_CLI::line( '' );

	foreach ( $table_image_results['checks'] as $name => $value ) {
		if ( is_bool( $value ) ) {
			WP_CLI::line( ( $value ? '  PASS' : '  FAIL' ) . '  ' . $name );
		} else {
			WP_CLI::line( '  INFO  ' . $name . ' = ' . print_r( $value, true ) );
		}
	}

	if ( ! $table_image_results['pass'] ) {
		$all_pass = false;
		foreach ( $table_image_results['errors'] as $err ) {
			WP_CLI::warning( $err );
		}
	}

	WP_CLI::line( '' );

	if ( $all_pass ) {
		WP_CLI::success( 'All checks passed.' );
	} else {
		WP_CLI::error( 'Some checks failed.' );
	}
}
