<?php
/** Empty metadata representation regressions. All WordPress state is in-memory. */
require __DIR__ . '/stored-layout-media-writer-unit.php';
$empty_settings_start = $checks;

function empty_settings_digest( $value ) { return hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $value ) ); }
function empty_settings_source(): void { $GLOBALS['example_meta']['_elementor_page_settings'] = []; }
function empty_settings_state( array $plan ): array {
    $method = new ReflectionMethod( Nova_Bridge_Suite_Stored_Layout_Writer::class, 'native_state' );
    return $method->invoke( null, 10, $plan['payload']['theme_presentation'] ?? null, $plan['payload']['elementor_document_settings'] ?? null, $plan['builder'] ?? 'elementor', $plan['payload'] );
}
/** Seed either exact historical post-save representation without changing the frozen plan. */
function empty_settings_committed( $representation = [] ): array {
    reset_media(); empty_settings_source();
    $plan = planned(); $result = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) );
    $GLOBALS['meta'][10]['_elementor_page_settings'] = $representation;
    $digest = empty_settings_digest( empty_settings_state( $plan ) );
    $operation = 'nova_layout_operation_' . hash( 'sha256', op( 1 ) );
    $association = 'nova_layout_page_' . hash( 'sha256', $plan['site_id'] . ':' . $plan['delivery_identity']['url_id'] );
    $GLOBALS['options'][$operation]['native_digest'] = $digest;
    $GLOBALS['options'][$association]['native_digest'] = $digest;
    $result['native_digest'] = $digest;
    return [ $plan, $result, $operation, $association ];
}
function empty_settings_flip( $value ) { return [] === $value ? '' : []; }

foreach ( [ [], '' ] as $historical ) {
    [ $old, $result, $operation, $association ] = empty_settings_committed( $historical );
    $old_frozen = $old; $record_before = $options[$operation]; $association_before = $options[$association];
    $meta[10]['_elementor_page_settings'] = empty_settings_flip( $historical );
    ok( Nova_Bridge_Suite_Stored_Layout_Writer::verify( $old, $result ) );
    check( $options[$operation] === $record_before && $options[$association] === $association_before, 'Read-only verification accepts either legacy empty hash without rewriting stored history.' );
    $next = planned( 2, '502' ); $next_frozen = $next;
    $updated = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $next, op( 2 ) ) );
    check( $updated['post_id'] === 10 && $inserts === 1 && $saves === 2 && $posts[10]->post_title === "New creator's café 😀 502", 'A higher CIV updates the same page after either empty-representation transition.' );
    check( $old === $old_frozen && $options[$operation]['plan'] === $old_frozen && $next === $next_frozen, 'Empty representation compatibility does not normalize or alter either immutable plan.' );
}

// A new delivery may replace mutable page state; it must still own the same native target.
$edits = [
    'body' => static function () { $GLOBALS['posts'][10]->post_content = 'Human body'; },
    'elements' => static function () { $data = json_decode( $GLOBALS['meta'][10]['_elementor_data'], true ); $data[0]['elements'][1]['settings']['editor'] = 'Human Elementor body'; $GLOBALS['meta'][10]['_elementor_data'] = json_encode( $data ); },
    'nonempty settings' => static function () { $GLOBALS['meta'][10]['_elementor_page_settings'] = [ 'background_color' => '#human' ]; },
    'unrelated nonempty settings' => static function () { $GLOBALS['meta'][10]['_elementor_page_settings'] = [ 'unselected_control' => 'new' ]; },
    'header' => static function () { $GLOBALS['meta'][10]['vendor_header'] = 'hidden'; },
    'width' => static function () { $GLOBALS['meta'][10]['vendor_width'] = 'narrow'; },
    'template' => static function () { $GLOBALS['meta'][10]['_wp_page_template'] = 'different-template'; },
    'owner' => static function () { $GLOBALS['meta'][10]['_nova_stored_layout_owner']['creation_operation'] = op( 90 ); },
    'owner site' => static function () { $GLOBALS['meta'][10]['_nova_stored_layout_owner']['site_id'] = op( 91 ); },
    'owner URL' => static function () { $GLOBALS['meta'][10]['_nova_stored_layout_owner']['url_id'] = '999'; },
    'owner template' => static function () { $GLOBALS['meta'][10]['_nova_stored_layout_owner']['template_id'] = 'different-template'; },
    'missing owner' => static function () { unset( $GLOBALS['meta'][10]['_nova_stored_layout_owner'] ); },
    'type' => static function () { $GLOBALS['posts'][10]->post_type = 'post'; },
    'status' => static function () { $GLOBALS['posts'][10]->post_status = 'draft'; },
    'slug' => static function () { $GLOBALS['posts'][10]->post_name = 'human-slug'; },
    'parent' => static function () { $GLOBALS['posts'][10]->post_parent = 44; },
    'author' => static function () { $GLOBALS['posts'][10]->post_author = 91; },
    'title' => static function () { $GLOBALS['posts'][10]->post_title = 'Human title'; },
    'excerpt' => static function () { $GLOBALS['posts'][10]->post_excerpt = 'Human excerpt'; },
    'reservation' => static function () { $GLOBALS['posts'][10]->post_content_filtered = 'changed-marker'; },
];
$mutable_edits = [ 'body', 'elements', 'nonempty settings', 'unrelated nonempty settings', 'header', 'width', 'template', 'status', 'author', 'title', 'excerpt', 'reservation' ];
foreach ( $mutable_edits as $label ) {
    [ $old, $result ] = empty_settings_committed(); $meta[10]['_elementor_page_settings'] = ''; $edits[$label]();
    $current_state = empty_settings_state( $old ); $before = [ $posts, $meta, $options, $saves, $inserts ];
    $next = planned( 2, '502' );
    check( $next['target_post_id'] === 10 && $next['native_before'] === $current_state, 'A between-version ' . $label . ' change becomes the new operation baseline.' );
    check( [ $posts, $meta, $options, $saves, $inserts ] === $before, 'Planning over a between-version ' . $label . ' change is read-only.' );
    $updated = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $next, op( 2 ) ) );
    check( $updated['post_id'] === 10 && $inserts === 1 && $saves === 2, 'A higher version replaces mutable ' . $label . ' state on the same owned page.' );
}
foreach ( [ 'owner', 'owner site', 'owner URL', 'owner template', 'missing owner', 'type', 'slug', 'parent' ] as $label ) {
    [ $old, $result ] = empty_settings_committed(); $meta[10]['_elementor_page_settings'] = ''; $edits[$label]();
    $before = [ $posts, $meta, $options, $saves, $inserts ];
    [ $content, $config ] = fixture( '502' );
    check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $config, [ 'operation_id' => op( 2 ) ] ) ), 'A changed ' . $label . ' identity still blocks higher-version planning.' );
    check( [ $posts, $meta, $options, $saves, $inserts ] === $before, 'Rejected ' . $label . ' edits cause no native or journal mutation.' );
}
// The comparator remains narrow for in-flight/recovery checks even though a new
// delivery can deliberately replace a prior operation's mutable page state.
// The fixture's get_post_meta collapses NULL to absent, so test the real comparators directly.
[ $old ] = empty_settings_committed(); $state = empty_settings_state( $old );
$digest_match = new ReflectionMethod( Nova_Bridge_Suite_Stored_Layout_Writer::class, 'native_digest_matches' );
$same_state = new ReflectionMethod( Nova_Bridge_Suite_Stored_Layout_Writer::class, 'same_native_state' );
foreach ( [ false, 0, '0', null ] as $invalid ) {
    $changed = $state; $changed['page_settings'] = $invalid;
    check( ! $digest_match->invoke( null, $changed, empty_settings_digest( $state ) ), 'Other false-like values are not equivalent to an empty settings digest.' );
    check( ! $same_state->invoke( null, $changed, $state ), 'Other false-like values are not equivalent to an empty settings baseline.' );
}

