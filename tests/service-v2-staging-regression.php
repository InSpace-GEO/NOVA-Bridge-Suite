<?php
/** Run with wp eval-file on staging. Uses new temporary fixtures only. */
use SEORAI\ServicePageCPT\ServiceDocument as Document;
use SEORAI\ServicePageCPT\ServiceRenderer as Renderer;
use SEORAI\ServicePageCPT\ServiceShared as Shared;

$checks = [];
$fixtures = [];
$original_presets = get_option( Shared::OPTION_PRESETS, null );
$original_template = get_option( Shared::OPTION_TEMPLATE, null );
$original_modes = [];
foreach ( [ 168, 173, 3876 ] as $existing_id ) { $original_modes[$existing_id] = Document::is_v2( $existing_id ); }
$assert = static function ( $condition, $label ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    $checks[] = $label;
};
$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
wp_set_current_user( $admins[0]->ID );
$api = static function ( $method, $route, array $params = [] ) {
    $request = new WP_REST_Request( $method, $route );
    $request->set_body_params( $params );
    return rest_do_request( $request );
};
$base = '/wp/v2/' . get_option( 'service_cpt_slug', 'services' );
$s = static function ( $id, $type, array $data, $enabled = true ) {
    return [ 'id' => $id, 'type' => $type, 'enabled' => $enabled, 'data' => $data ];
};
try {
    $assert( post_type_exists( 'service_page' ), 'CPT registered' );
    foreach ( [ 'gutenberg/gutenberg.php', 'essential-blocks/essential-blocks.php', 'faq-block-for-gutenberg/faq-block-for-gutenberg.php' ] as $plugin ) {
        $assert( ! in_array( $plugin, (array) get_option( 'active_plugins' ), true ), 'No dependency: ' . $plugin );
    }
    foreach ( $original_modes as $old_id => $mode ) { $assert( $mode === Document::is_v2( $old_id ), 'Existing experience preserved: ' . $old_id ); }
    $assert( 404 === $api( 'GET', '/nova-service/v1/context' )->get_status(), 'Separate REST context endpoint removed' );
    $doc = [ 'version' => 2, 'sections' => [
        $s( 'hero', 'hero', [ 'title' => 'Native service canary', 'body' => '<p>Hero summary</p>', 'buttons' => [ [ 'label' => 'Talk to us', 'url' => '#contact' ] ] ] ),
        $s( 'offer', 'rich_text', [ 'title' => 'Offer', 'body' => '<p>General content is allowed.</p>', 'intent' => 'general' ] ),
        $s( 'people', 'people', [ 'title' => 'People', 'images' => [ [ 'id' => 5510, 'alt' => 'Generated SEO team sample', 'caption' => 'Fictional demo team' ], [ 'id' => 5511, 'alt' => 'Generated SEO consultation sample' ] ] ] ),
        $s( 'location', 'location', [ 'title' => 'Location', 'images' => [ [ 'id' => 5512, 'alt' => 'Generated agency office sample' ] ] ] ),
        $s( 'map', 'map', [ 'title' => 'Visit us', 'address' => 'Demonstration location', 'map_url' => 'https://www.google.com/maps?q=Amsterdam&output=embed' ] ),
        $s( 'reviews', 'reviews', [ 'title' => 'Demonstration review', 'items' => [ [ 'body' => 'Sample review only.', 'author' => 'Demo reviewer', 'rating' => 5, 'source' => 'Demo vendor', 'url' => 'https://example.com/review' ] ] ] ),
        $s( 'credentials', 'credentials', [ 'title' => 'Demonstration certificate', 'items' => [ [ 'title' => 'Sample credential', 'body' => 'Demonstration only.', 'url' => 'https://example.com/award' ] ] ] ),
        $s( 'contact', 'custom_cta', [ 'title' => 'Contact', 'html' => '<div id="contact"><p>Safe HTML call to action</p><a href="/contact/">Contact</a><script>alert(1)</script><img src="https://example.com/image.png" onerror="alert(1)"></div>' ] ),
        $s( 'related', 'related', [ 'title' => 'Related', 'post_ids' => [ 168 ] ] ),
        $s( 'faq', 'faq', [ 'title' => 'Questions', 'items' => [ [ 'question' => 'Is this native?', 'answer' => '<p>Yes, without block plugins.</p>' ] ] ] ),
        $s( 'hidden', 'rich_text', [ 'body' => '<p>HIDDEN_CANARY</p>' ], false ),
    ] ];
    $create = $api( 'POST', $base, [ 'title' => 'NOVA native regression temporary', 'status' => 'draft', 'nova_service_document' => $doc ] );
    $assert( 201 === $create->get_status(), 'Native REST creation accepted' );
    $id = (int) $create->get_data()['id']; $fixtures[] = $id;
    $assert( Document::is_v2( $id ), 'Native document opts in' );
    $stored = Document::get_document( $id );
    $ordinary_get = $api( 'GET', $base . '/' . $id )->get_data();
    $assert( isset( $ordinary_get['meta_note'], $ordinary_get['meta_descriptions']['rich_text_1'], $ordinary_get['nova_service_content']['rich_text_1'] ) && ! isset( $ordinary_get['nova_service_context'], $ordinary_get['nova_service_content']['offer'] ), 'Regular GET contains Blog-style guidance and generic copy without edit context' );
    $html = Renderer::render( $id );
    $assert( substr_count( $html, '<h1' ) === 1, 'One page H1' );
    foreach ( [ 'Hero summary', 'General content', 'Sample review', 'Sample credential', 'Safe HTML call to action', '<iframe', '<details', 'keukendeurtjes-wrappen' ] as $needle ) {
        $assert( false !== strpos( $html, $needle ), 'Native rendering: ' . $needle );
    }
    $assert( false === strpos( $html, 'HIDDEN_CANARY' ), 'Hidden sections omitted' );
    $assert( false === strpos( $html, 'onerror' ) && false === strpos( $html, 'alert(1)' ), 'Custom HTML scripts and events removed' );
    $assert( false !== strpos( $html, 'FAQPage' ), 'Visible FAQ schema emitted' );
    $update = $api( 'POST', $base . '/' . $id, [ 'title' => 'NOVA title update only' ] );
    $assert( 200 === $update->get_status() && $stored === Document::get_document( $id ), 'Omitted document preserves content' );
    $bad = $doc; $bad['sections'][0]['data']['buttons'][0]['url'] = 'javascript:alert(1)';
    $response = $api( 'POST', $base . '/' . $id, [ 'title' => 'MUST_NOT_SAVE', 'nova_service_document' => $bad ] );
    $assert( 400 === $response->get_status() && get_post( $id )->post_title === 'NOVA title update only' && $stored === Document::get_document( $id ), 'Invalid document fails before any post mutation' );
    $bad = $doc; $bad['sections'][1]['data']['width'] = 'third';
    $response = $api( 'POST', $base . '/' . $id, [ 'title' => 'INVALID_WIDTH_MUST_NOT_SAVE', 'nova_service_document' => $bad ] );
    $assert( 400 === $response->get_status() && get_post( $id )->post_title === 'NOVA title update only' && $stored === Document::get_document( $id ), 'Real WordPress REST schema rejects an invalid row width without title or document mutation' );
    $bad = $doc; $bad['sections'][0]['id'] = 'offer';
    $assert( 400 === $api( 'POST', $base . '/' . $id, [ 'nova_service_document' => $bad ] )->get_status(), 'Duplicate IDs rejected' );
    $bad = $doc; $bad['sections'][4]['data']['map_url'] = 'https://evil.example/maps/embed';
    $assert( 400 === $api( 'POST', $base . '/' . $id, [ 'nova_service_document' => $bad ] )->get_status(), 'Non Google map rejected' );
    $bad = $doc; $bad['sections'][7]['data']['html'] = '<iframe src="https://evil.example/embed"></iframe>';
    $assert( 400 === $api( 'POST', $base . '/' . $id, [ 'nova_service_document' => $bad ] )->get_status(), 'Unknown iframe provider rejected' );
    $bad = $doc; $bad['sections'][7]['data']['shortcode'] = '[not_an_installed_form]';
    $assert( 400 === $api( 'POST', $base . '/' . $id, [ 'nova_service_document' => $bad ] )->get_status(), 'Unknown shortcode rejected' );
    $empty = $api( 'POST', $base . '/' . $id, [ 'nova_service_document' => [ 'version' => 2, 'sections' => [] ] ] );
    $assert( 200 === $empty->get_status() && [] === Document::get_document( $id )['sections'], 'Explicit empty section list clears sections' );
    $legacy = $api( 'POST', $base, [ 'title' => 'NOVA legacy regression temporary', 'status' => 'draft', 'meta' => [ 'sp_main_1' => '<p>LEGACY_CANARY</p>', 'sp_hero_title' => 'Legacy title', 'sp_faq' => [ [ 'question' => 'Legacy question?', 'answer' => '<p>Legacy answer.</p>' ] ] ] ] );
    if ( 201 !== $legacy->get_status() ) { throw new RuntimeException( 'Legacy creation failed: ' . wp_json_encode( $legacy->get_data() ) ); }
    $assert( true, 'Legacy posting API still creates pages' );
    $legacy_id = (int) $legacy->get_data()['id']; $fixtures[] = $legacy_id;
    $assert( ! Document::is_v2( $legacy_id ), 'Legacy posting fields retain legacy rendering' );
    $content_before = get_post( $legacy_id )->post_content;
    $meta_before = get_post_meta( $legacy_id, 'sp_main_1', true );
    $preview = $api( 'GET', '/nova-service/v1/pages/' . $legacy_id . '/migration' );
    $assert( 200 === $preview->get_status() && ! Document::is_v2( $legacy_id ), 'Migration preview does not switch page' );
    $migrate = $api( 'POST', '/nova-service/v1/pages/' . $legacy_id . '/migration', [ 'action' => 'migrate' ] );
    $assert( 200 === $migrate->get_status() && Document::is_v2( $legacy_id ), 'Explicit migration switches renderer' );
    $assert( false !== strpos( Renderer::render( $legacy_id ), 'LEGACY_CANARY' ), 'Migration carries old rich text' );
    $assert( get_post( $legacy_id )->post_content === $content_before && get_post_meta( $legacy_id, 'sp_main_1', true ) === $meta_before, 'Migration preserves original fields and content' );
    $edited = Document::get_document( $legacy_id ); $edited['sections'][0]['data']['title'] = 'Native edited title';
    Document::save_document( $legacy_id, $edited );
    $api( 'POST', '/nova-service/v1/pages/' . $legacy_id . '/migration', [ 'action' => 'migrate' ] );
    $assert( Document::get_document( $legacy_id )['sections'][0]['data']['title'] === 'Native edited title', 'Repeated migration preserves native edits' );
    $api( 'POST', '/nova-service/v1/pages/' . $legacy_id . '/migration', [ 'action' => 'legacy' ] );
    $assert( ! Document::is_v2( $legacy_id ), 'Rollback restores legacy rendering' );
    $api( 'POST', '/nova-service/v1/pages/' . $legacy_id . '/migration', [ 'action' => 'v2' ] );
    $assert( Document::is_v2( $legacy_id ) && Document::get_document( $legacy_id )['sections'][0]['data']['title'] === 'Native edited title', 'Return to native retains saved document' );
    wp_set_current_user( 0 );
    $assert( $api( 'POST', '/nova-service/v1/pages/' . $legacy_id . '/migration', [ 'action' => 'legacy' ] )->get_status() >= 400 && Document::is_v2( $legacy_id ), 'Anonymous migration denied' );
    $assert( $api( 'POST', $base . '/' . $id, [ 'nova_service_document' => $doc ] )->get_status() >= 400, 'Anonymous writes denied' );
    wp_set_current_user( $admins[0]->ID );
    $presets = [ 'version' => 2, 'sections' => [ $s( 'canary_hero', 'hero', [ 'buttons' => [ [ 'label' => 'Shared contact', 'url' => '#contact' ] ], 'images' => [ [ 'id' => 5510, 'alt' => 'Generated shared team sample' ] ] ] ), $s( 'canary_form', 'custom_cta', [ 'html' => '<form action="/contact/" method="post"><label>Email<input type="email" name="email" required></label><button type="submit">Send</button></form>' ] ) ] ];
    $reference = $s( 'hero', 'hero', [] ); $reference['shared_key'] = 'canary_hero';
    $form_ref = $s( 'contact', 'custom_cta', [] ); $form_ref['shared_key'] = 'canary_form';
    $template = [ 'version' => 2, 'sections' => [ $reference, $s( 'offer', 'rich_text', [ 'intent' => 'offer', 'title' => 'What we offer', 'body' => '' ] ), $form_ref, $s( 'faq', 'faq', [ 'items' => [] ], false ) ] ];
    $assert( true === Shared::save_settings( $presets, $template ), 'Shared library and default layout configured' );
    $content = [ 'hero' => [ 'body' => '<p>Service-specific introduction</p>' ], 'rich_text_1' => [ 'body' => '<p>Available content can be general.</p>' ], 'faq' => [ [ 'question' => 'Content API?', 'answer' => '<p>Yes.</p>' ] ] ];
    $simple = $api( 'POST', $base, [ 'title' => 'NOVA content-only regression temporary', 'status' => 'draft', 'nova_service_content' => $content ] );
    if ( 201 !== $simple->get_status() ) { throw new RuntimeException( 'Content-only create failed: ' . wp_json_encode( $simple->get_data() ) ); }
    $simple_id = (int) $simple->get_data()['id']; $fixtures[] = $simple_id;
    $raw = Document::get_document( $simple_id ); $resolved = Document::resolve_document( $simple_id );
    $assert( Document::is_v2( $simple_id ) && 'canary_hero' === $raw['sections'][0]['shared_key'], 'Content-only API inherits configured layout and references' );
    $assert( ! isset( $raw['sections'][0]['data']['buttons'] ) && 'Shared contact' === $resolved['sections'][0]['data']['buttons'][0]['label'], 'Inherited CTA remains linked with sparse local content' );
    $assert( false !== strpos( Renderer::render( $simple_id ), 'Service-specific introduction' ) && false !== strpos( Renderer::render( $simple_id ), '<form' ), 'Inherited media, HTML form and local copy render together' );
    $assert( false !== strpos( Renderer::render( $simple_id ), 'FAQPage' ), 'Content-only creation enables supplied FAQ' );
    $presets['sections'][0]['data']['buttons'][0]['label'] = 'Updated shared CTA';
    Shared::save_presets( $presets );
    $assert( 'Updated shared CTA' === Document::resolve_document( $simple_id )['sections'][0]['data']['buttons'][0]['label'], 'Global library edits update linked service pages' );
    $partial = $api( 'POST', $base . '/' . $simple_id, [ 'nova_service_content' => [ 'rich_text_1' => [ 'title' => 'Changed title' ] ] ] );
    $assert( 200 === $partial->get_status() && '<p>Available content can be general.</p>' === Document::get_content( $simple_id )['rich_text_1']['body'], 'Partial generic posting preserves omitted content fields' );
    $unknown_override = $api( 'POST', $base . '/' . $simple_id, [ 'title' => 'UNKNOWN_OVERRIDE_MUST_NOT_SAVE', 'nova_service_content' => [ 'rich_text_1' => [ 'body' => 'MUST_NOT_SAVE' ], 'section_overrides' => [ 'unknown' => [ 'enabled' => true ] ] ] ] );
    $assert( 400 === $unknown_override->get_status() && get_post( $simple_id )->post_title === 'NOVA content-only regression temporary' && '<p>Available content can be general.</p>' === Document::get_content( $simple_id )['rich_text_1']['body'], 'Unknown optional override rejects atomically before changing title or source copy' );
    $local_override = $api( 'POST', $base . '/' . $simple_id, [ 'nova_service_content' => [ 'section_overrides' => [ 'hero' => [ 'data' => [ 'buttons' => [ [ 'label' => 'One-page CTA', 'url' => '#contact' ] ] ] ] ] ] ] );
    $assert( 200 === $local_override->get_status() && 'One-page CTA' === Document::resolve_document( $simple_id )['sections'][0]['data']['buttons'][0]['label'] && 'canary_hero' === Document::get_document( $simple_id )['sections'][0]['shared_key'], 'Optional per-page CTA override keeps the client reference and unrelated local content' );
    $inherit_override = $api( 'POST', $base . '/' . $simple_id, [ 'nova_service_content' => [ 'section_overrides' => [ 'hero' => [ 'inherit' => [ 'buttons' ] ] ] ] ] );
    $assert( 200 === $inherit_override->get_status() && 'Updated shared CTA' === Document::resolve_document( $simple_id )['sections'][0]['data']['buttons'][0]['label'] && ! isset( Document::get_document( $simple_id )['sections'][0]['data']['buttons'] ), 'Per-field inheritance restores the current client CTA without structural replacement' );
    $before_simple = Document::get_document( $simple_id );
    $bad_content = $api( 'POST', $base . '/' . $simple_id, [ 'title' => 'DO_NOT_SAVE', 'nova_service_content' => [ 'hero' => [ 'body' => [ 'wrong type' ] ] ] ] );
    $assert( 400 === $bad_content->get_status() && get_post( $simple_id )->post_title === 'NOVA content-only regression temporary' && wp_json_encode( $before_simple ) === wp_json_encode( Document::get_document( $simple_id ) ), 'Invalid content-only payload rejected atomically' );
    $hidden = Document::get_document( $simple_id ); $hidden['sections'][1]['enabled'] = false;
    Document::save_document( $simple_id, $hidden );
    $api( 'POST', $base . '/' . $simple_id, [ 'nova_service_content' => [ 'offer' => [ 'body' => '<p>Refreshed copy</p>' ], 'faq' => [] ] ] );
    $assert( false === Document::get_document( $simple_id )['sections'][1]['enabled'] && [] === Document::get_content( $simple_id )['faq'], 'Content refresh respects hidden sections and explicit empty FAQ' );
    $override = Document::get_document( $simple_id ); $override['sections'][0]['data']['buttons'] = [];
    Document::save_document( $simple_id, $override );
    $assert( [] === Document::resolve_document( $simple_id )['sections'][0]['data']['buttons'], 'Explicit empty local override suppresses inherited CTA' );
    $before_settings = [ get_option( Shared::OPTION_PRESETS ), get_option( Shared::OPTION_TEMPLATE ) ];
    $bad_template = $template; $bad_template['sections'][0]['shared_key'] = 'missing_preset';
    $assert( is_wp_error( Shared::save_settings( $presets, $bad_template ) ) && wp_json_encode( $before_settings ) === wp_json_encode( [ get_option( Shared::OPTION_PRESETS ), get_option( Shared::OPTION_TEMPLATE ) ] ), 'Invalid shared settings do not partially save' );
    $presets['sections'][0]['enabled'] = false; Shared::save_presets( $presets );
    $assert( false === Document::resolve_document( $simple_id )['sections'][0]['enabled'], 'Globally hidden preset hides linked sections' );
    $video = $s( 'video', 'video', [ 'title' => 'Video example', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'layout' => 'compact' ] );
    $video_doc = [ 'version' => 2, 'sections' => [ $video ] ];
    $assert( true === Document::save_document( $simple_id, $video_doc ) && false !== strpos( Renderer::render( $simple_id ), 'youtube-nocookie.com/embed/' ), 'Video provider normalized and rendered' );
    $assert( false !== strpos( Renderer::render( $simple_id ), 'nova-service__layout--compact' ), 'Presentation variant renders' );
    $fresh_response = $api( 'GET', $base . '/' . $simple_id )->get_data();
    $assert( isset( $fresh_response['meta_note'], $fresh_response['meta_descriptions']['section_overrides'] ) && ! isset( $fresh_response['nova_service_context'] ), 'Regular response documents client defaults and optional overrides directly' );
    $semantic_presets = [ 'version' => 2, 'sections' => [ $s( 'canary_reasons', 'rich_text', [ 'intent' => 'why', 'title' => 'Reasons', 'body' => '<p>Shared reasons.</p>' ] ) ] ];
    $reasons = $s( 'business_reasons', 'rich_text', [] ); $reasons['shared_key'] = 'canary_reasons';
    Shared::save_settings( $semantic_presets, [ 'version' => 2, 'sections' => [ $reasons ] ] );
    Document::save_document( $simple_id, [ 'version' => 2, 'sections' => [ $reasons ] ] );
    $first = $api( 'POST', $base . '/' . $simple_id, [ 'nova_service_content' => [ 'why' => [ 'intent' => 'general', 'body' => '<p>Other available copy.</p>' ] ] ] );
    $assert( 200 === $first->get_status() && 1 === count( Document::get_document( $simple_id )['sections'] ) && '<p>Other available copy.</p>' === Document::get_content( $simple_id )['rich_text_3']['body'], 'Compatible older payload maps into a generic shared slot without duplicate' );
    $second = $api( 'POST', $base . '/' . $simple_id, [ 'nova_service_content' => [ 'why' => [ 'body' => '<p>Refreshed general copy.</p>' ] ] ] );
    $assert( 200 === $second->get_status() && 1 === count( Document::get_document( $simple_id )['sections'] ) && '<p>Refreshed general copy.</p>' === Document::get_content( $simple_id )['rich_text_3']['body'], 'Repeated compatible content refresh retains the same generic slot' );
    $row_presets = [ 'version' => 2, 'sections' => [
        $s( 'row_reasons', 'rich_text', [ 'intent' => 'why', 'title' => 'Reasons column', 'body' => '<p>Shared reasons column.</p>', 'width' => 'half' ] ),
        $s( 'row_awards', 'credentials', [ 'title' => 'Awards column', 'width' => 'half', 'items' => [ [ 'title' => 'Fictional award fixture', 'body' => '<p>Demonstration evidence.</p>' ] ] ] ),
    ] ];
    $row_reasons = $s( 'row_left', 'rich_text', [] ); $row_reasons['shared_key'] = 'row_reasons';
    $row_awards = $s( 'row_right', 'credentials', [] ); $row_awards['shared_key'] = 'row_awards';
    $row_doc = [ 'version' => 2, 'sections' => [
        $row_reasons,
        $s( 'row_hidden', 'rich_text', [ 'width' => 'half', 'body' => '<p>HIDDEN_ROW_CANARY</p>', 'intent' => 'offer' ], false ),
        $s( 'row_empty', 'cta', [ 'width' => 'full' ] ),
        $row_awards,
        $s( 'row_full', 'rich_text', [ 'width' => 'full', 'body' => '<p>Full row between columns.</p>' ] ),
        $s( 'row_final', 'faq', [ 'width' => 'half', 'items' => [ [ 'question' => 'Final fixture?', 'answer' => '<p>Final odd column.</p>' ] ] ] ),
    ] ];
    $assert( true === Shared::save_settings( $row_presets, $row_doc ) && true === Document::save_document( $simple_id, $row_doc ), 'Shared two-column presentation and row fixture save using the real WordPress schema' );
    $row_raw = Document::get_document( $simple_id ); $row_resolved = Document::resolve_document( $simple_id );
    $row_local_left = (array) $row_raw['sections'][0]['data']; $row_local_right = (array) $row_raw['sections'][3]['data'];
    $assert( ! isset( $row_local_left['width'], $row_local_right['width'] ) && 'half' === $row_resolved['sections'][0]['data']['width'] && 'half' === $row_resolved['sections'][3]['data']['width'], 'Both half-row widths inherit from shared sections while local references remain sparse' );
    $row_override = $row_raw; $row_override['sections'][0]['data'] = $row_local_left; $row_override['sections'][0]['data']['width'] = 'full';
    $assert( true === Document::save_document( $simple_id, $row_override ) && 'full' === Document::resolve_document( $simple_id )['sections'][0]['data']['width'] && 'half' === Document::resolve_document( $simple_id )['sections'][3]['data']['width'], 'Explicit full-row local override affects only its linked section' );
    unset( $row_override['sections'][0]['data']['width'] );
    $assert( true === Document::save_document( $simple_id, $row_override ) && 'half' === Document::resolve_document( $simple_id )['sections'][0]['data']['width'], 'Removing the width override restores shared half-row inheritance' );
    $row_order_before = array_column( Document::get_document( $simple_id )['sections'], 'id' );
    $row_refresh = $api( 'POST', $base . '/' . $simple_id, [ 'nova_service_content' => [ 'why' => [ 'body' => '<p>Updated service reason.</p>', 'intent' => 'general' ], 'offer' => [ 'body' => '<p>Updated hidden offer.</p>' ] ] ] );
    $row_after = Document::get_document( $simple_id ); $row_after_resolved = Document::resolve_document( $simple_id );
    $assert( 200 === $row_refresh->get_status() && $row_order_before === array_column( $row_after['sections'], 'id' ) && false === $row_after['sections'][1]['enabled'] && 'half' === $row_after['sections'][1]['data']['width'] && 'half' === $row_after_resolved['sections'][0]['data']['width'] && ! isset( $row_after['sections'][0]['data']['width'] ), 'Semantic content refresh preserves row order, hidden state and local or inherited widths' );
    $row_html = Renderer::render( $simple_id );
    $assert( false === strpos( $row_html, 'Updated hidden offer.' ) && false === strpos( $row_html, 'nova-service-row_hidden' ) && false === strpos( $row_html, 'nova-service-row_empty' ) && 2 === substr_count( $row_html, 'class="nova-service__row nova-service__container"' ), 'Column groups omit hidden and empty sections while pairing adjacent rendered halves' );
    $row_dom = new DOMDocument(); $xml_errors_before = libxml_use_internal_errors( true );
    try { $row_dom->loadHTML( '<?xml encoding="utf-8" ?>' . $row_html, LIBXML_NONET ); }
    finally { libxml_clear_errors(); libxml_use_internal_errors( $xml_errors_before ); }
    $row_xpath = new DOMXPath( $row_dom );
    $row_bands = $row_xpath->query( '//article[@id="nova-service-v2"]/div[contains(concat(" ", normalize-space(@class), " "), " nova-service__band ")]' );
    $row_groups = $row_xpath->query( '//article[@id="nova-service-v2"]/div[contains(concat(" ", normalize-space(@class), " "), " nova-service__band ")]/div[contains(concat(" ", normalize-space(@class), " "), " nova-service__row ")]' );
    $band_rows_valid = 2 === $row_bands->length;
    foreach ( $row_bands as $row_band ) { if ( 1 !== $row_xpath->query( './div[contains(concat(" ", normalize-space(@class), " "), " nova-service__row ")]', $row_band )->length ) { $band_rows_valid = false; } }
    $assert( $band_rows_valid && 2 === $row_groups->length && 0 === $row_xpath->query( '//article[@id="nova-service-v2"]/div[contains(concat(" ", normalize-space(@class), " "), " nova-service__band ")]//section[@id="nova-service-row_full"]' )->length && 1 === $row_xpath->query( '//article[@id="nova-service-v2"]/section[@id="nova-service-row_full"]' )->length, 'Each full-width band contains one column row and closes before intervening full-width content' );
    $first_children = $row_xpath->query( './section', $row_groups->item( 0 ) );
    $last_children = $row_xpath->query( './section', $row_groups->item( 1 ) );
    $assert( 2 === $first_children->length && 'nova-service-row_left' === $first_children->item( 0 )->getAttribute( 'id' ) && 'nova-service-row_right' === $first_children->item( 1 )->getAttribute( 'id' ), 'Hidden or empty sections do not create blank columns between the two rendered half sections' );
    $assert( 1 === $last_children->length && 'nova-service-row_final' === $last_children->item( 0 )->getAttribute( 'id' ) && 1 === $row_xpath->query( '//article[@id="nova-service-v2"]/section[@id="nova-service-row_full"]' )->length && 4 === $row_xpath->query( '//article[@id="nova-service-v2"]//section' )->length, 'Odd half row closes correctly and full-width content stays outside column wrappers in reading order' );
    $trust_doc = [ 'version' => 2, 'sections' => [ $s( 'credentials', 'credentials', [ 'layout' => 'badges', 'items' => [ [ 'title' => 'Credential schema fixture', 'image' => 'https://example.com/issuer.svg', 'url' => 'https://example.com/evidence' ] ] ] ), $s( 'reviews', 'reviews', [ 'source_logo' => 'https://example.com/shared-publisher.svg', 'items' => [ [ 'body' => '<p>Review schema fixture.</p>', 'author' => 'Fixture author', 'source_logo' => '/wp-content/uploads/publisher.svg', 'source_url' => 'https://www.trustpilot.com/review/example.test' ] ] ] ) ] ];
    $trust_response = $api( 'POST', $base . '/' . $simple_id, [ 'nova_service_document' => $trust_doc ] );
    $assert( 200 === $trust_response->get_status() && 'badges' === Document::get_document( $simple_id )['sections'][0]['data']['layout'] && '/wp-content/uploads/publisher.svg' === Document::get_document( $simple_id )['sections'][1]['data']['items'][0]['source_logo'] && 'https://example.com/shared-publisher.svg' === Document::get_document( $simple_id )['sections'][1]['data']['source_logo'], 'Real REST schema preserves optional shared/card publisher branding and credential badge layout' );
    $trust_doc['sections'][0]['data']['layout'] = 'logos';
    $assert( 200 === $api( 'POST', $base . '/' . $simple_id, [ 'nova_service_document' => $trust_doc ] )->get_status() && 'logos' === Document::get_document( $simple_id )['sections'][0]['data']['layout'], 'Real REST schema accepts the credential issuer-logo layout' );
    $trust_before = wp_json_encode( Document::get_document( $simple_id ) );
    $trust_doc['sections'][1]['data']['items'][0]['source_logo'] = 'javascript:alert(1)';
    $assert( 400 === $api( 'POST', $base . '/' . $simple_id, [ 'title' => 'UNSAFE_LOGO_MUST_NOT_SAVE', 'nova_service_document' => $trust_doc ] )->get_status() && $trust_before === wp_json_encode( Document::get_document( $simple_id ) ) && 'UNSAFE_LOGO_MUST_NOT_SAVE' !== get_post( $simple_id )->post_title, 'Unsafe publisher logo is rejected before title or document mutation' );
    foreach ( $original_modes as $existing_id => $mode ) { $assert( $mode === Document::is_v2( $existing_id ), 'Existing experience unchanged after fixture tests: ' . $existing_id ); }
    echo wp_json_encode( [ 'result' => 'PASS', 'checks' => $checks, 'fixtures' => $fixtures ], JSON_PRETTY_PRINT ) . "\n";
} finally {
    wp_set_current_user( $admins[0]->ID );
    foreach ( $fixtures as $fixture ) { wp_trash_post( $fixture ); }
    foreach ( [ Shared::OPTION_PRESETS => $original_presets, Shared::OPTION_TEMPLATE => $original_template ] as $option => $value ) {
        if ( null === $value ) { delete_option( $option ); } else { update_option( $option, $value, false ); }
    }
}
