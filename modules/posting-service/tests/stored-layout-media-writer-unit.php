<?php
/** Real media/provider/writer code; all WordPress persistence and downloads stay in memory/local fixtures. */
require __DIR__ . '/stored-layout-writer-unit.php';

function get_post_thumbnail_id( $id ) { return (int) get_post_meta( $id, '_thumbnail_id', true ); }
function set_post_thumbnail( $id, $attachment ) { if ( ! empty( $GLOBALS['drop_thumbnail'] ) ) { return false; } return update_post_meta( $id, '_thumbnail_id', $attachment ); }
function delete_post_thumbnail( $id ) { return delete_post_meta( $id, '_thumbnail_id' ); }
function wp_http_validate_url( $url ) { return str_starts_with( $url, 'https://images.example.test/' ) ? $url : false; }
function wp_tempnam( $name ) { return tempnam( sys_get_temp_dir(), 'nova-media-test-' ); }
function wp_safe_remote_get( $url, $args ) { ++$GLOBALS['media_downloads']; if ( $GLOBALS['bad_image'] ) { return new WP_Error( 'download', 'Unavailable image' ); } file_put_contents( $args['filename'], base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6Y6IAAAAASUVORK5CYII=' ) ); return [ 'status' => 200 ]; }
function wp_remote_retrieve_response_code( $r ) { return $r['status'] ?? 0; }
function wp_attachment_is_image( $id ) { return ( get_post( $id )->post_type ?? '' ) === 'attachment'; }
function wp_get_attachment_url( $id ) { return wp_attachment_is_image( $id ) ? 'https://example.test/uploads/local-' . $id . '.png' : false; }
function wp_get_attachment_metadata( $id ) { return [ 'width' => 1, 'height' => 1 ]; }
function wp_generate_attachment_metadata( $id, $file ) { return [ 'width' => 1, 'height' => 1 ]; }
function wp_update_attachment_metadata( $id, $data ) { return true; }
function media_handle_sideload( $file, $post_id, $description, $data ) {
    $p = new WP_Post(); $p->ID = 100 + $GLOBALS['attachment_count']++; $p->post_type = 'attachment'; $p->post_status = 'inherit'; $p->post_name = 'image-' . $p->ID; $p->post_content_filtered = $data['post_content_filtered']; $p->post_parent = $post_id; $GLOBALS['posts'][$p->ID] = $p;
    if ( $GLOBALS['crash_image'] ) { $GLOBALS['crash_image'] = false; throw new RuntimeException( 'Lost sideload response' ); }
    return $p->ID;
}
class Media_Layout_DB extends Layout_DB {
    public function get_col( $query ) {
        if ( strpos( $query[0], 'post_type = %s AND post_content_filtered = %s' ) === false ) { return parent::get_col( $query ); }
        $ids = []; foreach ( $GLOBALS['posts'] as $p ) { if ( $p->post_type === $query[1][0] && $p->post_content_filtered === $query[1][1] ) { $ids[] = $p->ID; } } return $ids;
    }
}
$wpdb = new Media_Layout_DB(); $start_checks = $checks;
function reset_media() { reset_native(); $GLOBALS['media_downloads'] = $GLOBALS['attachment_count'] = 0; $GLOBALS['bad_image'] = $GLOBALS['crash_image'] = $GLOBALS['drop_thumbnail'] = false; }
function media_plan( $operation = 1, $civ = '501', $url = 'https://images.example.test/generated.png' ) {
    $p = planned( $operation, $civ );
    $p['payload']['elementor_data'][0]['settings']['background_image'] = [ 'id' => 7, 'url' => 'https://example.test/template.jpg' ];
    $p['payload']['elementor_data'][0]['settings']['slides'] = [ [ 'photo' => [ 'id' => 8, 'url' => 'https://example.test/slide.jpg' ] ] ];
    $p['payload']['nova_media'] = [ '/native_post/featured_image' => [ 'kind' => 'featured', 'url' => $url ] ];
    foreach ( [ '/document/0/settings/background_image/url', '/document/0/settings/slides/0/photo/url' ] as $path ) { $p['payload']['nova_media'][$path] = [ 'kind' => 'elementor', 'url' => $url, 'url_target' => $path, 'id_target' => substr( $path, 0, -4 ) . '/id' ]; }
    if ( $p['target_post_id'] ) { $snapshot = new ReflectionMethod( Nova_Bridge_Suite_Stored_Layout_Writer::class, 'native_state' ); $p['native_before'] = $snapshot->invoke( null, $p['target_post_id'], $p['payload']['theme_presentation'] ?? null, $p['payload']['elementor_document_settings'] ?? null, 'elementor', $p['payload'] ); }
    unset( $p['plan_digest'] ); $p['plan_digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $p ) ); return $p;
}
function image_settings( $id = 10 ) { return json_decode( get_post_meta( $id, '_elementor_data', true ), true )[0]['settings']; }

reset_media(); $p = media_plan(); $frozen = $p; $result = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ) );
check( $media_downloads === 1 && $attachment_count === 1 && get_post_thumbnail_id( 10 ) === 100, 'Featured plus background/repeater bindings share one imported attachment.' );
check( image_settings()['background_image'] === [ 'id' => 100, 'url' => wp_get_attachment_url( 100 ) ] && image_settings()['slides'][0]['photo']['id'] === 100, 'Elementor nested native controls receive a consistent local URL and attachment ID.' );
check( $p === $frozen && $p['payload']['elementor_data'][0]['settings']['background_image']['id'] === 7, 'Runtime resolution leaves the immutable plan and source template intact.' );
$options_before = $options; $meta_before = $meta; ok( Nova_Bridge_Suite_Stored_Layout_Provider::verify( 'elementor', 10, $p['payload'], op( 1 ) ) );
check( $options === $options_before && $meta === $meta_before && $media_downloads === 1, 'Verification reads retained media without downloads, metadata changes, or journal writes.' );
ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $p, op( 1 ) ) ); ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ) );
check( $inserts === 1 && $saves === 1 && $attachment_count === 1, 'Repeated publication/recovery reuses both native page and attachment.' );
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-owned-cpt-media.php';
check( Nova_Bridge_Suite_Owned_CPT_Media::retained( op( 1 ), 'https://images.example.test/generated.png' ) === 100, 'The former CPT class and journal keys resolve the same retained attachment.' );
$meta[10]['_thumbnail_id'] = 41;
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::verify( $p, $result ) ), 'An edited featured image invalidates native readback.' );
$replacement = media_plan( 2, '502', 'https://images.example.test/replacement.png' );
check( $replacement['native_before']['featured_image'] === 41, 'A newer delivery can replace an edited featured image using its current value as the operation baseline.' );
ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $replacement, op( 2 ) ) );
check( $inserts === 1 && $saves === 2 && get_post_thumbnail_id( 10 ) === 101 && image_settings()['background_image']['id'] === 101, 'The approved higher version replaces the mapped images on the same page.' );

