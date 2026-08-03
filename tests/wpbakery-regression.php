<?php
/**
 * NOVA-268 regression check for the WPBakery bridge.
 *
 * Run with: wp eval-file tests/wpbakery-regression.php
 *
 * Covers template slot filling on content-filled templates: sections must land as
 * title+body pairs inside their own container, template chrome (hero / CTA / image
 * rows) must survive untouched, and overflow sections must inherit the styling of a
 * slot that was actually filled.
 */

if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}

function nova_wpb_test_assert( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function nova_wpb_test_request( $method, $route, $body, $id = null ) {
    $request = new WP_REST_Request( $method, $route );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_body( wp_json_encode( $body ) );
    if ( null !== $id ) {
        $request->set_param( 'id_or_slug', (string) $id );
    }
    return $request;
}

/** Shortcode fixture builders. */
function nova_wpb_test_row( $inner, $attrs = '' ) {
    return '[vc_row' . $attrs . '][vc_column]' . $inner . '[/vc_column][/vc_row]';
}

function nova_wpb_test_head( $text, $tag = 'h2' ) {
    return '[vc_custom_heading text="' . esc_attr( $text ) . '" use_theme_fonts="yes" font_container="tag:' . $tag . '" /]';
}

function nova_wpb_test_txt( $body ) {
    return '[vc_column_text]' . $body . '[/vc_column_text]';
}

function nova_wpb_test_section( $title, $body, $tag = 'h2' ) {
    return array(
        'title'     => $title,
        'body'      => $body,
        'title_tag' => $tag,
    );
}

/** Flatten a post's content into [ 'path' => ..., 'tag' => ..., 'text' => ... ] rows. */
function nova_wpb_test_outline( $shortcodes ) {
    $compact = nova_wpb_parse_shortcodes_to_compact( (string) $shortcodes );
    return nova_wpb_build_outline_from_compact( $compact, false );
}

function nova_wpb_test_texts( $shortcodes ) {
    $texts = array();
    foreach ( nova_wpb_test_outline( $shortcodes ) as $item ) {
        $texts[] = $item['text'];
    }
    return $texts;
}

/** Outline rows belonging to top-level row #$index. */
function nova_wpb_test_row_nodes( $shortcodes, $index ) {
    $rows = array();
    foreach ( nova_wpb_test_outline( $shortcodes ) as $item ) {
        if ( 0 === strpos( (string) $item['path'], $index . '.' ) ) {
            $rows[] = $item;
        }
    }
    return $rows;
}

function nova_wpb_test_source( $marker, $suffix, $shortcodes, &$created_ids ) {
    $source_id = wp_insert_post(
        array(
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => $marker . '-' . $suffix,
            'post_content' => wp_slash( $shortcodes ),
        ),
        true
    );
    nova_wpb_test_assert( ! is_wp_error( $source_id ), 'Could not create the ' . $suffix . ' source page.' );
    $created_ids[] = (int) $source_id;
    update_post_meta( $source_id, '_wpb_vc_js_status', 'true' );
    return (int) $source_id;
}

function nova_wpb_test_create( $marker, $suffix, $body, &$created_ids ) {
    $body['title']     = $marker . '-' . $suffix;
    $body['status']    = 'draft';
    $body['post_type'] = 'page';

    $result = nova_wpb_create_page( nova_wpb_test_request( 'POST', '/nova-wpbakery/v1/pages', $body ) );
    nova_wpb_test_assert( $result instanceof WP_REST_Response, $suffix . ': create did not return a REST response.' );
    nova_wpb_test_assert( 201 === $result->get_status(), $suffix . ': create did not return HTTP 201.' );

    $data       = $result->get_data();
    $created_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
    nova_wpb_test_assert( $created_id > 0, $suffix . ': create did not return a post ID.' );
    $created_ids[] = $created_id;

    return array( $created_id, $data );
}

$marker      = 'nova-wpb-regression-' . wp_generate_uuid4();
$created_ids = array();
$admins      = get_users(
    array(
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    )
);

nova_wpb_test_assert( ! empty( $admins ), 'No administrator is available for the regression check.' );
wp_set_current_user( (int) $admins[0] );

try {
    nova_wpb_test_assert( defined( 'NOVA_BRIDGE_SUITE_VERSION' ), 'NOVA Bridge Suite is not loaded.' );
    nova_wpb_test_assert( function_exists( 'nova_wpb_create_page' ), 'Enable the WPBakery bridge before running this check.' );
    nova_wpb_test_assert( function_exists( 'nova_wpb_collect_slot_candidates' ), 'The NOVA-268 slot model is not loaded.' );

    /* ---------------------------------------------------------------- 1. clean template */
    $clean = nova_wpb_test_row( nova_wpb_test_head( 'T1' ) . nova_wpb_test_txt( 'B1' ) )
        . nova_wpb_test_row( nova_wpb_test_head( 'T2' ) . nova_wpb_test_txt( 'B2' ) )
        . nova_wpb_test_row( nova_wpb_test_head( 'T3' ) . nova_wpb_test_txt( 'B3' ) );
    $clean_source = nova_wpb_test_source( $marker, 'clean-source', $clean, $created_ids );

    list( $clean_id, $clean_data ) = nova_wpb_test_create(
        $marker,
        'clean',
        array(
            'source_page_id'  => $clean_source,
            'text_updates'    => array(),
            'append_sections' => array(
                nova_wpb_test_section( 'S1', '<p>b1</p>' ),
                nova_wpb_test_section( 'S2', '<p>b2</p>' ),
                nova_wpb_test_section( 'S3', '<p>b3</p>' ),
            ),
        ),
        $created_ids
    );

    $clean_texts = nova_wpb_test_texts( get_post_field( 'post_content', $clean_id ) );
    nova_wpb_test_assert(
        array( 'S1', '<p>b1</p>', 'S2', '<p>b2</p>', 'S3', '<p>b3</p>' ) === $clean_texts,
        'An alternating template did not receive sections 1:1 in order.'
    );
    nova_wpb_test_assert( isset( $clean_data['nova']['slots_filled'] ) && 3 === (int) $clean_data['nova']['slots_filled'], 'Diagnostics did not report 3 filled slots.' );
    nova_wpb_test_assert( 0 === (int) $clean_data['nova']['sections_appended'], 'Diagnostics reported leftover sections on an exact-fit template.' );

    /* ------------------------------------------------- 2. content-filled template (NOVA-268) */
    $hero = '[vc_row css=".vc_custom_1{background-image:url(https://example.test/pattern-full.png)!important;}"][vc_column]'
        . nova_wpb_test_head( 'Hero title' ) . nova_wpb_test_txt( 'Hero copy' ) . '[/vc_column][/vc_row]';
    $image_row = nova_wpb_test_row( '[vc_single_image image="12" /]' . nova_wpb_test_txt( 'Caption' ) );
    $cta_row   = '[vc_row el_class="call-to-action"][vc_column]' . nova_wpb_test_head( 'CTA' )
        . nova_wpb_test_txt( 'CTA copy' ) . '[button btntext="Klik" /][/vc_column][/vc_row]';

    // The two slot rows are styled differently so the overflow shell can be pinned to
    // the LAST filled slot (overflow continues after it), not merely to "some slot".
    $filled = $hero
        . '[vc_row el_class="nova-slot-a"][vc_column width="2/3"]' . nova_wpb_test_head( 'Slot A' ) . nova_wpb_test_txt( 'Old A' ) . '[/vc_column][/vc_row]'
        . $image_row
        . '[vc_row el_class="nova-slot-b" el_id="slot-b-anchor"][vc_column width="1/2"]' . nova_wpb_test_head( 'Slot B' ) . nova_wpb_test_txt( 'Old B' ) . '[/vc_column][/vc_row]'
        . $cta_row;
    $filled_source = nova_wpb_test_source( $marker, 'filled-source', $filled, $created_ids );

    list( $filled_id, $filled_data ) = nova_wpb_test_create(
        $marker,
        'filled',
        array(
            'source_page_id'  => $filled_source,
            'text_updates'    => array(),
            'append_sections' => array(
                nova_wpb_test_section( 'S1', '<p>b1</p>' ),
                nova_wpb_test_section( 'S2', '<p>b2</p>' ),
                nova_wpb_test_section( 'S3', '<p>b3</p>' ),
                nova_wpb_test_section( 'S4', '<p>b4</p>' ),
            ),
        ),
        $created_ids
    );

    $filled_content = (string) get_post_field( 'post_content', $filled_id );
    $filled_texts   = nova_wpb_test_texts( $filled_content );

    foreach ( array( 'Hero title', 'Hero copy', 'Caption', 'CTA', 'CTA copy' ) as $chrome ) {
        nova_wpb_test_assert( in_array( $chrome, $filled_texts, true ), 'Template chrome "' . $chrome . '" was overwritten or blanked.' );
    }
    nova_wpb_test_assert( false !== strpos( $filled_content, 'btntext="Klik"' ), 'The CTA button label was lost.' );

    // Sections must be paired, in their own containers, in order.
    $slot_a = nova_wpb_test_row_nodes( $filled_content, 1 );
    $slot_b = nova_wpb_test_row_nodes( $filled_content, 3 );
    nova_wpb_test_assert( 'S1' === $slot_a[0]['text'] && '<p>b1</p>' === $slot_a[1]['text'], 'Section 1 title/body did not land together in the first slot.' );
    nova_wpb_test_assert( 'S2' === $slot_b[0]['text'] && '<p>b2</p>' === $slot_b[1]['text'], 'Section 2 title/body did not land together in the second slot.' );

    nova_wpb_test_assert( 2 === (int) $filled_data['nova']['slots_filled'], 'Diagnostics did not report 2 filled slots.' );
    nova_wpb_test_assert( 2 === (int) $filled_data['nova']['sections_appended'], 'Diagnostics did not report 2 overflowed sections.' );
    nova_wpb_test_assert( 3 === (int) $filled_data['nova']['rows_ineligible'], 'Hero, image and CTA rows were not all reported ineligible.' );

    // Overflow sections reuse the LAST filled slot's shell and never duplicate an el_id.
    nova_wpb_test_assert( false !== strpos( $filled_content, '<p>b3</p>' ), 'Overflow section 3 was dropped.' );
    nova_wpb_test_assert( false !== strpos( $filled_content, '<p>b4</p>' ), 'Overflow section 4 was dropped.' );
    nova_wpb_test_assert( 3 === substr_count( $filled_content, 'el_class="nova-slot-b"' ), 'Overflow rows did not reuse the last filled slot row styling.' );
    nova_wpb_test_assert( 3 === substr_count( $filled_content, 'width="1/2"' ), 'Overflow columns did not reuse the last filled slot column width.' );
    nova_wpb_test_assert( 1 === substr_count( $filled_content, 'el_class="nova-slot-a"' ), 'Overflow rows reused the wrong slot styling.' );
    nova_wpb_test_assert( 1 === substr_count( $filled_content, 'el_id="slot-b-anchor"' ), 'The cloned overflow shell duplicated a DOM id.' );

    /* ------------------------------------------- 3. heading with no text block in its column */
    $lonely = nova_wpb_test_row( nova_wpb_test_head( 'Lonely' ) )
        . nova_wpb_test_row( nova_wpb_test_head( 'Has both' ) . nova_wpb_test_txt( 'Old' ) );
    $lonely_source = nova_wpb_test_source( $marker, 'lonely-source', $lonely, $created_ids );

    list( $lonely_id ) = nova_wpb_test_create(
        $marker,
        'lonely',
        array(
            'source_page_id'  => $lonely_source,
            'append_sections' => array(
                nova_wpb_test_section( 'S1', '<p>b1</p>' ),
                nova_wpb_test_section( 'S2', '<p>b2</p>' ),
            ),
        ),
        $created_ids
    );

    $lonely_content = (string) get_post_field( 'post_content', $lonely_id );
    $lonely_row0    = nova_wpb_test_row_nodes( $lonely_content, 0 );
    $lonely_row1    = nova_wpb_test_row_nodes( $lonely_content, 1 );
    nova_wpb_test_assert( 2 === count( $lonely_row0 ), 'A text block was not injected next to the unpaired heading.' );
    nova_wpb_test_assert( 'S1' === $lonely_row0[0]['text'] && '<p>b1</p>' === $lonely_row0[1]['text'], 'Section 1 did not stay inside the first row.' );
    nova_wpb_test_assert( 'S2' === $lonely_row1[0]['text'] && '<p>b2</p>' === $lonely_row1[1]['text'], 'An unpaired heading desynced the following row.' );

    /* ------------------------------------------- 4. text block with no heading in its column */
    $orphan        = nova_wpb_test_row( nova_wpb_test_txt( 'Orphan' ) );
    $orphan_source = nova_wpb_test_source( $marker, 'orphan-source', $orphan, $created_ids );

    list( $orphan_id ) = nova_wpb_test_create(
        $marker,
        'orphan',
        array(
            'source_page_id'  => $orphan_source,
            'append_sections' => array( nova_wpb_test_section( 'S1', '<p>b1</p>' ) ),
        ),
        $created_ids
    );

    $orphan_outline = nova_wpb_test_outline( get_post_field( 'post_content', $orphan_id ) );
    nova_wpb_test_assert( 2 === count( $orphan_outline ), 'A heading was not injected before the unpaired text block.' );
    nova_wpb_test_assert( 'vc_custom_heading' === $orphan_outline[0]['tag'] && 'S1' === $orphan_outline[0]['text'], 'The injected heading did not carry the section title.' );
    nova_wpb_test_assert( 'vc_column_text' === $orphan_outline[1]['tag'] && '<p>b1</p>' === $orphan_outline[1]['text'], 'The section body did not land in the existing text block.' );

    /* ------------------------------------ 5. remove_paths + text_updates in the same request */
    $triple        = nova_wpb_test_row( nova_wpb_test_txt( 'A' ) . nova_wpb_test_txt( 'B' ) . nova_wpb_test_txt( 'C' ) );
    $triple_source = nova_wpb_test_source( $marker, 'triple-source', $triple, $created_ids );

    list( $triple_id ) = nova_wpb_test_create(
        $marker,
        'triple',
        array(
            'source_page_id' => $triple_source,
            'remove_paths'   => array( '0.0.0' ),
            'text_updates'   => array( array( 'path' => '0.0.2', 'text' => 'NEW-C' ) ),
        ),
        $created_ids
    );

    nova_wpb_test_assert(
        array( 'B', 'NEW-C' ) === nova_wpb_test_texts( get_post_field( 'post_content', $triple_id ) ),
        'remove_paths re-indexed siblings before text_updates were applied.'
    );

    /* ----------------------------------------- 6. first section title repeats the page title */
    $suppress        = $hero . nova_wpb_test_row( nova_wpb_test_head( 'Slot A' ) . nova_wpb_test_txt( 'Old A' ) );
    $suppress_source = nova_wpb_test_source( $marker, 'suppress-source', $suppress, $created_ids );
    $page_title      = 'Retoppers Dakkapel';

    $suppress_result = nova_wpb_create_page(
        nova_wpb_test_request(
            'POST',
            '/nova-wpbakery/v1/pages',
            array(
                'title'           => $page_title,
                'status'          => 'draft',
                'post_type'       => 'page',
                'source_page_id'  => $suppress_source,
                'append_sections' => array( nova_wpb_test_section( $page_title, '<p>b1</p>' ) ),
            )
        )
    );
    nova_wpb_test_assert( $suppress_result instanceof WP_REST_Response, 'suppress: create did not return a REST response.' );
    $suppress_data = $suppress_result->get_data();
    $suppress_id   = (int) $suppress_data['id'];
    $created_ids[] = $suppress_id;

    $suppress_content = (string) get_post_field( 'post_content', $suppress_id );
    $suppress_texts   = nova_wpb_test_texts( $suppress_content );
    nova_wpb_test_assert( in_array( 'Hero title', $suppress_texts, true ), 'The title-suppression rule consumed the hero heading.' );
    nova_wpb_test_assert( in_array( 'Hero copy', $suppress_texts, true ), 'The title-suppression rule overwrote the hero body.' );
    nova_wpb_test_assert( in_array( '<p>b1</p>', $suppress_texts, true ), 'The section body was lost while suppressing a duplicate title.' );
    nova_wpb_test_assert( 0 === (int) $suppress_data['nova']['sections_appended'], 'A suppressed-title section was appended instead of filling its slot.' );

    /* --------------------------------------------------- 7. keep_source_content escape hatch */
    list( $keep_id, $keep_data ) = nova_wpb_test_create(
        $marker,
        'keep',
        array(
            'source_page_id'      => $filled_source,
            'keep_source_content' => true,
            'append_sections'     => array( nova_wpb_test_section( 'Extra', '<p>extra</p>' ) ),
        ),
        $created_ids
    );

    $keep_texts = nova_wpb_test_texts( get_post_field( 'post_content', $keep_id ) );
    foreach ( array( 'Hero title', 'Slot A', 'Old A', 'Slot B', 'Old B', 'CTA copy' ) as $kept ) {
        nova_wpb_test_assert( in_array( $kept, $keep_texts, true ), 'keep_source_content dropped template text "' . $kept . '".' );
    }
    nova_wpb_test_assert( in_array( '<p>extra</p>', $keep_texts, true ), 'keep_source_content did not append the extra section.' );
    nova_wpb_test_assert( ! isset( $keep_data['nova'] ), 'Slot diagnostics were reported even though slot filling was skipped.' );

    echo 'PASS: WPBakery template slot filling, chrome preservation, overflow styling, and transform ordering.' . PHP_EOL;
} finally {
    $matches = get_posts(
        array(
            'post_type'      => 'any',
            'post_status'    => 'any',
            's'              => $marker,
            'fields'         => 'ids',
            'posts_per_page' => -1,
        )
    );
    foreach ( array_unique( array_merge( $created_ids, $matches ) ) as $post_id ) {
        if ( (int) $post_id > 0 ) {
            wp_delete_post( (int) $post_id, true );
        }
    }
}
