<?php
/**
 * Run with: wp eval-file tests/divi-regression.php
 */

if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}

function nova_divi_test_assert( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

function nova_divi_test_request( $method, $route, $body, $id = null ) {
    $request = new WP_REST_Request( $method, $route );
    $request->set_header( 'content-type', 'application/json' );
    $request->set_body( wp_json_encode( $body ) );
    if ( null !== $id ) {
        $request->set_param( 'id_or_slug', (string) $id );
    }
    return $request;
}

$marker      = 'nova-divi-regression-' . wp_generate_uuid4();
$created_ids = array();
$admins      = get_users(
    array(
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    )
);

nova_divi_test_assert( ! empty( $admins ), 'No administrator is available for the regression check.' );
wp_set_current_user( (int) $admins[0] );

try {
    nova_divi_test_assert( defined( 'NOVA_BRIDGE_SUITE_VERSION' ), 'NOVA Bridge Suite is not loaded.' );
    nova_divi_test_assert( function_exists( 'nova_divi_create_page' ), 'Enable the Divi bridge before running this check.' );
    nova_divi_test_assert( function_exists( 'cf_tmrb_update_post_meta_all_payload' ), 'The core meta_all handler is not loaded.' );

    $shortcodes = '[et_pb_section fb_built="1" _builder_version="4.27.4"][et_pb_row _builder_version="4.27.4"][et_pb_column type="4_4" _builder_version="4.27.4"][et_pb_text _builder_version="4.27.4"]<h2>Existing heading</h2><p>Existing body.</p>[/et_pb_text][et_pb_accordion _builder_version="4.27.4"][et_pb_accordion_item title="Old question" _builder_version="4.27.4"]<p>Old answer.</p>[/et_pb_accordion_item][/et_pb_accordion][/et_pb_column][/et_pb_row][/et_pb_section]';
    $source_id  = wp_insert_post(
        array(
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => $marker . '-source',
            'post_content' => wp_slash( $shortcodes ),
        ),
        true
    );
    nova_divi_test_assert( ! is_wp_error( $source_id ), 'Could not create the Divi 4 source page.' );
    $created_ids[] = (int) $source_id;
    update_post_meta( $source_id, '_et_pb_use_builder', 'on' );

    $outline = nova_divi_build_outline_from_compact( nova_divi_parse_shortcodes_to_compact( $shortcodes ), false );
    $faq_path = '';
    foreach ( $outline as $item ) {
        if ( isset( $item['tag'], $item['path'] ) && 'et_pb_accordion_item' === $item['tag'] ) {
            $faq_path = (string) $item['path'];
            break;
        }
    }
    nova_divi_test_assert( '' !== $faq_path, 'The accordion item was not present in the outline.' );

    $seo_input = array(
        'meta_title'       => 'Regression SEO title',
        'meta_description' => 'Regression SEO description',
        $marker            => 'meta-all',
    );
    $create_result = nova_divi_create_page(
        nova_divi_test_request(
            'POST',
            '/nova-divi/v1/pages',
            array(
                'title'          => $marker . '-created',
                'status'         => 'draft',
                'post_type'      => 'page',
                'source_page_id' => (int) $source_id,
                'text_updates'   => array(
                    array( 'path' => $faq_path, 'field' => 'title', 'text' => 'New question' ),
                    array( 'path' => $faq_path, 'field' => 'body', 'text' => '<p>New answer.</p>' ),
                ),
                'meta_all'       => $seo_input,
                'meta'           => array( $marker => 'legacy-wins' ),
            )
        )
    );
    nova_divi_test_assert( $create_result instanceof WP_REST_Response, 'Divi create did not return a REST response.' );
    nova_divi_test_assert( 201 === $create_result->get_status(), 'Divi create did not return HTTP 201.' );
    $create_data = $create_result->get_data();
    $created_id  = isset( $create_data['id'] ) ? (int) $create_data['id'] : 0;
    nova_divi_test_assert( $created_id > 0, 'Divi create did not return a post ID.' );
    $created_ids[] = $created_id;

    $created_content = (string) get_post_field( 'post_content', $created_id );
    nova_divi_test_assert( false !== strpos( $created_content, 'title="New question"' ), 'The accordion title was not updated.' );
    nova_divi_test_assert( false !== strpos( $created_content, '<p>New answer.</p>' ), 'The accordion body was not updated.' );
    nova_divi_test_assert( 'legacy-wins' === get_post_meta( $created_id, $marker, true ), 'Legacy meta did not retain documented precedence.' );

    $seo_resolution = cf_tmrb_resolve_post_seo_from_meta_input( $seo_input );
    foreach ( $seo_resolution['updates'] as $key => $value ) {
        nova_divi_test_assert( $value === get_post_meta( $created_id, $key, true ), 'SEO metadata was not written to ' . $key . '.' );
    }

    $invalid_slug   = sanitize_title( $marker . '-invalid' );
    $invalid_result = nova_divi_create_page(
        nova_divi_test_request(
            'POST',
            '/nova-divi/v1/pages',
            array(
                'title'    => $marker . '-invalid',
                'slug'     => $invalid_slug,
                'status'   => 'draft',
                'meta_all' => 'not-an-object',
            )
        )
    );
    nova_divi_test_assert( is_wp_error( $invalid_result ), 'Invalid meta_all was accepted.' );
    nova_divi_test_assert( 400 === (int) $invalid_result->get_error_data()['status'], 'Invalid meta_all did not return HTTP 400.' );
    nova_divi_test_assert( null === get_page_by_path( $invalid_slug, OBJECT, 'page' ), 'Invalid meta_all inserted a page before failing.' );

    $native_content = <<<'DIVI5'
<!-- wp:divi/placeholder -->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"$variable({\"type\":\"content\",\"value\":{\"name\":\"post_title\",\"settings\":{\"before\":\"<h1>\",\"after\":\"</h1>\"}}})$"}}}} /-->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<h2>Old section</h2><p>Old copy.</p>"}}},"builderVersion":"5.7.3"} /-->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"Desktop old"},"tablet":{"value":"Tablet old"},"phone":{"value":""}}},"builderVersion":"5.7.3"} /-->
<!-- wp:divi/blurb {"title":{"innerContent":{"desktop":{"value":{"text":"Blurb old"}}}},"content":{"innerContent":{"desktop":{"value":"<p>Blurb body old.</p>"}}},"module":{"advanced":{"link":{"desktop":{"value":{"url":"#old-link"}}}},"decoration":{"attributes":{"desktop":{"value":{"attributes":[{"name":"id","value":"old-anchor","targetElement":""},{"name":"data-keep","value":"opaque"}]}}}}},"builderVersion":"5.7.3"} /-->
<!-- wp:dsm/button {"opaque":{"nested":"Keep exactly \u002d\u002d \u003c \u0026 \u003e"}} /-->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"<h2>Heading old</h2>"}}},"builderVersion":"5.7.3"} /-->
<!-- wp:divi/accordion {"locked":{"desktop":{"value":"off"}}} -->
<!-- wp:divi/accordion-item {"title":{"innerContent":{"desktop":{"value":"Question old?"}}},"content":{"innerContent":{"desktop":{"value":"<p>Answer old.</p>"}}},"builderVersion":"5.7.3"} /-->
<!-- /wp:divi/accordion -->
<!-- wp:divi/text {"locked":{"desktop":{"value":"on"}},"content":{"innerContent":{"desktop":{"value":"Locked copy"}}}} /-->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"[gravityform id=\"2\"]"}}}} /-->
<!-- /wp:divi/placeholder -->
DIVI5;
    $native_id      = wp_insert_post(
        array(
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => $marker . '-native',
            'post_content' => wp_slash( $native_content ),
        ),
        true
    );
    nova_divi_test_assert( ! is_wp_error( $native_id ), 'Could not create the native Divi 5 test page.' );
    $created_ids[] = (int) $native_id;
    $native_content = (string) get_post_field( 'post_content', $native_id );
    $native_hash    = nova_divi5_document_hash( $native_content );

    $native_get = new WP_REST_Request( 'GET', '/nova-divi/v1/pages/' . $native_id );
    $native_get->set_param( 'id_or_slug', (string) $native_id );
    $native_get->set_param( 'layout_mode', 'outline' );
    $native_get->set_param( 'text_map', true );
    $native_get_result = nova_divi_get_page( $native_get );
    nova_divi_test_assert( $native_get_result instanceof WP_REST_Response, 'Native Divi 5 inspection failed.' );
    $native_get_data = $native_get_result->get_data();
    nova_divi_test_assert( 'divi5-blocks' === $native_get_data['layout']['content_format'], 'Native Divi 5 format was not reported.' );
    nova_divi_test_assert( 'native-block-v1' === $native_get_data['layout']['path_scheme'], 'Native path scheme was not reported.' );
    nova_divi_test_assert( $native_hash === $native_get_data['layout']['document_hash'], 'Native document hash does not match stored content.' );
    nova_divi_test_assert( true === $native_get_data['layout']['capabilities']['text_updates'], 'Native text updates were not advertised.' );
    nova_divi_test_assert( false === $native_get_data['layout']['capabilities']['append_sections'], 'Native structural appends were advertised.' );

    $outline_by_path = array();
    foreach ( $native_get_data['layout']['outline'] as $item ) {
        $outline_by_path[ $item['path'] ] = $item;
    }
    nova_divi_test_assert( 'post_title' === $outline_by_path['0.0']['dynamic'], 'Dynamic post title was not identified.' );
    nova_divi_test_assert( false === $outline_by_path['0.0']['editable'], 'Dynamic post title was exposed as editable.' );
    nova_divi_test_assert( true === $outline_by_path['0.2']['fields']['body']['requires_sync_responsive'], 'Responsive override was not reported.' );
    nova_divi_test_assert( isset( $outline_by_path['0.3']['fields']['title'], $outline_by_path['0.3']['fields']['body'] ), 'Blurb title/body were not exposed on one path.' );
    nova_divi_test_assert( 'faq_item' === $outline_by_path['0.6.0']['role'], 'Existing native FAQ item was not identified.' );
    foreach ( $native_get_data['text_map'] as $text_map_item ) {
        if ( '0.2' === $text_map_item['path'] && 'body' === $text_map_item['field'] ) {
            nova_divi_test_assert( true === $text_map_item['requires_sync_responsive'], 'Text map omitted the responsive precondition.' );
            nova_divi_test_assert( 'Tablet old' === $text_map_item['responsive']['tablet'], 'Text map omitted the tablet value.' );
        }
    }

    $native_updates = array(
        array( 'path' => '0.1', 'field' => 'body', 'text' => '<h2>New section</h2><p>New copy.</p>' ),
        array( 'path' => '0.2', 'field' => 'body', 'text' => 'Responsive new', 'sync_responsive' => true ),
        array( 'path' => '0.3', 'field' => 'title', 'text' => 'Blurb new' ),
        array( 'path' => '0.3', 'field' => 'body', 'text' => '<p>Blurb body new.</p>' ),
        array( 'path' => '0.3', 'field' => 'link_url', 'text' => '#new-link' ),
        array( 'path' => '0.3', 'field' => 'anchor_id', 'text' => 'New Anchor' ),
        array( 'path' => '0.5', 'field' => 'title', 'text' => '<h2>Heading new</h2>' ),
        array( 'path' => '0.6.0', 'field' => 'title', 'text' => 'Question new?' ),
        array( 'path' => '0.6.0', 'field' => 'body', 'text' => '<p>Answer new.</p>' ),
    );
    $expected_native = nova_divi5_apply_text_updates( $native_content, $native_updates );
    nova_divi_test_assert( ! is_wp_error( $expected_native ), 'Native scalar patcher rejected supported fields.' );
    nova_divi_test_assert( false !== strpos( $expected_native, '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\u003ch2\u003eNew section\u003c/h2\u003e\u003cp\u003eNew copy.\u003c/p\u003e"}}},"builderVersion":"5.7.3"} /-->' ), 'Native text scalar was not encoded in the expected delimiter.' );
    nova_divi_test_assert( false !== strpos( $expected_native, '<!-- wp:dsm/button {"opaque":{"nested":"Keep exactly \u002d\u002d \u003c \u0026 \u003e"}} /-->' ), 'Opaque DSM bytes changed.' );

    $native_create = nova_divi_create_page(
        nova_divi_test_request(
            'POST',
            '/nova-divi/v1/pages',
            array(
                'title'          => $marker . '-native-clone',
                'status'         => 'draft',
                'source_page_id'      => (int) $native_id,
                'source_document_hash' => $native_hash,
                'text_updates'        => $native_updates,
                'meta_all'            => array( $marker => 'native-create-meta' ),
            )
        )
    );
    nova_divi_test_assert( $native_create instanceof WP_REST_Response, 'Native Divi 5 clone failed.' );
    nova_divi_test_assert( 201 === $native_create->get_status(), 'Native Divi 5 clone did not return HTTP 201.' );
    $native_clone_id = (int) $native_create->get_data()['id'];
    $created_ids[]   = $native_clone_id;
    nova_divi_test_assert( $expected_native === (string) get_post_field( 'post_content', $native_clone_id ), 'Native clone was not stored byte-for-byte.' );
    nova_divi_test_assert( $native_content === (string) get_post_field( 'post_content', $native_id ), 'Native clone changed its source.' );
    nova_divi_test_assert( 1 === substr_count( $expected_native, 'wp:divi/accordion-item' ), 'Native FAQ item was duplicated.' );
    nova_divi_test_assert( 'on' === get_post_meta( $native_clone_id, '_et_pb_use_builder', true ), 'Native clone was not marked as a builder page.' );
    nova_divi_test_assert( 'native-create-meta' === get_post_meta( $native_clone_id, $marker, true ), 'Native create metadata was not applied.' );

    $missing_hash_result = nova_divi_create_page(
        nova_divi_test_request(
            'POST',
            '/nova-divi/v1/pages',
            array(
                'title'          => $marker . '-missing-hash',
                'slug'           => sanitize_title( $marker . '-missing-hash' ),
                'status'         => 'draft',
                'source_page_id' => (int) $native_id,
                'text_updates'   => array( array( 'path' => '0.1', 'field' => 'body', 'text' => 'Unsafe' ) ),
            )
        )
    );
    nova_divi_test_assert( is_wp_error( $missing_hash_result ), 'Native clone accepted text updates without a source hash.' );
    nova_divi_test_assert( 428 === (int) $missing_hash_result->get_error_data()['status'], 'Missing native source hash did not return HTTP 428.' );

    $responsive_result = nova_divi5_apply_text_updates(
        $native_content,
        array( array( 'path' => '0.2', 'field' => 'body', 'text' => 'Unsafe' ) )
    );
    nova_divi_test_assert( is_wp_error( $responsive_result ), 'Responsive native text was overwritten without explicit synchronization.' );
    nova_divi_test_assert( 'nova_divi5_responsive_precondition' === $responsive_result->get_error_code(), 'Responsive failure used the wrong error.' );

    $dynamic_result = nova_divi5_apply_text_updates(
        $native_content,
        array( array( 'path' => '0.0', 'field' => 'body', 'text' => '<h1>Unsafe</h1>' ) )
    );
    nova_divi_test_assert( is_wp_error( $dynamic_result ), 'Dynamic native H1 was writable.' );

    $locked_result = nova_divi5_apply_text_updates(
        $native_content,
        array( array( 'path' => '0.7', 'field' => 'body', 'text' => 'Unsafe' ) )
    );
    nova_divi_test_assert( is_wp_error( $locked_result ), 'Locked native content was writable.' );

    $duplicate_json = '<!-- wp:divi/placeholder --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"one","value":"two"}}}} /--><!-- /wp:divi/placeholder -->';
    $duplicate_result = nova_divi5_apply_text_updates(
        $duplicate_json,
        array( array( 'path' => '0.0', 'field' => 'body', 'text' => 'Unsafe' ) )
    );
    nova_divi_test_assert( is_wp_error( $duplicate_result ), 'Duplicate native JSON keys were accepted.' );

    $unknown_breakpoint = str_replace( '"phone":{"value":""}', '"watch":{"value":"Watch old"}', $native_content );
    $unknown_breakpoint_result = nova_divi5_apply_text_updates(
        $unknown_breakpoint,
        array( array( 'path' => '0.2', 'field' => 'body', 'text' => 'Unsafe', 'sync_responsive' => true ) )
    );
    nova_divi_test_assert( is_wp_error( $unknown_breakpoint_result ), 'Unknown native breakpoint was writable.' );

    $malformed_document = str_replace( '<!-- /wp:divi/placeholder -->', '<!-- /wp:divi/placeholder -- >', $native_content );
    nova_divi_test_assert( is_wp_error( nova_divi5_scan_document( $malformed_document ) ), 'Malformed native delimiter was ignored.' );
    $spaced_malformed = $native_content . '<!--  wp:divi/text /-- >';
    nova_divi_test_assert( is_wp_error( nova_divi5_scan_document( $spaced_malformed ) ), 'Malformed whitespace delimiter was ignored.' );

    $ambiguous_update = nova_divi5_apply_text_updates(
        $native_content,
        array(
            array(
                'path'            => '0.1',
                'field'           => 'body',
                'text'            => 'Unsafe',
                'responsive_text' => array( 'desktop' => 'Unsafe' ),
            ),
        )
    );
    nova_divi_test_assert( is_wp_error( $ambiguous_update ), 'Ambiguous native text payload was accepted.' );

    $clone_content_before_block = (string) get_post_field( 'post_content', $native_clone_id );
    $clone_title_before_block   = get_the_title( $native_clone_id );
    $native_blocked_update      = nova_divi_update_page(
        nova_divi_test_request(
            'PUT',
            '/nova-divi/v1/pages/' . $native_clone_id,
            array(
                'title'           => $marker . '-should-not-write',
                'append_sections' => array( array( 'type' => 'faq', 'title' => 'Unsafe' ) ),
            ),
            $native_clone_id
        )
    );
    nova_divi_test_assert( is_wp_error( $native_blocked_update ), 'Native structural update was accepted.' );
    nova_divi_test_assert( 422 === (int) $native_blocked_update->get_error_data()['status'], 'Native structural update did not return HTTP 422.' );
    nova_divi_test_assert( $clone_title_before_block === get_the_title( $native_clone_id ), 'Blocked native update changed the title.' );
    nova_divi_test_assert( $clone_content_before_block === (string) get_post_field( 'post_content', $native_clone_id ), 'Blocked native update changed content.' );

    $stale_update = nova_divi_update_page(
        nova_divi_test_request(
            'PUT',
            '/nova-divi/v1/pages/' . $native_clone_id,
            array(
                'title'         => $marker . '-stale-should-not-write',
                'document_hash' => 'sha256:' . str_repeat( '0', 64 ),
                'text_updates'  => array( array( 'path' => '0.1', 'field' => 'body', 'text' => 'Unsafe' ) ),
            ),
            $native_clone_id
        )
    );
    nova_divi_test_assert( is_wp_error( $stale_update ), 'Stale native document hash was accepted.' );
    nova_divi_test_assert( 409 === (int) $stale_update->get_error_data()['status'], 'Stale native document hash did not return HTTP 409.' );
    nova_divi_test_assert( ! isset( $stale_update->get_error_data()['expected'] ), 'Stale response leaked a retryable current hash.' );
    nova_divi_test_assert( $clone_title_before_block === get_the_title( $native_clone_id ), 'Stale native update changed the title.' );

    $native_update_input = array(
        array( 'path' => '0.6.0', 'field' => 'title', 'text' => 'Question updated again?' ),
        array( 'path' => '0.6.0', 'field' => 'body', 'text' => '<p>Answer updated again.</p>' ),
    );
    $expected_update = nova_divi5_apply_text_updates( $clone_content_before_block, $native_update_input );
    $native_update = nova_divi_update_page(
        nova_divi_test_request(
            'PUT',
            '/nova-divi/v1/pages/' . $native_clone_id,
            array(
                'title'         => $marker . '-native-updated',
                'document_hash' => nova_divi5_document_hash( $clone_content_before_block ),
                'text_updates'  => $native_update_input,
            ),
            $native_clone_id
        )
    );
    nova_divi_test_assert( $native_update instanceof WP_REST_Response, 'Native Divi 5 update failed.' );
    nova_divi_test_assert( $expected_update === (string) get_post_field( 'post_content', $native_clone_id ), 'Native update was not stored byte-for-byte.' );
    nova_divi_test_assert( $marker . '-native-updated' === get_the_title( $native_clone_id ), 'Native update did not change the post title.' );

    $native_meta_hash = nova_divi5_document_hash( (string) get_post_field( 'post_content', $native_clone_id ) );
    $native_meta_result = nova_divi_update_page(
        nova_divi_test_request(
            'PUT',
            '/nova-divi/v1/pages/' . $native_clone_id,
            array( 'meta_all' => array( $marker => 'native-meta' ) ),
            $native_clone_id
        )
    );
    nova_divi_test_assert( $native_meta_result instanceof WP_REST_Response, 'A byte-preserving native metadata update failed.' );
    nova_divi_test_assert( 'native-meta' === get_post_meta( $native_clone_id, $marker, true ), 'Native metadata was not applied.' );
    nova_divi_test_assert( $native_meta_hash === nova_divi5_document_hash( (string) get_post_field( 'post_content', $native_clone_id ) ), 'A metadata-only native update changed content.' );

    echo 'PASS: Divi 4 compatibility, metadata, and byte-preserving native Divi 5 text updates.' . PHP_EOL;
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