reset_media(); $old = planned(); $old_result = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $old, op( 1 ) ) ); $meta[10]['_thumbnail_id'] = 9;
ok( Nova_Bridge_Suite_Stored_Layout_Writer::verify( $old, $old_result ) );
check( ! isset( Nova_Bridge_Suite_Stored_Layout_Provider::snapshot_scope( 'elementor', $old['payload'] )['nova_featured'] ), 'Legacy manifest-free plans and associations retain their original digest scope.' );

reset_media(); $p = media_plan(); $crash_image = true;
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ) ) && $saves === 0, 'A lost sideload response does not claim a native document save.' );
$proof = ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $p, op( 1 ) ) ); check( $proof['safe_to_apply'] && $proof['post_id'] === 10, 'Media interruption retains the original reserved native identity.' );
ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ) ); check( $media_downloads === 1 && $attachment_count === 1 && $saves === 1, 'Interrupted attachment insertion resumes without another download or attachment.' );

foreach ( [ false, true ] as $existing ) {
    reset_media(); if ( $existing ) { existing_page(); $meta[10]['_thumbnail_id'] = 7; } $p = media_plan(); $crash_save = true;
    check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ) ), 'A response lost between native content and featured media is surfaced.' );
    $before = [ $options, $meta, $media_downloads ]; $proof = ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $p, op( 1 ) ) );
    check( $proof['state'] === 'not_committed' && $proof['safe_to_apply'] && $before === [ $options, $meta, $media_downloads ], 'Recovery recognizes the unchanged previous thumbnail and stays read-only.' );
    ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ) ); check( $saves === 1 && get_post_thumbnail_id( 10 ) === 100 && $attachment_count === 1, 'Resume finishes only the missing thumbnail, without repeating the document save.' );
}
reset_media(); $p = media_plan(); $crash_save = true; Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ); $meta[10]['_thumbnail_id'] = 44;
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $p, op( 1 ) ) ) && is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ) ) && get_post_thumbnail_id( 10 ) === 44, 'Partial-save recovery cannot overwrite an intervening human thumbnail choice.' );
reset_media(); $p = media_plan(); $crash_save = true; Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ); $changed = json_decode( $meta[10]['_elementor_data'], true ); $changed[0]['elements'][1]['settings']['editor'] = '<p>Human-edited body</p>'; $meta[10]['_elementor_data'] = json_encode( $changed );
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $p, op( 1 ) ) ) && get_post_thumbnail_id( 10 ) === 0, 'Pending featured recovery also blocks intervening content changes.' );
reset_media(); $p = media_plan(); $drop_thumbnail = true; check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ) ) && get_post_status( 10 ) === 'draft', 'A dropped featured-image write cannot claim complete publication.' );
$drop_thumbnail = false; ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ) ); check( $saves === 1 && get_post_thumbnail_id( 10 ) === 100, 'A retried thumbnail write does not repeat content insertion or native save.' );
unset( $posts[100] ); check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Provider::verify( 'elementor', 10, $p['payload'], op( 1 ) ) ) && $media_downloads === 1, 'Readback rejects a deleted retained attachment without silently downloading a replacement.' );