// Between planning and application, no owned native document has changed.
foreach ( [ [], '' ] as $before ) {
    reset_media(); empty_settings_source(); existing_page(); $meta[10]['_elementor_page_settings'] = $before;
    $plan = planned(); $frozen = $plan; $meta[10]['_elementor_page_settings'] = empty_settings_flip( $before );
    $proof = ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) );
    check( $proof['safe_to_apply'] && $proof['no_native_commit'], 'Pre-apply recovery recognizes the same native state after empty metadata normalization.' );
    ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) );
    check( $inserts === 0 && $saves === 1 && $plan === $frozen, 'Application resumes the same immutable plan and existing page.' );
}
foreach ( [ 'body', 'elements', 'nonempty settings', 'header', 'owner' ] as $label ) {
    reset_media(); empty_settings_source(); existing_page(); $meta[10]['_elementor_page_settings'] = [];
    $plan = planned(); $meta[10]['_elementor_page_settings'] = ''; $edits[$label]();
    check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) ), 'Recovery rejects a post-planning ' . $label . ' change even with empty metadata normalization.' );
    check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ) && $saves === 0 && $inserts === 0, 'Application preserves a post-planning ' . $label . ' edit without a native save.' );
}

// A crash before the provider save retains the old page plus its ownership marker.
foreach ( [ 'crash_owner', 'crash_before_save' ] as $interruption ) {
    reset_media(); empty_settings_source(); existing_page(); $meta[10]['_elementor_page_settings'] = [];
    $plan = planned(); $frozen = $plan; $GLOBALS[$interruption] = true;
    check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ), 'The simulated interruption is surfaced: ' . $interruption );
    $meta[10]['_elementor_page_settings'] = '';
    $proof = ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) );
    check( $proof['safe_to_apply'], 'An unchanged claimed page remains recoverable after empty settings normalization.' );
    ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) );
    check( $saves === 1 && $inserts === 0 && $plan === $frozen, 'Recovery completes one save without changing the frozen plan.' );
}

// Adoption of a new image/template payload remains possible after a legacy empty hash.
[ $old ] = empty_settings_committed(); $meta[10]['_elementor_page_settings'] = '';
$example_meta['_wp_page_template'] = 'new-page-template';
$next = media_plan( 2, '502', 'https://images.example.test/new-version.png' ); $frozen = $next;
$saved = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $next, op( 2 ) ) );
check( $saved['post_id'] === 10 && $inserts === 1 && $saves === 2 && get_post_thumbnail_id( 10 ) === 100 && image_settings()['background_image']['id'] === 100, 'A higher version applies its new native image bindings without duplicating the page.' );
check( $next === $frozen && $media_downloads === 1 && $attachment_count === 1, 'Image import preserves the immutable plan and reuses one attachment.' );
check( get_page_template_slug( 10 ) === 'new-page-template', 'The approved higher version can apply a newly captured page template.' );
$meta[10]['_elementor_page_settings'] = ''; $meta[10]['_thumbnail_id'] = 77;
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::verify( $next, $saved ) ), 'Empty settings normalization does not bypass a later featured-image change.' );

echo 'Stored-layout empty-settings checks: ' . ( $checks - $empty_settings_start ) . " passed.\n";