reset_media(); existing_page(); $meta[10]['_thumbnail_id'] = 17; $p = media_plan(); $bad_image = true;
ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ) );
check( $media_downloads === 1 && $attachment_count === 0 && get_post_thumbnail_id( 10 ) === 17 && image_settings()['background_image']['id'] === 7, 'A known optional image failure preserves template controls and the existing thumbnail.' );
ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $p, op( 1 ) ) ); check( $media_downloads === 1, 'Known skipped media is not downloaded again during recovery.' );

reset_media(); existing_page(); $meta[10]['_thumbnail_id'] = 17; $p = media_plan();
foreach ( $p['payload']['nova_media'] as &$entry ) { $entry['url'] = ''; $entry['clear'] = true; } unset( $entry, $p['plan_digest'] ); $p['plan_digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $p ) );
ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $p, op( 1 ) ) ); check( get_post_thumbnail_id( 10 ) === 0 && image_settings()['background_image'] === [ 'id' => 0, 'url' => '' ] && $media_downloads === 0, 'An explicit clear empties both native image controls without a download.' );

reset_media(); existing_page(); $payload = [ 'content' => '<p>Native core body</p>', 'nova_media' => [ '/native_post/featured_image' => [ 'kind' => 'featured', 'url' => 'https://images.example.test/core.png' ] ] ];
ok( Nova_Bridge_Suite_Stored_Layout_Provider::apply( 'core', 10, $payload, [ 'title' => 'Native core' ], 'core-operation' ) );
check( get_post_thumbnail_id( 10 ) === 100 && get_post( 10 )->post_content === '<p>Native core body</p>', 'Featured image support works with the generic content provider, independent of Elementor.' );
$bad = $p['payload']; $bad['nova_media']['/document/0/settings/background_image/url']['id_target'] = '/document/0/settings/another/id';
check( is_wp_error( Nova_Bridge_Suite_Native_Media::validate( 'elementor', $bad ) ), 'Malformed URL/ID pairs cannot retarget unrelated native settings.' );
reset_media(); $p = media_plan(); check( is_wp_error( Nova_Bridge_Suite_Native_Media::resolve( 'elementor', 10, $p['payload'], op( 1 ), false ) ) && $media_downloads === 0, 'Unstarted imports fail read-only verification instead of being mistaken for an optional skip.' );
echo 'Stored-layout media writer checks: ' . ( $checks - $start_checks ) . " passed.\n";
